{{--
    搜索结果区（局部刷新版）：卡片网格 + 分页 + 空态 + 加载/离线提示。

    与 SSR 版成对存在，开关是 $store.fly.resultsDirty：
      SSR（首屏，JS 关闭时永久可见）：home.blade.php 里 x-show="!$store.fly.resultsDirty" 的容器
      live（本文件，第一次局部刷新后接管）：x-show="$store.fly.resultsDirty" x-cloak
    为什么要两份 —— Blade 展开的静态 DOM 没法 x-for，而 Alpine 又不能替首屏出数据。
    这是渐进增强的固有成本：改卡片/分页/空态的视觉时，两份都要改。
    对照文件：fly/partials/{hotel-card,pagination,empty-state}.blade.php

    模板里不写业务判断：状态类、页码窗口、渐变底色全部走 $store.fly 的方法，
    与后端 HotelSearchService 的同一套口径保持同步。
--}}
<div x-show="$store.fly.resultsDirty" x-cloak>
    <p class="mt-4 text-center text-xs text-gray-400" x-show="$store.fly.loading" x-cloak>正在更新搜索结果…</p>
    <p class="mt-4 text-center text-xs text-orange-500" x-show="! $store.fly.online" x-cloak
       x-text="$store.fly.error || '网络异常，房量数据可能不是最新，请稍后重试'"></p>

    <div class="fly-hotel-grid" x-show="$store.fly.hotels.length > 0">
        @include('fly.partials.hotel-card-live')
    </div>

    {{-- 空态：与 empty-state.blade.php 的两个分支一一对应 --}}
    <div class="fly-empty" x-show="$store.fly.hotels.length === 0 && ! $store.fly.loading">
        <span class="fly-empty__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"/>
                <path d="m21 21-4.3-4.3"/>
                <path d="M8 11h6"/>
            </svg>
        </span>

        <p class="fly-empty__title" x-show="$store.fly.hasFilters()">没有找到符合条件的酒店</p>
        <p class="fly-empty__sub" x-show="$store.fly.hasFilters()" x-text="$store.fly.emptyFiltered()"></p>
        <div class="mt-5 flex flex-wrap items-center justify-center gap-2" x-show="$store.fly.hasFilters()">
            <a class="fly-btn-outline" :href="$store.fly.clearUrl()" @click.prevent="$store.fly.clearFilters()">清空筛选条件</a>
            <a class="fly-chip" x-show="$store.fly.filters.city !== ''" :href="$store.fly.noCityUrl()"
               @click.prevent="$store.fly.patch({ city: '' })">不限城市</a>
            <a class="fly-chip" x-show="$store.fly.filters.q !== ''" :href="$store.fly.noKeywordUrl()"
               @click.prevent="$store.fly.patch({ q: '' })">去掉关键词</a>
        </div>

        <p class="fly-empty__title" x-show="! $store.fly.hasFilters()">还没有上架的酒店</p>
        <p class="fly-empty__sub" x-show="! $store.fly.hasFilters()">后台「酒店管理」里新增酒店、房型和房间，前台即时可见。</p>
        @auth
            <div class="mt-5" x-show="! $store.fly.hasFilters()">
                <a href="{{ route('hotels.create') }}" class="fly-btn-primary">去后台添加</a>
            </div>
        @endauth
    </div>

    <nav class="fly-pager" aria-label="酒店搜索结果分页" x-show="$store.fly.lastPage > 1">
        {{-- 分页项三类（上一页/下一页、页码、省略号）样式名不同，故 class 只由 store 给出：
             元素上留任何静态 class 都会被 :class 的字符串语法「加」上去而摘不掉。 --}}
        <template x-for="(item, index) in $store.fly.pagerItems()" :key="index">
            <a :class="$store.fly.pagerClass(item)"
               :href="$store.fly.pagerHref(item)"
               x-text="item.label"
               @click.prevent="$store.fly.onPagerClick(item)"></a>
        </template>

        <span class="fly-pager__total" x-text="'共 ' + $store.fly.total + ' 家'"></span>
    </nav>
</div>
