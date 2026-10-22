{{--
    云途旅行 C 端首页（/fly）。

    数据来自 FlyHomeController → HotelSearchService::search()，
    卡片规格见 fly/partials/hotel-card.blade.php，与 resources/js/fly/store.js 的
    <template x-for> 共用同一份 JSON（formatCard 是唯一载荷规格）。

    渐进增强（resources/js/fly/）在本页只做一件事：把「搜索条件变了 → 整页刷新」
    换成局部刷新。分工按元素数量划：
      - 单例控件（表单、标题、chip、城市卡、分页链接）→ :href / :value / :class / x-text
        直接绑在 SSR 元素上，不复制第二份 DOM。
      - 会变的列表（酒店卡、页码项）→ SSR 容器 x-show="!resultsDirty" +
        fly/partials/results-live.blade.php 里的 x-for 版本。
    tab 是纯本地态：只有 hotel 有数据，跳 URL 会让无数据 tab 变成空页。
    左栏服务分类保持占位（<span> 无键盘可达性，加了点击反而给出错误暗示）。
    关掉 JS：下面所有 Alpine 属性都是惰性字符串，真 GET 表单、真链接、真 ?page=2 照常工作。
--}}
@extends('fly.layout')

@php
    $urls = app(\App\Services\Fly\HotelSearchService::class);
    $title = $filters['city'] !== ''
        ? $filters['city'] . '酒店预订' . ($filters['q'] !== '' ? ' · ' . $filters['q'] : '') . ' — 云途旅行'
        : '云途旅行 — 酒店 · 机票 · 火车票一站式预订';
@endphp

@section('title', $title)

