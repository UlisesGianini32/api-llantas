import AppShell from '@/Components/layout/AppShell'
import { router } from '@inertiajs/react'
import { useEffect, useMemo, useState } from 'react'

const FILTERS = [
    ['all', 'Todo FULL'],
    ['available', 'Disponible'],
    ['zero', 'Sin disponible'],
    ['unavailable', 'No disponible'],
    ['errors', 'Con error'],
]

function Pager({ links }) {
    return (
        <div className="flex flex-wrap justify-center gap-2">
            {(links ?? []).map((link, index) => (
                <button
                    key={`${link.label}-${index}`}
                    type="button"
                    disabled={!link.url}
                    onClick={() =>
                        link.url &&
                        router.visit(link.url, {
                            preserveScroll: true,
                            preserveState: true,
                        })
                    }
                    className={`rounded-xl border px-3 py-2 text-sm ${
                        link.active
                            ? 'border-indigo-600 bg-indigo-600 text-white'
                            : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200'
                    } disabled:cursor-not-allowed disabled:opacity-40`}
                    dangerouslySetInnerHTML={{ __html: link.label }}
                />
            ))}
        </div>
    )
}

function StatCard({ label, value, detail }) {
    return (
        <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
            <p className="text-xs font-bold uppercase tracking-[0.16em] text-slate-500 dark:text-slate-400">
                {label}
            </p>
            <p className="mt-3 text-3xl font-black text-slate-900 dark:text-white">{value ?? 0}</p>
            <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">{detail}</p>
        </div>
    )
}

function SectionButton({ active, label, value, detail, onClick, tone = 'indigo' }) {
    const activeClass =
        tone === 'amber'
            ? 'border-amber-500 bg-amber-50 dark:bg-amber-500/10'
            : tone === 'sky'
              ? 'border-sky-500 bg-sky-50 dark:bg-sky-500/10'
              : tone === 'rose'
                ? 'border-rose-500 bg-rose-50 dark:bg-rose-500/10'
                : tone === 'emerald'
                  ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-500/10'
                  : 'border-indigo-500 bg-indigo-50 dark:bg-indigo-500/10'

    return (
        <button
            type="button"
            onClick={onClick}
            className={`rounded-3xl border p-5 text-left shadow-sm transition ${
                active
                    ? activeClass
                    : 'border-slate-200 bg-white hover:border-slate-300 dark:border-neutral-800 dark:bg-neutral-900'
            }`}
        >
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-sm font-black text-slate-900 dark:text-white">{label}</p>
                    <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">{detail}</p>
                </div>
                <span className="rounded-2xl bg-slate-100 px-3 py-2 text-xl font-black text-slate-800 dark:bg-neutral-800 dark:text-white">
                    {value ?? 0}
                </span>
            </div>
        </button>
    )
}

function NotAvailableDetail({ details }) {
    if (!Array.isArray(details) || details.length === 0) {
        return <span className="text-xs text-slate-400">Sin desglose</span>
    }

    return (
        <div className="flex max-w-[260px] flex-wrap gap-1.5">
            {details.map((detail, index) => (
                <span
                    key={`${detail.status ?? 'detalle'}-${index}`}
                    className="rounded-full bg-rose-50 px-2 py-1 text-[11px] font-semibold text-rose-700 dark:bg-rose-500/10 dark:text-rose-300"
                >
                    {detail.status ?? 'sin estado'}: {detail.quantity ?? 0}
                </span>
            ))}
        </div>
    )
}

function QuantityBadge({ value, type = 'total', compact = false }) {
    const classes = {
        available:
            'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        unavailable: 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        total: 'bg-slate-100 text-slate-800 dark:bg-neutral-800 dark:text-slate-100',
    }

    return (
        <span
            className={`inline-flex justify-center rounded-2xl font-black ${classes[type]} ${
                compact ? 'min-w-12 px-3 py-2 text-base' : 'min-w-16 px-4 py-3 text-xl'
            }`}
        >
            {value ?? 0}
        </span>
    )
}

function PublicationStatusBadge({ row }) {
    const label = row.publication_status_label || 'Estado pendiente'
    const status = String(row.publication_status || '').toLowerCase()
    const subStatuses = Array.isArray(row.publication_sub_status)
        ? row.publication_sub_status.map((value) => String(value).toLowerCase())
        : []

    let classes = 'bg-slate-100 text-slate-700 dark:bg-neutral-800 dark:text-slate-300'

    if (status === 'active') {
        classes = 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
    } else if (status === 'paused' && subStatuses.includes('out_of_stock')) {
        classes = 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'
    } else if (['under_review', 'inactive', 'closed'].includes(status) || row.is_replenishable_publication === false) {
        classes = 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'
    }

    return (
        <span className={`mt-2 inline-flex rounded-full px-2.5 py-1 text-[11px] font-bold ${classes}`}>
            {label}
        </span>
    )
}

