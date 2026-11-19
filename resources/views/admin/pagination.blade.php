@if($page->hasPages())
<nav class="pagination" aria-label="ページ切替">
@if($page->previousPageUrl())<a class="btn compact" href="{{ $page->previousPageUrl() }}">← 前へ</a>@endif
<span>{{ $page->currentPage() }} / {{ $page->lastPage() }} ページ</span>
@if($page->nextPageUrl())<a class="btn compact" href="{{ $page->nextPageUrl() }}">次へ →</a>@endif
</nav>
@endif
