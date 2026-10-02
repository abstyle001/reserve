/**
 * 移动端排队查询 - Alpine store（唯一数据源）
 *
 * 分层约定与 reserve 模块一致：
 *   - api.js   只发请求，不写业务
 *   - store.js 只管状态与动作，不做 DOM 渲染
 *   - Blade    只读不写，$store.mobile 是界面上一切数值的唯一来源
 *
 * 只读约束：本 store 只有 fetchPosition 一个接口动作，移动端不能取号/放号。
 *
 * 号码来源优先级：
 *   1. URL 参数 /m?key=xxx&no=27（大屏二维码扫码直达）
 *   2. localStorage 记住的上次查询
 *   3. 都没有 → 显示查询表单
 */

import { POLL_MS, POLL_BACKOFF_MAX_MS, DEFAULT_QUEUE_KEY, TOAST_MS } from './config';
import * as api from './api';
import { loadTicket, saveTicket, clearTicket } from './storage';

function readScreenConfig() {
    try {
        const raw = document.body.dataset.reserveConfig;
        return raw ? JSON.parse(raw) : {};
    } catch (e) {
        return {};
    }
}

/** 从 URL 解析扫码直达参数 ?key=xxx&no=27，非法则返回 null */
function readTicketFromUrl() {
    try {
        const params = new URLSearchParams(window.location.search);
        const key = params.get('key');
        const no = parseInt(params.get('no'), 10);
        if (key && Number.isInteger(no) && no > 0) {
            return { key: key, serialNo: no };
        }
    } catch (e) {
        /* 忽略 */
    }
    return null;
}

export function registerMobileStore(Alpine) {
    // 定时器句柄放闭包里，避免被 Alpine 的响应式代理追踪
    let pollTimer = null;
    let toastTimer = null;

    Alpine.store('mobile', {
        // ---------- 状态 ----------
        queueOptions: [DEFAULT_QUEUE_KEY],
        queueKey: DEFAULT_QUEUE_KEY, // 表单里选中的队列
        serialInput: '', // 表单里输入的号码（字符串，提交时校验）
        ticket: null, // 已确认的查询目标 {key, serialNo}；null = 还在表单页
        result: null, // GET /api/reserve/position 的 data
        initializing: true,
        querying: false,
        online: true,
        deferred: 0, // 连续轮询失败次数（用于退避）
        toast: null, // {type, text}

        // ---------- 初始化 ----------
        init() {
            const config = readScreenConfig();
            if (Array.isArray(config.queueOptions) && config.queueOptions.length) {
                this.queueOptions = config.queueOptions;
            }
            this.queueKey = this.queueOptions[0] || DEFAULT_QUEUE_KEY;

            const ticket = readTicketFromUrl() || loadTicket();
            if (ticket) {
                this.bindTicket(ticket);
            } else {
                this.initializing = false;
            }

            // 用箭头函数包一层，保证回调里的 this 指向 store
            this._handlers = {
                visibility: () => this.handleVisibility(),
            };
            document.addEventListener('visibilitychange', this._handlers.visibility);
        },

        destroy() {
            this.clearPoll();
            if (this._handlers) {
                document.removeEventListener('visibilitychange', this._handlers.visibility);
                this._handlers = null;
            }
        },

        /** 绑定查询目标：持久化 + 立刻查一次 + 开始轮询 */
        bindTicket(ticket) {
            this.ticket = ticket;
            this.queueKey = ticket.key;
            this.serialInput = String(ticket.serialNo);
            saveTicket(ticket);
            this.poll();
            this.schedulePoll();
        },

        // ---------- 表单动作 ----------
        submit() {
            const serialNo = parseInt(this.serialInput, 10);
            if (!this.queueKey) {
                this.pushToast('error', '请选择队列');
                return;
            }
            if (!Number.isInteger(serialNo) || serialNo <= 0) {
                this.pushToast('error', '请输入正确的号码');
                return;
            }
            this.initializing = true;
            this.bindTicket({ key: this.queueKey, serialNo: serialNo });
        },

        /** 返回表单重新查询（换号 / 换队列） */
        reset() {
            this.clearPoll();
            this.ticket = null;
            this.result = null;
            clearTicket();
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
            if (!this.ticket) return Promise.resolve();
            this.querying = true;

            return api
                .fetchPosition(this.ticket.key, this.ticket.serialNo)
                .then((data) => {
                    this.result = data;
                    this.online = true;
                    this.deferred = 0; // 恢复标准间隔
                })
                .catch(() => {
                    this.online = false;
                    this.deferred += 1;
                })
                .then(() => {
                    this.querying = false;
                    this.initializing = false;
                    // 无论成功失败都排下一轮（失败时 schedulePoll 内部按 deferred 退避）
                    if (this.ticket && !document.hidden) {
                        this.schedulePoll();
                    }
                });
        },

        handleVisibility() {
            if (document.hidden) {
                this.clearPoll();
            } else if (this.ticket) {
                this.poll();
            }
        },

        // ---------- 提示 ----------
        pushToast(type, text) {
            this.toast = { type: type, text: text };

            if (toastTimer) {
                clearTimeout(toastTimer);
            }
            toastTimer = setTimeout(() => {
                this.toast = null;
            }, TOAST_MS);
        },

        // ---------- 派生数据（模板直接调用） ----------
        /** 状态文案：排队中 / 已办结 / 未找到 */
        statusLabel() {
            const status = this.result ? this.result.status : '';
            if (status === 'waiting') return '排队中';
            if (status === 'finished') return '已办结';
            if (status === 'not_found') return '号码未找到';
            return '查询中';
        },

        statusBadgeClass() {
            const status = this.result ? this.result.status : '';
            if (status === 'waiting') return 'badge--blue';
            if (status === 'finished') return 'badge--gray';
            return 'badge--amber';
        },

        /** 状态副文案：告诉用户接下来做什么 */
        statusHint() {
            const status = this.result ? this.result.status : '';
            if (status === 'waiting') return '请留意大屏叫号，轮到您时工作人员会为您办结';
            if (status === 'finished') return '您的号码已办结，感谢使用';
            if (status === 'not_found') return '该号码不存在或所在批次已轮换，请核对后重新查询';
            return '正在查询排队信息…';
        },
    });
}
