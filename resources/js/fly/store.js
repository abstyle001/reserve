/**
 * 云途旅行（/fly）- Alpine store（局部刷新的唯一数据源）
 *
 * 分层与 reserve / mobile 一致：api.js 只发请求 → store.js 只管状态与动作 →
 * Blade 只读不写。渐进增强的边界在这里：
 *
 *   - 首屏由 Blade 渲染，store 在 Alpine.start() 之前从 body[data-fly-config]
 *     水合同一份数据（顺序不能反：先水合再 start，否则带 :value / x-text 绑定的
 *     表单控件会先被默认值清空一下）。
 *   - store 只负责「搜索条件变了 → 重查 → 局部替换结果」，
 *     下单 / 取消这类写操作一律走 Blade 表单 POST + redirect，不经过这里。
 *   - 关掉 JS：本文件完全不参与，SSR 的真 GET 表单与真链接照常工作。
 *
 * 卡片字段规格由后端 HotelSearchService::formatCard() 单点产出，
 * fly/partials/hotel-card.blade.php（SSR）与 hotel-card-live.blade.php（本 store 渲染）
 * 必须与它一一对应。
 */

import { SEARCH_DEBOUNCE_MS, MAX_NIGHTS, MAX_DAYS_AHEAD, MAX_KEYWORD_LENGTH } from './config';
import * as api from './api';
import { loadSearch, saveSearch } from './storage';

const DAY_MS = 86400000;
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;
const FILTER_KEYS = ['city', 'q', 'check_in', 'check_out', 'sort', 'page'];

function readFlyConfig() {
    try {
        const raw = document.body ? document.body.dataset.flyConfig : '';
        return raw ? JSON.parse(raw) : {};
    } catch (e) {
        return {};
    }
}

/**
 * 'Y-m-d' -> UTC 毫秒。
 * 一律按 UTC 计算差值：用本地时区做减法，跨夏令时那天会差一小时，Math.round 后晚数±1。
 */
