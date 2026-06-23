<x-layouts.app :title="$tituloPagina">
    <section class="bg-[#1f2d44] min-h-screen py-6">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="rounded-2xl bg-[#031633] p-6 shadow-2xl">

                <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                    <div>
                        <h1 class="text-4xl font-bold text-white">{{ $tituloPagina }}</h1>
                        <p class="mt-2 text-lg text-slate-300">
                            {{ $subtitulo }} {{ \Carbon\Carbon::parse($fechaSeleccionada)->format('d/m/Y') }}
                        </p>
                    </div>

                    <form method="GET" action="{{ route('ams.pedidos.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div>
                            <label for="fecha" class="mb-1 block text-sm font-semibold text-white">
                                Seleccionar fecha
                            </label>
                            <input
                                type="date"
                                id="fecha"
                                name="fecha"
                                value="{{ $fechaSeleccionada }}"
                                class="rounded-xl border border-slate-500 bg-[#1f2d44] px-4 py-3 text-white focus:border-cyan-400 focus:outline-none"
                            >
                        </div>

                        <button
                            type="submit"
                            class="rounded-xl bg-slate-700 px-5 py-3 font-semibold text-white transition hover:bg-slate-600"
                        >
                            Ver pedidos
                        </button>
                    </form>
                </div>

                <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div class="rounded-xl bg-[#1f2d44] p-4">
                        <div class="text-sm text-slate-300">Pedidos</div>
                        <div class="mt-1 text-3xl font-bold text-white">{{ $totalPedidos }}</div>
                    </div>

                    <div class="rounded-xl bg-[#1f2d44] p-4">
                        <div class="text-sm text-slate-300">Piezas vendidas</div>
                        <div class="mt-1 text-3xl font-bold text-white">{{ $totalPiezas }}</div>
                    </div>

                    <div class="rounded-xl bg-[#1f2d44] p-4">
                        <div class="text-sm text-slate-300">Total vendido</div>
                        <div class="mt-1 text-3xl font-bold text-emerald-400">
                            ${{ number_format($totalVendido, 2) }}
                        </div>
                    </div>
                </div>

                <div class="mt-8 space-y-6">
                    @forelse($pedidos as $pedido)
                        <article class="overflow-hidden rounded-2xl border border-slate-600 bg-[#1f2d44] shadow-lg">
                            <div class="flex flex-col gap-2 border-b border-slate-600 px-4 py-3 md:flex-row md:items-center md:justify-between">
                                <div class="text-sm font-semibold text-white">
                                    Pedido #{{ $pedido->order_id }}
                                </div>
                                <div class="text-sm text-slate-300">
                                    {{ $pedido->fecha_pedido_formateada }}
                                </div>
                            </div>

                            <div class="divide-y divide-slate-600">
                                @foreach($pedido->items as $item)
                                    <div class="p-4">
                                        <div class="grid grid-cols-1 gap-4 md:grid-cols-[84px_minmax(0,1fr)]">
                                            <div class="flex items-start justify-center">
                                                @if($item->imagen)
                                                    <img
                                                        src="{{ $item->imagen }}"
                                                        alt="{{ $item->titulo }}"
                                                        class="h-20 w-20 rounded-xl bg-white object-contain p-1"
                                                    >
                                                @else
                                                    <div class="flex h-20 w-20 items-center justify-center rounded-xl bg-slate-700 text-xs text-slate-300">
                                                        Sin imagen
                                                    </div>
                                                @endif
                                            </div>

                                            <div>
                                                <h3 class="text-2xl font-semibold leading-tight text-white">
                                                    {{ $item->titulo }}
                                                </h3>

                                                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                                                    <div class="rounded-xl border border-slate-400 bg-[#1f2d44] px-4 py-3">
                                                        <div class="text-xs uppercase tracking-wide text-slate-300">Piezas</div>
                                                        <div class="mt-1 text-2xl text-white">{{ $item->cantidad }}</div>
                                                    </div>

                                                    <div class="rounded-xl border border-slate-400 bg-[#1f2d44] px-4 py-3">
                                                        <div class="text-xs uppercase tracking-wide text-slate-300">SKU</div>
                                                        <div class="mt-1 break-all text-xl text-white">
                                                            {{ $item->sku ?: 'Sin SKU' }}
                                                        </div>
                                                    </div>

                                                    <div class="rounded-xl border border-slate-400 bg-[#1f2d44] px-4 py-3">
                                                        <div class="text-xs uppercase tracking-wide text-slate-300">Precio unitario</div>
                                                        <div class="mt-1 text-2xl text-white">
                                                            ${{ number_format($item->precio_unitario, 2) }}
                                                        </div>
                                                    </div>

                                                    <div class="rounded-xl border border-slate-400 bg-[#1f2d44] px-4 py-3">
                                                        <div class="text-xs uppercase tracking-wide text-slate-300">Total</div>
                                                        <div class="mt-1 text-2xl font-semibold text-white">
                                                            ${{ number_format($item->total_linea, 2) }}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="border-t border-slate-600 bg-[#16253a] px-4 py-3">
                                <div class="flex flex-col gap-2 text-sm md:flex-row md:items-center md:justify-between">
                                    <div class="text-slate-300">
                                        Total de piezas del pedido:
                                        <span class="font-semibold text-white">{{ $pedido->total_piezas }}</span>
                                    </div>
                                    <div class="text-slate-300">
                                        Total del pedido:
                                        <span class="font-semibold text-emerald-400">${{ number_format($pedido->total_pedido, 2) }}</span>
                                    </div>
                                </div>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-slate-600 bg-[#1f2d44] px-6 py-14 text-center">
                            <div class="text-3xl font-semibold text-white">No hay pedidos para esta fecha</div>
                            <p class="mt-2 text-lg text-slate-300">Cambia la fecha para consultar otros pedidos.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </section>
</x-layouts.app>