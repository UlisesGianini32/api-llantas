<x-layouts.app title="Comparador ML vs Sistema">

@php
    $uid = auth()->id();
    $running = cache("ml_compare:running:user:{$uid}", false);
    $lastRun = cache("ml_compare:last_run_at:user:{$uid}");
    $lastRes = cache("ml_compare:last_result:user:{$uid}");
@endphp

<div class="space-y-6">

    {{-- ================= HEADER + BOTÓN ================= --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
                Comparador ML vs Sistema
            </h1>

            <div class="mt-2 text-xs text-zinc-600 dark:text-gray-400 space-y-1">
                <div>
                    Estado:
                    @if($running)
                        <span class="font-semibold text-amber-600 dark:text-amber-300">
                            Corriendo...
                        </span>
                    @else
                        <span class="font-semibold text-emerald-600 dark:text-emerald-300">
                            Listo
                        </span>
                    @endif
                </div>

                @if($lastRun)
                    <div>
                        Último inicio:
                        <span class="font-mono">{{ $lastRun }}</span>
                    </div>
                @endif

                @if(is_array($lastRes))
                    <div>
                        Último resultado:
                        @if(($lastRes['ok'] ?? false) === true)
                            <span class="text-emerald-600 dark:text-emerald-300">
                                OK — Nuevos: {{ $lastRes['inserted'] ?? 0 }}
                                | Actualizados: {{ $lastRes['updated'] ?? 0 }}
                            </span>
                        @else
                            <span class="text-red-600 dark:text-red-300">
                                ERROR — {{ $lastRes['error'] ?? 'Desconocido' }}
                            </span>
                        @endif
                    </div>
                @endif
            </div>
        </div>

        <form method="POST" action="{{ route('ml.compare.run') }}">
            @csrf
            <button
                type="submit"
                @disabled($running)
                class="rounded-lg px-4 py-2 text-sm font-semibold
                       bg-indigo-600 text-white hover:bg-indigo-700
                       disabled:opacity-60 disabled:cursor-not-allowed">
                {{ $running ? 'Sincronizando...' : 'Actualizar compare' }}
            </button>
        </form>
    </div>

    {{-- ================= FILTROS ================= --}}
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

        <form method="GET" action="{{ route('ml.compare') }}" class="w-full sm:max-w-xl">
            <input type="hidden" name="per_page" value="{{ request('per_page', 25) }}">
            <input
                type="text"
                name="search"
                value="{{ request('search') }}"
                placeholder="Buscar por MLM, SKU o nombre..."
                class="w-full rounded-md border border-zinc-300 bg-white px-4 py-2
                       text-zinc-900 placeholder-zinc-400
                       focus:outline-none focus:ring-2 focus:ring-indigo-500
                       dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
            >
        </form>

        <form method="GET" action="{{ route('ml.compare') }}">
            <input type="hidden" name="search" value="{{ request('search') }}">

            <div class="flex items-center gap-3">

    {{-- ✅ Contador MLM LLANTAS --}}
    <div class="inline-flex items-center gap-2 px-3 py-2 rounded-full
                bg-neutral-900/60 border border-neutral-700 text-neutral-100
                dark:bg-neutral-900/60 dark:border-neutral-700">
        <span class="text-xs uppercase tracking-wide text-neutral-300">
            MLM Llantas
        </span>
        <span class="inline-flex items-center justify-center min-w-[2.25rem] h-6 px-2 rounded-full
                     bg-indigo-500/20 text-indigo-200 text-xs font-bold">
            {{ number_format((int)($mlmLlantasCount ?? 0)) }}
        </span>
    </div>

    {{-- ✅ Contador MLM COMPUESTOS --}}
    <div class="inline-flex items-center gap-2 px-3 py-2 rounded-full
                bg-neutral-900/60 border border-neutral-700 text-neutral-100
                dark:bg-neutral-900/60 dark:border-neutral-700">
        <span class="text-xs uppercase tracking-wide text-neutral-300">
            MLM Compuestos
        </span>
        <span class="inline-flex items-center justify-center min-w-[2.25rem] h-6 px-2 rounded-full
                     bg-fuchsia-500/20 text-fuchsia-200 text-xs font-bold">
            {{ number_format((int)($mlmCompuestosCount ?? 0)) }}
        </span>
    </div>

    {{-- Por página --}}
    <div class="flex items-center gap-2">
        <span class="text-sm text-zinc-600 dark:text-gray-300">Por página</span>
        <select name="per_page"
                onchange="this.form.submit()"
                class="rounded-md border border-zinc-300 bg-white px-3 py-2
                       text-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-white">
            @foreach ([10,25,50,100,200] as $n)
                <option value="{{ $n }}" @selected((int)request('per_page', 25) === $n)>{{ $n }}</option>
            @endforeach
        </select>
    </div>

</div>
        </form>

    </div>

    {{-- ================= LEYENDA ================= --}}
    <div class="flex flex-wrap gap-2 text-xs">
        <span class="px-2 py-1 rounded bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
            Falta en sistema
        </span>
        <span class="px-2 py-1 rounded bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200">
            SKU no coincide
        </span>
        <span class="px-2 py-1 rounded bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">
            Duplicado / otro SKU
        </span>
    </div>

    {{-- =========================
        A) FALTAN EN SISTEMA
    ========================== --}}
    <div class="rounded-lg border border-zinc-200 bg-white overflow-hidden dark:bg-neutral-900 dark:border-neutral-800">
        <div class="px-4 py-3 bg-zinc-100 text-zinc-700 dark:bg-neutral-800 dark:text-gray-300 font-semibold">
            A) Publicados en ML pero faltan / no cuadran en sistema ({{ count($missingInSystem) }})
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-zinc-700 dark:text-gray-300">
                <thead class="bg-zinc-50 text-zinc-600 dark:bg-neutral-900 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-2 text-left">MLM</th>
                        <th class="px-4 py-2">SKU (ML)</th>
                        <th class="px-4 py-2">Nombre</th>
                        <th class="px-4 py-2">Estatus</th>
                        <th class="px-4 py-2">Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($missingInSystem as $row)
                        @php $p = $row['product']; @endphp
                        <tr class="border-t border-zinc-200 dark:border-neutral-800 bg-red-50/60 dark:bg-red-950/20">
                            <td class="px-4 py-2 font-mono text-blue-600 dark:text-blue-400">{{ $p->ml }}</td>
                            <td class="px-4 py-2 font-mono">{{ $p->sku ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $p->name }}</td>
                            <td class="px-4 py-2">{{ $p->status_ml ?? '—' }}</td>
                            <td class="px-4 py-2 text-red-700 dark:text-red-300">
                                {{ $row['reason'] }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-4 text-center text-zinc-500 dark:text-gray-400">
                                Sin resultados en esta página.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- =========================
        B) SKU NO COINCIDE
    ========================== --}}
    <div class="rounded-lg border border-zinc-200 bg-white overflow-hidden dark:bg-neutral-900 dark:border-neutral-800">
        <div class="px-4 py-3 bg-zinc-100 text-zinc-700 dark:bg-neutral-800 dark:text-gray-300 font-semibold">
            B) SKU no coincide ({{ count($skuMismatch) }})
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-zinc-700 dark:text-gray-300">
                <thead class="bg-zinc-50 text-zinc-600 dark:bg-neutral-900 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-2 text-left">MLM</th>
                        <th class="px-4 py-2">SKU (ML)</th>
                        <th class="px-4 py-2">SKU (Sistema)</th>
                        <th class="px-4 py-2">Nombre</th>
                        <th class="px-4 py-2">Estatus</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($skuMismatch as $row)
                        @php $p = $row['product']; @endphp
                        <tr class="border-t border-zinc-200 dark:border-neutral-800 bg-orange-50/60 dark:bg-orange-950/20">
                            <td class="px-4 py-2 font-mono text-blue-600 dark:text-blue-400">{{ $p->ml }}</td>
                            <td class="px-4 py-2 font-mono">{{ $row['ml_sku'] !== '' ? $row['ml_sku'] : '— (vacío)' }}</td>
                            <td class="px-4 py-2 font-mono font-semibold text-orange-800 dark:text-orange-200">{{ $row['sys_sku'] }}</td>
                            <td class="px-4 py-2">{{ $p->name }}</td>
                            <td class="px-4 py-2">{{ $p->status_ml ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-4 text-center text-zinc-500 dark:text-gray-400">
                                Sin resultados en esta página.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- =========================
        C) DUPLICADOS / OTRO SKU
    ========================== --}}
    <div class="rounded-lg border border-zinc-200 bg-white overflow-hidden dark:bg-neutral-900 dark:border-neutral-800">
        <div class="px-4 py-3 bg-zinc-100 text-zinc-700 dark:bg-neutral-800 dark:text-gray-300 font-semibold">
            C) Mismo MLM con varios SKUs ({{ count($dupByMlm) }})
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-zinc-700 dark:text-gray-300">
                <thead class="bg-zinc-50 text-zinc-600 dark:bg-neutral-900 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-2 text-left">MLM</th>
                        <th class="px-4 py-2">SKUs detectados (pubs)</th>
                        <th class="px-4 py-2">Nombre</th>
                        <th class="px-4 py-2">Estatus</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($dupByMlm as $row)
                        @php $p = $row['product']; @endphp
                        <tr class="border-t border-zinc-200 dark:border-neutral-800 bg-purple-50/60 dark:bg-purple-950/20">
                            <td class="px-4 py-2 font-mono text-blue-600 dark:text-blue-400">{{ $p->ml }}</td>
                            <td class="px-4 py-2 font-mono">{{ implode(', ', $row['pub_skus']) }}</td>
                            <td class="px-4 py-2">{{ $p->name }}</td>
                            <td class="px-4 py-2">{{ $p->status_ml ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-4 text-center text-zinc-500 dark:text-gray-400">
                                Sin resultados en esta página.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- =========================
        LISTA GENERAL (products)
    ========================== --}}
    <div class="rounded-lg border border-zinc-200 bg-white overflow-hidden dark:bg-neutral-900 dark:border-neutral-800">
        <div class="px-4 py-3 bg-zinc-100 text-zinc-700 dark:bg-neutral-800 dark:text-gray-300 font-semibold">
            Lista general de publicados (products) — página actual
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm text-zinc-700 dark:text-gray-300">
                <thead class="bg-zinc-50 text-zinc-600 dark:bg-neutral-900 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-2 text-left">Nombre</th>
                        <th class="px-4 py-2">MLM</th>
                        <th class="px-4 py-2">SKU</th>
                        <th class="px-4 py-2 text-right">Precio</th>
                        <th class="px-4 py-2 text-center">Stock</th>
                        <th class="px-4 py-2">Estatus</th>
                        <th class="px-4 py-2">Categoría</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($products as $p)
                        <tr class="border-t border-zinc-200 dark:border-neutral-800">
                            <td class="px-4 py-2">{{ $p->name }}</td>
                            <td class="px-4 py-2 font-mono text-blue-600 dark:text-blue-400">{{ $p->ml }}</td>
                            <td class="px-4 py-2 font-mono">{{ $p->sku ?? '—' }}</td>
                            <td class="px-4 py-2 text-right">${{ number_format((float)$p->price, 2) }}</td>
                            <td class="px-4 py-2 text-center">{{ (int)$p->stock }}</td>
                            <td class="px-4 py-2">{{ $p->status_ml ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $p->category_name ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="p-3">
            {{ $products->links() }}
        </div>
    </div>

</div>

</x-layouts.app>