/**
 * 预约大屏 - localStorage 封装
 *
 * 放号需要 batch_no + serial_no，而取号结果只存在于浏览器，
 * 所以用 localStorage 把「我的取号记录」持久化下来。
 *
 * 注意：隐私模式下 localStorage 可能直接抛异常，所有读写必须 try/catch。
 */

import { LS_RECORDS, LS_QUEUE_KEY, RECORDS_MAX } from './config';

function read(key, fallback) {
    try {
        const raw = window.localStorage.getItem(key);
        if (raw === null || raw === '') return fallback;
        const parsed = JSON.parse(raw);
        return parsed === null ? fallback : parsed;
    } catch (e) {
        return fallback;
    }
}

function write(key, value) {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
        return true;
    } catch (e) {
        // 配额满 / 隐私模式：静默失败，不影响主流程
        return false;
    }
}

export function loadQueueKey() {
    const value = read(LS_QUEUE_KEY, '');
    return typeof value === 'string' && value !== '' ? value : '';
}

export function saveQueueKey(key) {
    write(LS_QUEUE_KEY, key);
}

/**
 * 读取本机取号记录
 * @returns {Array<{id:string,batch_no:number,serial_no:number,is_finish:number,taken_at:string}>}
 */
export function loadRecords() {
    const list = read(LS_RECORDS, []);
    return Array.isArray(list) ? list : [];
}

/**
 * 追加一条取号记录，最多保留 RECORDS_MAX 条（超出丢弃最旧）
 */
export function appendRecord(record) {
    const list = loadRecords();
    list.push(record);

    const trimmed = list.slice(-RECORDS_MAX);
    write(LS_RECORDS, trimmed);
    return trimmed;
}

/**
 * 把指定记录标记为已放号（is_finish = 1）
 */
export function markRecordFinished(id) {
    const list = loadRecords();
    const target = list.find((item) => item.id === id);

    if (target) {
        target.is_finish = 1;
    }

    write(LS_RECORDS, list);
    return list;
}

/**
 * 把指定记录标记为失效（后端已办结但本地还以为是有效号，例如被他人放号）
 */
export function markRecordInvalid(id) {
    const list = loadRecords();
    const target = list.find((item) => item.id === id);

    if (target) {
        target.is_invalid = 1;
    }

    write(LS_RECORDS, list);
    return list;
}

export function clearRecords() {
    try {
        window.localStorage.removeItem(LS_RECORDS);
    } catch (e) {
        /* 忽略 */
    }
    return [];
}
