import { router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

const deliveryOptions = [
    ['all', 'Todos'],
    ['mercado_envios', 'Mercado Envíos'],
    ['agreed_with_buyer', 'Acordados con comprador'],
    ['custom_shipping', 'Envío personalizado'],
    ['pending_identification', 'Pendientes de identificar'],
]

const deliveryLabels = {
    mercado_envios: 'Mercado Envíos',
    agreed_with_buyer: 'Acordar entrega',
    custom_shipping: 'Envío personalizado',
    pending_shipment: 'Esperando envío',
    unknown: 'Sin identificar',
}

function money(n) {
    return `$${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function deliveryClass(type) {
    if (type === 'agreed_with_buyer') return 'border-amber-400 bg-amber-500/20 text-amber-200'
    if (type === 'mercado_envios') return 'border-cyan-400 bg-cyan-500/20 text-cyan-200'
    if (type === 'custom_shipping') return 'border-violet-400 bg-violet-500/20 text-violet-200'
    if (type === 'pending_shipment') return 'border-blue-400 bg-blue-500/20 text-blue-200'
    return 'border-slate-400 bg-slate-500/20 text-slate-200'
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
    meliAccounts = [],
    selectedMeliAccountId = 'all',
    deliveryType = 'all',
}) {
    const visit = (overrides = {}) => {
        router.get(dateFilterUrl, {
            fecha: fechaSeleccionada,
            account_id: selectedMeliAccountId,
            delivery_type: deliveryType,
            ...overrides,
        }, { preserveScroll: true, preserveState: true })
    }

    const onFilter = (event) => {
        event.preventDefault()
        const data = new FormData(event.currentTarget)
        visit({ fecha: data.get('fecha') || '', account_id: data.get('account_id') || 'all' })
    }

    const fechaLabel = fechaSeleccionada
        ? new Date(`${fechaSeleccionada}T12:00:00`).toLocaleDateString('es-MX', {
              day: '2-digit',
              month: '2-digit',
              year: 'numeric',
          })
        : ''

    return (
        <AppShell title={tituloPagina}>
            <section className="min-h-[calc(100vh-8rem)] rounded-2xl bg-[#1f2d44] p-4 shadow-xl sm:p-6">
                <div className="rounded-2xl bg-[#031633] p-6 shadow-2xl">
                    <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                        <div>
                            <h2 className="text-3xl font-bold text-white sm:text-4xl">{tituloPagina}</h2>
                            <p className="mt-2 text-lg text-slate-300">{subtitulo} {fechaLabel}</p>
                        </div>
                        <form onSubmit={onFilter} className="grid gap-3 sm:grid-cols-[minmax(190px,1fr)_minmax(220px,1fr)_auto] sm:items-end">
                            <div>
                                <label htmlFor="fecha-index" className="mb-1 block text-sm font-semibold text-white">Fecha</label>
                                <input type="date" id="fecha-index" name="fecha" defaultValue={fechaSeleccionada} className="w-full rounded-xl border border-slate-500 bg-[#1f2d44] px-4 py-3 text-white focus:border-cyan-400 focus:outline-none" />
                            </div>
                            <div>
                                <label htmlFor="meli-account" className="mb-1 block text-sm font-semibold text-white">Cuenta Mercado Libre</label>
                                <select id="meli-account" name="account_id" defaultValue={selectedMeliAccountId} className="w-full rounded-xl border border-slate-500 bg-[#1f2d44] px-4 py-3 text-white focus:border-cyan-400 focus:outline-none">
                                    <option value="all">Todas las cuentas</option>
                                    {meliAccounts.map((account) => (
                                        <option key={account.id} value={String(account.id)}>{account.name}{account.is_default ? ' · Principal' : ''}</option>
                                    ))}
                                </select>
                            </div>
                            <button type="submit" className="rounded-xl bg-slate-700 px-5 py-3 font-semibold text-white transition hover:bg-slate-600">Aplicar</button>
                        </form>
                    </div>

                    <div className="mt-6 flex flex-wrap gap-2" aria-label="Filtrar por tipo de entrega">
                        {deliveryOptions.map(([value, label]) => (
                            <button key={value} type="button" onClick={() => visit({ delivery_type: value })} className={`rounded-full border px-4 py-2 text-sm font-semibold transition ${deliveryType === value ? 'border-cyan-300 bg-cyan-500 text-slate-950' : 'border-slate-500 bg-slate-800 text-slate-200 hover:border-cyan-400'}`}>
                                {label}
                            </button>
                        ))}
                    </div>

                    <div className="mt-6 grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div className="rounded-xl bg-[#1f2d44] p-4"><div className="text-sm text-slate-300">Pedidos</div><div className="mt-1 text-3xl font-bold text-white">{totalPedidos}</div></div>
                        <div className="rounded-xl bg-[#1f2d44] p-4"><div className="text-sm text-slate-300">Piezas vendidas</div><div className="mt-1 text-3xl font-bold text-white">{totalPiezas}</div></div>
                        <div className="rounded-xl bg-[#1f2d44] p-4"><div className="text-sm text-slate-300">Total vendido</div><div className="mt-1 text-3xl font-bold text-emerald-400">{money(totalVendido)}</div></div>
                    </div>

                    <div className="mt-8 space-y-6">
                        {pedidos.length === 0 ? (
                            <div className="rounded-2xl border border-slate-600 bg-[#1f2d44] px-6 py-14 text-center">
                                <div className="text-2xl font-semibold text-white sm:text-3xl">No hay pedidos para estos filtros</div>
                                <p className="mt-2 text-lg text-slate-300">Cambia la fecha, cuenta o tipo de entrega.</p>
                            </div>
                        ) : pedidos.map((pedido) => (
                            <article key={pedido.group_key} className="overflow-hidden rounded-2xl border border-slate-600 bg-[#1f2d44] shadow-lg">
                                <div className="border-b border-slate-600 px-4 py-4">
                                    <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-semibold text-white">Pedido #{pedido.order_id}</span>
                                            <span className={`rounded-full border px-3 py-1 text-xs font-bold ${deliveryClass(pedido.delivery_type)}`}>{deliveryLabels[pedido.delivery_type] || 'Sin identificar'}</span>
                                            {pedido.needs_shipping_review ? <span className="rounded-full bg-red-500/20 px-3 py-1 text-xs font-semibold text-red-200">Revisar</span> : null}
                                        </div>
                                        <div className="text-sm text-slate-300">{pedido.fecha_pedido_formateada}</div>
                                    </div>
                                    {pedido.delivery_type === 'agreed_with_buyer' ? <p className="mt-3 rounded-xl border border-amber-400/60 bg-amber-500/10 px-4 py-3 font-semibold text-amber-100">Entrega acordada con el comprador. Contacta al comprador para definir la entrega.</p> : null}
                                    <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                        <div><dt className="text-slate-400">Cuenta</dt><dd className="font-semibold text-white">{pedido.meli_account_name}</dd></div>
                                        <div><dt className="text-slate-400">Comprador</dt><dd className="font-semibold text-white">{pedido.buyer_nickname || 'No disponible'}</dd></div>
                                        <div><dt className="text-slate-400">Pack ID</dt><dd className="font-semibold text-white">{pedido.pack_id || 'Sin pack'}</dd></div>
                                        <div><dt className="text-slate-400">Shipping ID</dt><dd className="font-semibold text-white">{pedido.shipping_id || 'Sin shipment'}</dd></div>
                                        <div><dt className="text-slate-400">Pago</dt><dd className="font-semibold uppercase text-white">{pedido.order_status || 'Sin estado'}</dd></div>
                                        <div><dt className="text-slate-400">Tipo de entrega</dt><dd className="font-semibold text-white">{deliveryLabels[pedido.delivery_type] || pedido.delivery_type}</dd></div>
                                    </dl>
                                </div>

                                <div className="divide-y divide-slate-600">
                                    {pedido.items.map((item) => (
                                        <div key={`${pedido.group_key}-${item.item_id}-${item.sku}`} className="p-4">
                                            <div className="grid grid-cols-1 gap-4 md:grid-cols-[84px_minmax(0,1fr)]">
                                                <div className="flex items-start justify-center">
                                                    {item.imagen ? <img src={item.imagen} alt={item.titulo} className="h-20 w-20 rounded-xl bg-white object-contain p-1" /> : <div className="flex h-20 w-20 items-center justify-center rounded-xl bg-slate-700 text-xs text-slate-300">Sin imagen</div>}
                                                </div>
                                                <div>
                                                    <h3 className="text-xl font-semibold leading-tight text-white sm:text-2xl">{item.titulo}</h3>
                                                    <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                                                        <div className="rounded-xl border border-slate-400 px-4 py-3"><div className="text-xs uppercase tracking-wide text-slate-300">Item ID</div><div className="mt-1 break-all text-lg text-white">{item.item_id || 'N/A'}</div></div>
                                                        <div className="rounded-xl border border-slate-400 px-4 py-3"><div className="text-xs uppercase tracking-wide text-slate-300">SKU</div><div className="mt-1 break-all text-lg text-white">{item.sku || 'Sin SKU'}</div></div>
                                                        <div className="rounded-xl border border-slate-400 px-4 py-3"><div className="text-xs uppercase tracking-wide text-slate-300">Cantidad</div><div className="mt-1 text-lg text-white">{item.cantidad}</div></div>
                                                        <div className="rounded-xl border border-slate-400 px-4 py-3"><div className="text-xs uppercase tracking-wide text-slate-300">Precio unitario</div><div className="mt-1 text-lg text-white">{money(item.precio_unitario)}</div></div>
                                                        <div className="rounded-xl border border-slate-400 px-4 py-3"><div className="text-xs uppercase tracking-wide text-slate-300">Total</div><div className="mt-1 text-lg font-semibold text-white">{money(item.total_linea)}</div></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                <div className="border-t border-slate-600 bg-[#16253a] px-4 py-3">
                                    <div className="flex flex-col gap-2 text-sm md:flex-row md:items-center md:justify-between">
                                        <div className="text-slate-300">Total de piezas: <span className="font-semibold text-white">{pedido.total_piezas}</span></div>
                                        <div className="text-slate-300">Total del pedido: <span className="font-semibold text-emerald-400">{money(pedido.total_pedido)}</span></div>
                                    </div>
                                </div>
                            </article>
                        ))}
                    </div>
                </div>
            </section>
        </AppShell>
    )
}
