import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'

export default function ShelfAuditIndex({ audits, summary, shelves, filters }) {
    const { flash = {} } = usePage().props
    const [search, setSearch] = useState(filters?.search || '')
    const [shelfCode, setShelfCode] = useState(filters?.shelf_code || '')
    const [status, setStatus] = useState(filters?.status || '')

    const submitSearch = (event) => {
        event.preventDefault()
        router.get(
            '/almacen/auditorias',
            { search, shelf_code: shelfCode, status },
            { preserveState: true, replace: true }
        )
    }

    const resetFilters = () => {
        setSearch('')
        setShelfCode('')
        setStatus('')
        router.get('/almacen/auditorias', {}, { preserveState: true, replace: true })
    }

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

    return (
        <AppShell title="Inventario por Anaquel">
            <Head title="Inventario y Auditoría por Anaquel" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="rounded-lg bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                Control de Stock Físico
                            </span>
                        </div>
                        <h1 className="mt-1.5 text-2xl font-bold tracking-tight text-slate-900 dark:text-white sm:text-3xl">
                            Inventario por Anaquel
                        </h1>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Audita anaqueles completos (ej. I4 incluye I4-1, I4-2, etc.), compara lo contado físicamente contra el sistema y ajusta discrepancias al instante.
                        </p>
                    </div>

                    <Link
                        href="/almacen/auditorias/crear"
                        className="inline-flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 active:scale-95"
                    >
                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        <span>Nuevo Conteo de Anaquel</span>
                    </Link>
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

                {/* KPI Metrics */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-xs font-semibold uppercase text-slate-500 dark:text-neutral-400">Total Auditorías</span>
                        <p className="mt-1 text-2xl font-black text-slate-900 dark:text-white">
                            {summary.total_audits || 0}
                        </p>
                    </div>
                    <div className="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-xs dark:border-emerald-900/40 dark:bg-emerald-950/20">
                        <span className="text-xs font-semibold uppercase text-emerald-700 dark:text-emerald-400">Ajustes Aplicados</span>
                        <p className="mt-1 text-2xl font-black text-emerald-700 dark:text-emerald-300">
                            {summary.applied_audits || 0}
                        </p>
                    </div>
                    <div className="rounded-2xl border border-amber-200 bg-amber-50/50 p-4 shadow-xs dark:border-amber-900/40 dark:bg-amber-950/20">
                        <span className="text-xs font-semibold uppercase text-amber-700 dark:text-amber-400">Borradores</span>
                        <p className="mt-1 text-2xl font-black text-amber-700 dark:text-amber-300">
                            {summary.in_progress_audits || 0}
                        </p>
                    </div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-xs font-semibold uppercase text-slate-500 dark:text-neutral-400">Anaqueles Auditados</span>
                        <p className="mt-1 text-2xl font-black text-slate-900 dark:text-white">
                            {summary.shelves_audited_count || 0}
                        </p>
                    </div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-xs font-semibold uppercase text-slate-500 dark:text-neutral-400">Diferencia Neta (Uds)</span>
                        <p className={`mt-1 text-2xl font-black ${summary.net_difference_units > 0 ? 'text-blue-600' : summary.net_difference_units < 0 ? 'text-rose-600' : 'text-slate-700 dark:text-slate-300'}`}>
                            {summary.net_difference_units > 0 ? `+${summary.net_difference_units}` : summary.net_difference_units}
                        </p>
                    </div>
                </div>

                {/* Filter Bar */}
                <form onSubmit={submitSearch} className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-xs dark:border-neutral-800 dark:bg-neutral-900 sm:flex-row sm:items-center">
                    <div className="min-w-0 flex-1">
                        <input
                            type="text"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Buscar por folio (AUD-...) o anaquel..."
                            className="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                        />
                    </div>
                    <div className="w-full sm:w-64">
                        <select
                            value={shelfCode}
                            onChange={(e) => setShelfCode(e.target.value)}
                            className="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                        >
                            <option value="">Todos los anaqueles</option>
                            {shelves.map((sh) => (
                                <option key={sh.shelf_code} value={sh.shelf_code}>
                                    📍 {sh.display_label}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="w-full sm:w-44">
                        <select
                            value={status}
                            onChange={(e) => setStatus(e.target.value)}
                            className="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                        >
                            <option value="">Todos los estados</option>
                            <option value="applied">Ajustado / Aplicado</option>
                            <option value="in_progress">Borrador / Pendiente</option>
                            <option value="cancelled">Cancelado</option>
                        </select>
                    </div>
                    <div className="flex gap-2">
                        <button
                            type="submit"
                            className="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 dark:bg-white dark:text-slate-900"
                        >
                            Filtrar
                        </button>
                        {(search || shelfCode || status) && (
                            <button
                                type="button"
                                onClick={resetFilters}
                                className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800"
                            >
                                Limpiar
                            </button>
                        )}
                    </div>
                </form>

                {/* Audits Table */}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-neutral-800">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-neutral-950 dark:text-slate-400">
                                <tr>
                                    <th className="px-5 py-4">Folio / Auditoría</th>
                                    <th className="px-5 py-4">Anaquel</th>
                                    <th className="px-5 py-4">Estado</th>
                                    <th className="px-5 py-4 text-center">Items</th>
                                    <th className="px-5 py-4 text-center">Resultado Físico</th>
                                    <th className="px-5 py-4">Fecha / Auditor</th>
                                    <th className="px-5 py-4 text-right">Acción</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                {audits.data.length === 0 ? (
                                    <tr>
                                        <td colSpan="7" className="px-5 py-16 text-center">
                                            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-400 dark:bg-neutral-800">
                                                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                                </svg>
                                            </div>
                                            <p className="mt-3 text-sm font-semibold text-slate-900 dark:text-white">
                                                No hay auditorías registradas
                                            </p>
                                            <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                                Comienza auditando tu primer anaquel (ej. I4, A1, F10) para cuadrar el inventario físico.
                                            </p>
                                            <Link
                                                href="/almacen/auditorias/crear"
                                                className="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-3.5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700"
                                            >
                                                + Iniciar Primer Conteo
                                            </Link>
                                        </td>
                                    </tr>
                                ) : (
                                    audits.data.map((audit) => {
                                        const displayShelf = audit.shelf_code || audit.location?.code

                                        return (
                                            <tr key={audit.id} className="transition hover:bg-slate-50/50 dark:hover:bg-neutral-800/40">
                                                <td className="px-5 py-4 font-mono">
                                                    <Link
                                                        href={`/almacen/auditorias/${audit.id}`}
                                                        className="font-bold text-indigo-600 hover:underline dark:text-indigo-400"
                                                    >
                                                        {audit.audit_number}
                                                    </Link>
                                                    {audit.notes && (
                                                        <p className="mt-0.5 line-clamp-1 font-sans text-xs text-slate-500 dark:text-neutral-400">
                                                            {audit.notes}
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="px-5 py-4">
                                                    <span className="inline-flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 font-mono text-xs font-black text-indigo-900 dark:border-indigo-900/60 dark:bg-indigo-950/40 dark:text-indigo-300">
                                                        📍 Anaquel {displayShelf}
                                                    </span>
                                                    <div className="mt-0.5 text-xs text-slate-500 dark:text-neutral-400 truncate max-w-xs">
                                                        {audit.location?.name}
                                                    </div>
                                                </td>
                                                <td className="px-5 py-4">
                                                    {audit.status === 'applied' && (
                                                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-300">
                                                            <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                                                            Ajuste Aplicado
                                                        </span>
                                                    )}
                                                    {audit.status === 'in_progress' && (
                                                        <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 ring-1 ring-amber-600/20 dark:bg-amber-950/40 dark:text-amber-300">
                                                            <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                                                            Borrador
                                                        </span>
                                                    )}
                                                    {audit.status === 'cancelled' && (
                                                        <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 dark:bg-neutral-800 dark:text-slate-400">
                                                            Cancelado
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-5 py-4 text-center font-bold text-slate-700 dark:text-slate-300">
                                                    {audit.total_expected_products || audit.total_products_count || 0}
                                                </td>
                                                <td className="px-5 py-4 text-center">
                                                    <div className="flex items-center justify-center gap-1.5 text-xs">
                                                        <span
                                                            title="Productos con conteo exacto"
                                                            className="rounded-md bg-emerald-50 px-2 py-0.5 font-bold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
                                                        >
                                                            ✓ {audit.matched_items_count ?? audit.exact_matches_count ?? 0}
                                                        </span>
                                                        {(audit.surplus_items_count > 0 || audit.over_stock_count > 0) && (
                                                            <span
                                                                title="Productos sobrantes (+)"
                                                                className="rounded-md bg-blue-50 px-2 py-0.5 font-bold text-blue-700 dark:bg-blue-950/40 dark:text-blue-300"
                                                            >
                                                                +{(audit.surplus_items_count ?? audit.over_stock_count ?? 0)}
                                                            </span>
                                                        )}
                                                        {(audit.missing_items_count > 0 || audit.short_stock_count > 0) && (
                                                            <span
                                                                title="Productos faltantes (-)"
                                                                className="rounded-md bg-rose-50 px-2 py-0.5 font-bold text-rose-700 dark:bg-rose-950/40 dark:text-rose-300"
                                                            >
                                                                -{(audit.missing_items_count ?? audit.short_stock_count ?? 0)}
                                                            </span>
                                                        )}
                                                    </div>
                                                    <div className="mt-1 text-[11px] font-semibold text-slate-500 dark:text-slate-400">
                                                        Dif. neta:{' '}
                                                        <span className={audit.total_difference_units > 0 ? 'text-blue-600 font-bold' : audit.total_difference_units < 0 ? 'text-rose-600 font-bold' : 'text-slate-600'}>
                                                            {audit.total_difference_units > 0 ? `+${audit.total_difference_units}` : audit.total_difference_units} uds
                                                        </span>
                                                    </div>
                                                </td>
                                                <td className="whitespace-nowrap px-5 py-4 text-xs text-slate-500 dark:text-slate-400">
                                                    <div className="font-medium text-slate-700 dark:text-slate-300">
                                                        {audit.user?.name || 'Sistema'}
                                                    </div>
                                                    <div className="text-[11px]">{formatDate(audit.created_at)}</div>
                                                </td>
                                                <td className="whitespace-nowrap px-5 py-4 text-right">
                                                    <Link
                                                        href={`/almacen/auditorias/${audit.id}`}
                                                        className="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-2xs transition hover:border-slate-300 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200 dark:hover:bg-neutral-700"
                                                    >
                                                        <span>{audit.status === 'in_progress' ? 'Revisar / Aplicar' : 'Ver Detalle'}</span>
                                                        <svg className="h-3.5 w-3.5 text-slate-400" viewBox="0 0 20 20" fill="currentColor">
                                                            <path fillRule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.25 4.5a.75.75 0 01-1.06.02z" clipRule="evenodd" />
                                                        </svg>
                                                    </Link>
                                                </td>
                                            </tr>
                                        )
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Pagination */}
                    {audits.links?.length > 3 && (
                        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-200 p-4 dark:border-neutral-800">
                            <span className="text-xs text-slate-500 dark:text-slate-400">
                                Mostrando {audits.from || 0} a {audits.to || 0} de {audits.total || 0} auditorías
                            </span>
                            <div className="flex flex-wrap gap-1">
                                {audits.links.map((link, idx) =>
                                    link.url ? (
                                        <Link
                                            key={idx}
                                            href={link.url}
                                            preserveState
                                            className={`rounded-lg border px-3 py-1 text-xs font-semibold ${
                                                link.active
                                                    ? 'border-indigo-600 bg-indigo-600 text-white'
                                                    : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-300'
                                            }`}
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                        />
                                    ) : (
                                        <span
                                            key={idx}
                                            className="rounded-lg border border-slate-100 px-3 py-1 text-xs text-slate-400 dark:border-neutral-800"
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                        />
                                    )
                                )}
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AppShell>
    )
}
