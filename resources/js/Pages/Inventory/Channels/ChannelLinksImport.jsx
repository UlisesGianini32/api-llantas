import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState, useEffect } from 'react'

const statusConfig = {
    MATCHED: { label: 'Listos para vincular', color: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800' },
    ALREADY_LINKED: { label: 'Ya vinculados', color: 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800' },
    MISSING_SKU: { label: 'Sin SKU en tienda', color: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800' },
    PRODUCT_NOT_FOUND: { label: 'No encontrado en almacén', color: 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:border-rose-800' },
    AMBIGUOUS: { label: 'SKU múltiple', color: 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-950/40 dark:text-purple-300 dark:border-purple-800' },
    CONFLICT: { label: 'Conflicto', color: 'bg-red-50 text-red-700 border-red-200 dark:bg-red-950/40 dark:text-red-300 dark:border-red-800' },
    UNSUPPORTED: { label: 'No soportado', color: 'bg-slate-50 text-slate-700 border-slate-200 dark:bg-slate-900 dark:text-slate-400 dark:border-slate-800' },
}

export default function ChannelLinksImport({
    activeChannel = 'mercado_libre',
    preview = {},
    accounts = [],
    canApply = false,
    amazonLoadedCount = 0,
    shopifyDomain = '',
}) {
    const { flash } = usePage().props
    const initial = preview.filters || {}
    const [search, setSearch] = useState(initial.search || '')
    const [result, setResult] = useState(initial.result || '')
    const [accountKey, setAccountKey] = useState(initial.account_key || '')
    const [selectedRows, setSelectedRows] = useState([])
    const [isSubmitting, setIsSubmitting] = useState(false)

    // Amazon upload state
    const [amazonTab, setAmazonTab] = useState('file') // 'file' | 'paste' | 'manual'
    const [amazonFile, setAmazonFile] = useState(null)
    const [amazonPastedText, setAmazonPastedText] = useState('')
    const [isUploadingAmazon, setIsUploadingAmazon] = useState(false)

    // Modal state for manual mapping
    const [mappingModalRow, setMappingModalRow] = useState(null)
    const [productQuery, setProductQuery] = useState('')
    const [searchResults, setSearchResults] = useState([])
    const [isSearching, setIsSearching] = useState(false)

    const counts = preview.counts || {}
    const rows = preview.rows || []

    const switchChannel = (channelKey) => {
        router.get('/almacen/canales/importar', {
            channel: channelKey,
            analyze: channelKey !== 'mercado_libre' ? 1 : undefined,
        }, { preserveState: false, preserveScroll: false })
    }

    const executeFilter = (newResult = result) => {
        router.get('/almacen/canales/importar', {
            channel: activeChannel,
            analyze: 1,
            search,
            result: newResult,
            account_key: accountKey,
        }, { preserveState: true, replace: true })
    }

    const handleSearchSubmit = (event) => {
        event.preventDefault()
        executeFilter(result)
    }

    const handleStatusCardClick = (status) => {
        const next = result === status ? '' : status
        setResult(next)
        executeFilter(next)
    }

    const applyAllMatched = () => {
        const total = counts.MATCHED || 0
        if (total === 0) return
        if (!confirm(`¿Deseas importar todas las coincidencias válidas (${total} vínculos en ${channelLabel(activeChannel)})?`)) {
            return
        }
        setIsSubmitting(true)
        router.post('/almacen/canales/importar', {
            channel: activeChannel,
            search,
            result,
            account_key: accountKey,
        }, {
            preserveScroll: true,
            onFinish: () => setIsSubmitting(false),
        })
    }

    const toggleSelectAll = (e) => {
        if (e.target.checked) {
            const matchedRows = rows.filter(r => r.status === 'MATCHED')
            setSelectedRows(matchedRows)
        } else {
            setSelectedRows([])
        }
    }

    const getRowKey = (row) => {
        return row.mlm ? `${row.mlm}-${row.variation_id || ''}` : (row.external_variant_id || row.seller_sku || row.asin || Math.random())
    }

    const toggleRowSelect = (row) => {
        const key = getRowKey(row)
        const exists = selectedRows.some(r => getRowKey(r) === key)
        if (exists) {
            setSelectedRows(selectedRows.filter(r => getRowKey(r) !== key))
        } else {
            setSelectedRows([...selectedRows, row])
        }
    }

    const applySelected = () => {
        if (selectedRows.length === 0) return
        if (!confirm(`¿Importar ${selectedRows.length} publicaciones seleccionadas de ${channelLabel(activeChannel)}?`)) return

        setIsSubmitting(true)
        router.post('/almacen/canales/vincular-seleccionados', {
            channel: activeChannel,
            items: selectedRows.map(r => ({
                inventory_product_id: r.inventory_product_id,
                account_key: r.account_key,
                listing_id: r.mlm || r.seller_sku || r.external_listing_id,
                variant_id: r.variation_id || r.external_variant_id,
                mlm: r.mlm,
                variation_id: r.variation_id,
                external_product_id: r.catalog_product_id || r.asin || r.external_product_id,
                external_url: r.external_url,
                remote_status: r.remote_status,
                remote_price: r.remote_price,
                remote_currency: r.remote_currency || 'MXN',
                title: r.title || r.amazon_title || r.shopify_title,
            })),
        }, {
            preserveScroll: true,
            onFinish: () => {
                setIsSubmitting(false)
                setSelectedRows([])
            },
        })
    }

    // Modal search debounce
    useEffect(() => {
        if (!productQuery || productQuery.length < 2) {
            setSearchResults([])
            return
        }

        const timeout = setTimeout(async () => {
            setIsSearching(true)
            try {
                const res = await fetch(`/almacen/canales/buscar-productos?q=${encodeURIComponent(productQuery)}`)
                if (res.ok) {
                    const data = await res.json()
                    setSearchResults(data)
                }
            } catch (err) {
                console.error(err)
            } finally {
                setIsSearching(false)
            }
        }, 300)

        return () => clearTimeout(timeout)
    }, [productQuery])

    const openMappingModal = (row) => {
        setMappingModalRow(row)
        setProductQuery(row.sku || row.seller_sku || '')
        setSearchResults([])
    }

    const confirmManualMapping = (product) => {
        if (!mappingModalRow || !product) return
        if (!confirm(`¿Vincular ${mappingModalRow.mlm || mappingModalRow.seller_sku || mappingModalRow.sku || 'esta publicación'} con "${product.name}" (${product.sku})?`)) return

        router.post('/almacen/canales/vincular-manual', {
            channel: activeChannel,
            inventory_product_id: product.id,
            account_key: mappingModalRow.account_key || '',
            external_listing_id: mappingModalRow.mlm || mappingModalRow.seller_sku || mappingModalRow.external_listing_id || 'AMZ',
            external_variant_id: mappingModalRow.variation_id || mappingModalRow.external_variant_id || null,
            external_product_id: mappingModalRow.catalog_product_id || mappingModalRow.asin || mappingModalRow.external_product_id || null,
            external_url: mappingModalRow.external_url || null,
            remote_status: mappingModalRow.remote_status || 'active',
            remote_price: mappingModalRow.remote_price || null,
            remote_currency: mappingModalRow.remote_currency || 'MXN',
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setMappingModalRow(null)
                executeFilter(result)
            },
        })
    }

    const handleAmazonReportUpload = (e) => {
        e.preventDefault()
        if (amazonTab === 'file' && !amazonFile) {
            alert('Por favor selecciona un archivo (.txt, .tsv, .csv o .xlsx).')
            return
        }
        if (amazonTab === 'paste' && !amazonPastedText.trim()) {
            alert('Por favor pega al menos una línea con SKU o ASIN.')
            return
        }

        setIsUploadingAmazon(true)
        const formData = new FormData()
        if (amazonTab === 'file') {
            formData.append('file', amazonFile)
        } else {
            formData.append('pasted_text', amazonPastedText)
        }

        router.post('/almacen/canales/amazon/cargar-reporte', formData, {
            onFinish: () => setIsUploadingAmazon(false),
        })
    }

    const handleClearAmazonReport = () => {
        if (!confirm('¿Limpiar los productos de Amazon cargados en memoria?')) return
        router.post('/almacen/canales/amazon/limpiar-reporte')
    }

    function channelLabel(ch) {
        if (ch === 'shopify') return 'Shopify'
        if (ch === 'amazon') return 'Amazon'
        return 'Mercado Libre'
    }

    return (
        <AppShell title="Vincular publicaciones">
            <Head title="Vincular publicaciones con Inventario" />

            <div className="space-y-6">
                {/* Encabezado Principal */}
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-300">
                            Inventario / Canales
                        </p>
                        <h1 className="mt-1 text-3xl font-bold text-slate-900 dark:text-white">
                            Vincular publicaciones
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            Conecta las publicaciones y variantes de tus tiendas con los productos de tu catálogo de almacén.
                        </p>
                    </div>

                    <Link
                        href="/almacen/canales"
                        className="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400 self-start sm:self-auto"
                    >
                        ← Ver vínculos existentes
                    </Link>
                </div>

                {/* Mensajes Flash */}
                {flash?.success && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-200">
                        {flash.error}
                    </div>
                )}

                {/* Pestañas de Canales (Mercado Libre, Shopify, Amazon) */}
                <div className="flex border-b border-slate-200 dark:border-neutral-800 gap-2">
                    <button
                        type="button"
                        onClick={() => switchChannel('mercado_libre')}
                        className={`flex items-center gap-2 border-b-2 px-5 py-3 text-sm font-bold transition ${
                            activeChannel === 'mercado_libre'
                                ? 'border-amber-500 text-amber-600 dark:text-amber-400'
                                : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white'
                        }`}
                    >
                        <span>🟡 Mercado Libre</span>
                    </button>

                    <button
                        type="button"
                        onClick={() => switchChannel('shopify')}
                        className={`flex items-center gap-2 border-b-2 px-5 py-3 text-sm font-bold transition ${
                            activeChannel === 'shopify'
                                ? 'border-emerald-500 text-emerald-600 dark:text-emerald-400'
                                : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white'
                        }`}
                    >
                        <span>🟢 Shopify</span>
                        {shopifyDomain ? (
                            <span className="text-[11px] font-normal text-slate-400">({shopifyDomain})</span>
                        ) : null}
                    </button>

                    <button
                        type="button"
                        onClick={() => switchChannel('amazon')}
                        className={`flex items-center gap-2 border-b-2 px-5 py-3 text-sm font-bold transition ${
                            activeChannel === 'amazon'
                                ? 'border-orange-500 text-orange-600 dark:text-orange-400'
                                : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white'
                        }`}
                    >
                        <span>🟠 Amazon</span>
                        {amazonLoadedCount > 0 ? (
                            <span className="rounded bg-orange-100 text-orange-800 dark:bg-orange-950/60 dark:text-orange-300 px-1.5 py-0.2 text-xs">
                                {amazonLoadedCount}
                            </span>
                        ) : null}
                    </button>
                </div>

                {/* Barra de Filtros / Herramientas Específicas por Canal */}
                {activeChannel === 'mercado_libre' && (
                    <form
                        onSubmit={handleSearchSubmit}
                        className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center dark:border-neutral-800 dark:bg-neutral-900 shadow-sm"
                    >
                        <div className="flex-1">
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Buscar por SKU, MLM o título de Mercado Libre..."
                                className="w-full rounded-xl border border-slate-300 px-4 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                            />
                        </div>

                        {accounts.length > 0 && (
                            <select
                                value={accountKey}
                                onChange={(e) => setAccountKey(e.target.value)}
                                className="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                            >
                                <option value="">Todas las cuentas ML</option>
                                {accounts.map(acc => (
                                    <option key={acc.id} value={acc.id}>{acc.nickname || `Cuenta #${acc.id}`}</option>
                                ))}
                            </select>
                        )}

                        <button
                            type="submit"
                            className="rounded-xl bg-slate-900 px-5 py-2 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-white dark:text-slate-900 transition"
                        >
                            Analizar publicaciones
                        </button>
                    </form>
                )}

                {activeChannel === 'shopify' && (
                    <form
                        onSubmit={handleSearchSubmit}
                        className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center dark:border-neutral-800 dark:bg-neutral-900 shadow-sm"
                    >
                        <div className="flex-1">
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Buscar por SKU, código de barras o título de Shopify..."
                                className="w-full rounded-xl border border-slate-300 px-4 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                            />
                        </div>

                        <button
                            type="submit"
                            className="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 transition"
                        >
                            Consultar y analizar Shopify
                        </button>
                    </form>
                )}

                {activeChannel === 'amazon' && (
                    <div className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900 shadow-sm">
                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 dark:border-neutral-800 pb-3">
                            <div>
                                <h3 className="font-bold text-base text-slate-900 dark:text-white">
                                    Cargar listados de Amazon para vincular
                                </h3>
                                <p className="text-xs text-slate-500 mt-0.5">
                                    Amazon no permite descargar el catálogo de golpe por API; puedes subir el reporte de inventario activo descargado de Seller Central o pegar tu lista de SKUs y ASINs.
                                </p>
                            </div>

                            {amazonLoadedCount > 0 && (
                                <div className="flex items-center gap-2">
                                    <span className="text-xs text-emerald-600 font-bold bg-emerald-50 dark:bg-emerald-950/60 px-2.5 py-1 rounded-lg border border-emerald-200">
                                        ✓ {amazonLoadedCount} ítems en memoria
                                    </span>
                                    <button
                                        type="button"
                                        onClick={handleClearAmazonReport}
                                        className="text-xs text-rose-600 hover:underline font-semibold"
                                    >
                                        Limpiar
                                    </button>
                                </div>
                            )}
                        </div>

                        <div className="flex gap-2">
                            <button
                                type="button"
                                onClick={() => setAmazonTab('file')}
                                className={`px-3 py-1.5 text-xs font-bold rounded-lg transition ${
                                    amazonTab === 'file' ? 'bg-orange-500 text-white' : 'bg-slate-100 text-slate-600 dark:bg-neutral-800 dark:text-slate-300'
                                }`}
                            >
                                📄 Subir archivo (.txt / .csv / .xlsx)
                            </button>
                            <button
                                type="button"
                                onClick={() => setAmazonTab('paste')}
                                className={`px-3 py-1.5 text-xs font-bold rounded-lg transition ${
                                    amazonTab === 'paste' ? 'bg-orange-500 text-white' : 'bg-slate-100 text-slate-600 dark:bg-neutral-800 dark:text-slate-300'
                                }`}
                            >
                                📋 Pegar texto (SKUs y ASINs)
                            </button>
                            <button
                                type="button"
                                onClick={() => openMappingModal({ seller_sku: '', asin: '' })}
                                className="ml-auto px-3 py-1.5 text-xs font-bold rounded-lg border border-indigo-300 text-indigo-600 hover:bg-indigo-50 dark:border-indigo-700 dark:text-indigo-400 transition"
                            >
                                + Vincular 1 producto a mano
                            </button>
                        </div>

                        <form onSubmit={handleAmazonReportUpload} className="space-y-3">
                            {amazonTab === 'file' ? (
                                <div className="flex flex-col sm:flex-row items-center gap-3">
                                    <input
                                        type="file"
                                        accept=".txt,.tsv,.csv,.xlsx,.xls"
                                        onChange={(e) => setAmazonFile(e.target.files[0] || null)}
                                        className="w-full sm:flex-1 text-sm border border-slate-300 dark:border-neutral-700 rounded-xl p-2 dark:bg-neutral-950"
                                    />
                                    <button
                                        type="submit"
                                        disabled={isUploadingAmazon || !amazonFile}
                                        className="w-full sm:w-auto rounded-xl bg-orange-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-orange-700 disabled:opacity-50 transition"
                                    >
                                        {isUploadingAmazon ? 'Procesando...' : 'Analizar archivo de Amazon'}
                                    </button>
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    <textarea
                                        rows={4}
                                        value={amazonPastedText}
                                        onChange={(e) => setAmazonPastedText(e.target.value)}
                                        placeholder="Pega aquí los SKUs o ASINs de Amazon (un producto por línea, ej: SKU123 TAB ASIN456)..."
                                        className="w-full rounded-xl border border-slate-300 p-3 text-xs font-mono dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                    <button
                                        type="submit"
                                        disabled={isUploadingAmazon || !amazonPastedText.trim()}
                                        className="rounded-xl bg-orange-600 px-5 py-2 text-sm font-semibold text-white hover:bg-orange-700 disabled:opacity-50 transition"
                                    >
                                        {isUploadingAmazon ? 'Analizando...' : 'Analizar texto de Amazon'}
                                    </button>
                                </div>
                            )}
                        </form>
                    </div>
                )}

                {/* Tarjetas de Estados (KPIs) */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-6">
                    {Object.entries(statusConfig).map(([statusKey, cfg]) => {
                        const count = counts[statusKey] ?? 0
                        const isSelected = result === statusKey
                        return (
                            <button
                                key={statusKey}
                                type="button"
                                onClick={() => handleStatusCardClick(statusKey)}
                                className={`flex flex-col justify-between rounded-xl border p-3 text-left transition ${cfg.color} ${
                                    isSelected ? 'ring-2 ring-indigo-500 scale-[1.02]' : 'hover:opacity-90'
                                }`}
                            >
                                <span className="text-[11px] font-bold uppercase tracking-wider">{cfg.label}</span>
                                <span className="mt-2 text-2xl font-black">{count}</span>
                            </button>
                        )
                    })}
                </div>

                {/* Botones de Acción Masiva */}
                <div className="flex flex-wrap items-center justify-between gap-3 bg-slate-50 dark:bg-neutral-950/60 p-3 rounded-xl border border-slate-200 dark:border-neutral-800">
                    <div className="flex items-center gap-2">
                        {canApply && (counts.MATCHED || 0) > 0 && (
                            <button
                                type="button"
                                onClick={applyAllMatched}
                                disabled={isSubmitting}
                                className="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white shadow hover:bg-emerald-700 disabled:opacity-50 transition"
                            >
                                ✓ Vincular todas las {counts.MATCHED} coincidencias listas
                            </button>
                        )}

                        {canApply && selectedRows.length > 0 && (
                            <button
                                type="button"
                                onClick={applySelected}
                                disabled={isSubmitting}
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white shadow hover:bg-indigo-700 disabled:opacity-50 transition"
                            >
                                Vincular {selectedRows.length} seleccionados
                            </button>
                        )}
                    </div>

                    <div className="text-xs text-slate-500">
                        Mostrando <strong>{rows.length}</strong> publicaciones analizadas
                    </div>
                </div>

                {/* Tabla de Publicaciones */}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-neutral-800">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500 dark:bg-neutral-950">
                                <tr>
                                    <th className="w-10 px-4 py-3.5 text-center">
                                        <input
                                            type="checkbox"
                                            onChange={toggleSelectAll}
                                            checked={rows.filter(r => r.status === 'MATCHED').length > 0 && selectedRows.length === rows.filter(r => r.status === 'MATCHED').length}
                                            className="rounded border-slate-300 text-indigo-600"
                                        />
                                    </th>
                                    <th className="px-4 py-3.5">Estado</th>
                                    <th className="px-4 py-3.5">Identificador Tienda</th>
                                    <th className="px-4 py-3.5">SKU Tienda</th>
                                    <th className="px-4 py-3.5">Título en Tienda</th>
                                    <th className="px-4 py-3.5">Coincidencia en Almacén</th>
                                    <th className="px-4 py-3.5 text-right">Acción</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                {rows.length === 0 ? (
                                    <tr>
                                        <td colSpan="7" className="p-12 text-center text-sm text-slate-500">
                                            {activeChannel === 'amazon' && amazonLoadedCount === 0 ? (
                                                <div>
                                                    <p className="font-semibold text-slate-700 dark:text-slate-300">
                                                        No hay listados de Amazon para analizar.
                                                    </p>
                                                    <p className="text-xs mt-1 text-slate-500">
                                                        Sube tu reporte de inventario activo descargado de Seller Central o pega tus SKUs arriba.
                                                    </p>
                                                </div>
                                            ) : (
                                                'No hay publicaciones que coincidan con los filtros seleccionados.'
                                            )}
                                        </td>
                                    </tr>
                                ) : (
                                    rows.map((row) => {
                                        const key = getRowKey(row)
                                        const isSelected = selectedRows.some(r => getRowKey(r) === key)
                                        const cfg = statusConfig[row.status] || statusConfig.UNSUPPORTED
                                        const storeSku = row.sku || row.seller_sku || row.variant_sku || '—'
                                        const identifier = row.mlm || row.external_variant_id || row.asin || row.seller_sku || '—'
                                        const title = row.title || row.amazon_title || row.shopify_title || '—'

                                        return (
                                            <tr
                                                key={key}
                                                className={`hover:bg-slate-50/70 dark:hover:bg-neutral-950/50 transition align-top ${
                                                    isSelected ? 'bg-indigo-50/40 dark:bg-indigo-950/20' : ''
                                                }`}
                                            >
                                                <td className="px-4 py-3.5 text-center">
                                                    {row.status === 'MATCHED' && (
                                                        <input
                                                            type="checkbox"
                                                            checked={isSelected}
                                                            onChange={() => toggleRowSelect(row)}
                                                            className="rounded border-slate-300 text-indigo-600"
                                                        />
                                                    )}
                                                </td>

                                                <td className="px-4 py-3.5">
                                                    <span className={`inline-flex rounded-lg px-2.5 py-0.5 text-xs font-bold border ${cfg.color}`}>
                                                        {cfg.label}
                                                    </span>
                                                    {row.reason && (
                                                        <div className="text-[11px] text-slate-400 mt-1 max-w-[180px] break-words">
                                                            {row.reason}
                                                        </div>
                                                    )}
                                                </td>

                                                <td className="px-4 py-3.5 font-mono text-xs font-bold text-slate-900 dark:text-white">
                                                    {identifier}
                                                    {row.variation_id && (
                                                        <div className="text-[10px] text-slate-400 font-normal">
                                                            Var: {row.variation_id}
                                                        </div>
                                                    )}
                                                </td>

                                                <td className="px-4 py-3.5 font-mono text-xs text-slate-700 dark:text-slate-300">
                                                    {storeSku}
                                                    {row.barcode && (
                                                        <div className="text-[10px] text-slate-400">
                                                            Code: {row.barcode}
                                                        </div>
                                                    )}
                                                </td>

                                                <td className="px-4 py-3.5 text-xs text-slate-800 dark:text-slate-200 max-w-[240px]">
                                                    <div className="line-clamp-2" title={title}>
                                                        {title}
                                                    </div>
                                                    {row.remote_price && (
                                                        <div className="mt-0.5 text-[11px] font-semibold text-emerald-600">
                                                            ${Number(row.remote_price).toFixed(2)}
                                                        </div>
                                                    )}
                                                </td>

                                                <td className="px-4 py-3.5 text-xs">
                                                    {row.inventory_product_id ? (
                                                        <div className="space-y-0.5">
                                                            <div className="font-bold text-indigo-600 dark:text-indigo-400">
                                                                {row.inventory_product_name}
                                                            </div>
                                                            <div className="font-mono text-[11px] text-slate-500">
                                                                SKU: {row.inventory_product_sku}
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <span className="text-slate-400 italic">Sin producto asignado</span>
                                                    )}
                                                </td>

                                                <td className="px-4 py-3.5 text-right whitespace-nowrap">
                                                    <button
                                                        type="button"
                                                        onClick={() => openMappingModal(row)}
                                                        className="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-indigo-600 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-indigo-300 shadow-sm transition"
                                                    >
                                                        {row.inventory_product_id ? 'Cambiar producto...' : 'Vincular manual...'}
                                                    </button>
                                                </td>
                                            </tr>
                                        )
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Modal de Búsqueda y Vinculación Manual */}
                {mappingModalRow && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm">
                        <div className="w-full max-w-xl rounded-2xl bg-white p-6 shadow-2xl dark:bg-neutral-900 border border-slate-200 dark:border-neutral-800 space-y-4">
                            <div className="flex items-center justify-between border-b pb-3 dark:border-neutral-800">
                                <h3 className="font-bold text-lg text-slate-900 dark:text-white">
                                    Vincular ítem de {channelLabel(activeChannel)} con Almacén
                                </h3>
                                <button
                                    type="button"
                                    onClick={() => setMappingModalRow(null)}
                                    className="text-slate-400 hover:text-slate-600 text-lg font-bold"
                                >
                                    ✕
                                </button>
                            </div>

                            <div className="bg-slate-50 dark:bg-neutral-950 p-3 rounded-xl border border-slate-200 dark:border-neutral-800 text-xs space-y-1">
                                <div><strong>Identificador:</strong> <span className="font-mono">{mappingModalRow.mlm || mappingModalRow.external_variant_id || mappingModalRow.seller_sku || '—'}</span></div>
                                <div><strong>SKU Tienda:</strong> <span className="font-mono">{mappingModalRow.sku || mappingModalRow.seller_sku || '—'}</span></div>
                                <div className="truncate"><strong>Título:</strong> {mappingModalRow.title || mappingModalRow.amazon_title || mappingModalRow.shopify_title || '—'}</div>
                            </div>

                            <div>
                                <label className="block text-xs font-bold uppercase text-slate-600 dark:text-slate-400 mb-1">
                                    Buscar producto en el catálogo de almacén:
                                </label>
                                <input
                                    autoFocus
                                    value={productQuery}
                                    onChange={(e) => setProductQuery(e.target.value)}
                                    placeholder="Escribe el nombre, SKU o código de barras..."
                                    className="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                                {isSearching && <p className="text-xs text-indigo-600 mt-1">Buscando productos...</p>}
                            </div>

                            <div className="max-h-60 overflow-y-auto divide-y divide-slate-100 dark:divide-neutral-800 rounded-xl border border-slate-200 dark:border-neutral-800">
                                {searchResults.length === 0 ? (
                                    <div className="p-4 text-center text-xs text-slate-400">
                                        {productQuery.length < 2 ? 'Escribe al menos 2 caracteres para buscar.' : 'No se encontraron productos coincidentes.'}
                                    </div>
                                ) : (
                                    searchResults.map((prod) => (
                                        <div
                                            key={prod.id}
                                            className="flex items-center justify-between p-3 hover:bg-slate-50 dark:hover:bg-neutral-800/60 transition cursor-pointer"
                                            onClick={() => confirmManualMapping(prod)}
                                        >
                                            <div className="min-w-0 flex-1 pr-3">
                                                <div className="font-bold text-sm text-slate-900 dark:text-white truncate">{prod.name}</div>
                                                <div className="flex items-center gap-2 mt-0.5 text-xs text-slate-500 font-mono">
                                                    <span>SKU: {prod.sku}</span>
                                                    {prod.barcode && <span>· Barcode: {prod.barcode}</span>}
                                                </div>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={(e) => {
                                                    e.stopPropagation()
                                                    confirmManualMapping(prod)
                                                }}
                                                className="shrink-0 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-indigo-700 transition"
                                            >
                                                Seleccionar
                                            </button>
                                        </div>
                                    ))
                                )}
                            </div>

                            <div className="flex justify-end pt-2">
                                <button
                                    type="button"
                                    onClick={() => setMappingModalRow(null)}
                                    className="rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-neutral-800 dark:text-slate-300"
                                >
                                    Cancelar
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AppShell>
    )
}
