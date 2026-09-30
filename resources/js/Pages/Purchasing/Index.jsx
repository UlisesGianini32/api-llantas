import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

function formatCurrency(val) {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    }).format(val || 0)
}

function formatDate(dateStr) {
    if (!dateStr) return '-'
    const d = new Date(dateStr)
    return d.toLocaleDateString('es-MX', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    })
}

const STATUS_BADGES = {
    DRAFT: {
        label: 'Borrador',
        bg: 'bg-slate-100 text-slate-700 border-slate-300 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700',
    },
    ORDERED: {
        label: 'Colocada (En camino)',
        bg: 'bg-blue-100 text-blue-800 border-blue-300 dark:bg-blue-900/40 dark:text-blue-300 dark:border-blue-700',
    },
    PARTIAL: {
        label: 'Recepción Parcial',
        bg: 'bg-amber-100 text-amber-800 border-amber-300 dark:bg-amber-900/40 dark:text-amber-300 dark:border-amber-700',
    },
    RECEIVED: {
        label: 'Recibida Completa',
        bg: 'bg-emerald-100 text-emerald-800 border-emerald-300 dark:bg-emerald-900/40 dark:text-emerald-300 dark:border-emerald-700',
    },
    CANCELLED: {
        label: 'Cancelada',
        bg: 'bg-rose-100 text-rose-800 border-rose-300 dark:bg-rose-900/40 dark:text-rose-300 dark:border-rose-700',
    },
}

