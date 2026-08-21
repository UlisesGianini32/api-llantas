import AppShell from '@/Components/layout/AppShell'
import Pagination from '@/Components/ui/Pagination'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { useEffect, useMemo, useState } from 'react'

const SHOPIFY_RESOLVER_PENDING_UNTIL_KEY = 'producto_shopify_resolver_pending_until'
/** Tiempo máximo para mostrar el aviso “en curso” (ms); el trabajo puede seguir después. */
const RESOLVER_BANNER_TTL_MS = 90 * 60 * 1000
const PRODUCT_COLUMNS_STORAGE_KEY = 'producto_visible_columns'

const PRODUCT_COLUMNS = [
    { key: 'product', label: 'Producto' },
    { key: 'brand', label: 'Marca' },
    { key: 'ml', label: 'ID de ML principal' },
    { key: 'publications', label: 'Publicaciones por cuenta' },
    { key: 'sku', label: 'SKU' },
    { key: 'officialStore', label: 'Tienda oficial' },
    { key: 'mlCategory', label: 'Categoría ML' },
    { key: 'shopifyCategory', label: 'Categoría Shopify' },
    { key: 'shopifySource', label: 'Fuente Shopify' },
    { key: 'price', label: 'Precio' },
    { key: 'stock', label: 'Existencias' },
    { key: 'status', label: 'Estado' },
    { key: 'actions', label: 'Acciones' },
]

const DEFAULT_VISIBLE_COLUMNS = PRODUCT_COLUMNS.map((column) => column.key)

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

