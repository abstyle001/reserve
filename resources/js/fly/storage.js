/**
 * 云途旅行（/fly）- localStorage 封装
 *
 * 只存一件事：上次搜索用的 {city, q, checkIn, checkOut}。
 * 顾客直接打开 /fly（URL 上没有 query）时用它恢复，不必每次重选城市和日期。
 *
 * 不存 tab：除酒店外的业务都还没开放，恢复一个「机票」空 tab 只会让人以为系统坏了。
 *
 * 注意：隐私模式下 localStorage 可能直接抛异常，所有读写必须 try/catch。
 */

import { LS_SEARCH, MAX_KEYWORD_LENGTH } from './config';

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

function text(value, max) {
    return typeof value === 'string' && value !== '' ? value.slice(0, max) : '';
}

export function loadSearch() {
    try {
        const raw = window.localStorage.getItem(LS_SEARCH);
        if (!raw) return null;

        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') return null;

        // 日期必须两个都在且成对，只恢复一半会让晚数算错
        if (!DATE_RE.test(String(parsed.checkIn || '')) || !DATE_RE.test(String(parsed.checkOut || ''))) {
            return null;
        }
        if (parsed.checkIn >= parsed.checkOut) return null;

        return {
            city: text(parsed.city, 50),
            q: text(parsed.q, MAX_KEYWORD_LENGTH),
            checkIn: parsed.checkIn,
            checkOut: parsed.checkOut,
        };
    } catch (e) {
        return null;
    }
}

export function saveSearch(filters) {
    try {
        window.localStorage.setItem(
            LS_SEARCH,
            JSON.stringify({
                city: filters.city || '',
                q: filters.q || '',
                checkIn: filters.check_in,
                checkOut: filters.check_out,
            })
        );
        return true;
    } catch (e) {
        // 配额满 / 隐私模式：静默失败，不影响搜索
        return false;
    }
}
