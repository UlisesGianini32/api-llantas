import { useState, useMemo } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

export default function MeliFullShipmentsEdit({
    shipment = {},
    warehouses = {},
    carriers = [],
    products = [],
    recommendations = [],
}) {
    // Form Header State
    const [shipmentCode, setShipmentCode] = useState(shipment.shipment_code || '')
    const [warehouseCode, setWarehouseCode] = useState(shipment.meli_warehouse_code || 'MXCD01')
    const [warehouseName, setWarehouseName] = useState(shipment.meli_warehouse_name || warehouses['MXCD01'] || '')
    const [meliShipmentId, setMeliShipmentId] = useState(shipment.meli_shipment_id || '')
    const [carrier, setCarrier] = useState(shipment.envia_carrier || 'Paquetexpress')
    const [trackingNumber, setTrackingNumber] = useState(shipment.envia_tracking_number || '')
    const [enviaCost, setEnviaCost] = useState(shipment.envia_cost ? shipment.envia_cost.toString() : '')
    const [notes, setNotes] = useState(shipment.notes || '')

    // Boxes / Bultos State
    const [boxes, setBoxes] = useState(
        (shipment.boxes && shipment.boxes.length > 0)
            ? shipment.boxes.map((b, idx) => ({
                  id: b.id || idx + 1,
                  box_number: b.box_number || idx + 1,
                  bulto_number: b.bulto_number || 1,
                  boxes_in_bulto: b.boxes_in_bulto || 1,
                  capacity_kg: parseFloat(b.capacity_kg) || (b.boxes_in_bulto || 1) * 30.00,
                  dimensions: b.dimensions || '40x30x30',
                  weight_kg: parseFloat(b.weight_kg) || 0,
                  items: (b.items || []).map((it) => ({
                      inventory_product_id: it.inventory_product_id,
                      sku: it.sku,
                      product_name: it.product_name,
                      brand: it.inventoryProduct?.brand || '',
                      quantity_sent: it.quantity_sent,
                      unit_weight_kg: parseFloat(it.unit_weight_kg) || 1.0,
                      total_weight_kg: parseFloat(it.total_weight_kg) || it.quantity_sent * (parseFloat(it.unit_weight_kg) || 1.0),
                  })),
              }))
            : [
                  {
                      id: 1,
                      box_number: 1,
                      bulto_number: 1,
                      boxes_in_bulto: 1,
                      capacity_kg: 30.00,
                      dimensions: '40x30x30',
                      weight_kg: 0,
                      items: [],
                  },
              ]
    )

    // Product search query & active box
    const [productSearch, setProductSearch] = useState('')
    const [selectedProduct, setSelectedProduct] = useState(null)
    const [itemQty, setItemQty] = useState(10)
    const [itemUnitWeight, setItemUnitWeight] = useState(1.0)
    const [activeBoxIndex, setActiveBoxIndex] = useState(0)

    // Recommendations filter & search
    const [recFilter, setRecFilter] = useState('ALL')
    const [recSearch, setRecSearch] = useState('')
    const [showRecs, setShowRecs] = useState(false)

    const [submitting, setSubmitting] = useState(false)
    const [errors, setErrors] = useState({})

    const handleWarehouseChange = (code) => {
        setWarehouseCode(code)
        setWarehouseName(warehouses[code] || '')
    }

    // Filtered products for manual picker
    const filteredProducts = useMemo(() => {
        if (!productSearch.trim()) return products.slice(0, 10)
        const q = productSearch.toLowerCase()
        return products
            .filter(
                (p) =>
                    (p.sku && p.sku.toLowerCase().includes(q)) ||
                    (p.name && p.name.toLowerCase().includes(q)) ||
                    (p.barcode && p.barcode.toLowerCase().includes(q))
            )
            .slice(0, 20)
    }, [products, productSearch])

    // Filtered recommendations
    const filteredRecommendations = useMemo(() => {
        return recommendations.filter((r) => {
            if (recFilter === 'CRITICAL' && r.priority !== 'CRITICAL') return false
            if (recFilter === 'HIGH' && r.priority !== 'CRITICAL' && r.priority !== 'HIGH') return false
            if (recFilter === 'IN_STOCK' && (r.available_stock || 0) <= 0) return false

            if (recSearch.trim()) {
                const q = recSearch.toLowerCase()
                return (
                    (r.sku && r.sku.toLowerCase().includes(q)) ||
                    (r.name && r.name.toLowerCase().includes(q)) ||
                    (r.brand && r.brand.toLowerCase().includes(q))
                )
            }
            return true
        })
    }, [recommendations, recFilter, recSearch])

    // Helper: calculate total weight of a box
    const calculateBoxWeight = (box) => {
        return (box.items || []).reduce((acc, it) => {
            const w = parseFloat(it.unit_weight_kg) || 0
            const q = parseInt(it.quantity_sent, 10) || 0
            return acc + w * q
        }, 0)
    }

    // Helper: calculate total units in a box
    const calculateBoxUnits = (box) => {
        return (box.items || []).reduce((acc, it) => acc + (parseInt(it.quantity_sent, 10) || 0), 0)
    }

    // Add another standard 30-kg box / bulto
    const addBox = () => {
        const nextNum = boxes.length + 1
        const maxBulto = boxes.reduce((m, b) => Math.max(m, b.bulto_number || 1), 1)

        setBoxes([
            ...boxes,
            {
                id: Date.now(),
                box_number: nextNum,
                bulto_number: maxBulto + 1,
                boxes_in_bulto: 1,
                capacity_kg: 30.00,
                dimensions: '40x30x30',
                weight_kg: 0,
                items: [],
            },
        ])
        setActiveBoxIndex(boxes.length)
    }

    // Update box configuration
    const updateBoxConfig = (boxIndex, field, value) => {
        const newBoxes = [...boxes]
        const box = { ...newBoxes[boxIndex] }

        if (field === 'boxes_in_bulto') {
            const count = Math.max(1, parseInt(value, 10) || 1)
            box.boxes_in_bulto = count
            box.capacity_kg = count * 30.00
        } else if (field === 'bulto_number') {
            box.bulto_number = Math.max(1, parseInt(value, 10) || 1)
        } else if (field === 'capacity_kg') {
            box.capacity_kg = Math.max(1, parseFloat(value) || 30.00)
        } else if (field === 'dimensions') {
            box.dimensions = value
        }

        newBoxes[boxIndex] = box
        setBoxes(newBoxes)
    }

    // Duplicate box
    const duplicateBox = (boxIndex) => {
        const sourceBox = boxes[boxIndex]
        const nextNum = boxes.length + 1
        const duplicated = {
            id: Date.now(),
            box_number: nextNum,
            bulto_number: (sourceBox.bulto_number || 1) + 1,
            boxes_in_bulto: sourceBox.boxes_in_bulto || 1,
            capacity_kg: sourceBox.capacity_kg || 30.00,
            dimensions: sourceBox.dimensions,
            weight_kg: calculateBoxWeight(sourceBox),
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
        const updated = boxes
            .filter((_, idx) => idx !== boxIndex)
            .map((b, idx) => ({ ...b, box_number: idx + 1 }))
        setBoxes(updated)
        if (activeBoxIndex >= updated.length) {
            setActiveBoxIndex(updated.length - 1)
        }
    }

    // Add item to active box
    const addItemToBox = (boxIndex, product, qty, customWeight = null) => {
        const q = parseInt(qty, 10)
        if (isNaN(q) || q <= 0) return

        const unitW =
            customWeight !== null && !isNaN(parseFloat(customWeight))
                ? parseFloat(customWeight)
                : parseFloat(product.weight_kg) || 1.0

        const newBoxes = [...boxes]
        const targetBox = newBoxes[boxIndex]
        const currentWeight = calculateBoxWeight(targetBox)
        const addedWeight = q * unitW
        const totalProjectedWeight = currentWeight + addedWeight

        if (totalProjectedWeight > targetBox.capacity_kg) {
            const excess = (totalProjectedWeight - targetBox.capacity_kg).toFixed(2)
            const proceed = confirm(
                `⚠️ ALERTA DE PESO ME LI FULL:\n\n` +
                    `La capacidad máxima permitida para esta caja/bulto es de ${targetBox.capacity_kg.toFixed(2)} kg (${targetBox.boxes_in_bulto} caja(s) de 30 kg).\n` +
                    `Con este producto el peso alcanzará ${totalProjectedWeight.toFixed(2)} kg (excede por ${excess} kg).\n\n` +
                    `¿Deseas agregarlo de todas formas? Puedes aumentar las "Cajas de 30 kg en este bulto" o ajustar la cantidad.`
            )
            if (!proceed) return
        }

        const existingIdx = targetBox.items.findIndex(
            (it) => it.inventory_product_id === product.id || it.sku === product.sku
        )

        if (existingIdx >= 0) {
            targetBox.items[existingIdx].quantity_sent += q
            targetBox.items[existingIdx].unit_weight_kg = unitW
            targetBox.items[existingIdx].total_weight_kg =
                targetBox.items[existingIdx].quantity_sent * unitW
        } else {
            targetBox.items.push({
                inventory_product_id: product.id,
                sku: product.sku,
                product_name: product.name,
                brand: product.brand,
                quantity_sent: q,
                unit_weight_kg: unitW,
                total_weight_kg: q * unitW,
            })
        }

        targetBox.weight_kg = calculateBoxWeight(targetBox)
        setBoxes(newBoxes)
        setSelectedProduct(null)
        setProductSearch('')
        setItemQty(10)
    }

    // Add directly from recommendations to active box
    const addRecommendationToActiveBox = (rec) => {
        const prod = products.find((p) => p.sku === rec.sku || p.id === rec.product_id) || {
            id: rec.product_id,
            sku: rec.sku,
            name: rec.name,
            brand: rec.brand,
            weight_kg: rec.weight_kg,
            available_stock: rec.available_stock,
        }

        const qtyToAdd = rec.suggested_quantity || 10
        addItemToBox(activeBoxIndex, prod, qtyToAdd, rec.weight_kg)
    }

    // Remove item from box
    const removeItemFromBox = (boxIndex, itemIndex) => {
        const newBoxes = [...boxes]
        newBoxes[boxIndex].items.splice(itemIndex, 1)
        newBoxes[boxIndex].weight_kg = calculateBoxWeight(newBoxes[boxIndex])
        setBoxes(newBoxes)
    }

    // Update item quantity
    const updateItemQty = (boxIndex, itemIndex, newQty) => {
        const q = parseInt(newQty, 10)
        if (isNaN(q) || q <= 0) return
        const newBoxes = [...boxes]
        const it = newBoxes[boxIndex].items[itemIndex]
        it.quantity_sent = q
        it.total_weight_kg = q * (parseFloat(it.unit_weight_kg) || 0)
        newBoxes[boxIndex].weight_kg = calculateBoxWeight(newBoxes[boxIndex])
        setBoxes(newBoxes)
    }

    // Update item unit weight
    const updateItemUnitWeight = (boxIndex, itemIndex, newWeight) => {
        const w = parseFloat(newWeight)
        if (isNaN(w) || w < 0) return
        const newBoxes = [...boxes]
        const it = newBoxes[boxIndex].items[itemIndex]
        it.unit_weight_kg = w
        it.total_weight_kg = it.quantity_sent * w
        newBoxes[boxIndex].weight_kg = calculateBoxWeight(newBoxes[boxIndex])
        setBoxes(newBoxes)
    }

    // Global Totals
    const totalBoxesSum = boxes.reduce((acc, b) => acc + (b.boxes_in_bulto || 1), 0)
    const uniqueBultosCount = new Set(boxes.map((b) => b.bulto_number || 1)).size
    const totalUnitsCount = boxes.reduce((acc, b) => acc + calculateBoxUnits(b), 0)
    const totalWeightSum = boxes.reduce((acc, b) => acc + calculateBoxWeight(b), 0)

    const handleSubmit = (e) => {
        e.preventDefault()

        if (totalUnitsCount <= 0) {
            alert('Debes agregar al menos un producto a las cajas de envío.')
            return
        }

        setSubmitting(true)
        setErrors({})

        router.put(
            `/meli/full/envios/${shipment.id}`,
            {
                meli_warehouse_code: warehouseCode,
                meli_warehouse_name: warehouseName,
                meli_shipment_id: meliShipmentId,
                envia_carrier: carrier,
                envia_tracking_number: trackingNumber,
                envia_cost: enviaCost ? parseFloat(enviaCost) : 0,
                notes: notes,
                boxes: boxes.map((b) => ({
                    box_number: b.box_number,
                    bulto_number: b.bulto_number || 1,
                    boxes_in_bulto: b.boxes_in_bulto || 1,
                    capacity: 30,
                    capacity_kg: b.capacity_kg || (b.boxes_in_bulto || 1) * 30.00,
                    dimensions: b.dimensions || '40x30x30',
                    weight_kg: calculateBoxWeight(b),
                    items: b.items.map((it) => ({
                        inventory_product_id: it.inventory_product_id,
                        sku: it.sku,
                        product_name: it.product_name,
                        quantity_sent: it.quantity_sent,
                        unit_weight_kg: parseFloat(it.unit_weight_kg) || 1.0,
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
            <Head title={`Editar Envío ${shipment.shipment_code} - Cajas de 30 kg`} />

            <div className="space-y-6 p-4 sm:p-6 lg:p-8">
                {/* BREADCRUMB & HEADER */}
                <div className="flex flex-col gap-2">
                    <div className="flex items-center gap-2 text-xs text-slate-500">
                        <Link href="/meli/full/envios" className="hover:underline">
                            Envíos FULL
                        </Link>
                        <span>/</span>
                        <Link href={`/meli/full/envios/${shipment.id}`} className="hover:underline">
                            {shipment.shipment_code}
                        </Link>
                        <span>/</span>
                        <span className="font-bold text-slate-800 dark:text-slate-200">
                            Editar Envío
                        </span>
                    </div>

                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div className="flex items-center gap-2">
                                <span className="rounded-lg bg-amber-400 px-2 py-0.5 text-xs font-black text-slate-950">
                                    ⚡ ME LI FULL
                                </span>
                                <span className="rounded-lg bg-indigo-100 px-2 py-0.5 text-xs font-bold text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300">
                                    ⚖️ Regla: Máx 30 kg por caja
                                </span>
                            </div>
                            <h1 className="mt-1 text-2xl font-black text-slate-900 dark:text-white">
                                Editar Envío: {shipment.shipment_code}
                            </h1>
                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                Ajusta productos, pesos, cajas y bultos antes de registrar la salida física del almacén con ENVIA.
                            </p>
                        </div>

                        <div className="flex items-center gap-3">
                            <Link
                                href={`/meli/full/envios/${shipment.id}`}
                                className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                            >
                                Cancelar
                            </Link>
                            <button
                                type="button"
                                onClick={handleSubmit}
                                disabled={submitting || totalUnitsCount <= 0}
                                className="rounded-xl bg-indigo-600 px-5 py-2 text-xs font-bold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
                            >
                                {submitting ? 'Guardando Cambios...' : '💾 Actualizar Envío'}
                            </button>
                        </div>
                    </div>
                </div>

                {/* FORM ERROR ALERT */}
                {Object.keys(errors).length > 0 && (
                    <div className="rounded-2xl border border-rose-300 bg-rose-50 p-4 text-xs font-semibold text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300">
                        <strong className="block mb-1">Por favor corrige los siguientes errores:</strong>
                        <ul className="list-disc pl-5 space-y-0.5">
                            {Object.entries(errors).map(([f, msg]) => (
                                <li key={f}>{msg}</li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* 1. TOP CONFIGURATION: WAREHOUSE & ENVIA */}
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
                                disabled
                                className="w-full rounded-xl border border-slate-300 bg-slate-100 px-3 py-2 font-mono text-xs font-bold text-slate-500 cursor-not-allowed dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-400"
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
                                placeholder="Instrucciones para bodega..."
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                            />
                        </div>
                    </div>
                </div>

                {/* 2. RECOMENDACIONES DE REABASTECIMIENTO FULL */}
                <div className="rounded-2xl border-2 border-amber-300 bg-gradient-to-br from-amber-50/70 via-white to-amber-50/40 p-5 shadow-sm dark:border-amber-900/60 dark:from-neutral-900 dark:via-neutral-900 dark:to-amber-950/20">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div className="flex items-center gap-2">
                                <span className="text-xl">✨</span>
                                <h2 className="text-base font-black text-slate-900 dark:text-white">
                                    Recomendaciones Inteligentes para Enviar a FULL
                                </h2>
                                <span className="rounded-full bg-amber-400 px-2.5 py-0.5 text-[10px] font-black text-slate-900">
                                    {recommendations.length} sugerencias
                                </span>
                            </div>
                            <p className="text-xs text-slate-600 dark:text-slate-400 mt-0.5">
                                Productos recomendados por alta demanda o stock por agotarse en MeLi CEDIS.
                            </p>
                        </div>

                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => setShowRecs(!showRecs)}
                                className="rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                            >
                                {showRecs ? '▲ Ocultar' : '▼ Ver Recomendaciones'}
                            </button>
                        </div>
                    </div>

                    {showRecs && (
                        <div className="mt-4 space-y-3">
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex flex-wrap items-center gap-1.5">
                                    <button
                                        type="button"
                                        onClick={() => setRecFilter('ALL')}
                                        className={`rounded-lg px-2.5 py-1 text-xs font-bold transition ${
                                            recFilter === 'ALL'
                                                ? 'bg-slate-900 text-white'
                                                : 'bg-white text-slate-600 border border-slate-200'
                                        }`}
                                    >
                                        Todos ({recommendations.length})
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setRecFilter('CRITICAL')}
                                        className={`rounded-lg px-2.5 py-1 text-xs font-bold transition ${
                                            recFilter === 'CRITICAL'
                                                ? 'bg-rose-600 text-white'
                                                : 'bg-white text-rose-700 border border-rose-200'
                                        }`}
                                    >
                                        🚨 Urgentes
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setRecFilter('HIGH')}
                                        className={`rounded-lg px-2.5 py-1 text-xs font-bold transition ${
                                            recFilter === 'HIGH'
                                                ? 'bg-amber-600 text-white'
                                                : 'bg-white text-amber-700 border border-amber-200'
                                        }`}
                                    >
                                        🔥 Alta Rotación
                                    </button>
                                </div>

                                <div className="w-full sm:w-64">
                                    <input
                                        type="text"
                                        placeholder="Filtrar por SKU o producto..."
                                        value={recSearch}
                                        onChange={(e) => setRecSearch(e.target.value)}
                                        className="w-full rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs text-slate-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                    />
                                </div>
                            </div>

                            <div className="max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                                <table className="w-full text-left text-xs">
                                    <thead className="sticky top-0 bg-slate-100 border-b border-slate-200 text-[11px] font-bold uppercase text-slate-600 dark:border-neutral-800 dark:bg-neutral-950">
                                        <tr>
                                            <th className="p-2.5">Prioridad & SKU</th>
                                            <th className="p-2.5">Producto</th>
                                            <th className="p-2.5 text-center">Ventas 30d FULL</th>
                                            <th className="p-2.5 text-center">Stock MeLi CEDIS</th>
                                            <th className="p-2.5 text-center">Stock Local</th>
                                            <th className="p-2.5 text-center">Peso Unit.</th>
                                            <th className="p-2.5 text-center">Sugerido</th>
                                            <th className="p-2.5 text-center">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 font-medium dark:divide-neutral-800">
                                        {filteredRecommendations.length === 0 ? (
                                            <tr>
                                                <td colSpan={8} className="py-6 text-center text-slate-400 italic">
                                                    No hay productos recomendados con los filtros seleccionados.
                                                </td>
                                            </tr>
                                        ) : (
                                            filteredRecommendations.map((rec, rIdx) => {
                                                const isCritical = rec.priority === 'CRITICAL'
                                                const isHigh = rec.priority === 'HIGH'
                                                const canAdd = (rec.available_stock || 0) > 0

                                                return (
                                                    <tr key={rIdx} className="hover:bg-amber-50/40">
                                                        <td className="p-2.5">
                                                            <div className="flex items-center gap-1.5">
                                                                <span
                                                                    className={`rounded px-1.5 py-0.5 text-[9px] font-black uppercase ${
                                                                        isCritical
                                                                            ? 'bg-rose-100 text-rose-800'
                                                                            : isHigh
                                                                            ? 'bg-amber-100 text-amber-800'
                                                                            : 'bg-blue-100 text-blue-800'
                                                                    }`}
                                                                >
                                                                    {isCritical ? '🚨 Agotado' : isHigh ? '🔥 Alto' : 'Normal'}
                                                                </span>
                                                                <span className="font-mono font-bold text-slate-900 dark:text-white">
                                                                    {rec.sku}
                                                                </span>
                                                            </div>
                                                        </td>
                                                        <td className="p-2.5">
                                                            <span className="font-semibold text-slate-800 dark:text-slate-200">
                                                                {rec.name}
                                                            </span>
                                                        </td>
                                                        <td className="p-2.5 text-center font-mono font-bold text-indigo-600">
                                                            {rec.sales_full_30d || 0} uds
                                                        </td>
                                                        <td className="p-2.5 text-center font-mono font-bold">
                                                            {rec.full_stock_available <= 0 ? (
                                                                <span className="text-rose-600">0 (¡Agotado!)</span>
                                                            ) : (
                                                                <span className="text-amber-600">{rec.full_stock_available} uds</span>
                                                            )}
                                                        </td>
                                                        <td className="p-2.5 text-center font-mono font-bold">
                                                            <span className={canAdd ? 'text-emerald-600' : 'text-slate-400'}>
                                                                {rec.available_stock || 0} disponibles
                                                            </span>
                                                        </td>
                                                        <td className="p-2.5 text-center font-mono text-slate-500">
                                                            {(parseFloat(rec.weight_kg) || 1.0).toFixed(2)} kg
                                                        </td>
                                                        <td className="p-2.5 text-center font-mono font-extrabold text-indigo-700">
                                                            {rec.suggested_quantity} uds
                                                        </td>
                                                        <td className="p-2.5 text-center">
                                                            <button
                                                                type="button"
                                                                disabled={!canAdd}
                                                                onClick={() => addRecommendationToActiveBox(rec)}
                                                                className="rounded-lg bg-indigo-600 px-2.5 py-1 text-[11px] font-bold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-40"
                                                            >
                                                                ➕ Agregar
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
                    )}
                </div>

                {/* 3. BOXES & BULTOS BUILDER */}
                <div className="space-y-4">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div className="flex items-center gap-2">
                                <span className="text-xl">📦</span>
                                <h2 className="text-base font-black text-slate-900 dark:text-white">
                                    2. Armador de Cajas de 30 kg y Bultos ENVIA
                                </h2>
                                <span className="rounded-full bg-slate-200 px-2.5 py-0.5 text-xs font-bold text-slate-800 dark:bg-neutral-800 dark:text-slate-200">
                                    {totalBoxesSum} {totalBoxesSum === 1 ? 'caja' : 'cajas'} de 30 kg en {uniqueBultosCount} {uniqueBultosCount === 1 ? 'bulto' : 'bultos'}
                                </span>
                            </div>
                            <p className="text-xs text-slate-500">
                                Cada caja tiene límite de <strong>30.00 kg</strong>. Puedes mandar más de 1 caja de 30 kg en el mismo bulto de paquetería.
                            </p>
                        </div>

                        <button
                            type="button"
                            onClick={addBox}
                            className="inline-flex items-center gap-1.5 rounded-xl bg-slate-900 px-3.5 py-2 text-xs font-bold text-white hover:bg-slate-800 dark:bg-neutral-800 dark:hover:bg-neutral-700"
                        >
                            <span>➕</span> Agregar Nueva Caja / Bulto
                        </button>
                    </div>

                    {/* BOX TABS SELECTOR */}
                    <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 pb-2 dark:border-neutral-800">
                        {boxes.map((box, idx) => {
                            const currentW = calculateBoxWeight(box)
                            const maxW = box.capacity_kg || (box.boxes_in_bulto || 1) * 30.00
                            const isOverWeight = currentW > maxW
                            const isOptimal = currentW >= maxW * 0.9 && currentW <= maxW
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
                                    <span>
                                        {box.boxes_in_bulto > 1
                                            ? `Bulto #${box.bulto_number} (${box.boxes_in_bulto} cajas de 30kg)`
                                            : `Caja #${box.box_number} (Bulto #${box.bulto_number})`}
                                    </span>
                                    <span
                                        className={`rounded-full px-2 py-0.5 text-[10px] font-black ${
                                            isOverWeight
                                                ? 'bg-rose-500 text-white'
                                                : isOptimal
                                                ? 'bg-emerald-500 text-white'
                                                : isActive
                                                ? 'bg-indigo-700 text-white'
                                                : 'bg-slate-200 text-slate-800 dark:bg-neutral-800 dark:text-slate-200'
                                        }`}
                                    >
                                        {currentW.toFixed(2)} / {maxW.toFixed(0)} kg
                                    </span>
                                </button>
                            )
                        })}
                    </div>

                    {/* ACTIVE BOX DETAILS */}
                    {boxes[activeBoxIndex] && (() => {
                        const activeBox = boxes[activeBoxIndex]
                        const currentWeight = calculateBoxWeight(activeBox)
                        const currentUnits = calculateBoxUnits(activeBox)
                        const maxWeight = activeBox.capacity_kg || (activeBox.boxes_in_bulto || 1) * 30.00
                        const remainingWeight = Math.max(0, maxWeight - currentWeight)
                        const isOverWeight = currentWeight > maxWeight
                        const weightPercentage = Math.min(100, (currentWeight / maxWeight) * 100)

                        return (
                            <div className="rounded-2xl border-2 border-indigo-200 bg-white p-5 shadow-sm dark:border-indigo-900/50 dark:bg-neutral-900">
                                {/* ACTIVE BOX HEADER & CONFIG */}
                                <div className="flex flex-col gap-4 border-b border-slate-100 pb-4 dark:border-neutral-800">
                                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                        <div className="flex items-center gap-3">
                                            <div className="rounded-xl bg-indigo-50 p-2.5 text-xl font-black text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300">
                                                📦
                                            </div>
                                            <div>
                                                <h3 className="text-base font-black text-slate-900 dark:text-white flex items-center gap-2">
                                                    <span>
                                                        Caja #{activeBox.box_number} · Bulto ENVIA #{activeBox.bulto_number}
                                                    </span>
                                                    {activeBox.boxes_in_bulto > 1 && (
                                                        <span className="rounded-lg bg-purple-100 px-2 py-0.5 text-xs font-bold text-purple-800 dark:bg-purple-950 dark:text-purple-300">
                                                            Multi-Caja ({activeBox.boxes_in_bulto} cajas de 30 kg juntas)
                                                        </span>
                                                    )}
                                                </h3>
                                                <p className="text-xs text-slate-500">
                                                    Capacidad de peso: <strong>{maxWeight.toFixed(2)} kg</strong> ({activeBox.boxes_in_bulto} caja(s) × 30 kg máx c/u)
                                                </p>
                                            </div>
                                        </div>

                                        <div className="flex items-center gap-2">
                                            <button
                                                type="button"
                                                onClick={() => duplicateBox(activeBoxIndex)}
                                                className="rounded-xl border border-slate-200 bg-slate-50 px-2.5 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-300"
                                            >
                                                📋 Duplicar
                                            </button>

                                            {boxes.length > 1 && (
                                                <button
                                                    type="button"
                                                    onClick={() => removeBox(activeBoxIndex)}
                                                    className="rounded-xl border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-xs font-bold text-rose-700 hover:bg-rose-100 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300"
                                                >
                                                    🗑️ Eliminar
                                                </button>
                                            )}
                                        </div>
                                    </div>

                                    {/* BULTO & MULTI-BOX CONTROLS */}
                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-4 rounded-xl bg-slate-50 p-3 dark:bg-neutral-950/80 border border-slate-200 dark:border-neutral-800">
                                        <div>
                                            <label className="block text-[11px] font-bold text-slate-600 dark:text-slate-400 mb-1">
                                                Cajas de 30 kg en este Bulto:
                                            </label>
                                            <select
                                                value={activeBox.boxes_in_bulto || 1}
                                                onChange={(e) => updateBoxConfig(activeBoxIndex, 'boxes_in_bulto', e.target.value)}
                                                className="w-full rounded-xl border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-bold text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                            >
                                                <option value={1}>1 caja de 30 kg (Máx 30 kg)</option>
                                                <option value={2}>2 cajas de 30 kg (Máx 60 kg)</option>
                                                <option value={3}>3 cajas de 30 kg (Máx 90 kg)</option>
                                                <option value={4}>4 cajas de 30 kg (Máx 120 kg)</option>
                                            </select>
                                        </div>

                                        <div>
                                            <label className="block text-[11px] font-bold text-slate-600 dark:text-slate-400 mb-1">
                                                # de Bulto ENVIA:
                                            </label>
                                            <input
                                                type="number"
                                                min="1"
                                                value={activeBox.bulto_number || 1}
                                                onChange={(e) => updateBoxConfig(activeBoxIndex, 'bulto_number', e.target.value)}
                                                className="w-full rounded-xl border border-slate-300 bg-white px-2.5 py-1.5 font-mono text-xs font-bold text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                            />
                                        </div>

                                        <div>
                                            <label className="block text-[11px] font-bold text-slate-600 dark:text-slate-400 mb-1">
                                                Límite de Peso (kg):
                                            </label>
                                            <input
                                                type="number"
                                                step="0.5"
                                                value={activeBox.capacity_kg || (activeBox.boxes_in_bulto || 1) * 30.00}
                                                onChange={(e) => updateBoxConfig(activeBoxIndex, 'capacity_kg', e.target.value)}
                                                className="w-full rounded-xl border border-slate-300 bg-white px-2.5 py-1.5 font-mono text-xs font-bold text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                            />
                                        </div>

                                        <div>
                                            <label className="block text-[11px] font-bold text-slate-600 dark:text-slate-400 mb-1">
                                                Dimensiones (cm):
                                            </label>
                                            <input
                                                type="text"
                                                value={activeBox.dimensions || '40x30x30'}
                                                onChange={(e) => updateBoxConfig(activeBoxIndex, 'dimensions', e.target.value)}
                                                className="w-full rounded-xl border border-slate-300 bg-white px-2.5 py-1.5 text-xs text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                            />
                                        </div>
                                    </div>

                                    {/* REAL-TIME WEIGHT PROGRESS BAR */}
                                    <div className="space-y-1.5">
                                        <div className="flex items-center justify-between text-xs">
                                            <span className="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                                                <span>⚖️ Peso acumulado:</span>
                                                <strong className="text-sm font-mono text-slate-900 dark:text-white">
                                                    {currentWeight.toFixed(2)} kg
                                                </strong>
                                                <span className="text-slate-400">/ {maxWeight.toFixed(2)} kg máx</span>
                                            </span>

                                            <span className="font-bold">
                                                {isOverWeight ? (
                                                    <span className="text-rose-600 dark:text-rose-400">
                                                        ⚠️ Excede por {(currentWeight - maxWeight).toFixed(2)} kg
                                                    </span>
                                                ) : (
                                                    <span className="text-slate-500">
                                                        Disponible: {remainingWeight.toFixed(2)} kg
                                                    </span>
                                                )}
                                            </span>
                                        </div>

                                        <div className="h-3 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-neutral-800 border border-slate-200 dark:border-neutral-700">
                                            <div
                                                className={`h-full transition-all duration-300 ${
                                                    isOverWeight
                                                        ? 'bg-rose-500'
                                                        : weightPercentage >= 90
                                                        ? 'bg-emerald-500'
                                                        : 'bg-indigo-600'
                                                }`}
                                                style={{ width: `${Math.min(100, (currentWeight / maxWeight) * 100)}%` }}
                                            />
                                        </div>
                                    </div>
                                </div>

                                {/* ADD PRODUCTS TO THIS BOX */}
                                <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50/70 p-4 dark:border-neutral-800 dark:bg-neutral-950">
                                    <span className="text-xs font-black uppercase tracking-wider text-slate-700 dark:text-slate-300 block mb-2">
                                        Agregar Producto a esta Caja / Bulto:
                                    </span>

                                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-12 items-end">
                                        <div className="sm:col-span-5">
                                            <label className="block text-[11px] font-bold text-slate-500 mb-1">
                                                Buscar en Almacén Local:
                                            </label>
                                            <input
                                                type="text"
                                                placeholder="Escribe SKU o nombre..."
                                                value={productSearch}
                                                onChange={(e) => setProductSearch(e.target.value)}
                                                className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-medium dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                                            />

                                            {filteredProducts.length > 0 && (
                                                <div className="mt-1 max-h-48 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-lg dark:border-neutral-800 dark:bg-neutral-900">
                                                    {filteredProducts.map((p) => (
                                                        <div
                                                            key={p.id}
                                                            onClick={() => {
                                                                setSelectedProduct(p)
                                                                setProductSearch(p.sku)
                                                                setItemUnitWeight(parseFloat(p.weight_kg) || 1.0)
                                                            }}
                                                            className={`cursor-pointer rounded-lg px-2.5 py-1.5 text-xs transition flex items-center justify-between ${
                                                                selectedProduct?.id === p.id
                                                                    ? 'bg-indigo-50 font-bold text-indigo-700'
                                                                    : 'hover:bg-slate-50 text-slate-800'
                                                            }`}
                                                        >
                                                            <div>
                                                                <span className="font-mono font-bold mr-2 text-indigo-600">
                                                                    {p.sku}
                                                                </span>
                                                                <span>{p.name}</span>
                                                            </div>
                                                            <div className="flex items-center gap-2">
                                                                <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-600">
                                                                    {(parseFloat(p.weight_kg) || 1.0).toFixed(2)} kg
                                                                </span>
                                                                <span className="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700">
                                                                    Stock: {p.available_stock ?? 0}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                        </div>

                                        <div className="sm:col-span-3">
                                            <label className="block text-[11px] font-bold text-slate-500 mb-1">
                                                Piezas a empacar:
                                            </label>
                                            <input
                                                type="number"
                                                min="1"
                                                max="1000"
                                                value={itemQty}
                                                onChange={(e) => setItemQty(e.target.value)}
                                                className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 font-mono text-xs font-bold text-slate-900 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                                            />
                                        </div>

                                        <div className="sm:col-span-2">
                                            <label className="block text-[11px] font-bold text-slate-500 mb-1">
                                                Peso Unit. (kg):
                                            </label>
                                            <input
                                                type="number"
                                                step="0.05"
                                                min="0.01"
                                                value={itemUnitWeight}
                                                onChange={(e) => setItemUnitWeight(e.target.value)}
                                                className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 font-mono text-xs font-bold text-slate-900 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                                            />
                                        </div>

                                        <div className="sm:col-span-2">
                                            <button
                                                type="button"
                                                disabled={!selectedProduct}
                                                onClick={() => {
                                                    if (selectedProduct) {
                                                        addItemToBox(activeBoxIndex, selectedProduct, itemQty, itemUnitWeight)
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
                                                <th className="p-3 text-center">Piezas</th>
                                                <th className="p-3 text-center">Peso Unitario</th>
                                                <th className="p-3 text-center">Peso Subtotal</th>
                                                <th className="p-3 text-center">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100 font-medium dark:divide-neutral-800">
                                            {activeBox.items.length === 0 ? (
                                                <tr>
                                                    <td colSpan={6} className="py-8 text-center text-slate-400">
                                                        Esta caja está vacía. Selecciona un producto para empacar.
                                                    </td>
                                                </tr>
                                            ) : (
                                                activeBox.items.map((item, itemIdx) => {
                                                    const subtotalW = (item.quantity_sent || 0) * (parseFloat(item.unit_weight_kg) || 0)

                                                    return (
                                                        <tr key={itemIdx} className="hover:bg-slate-50/50">
                                                            <td className="p-3 font-mono font-bold text-indigo-600">
                                                                {item.sku}
                                                            </td>
                                                            <td className="p-3 font-semibold text-slate-800 dark:text-slate-200">
                                                                {item.product_name}
                                                            </td>
                                                            <td className="p-3 text-center">
                                                                <input
                                                                    type="number"
                                                                    min="1"
                                                                    value={item.quantity_sent}
                                                                    onChange={(e) => updateItemQty(activeBoxIndex, itemIdx, e.target.value)}
                                                                    className="w-20 rounded-lg border border-slate-300 bg-white px-2 py-1 text-center font-mono text-xs font-bold"
                                                                />
                                                            </td>
                                                            <td className="p-3 text-center">
                                                                <div className="flex items-center justify-center gap-1 font-mono">
                                                                    <input
                                                                        type="number"
                                                                        step="0.05"
                                                                        min="0.01"
                                                                        value={item.unit_weight_kg}
                                                                        onChange={(e) => updateItemUnitWeight(activeBoxIndex, itemIdx, e.target.value)}
                                                                        className="w-16 rounded-lg border border-slate-300 bg-white px-1.5 py-1 text-center font-mono text-xs"
                                                                    />
                                                                    <span className="text-[10px] text-slate-400">kg</span>
                                                                </div>
                                                            </td>
                                                            <td className="p-3 text-center font-mono font-bold">
                                                                {subtotalW.toFixed(2)} kg
                                                            </td>
                                                            <td className="p-3 text-center">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => removeItemFromBox(activeBoxIndex, itemIdx)}
                                                                    className="text-xs font-bold text-rose-600 hover:underline"
                                                                >
                                                                    Quitar
                                                                </button>
                                                            </td>
                                                        </tr>
                                                    )
                                                })
                                            )}
                                        </tbody>
                                        {activeBox.items.length > 0 && (
                                            <tfoot className="border-t border-slate-200 bg-slate-50 font-bold">
                                                <tr>
                                                    <td colSpan={2} className="p-3 text-right">
                                                        Total en Caja #{activeBox.box_number}:
                                                    </td>
                                                    <td className="p-3 text-center font-mono text-sm">
                                                        {currentUnits} piezas
                                                    </td>
                                                    <td></td>
                                                    <td className="p-3 text-center font-mono text-sm text-indigo-700">
                                                        {currentWeight.toFixed(2)} / {maxWeight.toFixed(2)} kg
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

                {/* BOTTOM SUMMARY & FINAL SAVE */}
                <div className="rounded-2xl border border-indigo-200 bg-indigo-50/60 p-5 dark:border-indigo-900/50 dark:bg-indigo-950/20">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <span className="text-xs font-black uppercase tracking-wider text-indigo-700 dark:text-indigo-300">
                                Resumen Global del Envío FULL
                            </span>
                            <div className="mt-1 flex flex-wrap items-baseline gap-4 font-mono">
                                <div>
                                    <span className="text-2xl font-black text-slate-900 dark:text-white">
                                        {uniqueBultosCount}
                                    </span>{' '}
                                    <span className="text-xs text-slate-500 font-sans font-bold">
                                        {uniqueBultosCount === 1 ? 'bulto ENVIA' : 'bultos ENVIA'}
                                    </span>
                                </div>
                                <span className="text-slate-300">|</span>
                                <div>
                                    <span className="text-2xl font-black text-slate-900 dark:text-white">
                                        {totalBoxesSum}
                                    </span>{' '}
                                    <span className="text-xs text-slate-500 font-sans font-bold">
                                        {totalBoxesSum === 1 ? 'caja de 30 kg' : 'cajas de 30 kg'}
                                    </span>
                                </div>
                                <span className="text-slate-300">|</span>
                                <div>
                                    <span className="text-2xl font-black text-indigo-600 dark:text-indigo-400">
                                        {totalWeightSum.toFixed(2)}
                                    </span>{' '}
                                    <span className="text-xs text-slate-500 font-sans font-bold">
                                        kg totales
                                    </span>
                                </div>
                                <span className="text-slate-300">|</span>
                                <div>
                                    <span className="text-2xl font-black text-slate-900 dark:text-white">
                                        {totalUnitsCount}
                                    </span>{' '}
                                    <span className="text-xs text-slate-500 font-sans font-bold">
                                        piezas
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div className="flex items-center gap-3">
                            <Link
                                href={`/meli/full/envios/${shipment.id}`}
                                className="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50"
                            >
                                Cancelar
                            </Link>
                            <button
                                type="button"
                                onClick={handleSubmit}
                                disabled={submitting || totalUnitsCount <= 0}
                                className="rounded-xl bg-indigo-600 px-6 py-2.5 text-xs font-bold text-white shadow-md hover:bg-indigo-700 disabled:opacity-40"
                            >
                                {submitting ? 'Guardando Cambios...' : '💾 Actualizar Envío'}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </AppShell>
    )
}
