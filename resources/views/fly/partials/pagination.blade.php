{{--
    分页 —— 全部是真链接（?page=N），JS 关闭时可用；
    Alpine 接管后点击会改成局部刷新，但 href 保留（右键新开 / SEO / 回退都靠它）。
--}}
@php
    $urls = app(\App\Services\Fly\HotelSearchService::class);
    $current = (int) $result['current_page'];
    $last = (int) $result['last_page'];

    // 只显示以当前页为中心的 7 个页码，两端各留一个首/末页占位
    $from = max(1, min($current - 3, $last - 6));
    $to = min($last, $from + 6);
@endphp

@if ($last > 1)
    <nav class="fly-pager" aria-label="酒店搜索结果分页">
        @if ($current > 1)
            <a href="{{ $urls->pageUrl($filters, ['page' => $current - 1]) }}" class="fly-pager__btn">上一页</a>
        @endif

        @if ($from > 1)
            <a href="{{ $urls->pageUrl($filters, ['page' => 1]) }}" class="fly-pager__num">1</a>
            @if ($from > 2)
                <span class="fly-pager__gap">…</span>
            @endif
        @endif

        @for ($p = $from; $p <= $to; $p++)
            @if ($p === $current)
                <span class="fly-pager__num fly-pager__num--active" aria-current="page">{{ $p }}</span>
            @else
                <a href="{{ $urls->pageUrl($filters, ['page' => $p]) }}" class="fly-pager__num">{{ $p }}</a>
            @endif
        @endfor

        @if ($to < $last)
            @if ($to < $last - 1)
                <span class="fly-pager__gap">…</span>
            @endif
            <a href="{{ $urls->pageUrl($filters, ['page' => $last]) }}" class="fly-pager__num">{{ $last }}</a>
        @endif

        @if ($current < $last)
            <a href="{{ $urls->pageUrl($filters, ['page' => $current + 1]) }}" class="fly-pager__btn">下一页</a>
        @endif

        <span class="fly-pager__total">共 {{ $result['total'] }} 家</span>
    </nav>
@endif
