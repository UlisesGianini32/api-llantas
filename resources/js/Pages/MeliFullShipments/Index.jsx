import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

function formatDate(dateStr) {
    if (!dateStr) return '-'
    const d = new Date(dateStr)
    return d.toLocaleDateString('es-MX', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}

const STATUS_BADGES = {
    DRAFT: {
        label: 'Borrador / Preparación',
        bg: 'bg-slate-100 text-slate-700 border-slate-300 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700',
    },
    PACKED: {
        label: 'Cajas Selladas',
        bg: 'bg-indigo-100 text-indigo-800 border-indigo-300 dark:bg-indigo-900/40 dark:text-indigo-300 dark:border-indigo-700',
    },
    IN_TRANSIT: {
        label: 'En Tránsito (ENVIA)',
        bg: 'bg-blue-100 text-blue-800 border-blue-300 dark:bg-blue-900/40 dark:text-blue-300 dark:border-blue-700',
    },
    DELIVERED: {
        label: 'Entregado en CEDIS MeLi',
        bg: 'bg-purple-100 text-purple-800 border-purple-300 dark:bg-purple-900/40 dark:text-purple-300 dark:border-purple-700',
    },
    RECEIVED: {
        label: 'Recibido Completo',
        bg: 'bg-emerald-100 text-emerald-800 border-emerald-300 dark:bg-emerald-900/40 dark:text-emerald-300 dark:border-emerald-700',
    },
    DISCREPANCY: {
        label: 'Con Discrepancias / Faltantes',
        bg: 'bg-amber-100 text-amber-800 border-amber-300 dark:bg-amber-900/40 dark:text-amber-300 dark:border-amber-700',
    },
    CANCELLED: {
        label: 'Cancelado',
        bg: 'bg-rose-100 text-rose-800 border-rose-300 dark:bg-rose-900/40 dark:text-rose-300 dark:border-rose-700',
    },
}

export default function MeliFullShipmentsIndex({
    shipments = { data: [], links: [] },
    kpis = {},
    warehouses = {},
    carriers = [],
    filters = {},
}) {
    const [statusFilter, setStatusFilter] = useState(filters.status || 'ALL')
    const [carrierFilter, setCarrierFilter] = useState(filters.carrier || '')
    const [warehouseFilter, setWarehouseFilter] = useState(filters.warehouse || '')
    const [search, setSearch] = useState(filters.search || '')

    const applyFilter = (newFilters) => {
        const query = {
            status: statusFilter,
            carrier: carrierFilter,
            warehouse: warehouseFilter,
            search: search,
            ...newFilters,
        }

        Object.keys(query).forEach((k) => {
            if (!query[k] || query[k] === 'ALL') delete query[k]
        })

        router.get('/meli/full/envios', query, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    const handleSearch = (e) => {
        e.preventDefault()
        applyFilter({ search })
    }

    const handleDispatch = (shipment) => {
        if (!confirm(`¿Confirmas el despacho del envío ${shipment.shipment_code}?\n\nSe registrará automáticamente la SALIDA de ${shipment.total_units} piezas del almacén local.`)) {
            return
        }

        router.post(`/meli/full/envios/${shipment.id}/despachar`, {}, {
            preserveScroll: true,
        })
    }

    return (
        <AppShell>
            <Head title="Envíos a Mercado Libre FULL (Cajas de 30)" />

            <div className="space-y-6 p-4 sm:p-6 lg:p-8">
                {/* HEADER */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="rounded-xl bg-amber-400/20 px-2.5 py-1 text-xs font-black text-amber-800 dark:text-amber-300">
                                ⚡ MERCADO LIBRE FULL
                            </span>
                            <span className="text-xs font-semibold text-slate-400">
                                Logística ENVIA & Almacén Central
                            </span>
                        </div>
                        <h1 className="mt-1 text-2xl font-black text-slate-900 dark:text-white">
                            Gestión de Envíos FULL (Cajas de 30)
                        </h1>
                        <p className="text-xs text-slate-500 dark:text-slate-400">
                            Armado de bultos estándar de 30 piezas, vinculación de guías ENVIA, salida física de almacén y monitoreo de recepción en CEDIS MeLi.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            href="/meli/full"
                            className="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                        >
                            📊 Catálogo FULL
                        </Link>
                        <Link
                            href="/meli/full/envios/empaque"
                            className="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2 text-xs font-black text-white shadow-sm hover:bg-emerald-700"
                        >
                            <span>⚖️</span> Armar Cajas (1 a 1)
                        </Link>
                        <Link
                            href="/meli/full/envios/crear"
                            className="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-indigo-700"
                        >
                            <span>📦</span> Crear Envío Manual
                        </Link>
                    </div>
                </div>

                {/* 4 CORE KPI METRICS REQUESTED BY USER */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {/* 1. EN TRÁNSITO */}
                    <div
                        onClick={() => {
                            setStatusFilter('IN_TRANSIT')
                            applyFilter({ status: 'IN_TRANSIT' })
                        }}
                        className={`cursor-pointer rounded-2xl border p-4 shadow-sm transition ${
                            statusFilter === 'IN_TRANSIT'
                                ? 'border-blue-500 bg-blue-50/70 ring-2 ring-blue-400 dark:bg-blue-950/40'
                                : 'border-slate-200 bg-white hover:border-blue-300 dark:border-neutral-800 dark:bg-neutral-900'
                        }`}
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-[11px] font-bold uppercase tracking-wider text-blue-700 dark:text-blue-400">
                                🚚 En Tránsito hacia MeLi
                            </span>
                            <span className="rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-extrabold text-blue-800 dark:bg-blue-900/60 dark:text-blue-300">
                                {kpis.in_transit?.shipments_count ?? 0} envíos
                            </span>
                        </div>
                        <div className="mt-2 flex items-baseline gap-2">
                            <span className="font-mono text-3xl font-black text-slate-900 dark:text-white">
                                {kpis.in_transit?.units?.toLocaleString() ?? 0}
                            </span>
                            <span className="text-xs text-slate-400 font-semibold">piezas en camino</span>
                        </div>
                        <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                            Con guías ENVIA activas hacia CEDIS
                        </p>
                    </div>

                    {/* 2. EN BODEGA MELI */}
                    <div className="rounded-2xl border border-emerald-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="flex items-center justify-between">
                            <span className="text-[11px] font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                                🏢 En Bodega MeLi (Disponible)
                            </span>
                            <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-extrabold text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-300">
                                Listo para venta
                            </span>
                        </div>
                        <div className="mt-2 flex items-baseline gap-2">
                            <span className="font-mono text-3xl font-black text-emerald-700 dark:text-emerald-300">
                                {kpis.in_warehouse?.units?.toLocaleString() ?? 0}
                            </span>
                            <span className="text-xs text-slate-400 font-semibold">unidades en stock</span>
                        </div>
                        <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                            Inventario activo sincronizado con FULL
                        </p>
                    </div>

                    {/* 3. DAÑADO */}
                    <div className="rounded-2xl border border-rose-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="flex items-center justify-between">
                            <span className="text-[11px] font-bold uppercase tracking-wider text-rose-700 dark:text-rose-400">
                                ⚠️ Producto Dañado / Merma
                            </span>
                            <span className="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-extrabold text-rose-800 dark:bg-rose-900/60 dark:text-rose-300">
                                No vendible
                            </span>
                        </div>
                        <div className="mt-2 flex items-baseline gap-2">
                            <span className="font-mono text-3xl font-black text-rose-600 dark:text-rose-400">
                                {kpis.damaged?.total_units?.toLocaleString() ?? 0}
                            </span>
                            <span className="text-xs text-slate-400 font-semibold">piezas dañadas</span>
                        </div>
                        <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                            Reportadas en recepción o bodega
                        </p>
                    </div>

                    {/* 4. EN REVISIÓN / FALLA */}
                    <div
                        onClick={() => {
                            setStatusFilter('DISCREPANCY')
                            applyFilter({ status: 'DISCREPANCY' })
                        }}
                        className={`cursor-pointer rounded-2xl border p-4 shadow-sm transition ${
                            statusFilter === 'DISCREPANCY'
                                ? 'border-amber-500 bg-amber-50/70 ring-2 ring-amber-400 dark:bg-amber-950/40'
                                : 'border-slate-200 bg-white hover:border-amber-300 dark:border-neutral-800 dark:bg-neutral-900'
                        }`}
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-[11px] font-bold uppercase tracking-wider text-amber-700 dark:text-amber-400">
                                🔍 En Revisión / Falla
                            </span>
                            <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-extrabold text-amber-800 dark:bg-amber-900/60 dark:text-amber-300">
                                Requiere atención
                            </span>
                        </div>
                        <div className="mt-2 flex items-baseline gap-2">
                            <span className="font-mono text-3xl font-black text-amber-700 dark:text-amber-300">
                                {kpis.under_review?.total_units?.toLocaleString() ?? 0}
                            </span>
                            <span className="text-xs text-slate-400 font-semibold">piezas observadas</span>
                        </div>
                        <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                            Retenciones, auditoría o faltantes de bulto
                        </p>
                    </div>
                </div>

                {/* FILTERS TOOLBAR */}
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-wrap items-center gap-2">
                        {/* Status Filter */}
                        <div className="flex items-center gap-1.5 rounded-xl border border-slate-300 bg-slate-50/50 px-3 py-1.5 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                            <span className="text-slate-400">🚦</span>
                            <select
                                value={statusFilter}
                                onChange={(e) => {
                                    setStatusFilter(e.target.value)
                                    applyFilter({ status: e.target.value })
                                }}
                                className="bg-transparent font-semibold text-slate-800 outline-none dark:text-slate-200"
                            >
                                <option value="ALL">Todos los estados</option>
                                <option value="DRAFT">Borrador</option>
                                <option value="PACKED">Cajas Selladas</option>
                                <option value="IN_TRANSIT">En Tránsito (ENVIA)</option>
                                <option value="DELIVERED">Entregado en CEDIS</option>
                                <option value="RECEIVED">Recibido MeLi</option>
                                <option value="DISCREPANCY">Con Discrepancias</option>
                                <option value="CANCELLED">Cancelado</option>
                            </select>
                        </div>

                        {/* Warehouse Filter */}
                        <div className="flex items-center gap-1.5 rounded-xl border border-slate-300 bg-slate-50/50 px-3 py-1.5 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                            <span className="text-slate-400">🏢</span>
                            <select
                                value={warehouseFilter}
                                onChange={(e) => {
                                    setWarehouseFilter(e.target.value)
                                    applyFilter({ warehouse: e.target.value })
                                }}
                                className="bg-transparent font-semibold text-slate-800 outline-none dark:text-slate-200"
                            >
                                <option value="">Todas las bodegas MeLi</option>
                                {Object.entries(warehouses).map(([code, name]) => (
                                    <option key={code} value={code}>
                                        {code} - {name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {/* Carrier Filter */}
                        <div className="flex items-center gap-1.5 rounded-xl border border-slate-300 bg-slate-50/50 px-3 py-1.5 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                            <span className="text-slate-400">🚚</span>
                            <select
                                value={carrierFilter}
                                onChange={(e) => {
                                    setCarrierFilter(e.target.value)
                                    applyFilter({ carrier: e.target.value })
                                }}
                                className="bg-transparent font-semibold text-slate-800 outline-none dark:text-slate-200"
                            >
                                <option value="">Todas las paqueterías</option>
                                {carriers.map((c) => (
                                    <option key={c} value={c}>
                                        {c}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {(statusFilter !== 'ALL' || carrierFilter || warehouseFilter || search) && (
                            <button
                                type="button"
                                onClick={() => {
                                    setStatusFilter('ALL')
                                    setCarrierFilter('')
                                    setWarehouseFilter('')
                                    setSearch('')
                                    router.get('/meli/full/envios')
                                }}
                                className="text-xs font-bold text-red-500 hover:underline"
                            >
                                Limpiar filtros
                            </button>
                        )}
                    </div>

                    {/* Search Form */}
                    <form onSubmit={handleSearch} className="flex items-center gap-2">
                        <div className="flex items-center gap-1.5 rounded-xl border border-slate-300 bg-slate-50/50 px-3 py-1.5 text-xs dark:border-neutral-700 dark:bg-neutral-800 w-64">
                            <span className="text-slate-400">🔍</span>
                            <input
                                type="text"
                                placeholder="Folio, guía ENVIA, notas..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="w-full bg-transparent outline-none dark:text-white"
                            />
                        </div>
                        <button
                            type="submit"
                            className="rounded-xl bg-slate-800 px-3.5 py-1.5 text-xs font-bold text-white hover:bg-slate-700 dark:bg-neutral-800 dark:hover:bg-neutral-700"
                        >
                            Filtrar
                        </button>
                    </form>
                </div>

                {/* SHIPMENTS DATA TABLE */}
                <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <table className="w-full text-left text-xs">
                        <thead className="border-b border-slate-200 bg-slate-50/70 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:border-neutral-800 dark:bg-neutral-950">
                            <tr>
                                <th className="p-3.5">Folio & Fecha</th>
                                <th className="p-3.5">Bodega MeLi Destino</th>
                                <th className="p-3.5">Guía ENVIA / Paquetería</th>
                                <th className="p-3.5 text-center">Cajas (de 30)</th>
                                <th className="p-3.5 text-center">Total Piezas</th>
                                <th className="p-3.5 text-center">Estado</th>
                                <th className="p-3.5 text-center">Recepción MeLi</th>
                                <th className="p-3.5 text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 font-medium dark:divide-neutral-800">
                            {shipments.data?.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="py-12 text-center text-sm text-slate-400">
                                        No se encontraron envíos FULL con los filtros seleccionados.
                                    </td>
                                </tr>
                            ) : (
                                shipments.data?.map((shipment) => {
                                    const badge = STATUS_BADGES[shipment.status] || STATUS_BADGES.DRAFT
                                    return (
                                        <tr
                                            key={shipment.id}
                                            className="transition hover:bg-slate-50/60 dark:hover:bg-neutral-950/40"
                                        >
                                            <td className="p-3.5">
                                                <Link
                                                    href={`/meli/full/envios/${shipment.id}`}
                                                    className="font-mono font-black text-indigo-600 hover:underline dark:text-indigo-400 block"
                                                >
                                                    {shipment.shipment_code}
                                                </Link>
                                                <span className="text-[10px] text-slate-400">
                                                    {formatDate(shipment.created_at)}
                                                </span>
                                            </td>

                                            <td className="p-3.5">
                                                <div className="flex items-center gap-1.5">
                                                    <span className="rounded-md bg-amber-100 px-2 py-0.5 font-mono text-[11px] font-black text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                                                        {shipment.meli_warehouse_code}
                                                    </span>
                                                </div>
                                                <span className="text-[10px] text-slate-500 dark:text-slate-400 block truncate max-w-[180px]">
                                                    {shipment.meli_warehouse_name}
                                                </span>
                                            </td>

                                            <td className="p-3.5">
                                                {shipment.envia_tracking_number ? (
                                                    <div>
                                                        <div className="flex items-center gap-1">
                                                            <span className="font-semibold text-slate-800 dark:text-slate-200">
                                                                {shipment.envia_carrier || 'ENVIA'}:
                                                            </span>
                                                            <span className="font-mono font-bold text-slate-900 dark:text-white">
                                                                {shipment.envia_tracking_number}
                                                            </span>
                                                        </div>
                                                        {shipment.envia_tracking_url && (
                                                            <a
                                                                href={shipment.envia_tracking_url}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="text-[10px] font-bold text-blue-600 hover:underline dark:text-blue-400 inline-flex items-center gap-1"
                                                            >
                                                                <span>🔗</span> Rastrear en ENVIA
                                                            </a>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <span className="text-slate-400 italic">Sin guía asignada</span>
                                                )}
                                            </td>

                                            <td className="p-3.5 text-center font-mono">
                                                <span className="inline-block rounded-lg bg-slate-100 px-2.5 py-1 font-bold text-slate-800 dark:bg-neutral-800 dark:text-slate-200">
                                                    📦 {shipment.total_boxes || shipment.boxes_count || 1} cajas
                                                </span>
                                            </td>

                                            <td className="p-3.5 text-center font-mono">
                                                <span className="text-sm font-extrabold text-slate-900 dark:text-white">
                                                    {shipment.total_units}
                                                </span>
                                                <span className="text-[10px] text-slate-400 block">piezas</span>
                                            </td>

                                            <td className="p-3.5 text-center">
                                                <span
                                                    className={`inline-block rounded-full border px-2.5 py-0.5 text-[10px] font-extrabold ${badge.bg}`}
                                                >
                                                    {badge.label}
                                                </span>
                                            </td>

                                            <td className="p-3.5 text-center">
                                                {shipment.received_at ? (
                                                    <div className="text-[10px]">
                                                        <span className="font-bold text-emerald-600 dark:text-emerald-400 block">
                                                            ✓ {shipment.total_units_received} conformes
                                                        </span>
                                                        {(shipment.total_units_damaged > 0 || shipment.total_units_missing > 0) && (
                                                            <span className="font-bold text-rose-600 dark:text-rose-400 block">
                                                                {shipment.total_units_damaged > 0 && `⚠️ ${shipment.total_units_damaged} dañadas `}
                                                                {shipment.total_units_missing > 0 && `❌ ${shipment.total_units_missing} faltantes`}
                                                            </span>
                                                        )}
                                                    </div>
                                                ) : shipment.status === 'IN_TRANSIT' ? (
                                                    <span className="text-[10px] font-bold text-blue-600 dark:text-blue-400">
                                                        En camino a CEDIS
                                                    </span>
                                                ) : (
                                                    <span className="text-slate-400 text-[10px]">-</span>
                                                )}
                                            </td>

                                            <td className="p-3.5 text-center">
                                                <div className="flex items-center justify-center gap-1.5">
                                                    <Link
                                                        href={`/meli/full/envios/${shipment.id}`}
                                                        className="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                                                        title="Ver detalle de cajas y productos"
                                                    >
                                                        Ver Detalle
                                                    </Link>

                                                    <a
                                                        href={`/meli/full/envios/${shipment.id}/rotulos`}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                                                        title="Imprimir rótulos de caja de 30"
                                                    >
                                                        🏷️
                                                    </a>

                                                    {in_array_js(shipment.status, ['DRAFT', 'PACKED']) && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleDispatch(shipment)}
                                                            className="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-bold text-white hover:bg-emerald-700"
                                                            title="Despachar y registrar salida de almacén"
                                                        >
                                                            Despachar
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    )
                                })
                            )}
                        </tbody>
                    </table>
                </div>

                {/* PAGINATION */}
                {shipments.links?.length > 3 && (
                    <div className="flex items-center justify-center gap-1">
                        {shipments.links.map((link, idx) => (
                            <Link
                                key={idx}
                                href={link.url || '#'}
                                className={`rounded-xl px-3 py-1.5 text-xs font-bold transition ${
                                    link.active
                                        ? 'bg-indigo-600 text-white'
                                        : link.url
                                        ? 'bg-white text-slate-700 hover:bg-slate-100 dark:bg-neutral-800 dark:text-slate-300'
                                        : 'text-slate-400 cursor-not-allowed'
                                }`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppShell>
    )
}

function in_array_js(val, arr) {
    return Array.isArray(arr) && arr.includes(val)
}
