{{--
    酒店详情（/fly/hotels/{hotel}）：房态概览 + 指定日期区间的房型可订间数。

    房量口径由 AvailabilityService 统一算出，与首页卡片、下单页、只读 JSON 完全一致；
    本页绝不在模板里做减法（rooms_total − remaining 之类）。
    「预订」按钮指向下单页，链接由 bookUrls() 生成并带上当前日期窗口。

    渐进增强：改日期 → $store.fly.refreshRoomTypes() 局部重排房型列表
    （对照模板 fly/partials/room-row-live.blade.php）；概览区的单价、剩余间数、
    面包屑与「看同城」链接的 :href 跟着 store 的日期走。
    点「预订」仍然是整页跳转 —— 写操作不进 JS。
--}}
@extends('fly.layout')

@php
    $search = app(\App\Services\Fly\HotelSearchService::class);
    // tone 类名写完整字面量：拼接写法会被 Tailwind 内容扫描当未使用清除，颜色整个失效
    $tone = function ($tag) {
        if ($tag['tone'] === 'blue') {
            return 'fly-tag fly-tag--blue';
        }
        if ($tag['tone'] === 'green') {
            return 'fly-tag fly-tag--green';
        }

        return 'fly-tag';
    };
@endphp

@section('title', $card['name'] . ' 预订 · ' . $filters['check_in'] . ' 至 ' . $filters['check_out'] . ' — 云途旅行')

