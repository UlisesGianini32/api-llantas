import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'
import Pagination from '@/Components/ui/Pagination'
import { formatDateTime } from '@/lib/inventoryPresentation'
import {
    APPLY_STATUS_LABELS,
    DISPATCH_STATUS_LABELS,
    OPERATION_TYPE_LABELS,
    operatingModeMeta,
} from '@/lib/warehouseDispatchScanner'

export default function Index({
    dispatches,
    summary = [],
    destinations = [],
    filters = {},
    operationTypes = [],
    statuses = [],
    applyStatuses = [],
    operatingMode = 'RECORD_ONLY',
    capabilities = {},
}) {
    const [activeTab, setActiveTab] = useState('history')
    const [form, setForm] = useState({
        search: filters.search || '',
        destination_id: filters.destination_id || '',
        operation_type: filters.operation_type || '',
        status: filters.status || '',
        apply_status: filters.apply_status || '',
        brand: filters.brand || '',
        product_search: filters.product_search || '',
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
        only_provisional: Boolean(filters.only_provisional),
        only_discrepancies: Boolean(filters.only_discrepancies),
    })

    const modeMeta = operatingModeMeta(operatingMode)
    const isReadOnly = operatingMode === 'READ_ONLY'

    const applyFilters = (e) => {
        if (e) e.preventDefault()
        const clean = {}
        Object.entries(form).forEach(([k, v]) => {
            if (v !== '' && v !== false && v !== null) clean[k] = v
        })
        router.get('/almacen/salidas', clean, { preserveState: true, replace: true })
    }

    const resetFilters = () => {
        setForm({
            search: '',
            destination_id: '',
            operation_type: '',
            status: '',
            apply_status: '',
            brand: '',
            product_search: '',
            date_from: '',
            date_to: '',
            only_provisional: false,
            only_discrepancies: false,
        })
        router.get('/almacen/salidas', {}, { preserveState: true, replace: true })
    }

    const buildCsvHref = (mode = 'detail') => {
        const params = new URLSearchParams()
        if (mode === 'summary') {
            params.set('mode', 'summary')
        }
        Object.entries(form).forEach(([k, v]) => {
            if (v !== '' && v !== false && v !== null) params.set(k, String(v))
        })
        const qs = params.toString()
        return `/almacen/salidas/exportar-csv${qs ? `?${qs}` : ''}`
    }

    return (
        <AppShell title="Salidas a Salones">
            <Head title="Salidas de Almacén y Surtido a Salones" />

            <div className="space-y-4">
                {/* Operating mode banner */}
                <div className={`rounded-xl border px-4 py-3 text-sm ${modeMeta.badgeClass}`}>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <span className="font-bold">{modeMeta.label}:</span>{' '}
                            <span>{modeMeta.description}</span>
                        </div>
                        <span className="text-xs font-semibold uppercase tracking-wide opacity-80">
                            Mercado Libre Sync: Deshabilitado (Fase 1)
                        </span>
                    </div>
                </div>

                {/* Header & quick navigation */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-bold text-slate-900 dark:text-white">
                            Salidas de Almacén y Surtido a Salones
                        </h1>
                        <p className="text-xs text-slate-500 dark:text-neutral-400">
                            Control de entregas físicas a salones, productos provisionales y vales firmables.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            href="/almacen/salidas/destinos"
                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        >
                            Destinos / Salones
                        </Link>
                        <Link
                            href="/almacen/salidas/provisionales"
                            className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 hover:bg-amber-100 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-300"
                        >
                            Pendientes de vincular
                        </Link>
                        {capabilities.can_export_summary && (
                            <>
                                <a
                                    href={buildCsvHref('detail')}
                                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                >
                                    Exportar CSV
                                </a>
                                <a
                                    href={buildCsvHref('summary')}
                                    className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                                >
                                    CSV Resumen
                                </a>
                            </>
                        )}
                        {capabilities.can_create && !isReadOnly && (
                            <Link
                                href="/almacen/salidas/crear"
                                className="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-emerald-700"
                            >
                                + Nueva salida
                            </Link>
                        )}
                    </div>
                </div>

                {/* Filters */}
                <form
                    onSubmit={applyFilters}
                    className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                >
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <input
                            type="text"
                            value={form.search}
                            onChange={(e) => setForm({ ...form, search: e.target.value })}
                            placeholder="Folio SAL-..., destino o recibe..."
                            className="rounded-lg border border-slate-300 px-3 py-2 text-xs dark:border-neutral-700 dark:bg-neutral-950"
                        />
                        <select
                            value={form.destination_id}
                            onChange={(e) => setForm({ ...form, destination_id: e.target.value })}
                            className="rounded-lg border border-slate-300 px-3 py-2 text-xs dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <option value="">Todos los salones / destinos</option>
                            {destinations.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.name} ({d.code}){!d.is_active ? ' [Inactivo]' : ''}
                                </option>
                            ))}
                        </select>
                        <select
                            value={form.operation_type}
                            onChange={(e) => setForm({ ...form, operation_type: e.target.value })}
                            className="rounded-lg border border-slate-300 px-3 py-2 text-xs dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <option value="">Todos los tipos de salida</option>
                            {operationTypes.map((op) => (
                                <option key={op} value={op}>
                                    {OPERATION_TYPE_LABELS[op] || op}
                                </option>
                            ))}
                        </select>
                        <select
                            value={form.status}
                            onChange={(e) => setForm({ ...form, status: e.target.value })}
                            className="rounded-lg border border-slate-300 px-3 py-2 text-xs dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <option value="">Todos los estados</option>
                            {statuses.map((st) => (
                                <option key={st} value={st}>
                                    {DISPATCH_STATUS_LABELS[st] || st}
                                </option>
                            ))}
                        </select>
                        <select
                            value={form.apply_status}
                            onChange={(e) => setForm({ ...form, apply_status: e.target.value })}
                            className="rounded-lg border border-slate-300 px-3 py-2 text-xs dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <option value="">Estado de inventario (todos)</option>
                            {applyStatuses.map((st) => (
                                <option key={st} value={st}>
                                    {APPLY_STATUS_LABELS[st] || st}
                                </option>
                            ))}
                        </select>
                        <input
                            type="text"
                            value={form.product_search}
                            onChange={(e) => setForm({ ...form, product_search: e.target.value })}
                            placeholder="Producto, SKU, código o tono..."
                            className="rounded-lg border border-slate-300 px-3 py-2 text-xs dark:border-neutral-700 dark:bg-neutral-950"
                        />
                        <input
                            type="text"
                            value={form.brand}
                            onChange={(e) => setForm({ ...form, brand: e.target.value })}
                            placeholder="Marca (ej. Schwarzkopf)"
                            className="rounded-lg border border-slate-300 px-3 py-2 text-xs dark:border-neutral-700 dark:bg-neutral-950"
                        />
                        <input
                            type="date"
                            value={form.date_from}
                            onChange={(e) => setForm({ ...form, date_from: e.target.value })}
                            className="rounded-lg border border-slate-300 px-3 py-2 text-xs dark:border-neutral-700 dark:bg-neutral-950"
                        />
                        <input
                            type="date"
                            value={form.date_to}
                            onChange={(e) => setForm({ ...form, date_to: e.target.value })}
                            className="rounded-lg border border-slate-300 px-3 py-2 text-xs dark:border-neutral-700 dark:bg-neutral-950"
                        />
                        <div className="flex items-center gap-2">
                            <button
                                type="submit"
                                className="flex-1 rounded-lg bg-slate-900 px-3 py-2 text-xs font-bold text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900"
                            >
                                Filtrar
                            </button>
                            <button
                                type="button"
                                onClick={resetFilters}
                                className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:text-neutral-300"
                            >
                                Limpiar
                            </button>
                        </div>
                    </div>
                    <div className="mt-3 flex flex-wrap items-center gap-4 text-xs text-slate-600 dark:text-neutral-300">
                        <label className="inline-flex items-center gap-1.5">
                            <input
                                type="checkbox"
                                checked={form.only_provisional}
                                onChange={(e) => setForm({ ...form, only_provisional: e.target.checked })}
                            />
                            Solo salidas con productos provisionales
                        </label>
                        <label className="inline-flex items-center gap-1.5">
                            <input
                                type="checkbox"
                                checked={form.only_discrepancies}
                                onChange={(e) => setForm({ ...form, only_discrepancies: e.target.checked })}
                            />
                            Solo salidas con discrepancias / conciliación pendiente
                        </label>
                    </div>
                </form>

                {/* Tabs: Historial vs Resumen informativo */}
                <div className="flex items-center gap-2 border-b border-slate-200 dark:border-neutral-800">
                    <button
                        type="button"
                        onClick={() => setActiveTab('history')}
                        className={`border-b-2 px-4 py-2 text-xs font-bold ${
                            activeTab === 'history'
                                ? 'border-emerald-600 text-emerald-700 dark:text-emerald-400'
                                : 'border-transparent text-slate-500'
                        }`}
                    >
                        Historial de Salidas ({dispatches.total ?? dispatches.data?.length ?? 0})
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('summary')}
                        className={`border-b-2 px-4 py-2 text-xs font-bold ${
                            activeTab === 'summary'
                                ? 'border-emerald-600 text-emerald-700 dark:text-emerald-400'
                                : 'border-transparent text-slate-500'
                        }`}
                    >
                        Resumen Informativo por Producto ({summary.length})
                    </button>
                </div>

                {activeTab === 'history' ? (
                    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-xs dark:divide-neutral-800">
                                <thead className="bg-slate-50 dark:bg-neutral-950">
                                    <tr>
                                        <th className="px-3 py-2.5 text-left font-bold text-slate-600">Folio</th>
                                        <th className="px-3 py-2.5 text-left font-bold text-slate-600">Fecha efectiva</th>
                                        <th className="px-3 py-2.5 text-left font-bold text-slate-600">Salón / Destino</th>
                                        <th className="px-3 py-2.5 text-left font-bold text-slate-600">Tipo</th>
                                        <th className="px-3 py-2.5 text-left font-bold text-slate-600">Estado</th>
                                        <th className="px-3 py-2.5 text-right font-bold text-slate-600">Piezas / Partidas</th>
                                        <th className="px-3 py-2.5 text-left font-bold text-slate-600">Alertas</th>
                                        <th className="px-3 py-2.5 text-right font-bold text-slate-600">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                    {(dispatches.data || []).length === 0 ? (
                                        <tr>
                                            <td colSpan={8} className="px-4 py-8 text-center text-slate-500">
                                                No se encontraron salidas con los filtros aplicados.
                                            </td>
                                        </tr>
                                    ) : (
                                        dispatches.data.map((d) => (
                                            <tr key={d.id} className="hover:bg-slate-50 dark:hover:bg-neutral-800/40">
                                                <td className="px-3 py-2.5 font-mono font-bold text-slate-900 dark:text-white">
                                                    <Link href={`/almacen/salidas/${d.id}`} className="hover:underline">
                                                        {d.dispatch_number}
                                                    </Link>
                                                </td>
                                                <td className="px-3 py-2.5 text-slate-700 dark:text-neutral-300">
                                                    {formatDateTime(d.effective_at)}
                                                    {d.is_retroactive && (
                                                        <span className="ml-1 rounded bg-purple-100 px-1.5 py-0.5 text-[10px] font-bold text-purple-700">
                                                            Retroactiva
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2.5">
                                                    <div className="font-semibold text-slate-900 dark:text-white">
                                                        {d.destination_name}
                                                    </div>
                                                    <div className="text-[11px] text-slate-500">
                                                        {d.destination_code} · Recibe: {d.received_by_name || '—'}
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2.5">
                                                    {OPERATION_TYPE_LABELS[d.operation_type] || d.operation_type}
                                                </td>
                                                <td className="px-3 py-2.5">
                                                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-700 dark:bg-neutral-800 dark:text-neutral-200">
                                                        {DISPATCH_STATUS_LABELS[d.status] || d.status}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-2.5 text-right">
                                                    <div className="font-bold">{d.total_physical_units} pzas</div>
                                                    <div className="text-[11px] text-slate-500">
                                                        {d.total_lines_count} partida(s)
                                                        {d.unconverted_containers_count > 0 && (
                                                            <span className="ml-1 text-amber-600">
                                                                + {d.unconverted_containers_count} cont. sin conv.
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2.5">
                                                    <div className="flex flex-wrap gap-1">
                                                        {d.provisional_items_count > 0 && (
                                                            <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-800">
                                                                {d.provisional_items_count} prov.
                                                            </span>
                                                        )}
                                                        {d.discrepancy_items_count > 0 && (
                                                            <span className="rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-bold text-rose-800">
                                                                {d.discrepancy_items_count} disc./concil.
                                                            </span>
                                                        )}
                                                        {d.pending_apply_items_count > 0 && (
                                                            <span className="rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-bold text-sky-800">
                                                                {d.pending_apply_items_count} sin desc.
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2.5 text-right">
                                                    <div className="flex justify-end gap-2">
                                                        <Link
                                                            href={`/almacen/salidas/${d.id}`}
                                                            className="font-semibold text-emerald-700 hover:underline dark:text-emerald-400"
                                                        >
                                                            Ver
                                                        </Link>
                                                        <a
                                                            href={`/almacen/salidas/${d.id}/vale-pdf`}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="font-semibold text-slate-600 hover:underline dark:text-neutral-300"
                                                        >
                                                            Vale PDF
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {dispatches.links && <Pagination links={dispatches.links} />}
                    </div>
                ) : (
                    <div className="space-y-2 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <p className="text-xs text-slate-600 dark:text-neutral-300">
                            <strong>Consulta informativa (Fase 1):</strong> Cantidades entregadas en salidas confirmadas agrupadas por producto o identidad provisional canónica y tipo de salida. No alimenta automáticamente el cálculo de pedidos a proveedores todavía (Fase 3) ni suma cajas sin conversión con piezas físicas.
                        </p>
                        <div className="overflow-x-auto">
                            <table className="min-w-full divide-y divide-slate-200 text-xs dark:divide-neutral-800">
                                <thead className="bg-slate-50 dark:bg-neutral-950">
                                    <tr>
                                        <th className="px-3 py-2 text-left font-bold">Origen</th>
                                        <th className="px-3 py-2 text-left font-bold">SKU / Código / Barras</th>
                                        <th className="px-3 py-2 text-left font-bold">Producto / Marca / Tono / Pres.</th>
                                        <th className="px-3 py-2 text-left font-bold">Salón(es)</th>
                                        <th className="px-3 py-2 text-left font-bold">Tipo de salida</th>
                                        <th className="px-3 py-2 text-right font-bold">Salidas</th>
                                        <th className="px-3 py-2 text-right font-bold">Piezas físicas</th>
                                        <th className="px-3 py-2 text-right font-bold">Contenedores sin conv.</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                    {summary.length === 0 ? (
                                        <tr>
                                            <td colSpan={8} className="px-3 py-6 text-center text-slate-500">
                                                Sin entregas confirmadas para los filtros actuales.
                                            </td>
                                        </tr>
                                    ) : (
                                        summary.map((row, idx) => (
                                            <tr key={`${row.item_kind}-${row.inventory_product_id || row.canonical_provisional_id}-${row.operation_type}-${idx}`}>
                                                <td className="px-3 py-2">
                                                    <span className={`rounded px-1.5 py-0.5 text-[10px] font-bold ${
                                                        row.item_kind === 'REGISTERED'
                                                            ? 'bg-emerald-100 text-emerald-800'
                                                            : 'bg-amber-100 text-amber-800'
                                                    }`}>
                                                        {row.item_kind === 'REGISTERED' ? 'Catálogo' : 'Provisional'}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-2 font-mono">
                                                    <div>{row.display_code || '—'}</div>
                                                    {row.display_barcode && (
                                                        <div className="text-[10px] text-slate-500">{row.display_barcode}</div>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2">
                                                    <div className="font-semibold">{row.display_name}</div>
                                                    <div className="text-[11px] text-slate-500">
                                                        {[
                                                            row.display_brand,
                                                            row.display_tone ? `Tono ${row.display_tone}` : null,
                                                            row.display_presentation,
                                                        ].filter(Boolean).join(' · ') || '—'}
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2 text-[11px] text-slate-600 dark:text-neutral-300">
                                                    {row.destinations_summary || '—'}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {OPERATION_TYPE_LABELS[row.operation_type] || row.operation_type}
                                                </td>
                                                <td className="px-3 py-2 text-right">{row.dispatches_count}</td>
                                                <td className="px-3 py-2 text-right font-bold">{row.total_physical_units}</td>
                                                <td className="px-3 py-2 text-right text-amber-700">
                                                    {row.unconverted_containers_qty > 0 ? row.unconverted_containers_qty : '—'}
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </AppShell>
    )
}
