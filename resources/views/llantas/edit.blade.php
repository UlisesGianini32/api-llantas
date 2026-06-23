<x-layouts.app :title="__('Editar llanta')">

<div class="mx-auto max-w-3xl space-y-6">

    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
        Editar llanta – {{ $llanta->sku }}
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

        <form method="POST" action="{{ route('llantas.update', $llanta->id) }}" class="space-y-4">
            @csrf
            @method('PUT')

            {{-- ✅ CONTEXTO (para volver a la misma página/búsqueda/orden) --}}
            <input type="hidden" name="page" value="{{ request('page', $page ?? 1) }}">
            <input type="hidden" name="search" value="{{ request('search', $search ?? '') }}">
            <input type="hidden" name="sort" value="{{ request('sort') }}">
            <input type="hidden" name="dir" value="{{ request('dir') }}">

            {{-- Marca --}}
            <div>
                <label class="block text-sm mb-1 text-zinc-600 dark:text-gray-400">Marca</label>
                <input type="text" name="marca" value="{{ old('marca', $llanta->marca) }}" required
                       class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900
                              focus:outline-none focus:ring-2 focus:ring-indigo-500
                              dark:border-neutral-700 dark:bg-neutral-800 dark:text-white">
            </div>

            {{-- Medida --}}
            <div>
                <label class="block text-sm mb-1 text-zinc-600 dark:text-gray-400">Medida</label>
                <input type="text" name="medida" value="{{ old('medida', $llanta->medida) }}" required
                       class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900
                              focus:outline-none focus:ring-2 focus:ring-indigo-500
                              dark:border-neutral-700 dark:bg-neutral-800 dark:text-white">
            </div>

            {{-- Descripción --}}
            <div>
                <label class="block text-sm mb-1 text-zinc-600 dark:text-gray-400">Descripción</label>
                <textarea name="descripcion" rows="3"
                          class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900
                                 focus:outline-none focus:ring-2 focus:ring-indigo-500
                                 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white">{{ old('descripcion', $llanta->descripcion) }}</textarea>
            </div>

            {{-- Título --}}
            <div>
                <label class="block text-sm mb-1 text-zinc-600 dark:text-gray-400">Título MercadoLibre</label>
                <input type="text" name="title_familyname" value="{{ old('title_familyname', $llanta->title_familyname) }}" required
                       class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900
                              focus:outline-none focus:ring-2 focus:ring-indigo-500
                              dark:border-neutral-700 dark:bg-neutral-800 dark:text-white">
            </div>

            {{-- Costo --}}
            <div>
                <label class="block text-sm mb-1 text-zinc-600 dark:text-gray-400">Costo</label>
                <input type="number" step="0.01" name="costo" value="{{ old('costo', $llanta->costo) }}" required
                       class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900
                              focus:outline-none focus:ring-2 focus:ring-indigo-500
                              dark:border-neutral-700 dark:bg-neutral-800 dark:text-white">
            </div>

            {{-- Precio ML --}}
            <div>
                <label class="block text-sm mb-1 text-zinc-600 dark:text-gray-400">Precio MercadoLibre</label>
                <input type="number" step="0.01" name="precio_ML" value="{{ old('precio_ML', $llanta->precio_ML) }}" required
                       class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900
                              focus:outline-none focus:ring-2 focus:ring-indigo-500
                              dark:border-neutral-700 dark:bg-neutral-800 dark:text-white">
                <p class="mt-1 text-xs text-zinc-500 dark:text-gray-500">
                    Si lo editas, se marcará MANUAL y el import ya no lo pisa.
                </p>
            </div>

            {{-- Stock --}}
            <div>
                <label class="block text-sm mb-1 text-zinc-600 dark:text-gray-400">Stock</label>
                <input type="number" name="stock" value="{{ old('stock', $llanta->stock) }}" required
                       class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900
                              focus:outline-none focus:ring-2 focus:ring-indigo-500
                              dark:border-neutral-700 dark:bg-neutral-800 dark:text-white">
            </div>

            {{-- MLM --}}
            <div>
                <label class="block text-sm mb-1 text-zinc-600 dark:text-gray-400">MLM</label>
                <input type="text" name="MLM" value="{{ old('MLM', $llanta->MLM) }}"
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

                <a href="{{ route('llantas.index', [
                        'page' => request('page', $page ?? 1),
                        'search' => request('search', $search ?? ''),
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
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-semibold text-zinc-900 dark:text-white">
                        Control de precio (para que el import no lo cambie)
                    </p>
                    <p class="text-xs text-zinc-500 dark:text-gray-400">
                        MANUAL = el import no pisa el precio · AUTO = el import sí puede recalcular.
                    </p>
                </div>

                @php $mode = $llanta->price_mode ?? 'auto'; @endphp
                <span class="inline-flex items-center rounded-md border px-3 py-1 text-xs font-semibold
                    {{ $mode === 'manual'
                        ? 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950 dark:text-amber-200'
                        : 'border-sky-300 bg-sky-50 text-sky-800 dark:border-sky-900/50 dark:bg-sky-950 dark:text-sky-200'
                    }}">
                    Estado: {{ strtoupper($mode) }}
                </span>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                {{-- Bloquear (MANUAL) --}}
                <form method="POST" action="{{ route('llantas.price.manual', $llanta->id) }}" class="m-0">
                    @csrf
                    <button type="submit"
                            class="w-full rounded-md border border-zinc-300 bg-white px-4 py-3 text-left
                                   hover:bg-zinc-50 transition
                                   dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800">
                        <div class="text-sm font-semibold text-zinc-900 dark:text-white">Bloquear (MANUAL)</div>
                        <div class="text-xs text-zinc-500 dark:text-gray-400">El import ya NO cambiará el precio.</div>
                    </button>
                </form>

                {{-- Permitir import (AUTO) --}}
                <form method="POST" action="{{ route('llantas.price.auto', $llanta->id) }}" class="m-0">
                    @csrf
                    <button type="submit"
                            class="w-full rounded-md border border-zinc-300 bg-white px-4 py-3 text-left
                                   hover:bg-zinc-50 transition
                                   dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800">
                        <div class="text-sm font-semibold text-zinc-900 dark:text-white">Permitir import (AUTO)</div>
                        <div class="text-xs text-zinc-500 dark:text-gray-400">El import podrá recalcular el precio.</div>
                    </button>
                </form>

                {{-- Recalcular (AUTO) --}}
                <form method="POST" action="{{ route('llantas.price.recalc', $llanta->id) }}" class="m-0">
                    @csrf
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