@section('content')
    <div class="fly-container">
        {{-- 面包屑的 :href 跟着 store 的日期走：用户在本页改过日期后再回列表，
             链接不该还是首屏那晚（写操作不进 JS，这里只是把 GET 链接算准）。 --}}
        <nav class="fly-crumb" aria-label="面包屑">
            <a href="{{ route('fly.home') }}">首页</a>
            <span aria-hidden="true">/</span>
            @if ($card['city'])
                <a href="{{ $search->pageUrl($filters, ['city' => $card['city'], 'q' => '', 'page' => null]) }}"
                   data-city="{{ $card['city'] }}" :href="$store.fly.listUrl($el.dataset.city)">{{ $card['city'] }}酒店</a>
                <span aria-hidden="true">/</span>
            @else
                <a href="{{ $search->pageUrl($filters, ['q' => '', 'page' => null]) }}"
                   :href="$store.fly.listUrl('')">全部酒店</a>
                <span aria-hidden="true">/</span>
            @endif
            <span class="text-gray-600">{{ $card['name'] }}</span>
        </nav>
    </div>

    {{-- ===================== 酒店概览 ===================== --}}
    <section class="fly-container pb-2">
        {{-- 容器不能 overflow-hidden：日期日历要从概览卡里溢出来（裁掉就选不到下半月），
             圆角改由封面图自己承担 --}}
        <div class="grid grid-cols-1 rounded-3xl border border-gray-100 bg-white shadow-sm lg:grid-cols-[22rem_1fr]">
            <div class="relative h-48 rounded-t-[calc(1.5rem-1px)] fly-grad-{{ $card['grad_index'] }} lg:h-full lg:rounded-l-[calc(1.5rem-1px)] lg:rounded-tr-none"@if ($card['cover_url']) style="background-image:url('{{ $card['cover_url'] }}')"@endif>
                @if ($card['badge'])
                    <span class="fly-hotel-card__badge">{{ $card['badge'] }}</span>
                @endif
            </div>

            <div class="p-6 lg:p-8">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-gray-900 lg:text-[1.75rem]">{{ $card['name'] }}</h1>
                        <div class="mt-2 flex items-center gap-3">
                            @if ($card['star_level'])
                                <span class="fly-stars" aria-label="{{ $card['star_level'] }} 星级">
                                    @for ($i = 0; $i < $card['star_level']; $i++)
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                                    @endfor
                                </span>
                            @endif
                            @if ($card['score'] !== null)
                                <span class="text-sm text-gray-500">
                                    <b class="fly-score tabular-nums">{{ number_format($card['score'], 1) }}</b>
                                    @if ($card['score_text'])<span class="ml-1">{{ $card['score_text'] }}</span>@endif
                                    @if ($card['review_text'])<span class="ml-1 text-gray-400">· {{ $card['review_text'] }}</span>@endif
                                </span>
                            @endif
                        </div>
                    </div>

                    <div class="text-right">
                        {{-- 起价与剩余间数都是日期的函数：改日期后由 store 覆盖，
                             口径来自后端（formatCard 的 min_price / remaining_total），模板不做减法。

                             两个分支都渲染 + Blade 侧 inline style 控制首屏：只留 @if 的话，
                             JS 打开后 sold_out 翻转时 DOM 里根本没有那个元素；
                             只留 x-show 的话，JS 关闭时会同时显示「已订满」和空的价签。
                             Alpine 的 x-show 会 removeProperty('display')，所以 inline style 只是首屏兜底。 --}}
                        <p class="text-sm font-medium text-gray-400"@if (! $card['sold_out']) style="display:none"@endif
                           x-show="$store.fly.card && $store.fly.card.sold_out">该日期已订满</p>
                        <p class="fly-price tabular-nums"@if ($card['sold_out']) style="display:none"@endif
                           x-show="! ($store.fly.card && $store.fly.card.sold_out)">¥<span
                                x-text="$store.fly.card ? $store.fly.card.min_price_text : ''">{{ $card['min_price_text'] }}</span><small> 起/晚</small></p>
                        <p class="mt-1 text-xs text-gray-400"
                           x-text="'当前日期还剩 ' + ($store.fly.card ? $store.fly.card.remaining_total : 0) + ' 间'">当前日期还剩 {{ $card['remaining_total'] }} 间</p>
                    </div>
                </div>

                @if ($card['loc'])
                    <p class="fly-hotel-loc mt-4">
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

                @if ($hotel->phone)
                    <p class="mt-3 text-xs text-gray-400">前台电话：{{ $hotel->phone }}</p>
                @endif

                {{-- 改日期 = 重新查房量：只走 GET，JS 关闭时同样可用（原生 date 输入兜底）。
                     JS 开启时输入框隐藏、换自绘日历（与首页搜索表单共用 calendar-panel），
                     值仍写回同一组 name 供 readDetailForm 读取 --}}
                <form class="relative mt-6 flex flex-wrap items-center gap-3"
                      action="{{ route('fly.hotels.show', $card['id']) }}" method="get"
                      @submit.prevent="$store.fly.submitDetail($el)"
                      @change="$store.fly.onDetailChange($el)"
                      @click.outside="$store.fly.calOpen = false"
                      @keydown.escape.window="$store.fly.calOpen = false">
                    <div class="fly-field w-44">
                        <div class="min-w-0">
                            <label class="fly-field__label" for="d-check-in">入住</label>
                            <input id="d-check-in" type="date" name="check_in"
                                   value="{{ $filters['check_in'] }}" min="{{ $filters['today'] }}"
                                   :value="$store.fly.filters.check_in" x-show="false">
                            <button type="button" class="fly-field__value text-left" x-cloak
                                    @click="$store.fly.openCalendar('in')"
                                    x-text="$store.fly.calDateText($store.fly.filters.check_in)"></button>
                        </div>
                    </div>
                    <div class="fly-field w-44">
                        <div class="min-w-0">
                            <label class="fly-field__label" for="d-check-out">离店</label>
                            <input id="d-check-out" type="date" name="check_out" value="{{ $filters['check_out'] }}"
                                   :value="$store.fly.filters.check_out" x-show="false">
                            <button type="button" class="fly-field__value text-left" x-cloak
                                    @click="$store.fly.openCalendar('out')"
                                    x-text="$store.fly.calDateText($store.fly.filters.check_out)"></button>
                        </div>
                    </div>
                    <span class="fly-nights" x-text="$store.fly.nights + ' 晚'">{{ $filters['nights'] }} 晚</span>
                    <button type="submit" class="fly-btn-outline" :disabled="$store.fly.detailLoading">查询房量</button>

                    @include('fly.partials.calendar-panel')
                </form>
            </div>
        </div>
    </section>

    {{-- ===================== 房型与可订间数 ===================== --}}
    <section class="fly-container fly-section">
        <div class="fly-section-head">
            <div>
                <h2 class="fly-section-title">房型与价格</h2>
                <p class="fly-section-sub" x-text="$store.fly.detailSub()">{{ $filters['check_in'] }} 入住 · {{ $filters['check_out'] }} 离店 · 共 {{ $filters['nights'] }} 晚</p>
            </div>
            <a href="{{ $search->pageUrl($filters, ['city' => $card['city'], 'q' => '', 'page' => null]) }}" class="fly-more"
               data-city="{{ $card['city'] }}" :href="$store.fly.listUrl($el.dataset.city)">
                {{ $card['city'] ? '看同城其他酒店' : '返回酒店列表' }}
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m9 18 6-6-6-6"/>
                </svg>
            </a>
        </div>

        @if (count($roomTypes) === 0)
            {{-- 这家酒店压根没配房型：局部刷新也不会变出房型来，所以不给它配 live 版本，
                 改日期后这段提示继续留着正好。 --}}
            @include('fly.partials.empty-state', [
                'emptyTitle' => '这家酒店还没有房型',
                'emptySub' => '后台「酒店 → 房型 → 房间」里补齐后，前台即可查询房量并预订。',
            ])
        @else
            {{-- SSR 房型列表（首屏；JS 关闭时永久可见） --}}
            <div class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm"
                 x-show="! $store.fly.detailDirty">
                @foreach ($roomTypes as $type)
                    @include('fly.partials.room-row', ['type' => $type])
                @endforeach
            </div>

            {{-- 局部刷新的房型列表：对照模板 fly/partials/room-row-live.blade.php --}}
            <div class="overflow-hidden rounded-3xl border border-gray-100 bg-white shadow-sm"
                 x-show="$store.fly.detailDirty" x-cloak>
                <p class="border-b border-gray-50 px-6 py-4 text-xs text-gray-400" x-show="$store.fly.detailLoading" x-cloak>正在查询房量…</p>
                <p class="border-b border-gray-50 px-6 py-4 text-xs text-orange-500" x-show="! $store.fly.online" x-cloak
                   x-text="$store.fly.error || '网络异常，房量可能不是最新，请稍后重试'"></p>
                <p class="border-b border-gray-50 px-6 py-4 text-sm text-gray-400" x-show="$store.fly.roomTypes.length === 0 && ! $store.fly.detailLoading">
                    该日期暂无可订房型，换个日期试试。
                </p>

                @include('fly.partials.room-row-live')
            </div>
        @endif
    </section>
@endsection
