@props([
    'paginator',
    'label' => 'Pagination',
    'itemLabel' => 'items',
])

@if (method_exists($paginator, 'hasPages') && $paginator->hasPages())
    @php
        $currentPage = $paginator->currentPage();
        $lastPage = $paginator->lastPage();
        $windowStart = max(1, $currentPage - 2);
        $windowEnd = min($lastPage, $currentPage + 2);
    @endphp

    <nav class="order-pagination mt-5" aria-label="{{ $label }}">
        <div class="text-xs text-gray-500">
            Showing {{ $paginator->firstItem() }}-{{ $paginator->lastItem() }} of {{ $paginator->total() }} {{ $itemLabel }}
        </div>

        <div class="flex flex-wrap items-center justify-center gap-2 sm:justify-end">
            @if ($paginator->onFirstPage())
                <span class="order-pagination-link opacity-45">Previous</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" class="order-pagination-link">Previous</a>
            @endif

            @if ($windowStart > 1)
                <a href="{{ $paginator->url(1) }}" class="order-pagination-link">1</a>

                @if ($windowStart > 2)
                    <span class="order-pagination-link opacity-45">...</span>
                @endif
            @endif

            @foreach ($paginator->getUrlRange($windowStart, $windowEnd) as $page => $url)
                @if ($page === $currentPage)
                    <span class="order-pagination-link is-active">{{ $page }}</span>
                @else
                    <a href="{{ $url }}" class="order-pagination-link">{{ $page }}</a>
                @endif
            @endforeach

            @if ($windowEnd < $lastPage)
                @if ($windowEnd < $lastPage - 1)
                    <span class="order-pagination-link opacity-45">...</span>
                @endif

                <a href="{{ $paginator->url($lastPage) }}" class="order-pagination-link">{{ $lastPage }}</a>
            @endif

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="order-pagination-link">Next</a>
            @else
                <span class="order-pagination-link opacity-45">Next</span>
            @endif
        </div>
    </nav>
@endif