@section('content')
    {{-- ===================== Hero：业务分类 + 酒店预订面板 ===================== --}}
    <section class="fly-hero">
        <div class="fly-container pb-10 pt-8 lg:pt-12">
            <div class="fly-panel lg:grid-cols-[15rem_minmax(0,1fr)]">

                {{-- 左侧旅行服务分类：只有酒店已接真实数据，其余为占位 --}}
                <aside class="fly-sidebar">
                    <p class="fly-side-title">旅行服务</p>

                    <a href="{{ route('fly.home') }}" class="fly-side-item fly-side-item--active">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/>
                        </svg>
                        酒店
                    </a>
                    <span class="fly-side-item cursor-default" title="尚未开放">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>
                        </svg>
                        机票
                    </span>
                    <span class="fly-side-item cursor-default" title="尚未开放">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M8 3.1V7a4 4 0 0 0 8 0V3.1"/>
                            <path d="m9 15-1-1"/><path d="m15 15 1-1"/>
                            <path d="M9 17H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-4"/>
                            <path d="M8 17v3"/><path d="M16 17v3"/>
                        </svg>
                        火车票
                    </span>
                    <span class="fly-side-item cursor-default" title="尚未开放">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"/>
                            <polygon points="16.24 7.76 14.12 14.12 7.76 16.24 9.88 9.88 16.24 7.76"/>
                        </svg>
                        旅行
                    </span>
                    <span class="fly-side-item cursor-default" title="尚未开放">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2Z"/>
                            <path d="M13 5v2"/><path d="M13 17v2"/><path d="M13 11v2"/>
                        </svg>
                        门票
                    </span>
                    <span class="fly-side-item cursor-default" title="尚未开放">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M8 6v6"/><path d="M15 6v6"/><path d="M2 12h19.6"/>
                            <path d="M18 18h3s.5-1.7.8-2.8c.1-.4.2-.8.2-1.2 0-.4-.1-.8-.2-1.2l-1.4-5C20.1 6.8 19.1 6 18 6H4a2 2 0 0 0-2 2v10h3"/>
                            <circle cx="7" cy="18" r="2"/><path d="M9 18h5"/><circle cx="16" cy="18" r="2"/>
                        </svg>
                        汽车票
                    </span>
                    <span class="fly-side-item cursor-default" title="尚未开放">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="5" r="3"/>
                            <line x1="12" x2="12" y1="22" y2="8"/>
                            <path d="M5 12H2a10 10 0 0 0 20 0h-3"/>
                        </svg>
                        船票
                    </span>
                </aside>

                {{-- 中间：预订 tab + 搜索表单 --}}
                <div class="fly-search">
                    <div class="fly-tabs" role="tablist">
                        <button type="button" class="fly-tab fly-tab--active" role="tab" aria-selected="true"
                                :class="{ 'fly-tab--active': $store.fly.tab === 'hotel' }"
                                @click="$store.fly.applyTab('hotel', '酒店')">酒店</button>
                        <button type="button" class="fly-tab opacity-50" role="tab" aria-selected="false" title="尚未开放"
                                @click="$store.fly.applyTab('flight', '机票')">机票</button>
                        <button type="button" class="fly-tab opacity-50" role="tab" aria-selected="false" title="尚未开放"
                                @click="$store.fly.applyTab('train', '火车票')">火车票</button>
                        <button type="button" class="fly-tab opacity-50" role="tab" aria-selected="false" title="尚未开放"
                                @click="$store.fly.applyTab('ticket', '门票')">门票</button>
                        <button type="button" class="fly-tab opacity-50" role="tab" aria-selected="false" title="尚未开放"
                                @click="$store.fly.applyTab('bus', '汽车票')">汽车票</button>
                        <button type="button" class="fly-tab opacity-50" role="tab" aria-selected="false" title="尚未开放"
                                @click="$store.fly.applyTab('ship', '船票')">船票</button>
                    </div>

                    {{-- 未开放业务的一句话提示（JS 关闭时 x-cloak 让它永不出现） --}}
                    <p class="mt-2 text-xs text-orange-500" x-show="$store.fly.tabHint" x-cloak
                       x-text="$store.fly.tabHint"></p>

                    @include('fly.partials.search-form')

                    {{-- 热门搜索 + 排序（lg 以上排序 chip 靠右，与热门词同一行）
                         关键词/排序值走 data-* 而不是拼进 JS 表达式：Blade 的 {{ }} 负责 HTML 转义，
                         dataset 读回来就是原字符串，表达式里不出现任何用户可见文本。 --}}
                    <div class="fly-quickrow">
                        <span class="text-xs text-gray-400">热门搜索</span>
                        @foreach ($filters['hot_keywords'] as $keyword)
                            <a href="{{ $urls->pageUrl($filters, ['q' => $keyword, 'page' => null]) }}"
                               data-keyword="{{ $keyword }}"
                               class="fly-chip{{ $filters['q'] === $keyword ? ' fly-chip--active' : '' }}"
                               :class="{ 'fly-chip--active': $store.fly.filters.q === $el.dataset.keyword }"
                               :href="$store.fly.keywordUrl($el.dataset.keyword)"
                               @click.prevent="$store.fly.searchKeyword($el.dataset.keyword)">{{ $keyword }}</a>
                        @endforeach

                        <span class="ml-auto hidden items-center gap-2 lg:flex">
                            <span class="text-xs text-gray-400">排序</span>
                            @foreach ($filters['sorts'] as $key => $label)
                                <a href="{{ $urls->pageUrl($filters, ['sort' => $key, 'page' => null]) }}"
                                   data-sort="{{ $key }}"
                                   class="fly-filter-btn{{ $filters['sort'] === $key ? ' fly-filter-btn--active' : '' }}"
                                   :class="{ 'fly-filter-btn--active': $store.fly.filters.sort === $el.dataset.sort }"
                                   :href="$store.fly.sortUrl($el.dataset.sort)"
                                   @click.prevent="$store.fly.setSort($el.dataset.sort)">{{ $label }}</a>
                            @endforeach
                        </span>
                    </div>

                    {{-- lg 以下排序另起一行，4 个 chip 挤不进同一行 --}}
                    <div class="fly-quickrow lg:hidden">
                        <span class="text-xs text-gray-400">排序</span>
                        @foreach ($filters['sorts'] as $key => $label)
                            <a href="{{ $urls->pageUrl($filters, ['sort' => $key, 'page' => null]) }}"
                               data-sort="{{ $key }}"
                               class="fly-filter-btn{{ $filters['sort'] === $key ? ' fly-filter-btn--active' : '' }}"
                               :class="{ 'fly-filter-btn--active': $store.fly.filters.sort === $el.dataset.sort }"
                               :href="$store.fly.sortUrl($el.dataset.sort)"
                               @click.prevent="$store.fly.setSort($el.dataset.sort)">{{ $label }}</a>
                        @endforeach
                    </div>

                    {{-- 营销位：与搜索表单同一列，把右侧撑到和左栏分类差不多高 --}}
                    <div class="fly-promo">
                        <span class="fly-promo__badge">新客专享</span>
                        <p class="text-sm text-gray-600">首单立减 <span class="font-bold text-orange-500">¥50</span>
                            <span class="ml-1 text-gray-400">· 覆盖全部在售酒店</span></p>
                        <span class="fly-promo__soon">敬请期待</span>
                    </div>
                </div>
            </div>

            @include('fly.partials.promise-bar')
        </div>
    </section>

    {{-- ===================== 酒店列表 ===================== --}}
    <section class="fly-container fly-section">
        <div class="fly-section-head">
            <div>
                <h2 class="fly-section-title" x-text="$store.fly.resultsTitle()">{{ $filters['city'] !== '' ? $filters['city'] . '酒店' : '酒店推荐' }}</h2>
                <p class="fly-section-sub" x-text="$store.fly.resultsSub()">
                    @if ($result['total'] > 0)
                        共 {{ $result['total'] }} 家 · {{ $filters['check_in'] }} 至 {{ $filters['check_out'] }}（{{ $filters['nights'] }} 晚）· 按{{ $filters['sorts'][$filters['sort']] }}
                    @else
                        编辑精选 · 全网低价 · 真实住客点评
                    @endif
                </p>
            </div>
            {{-- 「查看全部」是单例：不复制两份，未筛选时用 inline style 隐藏。
                 Alpine 的 x-show 会 setProperty/removeProperty 改 display，
                 所以 JS 打开后它跟着 hasFilters() 走；JS 关闭时 inline style 就是最终态。 --}}
            <a href="{{ $urls->pageUrl($filters, ['city' => '', 'q' => '', 'page' => null]) }}" class="fly-more"
               @if ($filters['city'] === '' && $filters['q'] === '') style="display:none"@endif
               x-show="$store.fly.hasFilters()" :href="$store.fly.clearUrl()" @click.prevent="$store.fly.clearFilters()">
                查看全部酒店
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m9 18 6-6-6-6"/>
                </svg>
            </a>
        </div>

        {{-- SSR 结果：首屏由 Blade 出，Alpine 起来后 resultsDirty 仍是 false，所以不会闪 --}}
        <div x-show="! $store.fly.resultsDirty">
            @if (count($result['items']) > 0)
                <div class="fly-hotel-grid">
                    @foreach ($result['items'] as $card)
                        @include('fly.partials.hotel-card', ['card' => $card])
                    @endforeach
                </div>

                @include('fly.partials.pagination')
            @else
                @include('fly.partials.empty-state')
            @endif
        </div>

        {{-- 局部刷新结果：第一次请求之后接管 --}}
        @include('fly.partials.results-live')
    </section>

    {{-- ===================== 热门目的地 ===================== --}}
    @if (count($cities) > 0)
        <section class="fly-container fly-section pt-0 lg:pt-0">
            <div class="fly-section-head">
                <div>
                    <h2 class="fly-section-title">热门目的地</h2>
                    <p class="fly-section-sub">按在售酒店数排序 · 点进去保留你选的入住日期</p>
                </div>
            </div>

            <div class="fly-city-grid">
                @foreach ($cities as $i => $city)
                    @include('fly.partials.city-card', [
                        'city' => array_merge($city, [
                            'url' => $urls->pageUrl($filters, ['city' => $city['city'], 'page' => null]),
                            'grad_index' => ($i % 8) + 1,
                        ]),
                    ])
                @endforeach
            </div>
        </section>
    @endif
@endsection
