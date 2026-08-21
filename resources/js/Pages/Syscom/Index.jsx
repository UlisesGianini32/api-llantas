import AppShell from '@/Components/layout/AppShell'
import MeliCategoryPicker from './MeliCategoryPicker'
import { useCallback, useEffect, useState } from 'react'
import { Head, Link, router, useForm, usePage } from '@inertiajs/react'

const COLUMN_STORAGE_KEY = 'syscom-ml-table-columns-v1'

/** @type {{ id: string, label: string, defaultOn: boolean, required?: boolean }[]} */
const TABLE_COLUMNS = [
    { id: 'id', label: 'ID SYSCOM', defaultOn: true },
    { id: 'titulo', label: 'Título', defaultOn: true },
    { id: 'marca', label: 'Marca', defaultOn: true },
    { id: 'modelo', label: 'Modelo', defaultOn: false },
    { id: 'stock', label: 'Stock', defaultOn: true },
    { id: 'costo_mxn', label: 'Costo MXN', defaultOn: true },
    { id: 'precio_meli', label: 'Precio ML', defaultOn: true },
    { id: 'recibes', label: 'Recibes ~', defaultOn: true },
    { id: 'mlm', label: 'MLM', defaultOn: true },
    { id: 'publ_sku', label: 'Publ. mismo SKU', defaultOn: false },
    { id: 'estado', label: 'Estado ML', defaultOn: true },
    { id: 'acciones', label: 'Acciones', defaultOn: true, required: true },
]

function defaultColumnVisibility() {
    return Object.fromEntries(TABLE_COLUMNS.map((c) => [c.id, c.defaultOn]))
}

function loadColumnVisibility() {
    try {
        const raw = localStorage.getItem(COLUMN_STORAGE_KEY)
        if (!raw) return defaultColumnVisibility()
        const parsed = JSON.parse(raw)
        if (!parsed || typeof parsed !== 'object') return defaultColumnVisibility()
        const base = defaultColumnVisibility()
        for (const col of TABLE_COLUMNS) {
            if (typeof parsed[col.id] === 'boolean') {
                base[col.id] = parsed[col.id]
            }
        }
        base.acciones = true
        return base
    } catch {
        return defaultColumnVisibility()
    }
}

function mxn(n) {
    if (typeof n !== 'number' || Number.isNaN(n)) return '—'
    return n.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' })
}

const COLA_OPTIONS = [
    { value: '', label: 'Todo el catálogo' },
    { value: 'categorizado', label: 'Categorizados' },
    { value: 'publicando', label: 'Publicando' },
    { value: 'en_cola', label: 'En cola ML' },
    { value: 'pendiente', label: 'Pendiente publicar' },
    { value: 'publicado', label: 'Con MLM' },
    { value: 'error', label: 'Error al publicar' },
]

