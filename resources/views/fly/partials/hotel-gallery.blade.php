{{--
    酒店详情头图轮播（/fly/hotels/{hotel} 概览卡左侧图区）。

    渐进增强：全部图片 SSR 成叠放 <img>，无 JS 时只显示第一张（object-cover 完整填充，
    不再用背景图）；箭头 / 圆点 / 计数包 x-cloak 自动隐身。轮播索引是纯展示型局部状态，
    放组件级 x-data 即可 —— 不在任何 x-for 里，不会遮蔽 $store.fly。

    键盘绑在本节点（tabindex="0"），不要改成 .window —— 会劫持右侧日期 input 的方向键。
    本节点 overflow-hidden 只作用于图片区叶子，不影响日期日历从概览卡溢出
    （见 hotel.blade.php 概览区注释）。
--}}
@php
    $urls = $hotel->img_urls;
    $n = count($urls);
@endphp

@if ($n === 0)
    {{-- 无图：保持渐变占位 --}}
    <div class="relative h-48 rounded-t-[calc(1.5rem-1px)] fly-grad-{{ $card['grad_index'] }} lg:h-full lg:rounded-l-[calc(1.5rem-1px)] lg:rounded-tr-none">
        @if ($card['badge'])
            <span class="fly-hotel-card__badge">{{ $card['badge'] }}</span>
        @endif
    </div>
@else
    <section
        class="relative h-48 overflow-hidden rounded-t-[calc(1.5rem-1px)] fly-grad-{{ $card['grad_index'] }} lg:h-full lg:rounded-l-[calc(1.5rem-1px)] lg:rounded-tr-none"
        x-data="{ i: 0, n: {{ $n }}, x0: null,
                  prev() { this.i = (this.i - 1 + this.n) % this.n },
                  next() { this.i = (this.i + 1) % this.n },
                  go(k) { this.i = k } }"
        tabindex="0" role="region" aria-label="酒店图片轮播"
        @keydown.left.prevent="prev()"
        @keydown.right.prevent="next()"
        @touchstart="x0 = $event.touches[0].clientX"
        @touchend="if (x0 !== null && Math.abs($event.changedTouches[0].clientX - x0) > 40) { $event.changedTouches[0].clientX < x0 ? next() : prev(); x0 = null }">

        {{-- 叠放图片：第 1 张无 inline style（无 JS 时可见）；第 2 张起 display:none + x-show 双保险 --}}
        @foreach ($urls as $k => $url)
            <img src="{{ $url }}" alt="{{ $card['name'] }} 图片{{ $k + 1 }}"
                 class="fly-gallery__slide h-full w-full object-cover"
                 @if ($k > 0) style="display:none" loading="lazy"@endif
                 x-show="i === {{ $k }}"
                 x-transition:enter="fly-gallery__fade"
                 x-transition:enter-start="fly-gallery__fade--start"
                 x-transition:enter-end="fly-gallery__fade">
        @endforeach

        @if ($card['badge'])
            <span class="fly-hotel-card__badge">{{ $card['badge'] }}</span>
        @endif

        @if ($n > 1)
            <div class="fly-gallery__ctrl" x-cloak>
                <button type="button" class="fly-gallery__arrow" aria-label="上一张" @click="prev()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m15 18-6-6 6-6"/>
                    </svg>
                </button>
                <button type="button" class="fly-gallery__arrow fly-gallery__arrow--r" aria-label="下一张" @click="next()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m9 18 6-6-6-6"/>
                    </svg>
                </button>

                <span class="fly-gallery__count tabular-nums" x-text="(i + 1) + ' / ' + n">1 / {{ $n }}</span>

                <div class="fly-gallery__dots">
                    @foreach ($urls as $k => $url)
                        <button type="button" class="fly-gallery__dot"
                                :aria-current="i === {{ $k }} ? 'true' : null"
                                aria-label="第 {{ $k + 1 }} 张"
                                @click="go({{ $k }})"></button>
                    @endforeach
                </div>
            </div>
        @endif
    </section>
@endif
