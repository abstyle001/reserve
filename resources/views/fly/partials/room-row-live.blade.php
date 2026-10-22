{{--
    房型行（局部刷新版）—— 与 fly/hotel.blade.php 里的 SSR @foreach 成对存在。

    入参字段来自 AvailabilityService::forRoomTypes() + HotelSearchService::bookUrls()，
    与只读接口 /api/fly/hotels/{hotel}/room-types 的 items 完全同一份规格。
    改这里必须同步改 hotel.blade.php 那一侧的行内模板。

    「剩 N 间」的颜色与渐变底色都由 store 计算（roomRemainClass / roomGradClass）：
    元素上不留静态 class，否则 Alpine :class 的字符串语法只会加、摘不掉旧的。
--}}
<template x-for="type in $store.fly.roomTypes" :key="type.id">
    <div class="fly-room-row">
        <div class="fly-room-row__thumb" :class="$store.fly.roomGradClass(type)"></div>

        <div class="min-w-0 flex-1">
            <h3 class="fly-hotel-name" x-text="type.name"></h3>
            <p class="mt-1 text-xs text-gray-400"
               x-text="(type.bed_type || '—') + ' · ' + type.capacity + ' 人 · ' + type.area + ' ㎡ · 共 ' + type.rooms_total + ' 间'"></p>
            <p class="fly-hotel-loc mt-1 line-clamp-2" x-show="type.description" x-text="type.description"></p>
        </div>

        <div class="shrink-0 text-right">
            <p class="fly-room-row__remain text-orange-500" x-show="type.sold_out">已售罄</p>
            <p class="mt-1 text-sm font-semibold text-gray-700 tabular-nums" x-show="type.sold_out">¥<span x-text="type.price_text"></span></p>
            <span class="fly-filter-btn mt-2 cursor-default opacity-60" x-show="type.sold_out">换日期试试</span>

            <p class="fly-room-row__remain" :class="$store.fly.roomRemainClass(type)"
               x-show="! type.sold_out" x-text="'剩 ' + type.remaining + ' 间'"></p>
            <p class="mt-1 text-sm font-semibold text-gray-900 tabular-nums" x-show="! type.sold_out">
                ¥<span x-text="type.price_text"></span><span class="text-xs font-normal text-gray-400"> /晚</span>
            </p>
            {{-- 预订走 SSR 表单页（写操作不经过 JS），这里只负责把当前日期窗口拼进链接 --}}
            <a class="fly-btn-primary mt-2" :href="type.book_url" x-show="! type.sold_out">预订</a>
        </div>
    </div>
</template>
