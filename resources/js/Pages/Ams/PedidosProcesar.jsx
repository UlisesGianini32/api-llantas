import AppShell from '@/Components/layout/AppShell'
import { router } from '@inertiajs/react'

function formatFechaCorta(iso) {
    if (!iso) {
        return ''
    }
    const [y, m, d] = String(iso).split('-')
    if (!y || !m || !d) {
        return iso
    }
    return `${d}/${m}/${y}`
}

function tipoBadgeClass(tipo) {
    switch (tipo) {
        case 'FLEX':
            return 'border-emerald-500/60 bg-emerald-600/25 text-emerald-100'
        case 'COLECTA':
            return 'border-sky-500/60 bg-sky-600/25 text-sky-100'
        case 'FULL':
            return 'border-purple-500/60 bg-purple-600/25 text-purple-100'
        default:
            return 'border-slate-500/60 bg-slate-600/25 text-slate-200'
    }
}

function tipoLabel(tipo) {
    switch (tipo) {
        case 'FLEX':
            return 'Flex · entrega propia'
        case 'COLECTA':
            return 'Colecta · retiro MeLi'
        case 'FULL':
            return 'Full · depósito MeLi'
        default:
            return 'Otro'
    }
}

function envioBadgeClass(status, substatus) {
    if (status === 'shipped' || status === 'in_transit') {
        return 'border-amber-500/60 bg-amber-900/40 text-amber-100'
    }
    if (status === 'delivered' || substatus === 'delivered') {
        return 'border-slate-500/60 bg-slate-800/60 text-slate-300'
    }
    if (status === 'ready_to_ship' && substatus === 'ready_to_print') {
        return 'border-lime-500/60 bg-lime-900/30 text-lime-100'
    }
    if (status === 'ready_to_ship' && substatus === 'printed') {
        return 'border-cyan-500/60 bg-cyan-900/30 text-cyan-100'
    }
    return 'border-slate-500/60 bg-slate-700/40 text-slate-200'
}

function mlVentaUrl(pedido) {
    const term = encodeURIComponent(pedido?.display_id || pedido?.order_id || '')
    return `https://www.mercadolibre.com.mx/ventas?search=${term}`
}

function etiquetaUrl(pedido) {
    const shippingId = String(pedido?.shipping_id || '').trim()
    if (!shippingId) {
        return null
    }
    return `/ams/pedidos/shipping-label/${encodeURIComponent(shippingId)}/print`
}

