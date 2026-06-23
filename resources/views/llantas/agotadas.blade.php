<x-layouts.app title="Llantas agotadas">

<div class="space-y-6">

    <h1 class="text-2xl font-bold text-red-600 dark:text-red-400">
        Llantas agotadas (Stock = 0)
    </h1>

    {{-- BUSCADOR POR SKU --}}
    <form method="GET" action="{{ route('llantas.agotadas') }}" class="mb-4">
        <input
            type="text"
            name="search"
            value="{{ request('search') }}"
            placeholder="Buscar por SKU..."
            class="w-full rounded-md border border-zinc-300 bg-white px-4 py-2 text-zinc-900 placeholder-zinc-400
                   focus:outline-none focus:ring-2 focus:ring-indigo-500
                   dark:border-neutral-700 dark:bg-neutral-900 dark:text-white dark:placeholder-gray-500"
        >
    </form>

    <div class="rounded-lg border border-zinc-200 bg-white overflow-hidden dark:bg-neutral-900 dark:border-neutral-800">
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-zinc-700 dark:text-gray-300">
                <thead class="bg-zinc-100 text-zinc-600 dark:bg-neutral-800 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3 text-left">SKU</th>
                        <th class="px-4 py-3">Marca</th>
                        <th class="px-4 py-3">Medida</th>
                        <th class="px-4 py-3 text-center">Stock</th>
                        <th class="px-4 py-3">Descripción</th>
                        <th class="px-4 py-3 text-right">Costo</th>
                        <th class="px-4 py-3 text-right">Precio ML</th>
                        <th class="px-4 py-3">Título</th>
                        <th class="px-4 py-3">MLM</th>
                        <th class="px-4 py-3 text-center">Acción</th>
                    </tr>
                </thead>

                <tbody>
                    @forelse ($llantas as $llanta)
                        <tr class="border-t border-zinc-200 hover:bg-zinc-50 dark:border-neutral-800 dark:hover:bg-neutral-800">

                            <td class="px-4 py-2 font-mono text-blue-600 dark:text-blue-400">
                                {{ $llanta->sku }}
                            </td>

                            <td class="px-4 py-2">
                                {{ $llanta->marca ?? 'SIN MARCA' }}
                            </td>

                            <td class="px-4 py-2">
                                {{ $llanta->medida ?? 'N/A' }}
                            </td>

                            {{-- STOCK SIEMPRE ROJO --}}
                            <td class="px-4 py-2 text-center font-bold text-red-600 dark:text-red-400">
                                {{ $llanta->stock }}
                            </td>

                            <td class="px-4 py-2 text-zinc-500 dark:text-gray-400">
                                {{ $llanta->descripcion }}
                            </td>

                            <td class="px-4 py-2 text-right">
                                ${{ number_format($llanta->costo, 2) }}
                            </td>

                            <td class="px-4 py-2 text-right text-emerald-600 dark:text-green-400 font-semibold">
                                ${{ number_format($llanta->precio_ML, 2) }}
                            </td>

                            <td class="px-4 py-2">
                                {{ $llanta->title_familyname }}
                            </td>

                            <td class="px-4 py-2 text-xs text-zinc-500 dark:text-gray-400">
                                {{ $llanta->MLM ?? '—' }}
                            </td>

                            <td class="px-4 py-2 text-center">
                                <a href="{{ route('llantas.edit', $llanta->id) }}"
                                   class="text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300">
                                    ✏️ Editar
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-6 text-center text-zinc-500 dark:text-gray-400">
                                No hay llantas agotadas
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- PAGINACIÓN --}}
    {{ $llantas->appends(request()->query())->links() }}

</div>

</x-layouts.app>
