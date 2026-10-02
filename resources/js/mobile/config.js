/**
 * 移动端排队查询 - 常量配置
 *
 * 约定与 reserve 模块一致：localStorage 的 key 一律带 `:v1` 版本号。
 */

const VERSION = 'v1';

export const POLL_MS = 5000; // 轮询间隔：5 秒
export const POLL_BACKOFF_MAX_MS = 20000; // 失败退避上限：20 秒

export const LS_TICKET = `mobile:ticket:${VERSION}`; // 记住上次查询的 {key, serialNo}

/** 可选队列由后端注入 body[data-reserve-config]，这里只兜底 */
export const DEFAULT_QUEUE_KEY = 'counter-a';

export const TOAST_MS = 2600; // 提示浮层自动消失时间
