import { router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

function money(n) {
    return `$${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

export default function PedidosIndex({
    tituloPagina,
    subtitulo,
    fechaSeleccionada,
    dateFilterUrl,
    totalPedidos,
    totalPiezas,
    totalVendido,
    pedidos = [],
}) {
    const onFilterDate = (e) => {
        e.preventDefault()
        const fd = new FormData(e.currentTarget)
        const fecha = fd.get('fecha') || ''
        router.get(dateFilterUrl, { fecha }, { preserveScroll: true })
    }

    const fechaLabel = fechaSeleccionada
        ? new Date(fechaSeleccionada + 'T12:00:00').toLocaleDateString('es-MX', {
              day: '2-digit',
              month: '2-digit',
              year: 'numeric',
          })
        : ''

    return (
        <AppShell title={tituloPagina}>
            <section className="min-h-[calc(100vh-8rem)] rounded-2xl bg-[#1f2d44] p-4 shadow-xl sm:p-6">
                <div className="rounded-2xl bg-[#031633] p-6 shadow-2xl">
                    <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                        <div>
                            <h2 className="text-3xl font-bold text-white sm:text-4xl">{tituloPagina}</h2>
                            <p className="mt-2 text-lg text-slate-300">
                                {subtitulo} {fechaLabel}
                            </p>
                        </div>

                        <form onSubmit={onFilterDate} className="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <div>
                                <label htmlFor="fecha-index" className="mb-1 block text-sm font-semibold text-white">
                                    Seleccionar fecha
                                </label>
                                <input
                                    type="date"
                                    id="fecha-index"
                                    name="fecha"
                                    defaultValue={fechaSeleccionada}
                                    className="rounded-xl border border-slate-500 bg-[#1f2d44] px-4 py-3 text-white focus:border-cyan-400 focus:outline-none"
                                />
                            </div>
                            <button
                                type="submit"
                                className="rounded-xl bg-slate-700 px-5 py-3 font-semibold text-white transition hover:bg-slate-600"
                            >
                                Ver pedidos
                            </button>
                        </form>
                    </div>

                    <div className="mt-6 grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div className="rounded-xl bg-[#1f2d44] p-4">
                            <div className="text-sm text-slate-300">Pedidos</div>
                            <div className="mt-1 text-3xl font-bold text-white">{totalPedidos}</div>
                        </div>
                        <div className="rounded-xl bg-[#1f2d44] p-4">
                            <div className="text-sm text-slate-300">Piezas vendidas</div>
                            <div className="mt-1 text-3xl font-bold text-white">{totalPiezas}</div>
                        </div>
                        <div className="rounded-xl bg-[#1f2d44] p-4">
                            <div className="text-sm text-slate-300">Total vendido</div>
                            <div className="mt-1 text-3xl font-bold text-emerald-400">{money(totalVendido)}</div>
                        </div>
                    </div>

                    <div className="mt-8 space-y-6">
                        {pedidos.length === 0 ? (
                            <div className="rounded-2xl border border-slate-600 bg-[#1f2d44] px-6 py-14 text-center">
                                <div className="text-2xl font-semibold text-white sm:text-3xl">
                                    No hay pedidos para esta fecha
                                </div>
                                <p className="mt-2 text-lg text-slate-300">Cambia la fecha para consultar otros pedidos.</p>
                            </div>
                        ) : (
                            pedidos.map((pedido) => (
                                <article
                                    key={pedido.group_key}
                                    className="overflow-hidden rounded-2xl border border-slate-600 bg-[#1f2d44] shadow-lg"
                                >
                                    <div className="flex flex-col gap-2 border-b border-slate-600 px-4 py-3 md:flex-row md:items-center md:justify-between">
                                        <div className="text-sm font-semibold text-white">Pedido #{pedido.order_id}</div>
                                        <div className="text-sm text-slate-300">{pedido.fecha_pedido_formateada}</div>
                                    </div>

                                    <div className="divide-y divide-slate-600">
                                        {pedido.items.map((item) => (
                                            <div key={`${pedido.group_key}-${item.item_id}-${item.sku}`} className="p-4">
                                                <div className="grid grid-cols-1 gap-4 md:grid-cols-[84px_minmax(0,1fr)]">
                                                    <div className="flex items-start justify-center">
                                                        {item.imagen ? (
                                                            <img
                                                                src={item.imagen}
                                                                alt={item.titulo}
                                                                className="h-20 w-20 rounded-xl bg-white object-contain p-1"
                                                            />
                                                        ) : (
                                                            <div className="flex h-20 w-20 items-center justify-center rounded-xl bg-slate-700 text-xs text-slate-300">
                                                                Sin imagen
                                                            </div>
                                                        )}
                                                    </div>
                                                    <div>
                                                        <h3 className="text-xl font-semibold leading-tight text-white sm:text-2xl">
                                                            {item.titulo}
                                                        </h3>
                                                        <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                                                            <div className="rounded-xl border border-slate-400 bg-[#1f2d44] px-4 py-3">
                                                                <div className="text-xs uppercase tracking-wide text-slate-300">Piezas</div>
                                                                <div className="mt-1 text-2xl text-white">{item.cantidad}</div>
                                                            </div>
                                                            <div className="rounded-xl border border-slate-400 bg-[#1f2d44] px-4 py-3">
                                                                <div className="text-xs uppercase tracking-wide text-slate-300">SKU</div>
                                                                <div className="mt-1 break-all text-xl text-white">{item.sku || 'Sin SKU'}</div>
                                                            </div>
                                                            <div className="rounded-xl border border-slate-400 bg-[#1f2d44] px-4 py-3">
                                                                <div className="text-xs uppercase tracking-wide text-slate-300">Precio unitario</div>
                                                                <div className="mt-1 text-2xl text-white">{money(item.precio_unitario)}</div>
                                                            </div>
                                                            <div className="rounded-xl border border-slate-400 bg-[#1f2d44] px-4 py-3">
                                                                <div className="text-xs uppercase tracking-wide text-slate-300">Total</div>
                                                                <div className="mt-1 text-2xl font-semibold text-white">{money(item.total_linea)}</div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>

                                    <div className="border-t border-slate-600 bg-[#16253a] px-4 py-3">
                                        <div className="flex flex-col gap-2 text-sm md:flex-row md:items-center md:justify-between">
                                            <div className="text-slate-300">
                                                Total de piezas del pedido:{' '}
                                                <span className="font-semibold text-white">{pedido.total_piezas}</span>
                                            </div>
                                            <div className="text-slate-300">
                                                Total del pedido:{' '}
                                                <span className="font-semibold text-emerald-400">{money(pedido.total_pedido)}</span>
                                            </div>
                                        </div>
                                    </div>
                                </article>
                            ))
                        )}
                    </div>
                </div>
            </section>
        </AppShell>
    )
}
