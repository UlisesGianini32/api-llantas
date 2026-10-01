import { useState, useMemo } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

export default function MeliFullShipmentsCreate({
    nextShipmentCode = 'FULL-ENV-2026-0001',
    warehouses = {},
    carriers = [],
    products = [],
}) {
    // Form Header State
    const [shipmentCode, setShipmentCode] = useState(nextShipmentCode)
    const [warehouseCode, setWarehouseCode] = useState('MXCD01')
    const [warehouseName, setWarehouseName] = useState(warehouses['MXCD01'] || '')
    const [meliShipmentId, setMeliShipmentId] = useState('')
    const [carrier, setCarrier] = useState('Paquetexpress')
    const [trackingNumber, setTrackingNumber] = useState('')
    const [enviaCost, setEnviaCost] = useState('')
    const [notes, setNotes] = useState('')

    // Boxes State (Array of boxes with capacity 30)
    const [boxes, setBoxes] = useState([
        {
            id: 1,
            box_number: 1,
            capacity: 30,
            dimensions: '40x30x30',
            weight_kg: '',
            items: [],
        },
    ])

    // Product search query per box
    const [productSearch, setProductSearch] = useState('')
    const [selectedProduct, setSelectedProduct] = useState(null)
    const [itemQty, setItemQty] = useState(30)
    const [activeBoxIndex, setActiveBoxIndex] = useState(0)
    const [submitting, setSubmitting] = useState(false)
    const [errors, setErrors] = useState({})

    const handleWarehouseChange = (code) => {
        setWarehouseCode(code)
        setWarehouseName(warehouses[code] || '')
    }

    // Filtered products for quick picker
    const filteredProducts = useMemo(() => {
        if (!productSearch.trim()) return products.slice(0, 10)
        const q = productSearch.toLowerCase()
        return products
            .filter((p) => (p.sku && p.sku.toLowerCase().includes(q)) || (p.name && p.name.toLowerCase().includes(q)) || (p.barcode && p.barcode.toLowerCase().includes(q)))
            .slice(0, 20)
    }, [products, productSearch])

    // Add another standard 30-unit box
    const addBox = () => {
        const nextNum = boxes.length + 1
        setBoxes([
            ...boxes,
            {
                id: Date.now(),
                box_number: nextNum,
                capacity: 30,
                dimensions: '40x30x30',
                weight_kg: '',
                items: [],
            },
        ])
        setActiveBoxIndex(boxes.length)
    }

    // Duplicate box
    const duplicateBox = (boxIndex) => {
        const sourceBox = boxes[boxIndex]
        const nextNum = boxes.length + 1
        const duplicated = {
            id: Date.now(),
            box_number: nextNum,
            capacity: sourceBox.capacity,
            dimensions: sourceBox.dimensions,
            weight_kg: sourceBox.weight_kg,
            items: sourceBox.items.map((it) => ({ ...it })),
        }
        setBoxes([...boxes, duplicated])
        setActiveBoxIndex(boxes.length)
    }

    // Remove box
    const removeBox = (boxIndex) => {
        if (boxes.length <= 1) {
            alert('El envío debe contener al menos 1 caja.')
            return
        }
        const updated = boxes.filter((_, idx) => idx !== boxIndex).map((b, idx) => ({ ...b, box_number: idx + 1 }))
        setBoxes(updated)
        if (activeBoxIndex >= updated.length) {
            setActiveBoxIndex(updated.length - 1)
        }
    }

    // Add item to active box
    const addItemToBox = (boxIndex, product, qty) => {
        const q = parseInt(qty, 10)
        if (isNaN(q) || q <= 0) return

        const currentBox = boxes[boxIndex]
        const currentCount = currentBox.items.reduce((acc, it) => acc + (it.quantity_sent || 0), 0)

        if (currentCount + q > currentBox.capacity) {
            const excess = currentCount + q - currentBox.capacity
            if (!confirm(`Esta caja estándar es de ${currentBox.capacity} unidades.\nCon estas ${q} piezas sumará ${currentCount + q} piezas (excede por ${excess}).\n\n¿Deseas agregarla de todas formas?`)) {
                return
            }
        }

        const newBoxes = [...boxes]
        const targetBox = newBoxes[boxIndex]

        // Check if item already exists in this box
        const existingIdx = targetBox.items.findIndex((it) => it.inventory_product_id === product.id)
        if (existingIdx >= 0) {
            targetBox.items[existingIdx].quantity_sent += q
        } else {
            targetBox.items.push({
                inventory_product_id: product.id,
                sku: product.sku,
                product_name: product.name,
                brand: product.brand,
                quantity_sent: q,
            })
        }

        setBoxes(newBoxes)
        setSelectedProduct(null)
        setProductSearch('')
        setItemQty(30)
    }

    // Remove item from box
    const removeItemFromBox = (boxIndex, itemIndex) => {
        const newBoxes = [...boxes]
        newBoxes[boxIndex].items.splice(itemIndex, 1)
        setBoxes(newBoxes)
    }

    // Update item quantity
    const updateItemQty = (boxIndex, itemIndex, newQty) => {
        const q = parseInt(newQty, 10)
        if (isNaN(q) || q <= 0) return
        const newBoxes = [...boxes]
        newBoxes[boxIndex].items[itemIndex].quantity_sent = q
        setBoxes(newBoxes)
    }

    // Totals
    const totalBoxesCount = boxes.length
    const totalUnitsCount = boxes.reduce((acc, b) => acc + b.items.reduce((s, it) => s + (it.quantity_sent || 0), 0), 0)

    const handleSubmit = (e) => {
        e.preventDefault()

        if (totalUnitsCount <= 0) {
            alert('Debes agregar al menos un producto a las cajas de envío.')
            return
        }

        setSubmitting(true)
        setErrors({})

        router.post(
            '/meli/full/envios',
            {
                shipment_code: shipmentCode,
                meli_warehouse_code: warehouseCode,
                meli_warehouse_name: warehouseName,
                meli_shipment_id: meliShipmentId,
                envia_carrier: carrier,
                envia_tracking_number: trackingNumber,
                envia_cost: enviaCost ? parseFloat(enviaCost) : 0,
                notes: notes,
                boxes: boxes.map((b) => ({
                    box_number: b.box_number,
                    capacity: b.capacity,
                    dimensions: b.dimensions,
                    weight_kg: b.weight_kg ? parseFloat(b.weight_kg) : 0,
                    items: b.items.map((it) => ({
                        inventory_product_id: it.inventory_product_id,
                        sku: it.sku,
                        product_name: it.product_name,
                        quantity_sent: it.quantity_sent,
                    })),
                })),
            },
            {
                onError: (errs) => {
                    setErrors(errs)
                    setSubmitting(false)
                },
            }
        )
    }

    return (
        <AppShell>
            <Head title="Crear Envío FULL - Cajas de 30" />

            <div className="space-y-6 p-4 sm:p-6 lg:p-8">
                {/* BREADCRUMB & HEADER */}
                <div className="flex flex-col gap-2">
                    <div className="flex items-center gap-2 text-xs text-slate-500">
                        <Link href="/meli/full/envios" className="hover:underline">
                            Envíos FULL
                        </Link>
                        <span>/</span>
                        <span className="font-bold text-slate-800 dark:text-slate-200">
                            Nuevo Envío (Cajas de 30)
                        </span>
                    </div>

                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h1 className="text-2xl font-black text-slate-900 dark:text-white">
                                Armar Envío a Mercado Libre FULL
                            </h1>
                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                Organiza cajas estándar de 30 unidades, asigna bodega CEDIS destino y vincula tu guía de ENVIA.
                            </p>
                        </div>

                        <div className="flex items-center gap-3">
                            <Link
                                href="/meli/full/envios"
                                className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                            >
                                Cancelar
                            </Link>
                            <button
                                type="button"
                                onClick={handleSubmit}
                                disabled={submitting}
                                className="rounded-xl bg-indigo-600 px-5 py-2 text-xs font-bold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
                            >
                                {submitting ? 'Guardando Envío...' : '💾 Guardar Envío'}
                            </button>
                        </div>
                    </div>
                </div>

                {/* FORM ERROR ALERT */}
                {Object.keys(errors).length > 0 && (
                    <div className="rounded-2xl border border-rose-300 bg-rose-50 p-4 text-xs font-semibold text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300">
                        Por favor revisa los errores en el formulario antes de guardar.
                    </div>
                )}

                {/* TOP CONFIGURATION: WAREHOUSE & ENVIA */}
                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <h2 className="text-sm font-black uppercase tracking-wider text-slate-800 dark:text-slate-200 mb-4 flex items-center gap-2">
                        <span>🏢</span> 1. Bodega MeLi Destino y Guía ENVIA
                    </h2>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {/* Shipment Code */}
                        <div>
                            <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">
                                Folio de Envío
                            </label>
                            <input
                                type="text"
                                value={shipmentCode}
                                onChange={(e) => setShipmentCode(e.target.value)}
                                className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-xs font-bold text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                required
                            />
                        </div>

                        {/* MeLi Warehouse */}
                        <div>
                            <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">
                                Bodega MeLi Destino (CEDIS) *
                            </label>
                            <select
                                value={warehouseCode}
                                onChange={(e) => handleWarehouseChange(e.target.value)}
                                className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                            >
                                {Object.entries(warehouses).map(([code, name]) => (
                                    <option key={code} value={code}>
                                        {code} - {name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {/* ENVIA Carrier */}
                        <div>
                            <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">
                                Paquetería ENVIA *
                            </label>
                            <select
                                value={carrier}
                                onChange={(e) => setCarrier(e.target.value)}
                                className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                            >
                                {carriers.map((c) => (
                                    <option key={c} value={c}>
                                        {c}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {/* ENVIA Tracking Number */}
                        <div>
                            <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">
                                Número de Guía ENVIA
                            </label>
                            <input
                                type="text"
                                placeholder="Ej: 1234567890"
                                value={trackingNumber}
                                onChange={(e) => setTrackingNumber(e.target.value)}
                                className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 font-mono text-xs font-bold text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                            />
                        </div>

                        {/* MeLi Shipment ID / Appointment */}
                        <div>
                            <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">
                                ID de Cita / Envío MeLi (Opcional)
                            </label>
                            <input
                                type="text"
                                placeholder="Ej: 41258902"
                                value={meliShipmentId}
                                onChange={(e) => setMeliShipmentId(e.target.value)}
                                className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                            />
                        </div>

                        {/* ENVIA Cost */}
                        <div>
                            <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">
                                Costo Guía ENVIA ($ MXN)
                            </label>
                            <input
                                type="number"
                                step="0.01"
                                placeholder="0.00"
                                value={enviaCost}
                                onChange={(e) => setEnviaCost(e.target.value)}
                                className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-mono text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                            />
                        </div>

                        {/* Notes */}
                        <div className="sm:col-span-2">
                            <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">
                                Notas u Observaciones
                            </label>
                            <input
                                type="text"
                                placeholder="Instrucciones para bodega, horario de recolección ENVIA..."
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                            />
                        </div>
                    </div>
                </div>

                {/* 2. BOXES BUILDER (STANDARDIZED 30-PIECE BOXES) */}
                <div className="space-y-4">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 className="text-base font-black text-slate-900 dark:text-white flex items-center gap-2">
                                <span>📦</span> 2. Armador de Cajas de 30 Piezas ({boxes.length} {boxes.length === 1 ? 'caja' : 'cajas'})
                            </h2>
                            <p className="text-xs text-slate-500">
                                Mercado Libre FULL estandariza el empaque por cajas máster (normalmente 30 piezas por bulto).
                            </p>
                        </div>

                        <button
                            type="button"
                            onClick={addBox}
                            className="inline-flex items-center gap-1.5 rounded-xl bg-slate-800 px-3.5 py-2 text-xs font-bold text-white hover:bg-slate-700 dark:bg-neutral-800 dark:hover:bg-neutral-700"
                        >
                            <span>➕</span> Agregar Otra Caja de 30
                        </button>
                    </div>

                    {/* BOX TABS SELECTOR */}
                    <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 pb-2 dark:border-neutral-800">
                        {boxes.map((box, idx) => {
                            const count = box.items.reduce((acc, it) => acc + (it.quantity_sent || 0), 0)
                            const isFilled = count === box.capacity
                            const isOver = count > box.capacity
                            const isActive = activeBoxIndex === idx

                            return (
                                <button
                                    key={box.id}
                                    type="button"
                                    onClick={() => setActiveBoxIndex(idx)}
                                    className={`rounded-xl px-3.5 py-2 text-xs font-bold transition flex items-center gap-2 ${
                                        isActive
                                            ? 'bg-indigo-600 text-white shadow-sm ring-2 ring-indigo-400'
                                            : 'bg-white text-slate-700 hover:bg-slate-100 border border-slate-200 dark:bg-neutral-900 dark:border-neutral-800 dark:text-slate-300'
                                    }`}
                                >
                                    <span>Caja #{box.box_number}</span>
                                    <span
                                        className={`rounded-full px-2 py-0.5 text-[10px] font-black ${
                                            isFilled
                                                ? 'bg-emerald-500 text-white'
                                                : isOver
                                                ? 'bg-rose-500 text-white'
                                                : isActive
                                                ? 'bg-indigo-700 text-white'
                                                : 'bg-slate-200 text-slate-800 dark:bg-neutral-800 dark:text-slate-200'
                                        }`}
                                    >
                                        {count}/{box.capacity} uds
                                    </span>
                                </button>
                            )
                        })}
                    </div>

                    {/* ACTIVE BOX DETAILS */}
                    {boxes[activeBoxIndex] && (() => {
                        const activeBox = boxes[activeBoxIndex]
                        const currentCount = activeBox.items.reduce((acc, it) => acc + (it.quantity_sent || 0), 0)
                        const remaining = activeBox.capacity - currentCount

                        return (
                            <div className="rounded-2xl border-2 border-indigo-200 bg-white p-5 shadow-sm dark:border-indigo-900/50 dark:bg-neutral-900">
                                {/* BOX HEADER */}
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-slate-100 pb-4 dark:border-neutral-800">
                                    <div className="flex items-center gap-3">
                                        <div className="rounded-xl bg-indigo-50 p-2.5 text-xl font-black text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300">
                                            📦
                                        </div>
                                        <div>
                                            <h3 className="text-base font-black text-slate-900 dark:text-white">
                                                Caja #{activeBox.box_number} (Capacidad: {activeBox.capacity} unidades)
                                            </h3>
                                            <div className="flex items-center gap-2 mt-0.5 text-xs">
                                                <span className="font-semibold text-slate-500">
                                                    Dimensiones: {activeBox.dimensions || '40x30x30 cm'}
                                                </span>
                                                {activeBox.weight_kg > 0 && (
                                                    <span className="font-semibold text-slate-500">
                                                        · Peso: {activeBox.weight_kg} kg
                                                    </span>
                                                )}
                                            </div>
                                        </div>
                                    </div>

                                    {/* STATUS BADGE & PROGRESS */}
                                    <div className="flex items-center gap-2">
                                        {currentCount === activeBox.capacity ? (
                                            <span className="rounded-xl bg-emerald-100 px-3 py-1.5 text-xs font-black text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300">
                                                ✨ Caja Llena (30 de 30)
                                            </span>
                                        ) : currentCount < activeBox.capacity ? (
                                            <span className="rounded-xl bg-amber-100 px-3 py-1.5 text-xs font-black text-amber-800 dark:bg-amber-950/80 dark:text-amber-300">
                                                ⏳ {currentCount} / {activeBox.capacity} (Faltan {remaining} uds)
                                            </span>
                                        ) : (
                                            <span className="rounded-xl bg-rose-100 px-3 py-1.5 text-xs font-black text-rose-800 dark:bg-rose-950/80 dark:text-rose-300">
                                                ⚠️ Excedida: {currentCount} / {activeBox.capacity} uds
                                            </span>
                                        )}

                                        <button
                                            type="button"
                                            onClick={() => duplicateBox(activeBoxIndex)}
                                            className="rounded-xl border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-300"
                                            title="Duplicar esta caja idéntica"
                                        >
                                            📋 Duplicar Caja
                                        </button>

                                        {boxes.length > 1 && (
                                            <button
                                                type="button"
                                                onClick={() => removeBox(activeBoxIndex)}
                                                className="rounded-xl border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-xs font-bold text-rose-700 hover:bg-rose-100 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300"
                                                title="Eliminar esta caja"
                                            >
                                                🗑️ Eliminar
                                            </button>
                                        )}
                                    </div>
                                </div>

                                {/* ADD PRODUCTS TO THIS BOX */}
                                <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50/70 p-4 dark:border-neutral-800 dark:bg-neutral-950">
                                    <span className="text-xs font-black uppercase tracking-wider text-slate-700 dark:text-slate-300 block mb-2">
                                        Agregar Productos del Almacén a esta Caja:
                                    </span>

                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-12 items-end">
                                        {/* Product Search & Dropdown */}
                                        <div className="sm:col-span-7">
                                            <label className="block text-[11px] font-bold text-slate-500 mb-1">
                                                Buscar Producto por SKU o Nombre (Stock en Almacén):
                                            </label>
                                            <input
                                                type="text"
                                                placeholder="Escribe SKU o nombre..."
                                                value={productSearch}
                                                onChange={(e) => setProductSearch(e.target.value)}
                                                className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-medium dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                                            />

                                            {/* Quick Dropdown Options */}
                                            {filteredProducts.length > 0 && (
                                                <div className="mt-1 max-h-48 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-lg dark:border-neutral-800 dark:bg-neutral-900">
                                                    {filteredProducts.map((p) => (
                                                        <div
                                                            key={p.id}
                                                            onClick={() => {
                                                                setSelectedProduct(p)
                                                                setProductSearch(p.sku)
                                                                // If box needs some units to reach 30, pre-fill with that remaining amount
                                                                if (remaining > 0) {
                                                                    setItemQty(remaining)
                                                                }
                                                            }}
                                                            className={`cursor-pointer rounded-lg px-2.5 py-1.5 text-xs transition flex items-center justify-between ${
                                                                selectedProduct?.id === p.id
                                                                    ? 'bg-indigo-50 font-bold text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300'
                                                                    : 'hover:bg-slate-50 text-slate-800 dark:text-slate-200 dark:hover:bg-neutral-800'
                                                            }`}
                                                        >
                                                            <div>
                                                                <span className="font-mono font-bold mr-2 text-indigo-600 dark:text-indigo-400">
                                                                    {p.sku}
                                                                </span>
                                                                <span>{p.name}</span>
                                                            </div>
                                                            <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-600 dark:bg-neutral-800 dark:text-slate-300">
                                                                Stock: {p.available_stock ?? 0}
                                                            </span>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                        </div>

                                        {/* Quantity */}
                                        <div className="sm:col-span-3">
                                            <div className="flex items-center justify-between mb-1">
                                                <label className="text-[11px] font-bold text-slate-500">
                                                    Piezas a empacar:
                                                </label>
                                                {remaining > 0 && (
                                                    <button
                                                        type="button"
                                                        onClick={() => setItemQty(remaining)}
                                                        className="text-[10px] font-bold text-indigo-600 hover:underline dark:text-indigo-400"
                                                    >
                                                        Llenar caja (+{remaining})
                                                    </button>
                                                )}
                                            </div>
                                            <input
                                                type="number"
                                                min="1"
                                                max="1000"
                                                value={itemQty}
                                                onChange={(e) => setItemQty(e.target.value)}
                                                className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 font-mono text-xs font-bold text-slate-900 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                                            />
                                        </div>

                                        {/* Add Button */}
                                        <div className="sm:col-span-2">
                                            <button
                                                type="button"
                                                disabled={!selectedProduct}
                                                onClick={() => {
                                                    if (selectedProduct) {
                                                        addItemToBox(activeBoxIndex, selectedProduct, itemQty)
                                                    }
                                                }}
                                                className="w-full rounded-xl bg-indigo-600 py-2 text-xs font-bold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-40"
                                            >
                                                ➕ Empacar
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                {/* TABLE OF PACKED ITEMS IN THIS BOX */}
                                <div className="mt-4 overflow-x-auto rounded-xl border border-slate-200 dark:border-neutral-800">
                                    <table className="w-full text-left text-xs">
                                        <thead className="bg-slate-50 border-b border-slate-200 text-[11px] font-bold uppercase text-slate-500 dark:border-neutral-800 dark:bg-neutral-950">
                                            <tr>
                                                <th className="p-3">SKU</th>
                                                <th className="p-3">Producto</th>
                                                <th className="p-3 text-center">Cantidad en Caja</th>
                                                <th className="p-3 text-center">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100 font-medium dark:divide-neutral-800">
                                            {activeBox.items.length === 0 ? (
                                                <tr>
                                                    <td colSpan={4} className="py-8 text-center text-slate-400">
                                                        Esta caja está vacía. Selecciona un producto arriba para comenzar a empacar las 30 unidades.
                                                    </td>
                                                </tr>
                                            ) : (
                                                activeBox.items.map((item, itemIdx) => (
                                                    <tr key={itemIdx} className="hover:bg-slate-50/50 dark:hover:bg-neutral-950/30">
                                                        <td className="p-3 font-mono font-bold text-indigo-600 dark:text-indigo-400">
                                                            {item.sku}
                                                        </td>
                                                        <td className="p-3 font-semibold text-slate-800 dark:text-slate-200">
                                                            {item.product_name}
                                                            {item.brand && (
                                                                <span className="text-[10px] text-slate-400 block">
                                                                    {item.brand}
                                                                </span>
                                                            )}
                                                        </td>
                                                        <td className="p-3 text-center">
                                                            <input
                                                                type="number"
                                                                min="1"
                                                                value={item.quantity_sent}
                                                                onChange={(e) => updateItemQty(activeBoxIndex, itemIdx, e.target.value)}
                                                                className="w-20 rounded-lg border border-slate-300 bg-white px-2 py-1 text-center font-mono text-xs font-bold text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                                            />
                                                        </td>
                                                        <td className="p-3 text-center">
                                                            <button
                                                                type="button"
                                                                onClick={() => removeItemFromBox(activeBoxIndex, itemIdx)}
                                                                className="text-xs font-bold text-rose-600 hover:underline dark:text-rose-400"
                                                            >
                                                                Quitar
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ))
                                            )}
                                        </tbody>
                                        {activeBox.items.length > 0 && (
                                            <tfoot className="border-t border-slate-200 bg-slate-50/80 font-bold dark:border-neutral-800 dark:bg-neutral-950">
                                                <tr>
                                                    <td colSpan={2} className="p-3 text-right">
                                                        Total en Caja #{activeBox.box_number}:
                                                    </td>
                                                    <td className="p-3 text-center font-mono text-sm text-indigo-700 dark:text-indigo-300">
                                                        {currentCount} / {activeBox.capacity} uds
                                                    </td>
                                                    <td></td>
                                                </tr>
                                            </tfoot>
                                        )}
                                    </table>
                                </div>
                            </div>
                        )
                    })()}
                </div>

                {/* BOTTOM SUMMARY & DISPATCH NOTICE */}
                <div className="rounded-2xl border border-indigo-200 bg-indigo-50/60 p-5 dark:border-indigo-900/50 dark:bg-indigo-950/20">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <span className="text-xs font-black uppercase tracking-wider text-indigo-700 dark:text-indigo-300">
                                Resumen del Envío FULL
                            </span>
                            <div className="mt-1 flex items-baseline gap-4 font-mono">
                                <div>
                                    <span className="text-2xl font-black text-slate-900 dark:text-white">
                                        {totalBoxesCount}
                                    </span>{' '}
                                    <span className="text-xs text-slate-500 font-sans font-bold">
                                        {totalBoxesCount === 1 ? 'caja' : 'cajas'} (de 30 uds)
                                    </span>
                                </div>
                                <span className="text-slate-300">|</span>
                                <div>
                                    <span className="text-2xl font-black text-indigo-600 dark:text-indigo-400">
                                        {totalUnitsCount}
                                    </span>{' '}
                                    <span className="text-xs text-slate-500 font-sans font-bold">
                                        piezas totales
                                    </span>
                                </div>
                            </div>
                            <p className="mt-1 text-[11px] text-slate-600 dark:text-slate-400">
                                ℹ️ Al confirmar el despacho, el sistema descontará automáticamente las piezas del inventario físico local mediante movimientos <strong>TRANSFER_OUT</strong>.
                            </p>
                        </div>

                        <div className="flex items-center gap-3">
                            <Link
                                href="/meli/full/envios"
                                className="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                            >
                                Cancelar
                            </Link>
                            <button
                                type="button"
                                onClick={handleSubmit}
                                disabled={submitting || totalUnitsCount <= 0}
                                className="rounded-xl bg-indigo-600 px-6 py-2.5 text-xs font-bold text-white shadow-md hover:bg-indigo-700 disabled:opacity-40"
                            >
                                {submitting ? 'Guardando Envío...' : '💾 Guardar y Finalizar Envío'}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </AppShell>
    )
}
