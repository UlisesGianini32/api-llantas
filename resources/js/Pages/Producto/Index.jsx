import AppShell from '@/Components/layout/AppShell'
import Pagination from '@/Components/ui/Pagination'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { useEffect, useMemo, useState } from 'react'

const SHOPIFY_RESOLVER_PENDING_UNTIL_KEY = 'producto_shopify_resolver_pending_until'
/** Tiempo máximo para mostrar el aviso “en curso” (ms); el trabajo puede seguir después. */
const RESOLVER_BANNER_TTL_MS = 90 * 60 * 1000

function formatMoney(value) {
    const amount = Number(value || 0)
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    }).format(amount)
}

function SortableHeader({ label, column, filters, onSort, className = '' }) {
    const active = filters.sort === column
    const direction = active ? filters.dir : null

    return (
        <button
            type="button"
            onClick={() => onSort(column)}
            className={`inline-flex items-center gap-1 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500 transition hover:text-slate-800 dark:text-slate-400 dark:hover:text-white ${className}`}
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
        indigo: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300',
        emerald: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        amber: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
        rose: 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        sky: 'bg-sky-100 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
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
        indigo: 'from-indigo-500 to-violet-600',
        emerald: 'from-emerald-500 to-teal-600',
        amber: 'from-amber-500 to-orange-600',
        rose: 'from-rose-500 to-pink-600',
        sky: 'from-sky-500 to-cyan-600',
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

export default function Index({ products, filters, categories, officialStores }) {
    const page = usePage()
    const flash = page.props.flash || {}

    const [search, setSearch] = useState(filters.search || '')
    const [selectedCategories, setSelectedCategories] = useState(filters.categories || [])
    const [syncing, setSyncing] = useState(false)
    const [resolvingCategories, setResolvingCategories] = useState(false)
    const [resolverPendingUntil, setResolverPendingUntil] = useState(0)

    useEffect(() => {
        try {
            const raw = window.localStorage.getItem(SHOPIFY_RESOLVER_PENDING_UNTIL_KEY)
            if (!raw) return
            const until = Number(raw)
            if (Number.isFinite(until) && until > Date.now()) {
                setResolverPendingUntil(until)
            } else {
                window.localStorage.removeItem(SHOPIFY_RESOLVER_PENDING_UNTIL_KEY)
            }
        } catch {
            /* ignore */
        }
    }, [])

    const dismissResolverPendingBanner = () => {
        try {
            window.localStorage.removeItem(SHOPIFY_RESOLVER_PENDING_UNTIL_KEY)
        } catch {
            /* ignore */
        }
        setResolverPendingUntil(0)
    }

    const showResolverPendingBanner =
        resolverPendingUntil > 0 && resolverPendingUntil > Date.now()

    const selectedCount = useMemo(() => selectedCategories.length, [selectedCategories])

    const stats = useMemo(() => {
        const rows = products?.data || []

        const active = rows.filter(
            (item) => String(item.status_ml || '').toLowerCase() === 'active'
        ).length

        const noStock = rows.filter((item) => Number(item.stock || 0) <= 0).length

        const withoutShopify = rows.filter(
            (item) => !item.shopify_category_name || item.shopify_category_name.trim() === ''
        ).length

        const totalValue = rows.reduce((sum, item) => {
            const price = Number(item.price || 0)
            const stock = Number(item.stock || 0)
            return sum + price * stock
        }, 0)

        return {
            active,
            noStock,
            withoutShopify,
            totalValue,
        }
    }, [products])

    /** Misma query que el listado (sin perPage): el export respeta filtros en el servidor. */
    const shopifyExportHref = useMemo(() => {
        const params = new URLSearchParams()
        const s = (filters.search || '').trim()
        if (s) params.set('search', s)
        if (filters.official_store_id) {
            params.set('official_store_id', String(filters.official_store_id))
        }
        const cats = Array.isArray(filters.categories) ? filters.categories : []
        for (const cat of cats) {
            if (cat !== '' && cat != null) {
                params.append('categories[]', String(cat))
            }
        }
        if (filters.sort) params.set('sort', String(filters.sort))
        if (filters.dir) params.set('dir', String(filters.dir))
        const qs = params.toString()
        return qs ? `/producto/export/shopify/tobeauty?${qs}` : '/producto/export/shopify/tobeauty'
    }, [filters])

    const applyFilters = (extra = {}) => {
        router.get(
            '/producto',
            {
                search,
                categories: selectedCategories,
                official_store_id:
                    extra.official_store_id ?? filters.official_store_id ?? '',
                perPage: extra.perPage ?? filters.perPage ?? 25,
                sort: extra.sort ?? filters.sort ?? 'name',
                dir: extra.dir ?? filters.dir ?? 'asc',
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

    const handleCategoryChange = (e) => {
        const values = Array.from(e.target.selectedOptions).map((option) => option.value)
        setSelectedCategories(values)
    }

    const handleSync = () => {
        setSyncing(true)

        router.post(
            '/producto/sync',
            {},
            {
                preserveScroll: true,
                onFinish: () => setSyncing(false),
            }
        )
    }

    const handleResolveShopifyCategories = () => {
        if (
            !confirm(
                'Se encolará la resolución de categorías Shopify para los productos que coincidan con el filtro actual. ' +
                    'La página responderá en segundos; el trabajo puede tardar varios minutos en segundo plano. ¿Continuar?'
            )
        ) {
            return
        }

        setResolvingCategories(true)

        router.post(
            '/producto/resolve-shopify-categories',
            {
                search,
                categories: selectedCategories,
                official_store_id: filters.official_store_id || '',
                only_empty: false,
                force: true,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    try {
                        const until = Date.now() + RESOLVER_BANNER_TTL_MS
                        window.localStorage.setItem(SHOPIFY_RESOLVER_PENDING_UNTIL_KEY, String(until))
                        setResolverPendingUntil(until)
                    } catch {
                        /* ignore */
                    }
                },
                onFinish: () => setResolvingCategories(false),
            }
        )
    }

    const clearFilters = () => {
        setSearch('')
        setSelectedCategories([])

        router.get(
            '/producto',
            {},
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            }
        )
    }

    const statusTone = (status) => {
        const value = String(status || '').toLowerCase()

        if (value === 'active') return 'emerald'
        if (value === 'paused') return 'amber'
        if (value === 'closed' || value === 'inactive') return 'rose'
        if (value.includes('review')) return 'sky'
        return 'slate'
    }

    const sourceTone = (source) => {
        const value = String(source || '').toLowerCase()

        if (value.includes('validada')) return 'emerald'
        if (value.includes('directa')) return 'indigo'
        if (value.includes('taxonomy')) return 'sky'
        return 'slate'
    }

    return (
        <>
            <Head title="Productos ML" />

            <AppShell title="Productos ML">
                <div className="space-y-6">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-400">
                            Catálogo Mercado Libre
                        </p>
                        <h2 className="mt-2 text-3xl font-bold text-slate-900 dark:text-white">
                            Gestión de productos ML
                        </h2>
                        <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">
                            Filtra, ordena y administra tus productos sincronizados con Mercado Libre y Shopify.
                        </p>
                    </div>

                    {(flash.success || flash.error || flash.ok || flash.err) && (
                        <div className="space-y-3">
                            {flash.success && (
                                <div
                                    role="status"
                                    className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-100"
                                >
                                    <p className="font-semibold">Listo</p>
                                    <p className="mt-1 whitespace-pre-wrap">{flash.success}</p>
                                </div>
                            )}
                            {(flash.error || flash.err) && (
                                <div
                                    role="alert"
                                    className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900 dark:border-rose-800 dark:bg-rose-500/10 dark:text-rose-100"
                                >
                                    <p className="font-semibold">Error</p>
                                    <p className="mt-1 whitespace-pre-wrap">{flash.error || flash.err}</p>
                                </div>
                            )}
                            {flash.ok && (
                                <div
                                    role="status"
                                    className="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-800 dark:bg-sky-500/10 dark:text-sky-100"
                                >
                                    <p className="whitespace-pre-wrap">{flash.ok}</p>
                                </div>
                            )}
                        </div>
                    )}

                    {showResolverPendingBanner && (
                        <div className="flex flex-col gap-3 rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100 md:flex-row md:items-start md:justify-between">
                            <div>
                                <p className="font-semibold">Resolución Shopify en segundo plano</p>
                                <p className="mt-1 text-sky-900/90 dark:text-sky-200">
                                    El proceso sigue ejecutándose en el servidor después de cargar esta página (puede
                                    tardar bastante si hay muchos productos). Actualiza el listado en unos minutos o revisa{' '}
                                    <span className="font-mono text-xs">storage/logs/laravel.log</span> buscando{' '}
                                    <span className="font-mono text-xs">[SHOPIFY]</span>.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={dismissResolverPendingBanner}
                                className="shrink-0 rounded-xl border border-sky-300 bg-white px-4 py-2 text-xs font-semibold text-sky-900 hover:bg-sky-100 dark:border-sky-700 dark:bg-neutral-900 dark:text-sky-100 dark:hover:bg-sky-900/50"
                            >
                                Ocultar aviso
                            </button>
                        </div>
                    )}

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <StatCard
                            title="Productos visibles"
                            value={products.total ?? 0}
                            subtitle="Total de productos encontrados."
                            tone="indigo"
                        />

                        <StatCard
                            title="Activos"
                            value={stats.active}
                            subtitle="Publicaciones activas en esta vista."
                            tone="emerald"
                        />

                        <StatCard
                            title="Sin stock"
                            value={stats.noStock}
                            subtitle="Productos con existencia en cero."
                            tone="rose"
                        />

                        <StatCard
                            title="Sin categoría Shopify"
                            value={stats.withoutShopify}
                            subtitle="Registros que aún faltan por clasificar."
                            tone="amber"
                        />
                    </div>

                    <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="grid grid-cols-1 gap-6 xl:grid-cols-12">
                            <div className="xl:col-span-12">
                                <form onSubmit={handleSearchSubmit}>
                                    <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                        Buscador general
                                    </label>

                                    <div className="relative">
                                        <input
                                            type="text"
                                            value={search}
                                            onChange={(e) => setSearch(e.target.value)}
                                            placeholder="Buscar por nombre, ML ID, SKU, marca o categoría Shopify..."
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
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Categorías ML {selectedCount > 0 ? `(${selectedCount} seleccionadas)` : ''}
                                </label>

                                <select
                                    multiple
                                    value={selectedCategories}
                                    onChange={handleCategoryChange}
                                    className="h-60 w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white dark:focus:border-indigo-400 dark:focus:ring-indigo-500/20"
                                >
                                    {categories.map((category) => (
                                        <option key={category} value={category}>
                                            {category}
                                        </option>
                                    ))}
                                </select>

                                <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
                                    Usa Ctrl o Cmd para seleccionar varias categorías.
                                </p>
                            </div>

                            <div className="xl:col-span-7">
                                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <div>
                                        <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                            Tienda oficial
                                        </label>
                                        <select
                                            value={filters.official_store_id || ''}
                                            onChange={(e) =>
                                                applyFilters({ official_store_id: e.target.value })
                                            }
                                            className="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white dark:focus:border-indigo-400 dark:focus:ring-indigo-500/20"
                                        >
                                            <option value="">Todas</option>
                                            {officialStores.map((store) => (
                                                <option key={store} value={store}>
                                                    {store}
                                                </option>
                                            ))}
                                        </select>
                                    </div>

                                    <div>
                                        <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                            Por página
                                        </label>
                                        <select
                                            value={filters.perPage || 25}
                                            onChange={(e) =>
                                                applyFilters({ perPage: Number(e.target.value) })
                                            }
                                            className="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white dark:focus:border-indigo-400 dark:focus:ring-indigo-500/20"
                                        >
                                            {[10, 25, 50, 100].map((value) => (
                                                <option key={value} value={value}>
                                                    {value}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                </div>

                                <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                    <button
                                        type="button"
                                        onClick={handleSync}
                                        disabled={syncing}
                                        className="inline-flex items-center justify-center rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {syncing ? 'Sincronizando...' : 'Sincronizar todos'}
                                    </button>

                                    <a
                                        href={shopifyExportHref}
                                        className="inline-flex items-center justify-center rounded-2xl bg-fuchsia-600 px-5 py-3 text-center text-sm font-semibold text-white transition hover:bg-fuchsia-700"
                                    >
                                        Exportar Shopify TOBEAUTY
                                    </a>

                                    <button
                                        type="button"
                                        title="Arranca el job en servidor; esta vista no se quedará cargando varios minutos."
                                        onClick={handleResolveShopifyCategories}
                                        disabled={resolvingCategories}
                                        className="inline-flex items-center justify-center rounded-2xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {resolvingCategories
                                            ? 'Enviando trabajo…'
                                            : 'Resolver categorías Shopify'}
                                    </button>

                                    <button
                                        type="button"
                                        onClick={clearFilters}
                                        className="inline-flex items-center justify-center rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-950 dark:text-slate-200 dark:hover:bg-neutral-800"
                                    >
                                        Limpiar filtros
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() => applyFilters()}
                                        className="inline-flex items-center justify-center rounded-2xl border border-emerald-300 bg-emerald-50 px-5 py-3 text-sm font-semibold text-emerald-700 transition hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300 dark:hover:bg-emerald-500/20"
                                    >
                                        Aplicar filtros
                                    </button>
                                </div>

                                <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-neutral-800 dark:bg-neutral-950">
                                    <p className="text-sm font-semibold text-slate-900 dark:text-white">
                                        Valor estimado de esta vista
                                    </p>
                                    <p className="mt-2 text-2xl font-bold text-emerald-600">
                                        {formatMoney(stats.totalValue)}
                                    </p>
                                    <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                        Suma aproximada de precio × stock solo de los productos cargados en esta página.
                                    </p>
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
                                            <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                                Producto
                                            </span>
                                        </th>

                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="Marca"
                                                column="brand"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>

                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="ID de ML"
                                                column="ml"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>

                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="SKU"
                                                column="sku"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>

                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="Tienda oficial"
                                                column="official_store_id"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>

                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="Categoría ML"
                                                column="category_name"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>

                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="Categoría Shopify"
                                                column="shopify_category_name"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>

                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="Fuente Shopify"
                                                column="shopify_category_source"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>

                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="Precio"
                                                column="price"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>

                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="Existencias"
                                                column="stock"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>

                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="Estado"
                                                column="status_ml"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>

                                        <th className="px-4 py-4 text-left">
                                            <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                                Acciones
                                            </span>
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    {products.data.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan="12"
                                                className="px-6 py-16 text-center text-slate-500 dark:text-slate-400"
                                            >
                                                No se encontraron productos con los filtros actuales.
                                            </td>
                                        </tr>
                                    ) : (
                                        products.data.map((product) => (
                                            <tr
                                                key={product.id}
                                                className="border-b border-slate-100 align-top transition hover:bg-slate-50/60 dark:border-neutral-800 dark:hover:bg-neutral-800/40"
                                            >
                                                <td className="px-4 py-4">
                                                    <div className="flex min-w-[300px] gap-4">
                                                        <div className="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 dark:border-neutral-800 dark:bg-neutral-950">
                                                            {product.thumbnail ? (
                                                                <img
                                                                    src={product.thumbnail}
                                                                    alt={product.name}
                                                                    className="h-full w-full object-contain"
                                                                />
                                                            ) : (
                                                                <span className="text-[11px] text-slate-400">
                                                                    Sin imagen
                                                                </span>
                                                            )}
                                                        </div>

                                                        <div className="min-w-0">
                                                            <div className="font-semibold text-slate-900 dark:text-white">
                                                                {product.name}
                                                            </div>

                                                            {product.description && (
                                                                <p className="mt-1 line-clamp-2 max-w-[280px] text-xs text-slate-500 dark:text-slate-400">
                                                                    {product.description}
                                                                </p>
                                                            )}
                                                        </div>
                                                    </div>
                                                </td>

                                                <td className="px-4 py-4 text-slate-700 dark:text-slate-300">
                                                    <div className="max-w-[180px] whitespace-pre-line">
                                                        {product.brand || '—'}
                                                    </div>
                                                </td>

                                                <td className="px-4 py-4">
                                                    {product.ml ? (
                                                        <a
                                                            href={product.permalink || '#'}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="font-medium text-indigo-600 hover:underline dark:text-indigo-400"
                                                        >
                                                            {product.ml}
                                                        </a>
                                                    ) : (
                                                        <span className="text-slate-400">—</span>
                                                    )}
                                                </td>

                                                <td className="px-4 py-4 text-slate-700 dark:text-slate-300">
                                                    <div className="max-w-[160px] break-words">
                                                        {product.sku || '—'}
                                                    </div>
                                                </td>

                                                <td className="px-4 py-4 text-slate-700 dark:text-slate-300">
                                                    {product.official_store_id || '—'}
                                                </td>

                                                <td className="px-4 py-4">
                                                    <div className="max-w-[220px] text-slate-700 dark:text-slate-300">
                                                        {product.category_name || '—'}
                                                    </div>
                                                    {product.category_id && (
                                                        <div className="mt-1 text-xs text-slate-400">
                                                            {product.category_id}
                                                        </div>
                                                    )}
                                                </td>

                                                <td className="px-4 py-4">
                                                    <div className="max-w-[260px] text-slate-700 dark:text-slate-300">
                                                        {product.shopify_category_name || '—'}
                                                    </div>
                                                    {product.shopify_category_id && (
                                                        <div className="mt-1 text-xs break-all text-slate-400">
                                                            {product.shopify_category_id}
                                                        </div>
                                                    )}
                                                </td>

                                                <td className="px-4 py-4">
                                                    {product.shopify_category_source ? (
                                                        <Badge tone={sourceTone(product.shopify_category_source)}>
                                                            {product.shopify_category_source}
                                                        </Badge>
                                                    ) : (
                                                        <span className="text-slate-400">—</span>
                                                    )}
                                                </td>

                                                <td className="px-4 py-4 font-semibold text-slate-900 dark:text-white">
                                                    {formatMoney(product.price)}
                                                </td>

                                                <td className="px-4 py-4">
                                                    <span
                                                        className={`font-bold ${
                                                            Number(product.stock) > 0
                                                                ? 'text-emerald-600'
                                                                : 'text-rose-600'
                                                        }`}
                                                    >
                                                        {product.stock}
                                                    </span>
                                                </td>

                                                <td className="px-4 py-4">
                                                    <Badge tone={statusTone(product.status_ml)}>
                                                        {product.status_ml || '—'}
                                                    </Badge>
                                                </td>

                                                <td className="px-4 py-4">
                                                    <div className="flex min-w-[130px] flex-col gap-2">
                                                        {product.permalink && (
                                                            <a
                                                                href={product.permalink}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-950 dark:text-slate-200 dark:hover:bg-neutral-800"
                                                            >
                                                                Ver ML
                                                            </a>
                                                        )}

                                                        {product.ml && (
                                                            <Link
                                                                href={`/producto/${product.ml}/republish`}
                                                                className="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-indigo-700"
                                                            >
                                                                Republicar
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
                                        {products.from ?? 0}
                                    </span>{' '}
                                    a{' '}
                                    <span className="font-semibold text-slate-700 dark:text-slate-200">
                                        {products.to ?? 0}
                                    </span>{' '}
                                    de{' '}
                                    <span className="font-semibold text-slate-700 dark:text-slate-200">
                                        {products.total}
                                    </span>{' '}
                                    productos
                                </div>

                                <Pagination className="mt-6" links={products.links} />
                            </div>
                        </div>
                    </div>
                </div>
            </AppShell>
        </>
    )
}