export default function Index({
    products,
    filters,
    sucursal,
    recibesEstimateConfigured = false,
    meliLinked = false,
    queueCounts = {
        total: 0,
        pendiente: 0,
        publicado: 0,
        error: 0,
        publicando: 0,
        categorizado: 0,
    },
}) {
    const { auth, flash = {} } = usePage().props
    const [refreshing, setRefreshing] = useState(false)
    const [syncingPrices, setSyncingPrices] = useState(false)
    const [refreshingPrices, setRefreshingPrices] = useState(false)
    const [syncingPriceId, setSyncingPriceId] = useState(null)
    const [importingSearch, setImportingSearch] = useState(false)
    const [bulkPublishing, setBulkPublishing] = useState(false)
    const [gtinByProduct, setGtinByProduct] = useState({})
    const [retryingGtinId, setRetryingGtinId] = useState(null)
    const [columnsOpen, setColumnsOpen] = useState(false)
    const [columnVis, setColumnVis] = useState(defaultColumnVisibility)

    useEffect(() => {
        setColumnVis(loadColumnVisibility())
    }, [])

    const persistColumns = useCallback((next) => {
        const withActions = { ...next, acciones: true }
        setColumnVis(withActions)
        try {
            localStorage.setItem(COLUMN_STORAGE_KEY, JSON.stringify(withActions))
        } catch {
            /* ignore */
        }
    }, [])

    const toggleColumn = (id) => {
        const col = TABLE_COLUMNS.find((c) => c.id === id)
        if (!col || col.required) return
        persistColumns({ ...columnVis, [id]: !columnVis[id] })
    }

    const applyColumnPreset = (preset) => {
        if (preset === 'all') {
            persistColumns(Object.fromEntries(TABLE_COLUMNS.map((c) => [c.id, true])))
            return
        }
        if (preset === 'min') {
            persistColumns({
                id: true,
                titulo: true,
                marca: false,
                modelo: false,
                stock: true,
                costo_mxn: false,
                precio_meli: true,
                recibes: false,
                mlm: true,
                publ_sku: false,
                estado: true,
                acciones: true,
            })
            return
        }
        if (preset === 'prices') {
            persistColumns({
                id: true,
                titulo: false,
                marca: false,
                modelo: false,
                stock: true,
                costo_mxn: true,
                precio_meli: true,
                recibes: true,
                mlm: true,
                publ_sku: false,
                estado: true,
                acciones: true,
            })
        }
    }

    const isColVisible = (id) => columnVis[id] !== false
    const visibleColumnCount = TABLE_COLUMNS.filter((c) => isColVisible(c.id)).length
    const qForm = useForm({ q: filters?.q || '', cola: filters?.cola || '' })
    const publishForm = useForm({
        category_id: '',
        official_store_mode: 'marketmax',
        price_scope: 'llanta',
        universal_code: '',
    })

    const search = (e) => {
        e.preventDefault()
        qForm.get('/syscom-ml', { preserveState: true })
    }

    const syncCatalog = () => {
        if (
            !confirm(
                'Sincronizar catálogo SYSCOM (sucursal ' +
                    sucursal +
                    '): letras, marcas y categorías — solo productos con stock en Hermosillo. Tarda varios minutos en cola. ¿Continuar?'
            )
        ) {
            return
        }
        router.post('/syscom-ml/sync-catalog', {}, { preserveScroll: true })
    }

    const importFromSyscom = () => {
        const term = (qForm.data.q || '').trim()
        if (term.length < 2) {
            alert('Escribí al menos 2 caracteres (modelo, ej. EPLMCN230, o marca).')
            return
        }
        if (
            !confirm(
                '¿Consultar la API SYSCOM con «' +
                    term +
                    '» (sucursal ' +
                    sucursal +
                    ') e importar lo que tenga stock? Puede tardar un momento.'
            )
        ) {
            return
        }
        setImportingSearch(true)
        router.post(
            '/syscom-ml/import-search',
            { q: term, cola: qForm.data.cola || '' },
            {
                preserveScroll: true,
                onFinish: () => setImportingSearch(false),
            }
        )
    }

    const refreshSyscomPrices = () => {
        if (
            !confirm(
                '¿Consultar detalle SYSCOM para completar costos en las filas de esta página? (puede tardar ~1 min)'
            )
        ) {
            return
        }
        setRefreshingPrices(true)
        router.post(
            '/syscom-ml/refresh-prices-page',
            {
                q: filters?.q || '',
                cola: filters?.cola || '',
                page: products?.current_page ?? 1,
            },
            {
                preserveScroll: true,
                onFinish: () => setRefreshingPrices(false),
            }
        )
    }

    const syncPricesOnPage = () => {
        if (!auth?.user?.meli_linked) {
            alert('Vinculá Mercado Libre primero.')
            return
        }
        if (
            !confirm(
                '¿Enviar a Mercado Libre el precio y stock calculados de cada fila con MLM en esta página?'
            )
        ) {
            return
        }
        setSyncingPrices(true)
        router.post(
            '/syscom-ml/sync-prices-page',
            {
                q: filters?.q || '',
                cola: filters?.cola || '',
                page: products?.current_page ?? 1,
            },
            {
                preserveScroll: true,
                onFinish: () => setSyncingPrices(false),
            }
        )
    }

    const syncPriceOne = (productId) => {
        if (!auth?.user?.meli_linked) {
            alert('Vinculá Mercado Libre primero.')
            return
        }
        setSyncingPriceId(productId)
        router.post(
            '/syscom-ml/' + productId + '/sync-price',
            {},
            {
                preserveScroll: true,
                onFinish: () => setSyncingPriceId(null),
                onError: (errors) => {
                    const msg =
                        typeof errors === 'string'
                            ? errors
                            : errors?.message || 'No se pudo sincronizar (error del servidor). Revisá el mensaje arriba o el log.'
                    alert(msg)
                },
            }
        )
    }

    const publishCategorizedMarketmax = (limit = null) => {
        if (!auth?.user?.meli_linked) {
            alert('Vinculá tu cuenta de Mercado Libre primero.')
            return
        }

        const total = Number(queueCounts?.categorizado || 0)

        const message = limit
            ? `Se enviará a la cola una prueba de hasta ${limit} productos categorizados, con stock y sin MLM, exclusivamente a MARKETMAX. Los productos que fallen por GTIN u otra validación quedarán en Error y los demás continuarán. ¿Continuar?`
            : `Se enviarán a la cola los productos categorizados disponibles de los ${total} categorizados actuales. Solo se intentarán los que tengan stock, no tengan MLM y no estén ya publicándose o en Error. El destino será exclusivamente MARKETMAX. ¿Continuar?`

        if (!confirm(message)) {
            return
        }

        setBulkPublishing(true)

        router.post(
            '/syscom-ml/publish-categorized-marketmax',
            limit ? { limit } : {},
            {
                preserveScroll: true,
                onFinish: () => setBulkPublishing(false),
            }
        )
    }

    const refreshMlStatus = () => {
        if (!auth?.user?.meli_linked) {
            alert('Vinculá Mercado Libre primero.')
            return
        }
        setRefreshing(true)
        router.post(
            '/syscom-ml/refresh-status',
            {
                q: filters?.q || '',
                cola: filters?.cola || '',
                page: products?.current_page ?? 1,
            },
            {
                preserveScroll: true,
                onFinish: () => setRefreshing(false),
            }
        )
    }

    const needsGtin = (row) => {
        if (!row || row.mlm) {
            return false
        }

        const error = String(row.publish_error || '').toLowerCase()

        if (!error) {
            return false
        }

        return (
            error.includes('exige gtin') ||
            error.includes('código universal') ||
            error.includes('codigo universal') ||
            error.includes('gtin/ean/upc') ||
            error.includes('product_identifier') ||
            error.includes('invalid_format') ||
            error.includes('error 7810')
        )
    }

    const setRowGtin = (productId, value) => {
        const digits = String(value || '')
            .replace(/\D/g, '')
            .slice(0, 14)

        setGtinByProduct((current) => ({
            ...current,
            [productId]: digits,
        }))
    }

    const retryWithGtin = (row) => {
        if (!auth?.user?.meli_linked) {
            alert('Vinculá tu cuenta de Mercado Libre primero.')
            return
        }

        const gtin = String(
            gtinByProduct[row.id] || ''
        ).trim()

        if (
            !/^(?:\d{8}|\d{12}|\d{13}|\d{14})$/.test(gtin)
        ) {
            alert(
                'El código universal debe contener exactamente 8, 12, 13 o 14 dígitos.'
            )
            return
        }

        if (
            !confirm(
                `Reintentar publicación en Marketmax con GTIN ${gtin}?`
            )
        ) {
            return
        }

        setRetryingGtinId(row.id)

        router.post(
            '/syscom-ml/publish/' + row.id,
            {
                /*
                 * Categoría vacía:
                 * el backend usará el mapping/override
                 * aprobado que ya tiene este producto.
                 */
                category_id: '',

                /*
                 * Esta pantalla SYSCOM publica a Marketmax.
                 */
                official_store_mode: 'marketmax',

                /*
                 * Mismo scope usado por el bulk SYSCOM.
                 */
                price_scope: 'llanta',

                /*
                 * GTIN exclusivo de esta fila.
                 */
                universal_code: gtin,
            },
            {
                preserveScroll: true,
                preserveState: true,

                onFinish: () => {
                    setRetryingGtinId(null)
                },

                onError: (errors) => {
                    const msg =
                        typeof errors === 'string'
                            ? errors
                            : errors?.message ||
                              'No se pudo reintentar la publicación.'

                    alert(msg)
                },
            }
        )
    }

    const categoryIdFilled = (publishForm.data.category_id || '').trim() !== ''

    const canRepublishRow = (row) => Boolean(row?.mlm) && (row.puede_republicar || categoryIdFilled)

    const publish = (productId, republicar = false, row = null) => {
        if (!auth?.user?.meli_linked) {
            alert('Vinculá tu cuenta de Mercado Libre primero (Dashboard o /auth/meli).')
            return
        }
        const cat = (publishForm.data.category_id || '').trim()
        if (republicar && row && !row.puede_republicar && !cat) {
            alert(
                'Esta publicación está Activa o Pausada en Mercado Libre y no se puede cambiar la categoría editando la ficha. ' +
                    'Pegá el MLM correcto en «Categoría ML (opcional)» (ej. MLM437575 cámaras, MLM439043 montaje solar, MLM189958 medidores) y volvé a republicar.'
            )
            return
        }
        const msg = republicar
            ? cat
                ? `Se creará una NUEVA publicación en categoría ${cat}. La ficha Activa actual (${row?.mlm || 'MLM…'}) no cambia de categoría: finalizala en ML después para evitar duplicados. ¿Continuar?`
                : 'Se creará una nueva publicación en Mercado Libre (descripción sin enlaces ni datos de contacto). La publicación anterior puede seguir apareciendo en tu cuenta según su estado en ML. ¿Continuar?'
            : 'Publicar con datos de SYSCOM: imágenes, título, descripción (párrafos separados, sin enlaces ni teléfonos) y atributos automáticos. Precio: fórmulas Syscom. ¿Continuar?'
        if (!confirm(msg)) {
            return
        }
        publishForm.post('/syscom-ml/publish/' + productId, {
            preserveScroll: true,
            onSuccess: () => publishForm.setData('category_id', ''),
        })
    }

    const estadoBadgeClass = (estado) => {
        if (estado === 'ACTIVO') return 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950/60 dark:text-emerald-200'
        if (estado === 'PAUSADA') return 'bg-zinc-200 text-zinc-800 dark:bg-neutral-700 dark:text-zinc-100'
        if (estado === 'EN REVISION') return 'bg-amber-100 text-amber-950 dark:bg-amber-950/50 dark:text-amber-100'
        if (estado === 'INACTIVA' || estado === 'CERRADA')
            return 'bg-red-100 text-red-900 dark:bg-red-950/50 dark:text-red-200'
        if (estado === 'BLOQUEADA') return 'bg-red-100 text-red-900 dark:bg-red-950/50 dark:text-red-200'
        return 'bg-zinc-100 text-zinc-500 dark:bg-neutral-800 dark:text-zinc-400'
    }

    return (
        <>
            <Head title="SYSCOM → Mercado Libre" />
            <AppShell title="SYSCOM → Mercado Libre">
                <div className="mx-auto max-w-7xl space-y-6 p-1 text-zinc-900 dark:text-white">
                    {(flash.error || flash.err) && (
                        <div className="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
                            {flash.error || flash.err}
                        </div>
                    )}
                    {(flash.success || flash.ok) && (
                        <div className="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">
                            {flash.success || flash.ok}
                        </div>
                    )}

                    <div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <h1 className="text-xl font-semibold">Publicar productos de SYSCOM en Mercado Libre</h1>
                        <p className="mt-2 text-sm text-zinc-600 dark:text-gray-400">
                            Catálogo y <b>ventas solo con stock en {sucursal}</b> (sin existencia en Hermosillo la publicación se pausa en ML; el producto sigue listado aquí con stock 0). El precio sale de{' '}
                            <b>fórmulas → conjunto SYSCOM</b>{' '}
                            (no se usa el precio lista de SYSCOM como venta). Tras publicar, usá <b>Sincronizar precios ML</b> o el botón por fila para subir el precio
                            calculado a Mercado Libre. El job periódico también mantiene stock y precio alineados.
                        </p>
                        <p className="mt-2 text-sm text-amber-800 dark:text-amber-200/90">
                            La API de SYSCOM no entrega todo el catálogo de una vez: el sync recorre letras, marcas y <b>categorías</b> (incl. Energía / Energía Solar) filtrando siempre por Hermosillo.
                            Si falta un modelo, usá <b>Traer de SYSCOM</b> (ej. EPLMCN230). Luego <b>Publicar</b>: la categoría ML se elige con título, marca, modelo,
                            descripción y la jerarquía de categorías SYSCOM (domain_discovery con votación). Dejá <b>vacío</b> «Categoría ML» para switches, antenas, cámaras, etc.; no dejes un MLM de otro producto (ej. MLM437575 de una cámara). Si hace falta, pegá el MLM correcto{' '}
                            <b>solo para ese producto</b> (MLM1708 switches, MLM7642 antenas, MLM437575 cámaras).{' '}
                            <b>Si la publicación ya está Activa y la categoría está mal</b>, ML no la cambia al editar: pegá el MLM correcto arriba y usá <b>Republicar (nueva categoría)</b>, luego finalizá la ficha vieja en ML. Fotos pasan por el normalizador (lienzo cuadrado).
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm dark:border-neutral-800 dark:bg-neutral-950/50">
                        <span className="text-xs font-medium text-zinc-500">Cola ML:</span>
                        {COLA_OPTIONS.map((opt) => {
                            const active = (filters?.cola || '') === opt.value
                            const count =
                                opt.value === 'categorizado'
                                    ? queueCounts.categorizado
                                    : opt.value === 'publicando'
                                      ? queueCounts.publicando
                                      : opt.value === 'pendiente'
                                        ? queueCounts.pendiente
                                        : opt.value === 'publicado'
                                          ? queueCounts.publicado
                                          : opt.value === 'error'
                                            ? queueCounts.error
                                            : opt.value === 'en_cola'
                                              ? queueCounts.total
                                              : null
                            const params = new URLSearchParams()
                            if (filters?.q) params.set('q', filters.q)
                            if (opt.value) params.set('cola', opt.value)
                            const href = params.toString() ? `/syscom-ml?${params}` : '/syscom-ml'
                            return (
                                <Link
                                    key={opt.value || 'todos'}
                                    href={href}
                                    preserveScroll
                                    className={`rounded-md px-2.5 py-1 text-xs font-medium ${
                                        active
                                            ? 'bg-indigo-600 text-white'
                                            : 'bg-white text-zinc-700 ring-1 ring-zinc-200 hover:bg-zinc-100 dark:bg-neutral-900 dark:text-zinc-200 dark:ring-neutral-700'
                                    }`}
                                >
                                    {opt.label}
                                    {count !== null && count > 0 ? ` (${count})` : ''}
                                </Link>
                            )
                        })}
                    </div>

                    <form onSubmit={search} className="flex flex-wrap items-end gap-2">
                        <div className="min-w-[200px] flex-1">
                            <label className="text-xs text-zinc-500">Buscar</label>
                            <input
                                value={qForm.data.q}
                                onChange={(e) => qForm.setData('q', e.target.value)}
                                className="mt-0.5 w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                                placeholder="Título, marca, modelo"
                            />
                        </div>
                        <button
                            type="submit"
                            className="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm font-medium dark:border-neutral-700 dark:bg-neutral-800"
                        >
                            Filtrar
                        </button>
                        <button
                            type="button"
                            onClick={importFromSyscom}
                            disabled={importingSearch}
                            className="rounded-md border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-950 hover:bg-emerald-100 disabled:opacity-50 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100"
                            title="Consulta SYSCOM por modelo/marca y guarda en tu catálogo (no solo filtra lo ya importado)"
                        >
                            {importingSearch ? 'Importando…' : 'Traer de SYSCOM'}
                        </button>
                        <button
                            type="button"
                            onClick={syncCatalog}
                            className="rounded-md border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-900 dark:border-indigo-800 dark:bg-indigo-950/50 dark:text-indigo-200"
                        >
                            Sincronizar catálogo SYSCOM
                        </button>
                        <button
                            type="button"
                            onClick={refreshSyscomPrices}
                            disabled={refreshingPrices}
                            className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-950 hover:bg-amber-100 disabled:opacity-50 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-100"
                            title="Trae precios USD desde detalle SYSCOM si la tabla muestra — revisar costo"
                        >
                            {refreshingPrices ? 'Precios…' : 'Completar costos SYSCOM (página)'}
                        </button>
                        <button
                            type="button"
                            onClick={refreshMlStatus}
                            disabled={refreshing}
                            className="rounded-md border border-teal-200 bg-teal-50 px-3 py-2 text-sm font-medium text-teal-900 hover:bg-teal-100 disabled:opacity-50 dark:border-teal-800 dark:bg-teal-950/50 dark:text-teal-100 dark:hover:bg-teal-900/50"
                            title="Consulta en ML el estado y moderaciones de cada MLM en esta página"
                        >
                            {refreshing ? 'Actualizando…' : 'Actualizar estado ML'}
                        </button>
                        <button
                            type="button"
                            onClick={syncPricesOnPage}
                            disabled={syncingPrices}
                            className="rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-medium text-sky-900 hover:bg-sky-100 disabled:opacity-50 dark:border-sky-800 dark:bg-sky-950/50 dark:text-sky-100 dark:hover:bg-sky-900/50"
                            title="PUT precio y stock en ML según fórmulas SYSCOM (o precio MANUAL en cola)"
                        >
                            {syncingPrices ? 'Sincronizando…' : 'Sincronizar precios ML (página)'}
                        </button>
                        <button
                            type="button"
                            onClick={() => publishCategorizedMarketmax(20)}
                            disabled={bulkPublishing}
                            className="rounded-md border border-fuchsia-300 bg-fuchsia-50 px-3 py-2 text-sm font-semibold text-fuchsia-950 hover:bg-fuchsia-100 disabled:opacity-50 dark:border-fuchsia-800 dark:bg-fuchsia-950/40 dark:text-fuchsia-100"
                            title="Encola hasta 20 productos categorizados para comprobar el flujo real"
                        >
                            {bulkPublishing ? 'Encolando…' : 'Probar 20 categorizados'}
                        </button>

                        <button
                            type="button"
                            onClick={() => publishCategorizedMarketmax(null)}
                            disabled={bulkPublishing}
                            className="rounded-md border border-emerald-400 bg-emerald-600 px-3 py-2 text-sm font-bold text-white hover:bg-emerald-700 disabled:opacity-50 dark:border-emerald-700 dark:bg-emerald-700 dark:hover:bg-emerald-600"
                            title="Encola todos los productos con categoría aprobada, stock y sin publicación existente"
                        >
                            {bulkPublishing
                                ? 'Encolando…'
                                : 'Publicar categorizados en Marketmax'}
                        </button>
                    </form>

                    <div className="grid gap-2 text-sm text-zinc-600 dark:text-gray-400">
                        <div className="flex flex-wrap gap-3 border-y border-dashed border-zinc-200 py-2 dark:border-neutral-800">
                            <div>
                                <span className="text-xs font-medium text-zinc-500">Precio (Syscom)</span>
                                <select
                                    value={publishForm.data.price_scope}
                                    onChange={(e) => publishForm.setData('price_scope', e.target.value)}
                                    className="ml-1 rounded border border-zinc-200 bg-white px-2 py-0.5 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                    <option value="llanta">1 unidad (scope llanta)</option>
                                    <option value="par">PAR (2 piezas en fórmula)</option>
                                    <option value="juego4">Juego 4 (4 piezas en fórmula)</option>
                                </select>
                            </div>
                            <div>
                                <span className="text-xs font-medium text-zinc-500">Tienda ML</span>
                                <select
                                    value={publishForm.data.official_store_mode}
                                    onChange={(e) => publishForm.setData('official_store_mode', e.target.value)}
                                    className="ml-1 rounded border border-zinc-200 bg-white px-2 py-0.5 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                                >
                                    <option value="tobeauty">ToBeauty</option>
                                    <option value="marketmax">Marketmax</option>
                                    <option value="none">Sin tienda oficial</option>
                                </select>
                            </div>
                            <div className="min-w-[200px] flex-1">
                                <span className="text-xs font-medium text-zinc-500">Categoría ML (opcional)</span>
                                <MeliCategoryPicker
                                    value={publishForm.data.category_id}
                                    onChange={(id) => publishForm.setData('category_id', id)}
                                    meliLinked={meliLinked}
                                />
                            </div>
                            <div className="min-w-[220px] flex-1">
                                <span className="text-xs font-medium text-zinc-500">Código universal (GTIN/EAN/UPC)</span>
                                <input
                                    value={publishForm.data.universal_code}
                                    onChange={(e) => publishForm.setData('universal_code', e.target.value)}
                                    className="ml-1 w-full rounded border border-zinc-200 bg-white px-2 py-0.5 font-mono text-sm dark:border-neutral-700 dark:bg-neutral-900"
                                    placeholder="8, 12, 13 o 14 dígitos"
                                />
                            </div>
                        </div>
                        <p className="text-xs text-amber-700 dark:text-amber-300">
                            Si ML pide GTIN (error 7810), captura aquí el código universal para publicarlo.
                        </p>
                        <p className="text-xs text-zinc-600 dark:text-neutral-400">
                            <strong>Recibes ~</strong> replica el simulador ML:{' '}
                            <code className="rounded bg-zinc-100 px-1 dark:bg-neutral-800">
                                precio − comisión% − impuestos% − envío − financiamiento
                            </code>
                            . Copiá <strong>Cargo por vender → fee %</strong>, línea <strong>Impuestos → tax %</strong> sobre el precio, y montos de <strong>costo por envío</strong> desde una publicación real. Lo oficial sigue siendo{' '}
                            <strong>Recibes</strong> dentro de Mercado Libre.
                        </p>
                        {!recibesEstimateConfigured && (
                            <p className="text-xs text-amber-800 dark:text-amber-200/90">
                                Tip: agregá al menos uno entre{' '}
                                <code className="rounded bg-amber-100/80 px-1 dark:bg-amber-950/60">SYSCOM_MELI_ESTIMATE_FEE_SALE_PCT</code>,{' '}
                                <code className="rounded bg-amber-100/80 px-1 dark:bg-amber-950/60">SYSCOM_MELI_ESTIMATE_TAX_RETENTION_PCT</code>,{' '}
                                <code className="rounded bg-amber-100/80 px-1 dark:bg-amber-950/60">SYSCOM_MELI_ESTIMATE_SHIPPING_MXN</code>,{' '}
                                <code className="rounded bg-amber-100/80 px-1 dark:bg-amber-950/60">SYSCOM_MELI_ESTIMATE_FINANCING_MXN</code>, luego{' '}
                                <code className="rounded bg-amber-100/80 px-1 dark:bg-amber-950/60">php artisan config:clear</code>.
                            </p>
                        )}
                    </div>

                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <button
                            type="button"
                            onClick={() => setColumnsOpen((o) => !o)}
                            className="rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-sm font-medium text-zinc-800 hover:bg-zinc-50 dark:border-neutral-600 dark:bg-neutral-900 dark:text-zinc-200"
                        >
                            Columnas ({visibleColumnCount}/{TABLE_COLUMNS.length})
                        </button>
                        <span className="text-xs text-zinc-500 dark:text-gray-400">
                            Tu selección se guarda en este navegador.
                        </span>
                    </div>

                    {columnsOpen && (
                        <div className="rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-neutral-700 dark:bg-neutral-950/80">
                            <div className="mb-2 flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    onClick={() => applyColumnPreset('min')}
                                    className="rounded border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-neutral-600 dark:bg-neutral-900"
                                >
                                    Vista mínima
                                </button>
                                <button
                                    type="button"
                                    onClick={() => applyColumnPreset('prices')}
                                    className="rounded border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-neutral-600 dark:bg-neutral-900"
                                >
                                    Precios + MLM
                                </button>
                                <button
                                    type="button"
                                    onClick={() => applyColumnPreset('all')}
                                    className="rounded border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-neutral-600 dark:bg-neutral-900"
                                >
                                    Todas
                                </button>
                                <button
                                    type="button"
                                    onClick={() => persistColumns(defaultColumnVisibility())}
                                    className="rounded border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-neutral-600 dark:bg-neutral-900"
                                >
                                    Restablecer
                                </button>
                            </div>
                            <div className="flex flex-wrap gap-x-4 gap-y-2">
                                {TABLE_COLUMNS.map((col) => (
                                    <label
                                        key={col.id}
                                        className={`inline-flex cursor-pointer items-center gap-1.5 text-sm ${
                                            col.required ? 'opacity-60' : ''
                                        }`}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={isColVisible(col.id)}
                                            disabled={col.required}
                                            onChange={() => toggleColumn(col.id)}
                                            className="rounded border-zinc-400"
                                        />
                                        {col.label}
                                        {col.required ? ' (fija)' : ''}
                                    </label>
                                ))}
                            </div>
                        </div>
                    )}

                    <div className="overflow-x-auto rounded-xl border border-zinc-200 dark:border-neutral-800">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-zinc-50 text-xs font-semibold uppercase text-zinc-500 dark:bg-neutral-950 dark:text-gray-500">
                                <tr>
                                    {isColVisible('id') && <th className="px-3 py-2 whitespace-nowrap">ID</th>}
                                    {isColVisible('titulo') && <th className="px-3 py-2 min-w-[12rem]">Título</th>}
                                    {isColVisible('marca') && <th className="px-3 py-2">Marca</th>}
                                    {isColVisible('modelo') && <th className="px-3 py-2">Modelo</th>}
                                    {isColVisible('stock') && (
                                        <th className="px-3 py-2 whitespace-nowrap">Stock {sucursal}</th>
                                    )}
                                    {isColVisible('costo_mxn') && <th className="px-3 py-2">Costo MXN</th>}
                                    {isColVisible('precio_meli') && <th className="px-3 py-2">Precio ML</th>}
                                    {isColVisible('recibes') && (
                                        <th
                                            className="px-3 py-2 whitespace-nowrap"
                                            title="precio − fee% − impuestos% − envío − financiamiento (.env)"
                                        >
                                            Recibes&nbsp;~
                                        </th>
                                    )}
                                    {isColVisible('mlm') && <th className="px-3 py-2">MLM</th>}
                                    {isColVisible('publ_sku') && <th className="px-3 py-2">Publ. mismo SKU</th>}
                                    {isColVisible('estado') && <th className="px-3 py-2">Estado</th>}
                                    {isColVisible('acciones') && <th className="px-3 py-2" />}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-zinc-100 dark:divide-neutral-800">
                                {products.data.map((row) => (
                                    <tr key={row.id} className="hover:bg-zinc-50/80 dark:hover:bg-neutral-800/50">
                                        {isColVisible('id') && (
                                            <td className="px-3 py-2 font-mono text-xs text-zinc-500 whitespace-nowrap">
                                                {row.syscom_producto_id}
                                            </td>
                                        )}
                                        {isColVisible('titulo') && (
                                            <td className="px-3 py-2 max-w-xs truncate" title={row.titulo}>
                                                {row.titulo}
                                            </td>
                                        )}
                                        {isColVisible('marca') && <td className="px-3 py-2">{row.marca}</td>}
                                        {isColVisible('modelo') && (
                                            <td className="px-3 py-2">{row.modelo || '—'}</td>
                                        )}
                                        {isColVisible('stock') && (
                                            <td className="px-3 py-2 font-medium">{row.stock_hermosillo}</td>
                                        )}
                                        {isColVisible('costo_mxn') && (
                                            <td className="px-3 py-2 tabular-nums whitespace-nowrap">
                                                {row.costo_mxn > 0 ? mxn(row.costo_mxn) : <span className="text-zinc-400">—</span>}
                                            </td>
                                        )}
                                        {isColVisible('precio_meli') && (
                                            <td className="px-3 py-2 whitespace-nowrap">
                                                {row.precio_meli > 0 ? (
                                                    <span className="tabular-nums">{mxn(row.precio_meli)}</span>
                                                ) : row.mlm && row.meli_price_ml > 0 ? (
                                                    <>
                                                        <span className="tabular-nums text-slate-600 dark:text-slate-300">
                                                            En ML: {mxn(row.meli_price_ml)}
                                                        </span>
                                                        <div className="mt-0.5 text-[10px] text-amber-700 dark:text-amber-300">
                                                            {row.costo_mxn <= 0
                                                                ? 'Costo SYSCOM pendiente — Completar costos'
                                                                : 'Precio local pendiente — Sync precio ML o editar'}
                                                        </div>
                                                    </>
                                                ) : row.costo_mxn <= 0 ? (
                                                    <span className="text-amber-700 dark:text-amber-300">— revisar costo</span>
                                                ) : row.price_mode === 'manual' && row.precio_formula_mxn > 0 ? (
                                                    <>
                                                        <span className="text-amber-700 dark:text-amber-300">MANUAL sin precio</span>
                                                        <div className="mt-0.5 text-[10px] text-zinc-500">
                                                            Fórmula: {mxn(row.precio_formula_mxn)}
                                                        </div>
                                                    </>
                                                ) : (
                                                    <span className="text-amber-700 dark:text-amber-300">— revisar precio</span>
                                                )}
                                                {row.price_mode === 'manual' && (
                                                    <span className="ml-1 rounded bg-amber-100 px-1 text-[10px] font-semibold text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                                                        MANUAL
                                                    </span>
                                                )}
                                                {row.price_mode === 'manual' &&
                                                    row.precio_formula_mxn > 0 &&
                                                    Math.abs(row.precio_meli - row.precio_formula_mxn) > 0.02 && (
                                                        <div className="mt-0.5 text-[10px] text-zinc-500">
                                                            Fórmula: {mxn(row.precio_formula_mxn)}
                                                        </div>
                                                    )}
                                                {row.price_desync && row.meli_price_ml != null && (
                                                    <div className="mt-0.5 text-[10px] font-medium text-amber-800 dark:text-amber-200">
                                                        En ML: {mxn(row.meli_price_ml)} — usá Sync precio ML
                                                    </div>
                                                )}
                                            </td>
                                        )}
                                        {isColVisible('recibes') && (
                                            <td className="px-3 py-2 tabular-nums whitespace-nowrap">
                                                {row.recibes_estimado_mxn != null &&
                                                typeof row.costo_mxn === 'number' &&
                                                row.costo_mxn > 0 ? (
                                                    <span
                                                        className={
                                                            row.recibes_estimado_mxn >= row.costo_mxn
                                                                ? 'text-emerald-700 dark:text-emerald-300'
                                                                : 'text-red-700 dark:text-red-300'
                                                        }
                                                        title={
                                                            row.recibes_estimado_mxn >= row.costo_mxn
                                                                ? 'Recibes estimado cubre tu costo (referencia)'
                                                                : 'Recibes estimado menor al costo: revisá precio o costos ML'
                                                        }
                                                    >
                                                        {mxn(row.recibes_estimado_mxn)}
                                                    </span>
                                                ) : row.recibes_estimado_mxn != null ? (
                                                    mxn(row.recibes_estimado_mxn)
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                        )}
                                        {isColVisible('mlm') && (
                                            <td className="px-3 py-2 font-mono text-xs">
                                                {row.mlm || '—'}
                                                {row.queue_status && (
                                                    <span className="ml-1 text-[10px] text-zinc-400">
                                                        ({row.queue_status})
                                                    </span>
                                                )}
                                                {row.publish_error && (
                                                    <div className="mt-1 max-w-xs text-[10px] text-red-600 dark:text-red-300">
                                                        {row.publish_error}
                                                    </div>
                                                )}
                                            </td>
                                        )}
                                        {isColVisible('publ_sku') && (
                                            <td className="px-3 py-2 text-xs">
                                                <span
                                                    className={
                                                        row.publicaciones_mismo_sku > 1
                                                            ? 'font-semibold text-amber-800 dark:text-amber-200'
                                                            : 'text-zinc-500 dark:text-gray-400'
                                                    }
                                                    title={row.seller_sku || ''}
                                                >
                                                    {typeof row.publicaciones_mismo_sku === 'number'
                                                        ? row.publicaciones_mismo_sku
                                                        : '—'}
                                                </span>
                                                {row.publicaciones_mismo_sku > 1 && (
                                                    <div className="mt-0.5 max-w-[10rem] text-[10px] text-amber-800 dark:text-amber-200">
                                                        Varias publicaciones con el mismo seller SKU.
                                                    </div>
                                                )}
                                            </td>
                                        )}
                                        {isColVisible('estado') && (
                                            <td className="px-3 py-2">
                                                {row.meli_estado ? (
                                                    <span
                                                        className={
                                                            'inline-block rounded px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ' +
                                                            estadoBadgeClass(row.meli_estado)
                                                        }
                                                        title={[row.meli_status_raw, row.meli_sub_status]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    >
                                                        {row.meli_estado}
                                                    </span>
                                                ) : (
                                                    <span className="text-zinc-400">—</span>
                                                )}
                                                {row.meli_block_hint ? (
                                                    <div className="mt-1 max-w-xs text-[10px] text-red-700 dark:text-red-300">
                                                        {row.meli_block_hint}
                                                    </div>
                                                ) : null}
                                            </td>
                                        )}
                                        {isColVisible('acciones') && (
                                            <td className="px-3 py-2 text-right whitespace-nowrap">
                                                <div className="flex flex-col items-end gap-1.5">
                                                    {row.edit_url && (
                                                        <Link
                                                            href={row.edit_url}
                                                            className="inline-flex items-center justify-center rounded-md border border-zinc-300 bg-white px-3 py-1.5 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-neutral-600 dark:bg-neutral-900 dark:text-zinc-200 dark:hover:bg-neutral-800"
                                                        >
                                                            Editar
                                                        </Link>
                                                    )}
                                                    {row.mlm && row.precio_meli > 0 && row.can_sync_price_ml && (
                                                        <button
                                                            type="button"
                                                            onClick={() => syncPriceOne(row.id)}
                                                            disabled={syncingPriceId === row.id}
                                                            className="rounded-md border border-sky-300 bg-sky-50 px-3 py-1.5 text-xs font-semibold text-sky-950 hover:bg-sky-100 disabled:opacity-50 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100 dark:hover:bg-sky-900/50"
                                                            title="Envía precio y stock actuales a Mercado Libre"
                                                        >
                                                            {syncingPriceId === row.id
                                                                ? 'Sync…'
                                                                : row.price_desync
                                                                  ? 'Sync precio ML'
                                                                  : 'Sync ML'}
                                                        </button>
                                                    )}
                                                    {row.mlm &&
                                                        row.precio_meli > 0 &&
                                                        !row.can_sync_price_ml &&
                                                        row.puede_republicar && (
                                                            <span
                                                                className="max-w-[11rem] text-right text-[10px] text-amber-800 dark:text-amber-200"
                                                                title="ML no permite editar precio en publicaciones inactivas o cerradas"
                                                            >
                                                                Sync no disponible (INACTIVA). Usá Republicar.
                                                            </span>
                                                        )}
                                                    {row.puede_republicar &&
                                                        !row.mlm &&
                                                        needsGtin(row) && (
                                                            <div className="w-[230px] rounded-lg border border-amber-300 bg-amber-50 p-2 text-left dark:border-amber-800 dark:bg-amber-950/30">
                                                                <div className="mb-1 text-[10px] font-semibold text-amber-900 dark:text-amber-200">
                                                                    Código universal requerido
                                                                </div>

                                                                <input
                                                                    type="text"
                                                                    inputMode="numeric"
                                                                    autoComplete="off"
                                                                    maxLength={14}
                                                                    value={gtinByProduct[row.id] || ''}
                                                                    onChange={(e) =>
                                                                        setRowGtin(
                                                                            row.id,
                                                                            e.target.value
                                                                        )
                                                                    }
                                                                    onKeyDown={(e) => {
                                                                        if (
                                                                            e.key === 'Enter'
                                                                        ) {
                                                                            e.preventDefault()
                                                                            retryWithGtin(row)
                                                                        }
                                                                    }}
                                                                    className="mb-1.5 w-full rounded-md border border-amber-300 bg-white px-2 py-1.5 font-mono text-xs text-zinc-900 outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 dark:border-amber-800 dark:bg-neutral-950 dark:text-white"
                                                                    placeholder="8, 12, 13 o 14 dígitos"
                                                                />

                                                                <button
                                                                    type="button"
                                                                    onClick={() =>
                                                                        retryWithGtin(row)
                                                                    }
                                                                    disabled={
                                                                        retryingGtinId ===
                                                                        row.id
                                                                    }
                                                                    className="w-full rounded-md bg-amber-600 px-2 py-1.5 text-xs font-semibold text-white hover:bg-amber-500 disabled:cursor-wait disabled:opacity-50"
                                                                >
                                                                    {retryingGtinId ===
                                                                    row.id
                                                                        ? 'Reintentando…'
                                                                        : 'Reintentar con GTIN'}
                                                                </button>
                                                            </div>
                                                        )}

                                                    {row.puede_republicar &&
                                                        !row.mlm &&
                                                        !needsGtin(row) && (
                                                            <button
                                                                type="button"
                                                                onClick={() =>
                                                                    publish(
                                                                        row.id,
                                                                        false
                                                                    )
                                                                }
                                                                disabled={
                                                                    publishForm.processing
                                                                }
                                                                className="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                                                            >
                                                                Publicar
                                                            </button>
                                                        )}
                                                    {canRepublishRow(row) && (
                                                        <button
                                                            type="button"
                                                            onClick={() => publish(row.id, true, row)}
                                                            disabled={publishForm.processing}
                                                            title={
                                                                row.puede_republicar
                                                                    ? 'Crear una nueva publicación en Mercado Libre'
                                                                    : 'Publicación Activa/Pausada: requiere categoría ML manual arriba'
                                                            }
                                                            className="rounded-md border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-950 hover:bg-amber-100 disabled:opacity-50 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100 dark:hover:bg-amber-900/50"
                                                        >
                                                            {row.puede_republicar ? 'Republicar' : 'Republicar (nueva categoría)'}
                                                        </button>
                                                    )}
                                                    {row.mlm && !row.puede_republicar && !categoryIdFilled && (
                                                        <span
                                                            className="max-w-[12rem] text-right text-[10px] text-slate-500 dark:text-slate-400"
                                                            title="La publicación actual está activa. Solo captura una categoría manual si necesitas crear una publicación nueva en otra categoría."
                                                        >
                                                            Categoría manual opcional para republicar
                                                        </span>
                                                    )}
                                                    {row.mlm && row.ml_permalink && (
                                                        <a
                                                            href={row.ml_permalink}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="text-xs text-indigo-600 dark:text-indigo-400"
                                                        >
                                                            Abrir
                                                        </a>
                                                    )}
                                                </div>
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {(!products.data || products.data.length === 0) && (
                        <div className="space-y-2 text-sm text-zinc-500">
                            <p>
                                No hay productos importados en tu catálogo local todavía
                                {filters?.q ? (
                                    <>
                                        {' '}
                                        para «<span className="font-medium text-zinc-700 dark:text-zinc-300">{filters.q}</span>».
                                    </>
                                ) : (
                                    '.'
                                )}
                            </p>
                            <p>
                                El buscador solo muestra productos ya importados. Si existen en syscom.mx (Hermosillo), usá{' '}
                                <strong className="font-medium text-emerald-800 dark:text-emerald-300">Traer de SYSCOM</strong> con el
                                modelo exacto, o ejecutá <strong className="font-medium">Sincronizar catálogo</strong> (barrido completo).
                            </p>
                        </div>
                    )}

                    {products.links && products.links.length > 3 && (
                        <div className="flex flex-wrap gap-2 text-sm">
                            {products.links.map((l, i) => (
                                <Link
                                    key={i}
                                    href={l.url || '#'}
                                    className={
                                        l.active
                                            ? 'rounded border border-indigo-500 bg-indigo-50 px-2 py-1 dark:bg-indigo-950/50'
                                            : 'rounded border border-zinc-200 px-2 py-1 dark:border-neutral-700'
                                    }
                                    preserveState
                                >
                                    <span dangerouslySetInnerHTML={{ __html: l.label }} />
                                </Link>
                            ))}
                        </div>
                    )}
                </div>
            </AppShell>
        </>
    )
}