function toDayMs(text) {
    if (typeof text !== 'string' || !DATE_RE.test(text)) {
        return null;
    }

    const parts = text.split('-');

    return Date.UTC(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
}

function fromDayMs(ms) {
    return new Date(ms).toISOString().slice(0, 10);
}

function addDays(text, n) {
    const ms = toDayMs(text);

    return ms === null ? text : fromDayMs(ms + n * DAY_MS);
}

function nightsBetween(checkIn, checkOut) {
    const a = toDayMs(checkIn);
    const b = toDayMs(checkOut);

    if (a === null || b === null || b <= a) {
        return 0;
    }

    return Math.round((b - a) / DAY_MS);
}

export function registerFlyStore(Alpine) {
    // 定时器句柄与请求序号放模块闭包：放进 store 会被响应式代理追踪
    let searchTimer = null;
    let detailTimer = null;
    let searchSeq = 0;
    let detailSeq = 0;

    Alpine.store('fly', {
        // ---------- 搜索条件（键名与 HotelSearchService::normalize() 一一对应） ----------
        mode: '', // search 首页 / detail 详情页
        api: {},
        filters: { city: '', q: '', check_in: '', check_out: '', sort: 'recommended', page: 1 },
        nights: 1,
        today: '',
        sorts: {},
        hotKeywords: [],
        cities: [],

        // ---------- 搜索结果 ----------
        hotels: [],
        total: 0,
        currentPage: 1,
        lastPage: 1,
        loading: false,
        resultsDirty: false, // SSR 容器与 live 容器的开关：首次局部刷新后切到 live
        online: true,
        error: '',

        // ---------- tab ----------
        tab: 'hotel',
        tabHint: '',

        // ---------- 详情页 ----------
        hotelId: null,
        card: null,
        roomTypes: [],
        detailLoading: false,
        detailDirty: false,

        // ============================================================ 水合与初始化

        /** 必须在 Alpine.start() 之前调用：让 :value / x-text 绑定从第一帧起就是真实数据 */
        hydrate() {
            const config = readFlyConfig();

            this.api = config.api || {};

            if (Array.isArray(config.cities)) {
                this.cities = config.cities;
            }

            if (config.search) {
                this.mode = 'search';
                this.applySearch(config.search, { keepUrl: true });
            } else if (config.detail) {
                this.mode = 'detail';
                this.today = config.detail.today || '';
                this.card = config.hotel || null;
                this.hotelId = this.card ? Number(this.card.id) : null;
                this.roomTypes = Array.isArray(config.detail.items) ? config.detail.items : [];
                this.filters.city = this.card && this.card.city ? this.card.city : '';
                this.setDates(config.detail.check_in, config.detail.check_out);
            }
        },

        init() {
            if (this.mode !== 'search') {
                return;
            }

            // URL 上带条件时 SSR 已经按同一套参数渲染过，不必再查一次
            const params = new URLSearchParams(window.location.search);
            for (let i = 0; i < FILTER_KEYS.length; i++) {
                if (params.get(FILTER_KEYS[i]) !== null) {
                    return;
                }
            }

            const saved = loadSearch();
            if (!saved) {
                return;
            }

            const same =
                saved.city === this.filters.city &&
                saved.q === this.filters.q &&
                saved.checkIn === this.filters.check_in &&
                saved.checkOut === this.filters.check_out;

            if (same) {
                return;
            }

            this.filters.city = saved.city;
            this.filters.q = saved.q;
            this.setDates(saved.checkIn, saved.checkOut);
            this.runSearch();
        },

        // ============================================================ 动作

        /** 点「搜索酒店」：读表单 → 立刻查 */
        submitSearch(form) {
            this.readForm(form);

            return this.runSearch();
        },

        /** 改日期 / 改城市：读表单 → 合并成一次请求 */
        onFormChange(form) {
            this.readForm(form);

            return this.runSearch({ debounce: true });
        },

        readForm(form) {
            if (!form || !form.elements) {
                return;
            }

            const el = form.elements;

            this.filters.city = this.clampText(el.city, 50);
            this.filters.q = this.clampText(el.q, MAX_KEYWORD_LENGTH);
            this.setDates(el.check_in ? el.check_in.value : '', el.check_out ? el.check_out.value : '');

            if (el.sort && this.sorts[el.sort.value]) {
                this.filters.sort = el.sort.value;
            }

            this.filters.page = 1;
        },

        clampText(el, max) {
            const value = el && typeof el.value === 'string' ? el.value.trim() : '';

            return value.slice(0, max);
        },

        /**
         * 本地先把日期夹到合法区间（与后端 normalize 同规则），
         * 这样表单里的 :value 绑定能立刻显示修正后的值，而不是等一次请求回来。
         */
        setDates(checkIn, checkOut) {
            let inText = typeof checkIn === 'string' && DATE_RE.test(checkIn) ? checkIn : this.filters.check_in;
            let outText = typeof checkOut === 'string' && DATE_RE.test(checkOut) ? checkOut : this.filters.check_out;

            const today = this.today || fromDayMs(toDayMs(inText) === null ? Date.now() : toDayMs(inText));

            if (today && toDayMs(inText) !== null && toDayMs(inText) < toDayMs(today)) {
                inText = today;
            }
            if (today && toDayMs(inText) !== null && toDayMs(inText) > toDayMs(addDays(today, MAX_DAYS_AHEAD))) {
                inText = addDays(today, MAX_DAYS_AHEAD);
            }
            if (toDayMs(outText) === null || toDayMs(outText) <= toDayMs(inText)) {
                outText = addDays(inText, 1);
            }
            if (nightsBetween(inText, outText) > MAX_NIGHTS) {
                outText = addDays(inText, MAX_NIGHTS);
            }

            this.filters.check_in = inText;
            this.filters.check_out = outText;
            this.nights = nightsBetween(inText, outText);
        },

        patch(partial, options) {
            const keys = Object.keys(partial);

            for (let i = 0; i < keys.length; i++) {
                this.filters[keys[i]] = partial[keys[i]];
            }

            if (partial.page === undefined) {
                this.filters.page = 1;
            }

            if (partial.check_in !== undefined || partial.check_out !== undefined) {
                this.setDates(this.filters.check_in, this.filters.check_out);
            }

            return this.runSearch(options);
        },

        selectCity(city) {
            return this.patch({ city: this.filters.city === city ? '' : city });
        },

        searchKeyword(keyword) {
            return this.patch({ q: this.filters.q === keyword ? '' : keyword });
        },

        setSort(sort) {
            if (!this.sorts[sort]) {
                return Promise.resolve();
            }

            return this.patch({ sort: sort });
        },

        loadPage(page) {
            const target = Number(page);

            if (!target || target < 1 || target === Number(this.filters.page)) {
                return Promise.resolve();
            }

            return this.patch({ page: target });
        },

        clearFilters() {
            return this.patch({ city: '', q: '' });
        },

        applyTab(name, label) {
            if (name === 'hotel') {
                this.tab = 'hotel';
                this.tabHint = '';

                return;
            }

            // 未开放的业务：不切 tab，只给一句话提示。
            // 把「机票」点亮却仍显示酒店搜索面板，会让人以为页面坏了。
            this.tabHint = (label || '该业务') + '预订尚未开放，敬请期待';
        },

        /**
         * 下单表单提交：只锁按钮 + 改文案，不做任何前端校验。
         * 房量复核与金额计算都在服务端（FlyBookingController 重算一遍才落库），
         * 前端算得再准也只是「看起来一致」；防重复提交的兜底是 idempotency_key 唯一索引。
         */
        lockSubmit(form) {
            const button = form && form.querySelector ? form.querySelector('button[type="submit"]') : null;

            if (!button) {
                return;
            }

            button.disabled = true;
            button.textContent = '提交中…';
        },

        // ============================================================ 请求

        runSearch(options) {
            const opts = options || {};

            if (!this.api.hotels) {
                return Promise.resolve();
            }

            return this.queueSearch(opts.debounce ? SEARCH_DEBOUNCE_MS : 0);
        },

        queueSearch(delay) {
            if (searchTimer) {
                clearTimeout(searchTimer);
                searchTimer = null;
            }

            this.loading = true;
            this.resultsDirty = true;

            if (delay > 0) {
                return new Promise((resolve) => {
                    searchTimer = setTimeout(() => {
                        searchTimer = null;
                        resolve(this.executeSearch());
                    }, delay);
                });
            }

            return this.executeSearch();
        },

        executeSearch() {
            const seq = ++searchSeq;

            return api
                .fetchHotels(this.api.hotels, this.query())
                .then((data) => {
                    if (seq !== searchSeq) {
                        return; // 迟到的旧响应必须丢掉，否则连改两天会显示第一次的结果
                    }

                    this.applySearch(data);
                    this.online = true;
                    this.error = '';
                    saveSearch(this.filters);
                })
                .catch((e) => {
                    if (seq !== searchSeq) {
                        return;
                    }

                    this.online = false;
                    this.error = e.message || '搜索失败，请稍后重试';
                })
                .then(() => {
                    if (seq === searchSeq) {
                        this.loading = false;
                    }
                });
        },

        applySearch(data, options) {
            const opts = options || {};
            const facets = data.facets || {};

            this.hotels = Array.isArray(data.items) ? data.items : [];
            this.total = Number(data.total) || 0;
            this.currentPage = Number(data.current_page) || 1;
            this.lastPage = Number(data.last_page) || 1;
            this.filters.page = this.currentPage;

            if (facets.city !== undefined) {
                this.filters.city = facets.city;
            }
            if (facets.q !== undefined) {
                this.filters.q = facets.q;
            }
            if (facets.sort !== undefined) {
                this.filters.sort = facets.sort;
            }
            if (facets.sorts) {
                this.sorts = facets.sorts;
            }
            if (facets.hot_keywords) {
                this.hotKeywords = facets.hot_keywords;
            }
            if (facets.today) {
                this.today = facets.today;
            }

            this.setDates(facets.check_in, facets.check_out);

            if (!opts.keepUrl) {
                this.syncUrl();
            }
        },

        // ============================================================ 详情页

        submitDetail(form) {
            this.readDetailForm(form);

            return this.refreshRoomTypes();
        },

        onDetailChange(form) {
            this.readDetailForm(form);

            return this.refreshRoomTypes({ debounce: true });
        },

        readDetailForm(form) {
            if (!form || !form.elements) {
                return;
            }

            const el = form.elements;

            this.setDates(el.check_in ? el.check_in.value : '', el.check_out ? el.check_out.value : '');
        },

        refreshRoomTypes(options) {
            const opts = options || {};

            if (!this.api.roomTypes) {
                return Promise.resolve();
            }

            if (detailTimer) {
                clearTimeout(detailTimer);
                detailTimer = null;
            }

            this.detailLoading = true;
            this.detailDirty = true;

            const run = () => {
                const seq = ++detailSeq;

                return api
                    .fetchRoomTypes(this.api.roomTypes, {
                        check_in: this.filters.check_in,
                        check_out: this.filters.check_out,
                    })
                    .then((data) => {
                        if (seq !== detailSeq) {
                            return;
                        }

                        this.roomTypes = Array.isArray(data.items) ? data.items : [];
                        if (data.hotel) {
                            this.card = data.hotel;
                        }
                        this.setDates(data.check_in, data.check_out);
                        this.online = true;
                        this.error = '';
                        this.syncDetailUrl();
                    })
                    .catch((e) => {
                        if (seq !== detailSeq) {
                            return;
                        }

                        this.online = false;
                        this.error = e.message || '房量查询失败，请稍后重试';
                    })
                    .then(() => {
                        if (seq === detailSeq) {
                            this.detailLoading = false;
                        }
                    });
            };

            if (opts.debounce) {
                return new Promise((resolve) => {
                    detailTimer = setTimeout(() => {
                        detailTimer = null;
                        resolve(run());
                    }, SEARCH_DEBOUNCE_MS);
                });
            }

            return run();
        },

        syncDetailUrl() {
            // 详情页 URL 只带日期：与 FlyHotelController 读的参数一致
            this.replaceUrl(
                this.buildUrl({ check_in: this.filters.check_in, check_out: this.filters.check_out }, '/fly/hotels/' + this.hotelId)
            );
        },

        // ============================================================ 链接与派生文案

        query() {
            const params = {
                city: this.filters.city,
                q: this.filters.q,
                check_in: this.filters.check_in,
                check_out: this.filters.check_out,
                sort: this.filters.sort,
                page: Number(this.filters.page) || 1,
            };

            return params;
        },

        /** 与后端 HotelSearchService::pageUrl() 同一套省略规则：空值与第 1 页不进 URL */
        buildUrl(params, path) {
            const pairs = [];

            for (let i = 0; i < FILTER_KEYS.length; i++) {
                const key = FILTER_KEYS[i];
                const value = params[key];

                if (value === undefined || value === null || value === '') {
                    continue;
                }
                if (key === 'page' && Number(value) <= 1) {
                    continue;
                }

                pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(value)));
            }

            return (path || '/fly') + (pairs.length ? '?' + pairs.join('&') : '');
        },

        /** 城市卡是「切换」语义（再点一次取消），href 必须与 @click 的行为一致 */
        cityUrl(city) {
            const params = this.query();
            params.city = this.filters.city === city ? '' : city;
            params.page = 1;

            return this.buildUrl(params);
        },

        keywordUrl(keyword) {
            const params = this.query();
            params.q = this.filters.q === keyword ? '' : keyword;
            params.page = 1;

            return this.buildUrl(params);
        },

        sortUrl(sort) {
            const params = this.query();
            params.sort = sort;
            params.page = 1;

            return this.buildUrl(params);
        },

        pageHref(page) {
            const params = this.query();
            params.page = page;

            return this.buildUrl(params);
        },

        clearUrl() {
            const params = this.query();
            params.city = '';
            params.q = '';
            params.page = 1;

            return this.buildUrl(params);
        },

        /** 空态里的两个「放宽一档」链接：只去掉一个条件，另一个留着 */
        noCityUrl() {
            const params = this.query();
            params.city = '';
            params.page = 1;

            return this.buildUrl(params);
        },

        noKeywordUrl() {
            const params = this.query();
            params.q = '';
            params.page = 1;

            return this.buildUrl(params);
        },

        /** 详情页的面包屑与「看同城其他酒店」：日期改了以后链接必须跟着走，否则跳回旧日期 */
        listUrl(city) {
            return this.buildUrl(
                { city: city || '', check_in: this.filters.check_in, check_out: this.filters.check_out },
                '/fly'
            );
        },

        /**
         * 只改地址栏、不产生历史记录（局部刷新的 URL 同步）。
         * 入参是已经算好的完整 URL —— 省略规则（空值、page=1）由 buildUrl 单点负责，
         * 这里再拼一遍就会和后端 pageUrl() 的口径分家。
         */
        replaceUrl(url) {
            try {
                window.history.replaceState({}, '', url);
            } catch (e) {
                // 沙箱 iframe 里禁用 history 会抛异常，不影响页面可用
            }
        },

        syncUrl() {
            this.replaceUrl(this.buildUrl(this.query()));
        },

        onPagerClick(item) {
            if (!item || !item.page) {
                return Promise.resolve();
            }

            return this.loadPage(item.page);
        },

        /** 页码窗口算法与 fly/partials/pagination.blade.php 保持一致 */
        pagerItems() {
            const current = Number(this.currentPage) || 1;
            const last = Number(this.lastPage) || 1;
            const items = [];

            if (current > 1) {
                items.push({ type: 'btn', page: current - 1, label: '上一页' });
            }

            const from = Math.max(1, Math.min(current - 3, last - 6));
            const to = Math.min(last, from + 6);

            if (from > 1) {
                items.push({ type: 'num', page: 1, label: '1' });

                if (from > 2) {
                    items.push({ type: 'gap', page: 0, label: '…' });
                }
            }

            for (let p = from; p <= to; p++) {
                items.push({ type: 'num', page: p, label: String(p), active: p === current });
            }

            if (to < last) {
                if (to < last - 1) {
                    items.push({ type: 'gap', page: 0, label: '…' });
                }

                items.push({ type: 'num', page: last, label: String(last) });
            }

            if (current < last) {
                items.push({ type: 'btn', page: current + 1, label: '下一页' });
            }

            return items;
        },

        pagerClass(item) {
            if (item.type === 'gap') {
                return 'fly-pager__gap';
            }

            if (item.type === 'btn') {
                return 'fly-pager__btn';
            }

            return item.active ? 'fly-pager__num fly-pager__num--active' : 'fly-pager__num';
        },

        pagerHref(item) {
            return item.page ? this.pageHref(item.page) : null;
        },

        // ---------- 卡片派生（SSR 模板里同名的 PHP 计算，两边必须一致） ----------

        coverStyle(card) {
            const url = card.cover_url ? this.safeCssUrl(card.cover_url) : '';

            return { backgroundImage: url ? 'url("' + url + '")' : '' };
        },

        /** 去掉能逃出 url() 的字符：路径来自后台上传，但样式表达式不该信任任何字符串 */
        safeCssUrl(url) {
            return String(url).replace(/["'\\()]/g, '');
        },

        gradClass(index) {
            return 'fly-grad-' + ((Number(index) - 1) % 8 + 1);
        },

        /** 房型缩略图底色：公式与 hotel.blade.php 的 SSR 版一致，两边不能各写一份 */
        roomGradClass(type) {
            return this.gradClass(Number(type.id));
        },

        tagClass(tag) {
            return tag.tone && tag.tone !== 'gray' ? 'fly-tag fly-tag--' + tag.tone : 'fly-tag';
        },

        scoreText(card) {
            return card.score === null || card.score === undefined ? '' : Number(card.score).toFixed(1);
        },

        reviewLine(card) {
            const parts = [];

            if (card.score_text) {
                parts.push(card.score_text);
            }
            if (card.review_text) {
                parts.push(card.review_text);
            }

            return parts.join(' · ');
        },

        roomRemainClass(type) {
            return Number(type.remaining) <= 2 ? 'fly-room-row__remain text-orange-500' : 'fly-room-row__remain text-green-600';
        },

        /** 详情页房型区的小标题：措辞与 hotel.blade.php 的 SSR 版一致 */
        detailSub() {
            return this.filters.check_in + ' 入住 · ' + this.filters.check_out + ' 离店 · 共 ' + this.nights + ' 晚';
        },

        resultsTitle() {
            return this.filters.city !== '' ? this.filters.city + '酒店' : '酒店推荐';
        },

        resultsSub() {
            if (Number(this.total) > 0) {
                return (
                    '共 ' +
                    this.total +
                    ' 家 · ' +
                    this.filters.check_in +
                    ' 至 ' +
                    this.filters.check_out +
                    '（' +
                    this.nights +
                    ' 晚）· 按' +
                    (this.sorts[this.filters.sort] || '推荐')
                );
            }

            return '编辑精选 · 全网低价 · 真实住客点评';
        },

        hasFilters() {
            return this.filters.city !== '' || this.filters.q !== '';
        },

        emptyFiltered() {
            const parts = [];

            if (this.filters.city !== '') {
                parts.push('城市「' + this.filters.city + '」');
            }
            if (this.filters.q !== '') {
                parts.push('关键词「' + this.filters.q + '」');
            }

            parts.push(this.filters.check_in + ' 至 ' + this.filters.check_out);

            return parts.join(' · ');
        },
    });
}
