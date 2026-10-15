import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'

export default function ShelfAuditShow({ audit, shelf }) {
    const { flash = {} } = usePage().props
    const [isApplying, setIsApplying] = useState(false)
    const [applyNotes, setApplyNotes] = useState('')
    const [showApplyModal, setShowApplyModal] = useState(false)
    const [filterTab, setFilterTab] = useState('all')

    const displayShelf = audit.shelf_code || audit.location?.code

    const formatDate = (dateStr) => {
        if (!dateStr) return '—'
        try {
            const d = new Date(dateStr)
            return d.toLocaleString('es-MX', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            })
        } catch {
            return dateStr
        }
    }

    const handleApplyAdjustment = () => {
        setIsApplying(true)
        router.post(
            `/almacen/auditorias/${audit.id}/aplicar`,
            { notes: applyNotes },
            {
                onFinish: () => {
                    setIsApplying(false)
                    setShowApplyModal(false)
                },
                onError: (errs) => {
                    console.error('Error aplicando ajustes:', errs)
                    alert('Error al aplicar ajustes: ' + (errs?.error || 'Revisa el log.'))
                },
            }
        )
    }

    const filteredItems = (audit.items || []).filter((item) => {
        const diff = item.difference ?? item.difference_quantity
        if (filterTab === 'diff') return diff !== 0
        if (filterTab === 'over') return diff > 0
        if (filterTab === 'short') return diff < 0
        if (filterTab === 'exact') return diff === 0
        return true
    })

    const isBrand = audit.audit_type === 'brand'
    const displayTarget = isBrand
        ? `Marca ${audit.brand_name}`
        : `Anaquel ${audit.shelf_code || audit.location?.code || 'General'}`

    return (
        <AppShell title={`Auditoría ${displayTarget}`}>
            <Head title={`Auditoría ${displayTarget} - ${audit.audit_number}`} />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <div className="flex items-center gap-2">
                            <Link
                                href="/almacen/auditorias"
                                className="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                            >
                                ← Volver a auditorías
                            </Link>
                            <span className="text-slate-300 dark:text-neutral-700">•</span>
                            <span className="font-mono text-xs font-bold text-slate-500 dark:text-neutral-400">
                                {audit.audit_number}
                            </span>
                        </div>
                        <div className="mt-1 flex flex-wrap items-center gap-3">
                            <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-white sm:text-3xl">
                                {displayTarget}
                            </h1>
                            {audit.status === 'applied' && (
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-300">
                                    <span className="h-2 w-2 rounded-full bg-emerald-500" />
                                    Ajuste Aplicado al Inventario
                                </span>
                            )}
                            {audit.status === 'in_progress' && (
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1 text-xs font-bold text-amber-700 ring-1 ring-amber-600/20 dark:bg-amber-950/40 dark:text-amber-300">
                                    <span className="h-2 w-2 rounded-full bg-amber-500" />
                                    Borrador (Pendiente de Aplicar)
                                </span>
                            )}
                        </div>

                        {!isBrand && shelf?.sub_locations?.length > 1 && (
                            <p className="mt-1 text-xs font-medium text-slate-500 dark:text-slate-400">
                                Casillas auditadas ({shelf.sub_locations_count}):{' '}
                                <strong className="text-indigo-600 dark:text-indigo-400">
                                    {shelf.sub_locations.join(', ')}
                                </strong>
                            </p>
                        )}
                        {isBrand && (
                            <p className="mt-1 text-xs font-medium text-slate-500 dark:text-slate-400">
                                Auditoría física de todos los productos del catálogo de la marca <strong className="text-purple-600 dark:text-purple-400">{audit.brand_name}</strong>.
                            </p>
                        )}
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {audit.status === 'in_progress' && (
                            <button
                                type="button"
                                onClick={() => setShowApplyModal(true)}
                                className="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700 active:scale-95"
                            >
                                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
                                </svg>
                                <span>⚡ Aplicar Ajustes de Stock Ahora</span>
                            </button>
                        )}

                        <Link
                            href={`/almacen/movimientos?search=${encodeURIComponent(audit.audit_number)}`}
                            className="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                        >
                            <svg className="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                            </svg>
                            <span>Ver Movimientos de Ledger</span>
                        </Link>
                    </div>
                </div>

                {/* Flash Messages */}
                {flash.success && (
                    <div className="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800 dark:border-emerald-800/40 dark:bg-emerald-950/40 dark:text-emerald-300">
                        <svg className="h-5 w-5 shrink-0 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                        </svg>
                        <span>{flash.success}</span>
                    </div>
                )}
                {flash.error && (
                    <div className="flex items-center gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800 dark:border-rose-800/40 dark:bg-rose-950/40 dark:text-rose-300">
                        <svg className="h-5 w-5 shrink-0 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                        <span>{flash.error}</span>
                    </div>
                )}

                {/* In Progress Warning Banner */}
                {audit.status === 'in_progress' && (
                    <div className="rounded-2xl border border-amber-300 bg-gradient-to-r from-amber-50 to-orange-50 p-5 shadow-xs dark:border-amber-900/60 dark:from-amber-950/30 dark:to-orange-950/30">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-center gap-3">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-500 text-white font-bold text-lg">
                                    !
                                </div>
                                <div>
                                    <h3 className="text-sm font-bold text-amber-900 dark:text-amber-200">
                                        Esta auditoría está guardada como Borrador
                                    </h3>
                                    <p className="text-xs text-amber-800 dark:text-amber-300">
                                        Las cantidades físicas del anaquel {displayShelf} ya fueron contadas, pero el stock oficial aún no ha sido modificado. Haz clic en "Aplicar Ajustes de Stock Ahora" para cuadrar el inventario oficial.
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setShowApplyModal(true)}
                                className="rounded-xl bg-amber-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-amber-700"
                            >
                                ⚡ Aplicar Ajustes Ahora
                            </button>
                        </div>
                    </div>
                )}

                {/* Audit Meta Information Card */}
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-xs font-bold uppercase text-slate-400">Anaquel Auditado</span>
                        <p className="mt-1 text-base font-black text-slate-900 dark:text-white">
                            📍 Anaquel {displayShelf}
                        </p>
                        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            {shelf?.sub_locations_count > 1 ? `${shelf.sub_locations_count} casillas integradas` : (audit.location?.name || 'Ubicación única')}
                        </p>
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-xs font-bold uppercase text-slate-400">Auditor / Creación</span>
                        <p className="mt-1 text-base font-bold text-slate-900 dark:text-white">
                            👤 {audit.user?.name || 'Sistema'}
                        </p>
                        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Fecha: {formatDate(audit.created_at)}
                        </p>
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-xs font-bold uppercase text-slate-400">Estado de Ajuste</span>
                        {audit.status === 'applied' ? (
                            <div>
                                <p className="mt-1 text-base font-bold text-emerald-700 dark:text-emerald-300">
                                    ✓ Aplicado por {audit.applied_by?.name || 'Usuario'}
                                </p>
                                <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                    Fecha: {formatDate(audit.applied_at)}
                                </p>
                            </div>
                        ) : (
                            <p className="mt-1 text-base font-bold text-amber-700 dark:text-amber-400">
                                ⏳ Pendiente de aplicación
                            </p>
                        )}
                    </div>
                </div>

                {audit.notes && (
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-xs font-bold uppercase text-slate-400">Observaciones Generales</span>
                        <p className="mt-1 text-sm text-slate-800 dark:text-slate-200">
                            {audit.notes}
                        </p>
                    </div>
                )}

                {/* KPI Metrics */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-6">
                    <div className="rounded-2xl border border-slate-200 bg-white p-3.5 shadow-2xs dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-[11px] font-semibold uppercase text-slate-500 dark:text-neutral-400">Productos</span>
                        <p className="mt-0.5 text-xl font-black text-slate-900 dark:text-white">
                            {audit.total_expected_products || audit.total_products_count || 0}
                        </p>
                    </div>
                    <div className="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-3.5 shadow-2xs dark:border-emerald-900/40 dark:bg-emerald-950/20">
                        <span className="text-[11px] font-semibold uppercase text-emerald-700 dark:text-emerald-400">Exactos</span>
                        <p className="mt-0.5 text-xl font-black text-emerald-700 dark:text-emerald-300">
                            {audit.matched_items_count ?? audit.exact_matches_count ?? 0}
                        </p>
                    </div>
                    <div className="rounded-2xl border border-blue-200 bg-blue-50/50 p-3.5 shadow-2xs dark:border-blue-900/40 dark:bg-blue-950/20">
                        <span className="text-[11px] font-semibold uppercase text-blue-700 dark:text-blue-400">Sobrantes (+)</span>
                        <p className="mt-0.5 text-xl font-black text-blue-700 dark:text-blue-300">
                            {audit.surplus_items_count ?? audit.over_stock_count ?? 0}
                        </p>
                    </div>
                    <div className="rounded-2xl border border-rose-200 bg-rose-50/50 p-3.5 shadow-2xs dark:border-rose-900/40 dark:bg-rose-950/20">
                        <span className="text-[11px] font-semibold uppercase text-rose-700 dark:text-rose-400">Faltantes (-)</span>
                        <p className="mt-0.5 text-xl font-black text-rose-700 dark:text-rose-300">
                            {audit.missing_items_count ?? audit.short_stock_count ?? 0}
                        </p>
                    </div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-3.5 shadow-2xs dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-[11px] font-semibold uppercase text-slate-500 dark:text-neutral-400">Total Físico</span>
                        <p className="mt-0.5 text-xl font-black text-slate-900 dark:text-white">
                            {audit.total_counted_units ?? 0}
                        </p>
                    </div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-3.5 shadow-2xs dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-[11px] font-semibold uppercase text-slate-500 dark:text-neutral-400">Dif. Neta</span>
                        <p className={`mt-0.5 text-xl font-black ${audit.total_difference_units > 0 ? 'text-blue-600' : audit.total_difference_units < 0 ? 'text-rose-600' : 'text-slate-800 dark:text-slate-200'}`}>
                            {audit.total_difference_units > 0 ? `+${audit.total_difference_units}` : audit.total_difference_units} uds
                        </p>
                    </div>
                </div>

                {/* Items Detail Table */}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-wrap items-center gap-1.5 border-b border-slate-200 p-4 dark:border-neutral-800">
                        <button
                            type="button"
                            onClick={() => setFilterTab('all')}
                            className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                filterTab === 'all'
                                    ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-neutral-800 dark:text-slate-300'
                            }`}
                        >
                            Todos ({audit.items?.length || 0})
                        </button>
                        <button
                            type="button"
                            onClick={() => setFilterTab('diff')}
                            className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                filterTab === 'diff'
                                    ? 'bg-amber-600 text-white'
                                    : 'bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-950/40 dark:text-amber-300'
                            }`}
                        >
                            Con Diferencia ({((audit.surplus_items_count ?? audit.over_stock_count) || 0) + ((audit.missing_items_count ?? audit.short_stock_count) || 0)})
                        </button>
                        <button
                            type="button"
                            onClick={() => setFilterTab('over')}
                            className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                filterTab === 'over'
                                    ? 'bg-blue-600 text-white'
                                    : 'bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-950/40 dark:text-blue-300'
                            }`}
                        >
                            Sobrantes (+{audit.surplus_items_count ?? audit.over_stock_count ?? 0})
                        </button>
                        <button
                            type="button"
                            onClick={() => setFilterTab('short')}
                            className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                filterTab === 'short'
                                    ? 'bg-rose-600 text-white'
                                    : 'bg-rose-50 text-rose-700 hover:bg-rose-100 dark:bg-rose-950/40 dark:text-rose-300'
                            }`}
                        >
                            Faltantes (-{audit.missing_items_count ?? audit.short_stock_count ?? 0})
                        </button>
                        <button
                            type="button"
                            onClick={() => setFilterTab('exact')}
                            className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                filterTab === 'exact'
                                    ? 'bg-emerald-600 text-white'
                                    : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300'
                            }`}
                        >
                            Exactos ({audit.matched_items_count ?? audit.exact_matches_count ?? 0})
                        </button>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-neutral-800">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-neutral-950 dark:text-slate-400">
                                <tr>
                                    <th className="px-5 py-3.5">Producto / SKU</th>
                                    <th className="px-5 py-3.5 text-center">Stock Anterior en Anaquel</th>
                                    <th className="px-5 py-3.5 text-center">Físico Contado</th>
                                    <th className="px-5 py-3.5 text-center">Diferencia</th>
                                    <th className="px-5 py-3.5 text-center">Movimiento de Ajuste</th>
                                    <th className="px-5 py-3.5">Observaciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                {filteredItems.map((item) => {
                                    const diff = item.difference ?? item.difference_quantity

                                    return (
                                        <tr key={item.id} className="hover:bg-slate-50/50 dark:hover:bg-neutral-800/40">
                                            <td className="px-5 py-3.5">
                                                <Link
                                                    href={`/almacen/productos/${item.product?.id}`}
                                                    className="font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                                                >
                                                    {item.product?.name}
                                                </Link>
                                                <div className="mt-0.5 flex flex-wrap items-center gap-2 font-mono text-xs text-slate-500 dark:text-neutral-400">
                                                    <span className="font-bold text-slate-700 dark:text-slate-300">
                                                        SKU: {item.product?.sku}
                                                    </span>
                                                    {item.product?.barcode && <span>• Cód: {item.product?.barcode}</span>}
                                                    {item.product?.brand && (
                                                        <span className="font-sans text-indigo-600 dark:text-indigo-400">
                                                            [{item.product?.brand}]
                                                        </span>
                                                    )}
                                                    {item.product?.primary_location?.code && (
                                                        <span className="rounded bg-slate-100 px-1.5 py-0.2 font-sans text-[10px] text-slate-600 dark:bg-neutral-800 dark:text-slate-300">
                                                            Oficial: {item.product?.primary_location?.code}
                                                        </span>
                                                    )}
                                                </div>
                                            </td>

                                            <td className="px-5 py-3.5 text-center">
                                                <span className="font-mono text-base font-bold text-slate-700 dark:text-slate-300">
                                                    {item.system_quantity}
                                                </span>
                                                {item.location_breakdown && (
                                                    <span className="block text-[11px] font-medium text-slate-500 dark:text-slate-400">
                                                        {item.location_breakdown}
                                                    </span>
                                                )}
                                            </td>

                                            <td className="px-5 py-3.5 text-center font-mono text-base font-black text-slate-900 dark:text-white">
                                                {item.counted_quantity}
                                            </td>

                                            <td className="px-5 py-3.5 text-center">
                                                {diff === 0 && (
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-300">
                                                        ✓ Exacto (0)
                                                    </span>
                                                )}
                                                {diff > 0 && (
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700 ring-1 ring-blue-600/20 dark:bg-blue-950/40 dark:text-blue-300">
                                                        +{diff} Sobrante
                                                    </span>
                                                )}
                                                {diff < 0 && (
                                                    <span className="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2.5 py-1 text-xs font-bold text-rose-700 ring-1 ring-rose-600/20 dark:bg-rose-950/40 dark:text-rose-300">
                                                        {diff} Faltante
                                                    </span>
                                                )}
                                            </td>

                                            <td className="px-5 py-3.5 text-center">
                                                {audit.status === 'applied' ? (
                                                    diff === 0 ? (
                                                        <span className="text-xs text-slate-400">Sin movimiento</span>
                                                    ) : diff > 0 ? (
                                                        <span className="inline-flex items-center gap-1 font-mono text-xs font-bold text-blue-700 dark:text-blue-400">
                                                            <span className="h-1.5 w-1.5 rounded-full bg-blue-500" />
                                                            + {diff} Entrada (ADJUSTMENT_IN)
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center gap-1 font-mono text-xs font-bold text-rose-700 dark:text-rose-400">
                                                            <span className="h-1.5 w-1.5 rounded-full bg-rose-500" />
                                                            {diff} Salida (ADJUSTMENT_OUT)
                                                        </span>
                                                    )
                                                ) : (
                                                    <span className="text-xs italic text-amber-600 dark:text-amber-400">
                                                        Pendiente de aplicar
                                                    </span>
                                                )}
                                            </td>

                                            <td className="px-5 py-3.5 text-xs text-slate-500 dark:text-neutral-400">
                                                {item.notes || '—'}
                                            </td>
                                        </tr>
                                    )
                                })}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {/* Apply Adjustment Confirmation Modal */}
            {showApplyModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4 backdrop-blur-xs">
                    <div className="w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="flex items-center gap-3">
                            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400">
                                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
                                </svg>
                            </div>
                            <div>
                                <h3 className="text-lg font-bold text-slate-900 dark:text-white">
                                    Aplicar Ajustes al Anaquel {displayShelf}
                                </h3>
                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                    Esta acción actualizará el stock físico en el ledger para que coincida exactamente con lo contado en este anaquel.
                                </p>
                            </div>
                        </div>

                        <div className="mt-4 space-y-3 rounded-xl bg-slate-50 p-4 text-xs dark:bg-neutral-950">
                            <div className="flex justify-between">
                                <span className="text-slate-600 dark:text-slate-400">Productos a ajustar:</span>
                                <span className="font-bold text-slate-900 dark:text-white">
                                    {((audit.surplus_items_count ?? audit.over_stock_count) || 0) + ((audit.missing_items_count ?? audit.short_stock_count) || 0)}
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-slate-600 dark:text-slate-400">Total sobrantes (+):</span>
                                <span className="font-bold text-blue-600">
                                    +{audit.total_units_in ?? audit.surplus_items_count ?? 0} unidades
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-slate-600 dark:text-slate-400">Total faltantes (-):</span>
                                <span className="font-bold text-rose-600">
                                    -{audit.total_units_out ?? audit.missing_items_count ?? 0} unidades
                                </span>
                            </div>
                            <div className="flex justify-between border-t border-slate-200 pt-2 dark:border-neutral-800">
                                <span className="font-bold text-slate-700 dark:text-slate-300">Diferencia neta:</span>
                                <span className="font-black text-slate-900 dark:text-white">
                                    {audit.total_difference_units > 0 ? `+${audit.total_difference_units}` : audit.total_difference_units} unidades
                                </span>
                            </div>
                        </div>

                        <div className="mt-4">
                            <label className="block text-xs font-semibold text-slate-700 dark:text-neutral-300">
                                Nota del ajuste (opcional):
                            </label>
                            <input
                                type="text"
                                value={applyNotes}
                                onChange={(e) => setApplyNotes(e.target.value)}
                                placeholder="Ej: Ajuste quincenal autorizado por supervisor"
                                className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-xs dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                            />
                        </div>

                        <div className="mt-6 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setShowApplyModal(false)}
                                disabled={isApplying}
                                className="rounded-xl border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                            >
                                Cancelar
                            </button>
                            <button
                                type="button"
                                onClick={handleApplyAdjustment}
                                disabled={isApplying}
                                className="rounded-xl bg-emerald-600 px-5 py-2 text-xs font-bold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50"
                            >
                                {isApplying ? 'Aplicando ajustes...' : '✓ Confirmar y Ajustar Stock'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AppShell>
    )
}
