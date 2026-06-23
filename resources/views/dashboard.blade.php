<x-layouts.app :title="__('Dashboard')">

    <div class="min-h-screen bg-slate-50 text-slate-900 dark:bg-neutral-950 dark:text-slate-100">
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">

            {{-- HEADER --}}
            <div class="mb-8 flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                <div>
                    <p class="text-sm font-semibold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-400">
                        Panel principal
                    </p>
                    <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-900 dark:text-white">
                        Dashboard de inventario
                    </h1>
                    <p class="mt-2 max-w-2xl text-sm text-slate-600 dark:text-slate-400">
                        Control general de llantas, productos compuestos, stock crítico y procesos operativos.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            Última actualización
                        </p>
                        <p class="text-sm font-bold text-slate-900 dark:text-white">
                            {{ now()->format('d/m/Y H:i') }}
                        </p>
                    </div>
                </div>
            </div>

            {{-- MENSAJES --}}
            @if (session('success'))
                <div class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800 shadow-sm dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-red-800 shadow-sm dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                    {{ session('error') }}
                </div>
            @endif

            {{-- MÉTRICAS PRINCIPALES --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">

                <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-800 dark:bg-neutral-900">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Llantas individuales</p>
                            <p class="mt-3 text-3xl font-bold text-slate-900 dark:text-white">
                                {{ number_format($totalLlantas) }}
                            </p>
                            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                                Inventario base registrado
                            </p>
                        </div>
                        <div class="rounded-2xl bg-blue-50 p-3 dark:bg-blue-500/10">
                            <svg class="h-6 w-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M4 12h16M4 17h16" />
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4 h-1.5 rounded-full bg-slate-100 dark:bg-neutral-800">
                        <div class="h-1.5 w-2/3 rounded-full bg-blue-500"></div>
                    </div>
                </div>

                <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-800 dark:bg-neutral-900">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Tipos de combos</p>
                            <p class="mt-3 text-3xl font-bold text-slate-900 dark:text-white">
                                {{ number_format($totalCompuestos) }}
                            </p>
                            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                                Productos compuestos creados
                            </p>
                        </div>
                        <div class="rounded-2xl bg-violet-50 p-3 dark:bg-violet-500/10">
                            <svg class="h-6 w-6 text-violet-600 dark:text-violet-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7L12 3 4 7m16 0-8 4m8-4v10l-8 4m0-10L4 7m8 4v10" />
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4 h-1.5 rounded-full bg-slate-100 dark:bg-neutral-800">
                        <div class="h-1.5 w-3/4 rounded-full bg-violet-500"></div>
                    </div>
                </div>

                <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-800 dark:bg-neutral-900">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Stock llantas</p>
                            <p class="mt-3 text-3xl font-bold text-slate-900 dark:text-white">
                                {{ number_format($existenciasLlantas) }}
                            </p>
                            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                                Piezas disponibles actualmente
                            </p>
                        </div>
                        <div class="rounded-2xl bg-emerald-50 p-3 dark:bg-emerald-500/10">
                            <svg class="h-6 w-6 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 7h18M6 12h12M9 17h6" />
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4 h-1.5 rounded-full bg-slate-100 dark:bg-neutral-800">
                        <div class="h-1.5 w-4/5 rounded-full bg-emerald-500"></div>
                    </div>
                </div>

                <div class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-800 dark:bg-neutral-900">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Llantas agotadas</p>
                            <p class="mt-3 text-3xl font-bold text-slate-900 dark:text-white">
                                {{ number_format($llantasSinStock) }}
                            </p>
                            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                                Productos sin existencia
                            </p>
                        </div>
                        <div class="rounded-2xl bg-rose-50 p-3 dark:bg-rose-500/10">
                            <svg class="h-6 w-6 text-rose-600 dark:text-rose-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86 2.82 17a1 1 0 0 0 .87 1.5h16.62a1 1 0 0 0 .87-1.5l-7.47-13.14a1 1 0 0 0-1.74 0Z" />
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4 h-1.5 rounded-full bg-slate-100 dark:bg-neutral-800">
                        <div class="h-1.5 w-1/2 rounded-full bg-rose-500"></div>
                    </div>
                </div>
            </div>

            {{-- VALORES --}}
            <div class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div class="border-b border-slate-100 px-6 py-4 dark:border-neutral-800">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white">Valor inventario llantas</p>
                    </div>
                    <div class="flex items-center justify-between gap-4 px-6 py-5">
                        <div>
                            <p class="text-3xl font-bold text-emerald-600 dark:text-emerald-400">
                                ${{ number_format($valorInventarioLlantas, 2) }}
                            </p>
                            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                                Valor total actual del inventario individual.
                            </p>
                        </div>
                        <div class="rounded-2xl bg-emerald-50 p-4 dark:bg-emerald-500/10">
                            <svg class="h-7 w-7 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-2.21 0-4 .895-4 2s1.79 2 4 2 4 .895 4 2-1.79 2-4 2m0-10c1.687 0 3.13.523 3.712 1.264M12 8V6m0 12v-2m-3.712-6.736C8.87 8.523 10.313 8 12 8" />
                            </svg>
                        </div>
                    </div>
                </div>

                <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div class="border-b border-slate-100 px-6 py-4 dark:border-neutral-800">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white">Valor teórico combos</p>
                    </div>
                    <div class="flex items-center justify-between gap-4 px-6 py-5">
                        <div>
                            <p class="text-3xl font-bold text-indigo-600 dark:text-indigo-400">
                                ${{ number_format($valorInventarioCompuestos, 2) }}
                            </p>
                            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                                Valor estimado de productos compuestos.
                            </p>
                        </div>
                        <div class="rounded-2xl bg-indigo-50 p-4 dark:bg-indigo-500/10">
                            <svg class="h-7 w-7 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7 7h10v10H7zM3 3h4v4H3zm14 0h4v4h-4zM3 17h4v4H3zm14 0h4v4h-4z" />
                            </svg>
                        </div>
                    </div>
                </div>
            </div>

            {{-- BUSCADOR --}}
            <div class="mt-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                <form method="GET" action="{{ route('dashboard') }}">
                    <label for="search" class="mb-3 block text-sm font-semibold text-slate-900 dark:text-white">
                        Buscar producto en stock crítico
                    </label>

                    <div class="flex flex-col gap-3 md:flex-row">
                        <div class="relative flex-1">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-slate-400">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15z" />
                                </svg>
                            </span>

                            <input
                                id="search"
                                type="text"
                                name="search"
                                value="{{ request('search') }}"
                                placeholder="Buscar por SKU..."
                                class="w-full rounded-2xl border border-slate-200 bg-slate-50 py-3 pl-11 pr-4 text-slate-900 placeholder-slate-400 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white dark:placeholder-slate-500 dark:focus:ring-indigo-950"
                            >
                        </div>

                        <button
                            type="submit"
                            class="inline-flex items-center justify-center rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700"
                        >
                            Buscar
                        </button>

                        <a
                            href="{{ route('dashboard') }}"
                            class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200 dark:hover:bg-neutral-800"
                        >
                            Limpiar
                        </a>
                    </div>
                </form>
            </div>

            @php
                $sort = request('sort', $sort ?? 'id');
                $dir  = request('dir',  $dir  ?? 'desc');
                $dir  = in_array($dir, ['asc','desc']) ? $dir : 'desc';

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

                    $upClass = $upActive ? 'text-indigo-600 dark:text-indigo-400' : 'text-slate-400 dark:text-slate-500';
                    $dnClass = $downActive ? 'text-indigo-600 dark:text-indigo-400' : 'text-slate-400 dark:text-slate-500';

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

            {{-- STOCK CRÍTICO --}}
            <div class="mt-6 overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                <div class="flex flex-col gap-3 border-b border-slate-200 px-6 py-5 dark:border-neutral-800 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h2 class="text-xl font-bold text-slate-900 dark:text-white">
                            Stock crítico
                        </h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Productos con stock menor o igual a 4.
                        </p>
                    </div>

                    <div class="inline-flex items-center rounded-full bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">
                        ≤ 4 unidades
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 dark:bg-neutral-800/70">
                            <tr class="text-xs uppercase tracking-wide text-slate-600 dark:text-slate-300">
                                <th class="px-4 py-4 text-left"><a href="{{ $sortLink('sku') }}" class="inline-flex items-center font-semibold hover:text-indigo-600 dark:hover:text-indigo-400 transition">SKU {!! $arrow('sku') !!}</a></th>
                                <th class="px-4 py-4 text-left"><a href="{{ $sortLink('marca') }}" class="inline-flex items-center font-semibold hover:text-indigo-600 dark:hover:text-indigo-400 transition">Marca {!! $arrow('marca') !!}</a></th>
                                <th class="px-4 py-4 text-left"><a href="{{ $sortLink('medida') }}" class="inline-flex items-center font-semibold hover:text-indigo-600 dark:hover:text-indigo-400 transition">Medida {!! $arrow('medida') !!}</a></th>
                                <th class="px-4 py-4 text-left"><a href="{{ $sortLink('descripcion') }}" class="inline-flex items-center font-semibold hover:text-indigo-600 dark:hover:text-indigo-400 transition">Descripción {!! $arrow('descripcion') !!}</a></th>
                                <th class="px-4 py-4 text-right"><a href="{{ $sortLink('costo') }}" class="inline-flex items-center font-semibold hover:text-indigo-600 dark:hover:text-indigo-400 transition">Costo {!! $arrow('costo') !!}</a></th>
                                <th class="px-4 py-4 text-right"><a href="{{ $sortLink('precio_ML') }}" class="inline-flex items-center font-semibold hover:text-indigo-600 dark:hover:text-indigo-400 transition">Precio ML {!! $arrow('precio_ML') !!}</a></th>
                                <th class="px-4 py-4 text-left"><a href="{{ $sortLink('title_familyname') }}" class="inline-flex items-center font-semibold hover:text-indigo-600 dark:hover:text-indigo-400 transition">Título {!! $arrow('title_familyname') !!}</a></th>
                                <th class="px-4 py-4 text-left"><a href="{{ $sortLink('MLM') }}" class="inline-flex items-center font-semibold hover:text-indigo-600 dark:hover:text-indigo-400 transition">MLM {!! $arrow('MLM') !!}</a></th>
                                <th class="px-4 py-4 text-center"><a href="{{ $sortLink('stock') }}" class="inline-flex items-center font-semibold hover:text-indigo-600 dark:hover:text-indigo-400 transition">Stock {!! $arrow('stock') !!}</a></th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-slate-100 dark:divide-neutral-800">
                            @forelse ($stockBajo as $llanta)
                                @php
                                    $stockValue = (int) $llanta->stock;

                                    $stockBadge = match (true) {
                                        $stockValue <= 0 => 'bg-red-100 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-400 dark:ring-red-900',
                                        $stockValue <= 2 => 'bg-orange-100 text-orange-700 ring-orange-200 dark:bg-orange-950/40 dark:text-orange-400 dark:ring-orange-900',
                                        default => 'bg-yellow-100 text-yellow-700 ring-yellow-200 dark:bg-yellow-950/40 dark:text-yellow-400 dark:ring-yellow-900',
                                    };
                                @endphp

                                <tr class="hover:bg-slate-50 transition dark:hover:bg-neutral-800/60">
                                    <td class="px-4 py-4">
                                        <div class="font-mono text-sm font-semibold text-blue-600 dark:text-blue-400">
                                            {{ $llanta->sku }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 text-slate-800 dark:text-slate-200">{{ $llanta->marca ?? 'SIN MARCA' }}</td>
                                    <td class="px-4 py-4 text-slate-700 dark:text-slate-300">{{ $llanta->medida ?? 'N/A' }}</td>
                                    <td class="px-4 py-4 text-slate-600 dark:text-slate-400">
                                        <div class="max-w-xs truncate md:max-w-sm lg:max-w-md">
                                            {{ $llanta->descripcion ?? '—' }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-4 text-right text-slate-700 dark:text-slate-300">${{ number_format($llanta->costo, 2) }}</td>
                                    <td class="px-4 py-4 text-right font-bold text-emerald-600 dark:text-emerald-400">${{ number_format($llanta->precio_ML, 2) }}</td>
                                    <td class="px-4 py-4 text-slate-700 dark:text-slate-300">
                                        <div class="max-w-xs truncate">{{ $llanta->title_familyname }}</div>
                                    </td>
                                    <td class="px-4 py-4 text-slate-500 dark:text-slate-400">{{ $llanta->MLM ?? '—' }}</td>
                                    <td class="px-4 py-4 text-center">
                                        <span class="inline-flex min-w-[42px] items-center justify-center rounded-full px-3 py-1 text-xs font-bold ring-1 {{ $stockBadge }}">
                                            {{ $llanta->stock }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-4 py-10 text-center text-slate-500 dark:text-slate-400">
                                        No se encontraron resultados
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="border-t border-slate-200 px-4 py-4 dark:border-neutral-800">
                    {{ $stockBajo->appends(request()->query())->links() }}
                </div>
            </div>

            {{-- ACCIONES --}}
            <div class="mt-6">
                <div class="mb-3">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white">Acciones rápidas</h2>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Accesos directos y procesos importantes del sistema.
                    </p>
                </div>

                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
                    <a href="{{ route('llantas.index') }}" class="group rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-800 dark:bg-neutral-900">
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Inventario</p>
                        <p class="mt-2 text-base font-bold text-slate-900 transition group-hover:text-indigo-600 dark:text-white dark:group-hover:text-indigo-400">Ver llantas</p>
                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Consulta el inventario individual.</p>
                    </a>

                    <a href="{{ route('productos.index') }}" class="group rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-800 dark:bg-neutral-900">
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Combos</p>
                        <p class="mt-2 text-base font-bold text-slate-900 transition group-hover:text-indigo-600 dark:text-white dark:group-hover:text-indigo-400">Productos compuestos</p>
                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Administra juegos, pares y combos.</p>
                    </a>

                    <a href="{{ route('excel.vista') }}" class="group rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-800 dark:bg-neutral-900">
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Inventario</p>
                        <p class="mt-2 text-base font-bold text-emerald-600 transition group-hover:text-emerald-700 dark:text-emerald-400 dark:group-hover:text-emerald-300">Importar Excel</p>
                        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Sube y procesa tu archivo de inventario.</p>
                    </a>

                    <form method="POST" action="{{ route('dashboard.stock.zero') }}" onsubmit="return confirm('¿Seguro que quieres poner TODO el stock en 0 (llantas y productos compuestos)?');" class="m-0">
                        @csrf
                        <button type="submit" class="group h-full w-full rounded-3xl border border-red-200 bg-white p-5 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-red-900 dark:bg-neutral-900">
                            <p class="text-xs font-medium uppercase tracking-wide text-red-500 dark:text-red-400">Acción peligrosa</p>
                            <p class="mt-2 text-base font-bold text-slate-900 group-hover:text-red-600 dark:text-white dark:group-hover:text-red-400">Poner stock en 0</p>
                            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Reinicia todo el stock del sistema.</p>
                        </button>
                    </form>

                    <form method="POST" action="{{ route('dashboard.meli.refresh') }}" onsubmit="return confirm('¿Deseas refrescar el token de MercadoLibre ahora?');" class="m-0">
                        @csrf
                        <button type="submit" class="group h-full w-full rounded-3xl border border-slate-200 bg-white p-5 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-800 dark:bg-neutral-900">
                            <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Mercado Libre</p>
                            <p class="mt-2 text-base font-bold text-slate-900 group-hover:text-indigo-600 dark:text-white dark:group-hover:text-indigo-400">Refrescar token ML</p>
                            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Actualiza el acceso para sincronización.</p>
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>

</x-layouts.app>