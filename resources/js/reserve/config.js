/**
 * 预约大屏 - 常量配置
 *
 * 约定：localStorage 的 key 一律带 `:v1` 版本号，
 * 后续结构变更时升版本号即可平滑淘汰旧数据，不需要写迁移逻辑。
 */

const VERSION = 'v1';

export const POLL_MS = 5000; // 轮询间隔：5 秒
export const POLL_BACKOFF_MAX_MS = 20000; // 失败退避上限：20 秒
export const POLL_RECOVERY_COUNT = 3; // 连续成功几次后恢复标准间隔
export const RECORDS_MAX = 20; // 本机取号记录最多保留多少条（超出丢弃最旧）

export const LS_QUEUE_KEY = `reserve:queue_key:${VERSION}`;
export const LS_RECORDS = `reserve:records:${VERSION}`;

/** 可选队列，界面下拉用；后端只认传入的字符串，不校验枚举 */
export const DEFAULT_QUEUE_KEY = 'counter-a';

export const TOAST_MS = 2600; // 提示浮层自动消失时间
