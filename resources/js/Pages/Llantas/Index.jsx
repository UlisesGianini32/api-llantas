import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router } from '@inertiajs/react'
import { useMemo, useState } from 'react'

function formatMoney(value) {
    const amount = Number(value || 0)
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    }).format(amount)
}

function SortableHeader({ label, column, filters, onSort }) {
    const active = filters.sort === column
    const direction = active ? filters.dir : null

    return (
        <button
            type="button"
            onClick={() => onSort(column)}
            className="inline-flex items-center gap-1 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500 transition hover:text-slate-800 dark:text-slate-400 dark:hover:text-white"
        >
            <span>{label}</span>
            <span className="text-[10px]">
                {active ? (direction === 'asc' ? '▲' : '▼') : '↕'}
            </span>
        </button>
    )
}

function Badge({ children, tone = 'slate' }) {
    const styles = {
        slate: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
        emerald: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        amber: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
        rose: 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        sky: 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
        indigo: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300',
    }

    return (
        <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${styles[tone]}`}>
            {children}
        </span>
    )
}

function StatCard({ title, value, subtitle, tone = 'slate' }) {
    const tones = {
        slate: 'from-slate-500 to-slate-700',
        emerald: 'from-emerald-500 to-teal-600',
        amber: 'from-amber-500 to-orange-600',
        rose: 'from-rose-500 to-pink-600',
        sky: 'from-sky-500 to-cyan-600',
        indigo: 'from-indigo-500 to-violet-600',
    }

    return (
        <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                {title}
            </p>
            <p className="mt-3 text-3xl font-bold text-slate-900 dark:text-white">
                {value}
            </p>
            <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                {subtitle}
            </p>
            <div className={`mt-4 h-1.5 rounded-full bg-gradient-to-r ${tones[tone]}`} />
        </div>
    )
}

function Pagination({ links }) {
    if (!links || links.length <= 3) return null

    return (
        <div className="mt-6 flex flex-wrap items-center gap-2">
            {links.map((link, index) => {
                const label = link.label
                    .replace('&laquo; Previous', 'Anterior')
                    .replace('Next &raquo;', 'Siguiente')

                if (!link.url) {
                    return (
                        <span
                            key={index}
                            className="rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-400 dark:border-neutral-800 dark:text-slate-600"
                            dangerouslySetInnerHTML={{ __html: label }}
                        />
                    )
                }

                return (
                    <button
                        key={index}
                        type="button"
                        onClick={() =>
                            router.visit(link.url, {
                                preserveScroll: true,
                                preserveState: true,
                            })
                        }
                        className={`rounded-xl border px-4 py-2 text-sm transition ${
                            link.active
                                ? 'border-indigo-500 bg-indigo-600 text-white'
                                : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-neutral-800 dark:bg-neutral-900 dark:text-slate-300 dark:hover:bg-neutral-800'
                        }`}
                        dangerouslySetInnerHTML={{ __html: label }}
                    />
                )
            })}
        </div>
    )
}

function MlTab({ label, value, active, count, onClick }) {
    return (
        <button
            type="button"
            onClick={() => onClick(value)}
            className={`inline-flex items-center gap-2 rounded-2xl px-4 py-3 text-sm font-semibold transition ${
                active
                    ? 'bg-indigo-600 text-white'
                    : 'bg-white text-slate-700 hover:bg-slate-50 dark:bg-neutral-900 dark:text-slate-200 dark:hover:bg-neutral-800'
            }`}
        >
            <span>{label}</span>
            <span
                className={`rounded-full px-2 py-0.5 text-xs ${
                    active
                        ? 'bg-white/20 text-white'
                        : 'bg-slate-100 text-slate-600 dark:bg-neutral-800 dark:text-slate-300'
                }`}
            >
                {count}
            </span>
        </button>
    )
}

export default function Index({ llantas, filters, tabCounts }) {
    const [search, setSearch] = useState(filters.search || '')
    const [syncing, setSyncing] = useState(false)
    const [refreshingId, setRefreshingId] = useState(null)
    const [republishingId, setRepublishingId] = useState(null)

    const stats = useMemo(() => {
        const rows = llantas?.data || []

        const totalStock = rows.reduce((sum, item) => sum + Number(item.stock || 0), 0)
        const noStock = rows.filter((item) => Number(item.stock || 0) <= 0).length
        const active = rows.filter((item) => item.ml_status_key === 'activa').length
        const inventoryValue = rows.reduce((sum, item) => {
            const price = Number(item.precio_ML || 0)
            const stock = Number(item.stock || 0)
            return sum + price * stock
        }, 0)

        return {
            totalStock,
            noStock,
            active,
            inventoryValue,
        }
    }, [llantas])

    const applyFilters = (extra = {}) => {
        router.get(
            '/llantas',
            {
                search,
                per_page: extra.per_page ?? filters.per_page ?? 25,
                sort: extra.sort ?? filters.sort ?? 'sku',
                dir: extra.dir ?? filters.dir ?? 'asc',
                ml_status: extra.ml_status ?? filters.ml_status ?? '',
            },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            }
        )
    }

    const handleSearchSubmit = (e) => {
        e.preventDefault()
        applyFilters()
    }

    const handleSort = (column) => {
        const nextDir =
            filters.sort === column && filters.dir === 'asc' ? 'desc' : 'asc'

        applyFilters({
            sort: column,
            dir: nextDir,
        })
    }

    const handleSync = () => {
        setSyncing(true)

        router.post(
            '/meli/sync-manual',
            {},
            {
                preserveScroll: true,
                onFinish: () => setSyncing(false),
            }
        )
    }

    const handleRefreshStatus = (row) => {
        if (!row?.latest_publication?.id || !row?.actions?.refresh_status_url) return

        setRefreshingId(row.id)

        router.post(
            row.actions.refresh_status_url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setRefreshingId(null),
            }
        )
    }

    const handleRepublish = (row) => {
        if (!row?.actions?.republish_url) return

        setRepublishingId(row.id)

        router.post(
            row.actions.republish_url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setRepublishingId(null),
            }
        )
    }

    const statusTone = (key) => {
        if (key === 'activa') return 'emerald'
        if (key === 'pausada') return 'amber'
        if (key === 'en_revision') return 'sky'
        if (key === 'no_publicada') return 'slate'
        return 'rose'
    }

    return (
        <>
            <Head title="Llantas" />

            <AppShell title="Llantas">
                <div className="space-y-6">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-400">
                            Inventario ML
                        </p>
                        <h2 className="mt-2 text-3xl font-bold text-slate-900 dark:text-white">
                            Inventario de llantas
                        </h2>
                        <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                            Consulta, filtra y administra tus llantas con integración a Mercado Libre.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <StatCard
                            title="Llantas en vista"
                            value={llantas.total ?? 0}
                            subtitle="Total de registros encontrados."
                            tone="indigo"
                        />
                        <StatCard
                            title="Publicaciones activas"
                            value={stats.active}
                            subtitle="Registros activos en la página actual."
                            tone="emerald"
                        />
                        <StatCard
                            title="Stock total"
                            value={stats.totalStock}
                            subtitle="Piezas disponibles en esta vista."
                            tone="sky"
                        />
                        <StatCard
                            title="Valor estimado"
                            value={formatMoney(stats.inventoryValue)}
                            subtitle="Precio ML × stock en la página actual."
                            tone="amber"
                        />
                    </div>

                    <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="grid grid-cols-1 gap-6 xl:grid-cols-12">
                            <div className="xl:col-span-7">
                                <form onSubmit={handleSearchSubmit}>
                                    <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                        Buscador
                                    </label>
                                    <div className="relative">
                                        <input
                                            type="text"
                                            value={search}
                                            onChange={(e) => setSearch(e.target.value)}
                                            placeholder="Buscar por SKU, título o MLM..."
                                            className="w-full rounded-2xl border border-slate-300 bg-white px-5 py-4 pl-12 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white dark:focus:border-indigo-400 dark:focus:ring-indigo-500/20"
                                        />
                                        <svg
                                            className="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-slate-400"
                                            viewBox="0 0 20 20"
                                            fill="currentColor"
                                        >
                                            <path
                                                fillRule="evenodd"
                                                d="M9 3.5a5.5 5.5 0 103.473 9.766l3.63 3.631a.75.75 0 101.06-1.06l-3.63-3.632A5.5 5.5 0 009 3.5zM5 9a4 4 0 118 0 4 4 0 01-8 0z"
                                                clipRule="evenodd"
                                            />
                                        </svg>
                                    </div>
                                </form>
                            </div>

                            <div className="xl:col-span-5">
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                            Por página
                                        </label>
                                        <select
                                            value={filters.per_page || 25}
                                            onChange={(e) =>
                                                applyFilters({ per_page: Number(e.target.value) })
                                            }
                                            className="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white dark:focus:border-indigo-400 dark:focus:ring-indigo-500/20"
                                        >
                                            {[10, 25, 50, 100, 250].map((value) => (
                                                <option key={value} value={value}>
                                                    {value}
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    <div className="flex items-end">
                                        <button
                                            type="button"
                                            onClick={handleSync}
                                            disabled={syncing}
                                            className="inline-flex w-full items-center justify-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {syncing ? 'Sincronizando...' : 'Sincronizar con ML'}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div className="xl:col-span-12">
                                <label className="mb-3 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Filtro de Mercado Libre
                                </label>

                                <div className="flex flex-wrap gap-3">
                                    <MlTab
                                        label="Todas"
                                        value=""
                                        active={(filters.ml_status || '') === ''}
                                        count={tabCounts.todas}
                                        onClick={(value) => applyFilters({ ml_status: value })}
                                    />
                                    <MlTab
                                        label="No publicada"
                                        value="no_publicada"
                                        active={filters.ml_status === 'no_publicada'}
                                        count={tabCounts.no_publicada}
                                        onClick={(value) => applyFilters({ ml_status: value })}
                                    />
                                    <MlTab
                                        label="Activa"
                                        value="activa"
                                        active={filters.ml_status === 'activa'}
                                        count={tabCounts.activa}
                                        onClick={(value) => applyFilters({ ml_status: value })}
                                    />
                                    <MlTab
                                        label="Pausada"
                                        value="pausada"
                                        active={filters.ml_status === 'pausada'}
                                        count={tabCounts.pausada}
                                        onClick={(value) => applyFilters({ ml_status: value })}
                                    />
                                    <MlTab
                                        label="En revisión"
                                        value="en_revision"
                                        active={filters.ml_status === 'en_revision'}
                                        count={tabCounts.en_revision}
                                        onClick={(value) => applyFilters({ ml_status: value })}
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead className="sticky top-0 z-10 bg-slate-50 dark:bg-neutral-950">
                                    <tr className="border-b border-slate-200 dark:border-neutral-800">
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader label="SKU" column="sku" filters={filters} onSort={handleSort} />
                                        </th>
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader label="Marca" column="marca" filters={filters} onSort={handleSort} />
                                        </th>
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader label="Medida" column="medida" filters={filters} onSort={handleSort} />
                                        </th>
                                        <th className="px-4 py-4 text-left">
                                            <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                                Estatus ML
                                            </span>
                                        </th>
                                        <th className="px-4 py-4 text-left">
                                            <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                                Publicaciones
                                            </span>
                                        </th>
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader label="Stock" column="stock" filters={filters} onSort={handleSort} />
                                        </th>
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader label="Descripción" column="description" filters={filters} onSort={handleSort} />
                                        </th>
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader label="Costo" column="costo" filters={filters} onSort={handleSort} />
                                        </th>
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader label="Precio ML" column="precio_ML" filters={filters} onSort={handleSort} />
                                        </th>
                                        <th className="px-4 py-4 text-left">
                                            <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                                Título
                                            </span>
                                        </th>
                                        <th className="px-4 py-4 text-left">
                                            <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                                Acción
                                            </span>
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    {llantas.data.length === 0 ? (
                                        <tr>
                                            <td colSpan="11" className="px-6 py-16 text-center text-slate-500 dark:text-slate-400">
                                                No se encontraron llantas con los filtros actuales.
                                            </td>
                                        </tr>
                                    ) : (
                                        llantas.data.map((row) => (
                                            <tr
                                                key={row.id}
                                                className="border-b border-slate-100 align-top transition hover:bg-slate-50/60 dark:border-neutral-800 dark:hover:bg-neutral-800/40"
                                            >
                                                <td className="px-4 py-4 font-medium text-indigo-600 dark:text-indigo-400">
                                                    {row.sku || '—'}
                                                </td>

                                                <td className="px-4 py-4 text-slate-700 dark:text-slate-300">
                                                    {row.marca || '—'}
                                                </td>

                                                <td className="px-4 py-4 text-slate-700 dark:text-slate-300">
                                                    {row.medida || '—'}
                                                </td>

                                                <td className="px-4 py-4">
                                                    <Badge tone={statusTone(row.ml_status_key)}>
                                                        {row.ml_status}
                                                    </Badge>
                                                </td>

                                                <td className="px-4 py-4">
                                                    <div className="space-y-1">
                                                        {row.publications?.length > 0 ? (
                                                            row.publications.map((pub) => (
                                                                <div key={pub.id}>
                                                                    <span className="text-indigo-600 dark:text-indigo-400">
                                                                        {pub.mlm}
                                                                    </span>
                                                                </div>
                                                            ))
                                                        ) : (
                                                            <span className="text-slate-400">—</span>
                                                        )}
                                                    </div>
                                                </td>

                                                <td className="px-4 py-4">
                                                    <span
                                                        className={`font-bold ${
                                                            Number(row.stock) > 0
                                                                ? 'text-emerald-600'
                                                                : 'text-rose-600'
                                                        }`}
                                                    >
                                                        {row.stock}
                                                    </span>
                                                </td>

                                                <td className="px-4 py-4">
                                                    <div className="max-w-[220px] text-slate-700 dark:text-slate-300">
                                                        {row.description || '—'}
                                                    </div>
                                                </td>

                                                <td className="px-4 py-4 text-slate-700 dark:text-slate-300">
                                                    {formatMoney(row.costo)}
                                                </td>

                                                <td className="px-4 py-4 font-semibold text-emerald-600">
                                                    {formatMoney(row.precio_ML)}
                                                </td>

                                                <td className="px-4 py-4">
                                                    <div className="max-w-[240px] text-slate-700 dark:text-slate-300">
                                                        {row.title_familyname || row.description || '—'}
                                                    </div>
                                                </td>

                                                <td className="px-4 py-4">
                                                    <div className="flex min-w-[130px] flex-col gap-2">
                                                        <Link
                                                            href={row.actions.edit_url}
                                                            className="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-950 dark:text-slate-200 dark:hover:bg-neutral-800"
                                                        >
                                                            Editar
                                                        </Link>

                                                        {row.actions.refresh_status_url && (
                                                            <button
                                                                type="button"
                                                                onClick={() => handleRefreshStatus(row)}
                                                                disabled={refreshingId === row.id}
                                                                className="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-950 dark:text-slate-200 dark:hover:bg-neutral-800"
                                                            >
                                                                {refreshingId === row.id ? 'Actualizando...' : 'Status'}
                                                            </button>
                                                        )}

                                                        {row.latest_publication && row.actions.republish_url && (
                                                            <button
                                                                type="button"
                                                                onClick={() => handleRepublish(row)}
                                                                disabled={republishingId === row.id}
                                                                className="inline-flex items-center justify-center rounded-xl bg-rose-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                            >
                                                                {republishingId === row.id ? 'Republicando...' : 'Republicar'}
                                                            </button>
                                                        )}

                                                        {row.actions.publish_form_url && (
                                                            <Link
                                                                href={row.actions.publish_form_url}
                                                                className="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                                                            >
                                                                Publicar
                                                            </Link>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <div className="border-t border-slate-200 px-6 py-4 dark:border-neutral-800">
                            <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                                <div className="text-sm text-slate-500 dark:text-slate-400">
                                    Mostrando{' '}
                                    <span className="font-semibold text-slate-700 dark:text-slate-200">
                                        {llantas.from ?? 0}
                                    </span>{' '}
                                    a{' '}
                                    <span className="font-semibold text-slate-700 dark:text-slate-200">
                                        {llantas.to ?? 0}
                                    </span>{' '}
                                    de{' '}
                                    <span className="font-semibold text-slate-700 dark:text-slate-200">
                                        {llantas.total}
                                    </span>{' '}
                                    llantas
                                </div>

                                <Pagination links={llantas.links} />
                            </div>
                        </div>
                    </div>
                </div>
            </AppShell>
        </>
    )
}