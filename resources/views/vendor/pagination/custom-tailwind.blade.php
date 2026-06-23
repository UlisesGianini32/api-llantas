@props([
    'paginator',
])

@if ($paginator->hasPages())
    <nav class="flex space-x-1" aria-label="Pagination">
        <!-- Previous Page Link -->
        @if ($paginator->onFirstPage())
            <span class="relative inline-flex items-center px-1 py-0.5 text-xs font-medium text-gray-500 bg-white border border-gray-300 cursor-default rounded-lg dark:bg-gray-800 dark:border-gray-600 dark:text-gray-400 w-14 text-center">
                « Anterior
            </span>
        @else
            <button wire:click="previousPage" class="relative inline-flex items-center px-1 py-0.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-400 dark:hover:bg-gray-700 w-14 text-center">
                « Anterior
            </button>
        @endif

        <!-- Page Numbers with Ellipsis -->
        @php
            $window = 5; // Mostrar 5 páginas antes y después de la página actual
            $start = max(1, $paginator->currentPage() - $window);
            $end = min($paginator->lastPage(), $paginator->currentPage() + $window);

            // Ajustar el rango si estamos cerca del inicio o del final
            if ($end - $start < $window * 2) {
                if ($start == 1) {
                    $end = min($paginator->lastPage(), $start + ($window * 2));
                } else {
                    $start = max(1, $end - ($window * 2));
                }
            }
        @endphp

        <!-- First Page -->
        @if ($start > 1)
            <button wire:click="setPage(1)" class="relative inline-flex items-center px-1 py-0.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-400 dark:hover:bg-gray-700 w-6 text-center">
                1
            </button>
            @if ($start > 2)
                <span class="relative inline-flex items-center px-1 py-0.5 text-xs font-medium text-gray-500 bg-white border border-gray-300 cursor-default rounded-lg dark:bg-gray-800 dark:border-gray-600 dark:text-gray-400 w-6 text-center">
                    ...
                </span>
            @endif
        @endif

        <!-- Page Numbers in Range -->
        @for ($page = $start; $page <= $end; $page++)
            @if ($page == $paginator->currentPage())
                <span class="relative inline-flex items-center px-1 py-0.5 text-xs font-medium text-white bg-blue-500 border border-blue-500 rounded-lg dark:bg-blue-600 dark:border-blue-600 w-6 text-center">
                    {{ $page }}
                </span>
            @else
                <button wire:click="setPage({{ $page }})" class="relative inline-flex items-center px-1 py-0.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-400 dark:hover:bg-gray-700 w-6 text-center">
                    {{ $page }}
                </button>
            @endif
        @endfor

        <!-- Last Page and Ellipsis -->
        @if ($end < $paginator->lastPage())
            @if ($end < $paginator->lastPage() - 1)
                <span class="relative inline-flex items-center px-1 py-0.5 text-xs font-medium text-gray-500 bg-white border border-gray-300 cursor-default rounded-lg dark:bg-gray-800 dark:border-gray-600 dark:text-gray-400 w-6 text-center">
                    ...
                </span>
            @endif
            <button wire:click="setPage({{ $paginator->lastPage() }})" class="relative inline-flex items-center px-1 py-0.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-400 dark:hover:bg-gray-700 w-6 text-center">
                {{ $paginator->lastPage() }}
            </button>
        @endif

        <!-- Next Page Link -->
        @if ($paginator->hasMorePages())
            <button wire:click="nextPage({{ $paginator->lastPage() }})" class="relative inline-flex items-center px-1 py-0.5 text-xs font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 dark:bg-gray-800 dark:border-gray-600 dark:text-gray-400 dark:hover:bg-gray-700 w-14 text-center">
                Siguiente »
            </button>
        @else
            <span class="relative inline-flex items-center px-1 py-0.5 text-xs font-medium text-gray-500 bg-white border border-gray-300 cursor-default rounded-lg dark:bg-gray-800 dark:border-gray-600 dark:text-gray-400 w-14 text-center">
                Siguiente »
            </span>
        @endif
    </nav>
@endif