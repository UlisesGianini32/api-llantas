{{-- resources/views/compuestos/edit.blade.php --}}
<x-layouts.app :title="__('Editar producto compuesto')">

<div class="mx-auto max-w-3xl space-y-6">

    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
        Editar producto compuesto – {{ $compuesto->sku }}
    </h1>

    {{-- ✅ ERRORES --}}
    @if ($errors->any())
        <div class="rounded-lg border border-red-300 bg-red-50 p-4 text-red-800
                    dark:border-red-800 dark:bg-red-950 dark:text-red-200">
            <div class="font-semibold mb-2">Hay errores:</div>
            <ul class="list-disc pl-5 space-y-1 text-sm">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="rounded-xl border border-zinc-200 bg-white p-6 text-zinc-900
                dark:border-neutral-800 dark:bg-neutral-900 dark:text-white">

        <form method="POST" action="{{ route('productos.update', $compuesto->id) }}" class="space-y-4">
            @csrf
            @method('PUT')

            {{-- ✅ CONTEXTO (para volver a la misma página) --}}
            <input type="hidden" name="page" value="{{ request('page', 1) }}">
            <input type="hidden" name="search" value="{{ request('search', '') }}">
            <input type="hidden" name="sort" value="{{ request('sort') }}">
            <input type="hidden" name="dir" value="{{ request('dir') }}">

            {{-- LLANTA BASE + ESTADO --}}
            @php $mode = $compuesto->price_mode ?? 'auto'; @endphp
            <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-4
                        dark:border-neutral-800 dark:bg-neutral-900">
                <p class="text-sm text-zinc-500 dark:text-gray-400">Llanta base (SKU)</p>
                <p class="font-mono text-indigo-600 dark:text-indigo-400">
                    {{ $compuesto->llanta->sku ?? '—' }}
                </p>

                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs text-zinc-500 dark:text-gray-400">
                        MANUAL = el import no pisa el precio · AUTO = el import sí puede recalcular.
                    </p>

                    <span class="inline-flex items-center rounded-md border px-3 py-1 text-xs font-semibold
                        {{ $mode === 'manual'
                            ? 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950 dark:text-amber-200'
                            : 'border-sky-300 bg-sky-50 text-sky-800 dark:border-sky-900/50 dark:bg-sky-950 dark:text-sky-200'
                        }}">
                        Estado: {{ strtoupper($mode) }}
                    </span>
                </div>
            </div>

            {{-- DESCRIPCIÓN --}}
            <div>
                <label class="block text-sm mb-1 text-zinc-600 dark:text-gray-400">Descripción</label>
                <textarea name="descripcion" rows="3"
                          class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900 placeholder-zinc-400
                                 focus:outline-none focus:ring-2 focus:ring-indigo-500
                                 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white dark:placeholder-zinc-500">{{ old('descripcion', $compuesto->descripcion) }}</textarea>
            </div>

            {{-- TÍTULO --}}
            <div>
                <label class="block text-sm mb-1 text-zinc-600 dark:text-gray-400">Título MercadoLibre</label>
                <input type="text" name="title_familyname"
                       value="{{ old('title_familyname', $compuesto->title_familyname) }}"
                       required
                       class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900 placeholder-zinc-400
                              focus:outline-none focus:ring-2 focus:ring-indigo-500
                              dark:border-neutral-700 dark:bg-neutral-800 dark:text-white dark:placeholder-zinc-500">
            </div>

            {{-- COSTO --}}
            <div>
                <label class="block text-sm mb-1 text-zinc-600 dark:text-gray-400">Costo (manual)</label>
                <input type="number" step="0.01" name="costo"
                       value="{{ old('costo', $compuesto->costo) }}"
                       class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900
                              focus:outline-none focus:ring-2 focus:ring-indigo-500
                              dark:border-neutral-700 dark:bg-neutral-800 dark:text-white">
                <p class="mt-1 text-xs text-zinc-500 dark:text-gray-500">
                    Si lo dejas vacío, se usa el calculado desde la llanta (costo * piezas).
                </p>
            </div>

            {{-- PRECIO ML --}}
            <div>
                <label class="block text-sm mb-1 text-zinc-600 dark:text-gray-400">Precio MercadoLibre (manual)</label>
                <input type="number" step="0.01" name="precio_ML"
                       value="{{ old('precio_ML', $compuesto->precio_ML) }}"
                       class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900
                              focus:outline-none focus:ring-2 focus:ring-indigo-500
                              dark:border-neutral-700 dark:bg-neutral-800 dark:text-white">
                <p class="mt-1 text-xs text-zinc-500 dark:text-gray-500">
                    Si lo editas, el import ya NO lo pisa (se marca MANUAL).
                </p>
            </div>

            {{-- MLM --}}
            <div>
                <label class="block text-sm mb-1 text-zinc-600 dark:text-gray-400">Código MercadoLibre (MLM)</label>
                <input type="text" name="MLM"
                       value="{{ old('MLM', $compuesto->MLM) }}"
                       class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900
                              focus:outline-none focus:ring-2 focus:ring-indigo-500
                              dark:border-neutral-700 dark:bg-neutral-800 dark:text-white">
            </div>

            {{-- BOTONES --}}
            <div class="flex gap-3 pt-2">
                <button type="submit"
                        class="rounded-md bg-indigo-600 px-4 py-2 font-semibold text-white hover:bg-indigo-700">
                    Guardar
                </button>

                <a href="{{ route('productos.index', [
                        'page' => request('page', 1),
                        'search' => request('search', ''),
                        'sort' => request('sort'),
                        'dir' => request('dir'),
                    ]) }}"
                   class="rounded-md border border-zinc-300 bg-white px-4 py-2 text-zinc-900 hover:bg-zinc-100
                          dark:border-neutral-700 dark:bg-neutral-900 dark:text-white dark:hover:bg-neutral-800">
                    Cancelar
                </a>
            </div>
        </form>

        {{-- ✅ CONTROL DE PRECIO --}}
        <div class="mt-6 border-t border-zinc-200 pt-5 dark:border-neutral-800">
            <p class="text-sm font-semibold text-zinc-900 dark:text-white">
                Control de precio (para que el import no lo cambie)
            </p>
            <p class="text-xs text-zinc-500 dark:text-gray-400">
                MANUAL = el import no pisa el precio · AUTO = el import sí puede recalcular.
            </p>

            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">

                {{-- MANUAL --}}
                <form method="POST" action="{{ route('productos.price.manual', $compuesto->id) }}" class="m-0">
                    @csrf
                    <input type="hidden" name="page" value="{{ request('page', 1) }}">
                    <input type="hidden" name="search" value="{{ request('search', '') }}">
                    <input type="hidden" name="sort" value="{{ request('sort') }}">
                    <input type="hidden" name="dir" value="{{ request('dir') }}">

                    <button type="submit"
                            class="w-full rounded-md border border-zinc-300 bg-white px-4 py-3 text-left
                                   hover:bg-zinc-50 transition
                                   dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800">
                        <div class="text-sm font-semibold text-zinc-900 dark:text-white">Bloquear (MANUAL)</div>
                        <div class="text-xs text-zinc-500 dark:text-gray-400">El import ya NO cambiará el precio.</div>
                    </button>
                </form>

                {{-- AUTO --}}
                <form method="POST" action="{{ route('productos.price.auto', $compuesto->id) }}" class="m-0">
                    @csrf
                    <input type="hidden" name="page" value="{{ request('page', 1) }}">
                    <input type="hidden" name="search" value="{{ request('search', '') }}">
                    <input type="hidden" name="sort" value="{{ request('sort') }}">
                    <input type="hidden" name="dir" value="{{ request('dir') }}">

                    <button type="submit"
                            class="w-full rounded-md border border-zinc-300 bg-white px-4 py-3 text-left
                                   hover:bg-zinc-50 transition
                                   dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800">
                        <div class="text-sm font-semibold text-zinc-900 dark:text-white">Permitir import (AUTO)</div>
                        <div class="text-xs text-zinc-500 dark:text-gray-400">El import podrá recalcular el precio.</div>
                    </button>
                </form>

                {{-- RECALC --}}
                <form method="POST" action="{{ route('productos.price.recalc', $compuesto->id) }}" class="m-0">
                    @csrf
                    <input type="hidden" name="page" value="{{ request('page', 1) }}">
                    <input type="hidden" name="search" value="{{ request('search', '') }}">
                    <input type="hidden" name="sort" value="{{ request('sort') }}">
                    <input type="hidden" name="dir" value="{{ request('dir') }}">

                    <button type="submit"
                            class="w-full rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-left
                                   hover:bg-emerald-100 transition
                                   dark:border-emerald-900/50 dark:bg-emerald-950 dark:hover:bg-emerald-900/40">
                        <div class="text-sm font-semibold text-emerald-800 dark:text-emerald-200">Recalcular (AUTO)</div>
                        <div class="text-xs text-emerald-700/80 dark:text-emerald-200/80">Aplica fórmula activa y pone AUTO.</div>
                    </button>
                </form>

            </div>
        </div>

    </div>
</div>

</x-layouts.app>
