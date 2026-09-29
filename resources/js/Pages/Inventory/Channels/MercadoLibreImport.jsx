import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState, useEffect } from 'react'

const statusConfig = {
    MATCHED: { label: 'Listos para vincular', color: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800' },
    ALREADY_LINKED: { label: 'Ya vinculados', color: 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800' },
    MISSING_SKU: { label: 'Sin SKU en MeLi', color: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800' },
    PRODUCT_NOT_FOUND: { label: 'No encontrado', color: 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:border-rose-800' },
    AMBIGUOUS: { label: 'SKU múltiple', color: 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-950/40 dark:text-purple-300 dark:border-purple-800' },
    CONFLICT: { label: 'Conflicto', color: 'bg-red-50 text-red-700 border-red-200 dark:bg-red-950/40 dark:text-red-300 dark:border-red-800' },
    UNSUPPORTED: { label: 'No soportado', color: 'bg-slate-50 text-slate-700 border-slate-200 dark:bg-slate-900 dark:text-slate-400 dark:border-slate-800' },
}

export default function MercadoLibreImport({ preview, accounts = [], canApply = false }) {
    const { flash } = usePage().props
    const initial = preview.filters || {}
    const [search, setSearch] = useState(initial.search || '')
    const [result, setResult] = useState(initial.result || '')
    const [accountKey, setAccountKey] = useState(initial.account_key || '')
    const [selectedRows, setSelectedRows] = useState([])
    const [isSubmitting, setIsSubmitting] = useState(false)

    // Modal state for manual assignment
    const [mappingModalRow, setMappingModalRow] = useState(null)
    const [productQuery, setProductQuery] = useState('')
    const [searchResults, setSearchResults] = useState([])
    const [isSearching, setIsSearching] = useState(false)

    const counts = preview.counts || {}
    const rows = preview.rows || []

    const executeFilter = (newResult = result) => {
        router.get('/almacen/canales/mercado-libre/importar', {
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
        if (!confirm(`¿Deseas importar todas las coincidencias válidas (${counts.MATCHED || 0} vínculos)?`)) {
            return
        }
        setIsSubmitting(true)
        router.post('/almacen/canales/mercado-libre/importar', {
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

    const toggleRowSelect = (row) => {
        const exists = selectedRows.some(r => r.mlm === row.mlm && r.variation_id === row.variation_id)
        if (exists) {
            setSelectedRows(selectedRows.filter(r => !(r.mlm === row.mlm && r.variation_id === row.variation_id)))
        } else {
            setSelectedRows([...selectedRows, row])
        }
    }

    const applySelected = () => {
        if (selectedRows.length === 0) return
        if (!confirm(`¿Importar ${selectedRows.length} publicaciones seleccionadas?`)) return

        setIsSubmitting(true)
        router.post('/almacen/canales/mercado-libre/vincular-seleccionados', {
            items: selectedRows.map(r => ({
                inventory_product_id: r.inventory_product_id,
                account_key: r.account_key,
                mlm: r.mlm,
                variation_id: r.variation_id,
                external_product_id: r.catalog_product_id,
                external_url: r.external_url,
                remote_status: r.remote_status,
                remote_price: r.remote_price,
                remote_currency: r.remote_currency,
            }))
        }, {
            preserveScroll: true,
            onFinish: () => {
                setIsSubmitting(false)
                setSelectedRows([])
            }
        })
    }

    const handleSingleApply = (row) => {
        setIsSubmitting(true)
        router.post('/almacen/canales/mercado-libre/vincular-manual', {
            inventory_product_id: row.inventory_product_id,
            account_key: row.account_key,
            external_listing_id: row.mlm,
            external_variant_id: row.variation_id,
            external_product_id: row.catalog_product_id,
            external_url: row.external_url,
            remote_status: row.remote_status,
            remote_price: row.remote_price,
            remote_currency: row.remote_currency,
        }, {
            preserveScroll: true,
            onFinish: () => setIsSubmitting(false),
        })
    }

    // Interactive product search for manual modal
    useEffect(() => {
        if (!mappingModalRow || !productQuery.trim()) {
            setSearchResults([])
            return
        }

        const timeout = setTimeout(() => {
            setIsSearching(true)
            fetch(`/almacen/canales/mercado-libre/buscar-productos?q=${encodeURIComponent(productQuery)}`)
                .then(res => res.json())
                .then(data => {
                    setSearchResults(data)
                    setIsSearching(false)
                })
                .catch(() => setIsSearching(false))
        }, 300)

        return () => clearTimeout(timeout)
    }, [productQuery, mappingModalRow])

    const openMappingModal = (row) => {
        setMappingModalRow(row)
        setProductQuery(row.sku || '')
        setSearchResults([])
    }

    const confirmManualMapping = (product) => {
        if (!mappingModalRow) return

        setIsSubmitting(true)
        router.post('/almacen/canales/mercado-libre/vincular-manual', {
            inventory_product_id: product.id,
            account_key: mappingModalRow.account_key,
            external_listing_id: mappingModalRow.mlm,
            external_variant_id: mappingModalRow.variation_id,
            external_product_id: mappingModalRow.catalog_product_id,
            external_url: mappingModalRow.external_url,
            remote_status: mappingModalRow.remote_status,
            remote_price: mappingModalRow.remote_price,
            remote_currency: mappingModalRow.remote_currency,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setMappingModalRow(null)
                executeFilter(result)
            },
            onFinish: () => setIsSubmitting(false),
        })
    }

    return (
        <AppShell title="Mapeo Asistido de Mercado Libre">
            <Head title="Mapeo Asistido de Mercado Libre" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <div>
                        <Link href="/almacen/canales" className="inline-flex items-center text-sm font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">
                            ← Volver al listado de canales
                        </Link>
                        <h1 className="mt-2 text-3xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                            Mapeo y Enlace de Mercado Libre
                        </h1>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Empareja publicaciones remotas con tu catálogo de almacén por SKU, código de barras o asignación asistida.
                        </p>
                    </div>

                    {canApply && (
                        <div className="flex flex-wrap items-center gap-3">
                            {selectedRows.length > 0 && (
                                <button
                                    type="button"
                                    onClick={applySelected}
                                    disabled={isSubmitting}
                                    className="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50"
                                >
                                    ✓ Vincular seleccionados ({selectedRows.length})
                                </button>
                            )}

                            <button
                                type="button"
                                disabled={!counts.MATCHED || isSubmitting}
                                onClick={applyAllMatched}
                                className="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
                            >
                                Importar todas las coincidencias ({counts.MATCHED || 0})
                            </button>
                        </div>
                    )}
                </div>

                {/* Notifications */}
                {flash.success && (
                    <div className="flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/40 dark:text-emerald-300">
                        <span className="text-xl">✓</span>
                        <span>{flash.success}</span>
                    </div>
                )}
                {flash.error && (
                    <div className="flex items-center gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm font-medium text-rose-800 dark:border-rose-800/60 dark:bg-rose-950/40 dark:text-rose-300">
                        <span className="text-xl">⚠️</span>
                        <span>{flash.error}</span>
                    </div>
                )}
                {flash.importErrors?.length > 0 && (
                    <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-800/60 dark:bg-rose-950/40 dark:text-rose-300">
                        <div className="font-semibold">Errores durante la importación:</div>
                        <ul className="mt-2 list-inside list-disc space-y-1">
                            {flash.importErrors.map((error, idx) => (
                                <li key={idx}>{error}</li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* Status KPI Cards */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
                    {Object.keys(statusConfig).map((statusKey) => {
                        const cfg = statusConfig[statusKey]
                        const isSelected = result === statusKey
                        return (
                            <button
                                key={statusKey}
                                type="button"
                                onClick={() => handleStatusCardClick(statusKey)}
                                className={`flex flex-col justify-between rounded-2xl border p-3.5 text-left transition-all ${
                                    isSelected
                                        ? 'ring-2 ring-indigo-500 shadow-md border-indigo-300 bg-indigo-50/40 dark:bg-neutral-800'
                                        : 'border-slate-200 bg-white hover:border-slate-300 hover:shadow-sm dark:border-neutral-800 dark:bg-neutral-900'
                                }`}
                            >
                                <span className="text-xs font-semibold text-slate-500 dark:text-slate-400">
                                    {cfg.label}
                                </span>
                                <div className="mt-2 flex items-baseline justify-between">
                                    <span className="text-2xl font-black text-slate-900 dark:text-white">
                                        {counts[statusKey] || 0}
                                    </span>
                                    <span className={`inline-block rounded-md border px-1.5 py-0.5 text-[10px] font-bold ${cfg.color}`}>
                                        {statusKey}
                                    </span>
                                </div>
                            </button>
                        )
                    })}
                </div>

                {/* Filters */}
                <form
                    onSubmit={handleSearchSubmit}
                    className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row dark:border-neutral-800 dark:bg-neutral-900"
                >
                    <div className="relative flex-1">
                        <input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Buscar por término (ej. JOICO, MLM, SKU, código de barras)..."
                            className="w-full rounded-xl border border-slate-300 bg-slate-50 px-4 py-2.5 text-sm transition focus:border-indigo-500 focus:bg-white focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                        />
                        {search && (
                            <button
                                type="button"
                                onClick={() => { setSearch(''); executeFilter(result) }}
                                className="absolute right-3 top-2.5 text-slate-400 hover:text-slate-600"
                            >
                                ✕
                            </button>
                        )}
                    </div>

                    <select
                        value={accountKey}
                        onChange={(e) => { setAccountKey(e.target.value); }}
                        className="rounded-xl border border-slate-300 bg-slate-50 px-4 py-2.5 text-sm font-medium text-slate-700 dark:border-neutral-700 dark:bg-neutral-950 dark:text-slate-200"
                    >
                        <option value="">Todas las cuentas MeLi</option>
                        {accounts.map((acc) => (
                            <option key={acc.id} value={acc.id}>
                                {acc.nickname || acc.meli_user_id || `Cuenta ${acc.id}`}
                            </option>
                        ))}
                    </select>

                    <select
                        value={result}
                        onChange={(e) => { setResult(e.target.value); }}
                        className="rounded-xl border border-slate-300 bg-slate-50 px-4 py-2.5 text-sm font-medium text-slate-700 dark:border-neutral-700 dark:bg-neutral-950 dark:text-slate-200"
                    >
                        <option value="">Todos los estados</option>
                        {Object.keys(statusConfig).map((st) => (
                            <option key={st} value={st}>
                                {st} ({statusConfig[st].label})
                            </option>
                        ))}
                    </select>

                    <button
                        type="submit"
                        className="rounded-xl bg-slate-900 px-6 py-2.5 text-sm font-bold text-white transition hover:bg-slate-800 dark:bg-indigo-600 dark:hover:bg-indigo-500"
                    >
                        Filtrar
                    </button>
                </form>

                {preview.rows_truncated && (
                    <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-medium text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">
                        ⚡ Mostrando los primeros {preview.row_limit || 200} resultados de {preview.total_rows || 0}. Usa la barra de búsqueda para acotar por marca o SKU específico.
                    </div>
                )}

                {/* Main Table */}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-neutral-800">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500 dark:bg-neutral-950 dark:text-slate-400">
                                <tr>
                                    <th className="w-12 px-4 py-3.5 text-center">
                                        <input
                                            type="checkbox"
                                            onChange={toggleSelectAll}
                                            checked={rows.length > 0 && selectedRows.length === rows.filter(r => r.status === 'MATCHED').length && selectedRows.length > 0}
                                            className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                            title="Seleccionar todas las coincidencias válidas"
                                        />
                                    </th>
                                    <th className="px-4 py-3.5">Publicación Mercado Libre</th>
                                    <th className="px-4 py-3.5">Identificador MeLi</th>
                                    <th className="px-4 py-3.5">Producto Almacén</th>
                                    <th className="px-4 py-3.5">Estado</th>
                                    <th className="px-4 py-3.5 text-right">Acción</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                {rows.length === 0 ? (
                                    <tr>
                                        <td colSpan="6" className="px-6 py-16 text-center text-slate-500 dark:text-slate-400">
                                            <p className="text-base font-semibold">No se encontraron publicaciones con los filtros actuales.</p>
                                            <p className="mt-1 text-sm">Prueba buscando por marca (ej. "JOICO") o limpia los filtros.</p>
                                        </td>
                                    </tr>
                                ) : (
                                    rows.map((row, idx) => {
                                        const isSelected = selectedRows.some(r => r.mlm === row.mlm && r.variation_id === row.variation_id)
                                        const cfg = statusConfig[row.status] || statusConfig.UNSUPPORTED

                                        return (
                                            <tr
                                                key={`${row.mlm}-${row.variation_id || 'main'}-${idx}`}
                                                className={`transition-colors hover:bg-slate-50/70 dark:hover:bg-neutral-800/40 ${
                                                    isSelected ? 'bg-indigo-50/30 dark:bg-indigo-950/20' : ''
                                                }`}
                                            >
                                                {/* Checkbox */}
                                                <td className="px-4 py-3 text-center">
                                                    {row.status === 'MATCHED' ? (
                                                        <input
                                                            type="checkbox"
                                                            checked={isSelected}
                                                            onChange={() => toggleRowSelect(row)}
                                                            className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                                        />
                                                    ) : (
                                                        <span className="text-slate-300">—</span>
                                                    )}
                                                </td>

                                                {/* Publicación MLM */}
                                                <td className="px-4 py-3">
                                                    <div className="flex items-center gap-3">
                                                        {row.thumbnail ? (
                                                            <img
                                                                src={row.thumbnail}
                                                                alt=""
                                                                className="h-10 w-10 shrink-0 rounded-lg object-contain bg-slate-50 p-0.5 border border-slate-200"
                                                                loading="lazy"
                                                            />
                                                        ) : (
                                                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-xs text-slate-400 font-bold">
                                                                ML
                                                            </div>
                                                        )}
                                                        <div className="min-w-0">
                                                            <div className="flex items-center gap-2">
                                                                <a
                                                                    href={row.external_url || `https://articulo.mercadolibre.com.mx/${row.mlm}`}
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                    className="font-mono text-xs font-bold text-indigo-600 hover:underline dark:text-indigo-400"
                                                                >
                                                                    {row.mlm} ↗
                                                                </a>
                                                                <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600 dark:bg-neutral-800 dark:text-slate-300">
                                                                    {row.account_name || `Cuenta ${row.account_key}`}
                                                                </span>
                                                            </div>
                                                            <div className="truncate text-xs text-slate-700 dark:text-slate-300 max-w-sm mt-0.5 font-medium" title={row.title || row.sku}>
                                                                {row.title || 'Sin título remoto'}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>

                                                {/* Identificador MeLi */}
                                                <td className="px-4 py-3 font-mono text-xs">
                                                    <div className="font-semibold text-slate-800 dark:text-white">
                                                        {row.sku || <span className="text-rose-500 font-normal italic">Sin SKU</span>}
                                                    </div>
                                                    {row.variation_id && (
                                                        <div className="text-[11px] text-slate-500">
                                                            Var: {row.variation_id}
                                                        </div>
                                                    )}
                                                    {row.remote_price && (
                                                        <div className="text-[11px] text-emerald-600 font-sans font-medium">
                                                            ${row.remote_price} {row.remote_currency}
                                                        </div>
                                                    )}
                                                </td>

                                                {/* Producto Almacén */}
                                                <td className="px-4 py-3">
                                                    {row.inventory_product_id ? (
                                                        <div>
                                                            <div className="text-xs font-bold text-slate-900 dark:text-white">
                                                                {row.inventory_product_name}
                                                            </div>
                                                            <div className="font-mono text-[11px] text-slate-500">
                                                                SKU: {row.inventory_product_sku}
                                                            </div>
                                                        </div>
                                                    ) : (
                                                        <span className="text-xs italic text-slate-400">
                                                            No asignado
                                                        </span>
                                                    )}
                                                </td>

                                                {/* Estado */}
                                                <td className="px-4 py-3">
                                                    <span className={`inline-flex items-center rounded-lg border px-2 py-0.5 text-xs font-bold ${cfg.color}`}>
                                                        {row.status}
                                                    </span>
                                                    <div className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                                                        {row.reason}
                                                    </div>
                                                </td>

                                                {/* Acción */}
                                                <td className="px-4 py-3 text-right">
                                                    {row.status === 'MATCHED' && canApply && (
                                                        <button
                                                            type="button"
                                                            disabled={isSubmitting}
                                                            onClick={() => handleSingleApply(row)}
                                                            className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50"
                                                        >
                                                            Vincular
                                                        </button>
                                                    )}

                                                    {row.status === 'ALREADY_LINKED' && (
                                                        <span className="inline-flex items-center text-xs font-semibold text-blue-600 dark:text-blue-400">
                                                            ✓ Vinculado
                                                        </span>
                                                    )}

                                                    {(row.status === 'PRODUCT_NOT_FOUND' || row.status === 'MISSING_SKU' || row.status === 'AMBIGUOUS') && canApply && (
                                                        <button
                                                            type="button"
                                                            onClick={() => openMappingModal(row)}
                                                            className="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-bold text-indigo-700 hover:bg-indigo-100 dark:border-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-300"
                                                        >
                                                            Asignar...
                                                        </button>
                                                    )}
                                                </td>
                                            </tr>
                                        )
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {/* Modal de Asignación Manual Asistida */}
            {mappingModalRow && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                    <div className="w-full max-w-xl rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="flex items-start justify-between">
                            <div>
                                <h3 className="text-xl font-bold text-slate-900 dark:text-white">
                                    Asignar producto de almacén
                                </h3>
                                <p className="mt-1 text-xs text-slate-500">
                                    Vincula manualmente esta publicación de Mercado Libre al producto correcto.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setMappingModalRow(null)}
                                className="rounded-lg p-1 text-slate-400 hover:text-slate-600"
                            >
                                ✕
                            </button>
                        </div>

                        {/* Publicación info */}
                        <div className="mt-4 flex items-center gap-3 rounded-2xl border border-slate-100 bg-slate-50 p-3.5 dark:border-neutral-800 dark:bg-neutral-950">
                            {mappingModalRow.thumbnail ? (
                                <img src={mappingModalRow.thumbnail} alt="" className="h-12 w-12 rounded-lg object-contain bg-white p-1 border" />
                            ) : (
                                <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-slate-200 text-xs font-bold">ML</div>
                            )}
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2">
                                    <span className="font-mono text-xs font-bold text-indigo-600">{mappingModalRow.mlm}</span>
                                    <span className="text-[11px] text-slate-400">• SKU MeLi: {mappingModalRow.sku || 'Sin SKU'}</span>
                                </div>
                                <div className="truncate text-xs font-medium text-slate-800 dark:text-white mt-0.5">
                                    {mappingModalRow.title || 'Publicación sin título'}
                                </div>
                            </div>
                        </div>

                        {/* Search Input */}
                        <div className="mt-5">
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1.5">
                                Buscar producto en catálogo interno
                            </label>
                            <input
                                autoFocus
                                value={productQuery}
                                onChange={(e) => setProductQuery(e.target.value)}
                                placeholder="Escribe el SKU, código de barras o nombre..."
                                className="w-full rounded-xl border border-slate-300 px-4 py-2.5 text-sm transition focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                            />
                        </div>

                        {/* Search Results */}
                        <div className="mt-4 max-h-60 overflow-y-auto divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-neutral-800 dark:border-neutral-800">
                            {isSearching ? (
                                <div className="p-4 text-center text-xs text-slate-500">Buscando productos...</div>
                            ) : searchResults.length === 0 ? (
                                <div className="p-4 text-center text-xs text-slate-500">
                                    {productQuery.trim() ? 'No se encontraron productos coincidentes.' : 'Escribe arriba para buscar productos.'}
                                </div>
                            ) : (
                                searchResults.map((p) => (
                                    <div key={p.id} className="flex items-center justify-between p-3 hover:bg-slate-50 dark:hover:bg-neutral-800/60">
                                        <div className="min-w-0 pr-3">
                                            <div className="text-xs font-bold text-slate-900 dark:text-white truncate">
                                                {p.name}
                                            </div>
                                            <div className="flex items-center gap-2 font-mono text-[11px] text-slate-500 mt-0.5">
                                                <span>SKU: {p.sku}</span>
                                                {p.barcode && <span>• Barcode: {p.barcode}</span>}
                                                {p.price_public && <span className="text-emerald-600 font-sans">• ${p.price_public}</span>}
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            disabled={isSubmitting}
                                            onClick={() => confirmManualMapping(p)}
                                            className="shrink-0 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-indigo-700 disabled:opacity-50"
                                        >
                                            Seleccionar y Vincular
                                        </button>
                                    </div>
                                ))
                            )}
                        </div>

                        <div className="mt-5 flex justify-end">
                            <button
                                type="button"
                                onClick={() => setMappingModalRow(null)}
                                className="rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                            >
                                Cancelar
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AppShell>
    )
}
