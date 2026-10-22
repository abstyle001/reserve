{{--
    空态。两种「没结果」要分开说：
    有筛选条件但被筛光（该放宽条件），和压根没有数据可筛（该去后台补）。
    文案混着说会让人以为系统坏了。详情页复用本 partial，传 $emptyTitle / $emptySub 改措辞。

    Alpine 版在 fly/partials/results-live.blade.php 里（两个分支 + 三个链接），
    改这里的文案或按钮，那边要同步。
--}}
@php
    $urls = app(\App\Services\Fly\HotelSearchService::class);
    $filtered = $filters['city'] !== '' || $filters['q'] !== '';
    $emptyTitle = isset($emptyTitle) ? $emptyTitle : '还没有上架的酒店';
    $emptySub = isset($emptySub) ? $emptySub : '后台「酒店管理」里新增酒店、房型和房间，前台即时可见。';
@endphp
<div class="fly-empty">
    <span class="fly-empty__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/>
            <path d="m21 21-4.3-4.3"/>
            <path d="M8 11h6"/>
        </svg>
    </span>
    @if ($filtered)
        <p class="fly-empty__title">没有找到符合条件的酒店</p>
        <p class="fly-empty__sub">
            {{ $filters['city'] !== '' ? '城市「' . $filters['city'] . '」· ' : '' }}{{ $filters['q'] !== '' ? '关键词「' . $filters['q'] . '」· ' : '' }}{{ $filters['check_in'] }} 至 {{ $filters['check_out'] }}
        </p>
        <div class="mt-5 flex flex-wrap items-center justify-center gap-2">
            <a href="{{ $urls->pageUrl($filters, ['city' => '', 'q' => '', 'page' => null]) }}" class="fly-btn-outline">清空筛选条件</a>
            @if ($filters['city'] !== '')
                <a href="{{ $urls->pageUrl($filters, ['city' => '', 'page' => null]) }}" class="fly-chip">不限城市</a>
            @endif
            @if ($filters['q'] !== '')
                <a href="{{ $urls->pageUrl($filters, ['q' => '', 'page' => null]) }}" class="fly-chip">去掉关键词</a>
            @endif
        </div>
    @else
        <p class="fly-empty__title">{{ $emptyTitle }}</p>
        <p class="fly-empty__sub">{{ $emptySub }}</p>
        @auth
            <div class="mt-5">
                <a href="{{ route('hotels.create') }}" class="fly-btn-primary">去后台添加</a>
            </div>
        @endauth
    @endif
</div>