function ProductCell({ row, compact = false }) {
    return (
        <div className={`flex gap-4 ${compact ? 'min-w-[300px]' : 'min-w-[330px]'}`}>
            <div
                className={`flex shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-neutral-700 dark:bg-neutral-800 ${
                    compact ? 'h-16 w-16' : 'h-20 w-20'
                }`}
            >
                {row.thumbnail ? (
                    <img src={row.thumbnail} alt="" className="h-full w-full object-contain" />
                ) : (
                    <span className="text-xs text-slate-400">Sin imagen</span>
                )}
            </div>
            <div className="min-w-0">
                <p className="font-semibold text-slate-900 dark:text-white">{row.title}</p>
                <p className="mt-1 text-xs text-slate-500">SKU: {row.sku || '—'}</p>
                <p className="text-xs text-slate-500">MLM: {row.mlm}</p>
                <p className="text-xs text-slate-500">{row.account_name}</p>
                <PublicationStatusBadge row={row} />
                {row.permalink && (
                    <a
                        href={row.permalink}
                        target="_blank"
                        rel="noreferrer"
                        className="mt-2 inline-block text-xs font-bold text-indigo-600 hover:underline dark:text-indigo-300"
                    >
                        Ver publicación
                    </a>
                )}
            </div>
        </div>
    )
}

function StandardInventoryTable({ rows, stocks, syncingMlm, syncOne, section }) {
    return (
        <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
            <div className="border-b border-slate-200 px-5 py-4 dark:border-neutral-800">
                <h2 className="font-black text-slate-900 dark:text-white">
                    {section === 'variants'
                        ? 'Variantes FULL'
                        : section === 'out_of_stock'
                          ? 'Agotados en FULL'
                          : 'Todo el inventario FULL'}
                </h2>
                <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {section === 'variants'
                        ? 'Cada variante aparece como una fila independiente con su SKU, Variation ID e inventario.'
                        : section === 'out_of_stock'
                          ? 'Solo publicaciones reponibles con cero unidades: activas o pausadas automáticamente por falta de stock. Se excluyen bloqueadas y en revisión.'
                          : 'Se muestran publicaciones sin variante y publicaciones con cada una de sus variantes.'}
                </p>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full min-w-[1400px] border-collapse text-left">
                    <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-neutral-950">
                        <tr>
                            <th className="p-4">Producto FULL</th>
                            <th className="p-4">Variante</th>
                            <th className="p-4">Identificadores</th>
                            <th className="p-4 text-center">Disponible</th>
                            <th className="p-4 text-center">No disponible</th>
                            <th className="p-4 text-center">Total</th>
                            <th className="p-4">Detalle no disponible</th>
                            <th className="p-4">Actualización</th>
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.id} className="border-b border-slate-200 align-top dark:border-neutral-800">
                                <td className="p-4">
                                    <ProductCell row={row} />
                                </td>

                                <td className="p-4">
                                    <p className="max-w-[240px] text-sm font-semibold text-slate-800 dark:text-slate-100">
                                        {row.variation_label || 'Producto sin variantes'}
                                    </p>
                                    <p className="mt-2 text-xs text-slate-500">
                                        Variation ID: {row.variation_id || '—'}
                                    </p>
                                </td>

                                <td className="p-4 text-xs text-slate-600 dark:text-slate-300">
                                    <div className="max-w-[260px] space-y-2 break-all">
                                        <p>
                                            <strong>Inventory ID:</strong> {row.inventory_id || '—'}
                                        </p>
                                        <p>
                                            <strong>User Product:</strong> {row.user_product_id || '—'}
                                        </p>
                                        <span className="inline-flex rounded-full bg-sky-50 px-2 py-1 font-bold text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">
                                            {row.stock_source === 'inventory'
                                                ? 'Fulfillment inventory'
                                                : 'User Products'}
                                        </span>
                                        {row.shares_inventory && (
                                            <span className="block w-fit rounded-full bg-amber-50 px-2 py-1 font-bold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                                                Conectado: {row.connected_publications} publicación
                                                {row.connected_publications === 1 ? '' : 'es'} / {row.connected_rows} fila
                                                {row.connected_rows === 1 ? '' : 's'}
                                            </span>
                                        )}
                                    </div>
                                </td>

                                <td className="p-4 text-center">
                                    <QuantityBadge value={row.full_available_quantity} type="available" />
                                </td>

                                <td className="p-4 text-center">
                                    <QuantityBadge
                                        value={row.full_not_available_quantity ?? 0}
                                        type="unavailable"
                                    />
                                </td>

                                <td className="p-4 text-center">
                                    <QuantityBadge
                                        value={row.full_total_quantity ?? row.full_available_quantity ?? 0}
                                    />
                                </td>

                                <td className="p-4">
                                    <NotAvailableDetail details={row.not_available_detail} />
                                    {row.last_error && (
                                        <p className="mt-3 max-w-[260px] text-xs font-semibold text-rose-600 dark:text-rose-300">
                                            {row.last_error}
                                        </p>
                                    )}
                                </td>

                                <td className="p-4">
                                    <p className="text-xs text-slate-500">
                                        {row.synced_at || 'Sin sincronizar'}
                                    </p>
                                    <button
                                        type="button"
                                        disabled={syncingMlm !== null}
                                        onClick={() => syncOne(row.mlm)}
                                        className="mt-3 rounded-xl border border-indigo-300 px-3 py-2 text-xs font-bold text-indigo-700 hover:bg-indigo-50 disabled:opacity-40 dark:border-indigo-700 dark:text-indigo-300 dark:hover:bg-indigo-500/10"
                                    >
                                        {syncingMlm === row.mlm ? 'Enviando...' : 'Sincronizar MLM'}
                                    </button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {rows.length === 0 && (
                <div className="p-12 text-center text-sm text-slate-500">
                    No hay registros FULL guardados con estos filtros.
                </div>
            )}

            <div className="border-t border-slate-200 p-4 dark:border-neutral-800">
                <Pager links={stocks?.links ?? []} />
            </div>
        </section>
    )
}

