{{--
    房型行（SSR 版）—— 入参 $type = HotelSearchService::roomTypesFor()['items'] 的一行。

    与 fly/partials/room-row-live.blade.php（Alpine x-for 版）成对存在：
    类名与结构必须逐字一致，只是取数方式不同（$type['x'] vs type.x）。
    字段规格以 AvailabilityService::forRoomTypes() 为准，改一边就改另一边。

    「剩 N 间」的颜色与缩略图渐变在这里由 Blade 计算，live 版交给
    store 的 roomRemainClass() / roomGradClass()，两边公式相同。
--}}
<div class="fly-room-row">
    <div class="fly-room-row__thumb fly-grad-{{ (($type['id'] - 1) % 8) + 1 }}"></div>

    <div class="min-w-0 flex-1">
        <h3 class="fly-hotel-name">{{ $type['name'] }}</h3>
        <p class="mt-1 text-xs text-gray-400">
            {{ $type['bed_type'] ?: '—' }} · {{ $type['capacity'] }} 人 · {{ $type['area'] }} ㎡
            · 共 {{ $type['rooms_total'] }} 间
        </p>
        @if ($type['description'])
            <p class="fly-hotel-loc mt-1 line-clamp-2">{{ $type['description'] }}</p>
        @endif
    </div>

    <div class="shrink-0 text-right">
        @if ($type['sold_out'])
            <p class="fly-room-row__remain text-orange-500">已售罄</p>
            <p class="mt-1 text-sm font-semibold text-gray-700 tabular-nums">¥{{ $type['price_text'] }}</p>
            <span class="fly-filter-btn mt-2 cursor-default opacity-60">换日期试试</span>
        @else
            <p class="fly-room-row__remain {{ $type['remaining'] <= 2 ? 'text-orange-500' : 'text-green-600' }}">
                剩 {{ $type['remaining'] }} 间
            </p>
            <p class="mt-1 text-sm font-semibold text-gray-900 tabular-nums">
                ¥{{ $type['price_text'] }}<span class="text-xs font-normal text-gray-400"> /晚</span>
            </p>
            <a href="{{ $type['book_url'] }}" class="fly-btn-primary mt-2">预订</a>
        @endif
    </div>
</div>