export default function PedidosProcesar({
    pedidos = [],
    fechaSeleccionada = '',
    totalPedidos = 0,
    totalPiezas = 0,
    tituloPagina = 'AMS - Pedidos por procesar',
    subtitulo = 'Mostrando pedidos que te toca procesar el día:',
    formAction = '/ams/pedidos-procesar',
    orden = 'fecha',
    alcance = 'ml_listado',
}) {
    const queryNav = (patch) => {
        router.get(
            formAction,
            {
                fecha: fechaSeleccionada,
                orden,
                alcance,
                ...patch,
            },
            { preserveState: true, preserveScroll: true }
        )
    }

    const onSubmitFecha = (e) => {
        e.preventDefault()
        const form = new FormData(e.currentTarget)
        const fecha = form.get('fecha') || fechaSeleccionada
        queryNav({ fecha })
    }

    const setOrden = (nuevo) => {
        if (nuevo === orden) {
            return
        }
        queryNav({ orden: nuevo })
    }

    const setAlcance = (nuevo) => {
        if (nuevo === alcance) {
            return
        }
        queryNav({ alcance: nuevo })
    }

    return (
        <AppShell title={tituloPagina}>
            <section className="bg-[#0b1220] min-h-[calc(100vh-4rem)] py-4 sm:py-6">
                <div className="mx-auto max-w-[1400px] px-4 sm:px-6 lg:px-8">
                    <div className="rounded-none border border-slate-700 bg-[#00153b] p-6 shadow-2xl">
                        <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                            <div>
                                <h1 className="text-2xl font-bold text-white sm:text-3xl">{tituloPagina}</h1>
                                <p className="mt-2 text-sm text-slate-300">
                                    {subtitulo}
                                    {alcance === 'colecta' ? (
                                        <> {formatFechaCorta(fechaSeleccionada)}</>
                                    ) : null}
                                </p>
                            </div>

                            <form
                                onSubmit={onSubmitFecha}
                                className="flex flex-col gap-3 sm:flex-row sm:items-end"
                            >
                                <div>
                                    <label htmlFor="fecha" className="mb-1 block text-sm font-medium text-white">
                                        Seleccionar fecha
                                    </label>
                                    <input
                                        type="date"
                                        id="fecha"
                                        name="fecha"
                                        defaultValue={fechaSeleccionada}
                                        disabled={alcance === 'ml_listado'}
                                        className="w-full rounded-lg border border-slate-500 bg-slate-800 px-4 py-2 text-white outline-none focus:border-sky-400 disabled:cursor-not-allowed disabled:opacity-50"
                                    />
                                    {alcance === 'ml_listado' ? (
                                        <p className="mt-1 max-w-[220px] text-xs text-slate-500">
                                            En “Como ML” no se filtra por día.
                                        </p>
                                    ) : null}
                                </div>
                                <div>
                                    <button
                                        type="submit"
                                        className="rounded-lg border border-slate-500 bg-slate-800 px-5 py-2 text-white transition hover:bg-slate-700"
                                    >
                                        Ver pedidos
                                    </button>
                                </div>
                            </form>

                            <div className="flex w-full flex-col gap-2 md:max-w-[280px]">
                                <span className="text-xs font-medium uppercase tracking-wide text-slate-400">
                                    Qué pedidos ver
                                </span>
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setAlcance('colecta')}
                                        className={`rounded-lg border px-3 py-2 text-xs font-semibold transition sm:text-sm ${
                                            alcance === 'colecta'
                                                ? 'border-sky-400 bg-sky-500/20 text-sky-100'
                                                : 'border-slate-500 bg-slate-800 text-slate-200 hover:bg-slate-700'
                                        }`}
                                        title="Solo Flex/Colecta y ventana de fecha (tu lote diario)"
                                    >
                                        Lote colecta / Flex
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setAlcance('ml_listado')}
                                        className={`rounded-lg border px-3 py-2 text-xs font-semibold transition sm:text-sm ${
                                            alcance === 'ml_listado'
                                                ? 'border-sky-400 bg-sky-500/20 text-sky-100'
                                                : 'border-slate-500 bg-slate-800 text-slate-200 hover:bg-slate-700'
                                        }`}
                                        title="Como el listado de ML con filtro Etiquetas listas (más pedidos)"
                                    >
                                        Como ML · etiqueta lista
                                    </button>
                                </div>
                            </div>

                            <div className="flex w-full flex-col gap-2 md:w-auto">
                                <span className="text-xs font-medium uppercase tracking-wide text-slate-400">
                                    Orden de lista
                                </span>
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setOrden('fecha')}
                                        className={`rounded-lg border px-4 py-2 text-sm font-semibold transition ${
                                            orden === 'fecha'
                                                ? 'border-amber-400 bg-amber-500/20 text-amber-100'
                                                : 'border-slate-500 bg-slate-800 text-slate-200 hover:bg-slate-700'
                                        }`}
                                    >
                                        Por fecha
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setOrden('marca')}
                                        className={`rounded-lg border px-4 py-2 text-sm font-semibold transition ${
                                            orden === 'marca'
                                                ? 'border-amber-400 bg-amber-500/20 text-amber-100'
                                                : 'border-slate-500 bg-slate-800 text-slate-200 hover:bg-slate-700'
                                        }`}
                                    >
                                        Por marca (orden fijo)
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div className="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div className="border border-slate-700 bg-slate-800/70 p-4">
                                <div className="text-sm text-slate-300">Pedidos</div>
                                <div className="mt-2 text-3xl font-bold text-white">{totalPedidos}</div>
                            </div>
                            <div className="border border-slate-700 bg-slate-800/70 p-4">
                                <div className="text-sm text-slate-300">Piezas vendidas</div>
                                <div className="mt-2 text-3xl font-bold text-white">{totalPiezas}</div>
                            </div>
                        </div>

                        <div className="mt-6">
                            {pedidos.length === 0 ? (
                                <div className="border border-slate-700 bg-slate-800/60 px-6 py-16 text-center">
                                    <p className="text-3xl font-semibold text-white">
                                        {alcance === 'ml_listado'
                                            ? 'No hay pedidos con etiqueta lista en la base'
                                            : 'No hay pedidos para esta fecha'}
                                    </p>
                                    <p className="mt-3 text-lg text-slate-300">
                                        {alcance === 'ml_listado'
                                            ? 'Sincronizá órdenes desde ML o revisá que no sean Full (esos no entran en AMS).'
                                            : 'Cambia la fecha o probá “Como ML · etiqueta lista” para ver todo lo listo para imprimir.'}
                                    </p>
                                </div>
                            ) : (
                                pedidos.map((pedido) => (
                                    <div
                                        key={pedido.group_key}
                                        className="mb-6 border border-slate-500 bg-[#1b2a41]"
                                    >
                                        <div className="flex flex-col gap-2 border-b border-slate-500 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                            <div className="flex flex-wrap items-center gap-2 text-sm font-semibold text-white">
                                                <span>Pedido #{pedido.display_id}</span>
                                                <span
                                                    className={`rounded-md border px-2 py-0.5 text-xs font-semibold ${tipoBadgeClass(pedido.ams_tipo)}`}
                                                    title="Tipo de logística según datos de la orden y del envío"
                                                >
                                                    {tipoLabel(pedido.ams_tipo)}
                                                </span>
                                                {pedido.ml_envio_label ? (
                                                    <span
                                                        className={`rounded-md border px-2 py-0.5 text-xs font-semibold ${envioBadgeClass(
                                                            pedido.ml_envio_status,
                                                            pedido.ml_envio_substatus
                                                        )}`}
                                                        title={`MeLi envío: ${pedido.ml_envio_status || '—'}${pedido.ml_envio_substatus ? ` · ${pedido.ml_envio_substatus}` : ''}`}
                                                    >
                                                        {pedido.ml_envio_label}
                                                    </span>
                                                ) : null}
                                                {orden === 'marca' && pedido.ams_marca_label ? (
                                                    <span
                                                        className="rounded-md border border-amber-500/50 bg-amber-900/40 px-2 py-0.5 text-xs font-semibold text-amber-100"
                                                        title="Marca según título/SKU (primera coincidencia en la lista fija)"
                                                    >
                                                        {pedido.ams_marca_label}
                                                    </span>
                                                ) : null}
                                            </div>
                                            <div className="flex items-center gap-2">
                                                {etiquetaUrl(pedido) ? (
                                                    <a
                                                        href={etiquetaUrl(pedido)}
                                                        className="rounded-md border border-lime-500/60 bg-lime-700/20 px-3 py-1.5 text-xs font-semibold text-lime-100 transition hover:bg-lime-700/35"
                                                        title="Imprimir: pantalla de preparación y diálogo de impresión"
                                                    >
                                                        Imprimir etiqueta
                                                    </a>
                                                ) : (
                                                    <a
                                                        href={mlVentaUrl(pedido)}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="rounded-md border border-amber-500/60 bg-amber-700/20 px-3 py-1.5 text-xs font-semibold text-amber-100 transition hover:bg-amber-700/35"
                                                        title="Sin shipping_id; abre ventas de ML como respaldo"
                                                    >
                                                        Abrir en Mercado Libre
                                                    </a>
                                                )}
                                                <div className="text-sm text-slate-200">
                                                    {pedido.fecha_pedido_formateada}
                                                </div>
                                            </div>
                                        </div>

                                        <div className="divide-y divide-slate-500">
                                            {pedido.items.map((item, idx) => (
                                                <div key={`${item.sku}-${item.titulo}-${idx}`} className="p-4">
                                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-[110px_minmax(0,1fr)]">
                                                        <div className="flex items-start justify-center md:justify-start">
                                                            {item.imagen ? (
                                                                <img
                                                                    src={item.imagen}
                                                                    alt={item.titulo}
                                                                    className="h-[110px] w-[110px] rounded-xl bg-white object-contain p-1"
                                                                />
                                                            ) : (
                                                                <div className="flex h-[110px] w-[110px] items-center justify-center rounded-xl bg-slate-700 text-xs text-slate-300">
                                                                    Sin imagen
                                                                </div>
                                                            )}
                                                        </div>
                                                        <div className="min-w-0">
                                                            <div className="mb-3 flex flex-wrap items-center gap-2">
                                                                <h3 className="text-lg font-semibold leading-tight text-white">
                                                                    {item.titulo}
                                                                </h3>
                                                                {orden === 'marca' && item.ams_marca_label ? (
                                                                    <span className="rounded border border-slate-500 bg-slate-900/80 px-2 py-0.5 text-xs text-slate-300">
                                                                        {item.ams_marca_label}
                                                                    </span>
                                                                ) : null}
                                                            </div>
                                                            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                                                                <div className="rounded-xl border border-slate-400 bg-[#1b2a41] px-4 py-3">
                                                                    <div className="text-xs uppercase tracking-wide text-slate-300">
                                                                        Piezas
                                                                    </div>
                                                                    <div className="mt-1 text-3xl font-semibold text-white">
                                                                        {item.cantidad}
                                                                    </div>
                                                                </div>
                                                                <div className="rounded-xl border border-slate-400 bg-[#1b2a41] px-4 py-3">
                                                                    <div className="text-xs uppercase tracking-wide text-slate-300">
                                                                        SKU
                                                                    </div>
                                                                    <div className="mt-1 break-all text-3xl font-semibold text-white">
                                                                        {item.sku || 'N/A'}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>

                                        <div className="border-t border-slate-500 px-4 py-3 text-sm text-white">
                                            Total de piezas del pedido:
                                            <span className="font-semibold"> {pedido.total_piezas}</span>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </div>
            </section>
        </AppShell>
    )
}
