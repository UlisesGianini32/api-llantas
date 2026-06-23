import AppShell from '@/Components/layout/AppShell'
import Pagination from '@/Components/ui/Pagination'
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
        purple: 'bg-purple-100 text-purple-700 dark:bg-purple-500/10 dark:text-purple-300',
    }

    return (
        <span className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${styles[tone]}`}>
            {children}
        </span>
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

export default function Index({ compuestos, filters, tabCounts }) {
    const [search, setSearch] = useState(filters.search || '')
    const [refreshingId, setRefreshingId] = useState(null)
    const [republishingId, setRepublishingId] = useState(null)

    const stats = useMemo(() => {
        const rows = compuestos?.data || []

        const totalStock = rows.reduce((sum, item) => sum + Number(item.stock || 0), 0)
        const active = rows.filter((item) => item.ml_status_key === 'activa').length
        const inventoryValue = rows.reduce((sum, item) => {
            const price = Number(item.precio_ML || 0)
            const stock = Number(item.stock || 0)
            return sum + price * stock
        }, 0)

        return {
            totalStock,
            active,
            inventoryValue,
        }
    }, [compuestos])

    const applyFilters = (extra = {}) => {
        router.get(
            '/productos',
            {
                search,
                per_page: extra.per_page ?? filters.per_page ?? 25,
                sort: extra.sort ?? filters.sort ?? 'id',
                dir: extra.dir ?? filters.dir ?? 'desc',
                ml_status: extra.ml_status ?? filters.ml_status ?? 'all',
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
        if (key === 'en_revision') return 'purple'
        if (key === 'no_publicada') return 'slate'
        return 'rose'
    }

    return (
        <>
            <Head title="Productos compuestos" />

            <AppShell title="Productos compuestos">
                <div className="space-y-6">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-400">
                            Inventario ML
                        </p>
                        <h2 className="mt-2 text-3xl font-bold text-slate-900 dark:text-white">
                            Productos compuestos
                        </h2>
                        <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                            Consulta, filtra y administra pares y juegos de llantas.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                                Compuestos en vista
                            </p>
                            <p className="mt-3 text-3xl font-bold text-slate-900 dark:text-white">
                                {compuestos.total ?? 0}
                            </p>
                        </div>

                        <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                                Publicaciones activas
                            </p>
                            <p className="mt-3 text-3xl font-bold text-slate-900 dark:text-white">
                                {stats.active}
                            </p>
                        </div>

                        <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                                Total de existencias
                            </p>
                            <p className="mt-3 text-3xl font-bold text-slate-900 dark:text-white">
                                {stats.totalStock}
                            </p>
                        </div>

                        <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                                Valor estimado
                            </p>
                            <p className="mt-3 text-3xl font-bold text-slate-900 dark:text-white">
                                {formatMoney(stats.inventoryValue)}
                            </p>
                        </div>
                    </div>

                    <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="grid grid-cols-1 gap-6 xl:grid-cols-12">
                            <div className="xl:col-span-8">
                                <form onSubmit={handleSearchSubmit}>
                                    <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                        Buscador
                                    </label>
                                    <input
                                        type="text"
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        placeholder="Buscar por SKU, título o MLM..."
                                        className="w-full rounded-2xl border border-slate-300 bg-white px-5 py-4 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white dark:focus:border-indigo-400 dark:focus:ring-indigo-500/20"
                                    />
                                </form>
                            </div>

                            <div className="xl:col-span-4">
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
                                    {[10, 25, 50, 100, 200].map((value) => (
                                        <option key={value} value={value}>
                                            {value}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="xl:col-span-12">
                                <label className="mb-3 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Filtro de Mercado Libre
                                </label>

                                <div className="flex flex-wrap gap-3">
                                    <MlTab
                                        label="Todas"
                                        value="all"
                                        active={(filters.ml_status || 'all') === 'all'}
                                        count={tabCounts.all}
                                        onClick={(value) => applyFilters({ ml_status: value })}
                                    />
                                    <MlTab
                                        label="No publicado"
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
                                            <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Marca</span>
                                        </th>
                                        <th className="px-4 py-4 text-left">
                                            <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Medida</span>
                                        </th>
                                        <th className="px-4 py-4 text-left">
                                            <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Estado ML</span>
                                        </th>
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader label="Publicaciones" column="meli_pubs_count" filters={filters} onSort={handleSort} />
                                        </th>
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader label="Descripción" column="descripcion" filters={filters} onSort={handleSort} />
                                        </th>
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader label="Costo" column="costo" filters={filters} onSort={handleSort} />
                                        </th>
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader label="Precio ML" column="precio_ML" filters={filters} onSort={handleSort} />
                                        </th>
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader label="Título" column="title_familyname" filters={filters} onSort={handleSort} />
                                        </th>
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader label="Existencias" column="stock" filters={filters} onSort={handleSort} />
                                        </th>
                                        <th className="px-4 py-4 text-left">
                                            <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Acción</span>
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    {compuestos.data.length === 0 ? (
                                        <tr>
                                            <td colSpan="11" className="px-6 py-16 text-center text-slate-500 dark:text-slate-400">
                                                No se encontraron productos con ese filtro.
                                            </td>
                                        </tr>
                                    ) : (
                                        compuestos.data.map((row) => (
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
                                                    {row.publications?.length === 0 ? (
                                                        <span className="text-slate-400">—</span>
                                                    ) : row.publications.length <= 2 ? (
                                                        <div className="space-y-1">
                                                            {row.publications.map((pub) => (
                                                                <a
                                                                    key={pub.id}
                                                                    href={pub.permalink || '#'}
                                                                    target="_blank"
                                                                    rel="noreferrer"
                                                                    className="block text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
                                                                >
                                                                    {pub.mlm}
                                                                </a>
                                                            ))}
                                                        </div>
                                                    ) : (
                                                        <details className="group">
                                                            <summary className="cursor-pointer text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300">
                                                                {row.meli_pubs_count} publicaciones
                                                            </summary>
                                                            <div className="mt-2 space-y-1">
                                                                {row.publications.map((pub) => (
                                                                    <div key={pub.id} className="flex items-center justify-between gap-2">
                                                                        <a
                                                                            href={pub.permalink || '#'}
                                                                            target="_blank"
                                                                            rel="noreferrer"
                                                                            className="text-xs text-indigo-600 hover:text-indigo-500 dark:text-indigo-400 dark:hover:text-indigo-300"
                                                                        >
                                                                            {pub.mlm}
                                                                        </a>
                                                                        <span className="text-[11px] text-slate-500">
                                                                            {pub.status || '—'}
                                                                        </span>
                                                                    </div>
                                                                ))}
                                                            </div>
                                                        </details>
                                                    )}
                                                </td>

                                                <td className="px-4 py-4 text-slate-700 dark:text-slate-300">
                                                    <div className="max-w-[220px]">
                                                        {row.descripcion || '—'}
                                                    </div>
                                                </td>

                                                <td className="px-4 py-4 text-slate-700 dark:text-slate-300">
                                                    {formatMoney(row.costo)}
                                                </td>

                                                <td className="px-4 py-4 font-semibold text-emerald-600">
                                                    {formatMoney(row.precio_ML)}
                                                </td>

                                                <td className="px-4 py-4 text-slate-700 dark:text-slate-300">
                                                    <div className="max-w-[240px]">
                                                        {row.title_familyname || '—'}
                                                    </div>
                                                </td>

                                                <td className="px-4 py-4">
                                                    <span
                                                        className={`font-bold ${
                                                            Number(row.stock) <= 4
                                                                ? 'text-rose-600'
                                                                : 'text-emerald-600'
                                                        }`}
                                                    >
                                                        {row.stock}
                                                    </span>
                                                </td>

                                                <td className="px-4 py-4">
                                                    <div className="flex min-w-[130px] flex-col gap-2">
                                                        <Link
                                                            href={row.actions.edit_url}
                                                            className="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-950 dark:text-slate-200 dark:hover:bg-neutral-800"
                                                        >
                                                            Editor
                                                        </Link>

                                                        {row.actions.refresh_status_url ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => handleRefreshStatus(row)}
                                                                disabled={refreshingId === row.id}
                                                                className="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-950 dark:text-slate-200 dark:hover:bg-neutral-800"
                                                            >
                                                                {refreshingId === row.id ? 'Actualizando...' : 'Estado'}
                                                            </button>
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                disabled
                                                                className="inline-flex cursor-not-allowed items-center justify-center rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-400 dark:border-neutral-800 dark:bg-neutral-950 dark:text-slate-600"
                                                            >
                                                                Estado
                                                            </button>
                                                        )}

                                                        {row.latest_publication && row.actions.republish_url ? (
                                                            <button
                                                                type="button"
                                                                onClick={() => handleRepublish(row)}
                                                                disabled={republishingId === row.id}
                                                                className="inline-flex items-center justify-center rounded-xl bg-rose-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-60"
                                                            >
                                                                {republishingId === row.id ? 'Republicando...' : 'Republicar'}
                                                            </button>
                                                        ) : (
                                                            <button
                                                                type="button"
                                                                disabled
                                                                className="inline-flex cursor-not-allowed items-center justify-center rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-medium text-slate-400 dark:border-neutral-800 dark:bg-neutral-950 dark:text-slate-600"
                                                            >
                                                                Republicar
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
                                        {compuestos.from ?? 0}
                                    </span>{' '}
                                    a{' '}
                                    <span className="font-semibold text-slate-700 dark:text-slate-200">
                                        {compuestos.to ?? 0}
                                    </span>{' '}
                                    de{' '}
                                    <span className="font-semibold text-slate-700 dark:text-slate-200">
                                        {compuestos.total}
                                    </span>{' '}
                                    productos compuestos
                                </div>

                                <Pagination className="mt-6" links={compuestos.links} />
                            </div>
                        </div>
                    </div>
                </div>
            </AppShell>
        </>
    )
}