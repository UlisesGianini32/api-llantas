import { Link, router } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import AppShell from '@/Components/layout/AppShell'
import PageSection from '@/Components/ui/PageSection'
import Pagination from '@/Components/ui/Pagination'

function StatCard({ title, value, subtitle, barPercent, barLabel, colorClass }) {
    const pct = Math.min(100, Math.max(0, Number(barPercent) || 0))

    return (
        <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-800 dark:bg-neutral-900">
            <p className="text-sm font-medium text-slate-500 dark:text-slate-400">{title}</p>
            <p className="mt-3 text-3xl font-bold text-slate-900 dark:text-white">{value}</p>
            <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">{subtitle}</p>
            <div className="mt-4 h-1.5 rounded-full bg-slate-100 dark:bg-neutral-800">
                <div
                    className={`h-1.5 rounded-full transition-all ${colorClass}`}
                    style={{ width: `${pct}%` }}
                />
            </div>
            {barLabel && (
                <p className="mt-2 text-xs text-slate-500 dark:text-slate-500">{barLabel}</p>
            )}
        </div>
    )
}

function stockBadgeClass(stock) {
    if (stock <= 0) return 'bg-red-100 text-red-700 ring-red-200 dark:bg-red-950/40 dark:text-red-400 dark:ring-red-900'
    if (stock <= 2) return 'bg-orange-100 text-orange-700 ring-orange-200 dark:bg-orange-950/40 dark:text-orange-400 dark:ring-orange-900'
    return 'bg-yellow-100 text-yellow-700 ring-yellow-200 dark:bg-yellow-950/40 dark:text-yellow-400 dark:ring-yellow-900'
}

function ZeroStockModal({ open, onClose, onConfirm, busy }) {
    useEffect(() => {
        if (!open) return
        const onKey = (e) => {
            if (e.key === 'Escape') onClose()
        }
        window.addEventListener('keydown', onKey)
        return () => window.removeEventListener('keydown', onKey)
    }, [open, onClose])

    if (!open) return null

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <button
                type="button"
                className="absolute inset-0 bg-black/50 backdrop-blur-[1px]"
                aria-label="Cerrar"
                onClick={onClose}
            />
            <div className="relative z-10 w-full max-w-md rounded-3xl border border-slate-200 bg-white p-6 shadow-xl dark:border-neutral-700 dark:bg-neutral-900">
                <p className="text-xs font-semibold uppercase tracking-wide text-red-600 dark:text-red-400">Acción irreversible</p>
                <h4 className="mt-2 text-xl font-bold text-slate-900 dark:text-white">¿Poner todo el stock en cero?</h4>
                <p className="mt-3 text-sm leading-relaxed text-slate-600 dark:text-slate-400">
                    Se pondrá el stock en <strong>0</strong> para todas las llantas y todos los productos compuestos. Esta
                    operación no se puede deshacer desde el panel.
                </p>
                <div className="mt-6 flex flex-col gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={busy}
                        className="rounded-2xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200 dark:hover:bg-neutral-800"
                    >
                        Cancelar
                    </button>
                    <button
                        type="button"
                        onClick={onConfirm}
                        disabled={busy}
                        className="rounded-2xl bg-red-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-red-700 disabled:opacity-50"
                    >
                        {busy ? 'Procesando…' : 'Sí, poner stock en 0'}
                    </button>
                </div>
            </div>
        </div>
    )
}

