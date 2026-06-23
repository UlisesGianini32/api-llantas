{{-- resources/views/ams/pedidos/procesar.blade.php --}}
<x-layouts.app :title="$tituloPagina ?? 'AMS - Pedidos por procesar'">
    <section class="bg-[#0b1220] min-h-screen py-4 sm:py-6">
        <div class="mx-auto max-w-[1400px] px-4 sm:px-6 lg:px-8">

            <div class="rounded-none border border-slate-700 bg-[#00153b] p-6 shadow-2xl">

                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h1 class="text-2xl sm:text-3xl font-bold text-white">
                            {{ $tituloPagina ?? 'AMS - Pedidos por procesar' }}
                        </h1>

                        <p class="mt-2 text-sm text-slate-300">
                            {{ $subtitulo ?? 'Mostrando pedidos que te toca procesar el día:' }}
                            {{ \Carbon\Carbon::parse($fechaSeleccionada)->format('d/m/Y') }}
                        </p>
                    </div>

                    <form method="GET" action="{{ url()->current() }}" class="flex flex-col sm:flex-row sm:items-end gap-3">
                        <div>
                            <label for="fecha" class="mb-1 block text-sm font-medium text-white">
                                Seleccionar fecha
                            </label>
                            <input
                                type="date"
                                id="fecha"
                                name="fecha"
                                value="{{ $fechaSeleccionada }}"
                                class="w-full rounded-lg border border-slate-500 bg-slate-800 px-4 py-2 text-white outline-none focus:border-sky-400"
                            >
                        </div>

                        <div>
                            <button
                                type="submit"
                                class="rounded-lg border border-slate-500 bg-slate-800 px-5 py-2 text-white transition hover:bg-slate-700"
                            >
                                Ver pedidos
                            </button>
                        </div>
                    </form>
                </div>

                <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="border border-slate-700 bg-slate-800/70 p-4">
                        <div class="text-sm text-slate-300">Pedidos</div>
                        <div class="mt-2 text-3xl font-bold text-white">{{ $totalPedidos ?? 0 }}</div>
                    </div>

                    <div class="border border-slate-700 bg-slate-800/70 p-4">
                        <div class="text-sm text-slate-300">Piezas vendidas</div>
                        <div class="mt-2 text-3xl font-bold text-white">{{ $totalPiezas ?? 0 }}</div>
                    </div>

                    <div class="border border-slate-700 bg-slate-800/70 p-4">
                        <div class="text-sm text-slate-300">Total vendido</div>
                        <div class="mt-2 text-3xl font-bold text-emerald-400">
                            ${{ number_format((float) ($totalVendido ?? 0), 2) }}
                        </div>
                    </div>
                </div>

                <div class="mt-6">
                    @forelse ($pedidos as $pedido)
                        <div class="mb-6 border border-slate-500 bg-[#1b2a41]">

                            <div class="flex flex-col gap-2 border-b border-slate-500 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div class="text-sm font-semibold text-white">
                                    Pedido #{{ $pedido->display_id }}
                                </div>

                                <div class="text-sm text-slate-200">
                                    {{ $pedido->fecha_pedido_formateada }}
                                </div>
                            </div>

                            <div class="divide-y divide-slate-500">
                                @foreach ($pedido->items as $item)
                                    <div class="p-4">
                                        <div class="grid grid-cols-1 gap-4 lg:grid-cols-[76px_minmax(0,1fr)]">

                                            <div class="flex items-start justify-center lg:justify-start">
                                                @if (!empty($item->imagen))
                                                    <img
                                                        src="{{ $item->imagen }}"
                                                        alt="{{ $item->titulo }}"
                                                        class="h-[76px] w-[76px] rounded-xl bg-white object-contain p-1"
                                                    >
                                                @else
                                                    <div class="flex h-[76px] w-[76px] items-center justify-center rounded-xl bg-slate-700 text-xs text-slate-300">
                                                        Sin imagen
                                                    </div>
                                                @endif
                                            </div>

                                            <div class="min-w-0">
                                                <h3 class="mb-3 text-lg font-semibold leading-tight text-white">
                                                    {{ $item->titulo }}
                                                </h3>

                                                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                                                    <div class="rounded-xl border border-slate-400 bg-[#1b2a41] px-4 py-3">
                                                        <div class="text-xs uppercase tracking-wide text-slate-300">Piezas</div>
                                                        <div class="mt-1 text-3xl font-semibold text-white">
                                                            {{ $item->cantidad }}
                                                        </div>
                                                    </div>

                                                    <div class="rounded-xl border border-slate-400 bg-[#1b2a41] px-4 py-3">
                                                        <div class="text-xs uppercase tracking-wide text-slate-300">SKU</div>
                                                        <div class="mt-1 break-all text-3xl font-semibold text-white">
                                                            {{ $item->sku ?: 'N/A' }}
                                                        </div>
                                                    </div>

                                                    <div class="rounded-xl border border-slate-400 bg-[#1b2a41] px-4 py-3">
                                                        <div class="text-xs uppercase tracking-wide text-slate-300">Precio unitario</div>
                                                        <div class="mt-1 text-3xl font-semibold text-white">
                                                            ${{ number_format((float) $item->precio_unitario, 2) }}
                                                        </div>
                                                    </div>

                                                    <div class="rounded-xl border border-slate-400 bg-[#1b2a41] px-4 py-3">
                                                        <div class="text-xs uppercase tracking-wide text-slate-300">Total</div>
                                                        <div class="mt-1 text-3xl font-semibold text-white">
                                                            ${{ number_format((float) $item->total_linea, 2) }}
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <div class="border-t border-slate-500 px-4 py-3 text-sm text-white">
                                Total de piezas del pedido:
                                <span class="font-semibold">{{ $pedido->total_piezas }}</span>

                                <span class="mx-2">|</span>

                                Total del pedido:
                                <span class="font-semibold">${{ number_format((float) $pedido->total_pedido, 2) }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="border border-slate-700 bg-slate-800/60 px-6 py-16 text-center">
                            <p class="text-3xl font-semibold text-white">No hay pedidos para esta fecha</p>
                            <p class="mt-3 text-lg text-slate-300">
                                Cambia la fecha para consultar otros pedidos.
                            </p>
                        </div>
                    @endforelse
                </div>

            </div>
        </div>
    </section>
</x-layouts.app>