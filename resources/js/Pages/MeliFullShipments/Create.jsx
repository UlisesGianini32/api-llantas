import { useState, useMemo } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

export default function MeliFullShipmentsCreate({
    nextShipmentCode = 'FULL-ENV-2026-0001',
    warehouses = {},
    carriers = [],
    products = [],
    recommendations = [],
}) {
    // Current Wizard Step: 1, 2, 3, or 4
    const [currentStep, setCurrentStep] = useState(1)

    // Form Header / Logistics State
    const [shipmentCode, setShipmentCode] = useState(nextShipmentCode)
    const [warehouseCode, setWarehouseCode] = useState('MXCD01')
    const [warehouseName, setWarehouseName] = useState(warehouses['MXCD01'] || '')
    const [meliShipmentId, setMeliShipmentId] = useState('')
    const [carrier, setCarrier] = useState('Paquetexpress')
    const [trackingNumber, setTrackingNumber] = useState('')
    const [enviaCost, setEnviaCost] = useState('')
    const [notes, setNotes] = useState('')

    // STEP 1 STATE: Global Selected Items to send to FULL
    // Array of { product_id, sku, name, brand, weight_kg, barcode, available_stock, quantity_total, requires_labeling }
    const [selectedItems, setSelectedItems] = useState([])
    const [productSearch, setProductSearch] = useState('')
    const [selectedProduct, setSelectedProduct] = useState(null)
    const [inputQty, setInputQty] = useState(10)

    // Recommendations filters
    const [recFilter, setRecFilter] = useState('ALL')
    const [recSearch, setRecSearch] = useState('')
    const [showRecs, setShowRecs] = useState(true)

    // STEP 3 STATE: Boxes of 30 kg & Multi-box Bultos
    const [boxes, setBoxes] = useState([
        {
            id: 1,
            box_number: 1,
            bulto_number: 1,
            boxes_in_bulto: 1, // 1 caja de 30 kg por defecto
            capacity_kg: 30.00,
            dimensions: '40x30x30',
            weight_kg: 0,
            items: [],
        },
    ])
    const [activeBoxIndex, setActiveBoxIndex] = useState(0)

    const [submitting, setSubmitting] = useState(false)
    const [errors, setErrors] = useState({})

    const handleWarehouseChange = (code) => {
        setWarehouseCode(code)
        setWarehouseName(warehouses[code] || '')
    }

    // Filtered products for Step 1 search
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

    // ==========================================
    // STEP 1 HANDLERS: SELECTING PRODUCTS
    // ==========================================
    const addProductToSelection = (prod, qty) => {
        const q = parseInt(qty, 10)
        if (isNaN(q) || q <= 0) return

        const existingIdx = selectedItems.findIndex((it) => it.product_id === prod.id || it.sku === prod.sku)
        if (existingIdx >= 0) {
            const updated = [...selectedItems]
            updated[existingIdx].quantity_total += q
            setSelectedItems(updated)
        } else {
            setSelectedItems([
                ...selectedItems,
                {
                    product_id: prod.id,
                    sku: prod.sku,
                    name: prod.name,
                    brand: prod.brand || '',
                    barcode: prod.barcode || '',
                    weight_kg: parseFloat(prod.weight_kg) || 1.0,
                    available_stock: prod.available_stock ?? 0,
                    quantity_total: q,
                    // If product doesn't have barcode or has requires_meli_labeling = true, default to true
                    requires_labeling: Boolean(prod.requires_meli_labeling || !prod.barcode),
                },
            ])
        }

        setSelectedProduct(null)
        setProductSearch('')
        setInputQty(10)
    }

    const removeProductFromSelection = (index) => {
        const updated = [...selectedItems]
        updated.splice(index, 1)
        setSelectedItems(updated)
    }

    const updateProductSelectionQty = (index, newQty) => {
        const q = parseInt(newQty, 10)
        if (isNaN(q) || q <= 0) return
        const updated = [...selectedItems]
        updated[index].quantity_total = q
        setSelectedItems(updated)
    }

    // Step 1 Totals
    const step1TotalPieces = selectedItems.reduce((acc, it) => acc + (it.quantity_total || 0), 0)
    const step1TotalWeight = selectedItems.reduce(
        (acc, it) => acc + (it.quantity_total || 0) * (it.weight_kg || 1.0),
        0
    )
    const step1EstimatedBoxes = Math.max(1, Math.ceil(step1TotalWeight / 28.5))

    // ==========================================
    // STEP 2 HANDLERS: LABELING CONTROL
    // ==========================================
    const toggleRequiresLabeling = (index) => {
        const updated = [...selectedItems]
        updated[index].requires_labeling = !updated[index].requires_labeling
        setSelectedItems(updated)
    }

    const setAllLabeling = (status) => {
        const updated = selectedItems.map((it) => ({
            ...it,
            requires_labeling: status,
        }))
        setSelectedItems(updated)
    }

    const totalLabelsCount = selectedItems.reduce(
        (acc, it) => acc + (it.requires_labeling ? it.quantity_total : 0),
        0
    )

    // ==========================================
    // STEP 3 HANDLERS: PACKING IN 30 KG BOXES
    // ==========================================
    const calculateBoxWeight = (box) => {
        return (box.items || []).reduce((acc, it) => {
            const w = parseFloat(it.unit_weight_kg) || 0
            const q = parseInt(it.quantity_sent, 10) || 0
            return acc + w * q
        }, 0)
    }

    const calculateBoxUnits = (box) => {
        return (box.items || []).reduce((acc, it) => acc + (parseInt(it.quantity_sent, 10) || 0), 0)
    }

    // How many units of each product are already assigned to boxes
    const getPackedCount = (sku) => {
        return boxes.reduce((acc, b) => {
            const found = b.items.find((it) => it.sku === sku)
            return acc + (found ? found.quantity_sent : 0)
        }, 0)
    }

    // Unassigned products remaining
    const remainingProductsToPack = useMemo(() => {
        return selectedItems
            .map((it) => {
                const packed = getPackedCount(it.sku)
                const pending = Math.max(0, it.quantity_total - packed)
                return {
                    ...it,
                    packed,
                    pending,
                }
            })
            .filter((it) => it.pending > 0)
    }, [selectedItems, boxes])

    // Auto-distribute products across 30 kg boxes
    const autoDistributeBoxes = () => {
        if (selectedItems.length === 0) {
            alert('Primero selecciona productos en el Paso 1.')
            return
        }

        const newBoxes = []
        let currentBoxNumber = 1
        let currentBultoNumber = 1

        let currentBox = {
            id: Date.now() + Math.random(),
            box_number: currentBoxNumber,
            bulto_number: currentBultoNumber,
            boxes_in_bulto: 1,
            capacity_kg: 30.00,
            dimensions: '40x30x30',
            weight_kg: 0,
            items: [],
        }

        let currentWeight = 0

        selectedItems.forEach((prod) => {
            let pendingQty = prod.quantity_total
            const unitW = parseFloat(prod.weight_kg) || 1.0

            while (pendingQty > 0) {
                // Check how many pieces fit in current box without exceeding 30.00 kg (aiming for max 29.5 kg)
                const availableWeight = Math.max(0, 29.80 - currentWeight)
                let canFit = Math.floor(availableWeight / unitW)

                if (canFit <= 0 && currentBox.items.length > 0) {
                    // Close current box, start a new one
                    currentBox.weight_kg = currentWeight
                    newBoxes.push(currentBox)

                    currentBoxNumber += 1
                    currentBultoNumber += 1
                    currentWeight = 0
                    currentBox = {
                        id: Date.now() + Math.random() + currentBoxNumber,
                        box_number: currentBoxNumber,
                        bulto_number: currentBultoNumber,
                        boxes_in_bulto: 1,
                        capacity_kg: 30.00,
                        dimensions: '40x30x30',
                        weight_kg: 0,
                        items: [],
                    }
                    continue
                }

                // If even an empty box can't fit 1 unit (product > 30kg)
                if (canFit <= 0 && currentBox.items.length === 0) {
                    canFit = 1 // Pack at least 1
                }

                const packQty = Math.min(pendingQty, canFit)
                currentBox.items.push({
                    inventory_product_id: prod.product_id,
                    sku: prod.sku,
                    product_name: prod.name,
                    brand: prod.brand,
                    quantity_sent: packQty,
                    unit_weight_kg: unitW,
                    total_weight_kg: packQty * unitW,
                    requires_labeling: prod.requires_labeling,
                })

                currentWeight += packQty * unitW
                pendingQty -= packQty
            }
        })

        if (currentBox.items.length > 0) {
            currentBox.weight_kg = currentWeight
            newBoxes.push(currentBox)
        }

        setBoxes(newBoxes)
        setActiveBoxIndex(0)
    }

    // Add another box manually
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

    // Add item to active box manually
    const addItemToBox = (boxIndex, prod, qty) => {
        const q = parseInt(qty, 10)
        if (isNaN(q) || q <= 0) return

        const newBoxes = [...boxes]
        const targetBox = newBoxes[boxIndex]
        const unitW = parseFloat(prod.weight_kg) || 1.0

        const existingIdx = targetBox.items.findIndex((it) => it.sku === prod.sku)
        if (existingIdx >= 0) {
            targetBox.items[existingIdx].quantity_sent += q
            targetBox.items[existingIdx].total_weight_kg = targetBox.items[existingIdx].quantity_sent * unitW
        } else {
            targetBox.items.push({
                inventory_product_id: prod.product_id,
                sku: prod.sku,
                product_name: prod.name,
                brand: prod.brand,
                quantity_sent: q,
                unit_weight_kg: unitW,
                total_weight_kg: q * unitW,
                requires_labeling: prod.requires_labeling,
            })
        }

        targetBox.weight_kg = calculateBoxWeight(targetBox)
        setBoxes(newBoxes)
    }

    const removeItemFromBox = (boxIndex, itemIndex) => {
        const newBoxes = [...boxes]
        newBoxes[boxIndex].items.splice(itemIndex, 1)
        newBoxes[boxIndex].weight_kg = calculateBoxWeight(newBoxes[boxIndex])
        setBoxes(newBoxes)
    }

    // Overall Totals in Step 4
    const totalBoxesSum = boxes.reduce((acc, b) => acc + (b.boxes_in_bulto || 1), 0)
    const uniqueBultosCount = new Set(boxes.map((b) => b.bulto_number || 1)).size
    const totalUnitsCount = boxes.reduce((acc, b) => acc + calculateBoxUnits(b), 0)
    const totalWeightSum = boxes.reduce((acc, b) => acc + calculateBoxWeight(b), 0)

    // ==========================================
    // STEP 4: FINAL SUBMISSION
    // ==========================================
    const handleSubmit = (e) => {
        e?.preventDefault()

        if (totalUnitsCount <= 0) {
            alert('Debes empacar al menos un producto en las cajas antes de guardar.')
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
                        requires_labeling: Boolean(it.requires_labeling),
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
            <Head title="Crear Envío FULL - Wizard de 4 Pasos" />

            <div className="space-y-6 p-4 sm:p-6 lg:p-8">
                {/* TOP BREADCRUMB */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between text-xs text-slate-500">
                    <div className="flex items-center gap-2">
                        <Link href="/meli/full/envios" className="hover:underline">
                            Envíos FULL
                        </Link>
                        <span>/</span>
                        <span className="font-bold text-slate-800 dark:text-slate-200">
                            Nuevo Envío (Flujo de 4 Pasos)
                        </span>
                    </div>

                    <Link
                        href="/meli/full/envios/empaque"
                        className="inline-flex items-center gap-1.5 rounded-xl border border-emerald-300 bg-emerald-50 px-3.5 py-2 text-xs font-bold text-emerald-800 hover:bg-emerald-100 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300"
                    >
                        <span>⚖️</span> ¿Prefieres empacar caja por caja (1 a 1)? Ir a la Mesa de Empaque
                    </Link>
                </div>

                {/* 4-STEP WIZARD PROGRESS BAR */}
                <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-4">
                        {/* STEP 1 */}
                        <button
                            type="button"
                            onClick={() => setCurrentStep(1)}
                            className={`flex items-center gap-3 rounded-xl p-3 text-left transition ${
                                currentStep === 1
                                    ? 'bg-indigo-600 text-white shadow-md'
                                    : currentStep > 1
                                    ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                                    : 'bg-slate-50 text-slate-500 hover:bg-slate-100 dark:bg-neutral-800 dark:text-slate-400'
                            }`}
                        >
                            <span
                                className={`flex h-7 w-7 items-center justify-center rounded-full text-xs font-black ${
                                    currentStep === 1
                                        ? 'bg-white text-indigo-700'
                                        : currentStep > 1
                                        ? 'bg-emerald-600 text-white'
                                        : 'bg-slate-200 text-slate-700 dark:bg-neutral-700 dark:text-slate-300'
                                }`}
                            >
                                {currentStep > 1 ? '✓' : '1'}
                            </span>
                            <div>
                                <span className="block text-xs font-extrabold uppercase tracking-wider">
                                    Paso 1: Productos
                                </span>
                                <span className="block text-[11px] opacity-80">
                                    {selectedItems.length} seleccionados ({step1TotalPieces} uds)
                                </span>
                            </div>
                        </button>

                        {/* STEP 2 */}
                        <button
                            type="button"
                            onClick={() => {
                                if (selectedItems.length === 0) {
                                    alert('Primero selecciona productos en el Paso 1.')
                                    return
                                }
                                setCurrentStep(2)
                            }}
                            className={`flex items-center gap-3 rounded-xl p-3 text-left transition ${
                                currentStep === 2
                                    ? 'bg-indigo-600 text-white shadow-md'
                                    : currentStep > 2
                                    ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                                    : 'bg-slate-50 text-slate-500 hover:bg-slate-100 dark:bg-neutral-800 dark:text-slate-400'
                            }`}
                        >
                            <span
                                className={`flex h-7 w-7 items-center justify-center rounded-full text-xs font-black ${
                                    currentStep === 2
                                        ? 'bg-white text-indigo-700'
                                        : currentStep > 2
                                        ? 'bg-emerald-600 text-white'
                                        : 'bg-slate-200 text-slate-700 dark:bg-neutral-700 dark:text-slate-300'
                                }`}
                            >
                                {currentStep > 2 ? '✓' : '2'}
                            </span>
                            <div>
                                <span className="block text-xs font-extrabold uppercase tracking-wider">
                                    Paso 2: Etiquetado MeLi
                                </span>
                                <span className="block text-[11px] opacity-80">
                                    {totalLabelsCount} etiquetas necesarias
                                </span>
                            </div>
                        </button>

                        {/* STEP 3 */}
                        <button
                            type="button"
                            onClick={() => {
                                if (selectedItems.length === 0) {
                                    alert('Primero selecciona productos en el Paso 1.')
                                    return
                                }
                                setCurrentStep(3)
                            }}
                            className={`flex items-center gap-3 rounded-xl p-3 text-left transition ${
                                currentStep === 3
                                    ? 'bg-indigo-600 text-white shadow-md'
                                    : currentStep > 3
                                    ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                                    : 'bg-slate-50 text-slate-500 hover:bg-slate-100 dark:bg-neutral-800 dark:text-slate-400'
                            }`}
                        >
                            <span
                                className={`flex h-7 w-7 items-center justify-center rounded-full text-xs font-black ${
                                    currentStep === 3
                                        ? 'bg-white text-indigo-700'
                                        : currentStep > 3
                                        ? 'bg-emerald-600 text-white'
                                        : 'bg-slate-200 text-slate-700 dark:bg-neutral-700 dark:text-slate-300'
                                }`}
                            >
                                {currentStep > 3 ? '✓' : '3'}
                            </span>
                            <div>
                                <span className="block text-xs font-extrabold uppercase tracking-wider">
                                    Paso 3: Empacado (30 kg)
                                </span>
                                <span className="block text-[11px] opacity-80">
                                    {totalBoxesSum} cajas en {uniqueBultosCount} bultos
                                </span>
                            </div>
                        </button>

                        {/* STEP 4 */}
                        <button
                            type="button"
                            onClick={() => {
                                if (totalUnitsCount <= 0) {
                                    alert('Empaca los productos en las cajas (Paso 3) antes de ir al Resumen Final.')
                                    return
                                }
                                setCurrentStep(4)
                            }}
                            className={`flex items-center gap-3 rounded-xl p-3 text-left transition ${
                                currentStep === 4
                                    ? 'bg-indigo-600 text-white shadow-md'
                                    : 'bg-slate-50 text-slate-500 hover:bg-slate-100 dark:bg-neutral-800 dark:text-slate-400'
                            }`}
                        >
                            <span
                                className={`flex h-7 w-7 items-center justify-center rounded-full text-xs font-black ${
                                    currentStep === 4
                                        ? 'bg-white text-indigo-700'
                                        : 'bg-slate-200 text-slate-700 dark:bg-neutral-700 dark:text-slate-300'
                                }`}
                            >
                                4
                            </span>
                            <div>
                                <span className="block text-xs font-extrabold uppercase tracking-wider">
                                    Paso 4: Guías & ENVIA
                                </span>
                                <span className="block text-[11px] opacity-80">
                                    Rótulos MeLi y Guía ENVIA
                                </span>
                            </div>
                        </button>
                    </div>
                </div>

                {/* FORM ERROR ALERT */}
                {Object.keys(errors).length > 0 && (
                    <div className="rounded-2xl border border-rose-300 bg-rose-50 p-4 text-xs font-semibold text-rose-800 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300">
                        <strong className="block mb-1">Por favor revisa los errores antes de guardar:</strong>
                        <ul className="list-disc pl-5 space-y-0.5">
                            {Object.entries(errors).map(([f, msg]) => (
                                <li key={f}>{msg}</li>
                            ))}
                        </ul>
                    </div>
                )}

                {/* ======================================================== */}
                {/* PASO 1: SELECCIÓN GLOBAL DE PRODUCTOS Y CANTIDADES      */}
                {/* ======================================================== */}
                {currentStep === 1 && (
                    <div className="space-y-6">
                        {/* STEP 1 HEADER */}
                        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <span className="text-xs font-black uppercase tracking-wider text-indigo-600 dark:text-indigo-400">
                                        PASO 1 DE 4
                                    </span>
                                    <h2 className="text-xl font-black text-slate-900 dark:text-white mt-0.5">
                                        Selecciona todos los productos y cantidades que enviarás a FULL
                                    </h2>
                                    <p className="text-xs text-slate-500 mt-1">
                                        Agrega todos los productos que vas a mandar. El sistema estimará el peso total y cuántas cajas de 30 kg se ocuparán.
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    onClick={() => {
                                        if (selectedItems.length === 0) {
                                            alert('Agrega al menos un producto a la lista.')
                                            return
                                        }
                                        setCurrentStep(2)
                                    }}
                                    disabled={selectedItems.length === 0}
                                    className="rounded-xl bg-indigo-600 px-5 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-40"
                                >
                                    Siguiente: Etiquetado MeLi ➔
                                </button>
                            </div>
                        </div>

                        {/* RECOMENDACIONES DE REABASTECIMIENTO */}
                        <div className="rounded-2xl border-2 border-amber-300 bg-gradient-to-br from-amber-50/70 via-white to-amber-50/40 p-5 shadow-sm dark:border-amber-900/60 dark:from-neutral-900 dark:via-neutral-900 dark:to-amber-950/20">
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex items-center gap-2">
                                    <span className="text-xl">✨</span>
                                    <h3 className="text-base font-black text-slate-900 dark:text-white">
                                        Productos Recomendados para FULL (Poco o nulo stock en CEDIS MeLi)
                                    </h3>
                                    <span className="rounded-full bg-amber-400 px-2 py-0.5 text-[10px] font-black text-slate-900">
                                        {recommendations.length}
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setShowRecs(!showRecs)}
                                    className="text-xs font-bold text-slate-600 hover:underline"
                                >
                                    {showRecs ? '▲ Ocultar sugerencias' : '▼ Ver sugerencias'}
                                </button>
                            </div>

                            {showRecs && (
                                <div className="mt-4 space-y-3">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <button
                                            type="button"
                                            onClick={() => setRecFilter('ALL')}
                                            className={`rounded-lg px-2.5 py-1 text-xs font-bold ${
                                                recFilter === 'ALL' ? 'bg-slate-900 text-white' : 'bg-white border'
                                            }`}
                                        >
                                            Todos
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setRecFilter('CRITICAL')}
                                            className={`rounded-lg px-2.5 py-1 text-xs font-bold ${
                                                recFilter === 'CRITICAL' ? 'bg-rose-600 text-white' : 'bg-white border text-rose-700'
                                            }`}
                                        >
                                            🚨 Urgentes (Agotados MeLi)
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setRecFilter('HIGH')}
                                            className={`rounded-lg px-2.5 py-1 text-xs font-bold ${
                                                recFilter === 'HIGH' ? 'bg-amber-600 text-white' : 'bg-white border text-amber-700'
                                            }`}
                                        >
                                            🔥 Alta Rotación
                                        </button>
                                    </div>

                                    <div className="max-h-56 overflow-y-auto rounded-xl border border-slate-200 bg-white">
                                        <table className="w-full text-left text-xs">
                                            <thead className="sticky top-0 bg-slate-100 text-[11px] font-bold text-slate-600">
                                                <tr>
                                                    <th className="p-2.5">SKU / Producto</th>
                                                    <th className="p-2.5 text-center">Ventas 30d</th>
                                                    <th className="p-2.5 text-center">Stock MeLi</th>
                                                    <th className="p-2.5 text-center">Stock Almacén</th>
                                                    <th className="p-2.5 text-center">Sugerido</th>
                                                    <th className="p-2.5 text-center">Acción</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100 font-medium">
                                                {filteredRecommendations.map((rec, idx) => (
                                                    <tr key={idx} className="hover:bg-amber-50/50">
                                                        <td className="p-2.5">
                                                            <strong className="font-mono text-slate-900 mr-2">{rec.sku}</strong>
                                                            <span>{rec.name}</span>
                                                        </td>
                                                        <td className="p-2.5 text-center font-bold text-indigo-600">
                                                            {rec.sales_full_30d || 0} uds
                                                        </td>
                                                        <td className="p-2.5 text-center font-bold">
                                                            {rec.full_stock_available <= 0 ? (
                                                                <span className="text-rose-600">0 (¡Agotado!)</span>
                                                            ) : (
                                                                <span className="text-amber-600">{rec.full_stock_available}</span>
                                                            )}
                                                        </td>
                                                        <td className="p-2.5 text-center font-bold text-emerald-600">
                                                            {rec.available_stock || 0}
                                                        </td>
                                                        <td className="p-2.5 text-center font-bold text-indigo-700">
                                                            {rec.suggested_quantity} uds
                                                        </td>
                                                        <td className="p-2.5 text-center">
                                                            <button
                                                                type="button"
                                                                disabled={(rec.available_stock || 0) <= 0}
                                                                onClick={() => {
                                                                    const prod = products.find((p) => p.sku === rec.sku) || {
                                                                        id: rec.product_id,
                                                                        sku: rec.sku,
                                                                        name: rec.name,
                                                                        brand: rec.brand,
                                                                        weight_kg: rec.weight_kg,
                                                                        barcode: rec.barcode,
                                                                        available_stock: rec.available_stock,
                                                                    }
                                                                    addProductToSelection(prod, rec.suggested_quantity || 10)
                                                                }}
                                                                className="rounded-lg bg-indigo-600 px-2.5 py-1 text-[11px] font-bold text-white hover:bg-indigo-700 disabled:opacity-40"
                                                            >
                                                                + Agregar a Lista
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* PRODUCT SEARCH & ADD TO STEP 1 */}
                        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <span className="text-xs font-black uppercase tracking-wider text-slate-700 dark:text-slate-300 block mb-2">
                                Buscar y Agregar Productos del Almacén Local:
                            </span>

                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-12 items-end">
                                <div className="sm:col-span-7">
                                    <label className="block text-[11px] font-bold text-slate-500 mb-1">
                                        Buscar por SKU, Nombre o Código de Barras:
                                    </label>
                                    <input
                                        type="text"
                                        placeholder="Escribe para buscar..."
                                        value={productSearch}
                                        onChange={(e) => setProductSearch(e.target.value)}
                                        className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-medium dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                    />

                                    {filteredProducts.length > 0 && (
                                        <div className="mt-1 max-h-48 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-lg dark:border-neutral-800 dark:bg-neutral-900">
                                            {filteredProducts.map((p) => (
                                                <div
                                                    key={p.id}
                                                    onClick={() => {
                                                        setSelectedProduct(p)
                                                        setProductSearch(p.sku)
                                                    }}
                                                    className={`cursor-pointer rounded-lg px-2.5 py-1.5 text-xs transition flex items-center justify-between ${
                                                        selectedProduct?.id === p.id
                                                            ? 'bg-indigo-50 font-bold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300'
                                                            : 'hover:bg-slate-50 text-slate-800 dark:text-slate-200'
                                                    }`}
                                                >
                                                    <div>
                                                        <span className="font-mono font-bold mr-2 text-indigo-600 dark:text-indigo-400">
                                                            {p.sku}
                                                        </span>
                                                        <span>{p.name}</span>
                                                    </div>
                                                    <span className="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700">
                                                        Stock: {p.available_stock ?? 0} · {(parseFloat(p.weight_kg) || 1.0).toFixed(2)} kg
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>

                                <div className="sm:col-span-3">
                                    <label className="block text-[11px] font-bold text-slate-500 mb-1">
                                        Cantidad Total a Mandar:
                                    </label>
                                    <input
                                        type="number"
                                        min="1"
                                        value={inputQty}
                                        onChange={(e) => setInputQty(e.target.value)}
                                        className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 font-mono text-xs font-bold"
                                    />
                                </div>

                                <div className="sm:col-span-2">
                                    <button
                                        type="button"
                                        disabled={!selectedProduct}
                                        onClick={() => {
                                            if (selectedProduct) {
                                                addProductToSelection(selectedProduct, inputQty)
                                            }
                                        }}
                                        className="w-full rounded-xl bg-indigo-600 py-2 text-xs font-bold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-40"
                                    >
                                        ➕ Agregar
                                    </button>
                                </div>
                            </div>
                        </div>

                        {/* LIST OF SELECTED PRODUCTS IN STEP 1 */}
                        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="flex items-center justify-between mb-3">
                                <h3 className="text-sm font-black text-slate-900 dark:text-white uppercase tracking-wider">
                                    Lista de Productos a Enviar ({selectedItems.length} artículos)
                                </h3>

                                <div className="flex items-center gap-4 text-xs font-mono">
                                    <span>
                                        Total Piezas: <strong className="text-indigo-600">{step1TotalPieces}</strong>
                                    </span>
                                    <span>
                                        Peso Estimado: <strong className="text-slate-900">{step1TotalWeight.toFixed(2)} kg</strong>
                                    </span>
                                    <span className="rounded bg-slate-100 px-2 py-0.5 text-slate-700 font-sans font-bold">
                                        ~{step1EstimatedBoxes} {step1EstimatedBoxes === 1 ? 'caja' : 'cajas'} de 30 kg
                                    </span>
                                </div>
                            </div>

                            <div className="overflow-x-auto rounded-xl border border-slate-200">
                                <table className="w-full text-left text-xs">
                                    <thead className="bg-slate-50 border-b text-[11px] font-bold text-slate-500 uppercase">
                                        <tr>
                                            <th className="p-3">SKU</th>
                                            <th className="p-3">Producto</th>
                                            <th className="p-3 text-center">Stock Almacén</th>
                                            <th className="p-3 text-center">Peso Unitario</th>
                                            <th className="p-3 text-center">Cantidad a Enviar</th>
                                            <th className="p-3 text-center">Peso Subtotal</th>
                                            <th className="p-3 text-center">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 font-medium">
                                        {selectedItems.length === 0 ? (
                                            <tr>
                                                <td colSpan={7} className="py-8 text-center text-slate-400">
                                                    Aún no has agregado productos. Usa las recomendaciones arriba o el buscador.
                                                </td>
                                            </tr>
                                        ) : (
                                            selectedItems.map((item, idx) => (
                                                <tr key={idx} className="hover:bg-slate-50/50">
                                                    <td className="p-3 font-mono font-bold text-indigo-600">
                                                        {item.sku}
                                                    </td>
                                                    <td className="p-3 font-semibold text-slate-800">
                                                        {item.name}
                                                    </td>
                                                    <td className="p-3 text-center font-bold text-emerald-600">
                                                        {item.available_stock} uds
                                                    </td>
                                                    <td className="p-3 text-center font-mono text-slate-500">
                                                        {(item.weight_kg || 1.0).toFixed(2)} kg
                                                    </td>
                                                    <td className="p-3 text-center">
                                                        <input
                                                            type="number"
                                                            min="1"
                                                            value={item.quantity_total}
                                                            onChange={(e) => updateProductSelectionQty(idx, e.target.value)}
                                                            className="w-20 rounded-lg border border-slate-300 px-2 py-1 text-center font-mono text-xs font-bold"
                                                        />
                                                    </td>
                                                    <td className="p-3 text-center font-mono font-bold text-slate-900">
                                                        {((item.quantity_total || 0) * (item.weight_kg || 1.0)).toFixed(2)} kg
                                                    </td>
                                                    <td className="p-3 text-center">
                                                        <button
                                                            type="button"
                                                            onClick={() => removeProductFromSelection(idx)}
                                                            className="text-xs font-bold text-rose-600 hover:underline"
                                                        >
                                                            Quitar
                                                        </button>
                                                    </td>
                                                </tr>
                                            ))
                                        )}
                                    </tbody>
                                </table>
                            </div>

                            <div className="mt-5 flex justify-end">
                                <button
                                    type="button"
                                    onClick={() => setCurrentStep(2)}
                                    disabled={selectedItems.length === 0}
                                    className="rounded-xl bg-indigo-600 px-6 py-2.5 text-xs font-bold text-white shadow-md hover:bg-indigo-700 disabled:opacity-40"
                                >
                                    Siguiente: Paso 2 - Etiquetado MeLi ➔
                                </button>
                            </div>
                        </div>
                    </div>
                )}

                {/* ======================================================== */}
                {/* PASO 2: ETIQUETADO INDIVIDUAL DE PRODUCTOS (MELI STICKERS)*/}
                {/* ======================================================== */}
                {currentStep === 2 && (
                    <div className="space-y-6">
                        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <span className="text-xs font-black uppercase tracking-wider text-indigo-600 dark:text-indigo-400">
                                        PASO 2 DE 4
                                    </span>
                                    <h2 className="text-xl font-black text-slate-900 dark:text-white mt-0.5">
                                        Etiquetado Individual MeLi (Stickers de Producto)
                                    </h2>
                                    <p className="text-xs text-slate-500 mt-1">
                                        Mercado Libre FULL exige etiquetar con código de barras las piezas que no traen código de fábrica legible. Indica cuáles requieren etiqueta y emplayado:
                                    </p>
                                </div>

                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setCurrentStep(1)}
                                        className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
                                    >
                                        ⬅ Volver al Paso 1
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            // Pre-distribute into boxes if boxes empty
                                            if (boxes.length === 1 && boxes[0].items.length === 0) {
                                                autoDistributeBoxes()
                                            }
                                            setCurrentStep(3)
                                        }}
                                        className="rounded-xl bg-indigo-600 px-5 py-2 text-xs font-bold text-white shadow-sm hover:bg-indigo-700"
                                    >
                                        Siguiente: Paso 3 - Empacado en Cajas ➔
                                    </button>
                                </div>
                            </div>
                        </div>

                        {/* LABELING SUMMARY & BATCH CONTROLS */}
                        <div className="rounded-2xl border border-indigo-200 bg-indigo-50/70 p-5 dark:border-indigo-900/50 dark:bg-indigo-950/20">
                            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                                <div className="flex items-center gap-3">
                                    <div className="rounded-2xl bg-indigo-600 p-3 text-2xl text-white">
                                        🏷️
                                    </div>
                                    <div>
                                        <h3 className="text-base font-black text-slate-900 dark:text-white">
                                            {totalLabelsCount} Etiquetas de Producto a Imprimir
                                        </h3>
                                        <p className="text-xs text-slate-600 dark:text-slate-400">
                                            Se imprimirán stickers térmicos individuales (50x30 mm) para pegar a cada pieza antes de meterla a la caja.
                                        </p>
                                    </div>
                                </div>

                                <div className="flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setAllLabeling(true)}
                                        className="rounded-xl border border-indigo-300 bg-white px-3 py-1.5 text-xs font-bold text-indigo-700 hover:bg-indigo-50"
                                    >
                                        Marcar Todos
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setAllLabeling(false)}
                                        className="rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50"
                                    >
                                        Desmarcar Todos
                                    </button>
                                </div>
                            </div>
                        </div>

                        {/* LABELING TABLE */}
                        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="overflow-x-auto rounded-xl border border-slate-200">
                                <table className="w-full text-left text-xs">
                                    <thead className="bg-slate-50 border-b text-[11px] font-bold text-slate-500 uppercase">
                                        <tr>
                                            <th className="p-3 text-center" style={{ width: '80px' }}>¿Etiquetar?</th>
                                            <th className="p-3">SKU</th>
                                            <th className="p-3">Producto</th>
                                            <th className="p-3 text-center">Código de Barras / MLM</th>
                                            <th className="p-3 text-center">Piezas a Enviar</th>
                                            <th className="p-3 text-center">Etiquetas Requeridas</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 font-medium">
                                        {selectedItems.map((item, idx) => (
                                            <tr key={idx} className="hover:bg-slate-50/50">
                                                <td className="p-3 text-center">
                                                    <input
                                                        type="checkbox"
                                                        checked={item.requires_labeling}
                                                        onChange={() => toggleRequiresLabeling(idx)}
                                                        className="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                                                    />
                                                </td>
                                                <td className="p-3 font-mono font-bold text-indigo-600">
                                                    {item.sku}
                                                </td>
                                                <td className="p-3 font-semibold text-slate-800">
                                                    {item.name}
                                                    {item.brand && (
                                                        <span className="text-[10px] text-slate-400 block">{item.brand}</span>
                                                    )}
                                                </td>
                                                <td className="p-3 text-center font-mono text-slate-500">
                                                    {item.barcode || 'Sin código fábrica'}
                                                </td>
                                                <td className="p-3 text-center font-bold text-slate-900">
                                                    {item.quantity_total} uds
                                                </td>
                                                <td className="p-3 text-center">
                                                    {item.requires_labeling ? (
                                                        <span className="rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-black text-indigo-800">
                                                            {item.quantity_total} stickers
                                                        </span>
                                                    ) : (
                                                        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-500">
                                                            No requiere (tiene código)
                                                        </span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>

                            <div className="mt-5 flex items-center justify-between">
                                <button
                                    type="button"
                                    onClick={() => setCurrentStep(1)}
                                    className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
                                >
                                    ⬅ Volver al Paso 1
                                </button>
                                <button
                                    type="button"
                                    onClick={() => {
                                        if (boxes.length === 1 && boxes[0].items.length === 0) {
                                            autoDistributeBoxes()
                                        }
                                        setCurrentStep(3)
                                    }}
                                    className="rounded-xl bg-indigo-600 px-6 py-2 text-xs font-bold text-white shadow-md hover:bg-indigo-700"
                                >
                                    Siguiente: Paso 3 - Empacado en Cajas de 30 kg ➔
                                </button>
                            </div>
                        </div>
                    </div>
                )}

                {/* ======================================================== */}
                {/* PASO 3: EMPACADO FÍSICO EN CAJAS DE 30 KG (O AL AZAR)    */}
                {/* ======================================================== */}
                {currentStep === 3 && (
                    <div className="space-y-6">
                        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <span className="text-xs font-black uppercase tracking-wider text-indigo-600 dark:text-indigo-400">
                                        PASO 3 DE 4
                                    </span>
                                    <h2 className="text-xl font-black text-slate-900 dark:text-white mt-0.5">
                                        Empacado en Cajas de 30 kg y Bultos ENVIA
                                    </h2>
                                    <p className="text-xs text-slate-500 mt-1">
                                        Ve echando los productos en cada caja controlando que ninguna exceda <strong>30.00 kg</strong>. O usa el botón inteligente de auto-distribuir.
                                    </p>
                                </div>

                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={autoDistributeBoxes}
                                        className="rounded-xl bg-amber-400 px-3.5 py-2 text-xs font-black text-slate-950 shadow-sm hover:bg-amber-300"
                                    >
                                        ⚡ Auto-Distribuir en Cajas de 30 kg
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setCurrentStep(4)}
                                        className="rounded-xl bg-indigo-600 px-5 py-2 text-xs font-bold text-white shadow-sm hover:bg-indigo-700"
                                    >
                                        Siguiente: Paso 4 - Resumen & Guías ➔
                                    </button>
                                </div>
                            </div>
                        </div>

                        {/* UNASSIGNED PRODUCTS ALERT (IF ANY) */}
                        {remainingProductsToPack.length > 0 && (
                            <div className="rounded-2xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/40">
                                <div className="flex items-center justify-between mb-2">
                                    <h4 className="text-xs font-black uppercase tracking-wider text-amber-900 dark:text-amber-200 flex items-center gap-1.5">
                                        <span>⏳</span> Productos Pendientes por Meter a una Caja:
                                    </h4>
                                    <button
                                        type="button"
                                        onClick={autoDistributeBoxes}
                                        className="text-[11px] font-bold text-indigo-700 underline"
                                    >
                                        Llenar cajas automáticamente
                                    </button>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {remainingProductsToPack.map((p, idx) => (
                                        <div
                                            key={idx}
                                            className="flex items-center gap-2 rounded-xl border border-amber-200 bg-white px-3 py-1.5 text-xs shadow-sm"
                                        >
                                            <strong className="font-mono text-indigo-600">{p.sku}:</strong>
                                            <span>Faltan <strong>{p.pending}</strong> uds</span>
                                            <button
                                                type="button"
                                                onClick={() => addItemToBox(activeBoxIndex, p, p.pending)}
                                                className="rounded bg-indigo-600 px-1.5 py-0.5 text-[10px] font-bold text-white hover:bg-indigo-700"
                                            >
                                                + Meter a Caja #{boxes[activeBoxIndex]?.box_number}
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* BOX TABS & BUILDER */}
                        <div className="space-y-4">
                            <div className="flex items-center justify-between">
                                <div className="flex flex-wrap items-center gap-2">
                                    {boxes.map((box, idx) => {
                                        const currentW = calculateBoxWeight(box)
                                        const maxW = box.capacity_kg || (box.boxes_in_bulto || 1) * 30.00
                                        const isOver = currentW > maxW
                                        const isActive = activeBoxIndex === idx

                                        return (
                                            <button
                                                key={box.id}
                                                type="button"
                                                onClick={() => setActiveBoxIndex(idx)}
                                                className={`rounded-xl px-3.5 py-2 text-xs font-bold transition flex items-center gap-2 ${
                                                    isActive
                                                        ? 'bg-indigo-600 text-white shadow-sm ring-2 ring-indigo-400'
                                                        : 'bg-white text-slate-700 border hover:bg-slate-100'
                                                }`}
                                            >
                                                <span>Caja #{box.box_number}</span>
                                                <span
                                                    className={`rounded-full px-2 py-0.5 text-[10px] font-black ${
                                                        isOver
                                                            ? 'bg-rose-500 text-white'
                                                            : isActive
                                                            ? 'bg-indigo-700 text-white'
                                                            : 'bg-slate-200 text-slate-800'
                                                    }`}
                                                >
                                                    {currentW.toFixed(2)} / {maxW.toFixed(0)} kg
                                                </span>
                                            </button>
                                        )
                                    })}
                                </div>

                                <button
                                    type="button"
                                    onClick={addBox}
                                    className="rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-50"
                                >
                                    + Agregar Otra Caja
                                </button>
                            </div>

                            {/* ACTIVE BOX DETAILS */}
                            {boxes[activeBoxIndex] && (() => {
                                const activeBox = boxes[activeBoxIndex]
                                const currentWeight = calculateBoxWeight(activeBox)
                                const maxWeight = activeBox.capacity_kg || (activeBox.boxes_in_bulto || 1) * 30.00
                                const isOverWeight = currentWeight > maxWeight

                                return (
                                    <div className="rounded-2xl border-2 border-indigo-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                                        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between border-b pb-4 gap-3">
                                            <div>
                                                <h3 className="text-base font-black text-slate-900 dark:text-white flex items-center gap-2">
                                                    <span>Caja #{activeBox.box_number}</span>
                                                    <span className="text-xs text-slate-400">·</span>
                                                    <span>Bulto ENVIA #{activeBox.bulto_number}</span>
                                                </h3>
                                                <p className="text-xs text-slate-500">
                                                    Capacidad: <strong>{maxWeight.toFixed(2)} kg</strong> ({activeBox.boxes_in_bulto} caja(s) de 30 kg máx)
                                                </p>
                                            </div>

                                            <div className="flex items-center gap-2">
                                                {boxes.length > 1 && (
                                                    <button
                                                        type="button"
                                                        onClick={() => removeBox(activeBoxIndex)}
                                                        className="rounded-xl border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-bold text-rose-700 hover:bg-rose-100"
                                                    >
                                                        Eliminar Caja
                                                    </button>
                                                )}
                                            </div>
                                        </div>

                                        {/* BOX SETTINGS (MULTI-BOX IN SAME BULTO) */}
                                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-4 my-4 p-3 rounded-xl bg-slate-50 border text-xs">
                                            <div>
                                                <label className="block font-bold text-slate-500 mb-1">
                                                    Cajas de 30 kg en este Bulto:
                                                </label>
                                                <select
                                                    value={activeBox.boxes_in_bulto || 1}
                                                    onChange={(e) => updateBoxConfig(activeBoxIndex, 'boxes_in_bulto', e.target.value)}
                                                    className="w-full rounded-lg border bg-white px-2 py-1 font-bold"
                                                >
                                                    <option value={1}>1 caja de 30 kg (Máx 30 kg)</option>
                                                    <option value={2}>2 cajas de 30 kg flejadas (Máx 60 kg)</option>
                                                    <option value={3}>3 cajas de 30 kg flejadas (Máx 90 kg)</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label className="block font-bold text-slate-500 mb-1">
                                                    # de Bulto ENVIA:
                                                </label>
                                                <input
                                                    type="number"
                                                    min="1"
                                                    value={activeBox.bulto_number || 1}
                                                    onChange={(e) => updateBoxConfig(activeBoxIndex, 'bulto_number', e.target.value)}
                                                    className="w-full rounded-lg border bg-white px-2 py-1 font-bold"
                                                />
                                            </div>
                                            <div>
                                                <label className="block font-bold text-slate-500 mb-1">
                                                    Límite de Peso (kg):
                                                </label>
                                                <input
                                                    type="number"
                                                    step="0.5"
                                                    value={activeBox.capacity_kg || (activeBox.boxes_in_bulto || 1) * 30.00}
                                                    onChange={(e) => updateBoxConfig(activeBoxIndex, 'capacity_kg', e.target.value)}
                                                    className="w-full rounded-lg border bg-white px-2 py-1 font-bold"
                                                />
                                            </div>
                                            <div>
                                                <label className="block font-bold text-slate-500 mb-1">
                                                    Dimensiones (cm):
                                                </label>
                                                <input
                                                    type="text"
                                                    value={activeBox.dimensions || '40x30x30'}
                                                    onChange={(e) => updateBoxConfig(activeBoxIndex, 'dimensions', e.target.value)}
                                                    className="w-full rounded-lg border bg-white px-2 py-1"
                                                />
                                            </div>
                                        </div>

                                        {/* WEIGHT PROGRESS BAR */}
                                        <div className="mb-4">
                                            <div className="flex justify-between text-xs mb-1 font-bold">
                                                <span>
                                                    Peso en Caja: <strong>{currentWeight.toFixed(2)} kg</strong> / {maxWeight.toFixed(2)} kg
                                                </span>
                                                {isOverWeight ? (
                                                    <span className="text-rose-600 font-bold">
                                                        ⚠️ Excede por {(currentWeight - maxWeight).toFixed(2)} kg
                                                    </span>
                                                ) : (
                                                    <span className="text-slate-500">
                                                        Espacio disponible: {(maxWeight - currentWeight).toFixed(2)} kg
                                                    </span>
                                                )}
                                            </div>
                                            <div className="h-3 w-full rounded-full bg-slate-100 overflow-hidden border">
                                                <div
                                                    className={`h-full ${
                                                        isOverWeight ? 'bg-rose-500' : currentWeight >= maxWeight * 0.9 ? 'bg-emerald-500' : 'bg-indigo-600'
                                                    }`}
                                                    style={{ width: `${Math.min(100, (currentWeight / maxWeight) * 100)}%` }}
                                                />
                                            </div>
                                        </div>

                                        {/* QUICK ADD ITEMS FROM SELECTION */}
                                        <div className="rounded-xl border border-dashed border-slate-300 p-3 bg-slate-50/50 mb-4">
                                            <span className="text-[11px] font-bold text-slate-600 block mb-2">
                                                Echar producto a esta Caja #{activeBox.box_number}:
                                            </span>
                                            <div className="flex flex-wrap gap-2">
                                                {selectedItems.map((prod, pIdx) => {
                                                    const packedSoFar = getPackedCount(prod.sku)
                                                    const remainingForThisProd = Math.max(0, prod.quantity_total - packedSoFar)

                                                    return (
                                                        <button
                                                            key={pIdx}
                                                            type="button"
                                                            onClick={() => addItemToBox(activeBoxIndex, prod, Math.min(10, remainingForThisProd || 5))}
                                                            className="rounded-lg border bg-white px-2.5 py-1 text-xs font-semibold hover:border-indigo-400 flex items-center gap-1.5"
                                                        >
                                                            <span className="font-mono text-indigo-600">{prod.sku}</span>
                                                            <span className="rounded bg-slate-100 px-1 py-0.2 text-[10px]">
                                                                (Faltan {remainingForThisProd})
                                                            </span>
                                                            <span>+</span>
                                                        </button>
                                                    )
                                                })}
                                            </div>
                                        </div>

                                        {/* TABLE OF ITEMS IN THIS BOX */}
                                        <div className="overflow-x-auto rounded-xl border border-slate-200">
                                            <table className="w-full text-left text-xs">
                                                <thead className="bg-slate-50 border-b text-[11px] font-bold text-slate-500 uppercase">
                                                    <tr>
                                                        <th className="p-2.5">SKU</th>
                                                        <th className="p-2.5">Producto</th>
                                                        <th className="p-2.5 text-center">Piezas en Caja</th>
                                                        <th className="p-2.5 text-center">Peso Unitario</th>
                                                        <th className="p-2.5 text-center">Peso Total</th>
                                                        <th className="p-2.5 text-center">Acciones</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-100 font-medium">
                                                    {activeBox.items.length === 0 ? (
                                                        <tr>
                                                            <td colSpan={6} className="py-6 text-center text-slate-400">
                                                                Esta caja está vacía. Agrega productos arriba.
                                                            </td>
                                                        </tr>
                                                    ) : (
                                                        activeBox.items.map((item, itemIdx) => (
                                                            <tr key={itemIdx} className="hover:bg-slate-50/50">
                                                                <td className="p-2.5 font-mono font-bold text-indigo-600">
                                                                    {item.sku}
                                                                </td>
                                                                <td className="p-2.5 font-semibold text-slate-800">
                                                                    {item.product_name}
                                                                </td>
                                                                <td className="p-2.5 text-center font-mono font-bold text-slate-900">
                                                                    {item.quantity_sent}
                                                                </td>
                                                                <td className="p-2.5 text-center font-mono text-slate-500">
                                                                    {(parseFloat(item.unit_weight_kg) || 1.0).toFixed(2)} kg
                                                                </td>
                                                                <td className="p-2.5 text-center font-mono font-bold text-slate-900">
                                                                    {((item.quantity_sent || 0) * (item.unit_weight_kg || 1.0)).toFixed(2)} kg
                                                                </td>
                                                                <td className="p-2.5 text-center">
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => removeItemFromBox(activeBoxIndex, itemIdx)}
                                                                        className="text-xs font-bold text-rose-600 hover:underline"
                                                                    >
                                                                        Quitar
                                                                    </button>
                                                                </td>
                                                            </tr>
                                                        ))
                                                    )}
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                )
                            })()}

                            <div className="mt-5 flex items-center justify-between">
                                <button
                                    type="button"
                                    onClick={() => setCurrentStep(2)}
                                    className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
                                >
                                    ⬅ Volver a Etiquetado
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setCurrentStep(4)}
                                    className="rounded-xl bg-indigo-600 px-6 py-2 text-xs font-bold text-white shadow-md hover:bg-indigo-700"
                                >
                                    Siguiente: Paso 4 - Resumen & Guías ENVIA ➔
                                </button>
                            </div>
                        </div>
                    </div>
                )}

                {/* ======================================================== */}
                {/* PASO 4: RESUMEN FINAL, GUÍAS DE MERCADO LIBRE & ENVIA    */}
                {/* ======================================================== */}
                {currentStep === 4 && (
                    <div className="space-y-6">
                        {/* STEP 4 HEADER */}
                        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <div>
                                <span className="text-xs font-black uppercase tracking-wider text-emerald-600 dark:text-emerald-400">
                                    PASO 4 DE 4: RESUMEN Y FINALIZACIÓN
                                </span>
                                <h2 className="text-xl font-black text-slate-900 dark:text-white mt-0.5">
                                    Resumen de Cajas Resultantes y Registro de Guía ENVIA
                                </h2>
                                <p className="text-xs text-slate-500 mt-1">
                                    Revisa cuántas cajas se armaron, imprime los rótulos oficiales de Mercado Libre y registra la guía que sacaste por separado con ENVIA.
                                </p>
                            </div>
                        </div>

                        {/* RESULTING BOXES HIGHLIGHT (AS REQUESTED BY USER) */}
                        <div className="rounded-2xl border-2 border-emerald-300 bg-gradient-to-br from-emerald-50/70 via-white to-emerald-50/40 p-6 shadow-sm">
                            <span className="text-xs font-black uppercase tracking-wider text-emerald-800 block mb-2">
                                📦 Cajas y Bultos Resultantes para Enviar a FULL:
                            </span>

                            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4 font-mono">
                                <div className="rounded-xl border border-emerald-200 bg-white p-4">
                                    <span className="text-[11px] font-sans font-bold text-slate-500 block">
                                        Total Cajas de 30 kg:
                                    </span>
                                    <span className="text-3xl font-black text-slate-900">
                                        {totalBoxesSum}
                                    </span>
                                    <span className="text-xs text-slate-400 font-sans block mt-0.5">
                                        cajas estándar
                                    </span>
                                </div>

                                <div className="rounded-xl border border-emerald-200 bg-white p-4">
                                    <span className="text-[11px] font-sans font-bold text-slate-500 block">
                                        Total Bultos ENVIA:
                                    </span>
                                    <span className="text-3xl font-black text-emerald-700">
                                        {uniqueBultosCount}
                                    </span>
                                    <span className="text-xs text-slate-400 font-sans block mt-0.5">
                                        bultos a documentar
                                    </span>
                                </div>

                                <div className="rounded-xl border border-emerald-200 bg-white p-4">
                                    <span className="text-[11px] font-sans font-bold text-slate-500 block">
                                        Peso Total Neto:
                                    </span>
                                    <span className="text-3xl font-black text-indigo-700">
                                        {totalWeightSum.toFixed(2)}
                                    </span>
                                    <span className="text-xs text-slate-400 font-sans block mt-0.5">
                                        kg totales
                                    </span>
                                </div>

                                <div className="rounded-xl border border-emerald-200 bg-white p-4">
                                    <span className="text-[11px] font-sans font-bold text-slate-500 block">
                                        Piezas Totales:
                                    </span>
                                    <span className="text-3xl font-black text-slate-900">
                                        {totalUnitsCount}
                                    </span>
                                    <span className="text-xs text-slate-400 font-sans block mt-0.5">
                                        unidades empacadas
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* CONFIGURATION & ENVIA TRACKING FORM */}
                        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <h3 className="text-sm font-black uppercase tracking-wider text-slate-800 dark:text-slate-200 mb-4 flex items-center gap-2">
                                <span>🚚</span> Datos de Bodega MeLi y Guía de ENVIA (Sacada por Separado)
                            </h3>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                <div>
                                    <label className="block text-xs font-bold text-slate-600 mb-1">
                                        Folio de Envío:
                                    </label>
                                    <input
                                        type="text"
                                        value={shipmentCode}
                                        onChange={(e) => setShipmentCode(e.target.value)}
                                        className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 font-mono text-xs font-bold"
                                        required
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-bold text-slate-600 mb-1">
                                        Bodega MeLi Destino (CEDIS) *
                                    </label>
                                    <select
                                        value={warehouseCode}
                                        onChange={(e) => handleWarehouseChange(e.target.value)}
                                        className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold"
                                    >
                                        {Object.entries(warehouses).map(([code, name]) => (
                                            <option key={code} value={code}>
                                                {code} - {name}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label className="block text-xs font-bold text-slate-600 mb-1">
                                        Paquetería ENVIA *
                                    </label>
                                    <select
                                        value={carrier}
                                        onChange={(e) => setCarrier(e.target.value)}
                                        className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold"
                                    >
                                        {carriers.map((c) => (
                                            <option key={c} value={c}>
                                                {c}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label className="block text-xs font-bold text-slate-600 mb-1">
                                        Número de Guía ENVIA (Rastreo):
                                    </label>
                                    <input
                                        type="text"
                                        placeholder="Ej: 1234567890"
                                        value={trackingNumber}
                                        onChange={(e) => setTrackingNumber(e.target.value)}
                                        className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 font-mono text-xs font-bold"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-bold text-slate-600 mb-1">
                                        ID de Cita / Envío MeLi (Opcional):
                                    </label>
                                    <input
                                        type="text"
                                        placeholder="Ej: 41258902"
                                        value={meliShipmentId}
                                        onChange={(e) => setMeliShipmentId(e.target.value)}
                                        className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-bold text-slate-600 mb-1">
                                        Costo Guía ENVIA ($ MXN):
                                    </label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        placeholder="0.00"
                                        value={enviaCost}
                                        onChange={(e) => setEnviaCost(e.target.value)}
                                        className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-mono"
                                    />
                                </div>

                                <div className="sm:col-span-2">
                                    <label className="block text-xs font-bold text-slate-600 mb-1">
                                        Notas u Observaciones:
                                    </label>
                                    <input
                                        type="text"
                                        placeholder="Instrucciones para paquetería, emplayado..."
                                        value={notes}
                                        onChange={(e) => setNotes(e.target.value)}
                                        className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs"
                                    />
                                </div>
                            </div>
                        </div>

                        {/* FINAL ACTIONS & SAVE */}
                        <div className="rounded-2xl border border-indigo-200 bg-indigo-50/60 p-5 dark:border-indigo-900/50 dark:bg-indigo-950/20">
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <h4 className="text-xs font-black uppercase tracking-wider text-indigo-700">
                                        Listo para Despachar y Guardar
                                    </h4>
                                    <p className="mt-1 text-xs text-slate-600">
                                        Al guardar, el envío quedará registrado y podrás imprimir de inmediato los rótulos máster de Mercado Libre y las etiquetas de producto para tus {totalBoxesSum} cajas.
                                    </p>
                                </div>

                                <div className="flex items-center gap-3">
                                    <button
                                        type="button"
                                        onClick={() => setCurrentStep(3)}
                                        className="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50"
                                    >
                                        ⬅ Volver al Paso 3
                                    </button>
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
                )}
            </div>
        </AppShell>
    )
}
