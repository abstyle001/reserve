/**
 * 移动端排队查询 - localStorage 封装
 *
 * 只存一件事：用户上次查询的号码 {key, serialNo}，
 * 下次打开页面直接恢复并自动查询。
 *
 * 注意：隐私模式下 localStorage 可能直接抛异常，所有读写必须 try/catch。
 */

import { LS_TICKET } from './config';

export function loadTicket() {
    try {
        const raw = window.localStorage.getItem(LS_TICKET);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed.key !== 'string' || parsed.key === '') return null;
        const serialNo = Number(parsed.serialNo);
        if (!Number.isInteger(serialNo) || serialNo <= 0) return null;
        return { key: parsed.key, serialNo: serialNo };
    } catch (e) {
        return null;
    }
}

export function saveTicket(ticket) {
    try {
        window.localStorage.setItem(LS_TICKET, JSON.stringify(ticket));
        return true;
    } catch (e) {
        // 配额满 / 隐私模式：静默失败，不影响主流程
        return false;
    }
}

export function clearTicket() {
    try {
        window.localStorage.removeItem(LS_TICKET);
    } catch (e) {
        /* 忽略 */
    }
}