function ConnectedInventoryGroups({ groups, paginator, syncingMlm, syncOne }) {
    return (
        <section className="space-y-4">
            <div className="rounded-3xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900/60 dark:bg-amber-500/10">
                <h2 className="font-black text-amber-900 dark:text-amber-200">Productos conectados</h2>
                <p className="mt-1 text-sm text-amber-800/80 dark:text-amber-200/70">
                    Cada bloque reúne todas las publicaciones y variantes que Mercado Libre conecta al mismo
                    Inventory ID o User Product. El stock del encabezado se cuenta una sola vez.
                </p>
            </div>

            {groups.map((group) => (
                <article
                    key={group.physical_key}
                    className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                >
                    <header className="grid gap-5 border-b border-slate-200 bg-slate-50 p-5 dark:border-neutral-800 dark:bg-neutral-950 xl:grid-cols-[1fr_auto] xl:items-center">
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="rounded-full bg-amber-100 px-3 py-1 text-xs font-black text-amber-800 dark:bg-amber-500/20 dark:text-amber-200">
                                    Inventario compartido
                                </span>
                                <span className="rounded-full bg-indigo-50 px-3 py-1 text-xs font-bold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">
                                    {group.publication_count} publicación
                                    {group.publication_count === 1 ? '' : 'es'}
                                </span>
                                <span className="rounded-full bg-sky-50 px-3 py-1 text-xs font-bold text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">
                                    {group.row_count} fila{group.row_count === 1 ? '' : 's'}
                                </span>
                                <span className="rounded-full bg-violet-50 px-3 py-1 text-xs font-bold text-violet-700 dark:bg-violet-500/10 dark:text-violet-300">
                                    {group.variant_count} variante{group.variant_count === 1 ? '' : 's'}
                                </span>
                            </div>
                            <div className="mt-3 grid gap-1 text-xs text-slate-600 dark:text-slate-300 sm:grid-cols-2">
                                <p className="break-all">
                                    <strong>Inventory ID:</strong> {group.inventory_id || '—'}
                                </p>
                                <p className="break-all">
                                    <strong>User Product:</strong> {group.user_product_id || '—'}
                                </p>
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-3 xl:justify-end">
                            <div className="text-center">
                                <p className="mb-1 text-[10px] font-bold uppercase text-slate-500">Disponible</p>
                                <QuantityBadge value={group.available_quantity} type="available" compact />
                            </div>
                            <div className="text-center">
                                <p className="mb-1 text-[10px] font-bold uppercase text-slate-500">No disponible</p>
                                <QuantityBadge
                                    value={group.not_available_quantity}
                                    type="unavailable"
                                    compact
                                />
                            </div>
                            <div className="text-center">
                                <p className="mb-1 text-[10px] font-bold uppercase text-slate-500">Total</p>
                                <QuantityBadge value={group.total_quantity} compact />
                            </div>
                        </div>
                    </header>

                    <div className="divide-y divide-slate-200 dark:divide-neutral-800">
                        {group.rows.map((row) => (
                            <div
                                key={row.id}
                                className="grid gap-5 p-5 xl:grid-cols-[minmax(320px,1.5fr)_minmax(220px,1fr)_minmax(220px,1fr)_auto] xl:items-center"
                            >
                                <ProductCell row={row} compact />

                                <div>
                                    <p className="text-xs font-bold uppercase tracking-wide text-slate-400">
                                        Variante
                                    </p>
                                    <p className="mt-1 text-sm font-semibold text-slate-900 dark:text-white">
                                        {row.variation_label || 'Producto sin variantes'}
                                    </p>
                                    <p className="mt-1 break-all text-xs text-slate-500">
                                        Variation ID: {row.variation_id || '—'}
                                    </p>
                                </div>

                                <div className="space-y-1 text-xs text-slate-600 dark:text-slate-300">
                                    <p className="break-all">
                                        <strong>Inventory ID:</strong> {row.inventory_id || '—'}
                                    </p>
                                    <p className="break-all">
                                        <strong>User Product:</strong> {row.user_product_id || '—'}
                                    </p>
                                    <p>
                                        Actualizado: <strong>{row.synced_at || 'Sin sincronizar'}</strong>
                                    </p>
                                </div>

                                <div className="flex flex-wrap items-center gap-2 xl:justify-end">
                                    <QuantityBadge
                                        value={row.full_available_quantity}
                                        type="available"
                                        compact
                                    />
                                    <button
                                        type="button"
                                        disabled={syncingMlm !== null}
                                        onClick={() => syncOne(row.mlm)}
                                        className="rounded-xl border border-indigo-300 px-3 py-2 text-xs font-bold text-indigo-700 hover:bg-indigo-50 disabled:opacity-40 dark:border-indigo-700 dark:text-indigo-300 dark:hover:bg-indigo-500/10"
                                    >
                                        {syncingMlm === row.mlm ? 'Enviando...' : 'Sincronizar'}
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                </article>
            ))}

            {groups.length === 0 && (
                <div className="rounded-3xl border border-slate-200 bg-white p-12 text-center text-sm text-slate-500 dark:border-neutral-800 dark:bg-neutral-900">
                    No hay productos conectados con estos filtros.
                </div>
            )}

            <div className="rounded-3xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                <Pager links={paginator?.links ?? []} />
            </div>
        </section>
    )
}


function RecommendationGroups({
    groups,
    paginator,
    meta,
    filters,
    navigate,
    selectedAccountId,
}) {
    const minimumOneActive = (filters.recommendation_filter ?? 'all') === 'minimum_one'
    const canExport = Number(selectedAccountId) > 0 && Number(meta?.recommended_products ?? 0) > 0
    const exportQuery = new URLSearchParams({
        account_id: String(selectedAccountId ?? ''),
        sales_days: String(filters.sales_days ?? 30),
        coverage_days: String(filters.coverage_days ?? 14),
    }).toString()
    const exportUrl = `/meli/full/recommendations/export?${exportQuery}`

    return (
        <section className="space-y-4">
            <div className="rounded-3xl border border-emerald-200 bg-emerald-50 p-5 dark:border-emerald-900/60 dark:bg-emerald-500/10">
                <div className="grid gap-5 xl:grid-cols-[1fr_auto] xl:items-end">
                    <div>
                        <h2 className="font-black text-emerald-900 dark:text-emerald-200">
                            Recomendación de envío a FULL
                        </h2>
                        <p className="mt-1 max-w-4xl text-sm text-emerald-800/80 dark:text-emerald-200/70">
                            Se suman las ventas de todas las publicaciones y variantes conectadas al mismo
                            inventario físico. La cantidad sugerida se calcula una sola vez para evitar enviar
                            producto duplicado. Si un inventario está totalmente agotado y no tuvo
                            ventas durante los últimos 30 días, se recomienda enviar una pieza para volver a
                            mantenerlo disponible en FULL.
                        </p>
                        <p className="mt-3 text-xs font-semibold text-emerald-800 dark:text-emerald-200">
                            Fórmula: {meta?.formula ?? 'Promedio diario × cobertura - stock considerado'}
                        </p>
                        <p className="mt-1 text-xs text-emerald-700/80 dark:text-emerald-200/60">
                            Última actualización de pedidos:{' '}
                            <strong>{meta?.last_order_sync_at ?? 'Sin pedidos sincronizados'}</strong>
                        </p>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="text-xs font-bold uppercase tracking-wide text-emerald-900 dark:text-emerald-200">
                            Ventas analizadas
                            <select
                                value={filters.sales_days ?? 30}
                                onChange={(event) =>
                                    navigate({
                                        sales_days: Number(event.target.value),
                                        page: 1,
                                    })
                                }
                                className="mt-2 block w-full rounded-2xl border border-emerald-300 bg-white px-4 py-3 text-sm normal-case text-slate-900 dark:border-emerald-800 dark:bg-neutral-950 dark:text-white"
                            >
                                {[7, 15, 30, 60, 90].map((value) => (
                                    <option key={value} value={value}>
                                        Últimos {value} días
                                    </option>
                                ))}
                            </select>
                        </label>

                        <label className="text-xs font-bold uppercase tracking-wide text-emerald-900 dark:text-emerald-200">
                            Cobertura objetivo
                            <select
                                value={filters.coverage_days ?? 14}
                                onChange={(event) =>
                                    navigate({
                                        coverage_days: Number(event.target.value),
                                        page: 1,
                                    })
                                }
                                className="mt-2 block w-full rounded-2xl border border-emerald-300 bg-white px-4 py-3 text-sm normal-case text-slate-900 dark:border-emerald-800 dark:bg-neutral-950 dark:text-white"
                            >
                                {[7, 14, 21, 30].map((value) => (
                                    <option key={value} value={value}>
                                        {value} días de inventario
                                    </option>
                                ))}
                            </select>
                        </label>

                        {canExport ? (
                            <a
                                href={exportUrl}
                                className="sm:col-span-2 flex items-center justify-between gap-4 rounded-2xl bg-emerald-700 px-5 py-3 text-sm font-black text-white shadow-sm transition hover:bg-emerald-800 dark:bg-emerald-600 dark:hover:bg-emerald-500"
                            >
                                <span>Descargar Excel para Mercado Libre</span>
                                <span className="rounded-full bg-white/15 px-3 py-1 text-xs">
                                    {meta?.recommended_products ?? 0} productos · {meta?.recommended_units ?? 0} unidades
                                </span>
                            </a>
                        ) : (
                            <div className="sm:col-span-2 rounded-2xl border border-emerald-300 bg-white/60 px-5 py-3 text-sm font-semibold text-emerald-800 dark:border-emerald-800 dark:bg-neutral-950/40 dark:text-emerald-200">
                                No hay recomendaciones con cantidad mayor a cero para descargar.
                            </div>
                        )}
                    </div>
                </div>

                <div className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <div className="rounded-2xl bg-white/70 p-4 dark:bg-neutral-950/50">
                        <p className="text-xs font-bold uppercase text-emerald-700 dark:text-emerald-300">
                            Productos por reponer
                        </p>
                        <p className="mt-2 text-3xl font-black text-emerald-900 dark:text-white">
                            {meta?.recommended_products ?? 0}
                        </p>
                    </div>
                    <div className="rounded-2xl bg-white/70 p-4 dark:bg-neutral-950/50">
                        <p className="text-xs font-bold uppercase text-emerald-700 dark:text-emerald-300">
                            Unidades sugeridas
                        </p>
                        <p className="mt-2 text-3xl font-black text-emerald-900 dark:text-white">
                            {meta?.recommended_units ?? 0}
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() =>
                            navigate({
                                recommendation_filter: minimumOneActive ? 'all' : 'minimum_one',
                                search: '',
                                direction: 'desc',
                                page: 1,
                            })
                        }
                        className={`rounded-2xl p-4 text-left transition focus:outline-none focus:ring-2 focus:ring-amber-400 ${
                            minimumOneActive
                                ? 'border border-amber-400 bg-amber-100 shadow-sm dark:border-amber-500 dark:bg-amber-500/20'
                                : 'border border-transparent bg-white/70 hover:border-amber-300 hover:bg-amber-50 dark:bg-neutral-950/50 dark:hover:border-amber-700 dark:hover:bg-amber-500/10'
                        }`}
                    >
                        <p className="text-xs font-bold uppercase text-emerald-700 dark:text-emerald-300">
                            Mínimo de una pieza
                        </p>
                        <p className="mt-2 text-3xl font-black text-emerald-900 dark:text-white">
                            {meta?.minimum_one_products ?? 0}
                        </p>
                        <p className="mt-1 text-[11px] font-semibold text-amber-700 dark:text-amber-300">
                            {minimumOneActive
                                ? 'Mostrando estos productos · clic para ver todos'
                                : 'Haz clic para ver cuáles son'}
                        </p>
                    </button>
                    <div className="rounded-2xl bg-white/70 p-4 dark:bg-neutral-950/50">
                        <p className="text-xs font-bold uppercase text-emerald-700 dark:text-emerald-300">
                            Inventarios con ventas
                        </p>
                        <p className="mt-2 text-3xl font-black text-emerald-900 dark:text-white">
                            {meta?.groups_with_sales ?? 0}
                        </p>
                    </div>
                </div>
            </div>


            {minimumOneActive && (
                <div className="flex flex-col gap-3 rounded-2xl border border-amber-300 bg-amber-50 px-5 py-4 text-amber-900 dark:border-amber-800 dark:bg-amber-500/10 dark:text-amber-200 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="font-black">
                            Mostrando {meta?.visible_groups ?? meta?.minimum_one_products ?? 0} productos con mínimo de una pieza
                        </p>
                        <p className="mt-1 text-xs opacity-80">
                            Todos están agotados, tienen stock considerado en cero y no registran ventas en los últimos 30 días.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() =>
                            navigate({
                                recommendation_filter: 'all',
                                page: 1,
                            })
                        }
                        className="rounded-xl border border-amber-400 bg-white px-4 py-2 text-sm font-black text-amber-800 hover:bg-amber-100 dark:border-amber-700 dark:bg-neutral-950 dark:text-amber-200 dark:hover:bg-amber-500/10"
                    >
                        Ver todas las recomendaciones
                    </button>
                </div>
            )}

            {groups.map((group) => (
                <article
                    key={group.physical_key}
                    className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                >
                    <header className="grid gap-5 border-b border-slate-200 bg-slate-50 p-5 dark:border-neutral-800 dark:bg-neutral-950 xl:grid-cols-[1fr_auto] xl:items-center">
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <span
                                    className={`rounded-full px-3 py-1 text-xs font-black ${
                                        group.recommended_quantity > 0
                                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/20 dark:text-emerald-200'
                                            : 'bg-slate-200 text-slate-700 dark:bg-neutral-800 dark:text-slate-300'
                                    }`}
                                >
                                    Mandar {group.recommended_quantity} unidad
                                    {group.recommended_quantity === 1 ? '' : 'es'}
                                </span>
                                {group.minimum_one_applied && (
                                    <span className="rounded-full bg-amber-100 px-3 py-1 text-xs font-black text-amber-800 dark:bg-amber-500/20 dark:text-amber-200">
                                        Mínimo 1: agotado sin ventas en 30 días
                                    </span>
                                )}
                                <span className="rounded-full bg-indigo-50 px-3 py-1 text-xs font-bold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">
                                    {group.publication_count} publicación
                                    {group.publication_count === 1 ? '' : 'es'}
                                </span>
                                <span className="rounded-full bg-violet-50 px-3 py-1 text-xs font-bold text-violet-700 dark:bg-violet-500/10 dark:text-violet-300">
                                    {group.variant_count} variante{group.variant_count === 1 ? '' : 's'}
                                </span>
                            </div>

                            <div className="mt-3 grid gap-1 text-xs text-slate-600 dark:text-slate-300 sm:grid-cols-2">
                                <p className="break-all">
                                    <strong>Inventory ID:</strong> {group.inventory_id || '—'}
                                </p>
                                <p className="break-all">
                                    <strong>User Product:</strong> {group.user_product_id || '—'}
                                </p>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-5 xl:min-w-[720px]">
                            <div className="rounded-2xl bg-white p-3 text-center dark:bg-neutral-900">
                                <p className="text-[10px] font-bold uppercase text-slate-500">
                                    Ventas 7 días
                                </p>
                                <p className="mt-1 text-xl font-black text-slate-900 dark:text-white">
                                    {group.sales_7_days}
                                </p>
                            </div>
                            <div className="rounded-2xl bg-white p-3 text-center dark:bg-neutral-900">
                                <p className="text-[10px] font-bold uppercase text-slate-500">
                                    Ventas 30 días
                                </p>
                                <p className="mt-1 text-xl font-black text-slate-900 dark:text-white">
                                    {group.sales_30_days}
                                </p>
                            </div>
                            <div className="rounded-2xl bg-white p-3 text-center dark:bg-neutral-900">
                                <p className="text-[10px] font-bold uppercase text-slate-500">
                                    Ventas {group.sales_days} días
                                </p>
                                <p className="mt-1 text-xl font-black text-slate-900 dark:text-white">
                                    {group.sales_period}
                                </p>
                            </div>
                            <div className="rounded-2xl bg-white p-3 text-center dark:bg-neutral-900">
                                <p className="text-[10px] font-bold uppercase text-slate-500">
                                    Stock considerado
                                </p>
                                <p className="mt-1 text-xl font-black text-slate-900 dark:text-white">
                                    {group.stock_considered}
                                </p>
                            </div>
                            <div className="rounded-2xl bg-white p-3 text-center dark:bg-neutral-900">
                                <p className="text-[10px] font-bold uppercase text-slate-500">
                                    Stock objetivo
                                </p>
                                <p className="mt-1 text-xl font-black text-slate-900 dark:text-white">
                                    {group.target_stock}
                                </p>
                            </div>
                        </div>
                    </header>

                    <div className="border-b border-slate-200 px-5 py-3 text-xs text-slate-600 dark:border-neutral-800 dark:text-slate-300">
                        Promedio diario: <strong>{group.daily_average}</strong> · Ventas 30 días:{' '}
                        <strong>{group.sales_30_days}</strong> · Disponible:{' '}
                        <strong>{group.available_quantity}</strong> · En transferencia:{' '}
                        <strong>{group.transfer_quantity}</strong> · Total reportado:{' '}
                        <strong>{group.total_quantity}</strong>
                        {group.unmatched_rows > 0 && (
                            <span className="ml-3 rounded-full bg-amber-100 px-2 py-1 font-bold text-amber-800 dark:bg-amber-500/20 dark:text-amber-200">
                                {group.unmatched_rows} variante
                                {group.unmatched_rows === 1 ? '' : 's'} sin coincidencia exacta de venta
                            </span>
                        )}
                    </div>

                    <div className="divide-y divide-slate-200 dark:divide-neutral-800">
                        {group.rows.map((row) => (
                            <div
                                key={row.id}
                                className="grid gap-5 p-5 xl:grid-cols-[minmax(320px,1.5fr)_minmax(220px,1fr)_minmax(220px,1fr)] xl:items-center"
                            >
                                <ProductCell row={row} compact />
                                <div>
                                    <p className="text-xs font-bold uppercase tracking-wide text-slate-400">
                                        Variante
                                    </p>
                                    <p className="mt-1 text-sm font-semibold text-slate-900 dark:text-white">
                                        {row.variation_label || 'Producto sin variantes'}
                                    </p>
                                    <p className="mt-1 break-all text-xs text-slate-500">
                                        Variation ID: {row.variation_id || '—'}
                                    </p>
                                </div>
                                <div className="space-y-1 text-xs text-slate-600 dark:text-slate-300">
                                    <p className="break-all">
                                        <strong>Inventory ID:</strong> {row.inventory_id || '—'}
                                    </p>
                                    <p className="break-all">
                                        <strong>User Product:</strong> {row.user_product_id || '—'}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                </article>
            ))}

            {groups.length === 0 && (
                <div className="rounded-3xl border border-slate-200 bg-white p-12 text-center text-sm text-slate-500 dark:border-neutral-800 dark:bg-neutral-900">
                    {minimumOneActive
                        ? 'No hay productos que cumplan la regla de mínimo una pieza con los filtros actuales.'
                        : 'No hay inventarios FULL para calcular recomendaciones.'}
                </div>
            )}

            <div className="rounded-3xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                <Pager links={paginator?.links ?? []} />
            </div>
        </section>
    )
}

export default function Index({
    accounts,
    selectedAccountId,
    stocks,
    connectedGroups,
    recommendations,
    recommendationMeta,
    stats,
    filters,
}) {
    const [search, setSearch] = useState(filters.search ?? '')
    const [syncing, setSyncing] = useState(false)
    const [syncingMlm, setSyncingMlm] = useState(null)

    useEffect(() => setSearch(filters.search ?? ''), [filters.search])

    const selectedAccount = useMemo(
        () => accounts.find((account) => Number(account.id) === Number(selectedAccountId)),
        [accounts, selectedAccountId],
    )

    const navigate = (changes = {}) => {
        router.get(
            '/meli/full',
            {
                ...filters,
                account_id: selectedAccountId,
                search,
                ...changes,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        )
    }

    const syncAll = () => {
        if (!selectedAccountId || syncing) return

        setSyncing(true)
        router.post(
            '/meli/full/sync',
            { account_id: selectedAccountId },
            {
                preserveScroll: true,
                onFinish: () => setSyncing(false),
            },
        )
    }

    const syncOne = (mlm) => {
        if (!selectedAccountId || syncingMlm) return

        setSyncingMlm(mlm)
        router.post(
            `/meli/full/${encodeURIComponent(mlm)}/sync`,
            { account_id: selectedAccountId },
            {
                preserveScroll: true,
                onFinish: () => setSyncingMlm(null),
            },
        )
    }

    const rows = stocks?.data ?? []
    const groups = connectedGroups?.data ?? []
    const recommendationGroups = recommendations?.data ?? []
    const currentSection = filters.section ?? 'all'

    return (
        <AppShell title="Inventario FULL Mercado Libre">
            <div className="space-y-6">
                <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
                        <div>
                            <p className="text-xs font-bold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-300">
                                Bodegas de Mercado Libre
                            </p>
                            <h1 className="mt-2 text-3xl font-black text-slate-900 dark:text-white">
                                Inventario FULL
                            </h1>
                            <p className="mt-2 max-w-3xl text-sm text-slate-500 dark:text-slate-400">
                                Consulta todo el inventario, revisa variantes y productos conectados, detecta
                                agotados y calcula cuánto conviene enviar a FULL según tus ventas.
                            </p>
                        </div>

                        <div className="grid w-full gap-3 sm:grid-cols-2 xl:w-auto xl:grid-cols-[300px_auto_auto]">
                            <div>
                                <label className="mb-2 block text-xs font-bold uppercase tracking-wide text-slate-500">
                                    Cuenta
                                </label>
                                <select
                                    value={selectedAccountId || ''}
                                    onChange={(event) =>
                                        navigate({
                                            account_id: Number(event.target.value),
                                            page: 1,
                                        })
                                    }
                                    className="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 dark:border-neutral-700 dark:bg-neutral-950"
                                >
                                    {accounts.map((account) => (
                                        <option key={account.id} value={account.id}>
                                            {account.nickname} {account.is_default ? '(Principal)' : '(Secundaria)'}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <button
                                type="button"
                                disabled={!selectedAccountId || syncing}
                                onClick={syncAll}
                                className="self-end rounded-2xl bg-indigo-600 px-5 py-3 font-bold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {syncing ? 'Enviando...' : 'Sincronizar FULL'}
                            </button>

                            <button
                                type="button"
                                onClick={() => router.reload({ preserveScroll: true })}
                                className="self-end rounded-2xl border border-slate-300 bg-white px-5 py-3 font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-950 dark:text-slate-200 dark:hover:bg-neutral-800"
                            >
                                Actualizar vista
                            </button>
                        </div>
                    </div>

                    <p className="mt-4 text-xs text-slate-500 dark:text-slate-400">
                        Cuenta seleccionada: <strong>{selectedAccount?.nickname ?? 'Sin cuenta'}</strong> · Última
                        sincronización: <strong>{stats.last_sync_at ?? 'Todavía no sincronizada'}</strong>
                    </p>
                </section>

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-7">
                    <StatCard
                        label="Publicaciones FULL"
                        value={stats.products}
                        detail="Todos los MLM, aunque compartan inventario."
                    />
                    <StatCard
                        label="Publicaciones / variantes"
                        value={stats.rows}
                        detail="Una fila por MLM y por cada variante."
                    />
                    <StatCard
                        label="Inventarios físicos"
                        value={stats.physical_inventories}
                        detail={`${stats.shared_groups ?? 0} grupos están conectados.`}
                    />
                    <StatCard
                        label="Disponible"
                        value={stats.available}
                        detail="Sin duplicar inventarios compartidos."
                    />
                    <StatCard
                        label="No disponible"
                        value={stats.not_available}
                        detail="Sin duplicar inventarios compartidos."
                    />
                    <StatCard
                        label="Total FULL"
                        value={stats.total}
                        detail="Existencia física real, contada una vez."
                    />
                    <StatCard label="Errores" value={stats.errors} detail="Filas cuya última consulta tuvo error." />
                </section>

                <section>
                    <div className="mb-3">
                        <p className="text-xs font-bold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                            Apartados
                        </p>
                        <h2 className="mt-1 text-xl font-black text-slate-900 dark:text-white">
                            Selecciona qué deseas revisar
                        </h2>
                    </div>
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                        <SectionButton
                            active={currentSection === 'all'}
                            label="Todo el inventario"
                            value={stats.rows}
                            detail="Publicaciones sin variante y todas las variantes FULL."
                            onClick={() => navigate({ section: 'all', page: 1 })}
                        />
                        <SectionButton
                            active={currentSection === 'variants'}
                            label="Variantes"
                            value={stats.variant_rows}
                            detail={`${stats.variant_products ?? 0} publicaciones tienen variantes identificadas.`}
                            tone="sky"
                            onClick={() => navigate({ section: 'variants', page: 1 })}
                        />
                        <SectionButton
                            active={currentSection === 'connected'}
                            label="Productos conectados"
                            value={stats.shared_groups}
                            detail={`${stats.connected_rows ?? 0} filas están conectadas a un inventario compartido.`}
                            tone="amber"
                            onClick={() =>
                                navigate({
                                    section: 'connected',
                                    sort: 'connected',
                                    direction: 'desc',
                                    page: 1,
                                })
                            }
                        />
                        <SectionButton
                            active={currentSection === 'out_of_stock'}
                            label="Agotados FULL"
                            value={stats.out_of_stock}
                            detail="Agotados reponibles; excluye bloqueadas, cerradas y en revisión."
                            tone="rose"
                            onClick={() =>
                                navigate({
                                    section: 'out_of_stock',
                                    filter: 'all',
                                    sort: 'available',
                                    direction: 'asc',
                                    page: 1,
                                })
                            }
                        />
                        <SectionButton
                            active={currentSection === 'recommendations'}
                            label="Recomendación de envío"
                            value={currentSection === 'recommendations' ? stats.recommended_products : '—'}
                            detail="Calcula unidades sugeridas usando ventas y cobertura."
                            tone="emerald"
                            onClick={() =>
                                navigate({
                                    section: 'recommendations',
                                    filter: 'all',
                                    recommendation_filter: 'all',
                                    sort: 'recommended',
                                    direction: 'desc',
                                    page: 1,
                                })
                            }
                        />
                    </div>
                </section>

                {!['out_of_stock', 'recommendations'].includes(currentSection) && (
                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    {FILTERS.map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            onClick={() => navigate({ filter: key, page: 1 })}
                            className={`rounded-2xl border p-4 text-left shadow-sm transition ${
                                filters.filter === key
                                    ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-500/10'
                                    : 'border-slate-200 bg-white hover:border-slate-300 dark:border-neutral-800 dark:bg-neutral-900'
                            }`}
                        >
                            <p className="text-sm font-bold text-slate-800 dark:text-slate-100">{label}</p>
                        </button>
                    ))}
                    </section>
                )}

                <section className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <form
                        className="grid gap-3 xl:grid-cols-[1fr_210px_220px_180px_140px_auto]"
                        onSubmit={(event) => {
                            event.preventDefault()
                            navigate({ search, page: 1 })
                        }}
                    >
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Buscar título, SKU, MLM, variante, inventory_id..."
                            className="rounded-2xl border border-slate-300 bg-white px-4 py-3 dark:border-neutral-700 dark:bg-neutral-950"
                        />
                        <select
                            value={currentSection}
                            onChange={(event) => {
                                const section = event.target.value
                                navigate({
                                    section,
                                    filter: 'all',
                                    sort:
                                        section === 'connected'
                                            ? 'connected'
                                            : section === 'recommendations'
                                              ? 'recommended'
                                              : 'available',
                                    direction: section === 'out_of_stock' ? 'asc' : 'desc',
                                    recommendation_filter: 'all',
                                    page: 1,
                                })
                            }}
                            className="rounded-2xl border border-slate-300 bg-white px-4 py-3 dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <option value="all">Todo el inventario</option>
                            <option value="variants">Variantes</option>
                            <option value="connected">Productos conectados</option>
                            <option value="out_of_stock">Agotados FULL</option>
                            <option value="recommendations">Recomendación de envío</option>
                        </select>
                        <select
                            value={currentSection === 'recommendations' ? 'recommended' : filters.sort}
                            disabled={currentSection === 'recommendations'}
                            onChange={(event) => navigate({ sort: event.target.value, page: 1 })}
                            className="rounded-2xl border border-slate-300 bg-white px-4 py-3 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            {currentSection === 'recommendations' ? (
                                <option value="recommended">Cantidad recomendada</option>
                            ) : (
                                <>
                                    <option value="connected">
                                        {currentSection === 'connected'
                                            ? 'Más elementos conectados'
                                            : 'Publicaciones conectadas'}
                                    </option>
                                    <option value="available">Disponible FULL</option>
                                    <option value="unavailable">No disponible</option>
                                    <option value="total">Total FULL</option>
                                    <option value="updated">Actualización</option>
                                    <option value="title">Título</option>
                                </>
                            )}
                        </select>
                        <select
                            value={filters.direction}
                            onChange={(event) => navigate({ direction: event.target.value, page: 1 })}
                            className="rounded-2xl border border-slate-300 bg-white px-4 py-3 dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <option value="desc">
                                {currentSection === 'recommendations'
                                    ? 'Más unidades primero'
                                    : 'Mayor a menor'}
                            </option>
                            <option value="asc">
                                {currentSection === 'recommendations'
                                    ? 'Menos unidades primero'
                                    : 'Menor a mayor'}
                            </option>
                        </select>
                        <select
                            value={filters.per_page}
                            onChange={(event) => navigate({ per_page: Number(event.target.value), page: 1 })}
                            className="rounded-2xl border border-slate-300 bg-white px-4 py-3 dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            {[20, 40, 60, 100].map((value) => (
                                <option key={value} value={value}>
                                    {value} por página
                                </option>
                            ))}
                        </select>
                        <button
                            type="submit"
                            className="rounded-2xl bg-indigo-600 px-5 py-3 font-bold text-white hover:bg-indigo-700"
                        >
                            Buscar
                        </button>
                    </form>
                </section>

                {currentSection === 'connected' ? (
                    <ConnectedInventoryGroups
                        groups={groups}
                        paginator={connectedGroups}
                        syncingMlm={syncingMlm}
                        syncOne={syncOne}
                    />
                ) : currentSection === 'recommendations' ? (
                    <RecommendationGroups
                        groups={recommendationGroups}
                        paginator={recommendations}
                        meta={recommendationMeta}
                        filters={filters}
                        navigate={navigate}
                        selectedAccountId={selectedAccountId}
                    />
                ) : (
                    <StandardInventoryTable
                        rows={rows}
                        stocks={stocks}
                        syncingMlm={syncingMlm}
                        syncOne={syncOne}
                        section={currentSection}
                    />
                )}
            </div>
        </AppShell>
    )
}
