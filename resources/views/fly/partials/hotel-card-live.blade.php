{{--
    酒店卡片（局部刷新版）—— 与 fly/partials/hotel-card.blade.php 成对存在。

    两份的类名与结构必须逐字一致，只是取数方式不同：
      SSR 版用 $card['xxx']，本文件用 card.xxx（同一个 formatCard() 载荷）。
    字段清单以 App\Services\Fly\HotelSearchService::formatCard() 为准；
    那边加/改字段，这里必须同步，否则 JS 开与关会看到两种卡片。

    badge 不在这里渲染：formatCard() 目前恒为 null，SSR 版的 @if 分支同样不会命中。
--}}
<template x-for="card in $store.fly.hotels" :key="card.id">
    <a :href="card.detail_url" class="group fly-hotel-card">
        <div class="fly-hotel-card__media"
             :class="'fly-grad-' + card.grad_index"
             :style="$store.fly.coverStyle(card)">
            <div class="fly-hotel-card__price">
                <span x-text="card.sold_out ? '该日期' : '¥'"></span>
                <b class="tabular-nums"
                   :class="{ 'text-gray-400': card.sold_out }"
                   x-text="card.sold_out ? '已售罄' : card.min_price_text"></b>
                <span x-show="! card.sold_out">起</span>
            </div>
        </div>

        <div class="fly-hotel-card__body">
            <div class="flex items-center justify-between gap-2">
                <h3 class="fly-hotel-name" x-text="card.name"></h3>
                <span class="fly-stars" x-show="card.star_level" :aria-label="card.star_level + ' 星级'">
                    {{-- Alpine 的 x-for 支持数字：x-for="i in 5" 迭代 1..5，不必后端下发数组 --}}
                    <template x-for="star in card.star_level" :key="star">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                    </template>
                </span>
            </div>

            <p class="fly-hotel-loc" x-show="card.loc">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>
                </svg>
                <span x-text="card.loc"></span>
            </p>

            <div class="fly-hotel-tags" x-show="card.tags.length > 0">
                <template x-for="(tag, i) in card.tags" :key="i">
                    <span :class="$store.fly.tagClass(tag)" x-text="tag.text"></span>
                </template>
            </div>

            <div class="fly-hotel-foot">
                <div>
                    <span class="fly-score tabular-nums" x-show="card.score !== null" x-text="$store.fly.scoreText(card)"></span>
                    <span class="fly-score-txt" x-show="card.score_text || card.review_text" x-text="$store.fly.reviewLine(card)"></span>
                </div>
                <span class="text-sm font-medium text-gray-400" x-show="card.sold_out">换日期试试</span>
                <span class="fly-price tabular-nums" x-show="! card.sold_out">¥<span x-text="card.min_price_text"></span><small> 起</small></span>
            </div>
        </div>
    </a>
</template>
