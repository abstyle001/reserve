@if ($paginator->hasPages())
    <div class="admin-pager">
        @if ($paginator->onFirstPage())
            <span class="admin-btn-ghost cursor-not-allowed opacity-40">上一页</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" class="admin-btn-ghost">上一页</a>
        @endif

        <span class="admin-pager__info">第 {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }} 页 · 共 {{ $paginator->total() }} 条</span>

        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" class="admin-btn-ghost">下一页</a>
        @else
            <span class="admin-btn-ghost cursor-not-allowed opacity-40">下一页</span>
        @endif
    </div>
@endif
