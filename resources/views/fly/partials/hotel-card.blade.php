{{--
    酒店卡片（SSR 版）—— 视觉规格的唯一出处。

    入参 $card = HotelSearchService::formatCard() 的输出，字段名与
    fly/partials/hotel-card-live.blade.php（Alpine x-for 版）一一对应；
    改这里的类名或结构，必须同步改那边 —— 两份只差取数方式（$card['x'] vs card.x）。
--}}
@php
    $tone = function ($tag) {
        return $tag['tone'] === 'gray' ? 'fly-tag' : 'fly-tag fly-tag--' . $tag['tone'];
    };
@endphp
<a href="{{ $card['detail_url'] }}" class="group fly-hotel-card">
    <div class="fly-hotel-card__media fly-grad-{{ $card['grad_index'] }}"@if ($card['cover_url']) style="background-image:url('{{ $card['cover_url'] }}')"@endif>
        @if ($card['badge'])
            <span class="fly-hotel-card__badge">{{ $card['badge'] }}</span>
        @endif
        <div class="fly-hotel-card__price">
            @if ($card['sold_out'])
                <span>该日期</span><b class="text-gray-400">已售罄</b>
            @else
                <span>¥</span><b class="tabular-nums">{{ $card['min_price_text'] }}</b><span>起</span>
            @endif
        </div>
    </div>
    <div class="fly-hotel-card__body">
        <div class="flex items-center justify-between gap-2">
            <h3 class="fly-hotel-name">{{ $card['name'] }}</h3>
            @if ($card['star_level'])
                <span class="fly-stars" aria-label="{{ $card['star_level'] }} 星级">
                    @for ($i = 0; $i < $card['star_level']; $i++)
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                    @endfor
                </span>
            @endif
        </div>
        @if ($card['loc'])
            <p class="fly-hotel-loc">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>
                </svg>
                {{ $card['loc'] }}
            </p>
        @endif
        @if (!empty($card['tags']))
            <div class="fly-hotel-tags">
                @foreach ($card['tags'] as $tag)
                    <span class="{{ $tone($tag) }}">{{ $tag['text'] }}</span>
                @endforeach
            </div>
        @endif
        <div class="fly-hotel-foot">
            <div>
                @if ($card['score'] !== null)
                    <span class="fly-score tabular-nums">{{ number_format($card['score'], 1) }}</span>
                @endif
                @if ($card['score_text'] || $card['review_text'])
                    <span class="fly-score-txt">{{ trim(($card['score_text'] ? $card['score_text'] . ' · ' : '') . $card['review_text']) }}</span>
                @endif
            </div>
            @if ($card['sold_out'])
                <span class="text-sm font-medium text-gray-400">换日期试试</span>
            @else
                <span class="fly-price tabular-nums">¥{{ $card['min_price_text'] }}<small> 起</small></span>
            @endif
        </div>
    </div>
</a>