export default function PurchasingIndex({
    orders = { data: [], links: [] },
    summary = {},
    suppliers = [],
    brands = [],
    filters = {},
}) {
    const [search, setSearch] = useState(filters.search || '')
    const [selectedStatus, setSelectedStatus] = useState(filters.status || '')
    const [selectedSupplier, setSelectedSupplier] = useState(filters.supplier || '')
    const [selectedBrand, setSelectedBrand] = useState(filters.brand || '')

    const applyFilter = (overrides = {}) => {
        const query = {
            search,
            status: selectedStatus,
            supplier: selectedSupplier,
            brand: selectedBrand,
            ...overrides,
        }

        Object.keys(query).forEach((k) => {
            if (!query[k]) delete query[k]
        })

        router.get('/compras/ordenes', query, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    const handleSearchSubmit = (e) => {
        e.preventDefault()
        applyFilter({ search })
    }

    const clearFilters = () => {
        setSearch('')
        setSelectedStatus('')
        setSelectedSupplier('')
        setSelectedBrand('')
        router.get('/compras/ordenes')
    }

    return (
        <AppShell title="Órdenes de Compra">
            <Head title="Órdenes de Compra - Almacén" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                            Órdenes de Compra (PO)
                        </h1>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Gestión de pedidos a fabricantes/proveedores, control de importaciones y recepción física en almacén.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        <Link
                            href="/reabastecimiento/pronostico"
                            className="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200 dark:hover:bg-neutral-700"
                        >
                            <svg className="h-4 w-4 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                            </svg>
                            Ver Pronóstico Sugerido
                        </Link>

                        <Link
                            href="/compras/ordenes/crear"
                            className="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 dark:bg-indigo-500 dark:hover:bg-indigo-600"
                        >
                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                            </svg>
                            Nueva Orden de Compra
                        </Link>
                    </div>
                </div>

                {/* KPI Summary Cards */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <p className="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Total Órdenes</p>
                        <p className="mt-2 text-2xl font-bold text-slate-900 dark:text-white">{summary.total_orders ?? 0}</p>
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <p className="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Borradores</p>
                        <p className="mt-2 text-2xl font-bold text-slate-600 dark:text-slate-300">{summary.draft_count ?? 0}</p>
                    </div>

                    <div className="rounded-2xl border border-blue-200 bg-blue-50/50 p-4 shadow-sm dark:border-blue-900/50 dark:bg-blue-950/20">
                        <p className="text-xs font-medium uppercase tracking-wider text-blue-600 dark:text-blue-400">Colocadas</p>
                        <p className="mt-2 text-2xl font-bold text-blue-700 dark:text-blue-300">{summary.ordered_count ?? 0}</p>
                    </div>

                    <div className="rounded-2xl border border-amber-200 bg-amber-50/50 p-4 shadow-sm dark:border-amber-900/50 dark:bg-amber-950/20">
                        <p className="text-xs font-medium uppercase tracking-wider text-amber-600 dark:text-amber-400">Parciales</p>
                        <p className="mt-2 text-2xl font-bold text-amber-700 dark:text-amber-300">{summary.partial_count ?? 0}</p>
                    </div>

                    <div className="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-sm dark:border-emerald-900/50 dark:bg-emerald-950/20">
                        <p className="text-xs font-medium uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Recibidas</p>
                        <p className="mt-2 text-2xl font-bold text-emerald-700 dark:text-emerald-300">{summary.received_count ?? 0}</p>
                    </div>

                    <div className="rounded-2xl border border-indigo-200 bg-indigo-50/50 p-4 shadow-sm dark:border-indigo-900/50 dark:bg-indigo-950/20">
                        <p className="text-xs font-medium uppercase tracking-wider text-indigo-600 dark:text-indigo-400">Inversión Activa</p>
                        <p className="mt-2 text-lg font-bold text-indigo-700 dark:text-indigo-300 truncate" title={formatCurrency(summary.total_invested)}>
                            {formatCurrency(summary.total_invested)}
                        </p>
                    </div>
                </div>

                {/* Filters */}
                <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <form onSubmit={handleSearchSubmit} className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div className="flex flex-1 flex-wrap items-center gap-3">
                            <div className="relative min-w-[220px] flex-1">
                                <input
                                    type="text"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Buscar por PO#, proveedor o cotización..."
                                    className="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2 pl-9 text-sm text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                />
                                <svg
                                    className="absolute left-3 top-2.5 h-4 w-4 text-slate-400"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </div>

                            <select
                                value={selectedStatus}
                                onChange={(e) => {
                                    setSelectedStatus(e.target.value)
                                    applyFilter({ status: e.target.value })
                                }}
                                className="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                            >
                                <option value="">Todos los Estados</option>
                                <option value="DRAFT">Borradores</option>
                                <option value="ORDERED">Colocadas (En tránsito)</option>
                                <option value="PARTIAL">Recepción Parcial</option>
                                <option value="RECEIVED">Recibidas Completo</option>
                                <option value="CANCELLED">Canceladas</option>
                            </select>

                            {suppliers.length > 0 && (
                                <select
                                    value={selectedSupplier}
                                    onChange={(e) => {
                                        setSelectedSupplier(e.target.value)
                                        applyFilter({ supplier: e.target.value })
                                    }}
                                    className="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                >
                                    <option value="">Todos los Proveedores</option>
                                    {suppliers.map((s) => (
                                        <option key={s} value={s}>{s}</option>
                                    ))}
                                </select>
                            )}

                            {brands.length > 0 && (
                                <select
                                    value={selectedBrand}
                                    onChange={(e) => {
                                        setSelectedBrand(e.target.value)
                                        applyFilter({ brand: e.target.value })
                                    }}
                                    className="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                >
                                    <option value="">Todas las Marcas</option>
                                    {brands.map((b) => (
                                        <option key={b} value={b}>{b}</option>
                                    ))}
                                </select>
                            )}
                        </div>

                        <div className="flex items-center gap-2">
                            <button
                                type="submit"
                                className="rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 dark:bg-neutral-800 dark:hover:bg-neutral-700"
                            >
                                Buscar
                            </button>
                            {(search || selectedStatus || selectedSupplier || selectedBrand) && (
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    className="rounded-xl border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800"
                                >
                                    Limpiar
                                </button>
                            )}
                        </div>
                    </form>
                </div>

                {/* Orders Table */}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50/75 text-xs font-semibold uppercase tracking-wider text-slate-600 dark:border-neutral-800 dark:bg-neutral-950 dark:text-slate-400">
                                <tr>
                                    <th className="px-4 py-3.5">Orden #</th>
                                    <th className="px-4 py-3.5">Estado</th>
                                    <th className="px-4 py-3.5">Proveedor / Marca</th>
                                    <th className="px-4 py-3.5">Almacén Destino</th>
                                    <th className="px-4 py-3.5">Progreso Unidades</th>
                                    <th className="px-4 py-3.5 text-right">Inversión Total</th>
                                    <th className="px-4 py-3.5">Fecha Esperada</th>
                                    <th className="px-4 py-3.5 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                {orders.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={8} className="px-4 py-12 text-center text-slate-500 dark:text-slate-400">
                                            No se encontraron órdenes de compra con los filtros seleccionados.
                                        </td>
                                    </tr>
                                ) : (
                                    orders.data.map((order) => {
                                        const badge = STATUS_BADGES[order.status] || STATUS_BADGES.DRAFT
                                        const percentReceived = order.total_units_ordered > 0
                                            ? Math.min(100, Math.round((order.total_units_received / order.total_units_ordered) * 100))
                                            : 0

                                        return (
                                            <tr key={order.id} className="hover:bg-slate-50/60 dark:hover:bg-neutral-800/50 transition">
                                                <td className="px-4 py-3.5">
                                                    <Link
                                                        href={`/compras/ordenes/${order.id}`}
                                                        className="font-bold text-indigo-600 hover:underline dark:text-indigo-400"
                                                    >
                                                        {order.order_number}
                                                    </Link>
                                                    {order.supplier_quote_reference && (
                                                        <p className="text-xs text-slate-400 dark:text-slate-500">
                                                            Ref: {order.supplier_quote_reference}
                                                        </p>
                                                    )}
                                                </td>

                                                <td className="px-4 py-3.5">
                                                    <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold ${badge.bg}`}>
                                                        {badge.label}
                                                    </span>
                                                </td>

                                                <td className="px-4 py-3.5">
                                                    <p className="font-medium text-slate-900 dark:text-white">
                                                        {order.supplier_name}
                                                    </p>
                                                    {order.brand && (
                                                        <span className="inline-block rounded bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-600 dark:bg-neutral-800 dark:text-slate-300">
                                                            {order.brand}
                                                        </span>
                                                    )}
                                                </td>

                                                <td className="px-4 py-3.5 text-slate-700 dark:text-slate-300">
                                                    {order.location ? (
                                                        <span>{order.location.name} ({order.location.code})</span>
                                                    ) : (
                                                        <span className="text-slate-400">Sin asignar</span>
                                                    )}
                                                </td>

                                                <td className="px-4 py-3.5">
                                                    <div className="w-36">
                                                        <div className="flex items-center justify-between text-xs text-slate-600 dark:text-slate-300 mb-1">
                                                            <span>{order.total_units_received} / {order.total_units_ordered}</span>
                                                            <span className="font-semibold">{percentReceived}%</span>
                                                        </div>
                                                        <div className="h-2 w-full overflow-hidden rounded-full bg-slate-200 dark:bg-neutral-700">
                                                            <div
                                                                className={`h-full transition-all ${
                                                                    percentReceived === 100
                                                                        ? 'bg-emerald-500'
                                                                        : percentReceived > 0
                                                                        ? 'bg-amber-500'
                                                                        : 'bg-slate-400'
                                                                }`}
                                                                style={{ width: `${percentReceived}%` }}
                                                            />
                                                        </div>
                                                    </div>
                                                </td>

                                                <td className="px-4 py-3.5 text-right font-medium text-slate-900 dark:text-white">
                                                    {formatCurrency(order.total_cost)}
                                                </td>

                                                <td className="px-4 py-3.5 text-xs text-slate-600 dark:text-slate-400">
                                                    {order.expected_delivery_date ? (
                                                        <span>{formatDate(order.expected_delivery_date)}</span>
                                                    ) : (
                                                        <span className="text-slate-400">-</span>
                                                    )}
                                                </td>

                                                <td className="px-4 py-3.5 text-right">
                                                    <div className="flex items-center justify-end gap-2">
                                                        {(order.status === 'ORDERED' || order.status === 'PARTIAL') && (
                                                            <Link
                                                                href={`/compras/ordenes/${order.id}#recibir`}
                                                                className="rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-950/60"
                                                            >
                                                                Recibir
                                                            </Link>
                                                        )}
                                                        <Link
                                                            href={`/compras/ordenes/${order.id}`}
                                                            className="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800"
                                                        >
                                                            Ver
                                                        </Link>
                                                    </div>
                                                </td>
                                            </tr>
                                        )
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {orders.links && orders.links.length > 3 && (
                        <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 dark:border-neutral-800">
                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                Mostrando <span className="font-medium">{orders.from || 0}</span> a <span className="font-medium">{orders.to || 0}</span> de <span className="font-medium">{orders.total || 0}</span> órdenes
                            </p>
                            <div className="flex gap-1">
                                {orders.links.map((link, idx) => (
                                    <Link
                                        key={idx}
                                        href={link.url || '#'}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                        className={`rounded-lg px-3 py-1.5 text-xs font-medium ${
                                            link.active
                                                ? 'bg-indigo-600 text-white'
                                                : link.url
                                                ? 'border border-slate-300 text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800'
                                                : 'text-slate-400 opacity-50 cursor-not-allowed'
                                        }`}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AppShell>
    )
}