export default function Index({
    products,
    filters,
    categories,
    officialStores,
    meliAccounts = [],
}) {
    const page = usePage()
    const flash = page.props.flash || {}

    const [search, setSearch] = useState(filters.search || '')
    const [selectedCategories, setSelectedCategories] = useState(filters.categories || [])
    const [syncing, setSyncing] = useState(false)
    const [resolvingCategories, setResolvingCategories] = useState(false)
    const [resolverPendingUntil, setResolverPendingUntil] = useState(0)
    const [selectedProducts, setSelectedProducts] = useState([])
    const [destinationAccountId, setDestinationAccountId] = useState('')
    const [batchRepublishing, setBatchRepublishing] = useState(false)
    const [batchResult, setBatchResult] = useState(null)
    const [batchError, setBatchError] = useState('')
    const [gtinOverrides, setGtinOverrides] = useState({})
    const [retryingProductId, setRetryingProductId] = useState(null)
    const [visibleColumns, setVisibleColumns] = useState(DEFAULT_VISIBLE_COLUMNS)
    const [publicationAccountId, setPublicationAccountId] = useState(filters.publication_account_id || '')
    const [deletingPublicationId, setDeletingPublicationId] = useState(null)


    useEffect(() => {
        try {
            const storedColumns = JSON.parse(
                window.localStorage.getItem(PRODUCT_COLUMNS_STORAGE_KEY) || 'null'
            )

            if (!Array.isArray(storedColumns)) return

            const validColumns = storedColumns.filter((columnKey) =>
                PRODUCT_COLUMNS.some((column) => column.key === columnKey)
            )

            if (validColumns.length > 0) {
                setVisibleColumns(validColumns)
            }
        } catch {
            /* ignore invalid local storage values */
        }
    }, [])

    useEffect(() => {
        try {
            window.localStorage.setItem(
                PRODUCT_COLUMNS_STORAGE_KEY,
                JSON.stringify(visibleColumns)
            )
        } catch {
            /* ignore */
        }
    }, [visibleColumns])

    const isColumnVisible = (columnKey) => visibleColumns.includes(columnKey)

    const toggleColumnVisibility = (columnKey) => {
        setVisibleColumns((current) => {
            if (current.includes(columnKey)) {
                if (current.length === 1) return current
                return current.filter((key) => key !== columnKey)
            }

            const next = new Set([...current, columnKey])
            return PRODUCT_COLUMNS
                .map((column) => column.key)
                .filter((key) => next.has(key))
        })
    }

    const showAllColumns = () => {
        setVisibleColumns(DEFAULT_VISIBLE_COLUMNS)
    }

    const showEssentialColumns = () => {
        setVisibleColumns(['product', 'ml', 'sku', 'price', 'stock', 'status', 'actions'])
    }

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


    const sourceMeliAccount = useMemo(
        () => meliAccounts.find((account) => account.is_default) ?? meliAccounts[0] ?? null,
        [meliAccounts]
    )

    const secondaryMeliAccounts = useMemo(
        () =>
            meliAccounts.filter(
                (account) => Number(account.id) !== Number(sourceMeliAccount?.id)
            ),
        [meliAccounts, sourceMeliAccount]
    )

    const pageProductIds = useMemo(
        () =>
            (products?.data ?? [])
                .filter((product) => Boolean(product.ml))
                .map((product) => Number(product.id)),
        [products]
    )

    const allPageProductsSelected =
        pageProductIds.length > 0 &&
        pageProductIds.every((id) => selectedProducts.includes(id))

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
                publication_account_id:
                    extra.publication_account_id ?? publicationAccountId ?? '',
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

    const toggleProductSelection = (productId) => {
        const numericId = Number(productId)
        setSelectedProducts((current) =>
            current.includes(numericId)
                ? current.filter((id) => id !== numericId)
                : [...current, numericId]
        )
    }

    const toggleCurrentPageSelection = () => {
        setSelectedProducts((current) => {
            if (allPageProductsSelected) {
                return current.filter((id) => !pageProductIds.includes(id))
            }

            return Array.from(new Set([...current, ...pageProductIds]))
        })
    }

    const deleteSecondaryPublication = async (publication) => {
        if (!publication?.id) return

        const accountName = publication.account?.nickname || publication.account?.meli_user_id || 'cuenta secundaria'
        if (!window.confirm(`¿Eliminar definitivamente ${publication.mlm} de ${accountName}?`)) {
            return
        }

        setDeletingPublicationId(Number(publication.id))

        try {
            const csrfToken =
                document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
            const response = await fetch('/producto/ml/secondary-publications', {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ publication_id: Number(publication.id) }),
            })

            const data = await response.json()
            if (!response.ok) {
                const validationErrors = data?.errors ? Object.values(data.errors).flat() : []
                throw new Error(validationErrors[0] || data?.message || 'No se pudo eliminar la publicación.')
            }

            router.reload({ only: ['products'], preserveScroll: true })
        } catch (error) {
            window.alert(error?.message || 'No se pudo eliminar la publicación secundaria.')
        } finally {
            setDeletingPublicationId(null)
        }
    }

    const republishSelectedProducts = async () => {
        setBatchError('')
        setBatchResult(null)

        if (selectedProducts.length === 0) {
            setBatchError('Selecciona al menos un producto.')
            return
        }

        if (!destinationAccountId) {
            setBatchError('Selecciona la cuenta secundaria.')
            return
        }

        if (!window.confirm(`¿Publicar ${selectedProducts.length} producto(s) en la cuenta secundaria?`)) {
            return
        }

        setBatchRepublishing(true)

        try {
            const csrfToken =
                document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
            const response = await fetch('/producto/ml/batch-republish', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    product_ids: selectedProducts,
                    destination_account_id: Number(destinationAccountId),
                    gtin_overrides: gtinOverrides,
                }),
            })

            const data = await response.json()
            if (!response.ok) {
                const validationErrors = data?.errors ? Object.values(data.errors).flat() : []
                throw new Error(validationErrors[0] ?? data?.message ?? 'No se pudo procesar el lote.')
            }

            setBatchResult(data)
            const successfulIds = (data.successful ?? []).map((item) => Number(item.product_id))
            setSelectedProducts((current) =>
                current.filter((id) => !successfulIds.includes(id))
            )
        } catch (error) {
            setBatchError(error?.message ?? 'Ocurrió un error al procesar el lote.')
        } finally {
            setBatchRepublishing(false)
        }
    }

    const retryFailedProductWithGtin = async (item) => {
        const productId = Number(item.product_id)
        const gtin = String(gtinOverrides[productId] || '').trim()

        if (!destinationAccountId) {
            setBatchError('Selecciona la cuenta secundaria.')
            return
        }

        if (!/^(?:\d{8}|\d{12,14})$/.test(gtin)) {
            setBatchError('El GTIN debe contener únicamente 8, 12, 13 o 14 dígitos.')
            return
        }

        setBatchError('')
        setRetryingProductId(productId)

        try {
            const csrfToken =
                document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''

            const response = await fetch('/producto/ml/batch-republish', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    product_ids: [productId],
                    destination_account_id: Number(destinationAccountId),
                    gtin_overrides: {
                        [productId]: gtin,
                    },
                }),
            })

            const data = await response.json()

            if (!response.ok) {
                const validationErrors = data?.errors ? Object.values(data.errors).flat() : []
                throw new Error(validationErrors[0] ?? data?.message ?? 'No se pudo reintentar la publicación.')
            }

            setBatchResult((current) => {
                if (!current) return data

                const successful = [
                    ...(current.successful ?? []).filter(
                        (row) => Number(row.product_id) !== productId
                    ),
                    ...(data.successful ?? []),
                ]

                const failed = [
                    ...(current.failed ?? []).filter(
                        (row) => Number(row.product_id) !== productId
                    ),
                    ...(data.failed ?? []),
                ]

                return {
                    ...current,
                    successful,
                    failed,
                    summary: {
                        total: successful.length + failed.length,
                        successful: successful.length,
                        failed: failed.length,
                    },
                }
            })

            if ((data.successful ?? []).length > 0) {
                setSelectedProducts((current) =>
                    current.filter((id) => Number(id) !== productId)
                )
                setGtinOverrides((current) => {
                    const next = { ...current }
                    delete next[productId]
                    return next
                })
            }
        } catch (error) {
            setBatchError(error?.message ?? 'Ocurrió un error al reintentar la publicación.')
        } finally {
            setRetryingProductId(null)
        }
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

                    <div className="rounded-3xl border border-indigo-200 bg-indigo-50 p-6 shadow-sm dark:border-indigo-900/60 dark:bg-indigo-950/30">
                        <div className="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                            <div>
                                <h3 className="text-lg font-bold text-slate-900 dark:text-white">
                                    Publicar seleccionados en cuenta secundaria
                                </h3>
                                <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
                                    Cuenta principal:{' '}
                                    <span className="font-semibold">
                                        {sourceMeliAccount
                                            ? `${sourceMeliAccount.nickname || 'Mercado Libre'} (${sourceMeliAccount.meli_user_id})`
                                            : 'No configurada'}
                                    </span>
                                </p>
                                <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
                                    Seleccionados: <span className="font-bold text-indigo-700 dark:text-indigo-300">{selectedProducts.length}</span>
                                </p>
                            </div>

                            <div className="flex w-full flex-col gap-3 sm:flex-row xl:w-auto">
                                <select
                                    value={destinationAccountId}
                                    onChange={(event) => setDestinationAccountId(event.target.value)}
                                    disabled={batchRepublishing}
                                    className="min-w-[280px] rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                >
                                    <option value="">Selecciona la cuenta secundaria</option>
                                    {secondaryMeliAccounts.map((account) => (
                                        <option
                                            key={account.id}
                                            value={account.id}
                                            disabled={!account.has_access_token}
                                        >
                                            {account.nickname || 'Cuenta Mercado Libre'} — {account.meli_user_id}
                                            {!account.has_access_token ? ' — Requiere autorización' : ''}
                                        </option>
                                    ))}
                                </select>

                                <button
                                    type="button"
                                    onClick={republishSelectedProducts}
                                    disabled={batchRepublishing || selectedProducts.length === 0 || !destinationAccountId}
                                    className="rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {batchRepublishing ? 'Procesando lote…' : 'Publicar seleccionados'}
                                </button>

                                {selectedProducts.length > 0 && (
                                    <button
                                        type="button"
                                        onClick={() => setSelectedProducts([])}
                                        disabled={batchRepublishing}
                                        className="rounded-2xl border border-slate-300 bg-white px-5 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-950 dark:text-slate-200"
                                    >
                                        Limpiar selección
                                    </button>
                                )}
                            </div>
                        </div>

                        {batchError && (
                            <div className="mt-4 rounded-2xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-700 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300">
                                {batchError}
                            </div>
                        )}
                    </div>

                    <div className="flex flex-col justify-end gap-3 sm:flex-row sm:items-center">
                        <select
                            value={publicationAccountId}
                            onChange={(event) => {
                                const value = event.target.value
                                setPublicationAccountId(value)
                                applyFilters({ publication_account_id: value })
                            }}
                            className="rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm outline-none focus:border-indigo-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200"
                        >
                            <option value="">Publicaciones: todas las cuentas</option>
                            {meliAccounts.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.is_default ? 'Principal' : 'Secundaria'} — {account.nickname || 'Mercado Libre'} ({account.meli_user_id})
                                </option>
                            ))}
                        </select>

                        <details className="relative">
                            <summary className="inline-flex cursor-pointer list-none items-center gap-2 rounded-2xl border border-slate-300 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200 dark:hover:bg-neutral-800">
                                Columnas
                                <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-bold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">
                                    {visibleColumns.length}/{PRODUCT_COLUMNS.length}
                                </span>
                            </summary>

                            <div className="absolute right-0 z-30 mt-2 w-72 rounded-2xl border border-slate-200 bg-white p-4 shadow-2xl dark:border-neutral-700 dark:bg-neutral-900">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <h3 className="font-bold text-slate-900 dark:text-white">
                                            Columnas visibles
                                        </h3>
                                        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                            La selección se guarda automáticamente en este navegador.
                                        </p>
                                    </div>
                                </div>

                                <div className="mt-4 max-h-80 space-y-1 overflow-y-auto pr-1">
                                    {PRODUCT_COLUMNS.map((column) => {
                                        const checked = isColumnVisible(column.key)
                                        const isLastVisible = checked && visibleColumns.length === 1

                                        return (
                                            <label
                                                key={column.key}
                                                className="flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2 text-sm text-slate-700 transition hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-neutral-800"
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={checked}
                                                    disabled={isLastVisible}
                                                    onChange={() => toggleColumnVisibility(column.key)}
                                                    className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                                                />
                                                <span>{column.label}</span>
                                            </label>
                                        )
                                    })}
                                </div>

                                <div className="mt-4 grid grid-cols-2 gap-2 border-t border-slate-200 pt-4 dark:border-neutral-800">
                                    <button
                                        type="button"
                                        onClick={showEssentialColumns}
                                        className="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-200 dark:hover:bg-neutral-800"
                                    >
                                        Esenciales
                                    </button>
                                    <button
                                        type="button"
                                        onClick={showAllColumns}
                                        className="rounded-xl bg-indigo-600 px-3 py-2 text-xs font-semibold text-white transition hover:bg-indigo-700"
                                    >
                                        Mostrar todas
                                    </button>
                                </div>
                            </div>
                        </details>
                    </div>

                    <div className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead className="sticky top-0 z-10 bg-slate-50 dark:bg-neutral-950">
                                    <tr className="border-b border-slate-200 dark:border-neutral-800">
                                        <th className="w-12 px-4 py-4 text-left">
                                            <input
                                                type="checkbox"
                                                checked={allPageProductsSelected}
                                                onChange={toggleCurrentPageSelection}
                                                disabled={pageProductIds.length === 0 || batchRepublishing}
                                                aria-label="Seleccionar productos de esta página"
                                                className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                            />
                                        </th>

                                        {isColumnVisible('product') && (
                                        <th className="px-4 py-4 text-left">
                                            <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                                Producto
                                            </span>
                                        </th>
                                        )}

                                        {isColumnVisible('brand') && (
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="Marca"
                                                column="brand"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>
                                        )}

                                        {isColumnVisible('ml') && (
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="ID de ML"
                                                column="ml"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>
                                        )}

                                        {isColumnVisible('publications') && (
                                        <th className="px-4 py-4 text-left">
                                            <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Publicaciones por cuenta</span>
                                        </th>
                                        )}

                                        {isColumnVisible('sku') && (
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="SKU"
                                                column="sku"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>
                                        )}

                                        {isColumnVisible('officialStore') && (
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="Tienda oficial"
                                                column="official_store_id"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>
                                        )}

                                        {isColumnVisible('mlCategory') && (
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="Categoría ML"
                                                column="category_name"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>
                                        )}

                                        {isColumnVisible('shopifyCategory') && (
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="Categoría Shopify"
                                                column="shopify_category_name"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>
                                        )}

                                        {isColumnVisible('shopifySource') && (
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="Fuente Shopify"
                                                column="shopify_category_source"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>
                                        )}

                                        {isColumnVisible('price') && (
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="Precio"
                                                column="price"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>
                                        )}

                                        {isColumnVisible('stock') && (
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="Existencias"
                                                column="stock"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>
                                        )}

                                        {isColumnVisible('status') && (
                                        <th className="px-4 py-4 text-left">
                                            <SortableHeader
                                                label="Estado"
                                                column="status_ml"
                                                filters={filters}
                                                onSort={handleSort}
                                            />
                                        </th>
                                        )}

                                        {isColumnVisible('actions') && (
                                        <th className="px-4 py-4 text-left">
                                            <span className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                                                Acciones
                                            </span>
                                        </th>
                                        )}
                                    </tr>
                                </thead>

                                <tbody>
                                    {products.data.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={visibleColumns.length + 1}
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
                                                <td className="px-4 py-4 align-top">
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedProducts.includes(Number(product.id))}
                                                        onChange={() => toggleProductSelection(product.id)}
                                                        disabled={!product.ml || batchRepublishing}
                                                        aria-label={`Seleccionar ${product.name || product.ml}`}
                                                        className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:opacity-40"
                                                    />
                                                </td>

                                                {isColumnVisible('product') && (
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
                                                )}

                                                {isColumnVisible('brand') && (
                                                <td className="px-4 py-4 text-slate-700 dark:text-slate-300">
                                                    <div className="max-w-[180px] whitespace-pre-line">
                                                        {product.brand || '—'}
                                                    </div>
                                                </td>
                                                )}

                                                {isColumnVisible('ml') && (
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
                                                )}

                                                {isColumnVisible('publications') && (
                                                <td className="px-4 py-4">
                                                    <div className="min-w-[280px] space-y-2">
                                                        {(product.meli_publications ?? []).length === 0 ? (
                                                            <span className="text-sm text-slate-400">Sin publicaciones registradas</span>
                                                        ) : (
                                                            product.meli_publications.map((publication) => {
                                                                const isDefault = Boolean(publication.account?.is_default)
                                                                return (
                                                                    <div
                                                                        key={`${product.id}-${publication.mlm}`}
                                                                        className={`rounded-xl border p-3 ${
                                                                            isDefault
                                                                                ? 'border-indigo-200 bg-indigo-50 dark:border-indigo-900 dark:bg-indigo-950/20'
                                                                                : 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/20'
                                                                        }`}
                                                                    >
                                                                        <div className="flex items-start justify-between gap-3">
                                                                            <div>
                                                                                <div className="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                                                                    {isDefault ? 'Cuenta principal' : 'Cuenta secundaria'}
                                                                                </div>
                                                                                <div className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                                                                    {publication.account?.nickname || 'Mercado Libre'} ({publication.account?.meli_user_id || 'sin ID'})
                                                                                </div>
                                                                                <div className="mt-1 font-bold text-slate-900 dark:text-white">{publication.mlm}</div>
                                                                            </div>
                                                                            <Badge tone={statusTone(publication.status)}>
                                                                                {publication.status || '—'}
                                                                            </Badge>
                                                                        </div>

                                                                        <div className="mt-3 flex flex-wrap gap-2">
                                                                            {publication.permalink && (
                                                                                <a
                                                                                    href={publication.permalink}
                                                                                    target="_blank"
                                                                                    rel="noreferrer"
                                                                                    className="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-700 dark:bg-white dark:text-slate-900"
                                                                                >
                                                                                    Ver publicación
                                                                                </a>
                                                                            )}

                                                                            {!isDefault && publication.id && publication.status !== 'deleted' && (
                                                                                <button
                                                                                    type="button"
                                                                                    onClick={() => deleteSecondaryPublication(publication)}
                                                                                    disabled={deletingPublicationId === Number(publication.id)}
                                                                                    className="rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
                                                                                >
                                                                                    {deletingPublicationId === Number(publication.id) ? 'Eliminando...' : 'Eliminar secundaria'}
                                                                                </button>
                                                                            )}
                                                                        </div>
                                                                    </div>
                                                                )
                                                            })
                                                        )}
                                                    </div>
                                                </td>
                                                )}

                                                {isColumnVisible('sku') && (
                                                <td className="px-4 py-4 text-slate-700 dark:text-slate-300">
                                                    <div className="max-w-[160px] break-words">
                                                        {product.sku || '—'}
                                                    </div>
                                                </td>
                                                )}

                                                {isColumnVisible('officialStore') && (
                                                <td className="px-4 py-4 text-slate-700 dark:text-slate-300">
                                                    {product.official_store_id || '—'}
                                                </td>
                                                )}

                                                {isColumnVisible('mlCategory') && (
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
                                                )}

                                                {isColumnVisible('shopifyCategory') && (
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
                                                )}

                                                {isColumnVisible('shopifySource') && (
                                                <td className="px-4 py-4">
                                                    {product.shopify_category_source ? (
                                                        <Badge tone={sourceTone(product.shopify_category_source)}>
                                                            {product.shopify_category_source}
                                                        </Badge>
                                                    ) : (
                                                        <span className="text-slate-400">—</span>
                                                    )}
                                                </td>
                                                )}

                                                {isColumnVisible('price') && (
                                                <td className="px-4 py-4 font-semibold text-slate-900 dark:text-white">
                                                    {formatMoney(product.price)}
                                                </td>
                                                )}

                                                {isColumnVisible('stock') && (
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
                                                )}

                                                {isColumnVisible('status') && (
                                                <td className="px-4 py-4">
                                                    <Badge tone={statusTone(product.status_ml)}>
                                                        {product.status_ml || '—'}
                                                    </Badge>
                                                </td>
                                                )}

                                                {isColumnVisible('actions') && (
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
                                                )}
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

                {batchResult && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
                        <div className="max-h-[92vh] w-full max-w-7xl overflow-hidden rounded-3xl bg-white shadow-2xl dark:bg-neutral-900">
                            <div className="flex items-start justify-between border-b border-slate-200 px-6 py-5 dark:border-neutral-800">
                                <div>
                                    <h2 className="text-xl font-bold text-slate-900 dark:text-white">Resultado de republicación</h2>
                                    <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                        Destino: {batchResult.destination_account?.nickname || 'Mercado Libre'} ({batchResult.destination_account?.meli_user_id})
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setBatchResult(null)}
                                    className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100 dark:border-neutral-700 dark:text-slate-200 dark:hover:bg-neutral-800"
                                >
                                    Cerrar
                                </button>
                            </div>

                            <div className="max-h-[calc(92vh-90px)] overflow-y-auto p-6">
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <StatCard title="Procesados" value={batchResult.summary?.total ?? 0} subtitle="Total del lote." />
                                    <StatCard title="Publicados" value={batchResult.summary?.successful ?? 0} subtitle="Creados correctamente." tone="emerald" />
                                    <StatCard title="Con error" value={batchResult.summary?.failed ?? 0} subtitle="Revisa el motivo abajo." tone="rose" />
                                </div>

                                {(batchResult.successful ?? []).length > 0 && (
                                    <section className="mt-8">
                                        <h3 className="text-lg font-bold text-emerald-700 dark:text-emerald-300">Publicados correctamente</h3>
                                        <div className="mt-3 overflow-x-auto rounded-2xl border border-slate-200 dark:border-neutral-800">
                                            <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-neutral-800">
                                                <thead className="bg-slate-50 dark:bg-neutral-950">
                                                    <tr>
                                                        {['Producto', 'SKU', 'MLM original', 'Nuevo MLM', 'Estado', 'Acción'].map((label) => (
                                                            <th key={label} className="px-4 py-3 text-left text-xs font-bold uppercase text-slate-500">{label}</th>
                                                        ))}
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-200 dark:divide-neutral-800">
                                                    {batchResult.successful.map((item) => (
                                                        <tr key={`${item.product_id}-${item.destination_mlm}`}>
                                                            <td className="px-4 py-4 text-slate-900 dark:text-white">
                                                                <div className="font-semibold">{item.title}</div>
                                                                {item.description_warning && (
                                                                    <div className="mt-1 text-xs text-amber-600 dark:text-amber-300">
                                                                        Se publicó, pero la descripción tuvo una advertencia: {item.description_warning}
                                                                    </div>
                                                                )}
                                                            </td>
                                                            <td className="px-4 py-4 text-slate-600 dark:text-slate-300">{item.sku || '—'}</td>
                                                            <td className="px-4 py-4 text-slate-600 dark:text-slate-300">{item.source_mlm}</td>
                                                            <td className="px-4 py-4 font-bold text-emerald-700 dark:text-emerald-300">{item.destination_mlm}</td>
                                                            <td className="px-4 py-4 text-slate-600 dark:text-slate-300">{item.status}</td>
                                                            <td className="px-4 py-4">
                                                                {item.permalink ? (
                                                                    <a href={item.permalink} target="_blank" rel="noreferrer" className="rounded-xl bg-emerald-600 px-3 py-2 font-semibold text-white hover:bg-emerald-700">Ver publicación</a>
                                                                ) : '—'}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>
                                )}

                                {(batchResult.failed ?? []).length > 0 && (
                                    <section className="mt-8">
                                        <h3 className="text-lg font-bold text-rose-700 dark:text-rose-300">No se pudieron publicar</h3>
                                        <div className="mt-3 space-y-4">
                                            {batchResult.failed.map((item) => (
                                                <div key={`${item.product_id}-${item.source_mlm}`} className="rounded-2xl border border-rose-200 bg-rose-50 p-5 dark:border-rose-900 dark:bg-rose-950/20">
                                                    <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                                        <div>
                                                            <div className="font-bold text-slate-900 dark:text-white">{item.title}</div>
                                                            <div className="mt-1 text-sm text-slate-600 dark:text-slate-300">SKU: {item.sku || '—'} · MLM: {item.source_mlm || '—'}</div>
                                                        </div>
                                                        <div className="rounded-lg bg-rose-100 px-3 py-2 text-xs font-bold text-rose-700 dark:bg-rose-950 dark:text-rose-300">
                                                            {item.error?.code || 'unknown_error'}{item.error?.http_status ? ` · HTTP ${item.error.http_status}` : ''}
                                                        </div>
                                                    </div>
                                                    <div className="mt-4 text-sm font-medium text-rose-800 dark:text-rose-200">
                                                        {item.error?.message || 'Mercado Libre rechazó la publicación.'}
                                                    </div>

                                                    {/* MELI_REPUBLISH_GTIN_ONLY_V3 */}
                                                    {item.needs_gtin && (
                                                    <div className={`mt-4 rounded-xl border p-4 ${
                                                        item.needs_gtin
                                                            ? 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/30'
                                                            : 'border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-950'
                                                    }`}>
                                                        <div className="text-sm font-bold text-slate-900 dark:text-white">
                                                            {item.needs_gtin
                                                                ? 'Esta publicación necesita un código universal (GTIN)'
                                                                : 'Agregar código universal (GTIN) y reintentar'}
                                                        </div>
                                                        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                                            Escribe un código universal que no esté asociado a otra marca. Puede ser EAN, UPC o GTIN de 8, 12, 13 o 14 dígitos.
                                                        </p>

                                                        <div className="mt-3 flex flex-col gap-3 sm:flex-row">
                                                            <input
                                                                type="text"
                                                                inputMode="numeric"
                                                                maxLength={14}
                                                                value={gtinOverrides[Number(item.product_id)] || ''}
                                                                onChange={(event) => {
                                                                    const value = event.target.value.replace(/\D/g, '').slice(0, 14)
                                                                    setGtinOverrides((current) => ({
                                                                        ...current,
                                                                        [Number(item.product_id)]: value,
                                                                    }))
                                                                }}
                                                                placeholder="Ejemplo: 8429707038147"
                                                                className="min-w-0 flex-1 rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                                                            />

                                                            <button
                                                                type="button"
                                                                onClick={() => retryFailedProductWithGtin(item)}
                                                                disabled={
                                                                    retryingProductId === Number(item.product_id) ||
                                                                    !/^(?:\d{8}|\d{12,14})$/.test(
                                                                        String(gtinOverrides[Number(item.product_id)] || '')
                                                                    )
                                                                }
                                                                className="rounded-xl bg-amber-600 px-5 py-3 text-sm font-bold text-white transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-50"
                                                            >
                                                                {retryingProductId === Number(item.product_id)
                                                                    ? 'Reintentando…'
                                                                    : 'Guardar código y reintentar'}
                                                            </button>
                                                        </div>
                                                    </div>
                                                    )}

                                                    {(item.error?.causes ?? []).length > 0 && (
                                                        <div className="mt-4 space-y-2">
                                                            {item.error.causes.map((cause, index) => (
                                                                <div key={`${cause.code}-${index}`} className="rounded-xl border border-rose-200 bg-white px-4 py-3 dark:border-rose-900 dark:bg-neutral-950">
                                                                    <div className="text-xs font-bold text-rose-700 dark:text-rose-300">{cause.code}</div>
                                                                    <div className="mt-1 text-sm text-slate-700 dark:text-slate-300">{cause.message}</div>
                                                                </div>
                                                            ))}
                                                        </div>
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                    </section>
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </AppShell>
        </>
    )
}