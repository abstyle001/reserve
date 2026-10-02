/**
 * 预约大屏 - Alpine store（唯一数据源）
 *
 * 分层约定：
 *   - api.js   只发请求，不写业务
 *   - store.js 只管状态与动作，不做 DOM 渲染（DOM 变化交给 Blade partial）
 *   - partials 只读不写，$store.reserve 是界面上一切数值的唯一来源
 *
 * 业务规则（与后端 ReserveController 对齐）：
 *   - 取号返回 {queue_key, batch_no, serial_no}，放号必须带 batch_no + serial_no
 *   - code === 0 成功；code === 1 表示「记录不存在 / 重复放号」；422/500 走 message
 *   - active_count 归零 → need_reset = 1 → 再取号会开新批次
 */

import { POLL_MS, POLL_BACKOFF_MAX_MS, DEFAULT_QUEUE_KEY } from './config';
import * as api from './api';
import {
    loadQueueKey,
    saveQueueKey,
    loadRecords,
    appendRecord,
    markRecordFinished,
    markRecordInvalid,
} from './storage';

function readScreenConfig() {
    try {
        const raw = document.body.dataset.reserveConfig;
        return raw ? JSON.parse(raw) : {};
    } catch (e) {
        return {};
    }
}

export function registerReserveStore(Alpine) {
    // 定时器句柄放闭包里，避免被 Alpine 的响应式代理追踪
    let pollTimer = null;
    let toastTimer = null;

    Alpine.store('reserve', {
        // ---------- 状态 ----------
        queueKey: DEFAULT_QUEUE_KEY,
        queueOptions: [DEFAULT_QUEUE_KEY],
        state: null, // GET /api/reserve/state 的 data
        latest: null, // 最近一次取到的号 {batch_no, serial_no}
        records: [], // 本机取号记录（localStorage）
        selectedId: null,
        initializing: true,
        taking: false,
        releasing: false,
        flash: false, // 号牌弹跳动画开关
        toast: null, // {type, text}
        online: true, // 轮询是否在线
        deferred: 0, // 连续轮询失败次数（用于退避）
        zoomed: false,

        // ---------- 初始化 ----------
        init() {
            const config = readScreenConfig();
            if (Array.isArray(config.queueOptions) && config.queueOptions.length) {
                this.queueOptions = config.queueOptions;
            }

            this.queueKey = loadQueueKey() || this.queueOptions[0] || DEFAULT_QUEUE_KEY;
            this.records = loadRecords();

            // 先立刻拉一次，避免 5 秒白屏
            this.poll();
            this.schedulePoll();

            // 用箭头函数包一层，保证回调里的 this 指向 store
            this._handlers = {
                visibility: () => this.handleVisibility(),
                fullscreen: () => this.handleFullscreenChange(),
            };
            document.addEventListener('visibilitychange', this._handlers.visibility);
            document.addEventListener('fullscreenchange', this._handlers.fullscreen);
        },

        destroy() {
            this.clearPoll();
            if (this._handlers) {
                document.removeEventListener('visibilitychange', this._handlers.visibility);
                document.removeEventListener('fullscreenchange', this._handlers.fullscreen);
                this._handlers = null;
            }
        },

        // ---------- 轮询 ----------
        schedulePoll() {
            this.clearPoll();

            let delay = POLL_MS;
            if (this.deferred > 0) {
                delay = Math.min(POLL_MS * Math.pow(1.5, this.deferred), POLL_BACKOFF_MAX_MS);
            }

            pollTimer = setTimeout(this.poll.bind(this), delay);
        },

        clearPoll() {
            if (pollTimer) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }
        },

        poll() {
            return api
                .fetchState(this.queueKey)
                .then((data) => {
                    this.state = data;
                    this.online = true;
                    this.deferred = 0; // 恢复标准间隔
                })
                .catch(() => {
                    this.online = false;
                    this.deferred += 1;
                    this.schedulePoll();
                })
                .then(() => {
                    this.initializing = false;
                });
        },

        handleVisibility() {
            if (document.hidden) {
                this.clearPoll();
            } else {
                this.poll();
                this.schedulePoll();
            }
        },

        // ---------- 取号 ----------
        take() {
            if (this.taking) return;
            if (!this.queueKey) {
                this.pushToast('error', '请先选择队列');
                return;
            }

            this.taking = true;

            api
                .takeNumber(this.queueKey)
                .then((data) => {
                    const record = {
                        id: `${data.batch_no}-${data.serial_no}-${Date.now()}`,
                        batch_no: data.batch_no,
                        serial_no: data.serial_no,
                        is_finish: 0,
                        taken_at: new Date().toISOString(),
                    };

                    this.records = appendRecord(record);
                    this.latest = { batch_no: data.batch_no, serial_no: data.serial_no };
                    this.flash = true;
                    setTimeout(() => {
                        this.flash = false;
                    }, 600);

                    this.pushToast('success', `取号成功，${record.serial_no} 号请稍候`);
                    return this.poll();
                })
                .catch((error) => {
                    this.pushToast('error', error.message || '取号失败');
                })
                .then(() => {
                    this.taking = false;
                });
        },

        // ---------- 放号 ----------
        release() {
            if (this.releasing) return;

            const record = this.records.find((item) => item.id === this.selectedId);
            if (!record) {
                this.pushToast('error', '请先选择要放号的号票');
                return;
            }
            if (record.is_finish) {
                this.pushToast('error', '该号已放号');
                return;
            }

            this.releasing = true;

            api
                .releaseNumber(this.queueKey, record.batch_no, record.serial_no)
                .then(() => {
                    this.records = markRecordFinished(record.id);
                    this.pushToast('success', `${record.serial_no} 号已放号`);
                    return this.poll();
                })
                .catch((error) => {
                    const message = error.message || '放号失败';

                    // 后端 code === 1：记录不存在 / 重复放号 —— 本地同步为失效，但不丢记录
                    if (/不存在|重复/.test(message)) {
                        this.records = markRecordInvalid(record.id);
                        this.selectedId = null;
                    }

                    this.pushToast('error', message);
                })
                .then(() => {
                    this.releasing = false;
                });
        },

        selectRecord(id) {
            this.selectedId = this.selectedId === id ? null : id;
        },

        selectQueue(key) {
            this.queueKey = key;
            saveQueueKey(key);
            this.poll();
        },

        // ---------- 放大 / 全屏 ----------
        toggleZoom() {
            if (this.zoomed) {
                this.setZoom(false);
                if (document.fullscreenElement) {
                    document.exitFullscreen();
                }
            } else {
                this.setZoom(true);
                // requestFullscreen 必须由用户手势触发，否则被浏览器拦截
                if (document.documentElement.requestFullscreen) {
                    document.documentElement.requestFullscreen().catch(() => {
                        /* 浏览器不支持时仅保留放大字号 */
                    });
                }
            }
        },

        setZoom(on) {
            this.zoomed = on;
            document.documentElement.classList.toggle('zoomed', on);
        },

        handleFullscreenChange() {
            this.setZoom(!!document.fullscreenElement);
        },

        // ---------- 提示 ----------
        pushToast(type, text) {
            this.toast = { type: type, text: text };

            if (toastTimer) {
                clearTimeout(toastTimer);
            }
            toastTimer = setTimeout(() => {
                this.toast = null;
            }, 2600);
        },

        // ---------- 派生数据（模板直接调用） ----------
        /** 当前选中的号票记录，没选返回 null；历史批次的记录视为未选（不可放号） */
        activeRecord() {
            const record = this.records.find((item) => item.id === this.selectedId) || null;
            if (!record || !this.state) return record;
            return Number(record.batch_no) === Number(this.state.batch_no) ? record : null;
        },

        /** 当前批次的号票列表；历史批次的记录不再展示（仍保留在 localStorage） */
        currentBatchRecords() {
            if (!this.state) return this.records;
            const batch = Number(this.state.batch_no);
            return this.records.filter((item) => Number(item.batch_no) === batch);
        },

        /** 号牌大字：仅当 latest 属于当前批次时才展示，换批后回到「—」占位态 */
        currentLatest() {
            if (!this.latest) return null;
            if (!this.state) return this.latest;
            return Number(this.latest.batch_no) === Number(this.state.batch_no) ? this.latest : null;
        },

        /** 放号按钮是否可点：选中了一条且该条未放号 */
        canRelease() {
            const record = this.activeRecord();
            return !!record && !record.is_finish;
        },

        needReset() {
            return this.state ? Number(this.state.need_reset) === 1 : false;
        },

        isHistoryBatch(batchNo) {
            return this.state ? Number(batchNo) < Number(this.state.batch_no) : false;
        },
    });
}
