/**
 * 云途旅行（/fly）- 常量配置
 *
 * 约定与 reserve / mobile 模块一致：localStorage 的 key 一律带 `:v1` 版本号。
 *
 * 这里的 MAX_* 只用于「本地先把值夹到一个合理区间」，让用户少等一次请求；
 * 真正的口径在 config/fly.php + HotelSearchService::normalize()，
 * 前端被绕过也改不出脏数据。
 */

const VERSION = 'v1';

/** 记住上次的城市 / 日期 / 关键词：直接打开 /fly（无 query）时恢复 */
export const LS_SEARCH = `fly:search:${VERSION}`;

/** 日期与文本框改动的合并窗口：房量是日期的函数，连点两天不该发两次请求 */
export const SEARCH_DEBOUNCE_MS = 250;

export const MAX_NIGHTS = 30;
export const MAX_DAYS_AHEAD = 365;
export const MAX_KEYWORD_LENGTH = 50;
