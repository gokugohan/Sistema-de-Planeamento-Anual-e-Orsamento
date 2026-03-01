@if ($paginator->hasPages())
    <nav class="flex items-center justify-between gap-4" aria-label="Pagination">

        {{-- Left: result count --}}
        <p class="text-sm text-zinc-500 flex-shrink-0">
            {{ __('Showing') }}
            <span class="font-medium text-zinc-700">{{ $paginator->firstItem() }}</span>
            &ndash;
            <span class="font-medium text-zinc-700">{{ $paginator->lastItem() }}</span>
            {{ __('of') }}
            <span class="font-medium text-zinc-700">{{ $paginator->total() }}</span>
        </p>

        {{-- Right: page buttons --}}
        <div class="flex items-center gap-1">

            {{-- Previous --}}
            @if ($paginator->onFirstPage())
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-zinc-300 cursor-not-allowed" aria-disabled="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m15 18-6-6 6-6"/>
                    </svg>
                </span>
            @else
                <button wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled"
                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-zinc-500 hover:text-zinc-900 hover:bg-zinc-100 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m15 18-6-6 6-6"/>
                    </svg>
                </button>
            @endif

            {{-- Page numbers (show up to 7 pages around current) --}}
            @php
                $current    = $paginator->currentPage();
                $last       = $paginator->lastPage();
                $window     = 2; // pages on each side of current
                $start      = max(1, $current - $window);
                $end        = min($last, $current + $window);
                // Expand window if near start/end to always show ~5 pages
                if ($current - $window < 1) $end = min($last, $end + (1 - ($current - $window)));
                if ($current + $window > $last) $start = max(1, $start - (($current + $window) - $last));
            @endphp

            {{-- First page + ellipsis --}}
            @if ($start > 1)
                <button wire:click="gotoPage(1, '{{ $paginator->getPageName() }}')"
                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-sm text-zinc-600 hover:bg-zinc-100 transition-colors">1</button>
                @if ($start > 2)
                    <span class="inline-flex items-center justify-center w-8 h-8 text-zinc-400 text-sm select-none">&hellip;</span>
                @endif
            @endif

            {{-- Window pages --}}
            @for ($page = $start; $page <= $end; $page++)
                @if ($page == $current)
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-sm font-semibold bg-indigo-600 text-white shadow-sm"
                        aria-current="page">{{ $page }}</span>
                @else
                    <button wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')"
                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-sm text-zinc-600 hover:bg-zinc-100 transition-colors">{{ $page }}</button>
                @endif
            @endfor

            {{-- Last page + ellipsis --}}
            @if ($end < $last)
                @if ($end < $last - 1)
                    <span class="inline-flex items-center justify-center w-8 h-8 text-zinc-400 text-sm select-none">&hellip;</span>
                @endif
                <button wire:click="gotoPage({{ $last }}, '{{ $paginator->getPageName() }}')"
                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-sm text-zinc-600 hover:bg-zinc-100 transition-colors">{{ $last }}</button>
            @endif

            {{-- Next --}}
            @if ($paginator->hasMorePages())
                <button wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled"
                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-zinc-500 hover:text-zinc-900 hover:bg-zinc-100 transition-colors">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m9 18 6-6-6-6"/>
                    </svg>
                </button>
            @else
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-zinc-300 cursor-not-allowed" aria-disabled="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m9 18 6-6-6-6"/>
                    </svg>
                </span>
            @endif
        </div>
    </nav>
@endif
