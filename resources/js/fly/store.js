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
import { CHINA_CITIES, filterCityGroups } from '../china-cities';

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

        // ---------- 自绘日历（渐进增强：JS 关闭时整块不参与，原生 date 输入兜底） ----------
        calOpen: false,
        calY: 0, // 日历视图显示的年
        calM: 0, // 日历视图显示的月（1-12）
        calPicking: 'in', // in 正在选入住 / out 正在选离店
        calHover: '', // 选离店时悬停预览的日期

        // ---------- 城市面板（同日历：JS 关闭时整块不参与，自由文本输入兜底） ----------
        cityOpen: false,
        cityQuery: '',
        allCities: CHINA_CITIES,

        // ============================================================ 水合与初始化

        /** 必须在 Alpine.start() 之前调用：让 :value / x-text 绑定从第一帧起就是真实数据 */
        hydrate() {
            const config = readFlyConfig();

            this.api = config.api || {};

            // badges 是放宽上限的角标数据（FlyHomeController 下发），cities 只够首页城市卡
            if (Array.isArray(config.badges)) {
                this.cities = config.badges;
            } else if (Array.isArray(config.cities)) {
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

        // ============================================================ 城市面板

        openCityPanel() {
            this.cityQuery = '';
            this.cityOpen = true;
        },

        closeCityPanel() {
            this.cityOpen = false;
        },

        /** 城市面板按钮的选中态：类名写完整字面量，拼接写法会被 Tailwind 内容扫描清除 */
        cityItemClass(city) {
            return this.filters.city === city
                ? 'fly-city-item fly-city-item--active'
                : 'fly-city-item';
        },

        /** 按市名或省名过滤，仍按省分组返回；空省剔除（过滤逻辑与后台 city-picker 共用） */
        cityGroups() {
            return filterCityGroups(this.allCities, this.cityQuery);
        },

        /** 该城市在售酒店数（来自 SSR 水合的 cities），无数据返回 0，模板不渲染角标 */
        cityBadge(city) {
            for (let i = 0; i < this.cities.length; i++) {
                if (this.cities[i].city === city) {
                    return Number(this.cities[i].hotel_count) || 0;
                }
            }

            return 0;
        },

        /** 面板里点城市：与城市卡同一 toggle 语义（再点一次清除），选完关面板并搜索 */
        pickCity(city) {
            this.closeCityPanel();

            return this.selectCity(city);
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

        /** 重置按钮：清空全部条件回到默认搜索（今晚入住 / 明天离店 / 推荐排序） */
        resetFilters() {
            const base = this.today || fromDayMs(Date.now());

            return this.patch({
                city: '',
                q: '',
                sort: 'recommended',
                check_in: base,
                check_out: addDays(base, 1),
            });
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

        /** 重置按钮的 href：与 resetFilters() 同一语义（清空条件 + 回到今晚一晚） */
        resetUrl() {
            const base = this.today || this.filters.check_in;

            return this.buildUrl({
                city: '',
                q: '',
                sort: 'recommended',
                check_in: base,
                check_out: addDays(base, 1),
            });
        },

        // ============================================================ 自绘日历
        // 选择逻辑与 setDates 的钳制规则一致：今天起订、最多往后 MAX_DAYS_AHEAD 天、
        // 一单最多 MAX_NIGHTS 晚。首页选完走 runSearch，详情页走 refreshRoomTypes。

        calBounds() {
            const t = toDayMs(this.today) !== null ? toDayMs(this.today) : Date.now();
            const now = new Date(t);
            const first = Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), 1);
            const end = new Date(t + MAX_DAYS_AHEAD * DAY_MS);

            return [first, Date.UTC(end.getUTCFullYear(), end.getUTCMonth(), 1)];
        },

        openCalendar(picking) {
            const base = DATE_RE.test(this.filters.check_in) ? this.filters.check_in : this.today;

            if (DATE_RE.test(base)) {
                const parts = base.split('-');

                this.calY = Number(parts[0]);
                this.calM = Number(parts[1]);
            } else {
                const now = new Date();

                this.calY = now.getUTCFullYear();
                this.calM = now.getUTCMonth() + 1;
            }

            this.calPicking = picking === 'out' ? 'out' : 'in';
            this.calHover = '';
            this.calOpen = true;

            // 面板可能伸到视口外（矮窗口 + 首页 hero 靠上）：滚动到刚好完整可见
            Alpine.nextTick(() => {
                const el = document.querySelector('.fly-cal');

                if (el) {
                    el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
            });
        },

        calCanShift(delta) {
            const bounds = this.calBounds();

            return delta < 0
                ? Date.UTC(this.calY, this.calM - 2, 1) >= bounds[0]
                : Date.UTC(this.calY, this.calM, 1) <= bounds[1];
        },

        calShiftMonth(delta) {
            if (!this.calCanShift(delta)) {
                return;
            }

            let y = this.calY;
            let m = this.calM + delta;

            if (m < 1) {
                m = 12;
                y--;
            }
            if (m > 12) {
                m = 1;
                y++;
            }

            this.calY = y;
            this.calM = m;
        },

        calTitle() {
            return this.calY + ' 年 ' + this.calM + ' 月';
        },

        calDayState(date) {
            const inDate = this.filters.check_in;

            // 选离店：起点立刻常亮（不等 hover），悬停处实时预览终点与区间
            if (this.calPicking === 'out') {
                if (!inDate) {
                    return '';
                }

                const hover = this.calHover;

                if (date === inDate) {
                    return 'start';
                }

                if (hover > inDate) {
                    if (date === hover) {
                        return 'end';
                    }
                    if (date > inDate && date < hover) {
                        return 'between';
                    }
                }

                return '';
            }

            // 选入住：把当前已选区间亮出来，用户才知道自己改的是哪一段
            const outDate = this.filters.check_out;

            if (inDate && date === inDate) {
                return 'start';
            }
            if (outDate && date === outDate) {
                return 'end';
            }
            if (inDate && outDate && date > inDate && date < outDate) {
                return 'between';
            }

            return '';
        },

        /** 42 格（含月首补位空格），状态与 disabled 一并在后端算好，Blade 只读 */
        calDays() {
            const t = toDayMs(this.today) !== null ? toDayMs(this.today) : Date.now();
            const today = fromDayMs(t);
            const maxDate = fromDayMs(t + MAX_DAYS_AHEAD * DAY_MS);
            const pickingOut = this.calPicking === 'out' && toDayMs(this.filters.check_in) !== null;
            const maxOut = pickingOut ? fromDayMs(toDayMs(this.filters.check_in) + MAX_NIGHTS * DAY_MS) : '';
            const offset = new Date(Date.UTC(this.calY, this.calM - 1, 1)).getUTCDay();
            const daysInMonth = new Date(Date.UTC(this.calY, this.calM, 0)).getUTCDate();
            const cells = [];

            for (let i = 0; i < offset; i++) {
                cells.push({ key: 'blank-' + i, date: '', label: '' });
            }
            for (let d = 1; d <= daysInMonth; d++) {
                const date = fromDayMs(Date.UTC(this.calY, this.calM - 1, d));

                cells.push({
                    key: date,
                    date: date,
                    label: String(d),
                    disabled: date < today || date > maxDate || (maxOut !== '' && date > maxOut),
                    state: this.calDayState(date),
                    today: date === today,
                });
            }

            return cells;
        },

        calClass(cell) {
            // 变体类名必须以完整字面量出现：Tailwind 按源码扫描决定保留哪些规则，
            // 拼接出来的类名（'fly-cal__day--' + state）会被当未使用清除，样式整个失效
            let cls = 'fly-cal__day';

            if (cell.state === 'start') {
                cls += ' fly-cal__day--start';
            } else if (cell.state === 'end') {
                cls += ' fly-cal__day--end';
            } else if (cell.state === 'between') {
                cls += ' fly-cal__day--between';
            } else if (!cell.disabled) {
                cls += ' fly-cal__day--free';
            }

            if (cell.today) {
                cls += ' fly-cal__day--today';
            }

            return cls;
        },

        /** 「10月22日」；跨年时补上年份，避免 12 月订到次年看不出来 */
        calDateText(date) {
            if (typeof date !== 'string' || !DATE_RE.test(date)) {
                return '选择日期';
            }

            const parts = date.split('-');
            const todayYear = DATE_RE.test(this.today) ? Number(this.today.split('-')[0]) : Number(parts[0]);
            const year = Number(parts[0]) !== todayYear ? parts[0] + ' 年 ' : '';

            return year + Number(parts[1]) + ' 月 ' + Number(parts[2]) + ' 日';
        },

        pickCalDay(cell) {
            if (!cell || !cell.date || cell.disabled) {
                return;
            }

            if (this.calPicking === 'out' && cell.date > this.filters.check_in) {
                this.setDates(this.filters.check_in, cell.date);
                this.calPicking = 'in';
                this.calOpen = false;
                this.afterDatePick();

                return;
            }

            // 点了不晚于入住日的日子：视为重新选入住
            this.setDates(cell.date, '');
            this.calPicking = 'out';
        },

        afterDatePick() {
            if (this.mode === 'detail') {
                return this.refreshRoomTypes();
            }

            return this.runSearch();
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
            // 类名写完整字面量：拼接写法会被 Tailwind 内容扫描当成未使用而清除
            if (tag.tone === 'blue') {
                return 'fly-tag fly-tag--blue';
            }
            if (tag.tone === 'green') {
                return 'fly-tag fly-tag--green';
            }

            return 'fly-tag';
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
