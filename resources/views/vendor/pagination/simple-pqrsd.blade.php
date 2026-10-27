@if ($paginator->hasPages())
    <nav style="display:flex;gap:8px;align-items:center">
        @if ($paginator->onFirstPage())
            <span class="btn btn-secondary" style="opacity:.5">Anterior</span>
        @else
            <a class="btn btn-secondary" href="{{ $paginator->previousPageUrl() }}">Anterior</a>
        @endif

        <span class="muted">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>

        @if ($paginator->hasMorePages())
            <a class="btn btn-secondary" href="{{ $paginator->nextPageUrl() }}">Siguiente</a>
        @else
            <span class="btn btn-secondary" style="opacity:.5">Siguiente</span>
        @endif
    </nav>
@endif
