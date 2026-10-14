import AppShell from '@/Components/layout/AppShell'
import CameraBarcodeScanner from '@/Components/CameraBarcodeScanner'
import { Head, Link, router } from '@inertiajs/react'
import axios from 'axios'
import { useEffect, useMemo, useRef, useState } from 'react'

export default function ShelfAuditCreate({ shelves = [], preselectedShelfCode, initialProducts = [] }) {
    const [selectedShelfCode, setSelectedShelfCode] = useState(
        preselectedShelfCode || (shelves[0]?.shelf_code ?? '')
    )
    const [isLoadingProducts, setIsLoadingProducts] = useState(false)
    const [auditNotes, setAuditNotes] = useState('')
    const [isSubmitting, setIsSubmitting] = useState(false)

    // Items list for the audit
    const [items, setItems] = useState(() => {
        return (initialProducts || []).map((p) => ({
            product_id: p.product_id || p.id,
            sku: p.sku,
            barcode: p.barcode,
            barcode_secondary: p.barcode_secondary,
            name: p.name,
            brand: p.brand,
            primary_location: p.primary_location || p.primary_location_code,
            system_quantity: Number(p.system_quantity || p.system_stock || 0),
            location_breakdown: p.location_breakdown || '',
            counted_quantity: 0,
            notes: '',
            is_extra: false,
        }))
    })

    // Feedback message
    const [scanFeedback, setScanFeedback] = useState(null)
    const [highlightedProductId, setHighlightedProductId] = useState(null)

    // Barcode scanner gun input
    const [gunBarcode, setGunBarcode] = useState('')
    const gunInputRef = useRef(null)

    // Camera scanner modal
    const [isCameraOpen, setIsCameraOpen] = useState(false)

    // Manual search catalog
    const [catalogSearch, setCatalogSearch] = useState('')
    const [catalogResults, setCatalogResults] = useState([])
    const [isSearchingCatalog, setIsSearchingCatalog] = useState(false)
    const [showCatalogSearch, setShowCatalogSearch] = useState(false)

    // Table filter: 'all' | 'diff' | 'over' | 'short' | 'exact'
    const [tableFilter, setTableFilter] = useState('all')
    const [itemSearch, setItemSearch] = useState('')

    // Find current selected shelf details
    const currentShelf = useMemo(() => {
        return shelves.find((sh) => sh.shelf_code === selectedShelfCode) || shelves[0] || null
    }, [shelves, selectedShelfCode])

    // Auto-focus barcode input
    useEffect(() => {
        gunInputRef.current?.focus()
    }, [selectedShelfCode])

    // Load products when shelf selection changes
    const handleShelfChange = async (newShelfCode) => {
        setSelectedShelfCode(newShelfCode)
        if (!newShelfCode) {
            setItems([])
            return
        }

        setIsLoadingProducts(true)
        try {
            const resp = await axios.get(`/almacen/auditorias/anaquel/${encodeURIComponent(newShelfCode)}/productos`)
            if (resp.data?.ok && resp.data?.products) {
                const mapped = resp.data.products.map((p) => ({
                    product_id: p.product_id || p.id,
                    sku: p.sku,
                    barcode: p.barcode,
                    barcode_secondary: p.barcode_secondary,
                    name: p.name,
                    brand: p.brand,
                    primary_location: p.primary_location || p.primary_location_code,
                    system_quantity: Number(p.system_quantity || p.system_stock || 0),
                    location_breakdown: p.location_breakdown || '',
                    counted_quantity: 0,
                    notes: '',
                    is_extra: false,
                }))
                setItems(mapped)
                setScanFeedback({ type: 'info', text: `Cargados ${mapped.length} productos del anaquel ${newShelfCode}.` })
            }
        } catch (err) {
            console.error('Error al cargar productos del anaquel:', err)
            setScanFeedback({ type: 'error', text: 'Error al cargar productos del anaquel.' })
        } finally {
            setIsLoadingProducts(false)
        }
    }

    // Process a scanned barcode (from gun or camera)
    const processBarcode = async (rawCode) => {
        const code = String(rawCode || '').trim()
        if (!code) return

        const codeLower = code.toLowerCase()

        // 1. Check if product already exists in items list
        const existingIndex = items.findIndex((it) => {
            return (
                (it.barcode && it.barcode.toLowerCase() === codeLower) ||
                (it.barcode_secondary && it.barcode_secondary.toLowerCase() === codeLower) ||
                (it.sku && it.sku.toLowerCase() === codeLower)
            )
        })

        if (existingIndex !== -1) {
            // Found! Increment counted_quantity + 1
            const target = items[existingIndex]
            const updated = [...items]
            updated[existingIndex] = {
                ...target,
                counted_quantity: target.counted_quantity + 1,
            }
            setItems(updated)
            setHighlightedProductId(target.product_id)
            setScanFeedback({
                type: 'success',
                text: `+1 a "${target.name}" (Conteo en ${selectedShelfCode}: ${target.counted_quantity + 1})`,
            })
            setTimeout(() => setHighlightedProductId(null), 2500)
            return
        }

        // 2. Not found in current shelf items -> search catalog in DB
        try {
            setScanFeedback({ type: 'info', text: `Buscando código "${code}" en el catálogo...` })
            const resp = await axios.get('/almacen/auditorias/buscar-catalogo', {
                params: { q: code },
            })
            const catalogList = resp.data || []
            const matched = catalogList.find((p) => {
                return (
                    (p.barcode && p.barcode.toLowerCase() === codeLower) ||
                    (p.barcode_secondary && p.barcode_secondary.toLowerCase() === codeLower) ||
                    (p.sku && p.sku.toLowerCase() === codeLower)
                )
            }) || catalogList[0]

            if (matched) {
                const newItem = {
                    product_id: matched.id,
                    sku: matched.sku,
                    barcode: matched.barcode,
                    barcode_secondary: matched.barcode_secondary,
                    name: matched.name,
                    brand: matched.brand,
                    primary_location: matched.primary_location?.code || null,
                    system_quantity: 0,
                    location_breakdown: 'Sin stock previo en este anaquel',
                    counted_quantity: 1,
                    notes: `Encontrado físicamente en anaquel ${selectedShelfCode} (Ubicación oficial: ${matched.primary_location?.code || 'Sin asignar'})`,
                    is_extra: true,
                }
                setItems((prev) => [newItem, ...prev])
                setHighlightedProductId(matched.id)
                setScanFeedback({
                    type: 'warning',
                    text: `¡Producto no esperado en este anaquel! Añadido "${matched.name}" (+1 pieza).`,
                })
                setTimeout(() => setHighlightedProductId(null), 3000)
            } else {
                setScanFeedback({
                    type: 'error',
                    text: `Código "${code}" no encontrado en el catálogo de productos.`,
                })
            }
        } catch (err) {
            console.error('Error buscando código en catálogo:', err)
            setScanFeedback({ type: 'error', text: 'Error de red al consultar el catálogo.' })
        }
    }

    const handleGunSubmit = (e) => {
        e.preventDefault()
        processBarcode(gunBarcode)
        setGunBarcode('')
    }

    // Manual catalog search
    const handleCatalogSearchQuery = async (query) => {
        setCatalogSearch(query)
        if (!query.trim() || query.trim().length < 2) {
            setCatalogResults([])
            return
        }

        setIsSearchingCatalog(true)
        try {
            const resp = await axios.get('/almacen/auditorias/buscar-catalogo', {
                params: { q: query.trim() },
            })
            setCatalogResults(resp.data || [])
        } catch (err) {
            console.error('Error searching catalog:', err)
        } finally {
            setIsSearchingCatalog(false)
        }
    }

    const addProductFromCatalog = (product) => {
        const existing = items.find((it) => it.product_id === product.id)
        if (existing) {
            setScanFeedback({ type: 'info', text: `"${product.name}" ya está en la lista de este anaquel.` })
            setHighlightedProductId(product.id)
            setTimeout(() => setHighlightedProductId(null), 2000)
            setShowCatalogSearch(false)
            return
        }

        const newItem = {
            product_id: product.id,
            sku: product.sku,
            barcode: product.barcode,
            barcode_secondary: product.barcode_secondary,
            name: product.name,
            brand: product.brand,
            primary_location: product.primary_location?.code || null,
            system_quantity: 0,
            location_breakdown: 'Sin stock previo en este anaquel',
            counted_quantity: 1,
            notes: `Agregado manualmente al anaquel ${selectedShelfCode}`,
            is_extra: true,
        }
        setItems((prev) => [newItem, ...prev])
        setHighlightedProductId(product.id)
        setCatalogSearch('')
        setCatalogResults([])
        setShowCatalogSearch(false)
        setScanFeedback({ type: 'success', text: `Añadido "${product.name}" con 1 unidad contada.` })
        setTimeout(() => setHighlightedProductId(null), 2500)
    }

    // Row quantity changes
    const updateCounted = (productId, newQuantity) => {
        const val = Math.max(0, parseInt(newQuantity, 10) || 0)
        setItems((prev) =>
            prev.map((it) => (it.product_id === productId ? { ...it, counted_quantity: val } : it))
        )
    }

    const matchSystem = (productId) => {
        setItems((prev) =>
            prev.map((it) => (it.product_id === productId ? { ...it, counted_quantity: it.system_quantity } : it))
        )
    }

    const matchAllSystem = () => {
        if (confirm(`¿Deseas marcar todos los productos del anaquel ${selectedShelfCode} como coincidentes con el sistema?`)) {
            setItems((prev) => prev.map((it) => ({ ...it, counted_quantity: it.system_quantity })))
        }
    }

    const resetAllToZero = () => {
        if (confirm('¿Poner todas las cantidades contadas en 0 para iniciar conteo físico desde cero?')) {
            setItems((prev) => prev.map((it) => ({ ...it, counted_quantity: 0 })))
        }
    }

    const updateItemNotes = (productId, notes) => {
        setItems((prev) =>
            prev.map((it) => (it.product_id === productId ? { ...it, notes } : it))
        )
    }

    const removeExtraItem = (productId) => {
        setItems((prev) => prev.filter((it) => it.product_id !== productId))
    }

    // Calculations & Metrics
    const metrics = useMemo(() => {
        let exactCount = 0
        let overCount = 0
        let shortCount = 0
        let totalSystemUnits = 0
        let totalCountedUnits = 0

        items.forEach((it) => {
            const diff = it.counted_quantity - it.system_quantity
            totalSystemUnits += it.system_quantity
            totalCountedUnits += it.counted_quantity

            if (diff === 0) {
                exactCount++
            } else if (diff > 0) {
                overCount++
            } else {
                shortCount++
            }
        })

        return {
            totalProducts: items.length,
            exactCount,
            overCount,
            shortCount,
            totalSystemUnits,
            totalCountedUnits,
            netDifferenceUnits: totalCountedUnits - totalSystemUnits,
        }
    }, [items])

    // Filtered items
    const filteredItems = useMemo(() => {
        return items.filter((it) => {
            if (itemSearch.trim()) {
                const q = itemSearch.toLowerCase()
                const matchName = it.name?.toLowerCase().includes(q)
                const matchSku = it.sku?.toLowerCase().includes(q)
                const matchBar = it.barcode?.toLowerCase().includes(q)
                if (!matchName && !matchSku && !matchBar) return false
            }

            const diff = it.counted_quantity - it.system_quantity
            if (tableFilter === 'diff') return diff !== 0
            if (tableFilter === 'over') return diff > 0
            if (tableFilter === 'short') return diff < 0
            if (tableFilter === 'exact') return diff === 0
            return true
        })
    }, [items, itemSearch, tableFilter])

    // Submit handler
    const handleSubmit = (applyImmediately) => {
        if (!selectedShelfCode) {
            alert('Por favor selecciona un anaquel a auditar.')
            return
        }

        if (items.length === 0) {
            alert('No hay productos en la lista de auditoría.')
            return
        }

        const confirmMsg = applyImmediately
            ? `¿Confirmas aplicar el ajuste de inventario al Anaquel ${selectedShelfCode} ahora mismo?\n\n• Productos a ajustar: ${metrics.overCount + metrics.shortCount}\n• Diferencia neta: ${metrics.netDifferenceUnits > 0 ? '+' : ''}${metrics.netDifferenceUnits} unidades\n\nEl stock en el sistema del anaquel ${selectedShelfCode} quedará exactamente igual a lo que contaste físicamente.`
            : `¿Guardar la auditoría del anaquel ${selectedShelfCode} como borrador sin aplicar ajustes todavía?`

        if (!confirm(confirmMsg)) return

        setIsSubmitting(true)

        const payload = {
            shelf_code: selectedShelfCode,
            notes: auditNotes,
            apply_immediately: applyImmediately,
            items: items.map((it) => ({
                inventory_product_id: it.product_id,
                system_quantity: it.system_quantity,
                counted_quantity: it.counted_quantity,
                location_breakdown: it.location_breakdown,
                notes: it.notes,
            })),
        }

        router.post('/almacen/auditorias', payload, {
            onFinish: () => setIsSubmitting(false),
            onError: (errs) => {
                console.error('Error al guardar auditoría:', errs)
                alert('Ocurrió un error al guardar la auditoría. Revisa los datos e intenta nuevamente.')
            },
        })
    }

    return (
        <AppShell title="Nuevo Conteo de Anaquel">
            <Head title="Nuevo Conteo de Anaquel" />

            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <div className="flex items-center gap-2">
                            <Link
                                href="/almacen/auditorias"
                                className="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                            >
                                ← Volver a auditorías
                            </Link>
                            <span className="text-slate-300 dark:text-neutral-700">•</span>
                            <span className="rounded-lg bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                Conteo Físico
                            </span>
                        </div>
                        <h1 className="mt-1.5 text-2xl font-bold tracking-tight text-slate-900 dark:text-white sm:text-3xl">
                            Auditar Anaquel: {selectedShelfCode}
                        </h1>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {currentShelf?.sub_locations_count > 1 ? (
                                <span>
                                    Incluye todas las casillas de este anaquel:{' '}
                                    <strong className="text-indigo-600 dark:text-indigo-400">
                                        {currentShelf.sub_locations.join(', ')}
                                    </strong>
                                </span>
                            ) : (
                                <span>{currentShelf?.name || 'Ubicación completa'}</span>
                            )}
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            onClick={() => handleSubmit(false)}
                            disabled={isSubmitting || items.length === 0}
                            className="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-2xs transition hover:bg-slate-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200 dark:hover:bg-neutral-700"
                        >
                            <svg className="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" />
                            </svg>
                            <span>Guardar Borrador</span>
                        </button>

                        <button
                            type="button"
                            onClick={() => handleSubmit(true)}
                            disabled={isSubmitting || items.length === 0}
                            className="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700 active:scale-95 disabled:opacity-50"
                        >
                            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                            <span>⚡ Aplicar Ajuste Ahora</span>
                        </button>
                    </div>
                </div>

                {/* Anaquel Selector & Scan Command Bar */}
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
                    {/* Shelf Selector Card */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs dark:border-neutral-800 dark:bg-neutral-900 lg:col-span-5">
                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-neutral-400">
                            1. Anaquel a Contar:
                        </label>
                        <select
                            value={selectedShelfCode}
                            onChange={(e) => handleShelfChange(e.target.value)}
                            disabled={isLoadingProducts}
                            className="mt-2 w-full rounded-xl border border-indigo-300 bg-white px-3.5 py-2.5 text-base font-bold text-slate-900 focus:border-indigo-600 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                        >
                            {shelves.map((sh) => (
                                <option key={sh.shelf_code} value={sh.shelf_code}>
                                    📍 Anaquel {sh.display_label}
                                </option>
                            ))}
                        </select>

                        {currentShelf?.sub_locations_count > 1 ? (
                            <div className="mt-2.5 rounded-xl bg-indigo-50/70 p-2.5 text-xs text-indigo-900 dark:bg-indigo-950/40 dark:text-indigo-300">
                                <span className="font-bold">✓ Agrupa {currentShelf.sub_locations_count} casillas:</span>{' '}
                                {currentShelf.sub_locations.join(', ')}
                            </div>
                        ) : (
                            <div className="mt-2.5 text-xs text-slate-500 dark:text-slate-400">
                                Anaquel individual: {currentShelf?.name || selectedShelfCode}
                            </div>
                        )}
                    </div>

                    {/* Barcode Gun & Camera Scanner Card */}
                    <div className="rounded-2xl border-2 border-indigo-500/40 bg-gradient-to-br from-indigo-50/60 to-white p-4 shadow-xs dark:border-indigo-600/40 dark:from-indigo-950/20 dark:to-neutral-900 lg:col-span-7">
                        <div className="flex items-center justify-between">
                            <label className="text-xs font-bold uppercase tracking-wider text-indigo-900 dark:text-indigo-300">
                                2. Escáner de Código de Barras (Pistola USB / Bluetooth o Cámara):
                            </label>
                            <span className="text-[11px] font-semibold text-indigo-700 dark:text-indigo-400">
                                Cada escaneo suma +1 al producto
                            </span>
                        </div>

                        <div className="mt-2 flex flex-col gap-2 sm:flex-row">
                            <form onSubmit={handleGunSubmit} className="relative flex-1">
                                <input
                                    ref={gunInputRef}
                                    type="text"
                                    value={gunBarcode}
                                    onChange={(e) => setGunBarcode(e.target.value)}
                                    placeholder="Dispara con la pistola o escribe SKU / código y presiona Enter..."
                                    className="w-full rounded-xl border-2 border-indigo-400 bg-white px-4 py-2.5 text-sm font-semibold text-slate-900 placeholder-slate-400 shadow-inner focus:border-indigo-600 focus:outline-none dark:border-indigo-700 dark:bg-neutral-950 dark:text-white"
                                />
                                <button
                                    type="submit"
                                    className="absolute right-1.5 top-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-indigo-700"
                                >
                                    + Registrar
                                </button>
                            </form>

                            <button
                                type="button"
                                onClick={() => setIsCameraOpen(true)}
                                className="inline-flex items-center justify-center gap-2 rounded-xl border border-indigo-300 bg-white px-4 py-2.5 text-sm font-semibold text-indigo-700 shadow-xs hover:bg-indigo-50 dark:border-indigo-800 dark:bg-neutral-800 dark:text-indigo-300 dark:hover:bg-neutral-700"
                            >
                                <svg className="h-5 w-5 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.8} d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                <span>Cámara</span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setShowCatalogSearch(!showCatalogSearch)}
                                className="inline-flex items-center justify-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700 shadow-xs hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                            >
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                                <span>Buscar Extra</span>
                            </button>
                        </div>

                        {/* Scanner Feedback Notification */}
                        {scanFeedback && (
                            <div
                                className={`mt-2.5 flex items-center justify-between rounded-xl p-2.5 text-xs font-semibold ${
                                    scanFeedback.type === 'success'
                                        ? 'bg-emerald-100/90 text-emerald-900 dark:bg-emerald-950/60 dark:text-emerald-300'
                                        : scanFeedback.type === 'warning'
                                        ? 'bg-amber-100/90 text-amber-900 dark:bg-amber-950/60 dark:text-amber-300'
                                        : scanFeedback.type === 'error'
                                        ? 'bg-rose-100/90 text-rose-900 dark:bg-rose-950/60 dark:text-rose-300'
                                        : 'bg-indigo-100/90 text-indigo-900 dark:bg-indigo-950/60 dark:text-indigo-300'
                                }`}
                            >
                                <span>{scanFeedback.text}</span>
                                <button
                                    type="button"
                                    onClick={() => setScanFeedback(null)}
                                    className="text-slate-500 hover:text-slate-800 dark:text-slate-400"
                                >
                                    ✕
                                </button>
                            </div>
                        )}
                    </div>
                </div>

                {/* Catalog Search Dropdown Modal / Box */}
                {showCatalogSearch && (
                    <div className="rounded-2xl border border-indigo-200 bg-white p-4 shadow-md dark:border-neutral-700 dark:bg-neutral-900">
                        <div className="flex items-center justify-between">
                            <h3 className="text-sm font-bold text-slate-900 dark:text-white">
                                Buscar producto del catálogo para agregar al Anaquel {selectedShelfCode}:
                            </h3>
                            <button
                                type="button"
                                onClick={() => setShowCatalogSearch(false)}
                                className="text-xs text-slate-400 hover:text-slate-700 dark:hover:text-white"
                            >
                                Cerrar ✕
                            </button>
                        </div>
                        <div className="mt-2">
                            <input
                                type="text"
                                value={catalogSearch}
                                onChange={(e) => handleCatalogSearchQuery(e.target.value)}
                                placeholder="Escribe nombre, SKU, marca o código de barras..."
                                autoFocus
                                className="w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                            />
                        </div>
                        {isSearchingCatalog && (
                            <p className="mt-2 text-xs text-slate-500">Buscando productos...</p>
                        )}
                        {catalogResults.length > 0 && (
                            <div className="mt-3 max-h-60 overflow-y-auto divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-neutral-800 dark:border-neutral-700">
                                {catalogResults.map((p) => (
                                    <div
                                        key={p.id}
                                        className="flex items-center justify-between p-3 hover:bg-slate-50 dark:hover:bg-neutral-800"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-semibold text-slate-900 dark:text-white truncate">
                                                {p.name}
                                            </p>
                                            <p className="text-xs text-slate-500 dark:text-neutral-400">
                                                SKU: <span className="font-mono font-bold">{p.sku}</span> | Marca: {p.brand || '—'} | Cód: {p.barcode || '—'} | Ubic Oficial: {p.primary_location?.code || 'Sin asignar'}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => addProductFromCatalog(p)}
                                            className="ml-3 rounded-lg bg-indigo-600 px-3 py-1 text-xs font-bold text-white hover:bg-indigo-700"
                                        >
                                            + Agregar al anaquel
                                        </button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {/* Real-time Summary KPI Cards */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-6">
                    <div className="rounded-2xl border border-slate-200 bg-white p-3.5 shadow-2xs dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-[11px] font-semibold uppercase text-slate-500 dark:text-neutral-400">Total Productos</span>
                        <p className="mt-0.5 text-xl font-black text-slate-900 dark:text-white">
                            {metrics.totalProducts}
                        </p>
                    </div>
                    <div className="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-3.5 shadow-2xs dark:border-emerald-900/40 dark:bg-emerald-950/20">
                        <span className="text-[11px] font-semibold uppercase text-emerald-700 dark:text-emerald-400">Exactos</span>
                        <p className="mt-0.5 text-xl font-black text-emerald-700 dark:text-emerald-300">
                            {metrics.exactCount}
                        </p>
                    </div>
                    <div className="rounded-2xl border border-blue-200 bg-blue-50/50 p-3.5 shadow-2xs dark:border-blue-900/40 dark:bg-blue-950/20">
                        <span className="text-[11px] font-semibold uppercase text-blue-700 dark:text-blue-400">Sobrantes (+)</span>
                        <p className="mt-0.5 text-xl font-black text-blue-700 dark:text-blue-300">
                            {metrics.overCount}
                        </p>
                    </div>
                    <div className="rounded-2xl border border-rose-200 bg-rose-50/50 p-3.5 shadow-2xs dark:border-rose-900/40 dark:bg-rose-950/20">
                        <span className="text-[11px] font-semibold uppercase text-rose-700 dark:text-rose-400">Faltantes (-)</span>
                        <p className="mt-0.5 text-xl font-black text-rose-700 dark:text-rose-300">
                            {metrics.shortCount}
                        </p>
                    </div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-3.5 shadow-2xs dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-[11px] font-semibold uppercase text-slate-500 dark:text-neutral-400">Piezas Físicas</span>
                        <p className="mt-0.5 text-xl font-black text-slate-900 dark:text-white">
                            {metrics.totalCountedUnits} <span className="text-xs font-normal text-slate-500">/ {metrics.totalSystemUnits} esp.</span>
                        </p>
                    </div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-3.5 shadow-2xs dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-[11px] font-semibold uppercase text-slate-500 dark:text-neutral-400">Dif. Neta</span>
                        <p className={`mt-0.5 text-xl font-black ${metrics.netDifferenceUnits > 0 ? 'text-blue-600' : metrics.netDifferenceUnits < 0 ? 'text-rose-600' : 'text-slate-800 dark:text-slate-200'}`}>
                            {metrics.netDifferenceUnits > 0 ? `+${metrics.netDifferenceUnits}` : metrics.netDifferenceUnits} uds
                        </p>
                    </div>
                </div>

                {/* Count Items Table & Controls */}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                    {/* Table Filters & Quick Actions */}
                    <div className="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-neutral-800 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex flex-wrap items-center gap-1.5">
                            <button
                                type="button"
                                onClick={() => setTableFilter('all')}
                                className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                    tableFilter === 'all'
                                        ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                                        : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-neutral-800 dark:text-slate-300'
                                }`}
                            >
                                Todos ({items.length})
                            </button>
                            <button
                                type="button"
                                onClick={() => setTableFilter('diff')}
                                className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                    tableFilter === 'diff'
                                        ? 'bg-amber-600 text-white'
                                        : 'bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-950/40 dark:text-amber-300'
                                }`}
                            >
                                Con Diferencia ({metrics.overCount + metrics.shortCount})
                            </button>
                            <button
                                type="button"
                                onClick={() => setTableFilter('over')}
                                className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                    tableFilter === 'over'
                                        ? 'bg-blue-600 text-white'
                                        : 'bg-blue-50 text-blue-700 hover:bg-blue-100 dark:bg-blue-950/40 dark:text-blue-300'
                                }`}
                            >
                                Sobrantes (+{metrics.overCount})
                            </button>
                            <button
                                type="button"
                                onClick={() => setTableFilter('short')}
                                className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                    tableFilter === 'short'
                                        ? 'bg-rose-600 text-white'
                                        : 'bg-rose-50 text-rose-700 hover:bg-rose-100 dark:bg-rose-950/40 dark:text-rose-300'
                                }`}
                            >
                                Faltantes (-{metrics.shortCount})
                            </button>
                            <button
                                type="button"
                                onClick={() => setTableFilter('exact')}
                                className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                    tableFilter === 'exact'
                                        ? 'bg-emerald-600 text-white'
                                        : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300'
                                }`}
                            >
                                Exactos ({metrics.exactCount})
                            </button>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            <input
                                type="text"
                                value={itemSearch}
                                onChange={(e) => setItemSearch(e.target.value)}
                                placeholder="Filtrar en esta lista..."
                                className="w-full sm:w-48 rounded-lg border border-slate-200 px-3 py-1.5 text-xs dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                            />
                            <button
                                type="button"
                                onClick={matchAllSystem}
                                title="Copiar stock del sistema a conteo en todos"
                                className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                            >
                                ✓ Todos = Sistema
                            </button>
                            <button
                                type="button"
                                onClick={resetAllToZero}
                                title="Poner conteos en cero"
                                className="rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                            >
                                0 Todo
                            </button>
                        </div>
                    </div>

                    {/* Table View */}
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-neutral-800">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-neutral-950 dark:text-slate-400">
                                <tr>
                                    <th className="px-5 py-3.5">Producto / SKU / Código</th>
                                    <th className="px-5 py-3.5 text-center">Stock en Anaquel {selectedShelfCode}</th>
                                    <th className="px-5 py-3.5 text-center">Físico Contado</th>
                                    <th className="px-5 py-3.5 text-center">Diferencia</th>
                                    <th className="px-5 py-3.5">Observaciones</th>
                                    <th className="px-3 py-3.5 text-right"></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                {isLoadingProducts ? (
                                    <tr>
                                        <td colSpan="6" className="px-5 py-12 text-center text-slate-500">
                                            Cargando productos del anaquel {selectedShelfCode}...
                                        </td>
                                    </tr>
                                ) : filteredItems.length === 0 ? (
                                    <tr>
                                        <td colSpan="6" className="px-5 py-12 text-center text-slate-500 dark:text-neutral-400">
                                            No hay productos que coincidan con el filtro en el anaquel {selectedShelfCode}.
                                        </td>
                                    </tr>
                                ) : (
                                    filteredItems.map((item) => {
                                        const diff = item.counted_quantity - item.system_quantity
                                        const isHighlighted = highlightedProductId === item.product_id

                                        return (
                                            <tr
                                                key={item.product_id}
                                                className={`transition-colors ${
                                                    isHighlighted
                                                        ? 'bg-indigo-100/70 dark:bg-indigo-950/60'
                                                        : item.is_extra
                                                        ? 'bg-amber-50/40 dark:bg-amber-950/20'
                                                        : 'hover:bg-slate-50/50 dark:hover:bg-neutral-800/40'
                                                }`}
                                            >
                                                {/* Product Info */}
                                                <td className="px-5 py-3.5">
                                                    <div className="font-semibold text-slate-900 dark:text-white">
                                                        {item.name}
                                                    </div>
                                                    <div className="mt-1 flex flex-wrap items-center gap-2 font-mono text-xs text-slate-500 dark:text-neutral-400">
                                                        <span className="font-bold text-slate-700 dark:text-slate-300">
                                                            SKU: {item.sku}
                                                        </span>
                                                        {item.barcode && (
                                                            <span>• Cód: {item.barcode}</span>
                                                        )}
                                                        {item.barcode_secondary && (
                                                            <span>• Cód2: {item.barcode_secondary}</span>
                                                        )}
                                                        {item.brand && (
                                                            <span className="font-sans font-medium text-indigo-600 dark:text-indigo-400">
                                                                [{item.brand}]
                                                            </span>
                                                        )}
                                                        {item.primary_location && (
                                                            <span className="rounded bg-slate-100 px-1.5 py-0.2 font-sans text-[10px] font-bold text-slate-700 dark:bg-neutral-800 dark:text-slate-300">
                                                                Ubicación oficial: {item.primary_location}
                                                            </span>
                                                        )}
                                                        {item.is_extra && (
                                                            <span className="rounded bg-purple-100 px-1.5 py-0.2 font-sans text-[10px] font-bold text-purple-800 dark:bg-purple-900/40 dark:text-purple-300">
                                                                Añadido en conteo
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>

                                                {/* System Expected in this Shelf */}
                                                <td className="px-5 py-3.5 text-center">
                                                    <span className="font-mono text-base font-bold text-slate-800 dark:text-white">
                                                        {item.system_quantity}
                                                    </span>
                                                    <span className="block text-[10px] font-medium text-slate-500 dark:text-slate-400">
                                                        {item.location_breakdown || 'uds teóricas'}
                                                    </span>
                                                </td>

                                                {/* Counted Quantity Counter */}
                                                <td className="px-5 py-3.5 text-center">
                                                    <div className="inline-flex items-center gap-1">
                                                        <button
                                                            type="button"
                                                            onClick={() => updateCounted(item.product_id, item.counted_quantity - 1)}
                                                            className="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-300 bg-white text-base font-bold text-slate-700 hover:bg-slate-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                                                        >
                                                            -
                                                        </button>

                                                        <input
                                                            type="number"
                                                            min="0"
                                                            value={item.counted_quantity}
                                                            onChange={(e) => updateCounted(item.product_id, e.target.value)}
                                                            className="h-8 w-16 rounded-lg border border-slate-300 text-center font-mono text-base font-black text-slate-900 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                                        />

                                                        <button
                                                            type="button"
                                                            onClick={() => updateCounted(item.product_id, item.counted_quantity + 1)}
                                                            className="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-300 bg-white text-base font-bold text-slate-700 hover:bg-slate-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                                                        >
                                                            +
                                                        </button>

                                                        <button
                                                            type="button"
                                                            onClick={() => matchSystem(item.product_id)}
                                                            title="Marcar igual que sistema"
                                                            className="ml-1 rounded-md px-1.5 py-1 text-[11px] font-semibold text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-neutral-800"
                                                        >
                                                            = Sist
                                                        </button>
                                                    </div>
                                                </td>

                                                {/* Difference Badge */}
                                                <td className="px-5 py-3.5 text-center">
                                                    {diff === 0 && (
                                                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-300">
                                                            ✓ Exacto (0)
                                                        </span>
                                                    )}
                                                    {diff > 0 && (
                                                        <span className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700 ring-1 ring-blue-600/20 dark:bg-blue-950/40 dark:text-blue-300">
                                                            +{diff} Sobrante
                                                        </span>
                                                    )}
                                                    {diff < 0 && (
                                                        <span className="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2.5 py-1 text-xs font-bold text-rose-700 ring-1 ring-rose-600/20 dark:bg-rose-950/40 dark:text-rose-300">
                                                            {diff} Faltante
                                                        </span>
                                                    )}
                                                </td>

                                                {/* Item Notes */}
                                                <td className="px-5 py-3.5">
                                                    <input
                                                        type="text"
                                                        value={item.notes}
                                                        onChange={(e) => updateItemNotes(item.product_id, e.target.value)}
                                                        placeholder="Motivo o detalle (ej. caja dañada)..."
                                                        className="w-full rounded-lg border border-slate-200 px-2.5 py-1 text-xs text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                                    />
                                                </td>

                                                {/* Remove Extra Item Button */}
                                                <td className="px-3 py-3.5 text-right">
                                                    {item.is_extra && (
                                                        <button
                                                            type="button"
                                                            onClick={() => removeExtraItem(item.product_id)}
                                                            title="Quitar este producto añadido manualmente"
                                                            className="text-xs text-rose-500 hover:text-rose-700"
                                                        >
                                                            ✕
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

                {/* Audit General Notes & Final Submit Bar */}
                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                    <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-neutral-300">
                        Observaciones generales de la auditoría en Anaquel {selectedShelfCode} (opcional):
                    </label>
                    <textarea
                        value={auditNotes}
                        onChange={(e) => setAuditNotes(e.target.value)}
                        rows={2}
                        placeholder={`Ej: Conteo físico quincenal de anaquel ${selectedShelfCode}.`}
                        className="mt-2 w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                    />

                    <div className="mt-5 flex flex-col justify-between gap-4 border-t border-slate-100 pt-4 dark:border-neutral-800 sm:flex-row sm:items-center">
                        <div className="text-xs text-slate-500 dark:text-slate-400">
                            <span className="font-bold text-slate-900 dark:text-white">Resumen Anaquel {selectedShelfCode}:</span>{' '}
                            {metrics.totalProducts} productos • {metrics.exactCount} exactos • {metrics.overCount} sobrantes • {metrics.shortCount} faltantes.
                        </div>

                        <div className="flex flex-wrap items-center gap-3">
                            <Link
                                href="/almacen/auditorias"
                                className="rounded-xl border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                            >
                                Cancelar
                            </Link>
                            <button
                                type="button"
                                onClick={() => handleSubmit(false)}
                                disabled={isSubmitting || items.length === 0}
                                className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 shadow-2xs hover:bg-slate-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                            >
                                💾 Guardar Borrador
                            </button>
                            <button
                                type="button"
                                onClick={() => handleSubmit(true)}
                                disabled={isSubmitting || items.length === 0}
                                className="rounded-xl bg-emerald-600 px-5 py-2 text-xs font-bold text-white shadow-sm hover:bg-emerald-700 active:scale-95 disabled:opacity-50"
                            >
                                ⚡ Aplicar Ajuste de Inventario Ahora
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {/* Camera Barcode Scanner Modal */}
            <CameraBarcodeScanner
                isOpen={isCameraOpen}
                onClose={() => setIsCameraOpen(false)}
                onScan={(code) => {
                    processBarcode(code)
                    setIsCameraOpen(false)
                }}
                title={`Escanear Código de Barras (Anaquel ${selectedShelfCode})`}
            />
        </AppShell>
    )
}
