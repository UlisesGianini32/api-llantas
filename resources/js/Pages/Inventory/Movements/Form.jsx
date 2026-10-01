import AppShell from '@/Components/layout/AppShell'
import { movementTypeLabel } from '@/lib/inventoryPresentation'
import { Head, Link, router } from '@inertiajs/react'
import { useState, useRef, useEffect, useMemo } from 'react'

export default function InventoryMovementForm({ products = [], locations = [], types = [] }) {
    // 1. Tipo primero, Ubicación y datos generales
    const defaultType = types.includes('RECEIPT') ? 'RECEIPT' : (types[0] || 'RECEIPT')
    const [type, setType] = useState(defaultType)
    const [locationId, setLocationId] = useState(locations[0]?.id ? String(locations[0].id) : '')
    const [reference, setReference] = useState('')
    const [generalNotes, setGeneralNotes] = useState('')

    // 2. Lista de productos capturados en lote (varios a la vez)
    // Array of { product_id, name, sku, barcode, brand, quantity, notes }
    const [items, setItems] = useState([])

    // 3. Estado de búsqueda y escáner
    const [barcodeInput, setBarcodeInput] = useState('')
    const [searchQuery, setSearchQuery] = useState('')
    const [isDropdownOpen, setIsDropdownOpen] = useState(false)
    const [scannerFeedback, setScannerFeedback] = useState(null) // { type: 'success' | 'error', message: string }

    const [submitting, setSubmitting] = useState(false)
    const [errors, setErrors] = useState({})

    const barcodeInputRef = useRef(null)
    const searchDropdownRef = useRef(null)

    // Enfocar automáticamente el lector de código de barras al cargar
    useEffect(() => {
        barcodeInputRef.current?.focus()
    }, [])

    // Cerrar dropdown al hacer click fuera
    useEffect(() => {
        const handleClickOutside = (e) => {
            if (searchDropdownRef.current && !searchDropdownRef.current.contains(e.target)) {
                setIsDropdownOpen(false)
            }
        }
        document.addEventListener('mousedown', handleClickOutside)
        return () => document.removeEventListener('mousedown', handleClickOutside)
    }, [])

    // Sonido sutil de confirmación para pistola lectora
    const playBeep = (freq = 880, duration = 0.08) => {
        try {
            const AudioCtx = window.AudioContext || window.webkitAudioContext
            if (!AudioCtx) return
            const ctx = new AudioCtx()
            const osc = ctx.createOscillator()
            const gain = ctx.createGain()
            osc.type = 'sine'
            osc.frequency.setValueAtTime(freq, ctx.currentTime)
            gain.gain.setValueAtTime(0.08, ctx.currentTime)
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + duration)
            osc.connect(gain)
            gain.connect(ctx.destination)
            osc.start()
            osc.stop(ctx.currentTime + duration)
        } catch {
            // Audio no habilitado por el navegador
        }
    }

    // Clasificación de si el tipo suma o resta stock
    const isPositiveType = useMemo(() => {
        const positives = ['RECEIPT', 'INITIAL', 'RETURN', 'ADJUSTMENT_IN']
        return positives.includes(type)
    }, [type])

    // Filtro para el buscador manual desplegable
    const filteredProducts = useMemo(() => {
        const q = searchQuery.trim().toLowerCase()
        if (!q) return products.slice(0, 20)
        return products.filter((p) => {
            const nameMatch = p.name?.toLowerCase().includes(q)
            const skuMatch = p.sku?.toLowerCase().includes(q)
            const barcodeMatch = p.barcode?.toLowerCase().includes(q)
            const brandMatch = p.brand?.toLowerCase().includes(q)
            return nameMatch || skuMatch || barcodeMatch || brandMatch
        }).slice(0, 30)
    }, [searchQuery, products])

    // Agregar producto a la lista (o sumar cantidad si ya existe)
    const addProductToItems = (product, qtyToAdd = 1) => {
        if (!product) return

        setItems((prev) => {
            const existingIndex = prev.findIndex((item) => item.product_id === product.id)
            if (existingIndex >= 0) {
                const updated = [...prev]
                updated[existingIndex] = {
                    ...updated[existingIndex],
                    quantity: updated[existingIndex].quantity + qtyToAdd,
                }
                return updated
            }
            return [
                ...prev,
                {
                    product_id: product.id,
                    name: product.name,
                    sku: product.sku,
                    barcode: product.barcode || '',
                    brand: product.brand || '',
                    quantity: qtyToAdd,
                    notes: '',
                },
            ]
        })

        playBeep(920, 0.08)
        setScannerFeedback({
            type: 'success',
            message: `✓ Agregado: ${product.name} (${product.sku})`,
        })
        setTimeout(() => setScannerFeedback(null), 3000)
    }

    // Manejo de lectura de pistola de código de barras (tecla Enter automática)
    const handleBarcodeKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault()
            const code = barcodeInput.trim()
            if (!code) return

            // Búsqueda por coincidencia exacta con barcode o SKU
            const matched = products.find((p) => {
                const b = (p.barcode || '').trim().toLowerCase()
                const s = (p.sku || '').trim().toLowerCase()
                const target = code.toLowerCase()
                return b === target || s === target || String(p.id) === target
            })

            if (matched) {
                addProductToItems(matched, 1)
                setBarcodeInput('')
                barcodeInputRef.current?.focus()
            } else {
                playBeep(330, 0.15) // tono grave de error
                setScannerFeedback({
                    type: 'error',
                    message: `❌ No se encontró producto con código de barras o SKU: "${code}"`,
                })
                barcodeInputRef.current?.select()
            }
        }
    }

    // Actualizar cantidad de un item
    const updateQuantity = (index, newQty) => {
        const parsed = parseInt(newQty, 10)
        const qty = isNaN(parsed) || parsed < 1 ? 1 : parsed
        setItems((prev) => {
            const updated = [...prev]
            updated[index].quantity = qty
            return updated
        })
    }

    // Actualizar notas de un item
    const updateItemNotes = (index, notesVal) => {
        setItems((prev) => {
            const updated = [...prev]
            updated[index].notes = notesVal
            return updated
        })
    }

    // Eliminar producto de la lista
    const removeItem = (index) => {
        setItems((prev) => prev.filter((_, i) => i !== index))
    }

    // Vaciar toda la lista
    const clearItems = () => {
        if (items.length === 0) return
        if (window.confirm('¿Deseas vaciar todos los productos agregados a la lista?')) {
            setItems([])
        }
    }

    // Totales de la lista
    const totalLines = items.length
    const totalPieces = items.reduce((sum, item) => sum + (parseInt(item.quantity, 10) || 0), 0)

    // Envío del formulario
    const handleSubmit = (e) => {
        e.preventDefault()
        setErrors({})

        if (!locationId) {
            setErrors({ inventory_location_id: 'Debes seleccionar una ubicación de almacén.' })
            return
        }

        if (items.length === 0) {
            setErrors({ items: 'Debes agregar al menos un producto a la lista usando el escáner o el buscador.' })
            return
        }

        setSubmitting(true)

        // Se envía payload con los items y la fecha actual automática
        const payload = {
            type,
            inventory_location_id: parseInt(locationId, 10),
            reference: reference.trim() || null,
            notes: generalNotes.trim() || null,
            occurred_at: new Date().toISOString(), // Fecha automática de hoy
            items: items.map((item) => ({
                inventory_product_id: item.product_id,
                quantity: parseInt(item.quantity, 10),
                notes: item.notes?.trim() || null,
            })),
        }

        router.post('/almacen/movimientos', payload, {
            onError: (errs) => {
                setErrors(errs)
                setSubmitting(false)
            },
            onFinish: () => {
                setSubmitting(false)
            },
        })
    }

    // Formato de fecha de hoy para el indicador informativo
    const todayFormatted = new Intl.DateTimeFormat('es-MX', {
        dateStyle: 'full',
        timeStyle: 'short',
    }).format(new Date())

    return (
        <AppShell title="Registrar movimiento">
            <Head title="Registrar movimiento" />

            <div className="mx-auto max-w-5xl space-y-6 pb-12">
                {/* Header */}
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <Link
                            href="/almacen/movimientos"
                            className="inline-flex items-center gap-1.5 text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                        >
                            <span>←</span> Volver a movimientos
                        </Link>
                        <h1 className="mt-2 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl dark:text-white">
                            Registrar movimiento de inventario
                        </h1>
                        <p className="mt-1 text-sm text-slate-500 dark:text-neutral-400">
                            Captura rápida por lote o individual con soporte para escáner de código de barras.
                        </p>
                    </div>

                    {/* Indicador de Fecha Automática */}
                    <div className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-medium text-slate-700 shadow-sm dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300">
                        <span className="flex h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span className="text-slate-500 dark:text-neutral-400">Fecha automática:</span>
                        <strong className="capitalize">{todayFormatted}</strong>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* SECCIÓN 1: TIPO PRIMERO Y UBICACIÓN */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3 dark:border-neutral-800">
                            <span className="flex h-6 w-6 items-center justify-center rounded-lg bg-indigo-100 text-xs font-bold text-indigo-700 dark:bg-indigo-900/50 dark:text-indigo-300">
                                1
                            </span>
                            <h2 className="text-base font-semibold text-slate-900 dark:text-white">
                                Configuración del Movimiento
                            </h2>
                        </div>

                        <div className="grid gap-5 sm:grid-cols-2">
                            {/* CAMPO TIPO AL PRINCIPIO */}
                            <div>
                                <label className="block text-sm font-semibold text-slate-900 dark:text-slate-100">
                                    Tipo de movimiento <span className="text-rose-500">*</span>
                                </label>
                                <div className="mt-1.5 relative">
                                    <select
                                        value={type}
                                        onChange={(e) => setType(e.target.value)}
                                        className="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 font-medium text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    >
                                        {types.map((t) => (
                                            <option key={t} value={t}>
                                                {movementTypeLabel(t)}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="mt-2 flex items-center gap-2 text-xs">
                                    {isPositiveType ? (
                                        <span className="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                                            <span>+</span> Aumenta existencias (Entrada de mercancía)
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 rounded-md bg-amber-50 px-2 py-0.5 font-medium text-amber-700 dark:bg-amber-950/60 dark:text-amber-300">
                                            <span>-</span> Disminuye existencias (Salida de mercancía)
                                        </span>
                                    )}
                                </div>
                                {errors.type && <p className="mt-1.5 text-xs text-rose-600 font-medium">{errors.type}</p>}
                            </div>

                            {/* UBICACIÓN */}
                            <div>
                                <label className="block text-sm font-semibold text-slate-900 dark:text-slate-100">
                                    Ubicación de almacén <span className="text-rose-500">*</span>
                                </label>
                                <div className="mt-1.5">
                                    <select
                                        value={locationId}
                                        onChange={(e) => setLocationId(e.target.value)}
                                        className="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 font-medium text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    >
                                        <option value="">Selecciona ubicación física</option>
                                        {locations.map((loc) => (
                                            <option key={loc.id} value={loc.id}>
                                                {loc.code} {loc.name ? `— ${loc.name}` : ''}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                {errors.inventory_location_id && (
                                    <p className="mt-1.5 text-xs text-rose-600 font-medium">{errors.inventory_location_id}</p>
                                )}
                            </div>
                        </div>

                        {/* Referencia y Notas Generales */}
                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label className="block text-xs font-medium text-slate-600 dark:text-neutral-400">
                                    Referencia opcional (Factura, Remisión, Proveedor o Folio)
                                </label>
                                <input
                                    type="text"
                                    value={reference}
                                    onChange={(e) => setReference(e.target.value)}
                                    placeholder="Ej: FAC-84920, REM-2026, Proveedor..."
                                    className="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-medium text-slate-600 dark:text-neutral-400">
                                    Notas generales del movimiento
                                </label>
                                <input
                                    type="text"
                                    value={generalNotes}
                                    onChange={(e) => setGeneralNotes(e.target.value)}
                                    placeholder="Detalles adicionales del cargamento..."
                                    className="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>
                        </div>
                    </div>

                    {/* SECCIÓN 2: BÚSQUEDA Y ESCÁNER DE PRODUCTOS */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="mb-4 flex items-center justify-between border-b border-slate-100 pb-3 dark:border-neutral-800">
                            <div className="flex items-center gap-2">
                                <span className="flex h-6 w-6 items-center justify-center rounded-lg bg-indigo-100 text-xs font-bold text-indigo-700 dark:bg-indigo-900/50 dark:text-indigo-300">
                                    2
                                </span>
                                <h2 className="text-base font-semibold text-slate-900 dark:text-white">
                                    Captura de Productos
                                </h2>
                            </div>
                            <span className="text-xs text-slate-500 dark:text-neutral-400">
                                Escanea con pistola o busca por nombre/SKU
                            </span>
                        </div>

                        {/* FEEDBACK DEL ESCÁNER */}
                        {scannerFeedback && (
                            <div
                                className={`mb-4 flex items-center justify-between rounded-xl px-4 py-2.5 text-sm font-medium transition ${
                                    scannerFeedback.type === 'success'
                                        ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/70 dark:text-emerald-300'
                                        : 'bg-rose-50 text-rose-800 dark:bg-rose-950/70 dark:text-rose-300'
                                }`}
                            >
                                <span>{scannerFeedback.message}</span>
                                <button
                                    type="button"
                                    onClick={() => setScannerFeedback(null)}
                                    className="ml-2 text-xs opacity-70 hover:opacity-100"
                                >
                                    ✕
                                </button>
                            </div>
                        )}

                        <div className="grid gap-5 lg:grid-cols-12">
                            {/* 1. LECTOR DE CÓDIGO DE BARRAS / PISTOLA SCANNER */}
                            <div className="lg:col-span-6">
                                <label className="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-slate-100">
                                    <svg className="h-4 w-4 text-indigo-600 dark:text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                        <path d="M3 5v14M8 5v14M12 5v14M17 5v14M21 5v14" />
                                    </svg>
                                    Lector de Código de Barras / Pistola
                                    <span className="rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                                        Rápido
                                    </span>
                                </label>
                                <div className="mt-1.5 relative">
                                    <input
                                        ref={barcodeInputRef}
                                        type="text"
                                        value={barcodeInput}
                                        onChange={(e) => setBarcodeInput(e.target.value)}
                                        onKeyDown={handleBarcodeKeyDown}
                                        placeholder="Escanear código o SKU + Enter..."
                                        className="w-full rounded-xl border-2 border-indigo-200 bg-indigo-50/40 px-4 py-3 font-mono text-sm text-slate-900 shadow-sm transition placeholder:font-sans placeholder:text-slate-400 focus:border-indigo-600 focus:bg-white focus:outline-none focus:ring-4 focus:ring-indigo-500/10 dark:border-indigo-900/60 dark:bg-indigo-950/20 dark:text-white dark:focus:bg-neutral-950"
                                    />
                                    <div className="absolute inset-y-0 right-0 flex items-center pr-3">
                                        <span className="rounded-lg border border-slate-200 bg-white px-2 py-1 text-[11px] font-semibold text-slate-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-400">
                                            Enter ↵
                                        </span>
                                    </div>
                                </div>
                                <p className="mt-1 text-xs text-slate-500 dark:text-neutral-400">
                                    Al disparar con la pistola, se agrega o incrementa automáticamente (+1).
                                </p>
                            </div>

                            {/* 2. BARRA DESPLEGABLE CON FILTRO */}
                            <div className="lg:col-span-6 relative" ref={searchDropdownRef}>
                                <label className="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-slate-100">
                                    <svg className="h-4 w-4 text-slate-500 dark:text-neutral-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                        <circle cx="11" cy="11" r="8" />
                                        <line x1="21" y1="21" x2="16.65" y2="16.65" />
                                    </svg>
                                    Buscador Desplegable Manual
                                </label>
                                <div className="mt-1.5 relative">
                                    <input
                                        type="text"
                                        value={searchQuery}
                                        onChange={(e) => {
                                            setSearchQuery(e.target.value)
                                            setIsDropdownOpen(true)
                                        }}
                                        onFocus={() => setIsDropdownOpen(true)}
                                        placeholder="Buscar por nombre, SKU, marca..."
                                        className="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                    {searchQuery && (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setSearchQuery('')
                                                setIsDropdownOpen(false)
                                            }}
                                            className="absolute inset-y-0 right-0 flex items-center pr-3 text-xs text-slate-400 hover:text-slate-600"
                                        >
                                            ✕
                                        </button>
                                    )}
                                </div>

                                {/* Menú flotante desplegable */}
                                {isDropdownOpen && (
                                    <div className="absolute z-30 mt-1 max-h-72 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl dark:border-neutral-700 dark:bg-neutral-900">
                                        {filteredProducts.length === 0 ? (
                                            <div className="p-4 text-center text-sm text-slate-500 dark:text-neutral-400">
                                                No se encontraron productos coincidentes.
                                            </div>
                                        ) : (
                                            <div className="divide-y divide-slate-100 dark:divide-neutral-800">
                                                {filteredProducts.map((p) => (
                                                    <button
                                                        key={p.id}
                                                        type="button"
                                                        onClick={() => {
                                                            addProductToItems(p, 1)
                                                            setSearchQuery('')
                                                            setIsDropdownOpen(false)
                                                            barcodeInputRef.current?.focus()
                                                        }}
                                                        className="flex w-full items-center justify-between p-3 text-left transition hover:bg-indigo-50 dark:hover:bg-neutral-800/80"
                                                    >
                                                        <div className="min-w-0 pr-3">
                                                            <div className="font-semibold text-slate-900 text-sm truncate dark:text-white">
                                                                {p.name}
                                                            </div>
                                                            <div className="flex flex-wrap items-center gap-2 mt-0.5 text-xs text-slate-500 dark:text-neutral-400">
                                                                <span className="font-mono bg-slate-100 px-1.5 py-0.5 rounded text-slate-700 dark:bg-neutral-800 dark:text-neutral-300">
                                                                    {p.sku}
                                                                </span>
                                                                {p.brand && <span>{p.brand}</span>}
                                                                {p.barcode && (
                                                                    <span className="font-mono text-slate-400 dark:text-neutral-500">
                                                                        Barcode: {p.barcode}
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </div>
                                                        <span className="shrink-0 rounded-lg bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white">
                                                            + Agregar
                                                        </span>
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* SECCIÓN 3: TABLA DE PRODUCTOS A REGISTRAR (VARIOS A LA VEZ) */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between border-b border-slate-100 pb-3 dark:border-neutral-800">
                            <div className="flex items-center gap-2">
                                <span className="flex h-6 w-6 items-center justify-center rounded-lg bg-indigo-100 text-xs font-bold text-indigo-700 dark:bg-indigo-900/50 dark:text-indigo-300">
                                    3
                                </span>
                                <h2 className="text-base font-semibold text-slate-900 dark:text-white">
                                    Productos en este Movimiento ({totalLines} items · {totalPieces} pzas)
                                </h2>
                            </div>

                            {items.length > 0 && (
                                <button
                                    type="button"
                                    onClick={clearItems}
                                    className="text-xs font-medium text-rose-600 hover:text-rose-700 hover:underline dark:text-rose-400"
                                >
                                    Vaciar lista
                                </button>
                            )}
                        </div>

                        {errors.items && (
                            <div className="mb-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700 dark:bg-rose-950/60 dark:text-rose-300">
                                {errors.items}
                            </div>
                        )}
                        {errors.quantity && (
                            <div className="mb-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700 dark:bg-rose-950/60 dark:text-rose-300">
                                {errors.quantity}
                            </div>
                        )}

                        {items.length === 0 ? (
                            <div className="flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-slate-200 py-12 px-4 text-center dark:border-neutral-800">
                                <div className="flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400 dark:bg-neutral-800 dark:text-neutral-500">
                                    <svg className="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                        <path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                                    </svg>
                                </div>
                                <h3 className="mt-3 text-sm font-semibold text-slate-900 dark:text-white">
                                    No hay productos en la lista
                                </h3>
                                <p className="mt-1 text-xs text-slate-500 max-w-sm dark:text-neutral-400">
                                    Usa la pistola de código de barras o el buscador desplegable arriba para añadir varios productos a la vez.
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr className="border-b border-slate-200 text-xs font-semibold text-slate-500 dark:border-neutral-800 dark:text-neutral-400">
                                            <th className="pb-3 pl-1">Producto</th>
                                            <th className="pb-3 text-center w-36">Cantidad</th>
                                            <th className="pb-3 w-48">Nota / Detalle</th>
                                            <th className="pb-3 text-right pr-1 w-16">Quitar</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-neutral-800/80">
                                        {items.map((item, idx) => (
                                            <tr key={item.product_id} className="group hover:bg-slate-50/70 dark:hover:bg-neutral-800/40">
                                                <td className="py-3 pl-1 pr-3">
                                                    <div className="font-semibold text-slate-900 dark:text-white">
                                                        {item.name}
                                                    </div>
                                                    <div className="flex flex-wrap items-center gap-2 mt-0.5 text-xs text-slate-500 dark:text-neutral-400">
                                                        <span className="font-mono bg-slate-100 px-1.5 py-0.5 rounded text-slate-700 dark:bg-neutral-800 dark:text-neutral-300">
                                                            SKU: {item.sku}
                                                        </span>
                                                        {item.barcode && (
                                                            <span className="font-mono text-slate-400 dark:text-neutral-500">
                                                                Barcode: {item.barcode}
                                                            </span>
                                                        )}
                                                        {item.brand && <span>{item.brand}</span>}
                                                    </div>
                                                </td>

                                                {/* Controles de Cantidad */}
                                                <td className="py-3 px-2">
                                                    <div className="flex items-center justify-center gap-1.5">
                                                        <button
                                                            type="button"
                                                            onClick={() => updateQuantity(idx, item.quantity - 1)}
                                                            className="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-300 bg-white font-bold text-slate-700 shadow-sm hover:bg-slate-100 active:scale-95 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                                            title="Restar 1"
                                                        >
                                                            -
                                                        </button>
                                                        <input
                                                            type="number"
                                                            min="1"
                                                            step="1"
                                                            value={item.quantity}
                                                            onChange={(e) => updateQuantity(idx, e.target.value)}
                                                            className="h-8 w-16 rounded-lg border border-slate-300 px-2 text-center font-bold text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                                        />
                                                        <button
                                                            type="button"
                                                            onClick={() => updateQuantity(idx, item.quantity + 1)}
                                                            className="flex h-8 w-8 items-center justify-center rounded-lg border border-slate-300 bg-white font-bold text-slate-700 shadow-sm hover:bg-slate-100 active:scale-95 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                                            title="Sumar 1"
                                                        >
                                                            +
                                                        </button>
                                                    </div>
                                                </td>

                                                {/* Nota opcional por ítem */}
                                                <td className="py-3 px-2">
                                                    <input
                                                        type="text"
                                                        value={item.notes}
                                                        onChange={(e) => updateItemNotes(idx, e.target.value)}
                                                        placeholder="Detalle o lote opcional..."
                                                        className="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                                    />
                                                </td>

                                                {/* Botón Quitar */}
                                                <td className="py-3 pr-1 pl-2 text-right">
                                                    <button
                                                        type="button"
                                                        onClick={() => removeItem(idx)}
                                                        className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/40 dark:hover:text-rose-300"
                                                        title="Eliminar de la lista"
                                                    >
                                                        <svg className="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                                            <path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                        </svg>
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot>
                                        <tr className="border-t-2 border-slate-200 font-bold text-slate-900 dark:border-neutral-700 dark:text-white">
                                            <td className="pt-3.5 pl-1">
                                                Total acumulado en este registro:
                                            </td>
                                            <td className="pt-3.5 text-center text-base text-indigo-600 dark:text-indigo-400">
                                                {totalPieces} piezas
                                            </td>
                                            <td className="pt-3.5 text-xs font-normal text-slate-500 dark:text-neutral-400" colSpan={2}>
                                                {totalLines} producto{totalLines === 1 ? '' : 's'} diferente{totalLines === 1 ? '' : 's'}
                                            </td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        )}
                    </div>

                    {/* BOTONES DE ACCIÓN */}
                    <div className="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
                        <Link
                            href="/almacen/movimientos"
                            className="inline-flex justify-center rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200 dark:hover:bg-neutral-700"
                        >
                            Cancelar
                        </Link>
                        <button
                            type="submit"
                            disabled={submitting || items.length === 0}
                            className={`inline-flex items-center justify-center gap-2 rounded-xl px-6 py-2.5 text-sm font-bold text-white shadow-md transition disabled:cursor-not-allowed disabled:opacity-50 ${
                                isPositiveType
                                    ? 'bg-emerald-600 hover:bg-emerald-700 focus:ring-4 focus:ring-emerald-500/20'
                                    : 'bg-indigo-600 hover:bg-indigo-700 focus:ring-4 focus:ring-indigo-500/20'
                            }`}
                        >
                            {submitting ? (
                                <>
                                    <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    <span>Guardando movimientos...</span>
                                </>
                            ) : (
                                <>
                                    <span>
                                        {isPositiveType ? '✓ Registrar Entrada' : 'Registrar Salida / Movimiento'}
                                    </span>
                                    {totalPieces > 0 && (
                                        <span className="rounded-lg bg-black/20 px-2 py-0.5 text-xs font-bold">
                                            {totalPieces} piezas
                                        </span>
                                    )}
                                </>
                            )}
                        </button>
                    </div>
                </form>
            </div>
        </AppShell>
    )
}
