import AppShell from '@/Components/layout/AppShell'
import CameraBarcodeScanner from '@/Components/CameraBarcodeScanner'
import { movementTypeLabel } from '@/lib/inventoryPresentation'
import { Head, Link, router } from '@inertiajs/react'
import { useState, useRef, useEffect, useMemo } from 'react'

export default function InventoryMovementForm({ products = [], locations = [], types = [] }) {
    // 1. Tipo primero, Ubicación y datos generales
    const defaultType = types.includes('RECEIPT') ? 'RECEIPT' : (types[0] || 'RECEIPT')
    const [type, setType] = useState(defaultType)
    const [locationId, setLocationId] = useState(locations[0]?.id ? String(locations[0].id) : '')
    const [destinationLocationId, setDestinationLocationId] = useState(
        locations.length > 1 ? String(locations[1]?.id) : (locations[0]?.id ? String(locations[0].id) : '')
    )
    const [reference, setReference] = useState('')
    const [generalNotes, setGeneralNotes] = useState('')

    // 2. Lista de productos capturados en lote
    // Array of { product_id, name, sku, barcode, brand, quantity, location_id, location_code, is_primary, notes }
    const [items, setItems] = useState([])

    // 3. Estado de búsqueda y escáner
    const [barcodeInput, setBarcodeInput] = useState('')
    const [searchQuery, setSearchQuery] = useState('')
    const [isDropdownOpen, setIsDropdownOpen] = useState(false)
    const [scannerFeedback, setScannerFeedback] = useState(null) // { type: 'success' | 'error', message: string }

    // 4. Modal de Cámara del Celular
    const [isCameraOpen, setIsCameraOpen] = useState(false)

    // 5. Mini Pantalla / Modal de Cantidad al Escanear
    const [promptQuantityOnScan, setPromptQuantityOnScan] = useState(() => {
        try {
            return localStorage.getItem('inv_prompt_qty') !== 'false'
        } catch {
            return true
        }
    })
    const [isQuantityModalOpen, setIsQuantityModalOpen] = useState(false)
    const [modalProduct, setModalProduct] = useState(null)
    const [modalQuantity, setModalQuantity] = useState(1)
    const [modalLocationId, setModalLocationId] = useState('')
    const [modalNotes, setModalNotes] = useState('')

    const [submitting, setSubmitting] = useState(false)
    const [errors, setErrors] = useState({})

    const barcodeInputRef = useRef(null)
    const searchDropdownRef = useRef(null)
    const modalQuantityInputRef = useRef(null)

    // Guardar preferencia de promptQuantityOnScan
    const togglePromptQuantity = (val) => {
        setPromptQuantityOnScan(val)
        try {
            localStorage.setItem('inv_prompt_qty', String(val))
        } catch {}
    }

    // Enfocar automáticamente el lector de código de barras al cargar
    useEffect(() => {
        barcodeInputRef.current?.focus()
    }, [])

    // Enfocar y preseleccionar la cantidad al abrir el modal de cantidad
    useEffect(() => {
        if (isQuantityModalOpen) {
            setTimeout(() => {
                modalQuantityInputRef.current?.focus()
                modalQuantityInputRef.current?.select()
            }, 60)
        }
    }, [isQuantityModalOpen])

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

    // Manejo de tecla Escape para cerrar modal de cantidad
    useEffect(() => {
        const handleKeyDown = (e) => {
            if (e.key === 'Escape' && isQuantityModalOpen) {
                closeQuantityModal()
            }
        }
        window.addEventListener('keydown', handleKeyDown)
        return () => window.removeEventListener('keydown', handleKeyDown)
    }, [isQuantityModalOpen])

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

    // Clasificación de si el tipo suma, resta o transfiere stock
    const isPositiveType = useMemo(() => {
        const positives = ['RECEIPT', 'INITIAL', 'RETURN', 'ADJUSTMENT_IN']
        return positives.includes(type)
    }, [type])

    const isTransferType = type === 'TRANSFER'

    // Filtro para el buscador manual desplegable
    const filteredProducts = useMemo(() => {
        const q = searchQuery.trim().toLowerCase()
        if (!q) return products.slice(0, 20)
        return products.filter((p) => {
            const nameMatch = p.name?.toLowerCase().includes(q)
            const skuMatch = p.sku?.toLowerCase().includes(q)
            const barcodeMatch = p.barcode?.toLowerCase().includes(q) || p.barcode_secondary?.toLowerCase().includes(q)
            const brandMatch = p.brand?.toLowerCase().includes(q)
            return nameMatch || skuMatch || barcodeMatch || brandMatch
        }).slice(0, 30)
    }, [searchQuery, products])

    // Abrir mini pantalla de cantidad
    const openQuantityModal = (product) => {
        if (!product) return
        setModalProduct(product)
        setModalQuantity(1)

        // Usar ubicación asignada al producto automáticamente, o respaldo
        const assignedLoc = product.primary_location_id
            ? String(product.primary_location_id)
            : (locationId ? String(locationId) : '')
        setModalLocationId(assignedLoc)
        setModalNotes('')
        setIsQuantityModalOpen(true)
        playBeep(750, 0.06)
    }

    const closeQuantityModal = () => {
        setIsQuantityModalOpen(false)
        setModalProduct(null)
        setTimeout(() => {
            barcodeInputRef.current?.focus()
        }, 50)
    }

    // Confirmar desde el modal de cantidad
    const handleConfirmQuantityModal = (e) => {
        e?.preventDefault()
        if (!modalProduct) return

        const qty = parseInt(modalQuantity, 10)
        if (isNaN(qty) || qty < 1) {
            window.alert('La cantidad debe ser mayor a 0.')
            return
        }

        addProductToItems(modalProduct, qty, modalLocationId, modalNotes)
        closeQuantityModal()
    }

    // Agregar producto a la lista (o sumar cantidad si ya existe en esa misma ubicación)
    const addProductToItems = (product, qtyToAdd = 1, specificLocationId = null, notes = '') => {
        if (!product) return

        // 1. Determinar ubicación: prioridad a la específica seleccionada, luego asignada al producto, luego respaldo general
        const finalLocId = specificLocationId
            ? String(specificLocationId)
            : (product.primary_location_id ? String(product.primary_location_id) : (locationId ? String(locationId) : ''))

        const locObj = locations.find((l) => String(l.id) === String(finalLocId))
        const locCode = locObj ? locObj.code : (product.primary_location?.code || 'General')
        const isPrimary = Boolean(product.primary_location_id && String(product.primary_location_id) === String(finalLocId))

        setItems((prev) => {
            // Si ya existe el mismo producto en la misma ubicación, sumamos cantidad
            const existingIndex = prev.findIndex(
                (item) => item.product_id === product.id && String(item.location_id || '') === String(finalLocId || '')
            )
            if (existingIndex >= 0) {
                const updated = [...prev]
                updated[existingIndex] = {
                    ...updated[existingIndex],
                    quantity: updated[existingIndex].quantity + qtyToAdd,
                    notes: notes ? (updated[existingIndex].notes ? `${updated[existingIndex].notes} | ${notes}` : notes) : updated[existingIndex].notes,
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
                    location_id: finalLocId ? parseInt(finalLocId, 10) : null,
                    location_code: locCode,
                    is_primary_location: isPrimary,
                    notes: notes || '',
                },
            ]
        })

        playBeep(920, 0.08)
        setScannerFeedback({
            type: 'success',
            message: `✓ Agregado: ${product.name} (${qtyToAdd} pza${qtyToAdd === 1 ? '' : 's'} en ${locCode})`,
        })
        setTimeout(() => setScannerFeedback(null), 3500)
    }

    // Búsqueda común para pistola y cámara
    const findProductByCode = (rawCode) => {
        const code = (rawCode || '').trim().toLowerCase()
        if (!code) return null
        return products.find((p) => {
            const b = (p.barcode || '').trim().toLowerCase()
            const b2 = (p.barcode_secondary || '').trim().toLowerCase()
            const s = (p.sku || '').trim().toLowerCase()
            return b === code || b2 === code || s === code || String(p.id) === code
        })
    }

    // Manejo de lectura de pistola de código de barras (tecla Enter automática)
    const handleBarcodeKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault()
            const code = barcodeInput.trim()
            if (!code) return

            const matched = findProductByCode(code)

            if (matched) {
                setBarcodeInput('')
                if (promptQuantityOnScan) {
                    openQuantityModal(matched)
                } else {
                    addProductToItems(matched, 1)
                    barcodeInputRef.current?.focus()
                }
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

    // Manejo de lectura desde la Cámara del Celular
    const handleCameraScan = (code) => {
        const matched = findProductByCode(code)
        if (matched) {
            setIsCameraOpen(false)
            if (promptQuantityOnScan) {
                openQuantityModal(matched)
            } else {
                addProductToItems(matched, 1)
                barcodeInputRef.current?.focus()
            }
        } else {
            playBeep(330, 0.15)
            setScannerFeedback({
                type: 'error',
                message: `❌ Código de barras no encontrado en catálogo: "${code}"`,
            })
            setTimeout(() => setScannerFeedback(null), 4000)
        }
    }

    // Actualizar cantidad de un item en la tabla
    const updateQuantity = (index, newQty) => {
        const parsed = parseInt(newQty, 10)
        const qty = isNaN(parsed) || parsed < 1 ? 1 : parsed
        setItems((prev) => {
            const updated = [...prev]
            updated[index].quantity = qty
            return updated
        })
    }

    // Actualizar ubicación de un item en la tabla
    const updateItemLocation = (index, newLocId) => {
        const locObj = locations.find((l) => String(l.id) === String(newLocId))
        setItems((prev) => {
            const updated = [...prev]
            const product = products.find((p) => p.id === updated[index].product_id)
            updated[index].location_id = newLocId ? parseInt(newLocId, 10) : null
            updated[index].location_code = locObj ? locObj.code : 'Sin ubicación'
            updated[index].is_primary_location = Boolean(
                product?.primary_location_id && String(product.primary_location_id) === String(newLocId)
            )
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

        if (items.length === 0) {
            setErrors({ items: 'Debes agregar al menos un producto a la lista usando el escáner o el buscador.' })
            return
        }

        // Verificar que cada item tenga una ubicación (propia, asignada o de respaldo)
        const missingLocItem = items.find((item) => !item.location_id && !locationId)
        if (missingLocItem) {
            setErrors({
                inventory_location_id: `El producto "${missingLocItem.name}" no tiene ubicación. Selecciona una ubicación en la tabla o en la configuración general.`,
            })
            return
        }

        if (isTransferType) {
            if (!destinationLocationId) {
                setErrors({ destination_location_id: 'Debes seleccionar la ubicación de destino a la que se moverán los productos.' })
                return
            }
            if (locationId && String(locationId) === String(destinationLocationId)) {
                setErrors({ destination_location_id: 'La ubicación de destino debe ser diferente a la ubicación de origen.' })
                return
            }
        }

        setSubmitting(true)

        // Se envía payload con los items y la ubicación por ítem
        const payload = {
            type,
            inventory_location_id: locationId ? parseInt(locationId, 10) : null,
            destination_location_id: isTransferType && destinationLocationId ? parseInt(destinationLocationId, 10) : null,
            reference: reference.trim() || null,
            notes: generalNotes.trim() || null,
            occurred_at: new Date().toISOString(),
            items: items.map((item) => ({
                inventory_product_id: item.product_id,
                inventory_location_id: item.location_id ? parseInt(item.location_id, 10) : null,
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
                            Captura rápida por lote o individual con soporte para escáner físico y cámara de celular.
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
                    {/* SECCIÓN 1: TIPO PRIMERO Y UBICACIÓN DE RESPALDO */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="mb-4 flex items-center gap-2 border-b border-slate-100 pb-3 dark:border-neutral-800">
                            <span className="flex h-6 w-6 items-center justify-center rounded-lg bg-indigo-100 text-xs font-bold text-indigo-700 dark:bg-indigo-900/50 dark:text-indigo-300">
                                1
                            </span>
                            <div>
                                <h2 className="text-base font-semibold text-slate-900 dark:text-white">
                                    Configuración del Movimiento
                                </h2>
                                <p className="text-xs text-slate-500 dark:text-neutral-400">
                                    Los productos usarán automáticamente la ubicación que tengan asignada en su catálogo (ej. E5-1).
                                </p>
                            </div>
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
                                    {isTransferType ? (
                                        <span className="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-0.5 font-medium text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300">
                                            <span>🔄</span> Mover productos (Salida de Origen y Entrada en Destino)
                                        </span>
                                    ) : isPositiveType ? (
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

                            {/* UBICACIONES */}
                            {isTransferType ? (
                                <div className="space-y-4">
                                    <div>
                                        <label className="block text-sm font-semibold text-slate-900 dark:text-slate-100">
                                            📍 Ubicación Origen (De dónde sale) <span className="text-rose-500">*</span>
                                        </label>
                                        <div className="mt-1.5">
                                            <select
                                                value={locationId}
                                                onChange={(e) => setLocationId(e.target.value)}
                                                className="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 font-medium text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                            >
                                                <option value="">(Ubicación asignada a cada producto)</option>
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

                                    <div>
                                        <label className="block text-sm font-semibold text-slate-900 dark:text-slate-100">
                                            🎯 Ubicación Destino (A dónde entra) <span className="text-rose-500">*</span>
                                        </label>
                                        <div className="mt-1.5">
                                            <select
                                                value={destinationLocationId}
                                                onChange={(e) => setDestinationLocationId(e.target.value)}
                                                className="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 font-medium text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                            >
                                                <option value="">Selecciona ubicación destino...</option>
                                                {locations.map((loc) => (
                                                    <option key={loc.id} value={loc.id}>
                                                        {loc.code} {loc.name ? `— ${loc.name}` : ''}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <p className="mt-1.5 text-[11px] text-indigo-600 dark:text-indigo-400">
                                            🔄 Se descontará el stock de la ubicación origen y se sumará en la ubicación destino.
                                        </p>
                                        {errors.destination_location_id && (
                                            <p className="mt-1.5 text-xs text-rose-600 font-medium">{errors.destination_location_id}</p>
                                        )}
                                    </div>
                                </div>
                            ) : (
                                <div>
                                    <label className="block text-sm font-semibold text-slate-900 dark:text-slate-100">
                                        Ubicación general / Respaldo
                                    </label>
                                    <div className="mt-1.5">
                                        <select
                                            value={locationId}
                                            onChange={(e) => setLocationId(e.target.value)}
                                            className="w-full rounded-xl border border-slate-300 bg-white px-4 py-2.5 font-medium text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                        >
                                            <option value="">(Ubicación automática de cada producto)</option>
                                            {locations.map((loc) => (
                                                <option key={loc.id} value={loc.id}>
                                                    {loc.code} {loc.name ? `— ${loc.name}` : ''}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <p className="mt-1.5 text-[11px] text-slate-500 dark:text-neutral-400">
                                        💡 Se aplicará como respaldo únicamente si algún producto escaneado no tiene ubicación asignada.
                                    </p>
                                    {errors.inventory_location_id && (
                                        <p className="mt-1.5 text-xs text-rose-600 font-medium">{errors.inventory_location_id}</p>
                                    )}
                                </div>
                            )}
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

                    {/* SECCIÓN 2: CAPTURA DE PRODUCTOS (PISTOLA / CÁMARA MÓVIL / BUSCADOR) */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6 dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between border-b border-slate-100 pb-3 dark:border-neutral-800">
                            <div className="flex items-center gap-2">
                                <span className="flex h-6 w-6 items-center justify-center rounded-lg bg-indigo-100 text-xs font-bold text-indigo-700 dark:bg-indigo-900/50 dark:text-indigo-300">
                                    2
                                </span>
                                <h2 className="text-base font-semibold text-slate-900 dark:text-white">
                                    Captura de Productos
                                </h2>
                            </div>

                            {/* Switch: Preguntar cantidad al escanear */}
                            <label className="flex items-center gap-2 cursor-pointer select-none text-xs font-semibold text-slate-700 dark:text-slate-300">
                                <input
                                    type="checkbox"
                                    checked={promptQuantityOnScan}
                                    onChange={(e) => togglePromptQuantity(e.target.checked)}
                                    className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 dark:border-neutral-700"
                                />
                                <span>Mostrar mini pantalla para poner cantidad al escanear</span>
                            </label>
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
                                <div className="flex items-center justify-between">
                                    <label className="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-slate-100">
                                        <svg className="h-4 w-4 text-indigo-600 dark:text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                                            <path d="M3 5v14M8 5v14M12 5v14M17 5v14M21 5v14" />
                                        </svg>
                                        Lector de Código / Pistola
                                        <span className="rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                                            USB / BT
                                        </span>
                                    </label>

                                    {/* BOTÓN CÁMARA CELULAR */}
                                    <button
                                        type="button"
                                        onClick={() => setIsCameraOpen(true)}
                                        className="inline-flex items-center gap-1.5 rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-bold text-indigo-700 shadow-sm transition hover:bg-indigo-100 active:scale-95 dark:border-indigo-800 dark:bg-indigo-950/60 dark:text-indigo-300 dark:hover:bg-indigo-900"
                                        title="Abrir cámara del celular para escanear"
                                    >
                                        <span>📷</span>
                                        <span>Cámara Móvil</span>
                                    </button>
                                </div>

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
                                    {promptQuantityOnScan
                                        ? 'Al disparar con la pistola, se abrirá la mini pantalla para poner la cantidad y ubicación.'
                                        : 'Modo continuo activo: cada lectura suma 1 pza automáticamente.'}
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
                                                            setSearchQuery('')
                                                            setIsDropdownOpen(false)
                                                            if (promptQuantityOnScan) {
                                                                openQuantityModal(p)
                                                            } else {
                                                                addProductToItems(p, 1)
                                                                barcodeInputRef.current?.focus()
                                                            }
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
                                                                {p.primary_location?.code && (
                                                                    <span className="rounded bg-emerald-50 px-1.5 py-0.5 font-semibold text-emerald-700 border border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800">
                                                                        📍 {p.primary_location.code}
                                                                    </span>
                                                                )}
                                                                {p.brand && <span>{p.brand}</span>}
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

                    {/* SECCIÓN 3: TABLA DE PRODUCTOS A REGISTRAR */}
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
                                    Usa la pistola de código de barras, la cámara de tu celular o el buscador arriba para añadir los productos.
                                </p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr className="border-b border-slate-200 text-xs font-semibold text-slate-500 dark:border-neutral-800 dark:text-neutral-400">
                                            <th className="pb-3 pl-1">Producto</th>
                                            <th className="pb-3 w-44">Ubicación</th>
                                            <th className="pb-3 text-center w-36">Cantidad</th>
                                            <th className="pb-3 w-40">Nota / Detalle</th>
                                            <th className="pb-3 text-right pr-1 w-12">Quitar</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-neutral-800/80">
                                        {items.map((item, idx) => (
                                            <tr key={`${item.product_id}-${item.location_id || idx}`} className="group hover:bg-slate-50/70 dark:hover:bg-neutral-800/40">
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

                                                {/* Selector de Ubicación por Producto */}
                                                <td className="py-3 px-2">
                                                    <div className="space-y-1">
                                                        <select
                                                            value={item.location_id ? String(item.location_id) : ''}
                                                            onChange={(e) => updateItemLocation(idx, e.target.value)}
                                                            className={`w-full rounded-lg border px-2 py-1 text-xs font-semibold ${
                                                                item.is_primary_location
                                                                    ? 'border-emerald-300 bg-emerald-50/70 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                                                                    : 'border-slate-300 bg-white text-slate-800 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white'
                                                            }`}
                                                        >
                                                            <option value="">Selecciona ubicación</option>
                                                            {locations.map((loc) => (
                                                                <option key={loc.id} value={loc.id}>
                                                                    {loc.code} {loc.name ? `(${loc.name})` : ''}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        {item.is_primary_location && (
                                                            <span className="inline-block text-[10px] font-bold text-emerald-600 dark:text-emerald-400">
                                                                ✓ Ubicación asignada
                                                            </span>
                                                        )}
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
                                            <td></td>
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

            {/* MINI PANTALLA / MODAL DE CANTIDAD AL ESCANEAR */}
            {isQuantityModalOpen && modalProduct && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm">
                    <div className="w-full max-w-md overflow-hidden rounded-3xl bg-white shadow-2xl dark:bg-neutral-900 border border-slate-200 dark:border-neutral-800 animate-in fade-in zoom-in-95 duration-150">
                        {/* Header del Modal */}
                        <div className="border-b border-slate-100 bg-slate-50/70 p-5 dark:border-neutral-800 dark:bg-neutral-950">
                            <div className="flex items-start justify-between">
                                <div className="space-y-1 pr-3">
                                    <span className="inline-flex items-center gap-1 rounded-full bg-indigo-100 px-2.5 py-0.5 text-[11px] font-bold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                                        📦 Producto detectado
                                    </span>
                                    <h3 className="text-base font-bold text-slate-900 dark:text-white leading-snug">
                                        {modalProduct.name}
                                    </h3>
                                    <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-neutral-400">
                                        <span className="font-mono font-bold text-slate-700 dark:text-slate-300">
                                            SKU: {modalProduct.sku}
                                        </span>
                                        {modalProduct.barcode && (
                                            <span className="font-mono">
                                                Cód: {modalProduct.barcode}
                                            </span>
                                        )}
                                        {modalProduct.brand && (
                                            <span className="rounded bg-slate-200 px-1.5 py-0.2 text-[10px] font-bold uppercase dark:bg-neutral-800">
                                                {modalProduct.brand}
                                            </span>
                                        )}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={closeQuantityModal}
                                    className="rounded-xl p-1.5 text-slate-400 hover:bg-slate-200 hover:text-slate-700 dark:hover:bg-neutral-800 dark:hover:text-white"
                                    title="Cerrar (Esc)"
                                >
                                    ✕
                                </button>
                            </div>
                        </div>

                        {/* Formulario de Cantidad y Ubicación */}
                        <form onSubmit={handleConfirmQuantityModal} className="p-5 space-y-4">
                            {/* UBICACIÓN ASIGNADA AUTOMÁTICA */}
                            <div className="rounded-xl bg-slate-50 p-3.5 border border-slate-200 dark:bg-neutral-950 dark:border-neutral-800">
                                <label className="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                                    📍 Ubicación de Almacén:
                                </label>
                                <select
                                    value={modalLocationId}
                                    onChange={(e) => setModalLocationId(e.target.value)}
                                    className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-900 shadow-sm focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                                >
                                    <option value="">Selecciona ubicación</option>
                                    {locations.map((loc) => (
                                        <option key={loc.id} value={loc.id}>
                                            {loc.code} {loc.name ? `— ${loc.name}` : ''}
                                            {modalProduct.primary_location_id === loc.id ? ' ★ (Asignada en catálogo)' : ''}
                                        </option>
                                    ))}
                                </select>
                                {modalProduct.primary_location_id && String(modalProduct.primary_location_id) === String(modalLocationId) ? (
                                    <p className="mt-1 text-[11px] font-bold text-emerald-600 dark:text-emerald-400 flex items-center gap-1">
                                        <span>✓</span> Ubicación predeterminada del producto (utilizada automáticamente).
                                    </p>
                                ) : (
                                    <p className="mt-1 text-[11px] text-amber-600 dark:text-amber-400">
                                        ⚠️ Ubicación personalizada para este lote.
                                    </p>
                                )}
                            </div>

                            {/* ENTRADA GIGANTE DE CANTIDAD */}
                            <div>
                                <label className="block text-center text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400 mb-2">
                                    {isPositiveType ? 'Cantidad a ingresar al almacén' : 'Cantidad a retirar / mover'}
                                </label>
                                <div className="flex items-center justify-center gap-3">
                                    <button
                                        type="button"
                                        onClick={() => setModalQuantity((prev) => Math.max(1, (parseInt(prev, 10) || 1) - 1))}
                                        className="flex h-12 w-12 items-center justify-center rounded-2xl border border-slate-300 bg-slate-100 text-xl font-bold text-slate-700 shadow-sm transition hover:bg-slate-200 active:scale-95 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                    >
                                        -
                                    </button>
                                    <input
                                        ref={modalQuantityInputRef}
                                        type="number"
                                        min="1"
                                        required
                                        value={modalQuantity}
                                        onChange={(e) => setModalQuantity(e.target.value)}
                                        className="h-16 w-32 rounded-2xl border-2 border-indigo-500 bg-white text-center font-mono text-3xl font-extrabold text-slate-900 shadow-lg focus:border-indigo-600 focus:outline-none focus:ring-4 focus:ring-indigo-500/20 dark:border-indigo-500 dark:bg-neutral-950 dark:text-white"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setModalQuantity((prev) => (parseInt(prev, 10) || 0) + 1)}
                                        className="flex h-12 w-12 items-center justify-center rounded-2xl border border-slate-300 bg-slate-100 text-xl font-bold text-slate-700 shadow-sm transition hover:bg-slate-200 active:scale-95 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                    >
                                        +
                                    </button>
                                </div>

                                {/* Botones de incremento rápido */}
                                <div className="mt-3 flex flex-wrap items-center justify-center gap-1.5">
                                    {[1, 5, 10, 12, 24, 48, 100].map((step) => (
                                        <button
                                            key={step}
                                            type="button"
                                            onClick={() => setModalQuantity((prev) => (parseInt(prev, 10) || 0) + step)}
                                            className="rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-bold text-slate-700 hover:bg-indigo-50 hover:border-indigo-300 hover:text-indigo-600 active:scale-95 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-300"
                                        >
                                            +{step}
                                        </button>
                                    ))}
                                    <button
                                        type="button"
                                        onClick={() => setModalQuantity(1)}
                                        className="rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-500 hover:bg-slate-100 dark:border-neutral-700 dark:bg-neutral-800"
                                    >
                                        =1
                                    </button>
                                </div>
                            </div>

                            {/* Notas opcionales del ítem */}
                            <div>
                                <label className="block text-xs font-medium text-slate-600 dark:text-neutral-400 mb-1">
                                    Nota o detalle opcional para este producto:
                                </label>
                                <input
                                    type="text"
                                    value={modalNotes}
                                    onChange={(e) => setModalNotes(e.target.value)}
                                    placeholder="Ej: Lote 2026, Caja abierta..."
                                    className="w-full rounded-xl border border-slate-300 px-3 py-1.5 text-xs text-slate-900 placeholder:text-slate-400 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            {/* Botones de acción */}
                            <div className="flex items-center justify-end gap-2.5 pt-3 border-t border-slate-100 dark:border-neutral-800">
                                <button
                                    type="button"
                                    onClick={closeQuantityModal}
                                    className="rounded-xl border border-slate-300 px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-300"
                                >
                                    Cancelar (Esc)
                                </button>
                                <button
                                    type="submit"
                                    className="flex-1 rounded-xl bg-indigo-600 px-5 py-2.5 text-xs font-bold text-white shadow-md hover:bg-indigo-700 focus:ring-4 focus:ring-indigo-500/20 active:scale-98 transition flex items-center justify-center gap-1.5"
                                >
                                    <span>✓ Agregar a la lista</span>
                                    <span className="opacity-70 text-[10px]">(Enter)</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* MODAL DE CÁMARA PARA CELULAR */}
            <CameraBarcodeScanner
                isOpen={isCameraOpen}
                onScan={handleCameraScan}
                onClose={() => setIsCameraOpen(false)}
                title="Escanear Producto con Cámara"
            />
        </AppShell>
    )
}
