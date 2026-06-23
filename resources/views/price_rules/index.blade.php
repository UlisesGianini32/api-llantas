<x-layouts.app :title="__('Fórmulas de ventas')">

    <div class="min-h-screen bg-zinc-50 dark:bg-black p-6">
        <div class="mx-auto max-w-7xl space-y-6 text-zinc-900 dark:text-white">

            {{-- ✅ MENSAJES (igual que dashboard) --}}
            @if (session('ok'))
                <div class="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-emerald-800
                            dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">
                    {{ session('ok') }}
                </div>
            @endif

            @if (session('err'))
                <div class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-red-800
                            dark:border-red-800 dark:bg-red-950 dark:text-red-200">
                    {{ session('err') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-red-800
                            dark:border-red-800 dark:bg-red-950 dark:text-red-200">
                    <ul class="list-disc pl-5 space-y-1">
                        @foreach ($errors->all() as $e)
                            <li>{{ $e }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- ✅ HEADER CARD --}}
            <div class="rounded-lg bg-white p-4 border border-zinc-200 shadow-sm
                        dark:bg-neutral-900 dark:border-neutral-800 dark:shadow-none">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-semibold">Fórmulas de ventas</h1>

                        <p class="mt-2 text-sm text-zinc-600 dark:text-gray-400">
                            Variables permitidas:
                            <span class="font-mono">costo</span>,
                            <span class="font-mono">piezas</span> (1/2/4)
                            | Operadores:
                            <span class="font-mono">+ - * / ( )</span>
                        </p>

                        <p class="mt-1 text-sm text-zinc-600 dark:text-gray-400">
                            Nota: si un producto está en <b>manual</b>, el import NO cambia el precio.
                        </p>
                    </div>

                    <div class="text-xs text-zinc-600 dark:text-gray-400">
                        <div class="rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2
                                    dark:border-neutral-800 dark:bg-neutral-950">
                            Ejemplos:
                            <div class="mt-1 font-mono text-zinc-800 dark:text-gray-300">costo * 1.5</div>
                            <div class="font-mono text-zinc-800 dark:text-gray-300">(costo * piezas) * 1.45</div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ✅ EDITAR REGLAS --}}
            <div class="rounded-lg bg-white border border-zinc-200 overflow-hidden shadow-sm
                        dark:bg-neutral-900 dark:border-neutral-800 dark:shadow-none">
                <div class="flex items-center gap-2 border-b border-zinc-200 p-4
                            dark:border-neutral-800">
                    <span class="text-blue-600 dark:text-blue-400"</span>
                    <h2 class="font-semibold">Editar fórmulas</h2>
                </div>

                <form method="POST" action="{{ route('price_rules.update') }}" class="p-4 space-y-4">
                    @csrf

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        @foreach ($rules as $i => $rule)
                            <div class="rounded-lg bg-zinc-50 p-4 border border-zinc-200 space-y-3
                                        dark:bg-neutral-950 dark:border-neutral-800">

                                <div class="flex items-center justify-between">
                                    <p class="text-sm font-semibold text-zinc-800 dark:text-gray-200">
                                        {{ strtoupper($rule->scope) }}
                                    </p>

                                    <label class="flex items-center gap-2 text-xs text-zinc-700 dark:text-gray-300">
                                        <input
                                            type="checkbox"
                                            name="rules[{{ $i }}][active]"
                                            value="1"
                                            {{ $rule->active ? 'checked' : '' }}
                                            class="rounded border-zinc-300 bg-white
                                                   dark:border-neutral-700 dark:bg-neutral-800"
                                        >
                                        Activa
                                    </label>
                                </div>

                                <input type="hidden" name="rules[{{ $i }}][scope]" value="{{ $rule->scope }}">

                                <div>
                                    <label class="block text-xs text-zinc-600 dark:text-gray-400 mb-1">Fórmula</label>
                                    <input
                                        name="rules[{{ $i }}][formula]"
                                        value="{{ old("rules.$i.formula", $rule->formula) }}"
                                        class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2
                                               text-zinc-900 font-mono placeholder-zinc-400
                                               focus:outline-none focus:ring-2 focus:ring-indigo-500
                                               dark:border-neutral-700 dark:bg-neutral-900 dark:text-white dark:placeholder-gray-500"
                                        placeholder="Ej: costo * 1.5"
                                    >
                                </div>

                                <div class="text-[11px] text-zinc-500 dark:text-gray-500">
                                    Consejo: usa <span class="font-mono">piezas</span> si quieres una sola fórmula
                                    para par/juego4.
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <button
                        class="rounded-md border border-zinc-300 bg-white px-4 py-3 font-semibold
                               hover:bg-zinc-100 transition
                               dark:border-neutral-800 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                    >
                        Guardar fórmulas
                    </button>
                </form>
            </div>

            {{-- ✅ PROBAR FÓRMULA --}}
            <div class="rounded-lg bg-white border border-zinc-200 overflow-hidden shadow-sm
                        dark:bg-neutral-900 dark:border-neutral-800 dark:shadow-none">
                <div class="flex items-center gap-2 border-b border-zinc-200 p-4
                            dark:border-neutral-800">
                    <span class="text-green-600 dark:text-green-400"></span>
                    <h2 class="font-semibold">Probar fórmula</h2>
                </div>

                <form method="POST" action="{{ route('price_rules.test') }}" class="p-4 grid grid-cols-1 md:grid-cols-3 gap-3">
                    @csrf

                    <select
                        name="scope"
                        class="rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900
                               focus:outline-none focus:ring-2 focus:ring-indigo-500
                               dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                    >
                        <option value="llanta">LLANTA</option>
                        <option value="par">PAR</option>
                        <option value="juego4">JUEGO 4</option>
                    </select>

                    <input
                        type="number"
                        step="0.01"
                        name="costo"
                        placeholder="Costo (ej 1000)"
                        class="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900 placeholder-zinc-400
                               focus:outline-none focus:ring-2 focus:ring-indigo-500
                               dark:border-neutral-700 dark:bg-neutral-900 dark:text-white dark:placeholder-gray-500"
                    >

                    <button
                        class="rounded-md border border-zinc-300 bg-white px-4 py-2 font-semibold
                               hover:bg-zinc-100 transition
                               dark:border-neutral-800 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                    >
                        Probar
                    </button>
                </form>
            </div>

        </div>
    </div>

</x-layouts.app>
