@if ($paginator->hasPages() || $paginator->total() > 0)
    <nav class="pager" aria-label="Pages">
        <span class="grow">Showing {{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }} of {{ $paginator->total() }}</span>
        @if ($paginator->onFirstPage())
            <span class="btn btn-s" aria-disabled="true" style="opacity:.45">@include('partials.icon', ['n' => 'left', 'small' => true])<span class="sr-only">Previous page</span></span>
        @else
            <a class="btn btn-s" href="{{ $paginator->previousPageUrl() }}" rel="prev">@include('partials.icon', ['n' => 'left', 'small' => true])<span class="sr-only">Previous page</span></a>
        @endif
        @if ($paginator->hasMorePages())
            <a class="btn btn-s" href="{{ $paginator->nextPageUrl() }}" rel="next">@include('partials.icon', ['n' => 'right', 'small' => true])<span class="sr-only">Next page</span></a>
        @else
            <span class="btn btn-s" aria-disabled="true" style="opacity:.45">@include('partials.icon', ['n' => 'right', 'small' => true])<span class="sr-only">Next page</span></span>
        @endif
    </nav>
@endif
