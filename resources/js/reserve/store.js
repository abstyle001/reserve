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
 *
 * 号票面板数据源（重要）：
 *   - 面板列表来自后端 state.tickets（当前批次真实号票），不是 localStorage！
 *     localStorage 是浏览器私有的：不同 origin / 设备看到的列表会不一致，
 *     且本地 is_finish 永远不会被后端校正（别处放号后本地仍显示待叫号）。
 *   - localStorage records 只保留一个用途：标记「哪些号是本机取的」（面板角标）。
 */

import { POLL_MS, POLL_BACKOFF_MAX_MS, DEFAULT_QUEUE_KEY } from './config';
import * as api from './api';
import QRCode from 'qrcode';
import {
    loadQueueKey,
    saveQueueKey,
    loadRecords,
    appendRecord,
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
    // 最近一次生成二维码的目标（queueKey:batch:serial），同一张票不重复生成
    let lastQrTarget = null;

    Alpine.store('reserve', {
        // ---------- 状态 ----------
        queueKey: DEFAULT_QUEUE_KEY,
        queueOptions: [DEFAULT_QUEUE_KEY],
        state: null, // GET /api/reserve/state 的 data
        latest: null, // 最近一次取到的号 {batch_no, serial_no}
        records: [], // 本机取号记录（localStorage，仅用于「本机取的」角标）
        selectedSerial: null, // 选中的号票 serial_no（当前批次内唯一）
        initializing: true,
        taking: false,
        releasing: false,
        flash: false, // 号牌弹跳动画开关
        toast: null, // {type, text}
        online: true, // 轮询是否在线
        deferred: 0, // 连续轮询失败次数（用于退避）
        zoomed: false,
        qrUrl: '', // 最新号票的扫码直达二维码（dataURL），无最新号票时为空

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
                    this.updateQr(); // 换批后 currentLatest() 失效，二维码随之隐藏
                    // 选中的号票不在当前批次里了（换批），清掉选中态
                    if (this.selectedSerial !== null && !this.activeTicket()) {
                        this.selectedSerial = null;
                    }
                })
                .catch(() => {
                    this.online = false;
                    this.deferred += 1;
                })
                .then(() => {
                    this.initializing = false;
                    // 无论成功失败都排下一轮（失败时 schedulePoll 内部按 deferred 退避）
                    if (!document.hidden) {
                        this.schedulePoll();
                    }
                });
        },

        handleVisibility() {
            if (document.hidden) {
                this.clearPoll();
            } else {
                this.poll();
            }
        },

        /**
         * 二维码的目标号票：
         *   1. 当前选中的「待叫号」票（操作员点选哪张，就展示哪张的二维码）
         *   2. 否则回退到本机刚取的号（currentLatest）
         * 内容为绝对地址 /m?key=xxx&no=27，手机扫码后自动查询该号码。
         */
        qrTicket() {
            const ticket = this.activeTicket();
            if (ticket && Number(ticket.is_finish) === 0 && this.state) {
                return { batch_no: Number(this.state.batch_no), serial_no: Number(ticket.serial_no) };
            }
            return this.currentLatest();
        },

        updateQr() {
            const target0 = this.qrTicket();
            if (!target0) {
                this.qrUrl = '';
                lastQrTarget = null;
                return;
            }

            const target = `${this.queueKey}:${target0.batch_no}:${target0.serial_no}`;
            if (target === lastQrTarget) return; // 同一张票不重复生成
            lastQrTarget = target;

            const url =
                `${window.location.origin}/m?key=${encodeURIComponent(this.queueKey)}` +
                `&no=${target0.serial_no}`;

            QRCode.toDataURL(url, { margin: 1, width: 240 })
                .then((dataUrl) => {
                    // 异步回来时目标可能已变（又取了新号 / 换了选中票），只对当前目标生效
                    if (lastQrTarget === target) {
                        this.qrUrl = dataUrl;
                    }
                })
                .catch(() => {
                    this.qrUrl = '';
                });
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
                    this.updateQr(); // 为新号票生成扫码直达二维码
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

            // 放号目标来自后端 tickets（当前批次），批次号取 state.batch_no
            const ticket = this.activeTicket();
            if (!ticket) {
                this.pushToast('error', '请先选择要放号的号票');
                return;
            }
            if (Number(ticket.is_finish) === 1) {
                this.pushToast('error', '该号已放号');
                return;
            }

            this.releasing = true;

            api
                .releaseNumber(this.queueKey, this.state.batch_no, ticket.serial_no)
                .then(() => {
                    this.pushToast('success', `${ticket.serial_no} 号已放号`);
                    this.selectedSerial = null;
                    // 号票状态以后端为准，poll 回来 tickets 里的 is_finish 就是新的
                    return this.poll();
                })
                .catch((error) => {
                    this.pushToast('error', error.message || '放号失败');
                })
                .then(() => {
                    this.releasing = false;
                });
        },

        selectRecord(serialNo) {
            this.selectedSerial = this.selectedSerial === serialNo ? null : serialNo;
            this.updateQr(); // 二维码跟随选中的待叫号票
        },

        selectQueue(key) {
            this.queueKey = key;
            this.selectedSerial = null; // 换队列后旧选择失效
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
        /** 当前批次号票列表（后端下发，所有端看到的一致） */
        currentTickets() {
            return this.state && Array.isArray(this.state.tickets) ? this.state.tickets : [];
        },

        /** 当前选中的号票（来自后端 tickets），没选或已换批返回 null */
        activeTicket() {
            if (this.selectedSerial === null || !this.state) return null;
            return (
                this.currentTickets().find(
                    (t) => Number(t.serial_no) === Number(this.selectedSerial),
                ) || null
            );
        },

        /** 该号是否本机取的（面板「本机」角标，数据来自 localStorage records） */
        isMine(serialNo) {
            if (!this.state) return false;
            const batch = Number(this.state.batch_no);
            return this.records.some(
                (item) =>
                    Number(item.batch_no) === batch && Number(item.serial_no) === Number(serialNo),
            );
        },

        /** 号牌大字：仅当 latest 属于当前批次时才展示，换批后回到「—」占位态 */
        currentLatest() {
            if (!this.latest) return null;
            if (!this.state) return this.latest;
            return Number(this.latest.batch_no) === Number(this.state.batch_no) ? this.latest : null;
        },

        /** 放号按钮是否可点：选中了一张且该票未放号 */
        canRelease() {
            const ticket = this.activeTicket();
            return !!ticket && Number(ticket.is_finish) === 0;
        },

        needReset() {
            return this.state ? Number(this.state.need_reset) === 1 : false;
        },
    });
}