export default function DashboardIndex(props) {
    const filters = props.filters || {}
    const stockBajo = props.stockBajo || { data: [] }
    const [zeroModalOpen, setZeroModalOpen] = useState(false)
    const [zeroBusy, setZeroBusy] = useState(false)

    const totalLlantas = Number(props.totalLlantas || 0)
    const totalCompuestos = Number(props.totalCompuestos || 0)
    const llantasSinStock = Number(props.llantasSinStock || 0)
    const compuestosSinStock = Number(props.compuestosSinStock ?? 0)
    const llantasConStockSaludable = Number(props.llantasConStockSaludable ?? 0)
    const existenciasLlantas = Number(props.existenciasLlantas || 0)
    const syscomSyncOkToday = Number(props.syscomSyncOkToday || 0)
    const syscomSyncSkipToday = Number(props.syscomSyncSkipToday || 0)
    const syscomSyncErrToday = Number(props.syscomSyncErrToday || 0)
    const syscomPedidosRecientes = Array.isArray(props.syscomPedidosRecientes) ? props.syscomPedidosRecientes : []

    const pct = (num, den) => (den > 0 ? (num / den) * 100 : 0)

    const copyText = async (text) => {
        const t = String(text || '')
        if (!t) return
        try {
            await navigator.clipboard.writeText(t)
        } catch {
            window.prompt('Copiar:', t)
        }
    }

    const submitSearch = (e) => {
        e.preventDefault()
        const form = new FormData(e.currentTarget)
        const search = form.get('search') || ''

        router.get('/dashboard', {
            search,
            sort: filters.sort || 'stock',
            dir: filters.dir || 'asc',
        }, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    const sortLink = (column) => {
        const currentSort = filters.sort || 'stock'
        const currentDir = filters.dir || 'asc'
        const nextDir = currentSort === column && currentDir === 'asc' ? 'desc' : 'asc'

        return `/dashboard?search=${encodeURIComponent(filters.search || '')}&sort=${column}&dir=${nextDir}`
    }

    const SortTh = ({ column, label, align = 'left', sticky = false }) => {
        const active = (filters.sort || 'stock') === column
        const dir = filters.dir || 'asc'
        const alignClass = align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left'
        const linkJustify =
            align === 'right' ? 'justify-end' : align === 'center' ? 'justify-center' : 'justify-start'
        const stickyClass = sticky
            ? 'sticky left-0 z-20 bg-slate-50 shadow-[2px_0_8px_-4px_rgba(0,0,0,0.08)] dark:bg-neutral-800/90 dark:shadow-[2px_0_8px_-4px_rgba(0,0,0,0.4)]'
            : ''

        return (
            <th className={`px-4 py-4 ${alignClass} ${stickyClass}`}>
                <Link
                    href={sortLink(column)}
                    className={`inline-flex w-full items-center gap-1.5 font-semibold text-slate-700 transition hover:text-indigo-600 dark:text-slate-200 dark:hover:text-indigo-400 ${linkJustify}`}
                >
                    <span>{label}</span>
                    <span className="text-[10px] text-slate-400 dark:text-slate-500" aria-hidden>
                        {active ? (dir === 'asc' ? '▲' : '▼') : '↕'}
                    </span>
                </Link>
            </th>
        )
    }

    const confirmZeroStock = () => {
        setZeroBusy(true)
        router.post(
            '/dashboard/stock/zero',
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setZeroBusy(false)
                    setZeroModalOpen(false)
                },
            },
        )
    }

    const searchActive = Boolean((filters.search || '').trim())
    const stockRows = stockBajo.data || []
    const stockTotal = Number(stockBajo.total ?? 0)
    const emptyMessage =
        stockTotal === 0
            ? searchActive
                ? 'No hay coincidencias con la búsqueda en stock crítico.'
                : 'No hay productos en stock crítico (todos tienen más de 4 unidades).'
            : 'No se encontraron resultados'

    return (
        <AppShell title="Dashboard">
            <div className="space-y-10">
                <div>
                    <p className="text-sm font-semibold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-400">
                        Panel principal
                    </p>
                    <h2 className="mt-2 text-3xl font-bold tracking-tight text-slate-900 dark:text-white">
                        Dashboard de inventario
                    </h2>
                    <p className="mt-2 max-w-2xl text-sm text-slate-600 dark:text-slate-400">
                        Control general de llantas, productos compuestos, stock crítico y procesos operativos.
                    </p>
                </div>

                <section className="space-y-4">
                    <PageSection
                        eyebrow="Inventario"
                        title="Resumen de catálogo y existencias"
                        description="Indicadores calculados en tiempo real sobre tu base de datos."
                    />
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <StatCard
                            title="Llantas individuales"
                            value={totalLlantas.toLocaleString()}
                            subtitle="Referencias en catálogo"
                            barPercent={pct(totalLlantas - llantasSinStock, totalLlantas)}
                            barLabel={`${pct(totalLlantas - llantasSinStock, totalLlantas).toFixed(1)}% del catálogo con al menos 1 pieza`}
                            colorClass="bg-blue-500"
                        />
                        <StatCard
                            title="Tipos de combos"
                            value={totalCompuestos.toLocaleString()}
                            subtitle="Productos compuestos creados"
                            barPercent={pct(totalCompuestos - compuestosSinStock, totalCompuestos)}
                            barLabel={`${pct(totalCompuestos - compuestosSinStock, totalCompuestos).toFixed(1)}% combos con existencia`}
                            colorClass="bg-violet-500"
                        />
                        <StatCard
                            title="Stock llantas"
                            value={existenciasLlantas.toLocaleString()}
                            subtitle={
                                totalLlantas > 0
                                    ? `Promedio ${(existenciasLlantas / totalLlantas).toFixed(1)} piezas por referencia`
                                    : 'Piezas disponibles actualmente'
                            }
                            barPercent={pct(llantasConStockSaludable, totalLlantas)}
                            barLabel={`${pct(llantasConStockSaludable, totalLlantas).toFixed(1)}% SKUs con más de 4 piezas`}
                            colorClass="bg-emerald-500"
                        />
                        <StatCard
                            title="Llantas agotadas"
                            value={llantasSinStock.toLocaleString()}
                            subtitle="Referencias en cero"
                            barPercent={pct(llantasSinStock, totalLlantas)}
                            barLabel={`${pct(llantasSinStock, totalLlantas).toFixed(1)}% del catálogo sin stock`}
                            colorClass="bg-rose-500"
                        />
                    </div>
                </section>

                <section className="space-y-4">
                    <PageSection
                        eyebrow="Valor"
                        title="Valorización a costo"
                        description="Costo × stock acumulado (llantas y combos)."
                    />
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <p className="text-sm font-semibold text-slate-900 dark:text-white">Valor inventario llantas</p>
                            <p className="mt-3 text-3xl font-bold text-emerald-600 dark:text-emerald-400">
                                ${Number(props.valorInventarioLlantas || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                            </p>
                            <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                                Valor total actual del inventario individual.
                            </p>
                        </div>

                        <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <p className="text-sm font-semibold text-slate-900 dark:text-white">Valor teórico combos</p>
                            <p className="mt-3 text-3xl font-bold text-indigo-600 dark:text-indigo-400">
                                ${Number(props.valorInventarioCompuestos || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                            </p>
                            <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                                Valor estimado de productos compuestos.
                            </p>
                        </div>
                    </div>
                </section>

                <section className="space-y-4">
                    <PageSection
                        eyebrow="ML → SYSCOM"
                        title="Estado de sincronización de hoy"
                        description="Resultado del proceso automático que convierte ventas de Mercado Libre en pedidos SYSCOM."
                    />
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <StatCard
                            title="Pedidos SYSCOM creados"
                            value={syscomSyncOkToday.toLocaleString()}
                            subtitle="Órdenes ML convertidas correctamente"
                            barPercent={100}
                            barLabel="Hoy"
                            colorClass="bg-emerald-500"
                        />
                        <StatCard
                            title="SKIP (no SYSCOM)"
                            value={syscomSyncSkipToday.toLocaleString()}
                            subtitle="Órdenes ML que no corresponden a publicaciones SYSCOM"
                            barPercent={100}
                            barLabel="Hoy"
                            colorClass="bg-slate-400"
                        />
                        <StatCard
                            title="Errores SYSCOM"
                            value={syscomSyncErrToday.toLocaleString()}
                            subtitle="Fallos reales al crear pedido en SYSCOM"
                            barPercent={syscomSyncErrToday > 0 ? 100 : 0}
                            barLabel="Hoy"
                            colorClass="bg-rose-500"
                        />
                    </div>

                    <div className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="border-b border-slate-200 px-6 py-4 dark:border-neutral-800">
                            <h3 className="text-lg font-bold text-slate-900 dark:text-white">Folios SYSCOM recientes</h3>
                            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                Pedidos generados en SYSCOM (sucursal). Últimos 40 por fecha de sincronización.
                            </p>
                        </div>
                        {syscomPedidosRecientes.length === 0 ? (
                            <div className="px-6 py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                                Aún no hay folios registrados.
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-left text-sm">
                                    <thead className="bg-slate-50 text-xs font-semibold uppercase tracking-wide text-slate-600 dark:bg-neutral-800/80 dark:text-slate-400">
                                        <tr>
                                            <th className="px-4 py-3">Orden ML</th>
                                            <th className="px-4 py-3">Ref. orden compra</th>
                                            <th className="px-4 py-3">Folio SYSCOM</th>
                                            <th className="px-4 py-3">Sincronizado</th>
                                            <th className="px-4 py-3 text-right">Copiar</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                        {syscomPedidosRecientes.map((row) => {
                                            const mlCancelled = Boolean(row.ml_cancelled)
                                            const syscomCancelled = Boolean(row.syscom_cancelled)
                                            const strike = mlCancelled
                                                ? 'line-through text-slate-400 dark:text-slate-500'
                                                : ''
                                            const folioClass = syscomCancelled
                                                ? 'text-slate-400 line-through dark:text-slate-500'
                                                : mlCancelled
                                                  ? 'text-amber-700 dark:text-amber-400'
                                                  : 'text-emerald-700 dark:text-emerald-400'

                                            return (
                                            <tr key={row.order_id} className="text-slate-800 dark:text-slate-200">
                                                <td className={`whitespace-nowrap px-4 py-3 font-mono text-xs ${strike}`}>{row.order_id}</td>
                                                <td className={`whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-400 ${strike}`}>
                                                    {row.referencia_ml}
                                                </td>
                                                <td className={`px-4 py-3 font-mono text-xs font-semibold ${folioClass}`}>
                                                    {row.syscom_order_folio}
                                                    {mlCancelled && !syscomCancelled && (
                                                        <span className="ml-2 block text-[10px] font-normal normal-case text-amber-700 dark:text-amber-400">
                                                            ML cancelada — pendiente SYSCOM
                                                        </span>
                                                    )}
                                                    {syscomCancelled && (
                                                        <span className="ml-2 block text-[10px] font-normal normal-case text-slate-500">
                                                            Cancelado SYSCOM {row.syscom_order_cancelled_at || ''}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-3 text-slate-600 dark:text-slate-400">
                                                    {row.syscom_order_synced_at || '—'}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <button
                                                        type="button"
                                                        onClick={() => copyText(row.syscom_order_folio)}
                                                        className="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-slate-200 dark:hover:bg-neutral-700"
                                                    >
                                                        Folio
                                                    </button>
                                                </td>
                                            </tr>
                                            )
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                </section>

                <section className="space-y-4">
                    <PageSection
                        eyebrow="Stock crítico"
                        title="Buscar y revisar referencias con poco stock"
                        description="Listado de llantas con stock ≤ 4. Ordena columnas y usa la búsqueda para filtrar."
                    />

                    <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <form onSubmit={submitSearch}>
                            <label htmlFor="search" className="mb-3 block text-sm font-semibold text-slate-900 dark:text-white">
                                Buscar en esta lista
                            </label>

                            <div className="flex flex-col gap-3 md:flex-row">
                                <input
                                    id="search"
                                    name="search"
                                    defaultValue={filters.search || ''}
                                    placeholder="SKU, título o MLM…"
                                    className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-900 outline-none ring-0 transition focus:border-indigo-500 focus:bg-white dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />

                                <button
                                    type="submit"
                                    className="rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700"
                                >
                                    Buscar
                                </button>

                                <Link
                                    href="/dashboard"
                                    className="rounded-2xl border border-slate-200 bg-white px-5 py-3 text-center text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200 dark:hover:bg-neutral-800"
                                >
                                    Limpiar
                                </Link>
                            </div>
                        </form>
                    </div>

                    <div className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="flex flex-col gap-3 border-b border-slate-200 px-6 py-5 dark:border-neutral-800 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 className="text-xl font-bold text-slate-900 dark:text-white">Tabla de stock crítico</h2>
                                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                    Productos con stock menor o igual a 4.
                                </p>
                            </div>

                            <div className="flex flex-wrap items-center gap-3">
                                {stockBajo.from != null && stockBajo.to != null && stockTotal > 0 && (
                                    <span className="text-sm text-slate-600 dark:text-slate-400">
                                        Mostrando{' '}
                                        <span className="font-semibold text-slate-900 dark:text-white">
                                            {stockBajo.from}–{stockBajo.to}
                                        </span>{' '}
                                        de {stockTotal.toLocaleString()}
                                    </span>
                                )}
                                <div className="inline-flex items-center rounded-full bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-600 dark:bg-rose-500/10 dark:text-rose-400">
                                    ≤ 4 unidades
                                </div>
                            </div>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead className="bg-slate-50 dark:bg-neutral-800/70">
                                    <tr className="text-xs uppercase tracking-wide text-slate-600 dark:text-slate-300">
                                        <SortTh column="sku" label="SKU" sticky />
                                        <SortTh column="marca" label="Marca" />
                                        <SortTh column="medida" label="Medida" />
                                        <SortTh column="descripcion" label="Descripción" />
                                        <SortTh column="costo" label="Costo" align="right" />
                                        <SortTh column="precio_ML" label="Precio ML" align="right" />
                                        <SortTh column="title_familyname" label="Título" />
                                        <SortTh column="MLM" label="MLM" />
                                        <SortTh column="stock" label="Stock" align="center" />
                                    </tr>
                                </thead>

                                <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                    {stockRows.length === 0 && (
                                        <tr>
                                            <td colSpan="9" className="px-4 py-10 text-center text-slate-500 dark:text-slate-400">
                                                {emptyMessage}
                                            </td>
                                        </tr>
                                    )}

                                    {stockRows.map((item, index) => (
                                        <tr key={`${item.sku}-${index}`} className="group hover:bg-slate-50 dark:hover:bg-neutral-800/60">
                                            <td className="sticky left-0 z-10 bg-white px-4 py-4 shadow-[2px_0_8px_-4px_rgba(0,0,0,0.08)] group-hover:bg-slate-50 dark:bg-neutral-900 dark:shadow-[2px_0_8px_-4px_rgba(0,0,0,0.4)] dark:group-hover:bg-neutral-800/60">
                                                <Link
                                                    href={`/llantas/${item.id}/editar`}
                                                    className="font-mono text-sm font-semibold text-blue-600 hover:underline dark:text-blue-400"
                                                >
                                                    {item.sku}
                                                </Link>
                                            </td>
                                            <td className="px-4 py-4 text-slate-800 dark:text-slate-200">{item.marca ?? 'SIN MARCA'}</td>
                                            <td className="px-4 py-4 text-slate-700 dark:text-slate-300">{item.medida ?? 'N/A'}</td>
                                            <td className="px-4 py-4 text-slate-600 dark:text-slate-400">
                                                <div className="max-w-xs truncate md:max-w-sm lg:max-w-md" title={item.descripcion ?? ''}>
                                                    {item.descripcion ?? '—'}
                                                </div>
                                            </td>
                                            <td className="px-4 py-4 text-right text-slate-700 dark:text-slate-300">
                                                ${Number(item.costo || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                            </td>
                                            <td className="px-4 py-4 text-right font-bold text-emerald-600 dark:text-emerald-400">
                                                ${Number(item.precio_ML || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                            </td>
                                            <td className="px-4 py-4 text-slate-700 dark:text-slate-300">
                                                <div className="max-w-xs truncate" title={item.title_familyname ?? ''}>
                                                    {item.title_familyname}
                                                </div>
                                            </td>
                                            <td className="px-4 py-4 text-slate-500 dark:text-slate-400">{item.MLM ?? '—'}</td>
                                            <td className="px-4 py-4 text-center">
                                                <span className={`inline-flex min-w-[42px] items-center justify-center rounded-full px-3 py-1 text-xs font-bold ring-1 ${stockBadgeClass(Number(item.stock || 0))}`}>
                                                    {item.stock}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <div className="border-t border-slate-200 px-6 py-4 dark:border-neutral-800">
                            <Pagination links={stockBajo.links || []} />
                        </div>
                    </div>
                </section>

                <section className="space-y-4">
                    <PageSection
                        eyebrow="Operaciones"
                        title="Acciones rápidas"
                        description="Accesos directos y procesos importantes del sistema."
                    />

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
                        <Link href="/llantas" className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-800 dark:bg-neutral-900">
                            <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Inventario</p>
                            <p className="mt-2 text-base font-bold text-slate-900 dark:text-white">Ver llantas</p>
                            <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Consulta el inventario individual.</p>
                        </Link>

                        <Link href="/productos" className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-800 dark:bg-neutral-900">
                            <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Combos</p>
                            <p className="mt-2 text-base font-bold text-slate-900 dark:text-white">Productos compuestos</p>
                            <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Administra juegos, pares y combos.</p>
                        </Link>

                        <Link href="/excel/vista" className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-800 dark:bg-neutral-900">
                            <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Inventario</p>
                            <p className="mt-2 text-base font-bold text-emerald-600 dark:text-emerald-400">Importar Excel</p>
                            <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Sube y procesa tu archivo de inventario.</p>
                        </Link>

                        <button
                            type="button"
                            onClick={() => setZeroModalOpen(true)}
                            className="w-full rounded-3xl border border-red-200 bg-white p-5 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-red-900 dark:bg-neutral-900"
                        >
                            <p className="text-xs font-medium uppercase tracking-wide text-red-500 dark:text-red-400">Acción peligrosa</p>
                            <p className="mt-2 text-base font-bold text-slate-900 dark:text-white">Poner stock en 0</p>
                            <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Reinicia todo el stock del sistema.</p>
                        </button>

                        <Link href="/dashboard/meli/refresh-token" method="post" as="button" className="w-full rounded-3xl border border-slate-200 bg-white p-5 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-800 dark:bg-neutral-900">
                            <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Mercado Libre</p>
                            <p className="mt-2 text-base font-bold text-slate-900 dark:text-white">Refrescar token ML</p>
                            <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Actualiza el acceso para sincronización.</p>
                        </Link>
                    </div>
                </section>
            </div>

            <ZeroStockModal
                open={zeroModalOpen}
                onClose={() => !zeroBusy && setZeroModalOpen(false)}
                onConfirm={confirmZeroStock}
                busy={zeroBusy}
            />
        </AppShell>
    )
}
