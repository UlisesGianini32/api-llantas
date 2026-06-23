<x-layouts.app title="Llantas no actualizadas">

<div class="space-y-6">

    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
        Llantas no actualizadas en el último import
    </h1>

    {{-- 🔍 BUSCADOR --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <form method="GET" action="{{ route('llantas.no_actualizadas') }}" class="w-full sm:max-w-xl">
            <input
                type="text"
                name="search"
                value="{{ request('search') }}"
                placeholder="Buscar por SKU, título o MLM..."
                class="w-full rounded-md border border-zinc-300 bg-white px-4 py-2 text-zinc-900 placeholder-zinc-400
                       focus:outline-none focus:ring-2 focus:ring-indigo-500
                       dark:border-neutral-700 dark:bg-neutral-900 dark:text-white dark:placeholder-gray-500"
            >
        </form>
    </div>

    {{-- MENSAJE DE ÉXITO --}}
    @if(session('success'))
        <div class="rounded-lg bg-green-50 border border-green-200 text-green-700 px-4 py-3 dark:bg-green-900/30 dark:border-green-700 dark:text-green-200">
            {{ session('success') }}
        </div>
    @endif

    @php
        $sort = request('sort', $sort ?? 'sku');
        $dir  = request('dir',  $dir  ?? 'asc');
        $dir  = in_array($dir, ['asc','desc']) ? $dir : 'asc';

        $toggleDir = fn($col) => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';

        $sortLink = fn($col) =>
            request()->fullUrlWithQuery([
                'sort' => $col,
                'dir'  => $toggleDir($col),
                'page' => null,
            ]);

        $arrow = function($col) use ($sort, $dir) {
            $active = $sort === $col;
            $upActive   = $active && $dir === 'asc';
            $downActive = $active && $dir === 'desc';

            $upClass = $upActive ? 'text-indigo-600 dark:text-indigo-400' : 'text-zinc-400 dark:text-zinc-500';
            $dnClass = $downActive ? 'text-indigo-600 dark:text-indigo-400' : 'text-zinc-400 dark:text-zinc-500';

            return '
            <span class="ml-1 inline-flex flex-col items-center justify-center select-none">
                <svg class="h-3 w-3 '.$upClass.' transition-colors" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M14.77 12.79a.75.75 0 0 1-1.06.02L10 9.31l-3.71 3.5a.75.75 0 1 1-1.04-1.08l4.24-4a.75.75 0 0 1 1.04 0l4.24 4a.75.75 0 0 1 .02 1.06Z" clip-rule="evenodd"/>
                </svg>
                <svg class="h-3 w-3 -mt-1 '.$dnClass.' transition-colors" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06-.02L10 10.69l3.71-3.5a.75.75 0 1 1 1.04 1.08l-4.24 4a.75.75 0 0 1-1.04 0l-4.24-4a.75.75 0 0 1-.02-1.06Z" clip-rule="evenodd"/>
                </svg>
            </span>';
        };
    @endphp

    {{-- TABLA --}}
    <div class="rounded-lg border border-zinc-200 bg-white overflow-hidden dark:bg-neutral-900 dark:border-neutral-800">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-zinc-700 dark:text-gray-300">
                <thead class="bg-zinc-100 text-zinc-600 dark:bg-neutral-800 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3 text-left">
                            <a href="{{ $sortLink('sku') }}" class="inline-flex items-center font-medium hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                SKU {!! $arrow('sku') !!}
                            </a>
                        </th>

                        <th class="px-4 py-3">
                            <a href="{{ $sortLink('marca') }}" class="inline-flex items-center font-medium hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                Marca {!! $arrow('marca') !!}
                            </a>
                        </th>

                        <th class="px-4 py-3">
                            <a href="{{ $sortLink('medida') }}" class="inline-flex items-center font-medium hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                Medida {!! $arrow('medida') !!}
                            </a>
                        </th>

                        <th class="px-4 py-3">
                            <a href="{{ $sortLink('descripcion') }}" class="inline-flex items-center font-medium hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                Descripción {!! $arrow('descripcion') !!}
                            </a>
                        </th>

                        <th class="px-4 py-3 text-center">
                            <a href="{{ $sortLink('stock') }}" class="inline-flex items-center font-medium hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                Stock {!! $arrow('stock') !!}
                            </a>
                        </th>

                        <th class="px-4 py-3 text-center">
                            <a href="{{ $sortLink('last_import_at') }}" class="inline-flex items-center font-medium hover:text-indigo-600 dark:hover:text-indigo-400 transition">
                                Última importación {!! $arrow('last_import_at') !!}
                            </a>
                        </th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($llantas as $llanta)
                        <tr class="border-t border-zinc-200 hover:bg-zinc-50 dark:border-neutral-800 dark:hover:bg-neutral-800">

                            <td class="px-4 py-2 font-mono text-blue-600 dark:text-blue-400">
                                {{ $llanta->sku }}
                            </td>

                            <td class="px-4 py-2">
                                {{ $llanta->marca }}
                            </td>

                            <td class="px-4 py-2">
                                {{ $llanta->medida }}
                            </td>

                            <td class="px-4 py-2 text-zinc-500 dark:text-gray-400">
                                {{ $llanta->descripcion }}
                            </td>

                            <td class="px-4 py-2 text-center font-bold text-red-600 dark:text-red-400">
                                {{ $llanta->stock }}
                            </td>

                            <td class="px-4 py-2 text-center text-zinc-500 dark:text-gray-400 text-xs">
                                {{ $llanta->last_import_at
                                    ? $llanta->last_import_at->format('Y-m-d H:i')
                                    : 'Nunca'
                                }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-center text-zinc-500 dark:text-gray-400">
                                No hay llantas pendientes
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- PAGINACIÓN (mantiene querystring) --}}
    {{ $llantas->appends(request()->query())->links() }}

</div>

</x-layouts.app>
