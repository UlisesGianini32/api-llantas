import { useState, useMemo, useEffect, useRef } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'
import CameraBarcodeScanner from '@/Components/CameraBarcodeScanner'

export default function MeliFullShipmentsPackStation({
    shipment = {},
    draftShipments = [],
    products = [],
    meliFullStocks = [],
    warehouses = {},
    carriers = [],
    nextShipmentCode = 'FULL-ENV-2026-0001',
}) {
    // Current Active Shipment
    const [currentShipment, setCurrentShipment] = useState(shipment || {})
    const [boxesList, setBoxesList] = useState(shipment?.boxes || [])

    // Camera Barcode Scanner
    const [isCameraOpen, setIsCameraOpen] = useState(false)

    // Current Box being packed (1 by 1)
    const [boxNumber, setBoxNumber] = useState((boxesList?.length || 0) + 1)
    const [capacityKg, setCapacityKg] = useState(30.00)
    const [currentBoxItems, setCurrentBoxItems] = useState([])

    // Item Search & Picker
    const [searchQuery, setSearchQuery] = useState('')
    const [searchTab, setSearchTab] = useState('ALL') // 'ALL' | 'FULL' | 'LOCAL'
    const [selectedItem, setSelectedItem] = useState(null)
    const [inputQty, setInputQty] = useState(10)
    const [inputWeight, setInputWeight] = useState(1.000)
    const [requiresLabeling, setRequiresLabeling] = useState(false)

    // Modal: Link Product
    const [linkModalOpen, setLinkModalOpen] = useState(false)
    const [itemToLink, setItemToLink] = useState(null)
    const [linkSearchQuery, setLinkSearchQuery] = useState('')
    const [linkingLoading, setLinkingLoading] = useState(false)

    // Modal: Quick Create Product
    const [createModalOpen, setCreateModalOpen] = useState(false)
    const [quickProductData, setQuickProductData] = useState({
        name: '',
        sku: '',
        barcode: '',
        brand: 'General',
        weight_kg: 1.000,
        initial_stock: 50,
        mlm: '',
        variation_id: '',
        meli_sku: '',
    })
    const [quickCreating, setQuickCreating] = useState(false)

    // Sealing / Saving Box Loading
    const [sealingBox, setSealingBox] = useState(false)
    const [lastSealedBox, setLastSealedBox] = useState(null)
    const [actionFeedback, setActionFeedback] = useState(null)

    // Local copy of products & full stocks (to allow instant additions when quick creating/linking)
    const [localProducts, setLocalProducts] = useState(products || [])
    const [localFullStocks, setLocalFullStocks] = useState(meliFullStocks || [])

    // Update box number when boxesList changes
    useEffect(() => {
        setBoxNumber((boxesList?.length || 0) + 1)
    }, [boxesList])

    // Weight Calculation for the Active Box
    const currentBoxWeight = useMemo(() => {
        return currentBoxItems.reduce((acc, it) => acc + (it.quantity_sent * (parseFloat(it.unit_weight_kg) || 0)), 0)
    }, [currentBoxItems])

    const currentBoxUnits = useMemo(() => {
        return currentBoxItems.reduce((acc, it) => acc + (parseInt(it.quantity_sent, 10) || 0), 0)
    }, [currentBoxItems])

    const isWeightExceeded = currentBoxWeight > capacityKg
    const isWeightNear = currentBoxWeight >= 27.00 && !isWeightExceeded
    const weightRemaining = Math.max(0, capacityKg - currentBoxWeight)

    // Lookup products map for quick resolution
    const productsMap = useMemo(() => {
        const map = new Map()
        localProducts.forEach((p) => map.set(p.id, p))
        return map
    }, [localProducts])

    // Filtered items in search
    const searchResults = useMemo(() => {
        const q = searchQuery.trim().toLowerCase()
        if (!q) return []

        const fullMatches = []
        if (searchTab === 'ALL' || searchTab === 'FULL') {
            localFullStocks.forEach((st) => {
                const matchMlm = st.mlm && st.mlm.toLowerCase().includes(q)
                const matchTitle = st.title && st.title.toLowerCase().includes(q)
                const matchSku = st.sku && st.sku.toLowerCase().includes(q)

                if (matchMlm || matchTitle || matchSku) {
                    const linkedProd = st.linked_product_id ? productsMap.get(st.linked_product_id) : null
                    fullMatches.push({
                        type: 'FULL',
                        id: `full_${st.id}`,
                        mlm: st.mlm,
                        variation_id: st.variation_id,
                        meli_sku: st.sku,
                        title: st.title,
                        thumbnail: st.thumbnail,
                        full_available: st.full_available_quantity ?? 0,
                        linked_product_id: st.linked_product_id,
                        linked_product: linkedProd,
                        weight_kg: linkedProd?.weight_kg || 1.0,
                    })
                }
            })
        }

        const localMatches = []
        if (searchTab === 'ALL' || searchTab === 'LOCAL') {
            localProducts.forEach((p) => {
                const matchSku = p.sku && p.sku.toLowerCase().includes(q)
                const matchName = p.name && p.name.toLowerCase().includes(q)
                const matchBarcode = p.barcode && p.barcode.toLowerCase().includes(q)
                const matchBarcodeSec = p.barcode_secondary && p.barcode_secondary.toLowerCase().includes(q)

                if (matchSku || matchName || matchBarcode || matchBarcodeSec) {
                    localMatches.push({
                        type: 'LOCAL',
                        id: `local_${p.id}`,
                        inventory_product_id: p.id,
                        sku: p.sku,
                        barcode: p.barcode,
                        name: p.name,
                        brand: p.brand || '',
                        available_stock: p.available_stock ?? 0,
                        weight_kg: parseFloat(p.weight_kg) || 1.0,
                        requires_meli_labeling: Boolean(p.requires_meli_labeling),
                    })
                }
            })
        }

        return [...fullMatches.slice(0, 15), ...localMatches.slice(0, 15)]
    }, [searchQuery, searchTab, localFullStocks, localProducts, productsMap])

    // Select an item from search
    const handleSelectItem = (item) => {
        setSelectedItem(item)
        setInputWeight(item.weight_kg || 1.0)
        setRequiresLabeling(item.type === 'FULL' ? true : Boolean(item.requires_meli_labeling))
        setSearchQuery('')
    }

    // Barcode scanned via Camera or Gun
    const handleBarcodeScanned = (barcode) => {
        setIsCameraOpen(false)
        const cleaned = String(barcode).trim()
        if (!cleaned) return

        // 1. Check exact match in local products
        const localMatch = localProducts.find(
            (p) =>
                (p.barcode && p.barcode.toLowerCase() === cleaned.toLowerCase()) ||
                (p.barcode_secondary && p.barcode_secondary.toLowerCase() === cleaned.toLowerCase()) ||
                (p.sku && p.sku.toLowerCase() === cleaned.toLowerCase())
        )

        if (localMatch) {
            handleSelectItem({
                type: 'LOCAL',
                id: `local_${localMatch.id}`,
                inventory_product_id: localMatch.id,
                sku: localMatch.sku,
                barcode: localMatch.barcode,
                name: localMatch.name,
                brand: localMatch.brand || '',
                available_stock: localMatch.available_stock ?? 0,
                weight_kg: parseFloat(localMatch.weight_kg) || 1.0,
                requires_meli_labeling: Boolean(localMatch.requires_meli_labeling),
            })
            return
        }

        // 2. Check match in FULL stocks
        const fullMatch = localFullStocks.find(
            (st) =>
                (st.sku && st.sku.toLowerCase() === cleaned.toLowerCase()) ||
                (st.mlm && st.mlm.toLowerCase() === cleaned.toLowerCase())
        )

        if (fullMatch) {
            const linkedProd = fullMatch.linked_product_id ? productsMap.get(fullMatch.linked_product_id) : null
            handleSelectItem({
                type: 'FULL',
                id: `full_${fullMatch.id}`,
                mlm: fullMatch.mlm,
                variation_id: fullMatch.variation_id,
                meli_sku: fullMatch.sku,
                title: fullMatch.title,
                thumbnail: fullMatch.thumbnail,
                full_available: fullMatch.full_available_quantity ?? 0,
                linked_product_id: fullMatch.linked_product_id,
                linked_product: linkedProd,
                weight_kg: linkedProd?.weight_kg || 1.0,
            })
            return
        }

        // 3. Fallback: put into search query
        setSearchQuery(cleaned)
    }

    // Add selected item to current box
    const handleAddItemToCurrentBox = () => {
        if (!selectedItem) return
        const qty = parseInt(inputQty, 10)
        if (isNaN(qty) || qty <= 0) return

        const w = parseFloat(inputWeight) || 1.0

        let itemToAdd = null
        if (selectedItem.type === 'FULL') {
            const linkedProd = selectedItem.linked_product_id ? productsMap.get(selectedItem.linked_product_id) : null

            itemToAdd = {
                temp_id: Date.now() + Math.random(),
                inventory_product_id: selectedItem.linked_product_id || null,
                sku: selectedItem.meli_sku || linkedProd?.sku || 'FULL-ITEM',
                product_name: selectedItem.title || linkedProd?.name || 'Producto FULL',
                mlm: selectedItem.mlm,
                variation_id: selectedItem.variation_id,
                quantity_sent: qty,
                unit_weight_kg: w,
                total_weight_kg: round3(qty * w),
                requires_labeling: requiresLabeling,
                linked_sku: linkedProd?.sku || null,
                linked_stock: linkedProd?.available_stock ?? null,
            }
        } else {
            itemToAdd = {
                temp_id: Date.now() + Math.random(),
                inventory_product_id: selectedItem.inventory_product_id,
                sku: selectedItem.sku,
                product_name: selectedItem.name,
                mlm: null,
                variation_id: null,
                quantity_sent: qty,
                unit_weight_kg: w,
                total_weight_kg: round3(qty * w),
                requires_labeling: requiresLabeling,
                linked_sku: selectedItem.sku,
                linked_stock: selectedItem.available_stock,
            }
        }

        // Check if item with same inventory_product_id or SKU already in this box
        const existingIdx = currentBoxItems.findIndex(
            (it) =>
                (it.inventory_product_id && it.inventory_product_id === itemToAdd.inventory_product_id) ||
                (it.mlm && it.mlm === itemToAdd.mlm)
        )

        if (existingIdx >= 0) {
            const updated = [...currentBoxItems]
            updated[existingIdx].quantity_sent += qty
            updated[existingIdx].total_weight_kg = round3(updated[existingIdx].quantity_sent * updated[existingIdx].unit_weight_kg)
            setCurrentBoxItems(updated)
        } else {
            setCurrentBoxItems([...currentBoxItems, itemToAdd])
        }

        // Reset selected item
        setSelectedItem(null)
        setInputQty(10)
    }

    // Remove item from current box
    const handleRemoveItemFromCurrentBox = (tempId) => {
        setCurrentBoxItems(currentBoxItems.filter((it) => it.temp_id !== tempId))
    }

    // SEAL / SAVE BOX (DESCONTAR STOCK INMEDIATAMENTE)
    const handleSealBox = async () => {
        if (currentBoxItems.length === 0) {
            alert('Agrega al menos un producto a la caja antes de sellarla.')
            return
        }

        if (currentBoxWeight > 30.00) {
            const proceed = confirm(
                `⚠️ ¡ADVERTENCIA DE PESO!\n\nEsta caja pesa ${currentBoxWeight.toFixed(2)} kg, lo cual supera el límite de 30 kg permitido por Mercado Libre FULL.\n\n¿Deseas sellarla de todas formas?`
            )
            if (!proceed) return
        }

        setSealingBox(true)

        const payload = {
            box_number: boxNumber,
            bulto_number: boxNumber,
            boxes_in_bulto: 1,
            capacity_kg: capacityKg,
            dimensions: '40x30x30',
            items: currentBoxItems.map((it) => ({
                inventory_product_id: it.inventory_product_id,
                sku: it.sku,
                product_name: it.product_name,
                mlm: it.mlm,
                variation_id: it.variation_id,
                quantity_sent: it.quantity_sent,
                requires_labeling: it.requires_labeling,
                unit_weight_kg: it.unit_weight_kg,
            })),
        }

        try {
            const res = await fetch(`/meli/full/envios/${currentShipment.id}/cajas`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify(payload),
            })

            const data = await res.json()

            if (res.ok && data.success) {
                // Update local shipment and boxes
                const newBox = data.box
                setBoxesList([...boxesList, newBox])
                setLastSealedBox(newBox)
                setActionFeedback({
                    type: 'success',
                    message: data.message || `¡Caja #${newBox.box_number} sellada con éxito! Stock descontado del almacén.`,
                })

                // Clear current box for next one
                setCurrentBoxItems([])
                setBoxNumber(newBox.box_number + 1)

                // Deduct stock in local products state immediately
                setLocalProducts((prev) =>
                    prev.map((p) => {
                        const itemInBox = currentBoxItems.find((it) => it.inventory_product_id === p.id)
                        if (itemInBox) {
                            return {
                                ...p,
                                available_stock: Math.max(0, (p.available_stock || 0) - itemInBox.quantity_sent),
                            }
                        }
                        return p
                    })
                )
            } else {
                alert(data.error || 'Error al sellar la caja.')
            }
        } catch (err) {
            console.error('Error saving box:', err)
            alert('Error de conexión al sellar la caja.')
        } finally {
            setSealingBox(false)
        }
    }

    // UNPACK / DELETE A SEALED BOX (REINTEGRAR STOCK)
    const handleUnpackBox = async (box) => {
        const confirmMsg = `¿Deseas desempacar la Caja #${box.box_number}?\n\nSe reintegrarán automáticamente ${box.units_count} unidades al almacén local.`
        if (!confirm(confirmMsg)) return

        try {
            const res = await fetch(`/meli/full/envios/${currentShipment.id}/cajas/${box.id}`, {
                method: 'DELETE',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
            })

            const data = await res.json()

            if (res.ok && data.success) {
                // Remove from boxes list
                setBoxesList(boxesList.filter((b) => b.id !== box.id))
                setActionFeedback({
                    type: 'info',
                    message: data.message || `Caja #${box.box_number} desempacada. Unidades reintegradas al almacén.`,
                })

                // Restore stock in local products state
                setLocalProducts((prev) =>
                    prev.map((p) => {
                        const boxItem = (box.items || []).find((it) => it.inventory_product_id === p.id)
                        if (boxItem) {
                            return {
                                ...p,
                                available_stock: (p.available_stock || 0) + boxItem.quantity_sent,
                            }
                        }
                        return p
                    })
                )
            } else {
                alert(data.error || 'Error al desempacar la caja.')
            }
        } catch (err) {
            console.error('Error unpacking box:', err)
            alert('Error de conexión al desempacar la caja.')
        }
    }

    // OPEN LINK MODAL
    const openLinkModal = (item) => {
        setItemToLink(item)
        setLinkSearchQuery('')
        setLinkModalOpen(true)
    }

    // SUBMIT LINK
    const handleConfirmLink = async (localProduct) => {
        if (!itemToLink || !localProduct) return
        setLinkingLoading(true)

        try {
            const res = await fetch('/meli/full/vincular-producto', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify({
                    inventory_product_id: localProduct.id,
                    mlm: itemToLink.mlm,
                    variation_id: itemToLink.variation_id || null,
                    meli_sku: itemToLink.meli_sku || null,
                }),
            })

            const data = await res.json()

            if (res.ok && data.success) {
                // Update local full stocks mapping
                setLocalFullStocks((prev) =>
                    prev.map((st) => (st.mlm === itemToLink.mlm ? { ...st, linked_product_id: localProduct.id } : st))
                )

                // If currently selected, update selected item
                if (selectedItem && selectedItem.mlm === itemToLink.mlm) {
                    setSelectedItem({
                        ...selectedItem,
                        linked_product_id: localProduct.id,
                        linked_product: localProduct,
                        weight_kg: parseFloat(localProduct.weight_kg) || selectedItem.weight_kg,
                    })
                    setInputWeight(parseFloat(localProduct.weight_kg) || inputWeight)
                }

                setLinkModalOpen(false)
                setActionFeedback({
                    type: 'success',
                    message: `Publicación ${itemToLink.mlm} vinculada con éxito a ${localProduct.sku}.`,
                })
            } else {
                alert(data.error || 'No se pudo vincular el producto.')
            }
        } catch (err) {
            console.error('Error linking product:', err)
            alert('Error de conexión al vincular producto.')
        } finally {
            setLinkingLoading(false)
        }
    }

    // OPEN QUICK CREATE MODAL
    const openQuickCreateModal = (fullItem) => {
        setQuickProductData({
            name: fullItem?.title || '',
            sku: fullItem?.meli_sku || (fullItem?.mlm ? `SKU-${fullItem.mlm}` : ''),
            barcode: '',
            brand: 'Joico',
            weight_kg: 0.500,
            initial_stock: 30,
            mlm: fullItem?.mlm || '',
            variation_id: fullItem?.variation_id || '',
            meli_sku: fullItem?.meli_sku || '',
        })
        setCreateModalOpen(true)
    }

    // SUBMIT QUICK CREATE
    const handleQuickCreateSubmit = async (e) => {
        e.preventDefault()
        setQuickCreating(true)

        try {
            const res = await fetch('/meli/full/alta-rapida-producto', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                },
                body: JSON.stringify(quickProductData),
            })

            const data = await res.json()

            if (res.ok && data.success) {
                const newProd = data.product

                // Add to local products
                setLocalProducts((prev) => [newProd, ...prev])

                // Link to FULL stock
                if (quickProductData.mlm) {
                    setLocalFullStocks((prev) =>
                        prev.map((st) => (st.mlm === quickProductData.mlm ? { ...st, linked_product_id: newProd.id } : st))
                    )

                    if (selectedItem && selectedItem.mlm === quickProductData.mlm) {
                        setSelectedItem({
                            ...selectedItem,
                            linked_product_id: newProd.id,
                            linked_product: newProd,
                            weight_kg: parseFloat(newProd.weight_kg) || 1.0,
                        })
                    }
                }

                setCreateModalOpen(false)
                setActionFeedback({
                    type: 'success',
                    message: `¡Producto ${newProd.sku} registrado y vinculado! Stock inicial: ${quickProductData.initial_stock} uds.`,
                })
            } else {
                alert(data.error || 'No se pudo crear el producto.')
            }
        } catch (err) {
            console.error('Error creating product:', err)
            alert('Error de conexión al registrar producto.')
        } finally {
            setQuickCreating(false)
        }
    }

    return (
        <AppShell>
            <Head title="Mesa de Empaque Cajas 30 kg MeLi FULL" />

            <div className="space-y-6 p-4 sm:p-6 lg:p-8">
                {/* TOP BAR / SESSION HEADER */}
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="rounded-xl bg-amber-400/20 px-2.5 py-1 text-xs font-black text-amber-800 dark:text-amber-300">
                                ⚖️ BÁSCULA & MESA DE EMPAQUE (1 A 1)
                            </span>
                            <span className="rounded-xl bg-indigo-100 px-2.5 py-1 text-xs font-bold text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300">
                                Límite: 30.00 kg / Caja
                            </span>
                            <span className="text-xs text-slate-500">
                                Envío: <strong className="text-slate-800 dark:text-white font-mono">{currentShipment.shipment_code}</strong>
                            </span>
                        </div>
                        <h1 className="mt-1 text-2xl font-black text-slate-900 dark:text-white">
                            Armado de Cajas de 30 kg — Mercado Libre FULL
                        </h1>
                        <p className="text-xs text-slate-500 dark:text-slate-400">
                            Empaca tus cajas una a una. Al sellar cada caja, el sistema descuenta inmediatamente las unidades de tu almacén local.
                        </p>
                    </div>

                    {/* ACTIONS & SHIPMENT SELECTOR */}
                    <div className="flex flex-wrap items-center gap-2">
                        {draftShipments.length > 1 && (
                            <select
                                value={currentShipment.id}
                                onChange={(e) => {
                                    const nextId = parseInt(e.target.value, 10)
                                    const found = draftShipments.find((s) => s.id === nextId)
                                    if (found) {
                                        setCurrentShipment(found)
                                        setBoxesList(found.boxes || [])
                                    }
                                }}
                                className="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                            >
                                {draftShipments.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.shipment_code} ({s.meli_warehouse_code}) · {s.boxes?.length || 0} cajas
                                    </option>
                                ))}
                            </select>
                        )}

                        <Link
                            href={`/meli/full/envios/${currentShipment.id}`}
                            className="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                        >
                            <span>📋</span> Ver Resumen del Envío
                        </Link>

                        <a
                            href={`/meli/full/envios/${currentShipment.id}/rotulos`}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-1.5 rounded-xl bg-slate-800 px-3.5 py-2 text-xs font-bold text-white hover:bg-slate-900 dark:bg-slate-700 dark:hover:bg-slate-600"
                        >
                            <span>🖨️</span> Rótulos Cajas MeLi
                        </a>
                    </div>
                </div>

                {/* FEEDBACK BANNER */}
                {actionFeedback && (
                    <div
                        className={`flex items-center justify-between rounded-2xl p-4 text-xs font-bold ${
                            actionFeedback.type === 'success'
                                ? 'border border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/60 dark:text-emerald-200'
                                : 'border border-blue-300 bg-blue-50 text-blue-900 dark:border-blue-900 dark:bg-blue-950/60 dark:text-blue-200'
                        }`}
                    >
                        <div className="flex items-center gap-2">
                            <span>{actionFeedback.type === 'success' ? '✅' : 'ℹ️'}</span>
                            <span>{actionFeedback.message}</span>
                        </div>
                        <button
                            type="button"
                            onClick={() => setActionFeedback(null)}
                            className="text-xs opacity-60 hover:opacity-100"
                        >
                            ✕
                        </button>
                    </div>
                )}

                {/* MAIN GRID: PACKING TABLE (LEFT) + SEALED BOXES HISTORY (RIGHT) */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                    {/* LEFT COLUMN: ACTIVE PACKING STATION (8 OF 12 COLS) */}
                    <div className="space-y-6 lg:col-span-8">
                        {/* 1. ACTIVE BOX CARD & LIVE SCALE */}
                        <div className="rounded-3xl border-2 border-indigo-500/40 bg-white p-5 shadow-sm dark:border-indigo-500/30 dark:bg-neutral-900">
                            {/* BOX HEADER & WEIGHT GAUGE */}
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between border-b border-slate-100 pb-4 dark:border-neutral-800">
                                <div className="flex items-center gap-3">
                                    <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-600 text-xl font-black text-white shadow-sm">
                                        📦
                                    </div>
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <h2 className="text-lg font-black text-slate-900 dark:text-white">
                                                Caja #{boxNumber} en la Mesa
                                            </h2>
                                            <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-black text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300">
                                                EMPACANDO AHORA
                                            </span>
                                        </div>
                                        <p className="text-xs text-slate-500 dark:text-slate-400">
                                            Destino: <strong>{currentShipment.meli_warehouse_code}</strong> ({warehouses[currentShipment.meli_warehouse_code] || 'CEDIS MeLi'})
                                        </p>
                                    </div>
                                </div>

                                {/* LIVE SCALE DISPLAY */}
                                <div className="flex items-baseline gap-3 rounded-2xl bg-slate-50 px-4 py-2.5 font-mono dark:bg-neutral-800/80">
                                    <div>
                                        <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block font-sans">
                                            ⚖️ Peso Actual
                                        </span>
                                        <span
                                            className={`text-2xl font-black ${
                                                isWeightExceeded
                                                    ? 'text-rose-600 animate-pulse'
                                                    : isWeightNear
                                                      ? 'text-amber-600'
                                                      : 'text-emerald-600 dark:text-emerald-400'
                                            }`}
                                        >
                                            {currentBoxWeight.toFixed(2)}
                                        </span>
                                        <span className="text-xs text-slate-500 ml-1">/ {capacityKg.toFixed(2)} kg</span>
                                    </div>
                                    <div className="border-l border-slate-200 pl-3 dark:border-neutral-700">
                                        <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400 block font-sans">
                                            Piezas
                                        </span>
                                        <span className="text-2xl font-black text-slate-900 dark:text-white">
                                            {currentBoxUnits}
                                        </span>
                                        <span className="text-xs text-slate-500 ml-1">uds</span>
                                    </div>
                                </div>
                            </div>

                            {/* PROGRESS BAR */}
                            <div className="mt-4">
                                <div className="flex justify-between text-xs font-semibold text-slate-500 mb-1">
                                    <span>
                                        Capacidad ocupada: <strong>{((currentBoxWeight / capacityKg) * 100).toFixed(1)}%</strong>
                                    </span>
                                    <span
                                        className={
                                            isWeightExceeded
                                                ? 'text-rose-600 font-bold'
                                                : isWeightNear
                                                  ? 'text-amber-600 font-bold'
                                                  : 'text-slate-500'
                                        }
                                    >
                                        {isWeightExceeded
                                            ? `⚠️ Excedido por +${(currentBoxWeight - capacityKg).toFixed(2)} kg`
                                            : `Espacio disponible: ${weightRemaining.toFixed(2)} kg`}
                                    </span>
                                </div>
                                <div className="h-3 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-neutral-800">
                                    <div
                                        className={`h-full transition-all duration-300 ${
                                            isWeightExceeded
                                                ? 'bg-rose-500'
                                                : isWeightNear
                                                  ? 'bg-amber-500'
                                                  : 'bg-emerald-500'
                                        }`}
                                        style={{ width: `${Math.min(100, (currentBoxWeight / capacityKg) * 100)}%` }}
                                    />
                                </div>
                            </div>

                            {/* 2. SEARCH / SCAN PRODUCT SECTION */}
                            <div className="mt-6 rounded-2xl border border-slate-200 bg-slate-50/70 p-4 dark:border-neutral-800 dark:bg-neutral-800/40">
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <span className="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                                        <span>🔍</span> Buscar o Escanear Producto
                                    </span>

                                    {/* FILTER TABS */}
                                    <div className="flex items-center gap-1 text-xs">
                                        <button
                                            type="button"
                                            onClick={() => setSearchTab('ALL')}
                                            className={`rounded-lg px-2.5 py-1 font-bold ${
                                                searchTab === 'ALL'
                                                    ? 'bg-indigo-600 text-white'
                                                    : 'bg-white text-slate-600 hover:bg-slate-100 dark:bg-neutral-700 dark:text-slate-300'
                                            }`}
                                        >
                                            Todos
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setSearchTab('FULL')}
                                            className={`rounded-lg px-2.5 py-1 font-bold ${
                                                searchTab === 'FULL'
                                                    ? 'bg-amber-500 text-white'
                                                    : 'bg-white text-slate-600 hover:bg-slate-100 dark:bg-neutral-700 dark:text-slate-300'
                                            }`}
                                        >
                                            ⚡ Solo FULL ({localFullStocks.length})
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setSearchTab('LOCAL')}
                                            className={`rounded-lg px-2.5 py-1 font-bold ${
                                                searchTab === 'LOCAL'
                                                    ? 'bg-blue-600 text-white'
                                                    : 'bg-white text-slate-600 hover:bg-slate-100 dark:bg-neutral-700 dark:text-slate-300'
                                            }`}
                                        >
                                            📦 Solo Almacén ({localProducts.length})
                                        </button>
                                    </div>
                                </div>

                                {/* SEARCH INPUT & CAMERA SCANNER BUTTON */}
                                <div className="mt-3 flex gap-2">
                                    <div className="relative flex-1">
                                        <input
                                            type="text"
                                            value={searchQuery}
                                            onChange={(e) => setSearchQuery(e.target.value)}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter' && searchResults.length > 0) {
                                                    e.preventDefault()
                                                    handleSelectItem(searchResults[0])
                                                }
                                            }}
                                            placeholder="Escanear código de barras o escribir Título MeLi, SKU o Nombre..."
                                            className="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-xs text-slate-900 placeholder:text-slate-400 focus:border-indigo-600 focus:ring-1 focus:ring-indigo-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                                        />
                                        {searchQuery && (
                                            <button
                                                type="button"
                                                onClick={() => setSearchQuery('')}
                                                className="absolute right-3 top-2.5 text-xs text-slate-400 hover:text-slate-600"
                                            >
                                                ✕
                                            </button>
                                        )}
                                    </div>

                                    <button
                                        type="button"
                                        onClick={() => setIsCameraOpen(true)}
                                        className="inline-flex items-center gap-1.5 rounded-xl border border-indigo-200 bg-indigo-50 px-3.5 py-2.5 text-xs font-bold text-indigo-700 hover:bg-indigo-100 dark:border-indigo-900 dark:bg-indigo-950 dark:text-indigo-300"
                                        title="Escanear con Cámara"
                                    >
                                        <span>📷</span> Cámara
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() => openQuickCreateModal(null)}
                                        className="inline-flex items-center gap-1.5 rounded-xl border border-emerald-300 bg-emerald-50 px-3.5 py-2.5 text-xs font-bold text-emerald-800 hover:bg-emerald-100 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300"
                                    >
                                        <span>⚡</span> Alta Rápida
                                    </button>
                                </div>

                                {/* SEARCH RESULTS DROPDOWN */}
                                {searchResults.length > 0 && (
                                    <div className="mt-2 max-h-64 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-lg dark:border-neutral-700 dark:bg-neutral-900">
                                        <div className="divide-y divide-slate-100 text-xs dark:divide-neutral-800">
                                            {searchResults.map((it) => (
                                                <div
                                                    key={it.id}
                                                    onClick={() => handleSelectItem(it)}
                                                    className="flex cursor-pointer items-center justify-between p-3 hover:bg-indigo-50/60 dark:hover:bg-neutral-800"
                                                >
                                                    <div className="flex items-center gap-3">
                                                        {it.thumbnail ? (
                                                            <img
                                                                src={it.thumbnail}
                                                                alt=""
                                                                className="h-10 w-10 rounded-lg object-cover border border-slate-200"
                                                            />
                                                        ) : (
                                                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-base dark:bg-neutral-800">
                                                                {it.type === 'FULL' ? '⚡' : '📦'}
                                                            </div>
                                                        )}
                                                        <div>
                                                            <div className="flex items-center gap-1.5">
                                                                {it.type === 'FULL' ? (
                                                                    <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[9px] font-black text-amber-900 dark:bg-amber-950 dark:text-amber-300">
                                                                        ⚡ FULL ({it.mlm})
                                                                    </span>
                                                                ) : (
                                                                    <span className="rounded bg-blue-100 px-1.5 py-0.5 text-[9px] font-black text-blue-900 dark:bg-blue-950 dark:text-blue-300">
                                                                        📦 ALMACÉN
                                                                    </span>
                                                                )}
                                                                <span className="font-mono font-bold text-indigo-600 dark:text-indigo-400">
                                                                    {it.sku || it.meli_sku || it.barcode}
                                                                </span>
                                                            </div>
                                                            <p className="font-semibold text-slate-800 dark:text-slate-200 line-clamp-1">
                                                                {it.title || it.name}
                                                            </p>
                                                            {it.type === 'FULL' && (
                                                                <div className="flex items-center gap-2 text-[10px] text-slate-400">
                                                                    {it.linked_product ? (
                                                                        <span className="text-emerald-600 font-bold dark:text-emerald-400">
                                                                            ✓ Vinculado: {it.linked_product.sku} (Stock: {it.linked_product.available_stock} uds)
                                                                        </span>
                                                                    ) : (
                                                                        <span className="text-amber-600 font-bold dark:text-amber-400">
                                                                            ⚠️ Sin vincular a catálogo local
                                                                        </span>
                                                                    )}
                                                                    <span>·</span>
                                                                    <span>Stock MeLi: {it.full_available} uds</span>
                                                                </div>
                                                            )}
                                                            {it.type === 'LOCAL' && (
                                                                <div className="text-[10px] text-slate-400">
                                                                    Stock Almacén: <strong className="text-slate-700 dark:text-slate-300">{it.available_stock} uds</strong> · Peso: {it.weight_kg} kg
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>

                                                    <button
                                                        type="button"
                                                        className="rounded-lg bg-indigo-50 px-2.5 py-1 text-xs font-bold text-indigo-700 hover:bg-indigo-100 dark:bg-neutral-800 dark:text-indigo-300"
                                                    >
                                                        Seleccionar
                                                    </button>
                                                </div>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {/* SELECTED ITEM CARD / ADD TO BOX CONFIG */}
                                {selectedItem && (
                                    <div className="mt-4 rounded-xl border-2 border-indigo-500 bg-white p-4 shadow-sm dark:bg-neutral-900">
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div className="flex items-start gap-3">
                                                {selectedItem.thumbnail ? (
                                                    <img
                                                        src={selectedItem.thumbnail}
                                                        alt=""
                                                        className="h-12 w-12 rounded-xl object-cover border border-slate-200"
                                                    />
                                                ) : (
                                                    <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-50 text-xl font-bold text-indigo-600 dark:bg-neutral-800">
                                                        {selectedItem.type === 'FULL' ? '⚡' : '📦'}
                                                    </div>
                                                )}
                                                <div>
                                                    <div className="flex flex-wrap items-center gap-1.5">
                                                        <span className="rounded bg-indigo-100 px-2 py-0.5 text-[10px] font-black text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300">
                                                            {selectedItem.type === 'FULL' ? `⚡ Publicación FULL (${selectedItem.mlm})` : '📦 Almacén Local'}
                                                        </span>
                                                        <span className="font-mono text-xs font-black text-slate-900 dark:text-white">
                                                            {selectedItem.sku || selectedItem.meli_sku}
                                                        </span>
                                                    </div>
                                                    <h3 className="mt-1 text-sm font-bold text-slate-900 dark:text-white">
                                                        {selectedItem.title || selectedItem.name}
                                                    </h3>

                                                    {/* LINKING STATUS & ACTION BUTTONS */}
                                                    {selectedItem.type === 'FULL' && (
                                                        <div className="mt-2">
                                                            {selectedItem.linked_product ? (
                                                                <div className="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                                                    <span>✓</span> Descuenta de almacén:{' '}
                                                                    <strong className="underline font-mono">{selectedItem.linked_product.sku}</strong>{' '}
                                                                    ({selectedItem.linked_product.name}) · Disp: {selectedItem.linked_product.available_stock} uds
                                                                </div>
                                                            ) : (
                                                                <div className="flex flex-wrap items-center gap-2 rounded-xl bg-amber-50 p-2.5 text-xs text-amber-900 dark:bg-amber-950/50 dark:text-amber-200">
                                                                    <span>⚠️ Esta publicación de FULL no está vinculada a tu catálogo local.</span>
                                                                    <div className="flex items-center gap-2 mt-1">
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => openLinkModal(selectedItem)}
                                                                            className="rounded-lg bg-indigo-600 px-2.5 py-1 font-bold text-white hover:bg-indigo-700"
                                                                        >
                                                                            🔗 Vincular a producto de almacén
                                                                        </button>
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => openQuickCreateModal(selectedItem)}
                                                                            className="rounded-lg bg-emerald-600 px-2.5 py-1 font-bold text-white hover:bg-emerald-700"
                                                                        >
                                                                            ⚡ Dar de alta en almacén (1 clic)
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>

                                            <button
                                                type="button"
                                                onClick={() => setSelectedItem(null)}
                                                className="text-xs text-slate-400 hover:text-slate-600"
                                            >
                                                ✕ Cambiar producto
                                            </button>
                                        </div>

                                        {/* INPUTS: QTY & WEIGHT & ACTION */}
                                        <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-4 border-t border-slate-100 pt-3 dark:border-neutral-800">
                                            <div>
                                                <label className="text-[11px] font-bold uppercase text-slate-500 block mb-1">
                                                    Cantidad a meter
                                                </label>
                                                <div className="flex items-center gap-1">
                                                    <input
                                                        type="number"
                                                        min="1"
                                                        value={inputQty}
                                                        onChange={(e) => setInputQty(e.target.value)}
                                                        className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                                    />
                                                </div>
                                                <div className="flex gap-1 mt-1">
                                                    {[5, 10, 15, 20].map((n) => (
                                                        <button
                                                            key={n}
                                                            type="button"
                                                            onClick={() => setInputQty(n)}
                                                            className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-600 hover:bg-slate-200 dark:bg-neutral-800 dark:text-slate-300"
                                                        >
                                                            +{n}
                                                        </button>
                                                    ))}
                                                </div>
                                            </div>

                                            <div>
                                                <label className="text-[11px] font-bold uppercase text-slate-500 block mb-1">
                                                    Peso unitario (kg)
                                                </label>
                                                <input
                                                    type="number"
                                                    step="0.05"
                                                    min="0.01"
                                                    value={inputWeight}
                                                    onChange={(e) => setInputWeight(e.target.value)}
                                                    className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-bold text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                                />
                                                <span className="text-[10px] text-slate-400 mt-1 block">
                                                    Suma a la caja: +{(parseFloat(inputQty || 0) * parseFloat(inputWeight || 0)).toFixed(2)} kg
                                                </span>
                                            </div>

                                            <div className="flex items-center">
                                                <label className="flex items-center gap-2 cursor-pointer mt-3">
                                                    <input
                                                        type="checkbox"
                                                        checked={requiresLabeling}
                                                        onChange={(e) => setRequiresLabeling(e.target.checked)}
                                                        className="rounded text-indigo-600 focus:ring-indigo-500"
                                                    />
                                                    <span className="text-xs font-bold text-slate-700 dark:text-slate-300">
                                                        🏷️ Requiere sticker MeLi
                                                    </span>
                                                </label>
                                            </div>

                                            <div className="flex items-end">
                                                <button
                                                    type="button"
                                                    onClick={handleAddItemToCurrentBox}
                                                    className="w-full rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-black text-white shadow-sm hover:bg-indigo-700 transition-colors"
                                                >
                                                    ➕ Poner en la Caja #{boxNumber}
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>

                            {/* 3. CURRENT BOX ITEMS TABLE */}
                            <div className="mt-6">
                                <div className="flex items-center justify-between mb-3">
                                    <h3 className="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                                        <span>📦</span> Contenido actual de la Caja #{boxNumber} ({currentBoxItems.length} tipos de producto)
                                    </h3>
                                    {currentBoxItems.length > 0 && (
                                        <button
                                            type="button"
                                            onClick={() => setCurrentBoxItems([])}
                                            className="text-xs text-rose-600 hover:underline"
                                        >
                                            Vaciar caja actual
                                        </button>
                                    )}
                                </div>

                                {currentBoxItems.length === 0 ? (
                                    <div className="rounded-2xl border-2 border-dashed border-slate-200 p-8 text-center dark:border-neutral-800">
                                        <span className="text-3xl">📥</span>
                                        <p className="mt-2 text-xs font-bold text-slate-600 dark:text-slate-300">
                                            La Caja #{boxNumber} está vacía en este momento.
                                        </p>
                                        <p className="text-[11px] text-slate-400">
                                            Escanea un código de barras o busca un producto arriba para agregarlo.
                                        </p>
                                    </div>
                                ) : (
                                    <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                                        <table className="w-full text-left text-xs">
                                            <thead className="bg-slate-50 text-[11px] font-bold uppercase text-slate-500 dark:bg-neutral-800 dark:text-slate-400">
                                                <tr>
                                                    <th className="py-2.5 px-3">SKU / MeLi</th>
                                                    <th className="py-2.5 px-3">Producto</th>
                                                    <th className="py-2.5 px-3 text-center">Cantidad</th>
                                                    <th className="py-2.5 px-3 text-center">Peso Unit.</th>
                                                    <th className="py-2.5 px-3 text-center">Peso Total</th>
                                                    <th className="py-2.5 px-3">Descuento Almacén</th>
                                                    <th className="py-2.5 px-3 text-right">Quitar</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100 font-medium dark:divide-neutral-800">
                                                {currentBoxItems.map((it) => (
                                                    <tr key={it.temp_id} className="hover:bg-slate-50/50 dark:hover:bg-neutral-800/40">
                                                        <td className="py-2.5 px-3 font-mono font-bold text-indigo-600 dark:text-indigo-400">
                                                            <div>{it.sku}</div>
                                                            {it.mlm && <span className="text-[10px] text-slate-400 font-normal">{it.mlm}</span>}
                                                        </td>
                                                        <td className="py-2.5 px-3 font-semibold text-slate-800 dark:text-slate-200">
                                                            <div className="flex items-center gap-1.5">
                                                                <span>{it.product_name}</span>
                                                                {it.requires_labeling && (
                                                                    <span className="rounded bg-indigo-100 px-1.5 py-0.5 text-[9px] font-black text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300">
                                                                        🏷️ Sticker
                                                                    </span>
                                                                )}
                                                            </div>
                                                        </td>
                                                        <td className="py-2.5 px-3 text-center font-bold text-slate-900 dark:text-white">
                                                            {it.quantity_sent} uds
                                                        </td>
                                                        <td className="py-2.5 px-3 text-center text-slate-500 font-mono">
                                                            {it.unit_weight_kg} kg
                                                        </td>
                                                        <td className="py-2.5 px-3 text-center font-mono font-bold text-indigo-600 dark:text-indigo-400">
                                                            {it.total_weight_kg} kg
                                                        </td>
                                                        <td className="py-2.5 px-3">
                                                            {it.inventory_product_id ? (
                                                                <span className="rounded bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                                                                    ✓ Descontará {it.quantity_sent} de {it.linked_sku}
                                                                </span>
                                                            ) : (
                                                                <span className="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-500 dark:bg-neutral-800 dark:text-slate-400">
                                                                    ⚪ Sin control local
                                                                </span>
                                                            )}
                                                        </td>
                                                        <td className="py-2.5 px-3 text-right">
                                                            <button
                                                                type="button"
                                                                onClick={() => handleRemoveItemFromCurrentBox(it.temp_id)}
                                                                className="rounded-lg p-1 text-slate-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950"
                                                            >
                                                                ❌
                                                            </button>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </div>

                            {/* 4. MAIN ACTION: SEAL BOX & DEDUCT STOCK */}
                            <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-t border-slate-100 pt-4 dark:border-neutral-800">
                                <div>
                                    <span className="text-xs font-bold text-slate-700 dark:text-slate-300">
                                        ¿Terminaste de empacar esta caja?
                                    </span>
                                    <p className="text-[11px] text-slate-400">
                                        Al sellar, se descontarán las unidades del almacén local y podrás empezar la siguiente caja.
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    disabled={sealingBox || currentBoxItems.length === 0}
                                    onClick={handleSealBox}
                                    className={`inline-flex items-center gap-2 rounded-2xl px-6 py-3.5 text-sm font-black text-white shadow-md transition-all ${
                                        currentBoxItems.length === 0
                                            ? 'bg-slate-300 cursor-not-allowed dark:bg-neutral-800'
                                            : isWeightExceeded
                                              ? 'bg-amber-600 hover:bg-amber-700'
                                              : 'bg-emerald-600 hover:bg-emerald-700 hover:scale-[1.01]'
                                    }`}
                                >
                                    <span>📦</span>
                                    {sealingBox
                                        ? 'Sellando y Descontando Stock...'
                                        : `CERRAR Y SELLAR CAJA #${boxNumber} (${currentBoxWeight.toFixed(2)} kg)`}
                                </button>
                            </div>
                        </div>
                    </div>

                    {/* RIGHT COLUMN: SEALED BOXES IN THIS SHIPMENT (4 OF 12 COLS) */}
                    <div className="space-y-6 lg:col-span-4">
                        <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="flex items-center justify-between border-b border-slate-100 pb-3 dark:border-neutral-800">
                                <h3 className="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
                                    <span>📋</span> Cajas Cerradas en este Envío ({boxesList.length})
                                </h3>
                                <span className="font-mono text-xs font-bold text-indigo-600 dark:text-indigo-400">
                                    Total: {boxesList.reduce((acc, b) => acc + (parseFloat(b.weight_kg) || 0), 0).toFixed(2)} kg
                                </span>
                            </div>

                            {boxesList.length === 0 ? (
                                <div className="p-6 text-center text-xs text-slate-400">
                                    <span className="text-2xl block mb-2">📦</span>
                                    Aún no hay cajas cerradas en este envío. Empaca y sella la Caja #1 a la izquierda.
                                </div>
                            ) : (
                                <div className="mt-4 space-y-3">
                                    {boxesList.map((box) => {
                                        const bWeight = parseFloat(box.weight_kg) || 0
                                        const bOver = bWeight > (parseFloat(box.capacity_kg) || 30.00)

                                        return (
                                            <div
                                                key={box.id}
                                                className="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 dark:border-neutral-800 dark:bg-neutral-800/40"
                                            >
                                                <div className="flex items-start justify-between">
                                                    <div>
                                                        <div className="flex items-center gap-2">
                                                            <h4 className="text-xs font-black text-slate-900 dark:text-white">
                                                                CAJA #{box.box_number}
                                                            </h4>
                                                            <span className="font-mono text-[10px] text-slate-400">
                                                                ({box.box_code})
                                                            </span>
                                                        </div>
                                                        <div className="mt-1 flex items-center gap-2">
                                                            <span
                                                                className={`rounded-full px-2 py-0.5 text-[10px] font-black ${
                                                                    bOver
                                                                        ? 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300'
                                                                        : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                                                                }`}
                                                            >
                                                                ⚖️ {bWeight.toFixed(2)} kg
                                                            </span>
                                                            <span className="rounded-full bg-slate-200 px-2 py-0.5 text-[10px] font-bold text-slate-700 dark:bg-neutral-700 dark:text-slate-300">
                                                                {box.units_count} piezas
                                                            </span>
                                                        </div>
                                                    </div>

                                                    <div className="flex flex-col gap-1 items-end">
                                                        <a
                                                            href={`/meli/full/envios/${currentShipment.id}/cajas/${box.id}/rotulo`}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="inline-flex items-center gap-1 rounded-lg border border-slate-300 bg-white px-2 py-1 text-[11px] font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                                                        >
                                                            <span>🖨️</span> Rótulo
                                                        </a>
                                                        <button
                                                            type="button"
                                                            onClick={() => handleUnpackBox(box)}
                                                            className="text-[10px] text-rose-600 hover:underline font-semibold"
                                                        >
                                                            ↩️ Desempacar
                                                        </button>
                                                    </div>
                                                </div>

                                                {/* ITEMS PREVIEW */}
                                                <div className="mt-2.5 border-t border-slate-200/60 pt-2 dark:border-neutral-700/60 text-[11px]">
                                                    <span className="text-slate-400 font-semibold block text-[10px] uppercase">
                                                        Productos dentro:
                                                    </span>
                                                    <ul className="mt-1 space-y-0.5 text-slate-600 dark:text-slate-300">
                                                        {(box.items || []).map((it, idx) => (
                                                            <li key={idx} className="flex justify-between">
                                                                <span className="truncate max-w-[180px] font-medium">{it.product_name}</span>
                                                                <span className="font-bold font-mono ml-2">{it.quantity_sent} uds</span>
                                                            </li>
                                                        ))}
                                                    </ul>
                                                </div>
                                            </div>
                                        )
                                    })}
                                </div>
                            )}

                            {/* DISPATCH CTA IF READY */}
                            {boxesList.length > 0 && (
                                <div className="mt-6 border-t border-slate-100 pt-4 dark:border-neutral-800">
                                    <Link
                                        href={`/meli/full/envios/${currentShipment.id}`}
                                        className="w-full inline-flex justify-center items-center gap-2 rounded-2xl bg-indigo-600 px-4 py-3 text-xs font-black text-white hover:bg-indigo-700 shadow-sm"
                                    >
                                        <span>🚚</span> Finalizar y Preparar Guía ENVIA
                                    </Link>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {/* CAMERA SCANNER MODAL */}
            <CameraBarcodeScanner
                isOpen={isCameraOpen}
                onClose={() => setIsCameraOpen(false)}
                onScan={handleBarcodeScanned}
                title="Escanear Producto para Caja de 30 kg"
            />

            {/* MODAL 1: LINK FULL ITEM TO LOCAL INVENTORY PRODUCT */}
            {linkModalOpen && itemToLink && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                    <div className="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl dark:bg-neutral-900 border border-slate-200 dark:border-neutral-800">
                        <div className="flex items-center justify-between border-b border-slate-100 pb-3 dark:border-neutral-800">
                            <div>
                                <h3 className="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
                                    <span>🔗</span> Vincular Publicación FULL a Almacén Local
                                </h3>
                                <p className="text-xs text-slate-500 mt-0.5">
                                    Selecciona qué producto de tu catálogo corresponde a esta publicación para descontar su stock.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setLinkModalOpen(false)}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                ✕
                            </button>
                        </div>

                        {/* TARGET FULL PUBLICATION SUMMARY */}
                        <div className="mt-4 rounded-xl bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                            <span className="font-bold">Publicación FULL:</span> {itemToLink.title}
                            <div className="mt-1 flex items-center gap-2 font-mono text-[11px]">
                                <span>MLM: <strong>{itemToLink.mlm}</strong></span>
                                <span>·</span>
                                <span>SKU MeLi: <strong>{itemToLink.meli_sku || 'Sin SKU'}</strong></span>
                            </div>
                        </div>

                        {/* SEARCH LOCAL PRODUCTS */}
                        <div className="mt-4">
                            <label className="text-[11px] font-bold uppercase text-slate-500 block mb-1">
                                Buscar producto en tu almacén local:
                            </label>
                            <input
                                type="text"
                                value={linkSearchQuery}
                                onChange={(e) => setLinkSearchQuery(e.target.value)}
                                placeholder="Escribe el nombre o SKU de tu almacén..."
                                className="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                            />
                        </div>

                        {/* LIST OF CANDIDATE LOCAL PRODUCTS */}
                        <div className="mt-3 max-h-56 overflow-y-auto divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-neutral-800 dark:border-neutral-700">
                            {localProducts
                                .filter((p) => {
                                    if (!linkSearchQuery.trim()) return true
                                    const q = linkSearchQuery.toLowerCase()
                                    return (
                                        (p.sku && p.sku.toLowerCase().includes(q)) ||
                                        (p.name && p.name.toLowerCase().includes(q))
                                    )
                                })
                                .slice(0, 15)
                                .map((p) => (
                                    <div
                                        key={p.id}
                                        onClick={() => handleConfirmLink(p)}
                                        className="flex cursor-pointer items-center justify-between p-3 hover:bg-indigo-50/60 dark:hover:bg-neutral-800 text-xs"
                                    >
                                        <div>
                                            <span className="font-mono font-bold text-indigo-600 dark:text-indigo-400">
                                                {p.sku}
                                            </span>
                                            <p className="font-semibold text-slate-800 dark:text-slate-200">
                                                {p.name}
                                            </p>
                                            <span className="text-[10px] text-slate-400">
                                                Stock Almacén: <strong>{p.available_stock} uds</strong> · Peso: {p.weight_kg} kg
                                            </span>
                                        </div>

                                        <button
                                            type="button"
                                            disabled={linkingLoading}
                                            className="rounded-lg bg-indigo-600 px-3 py-1 font-bold text-white hover:bg-indigo-700"
                                        >
                                            {linkingLoading ? 'Vinculando...' : 'Elegir'}
                                        </button>
                                    </div>
                                ))}
                        </div>

                        <div className="mt-4 flex justify-end">
                            <button
                                type="button"
                                onClick={() => setLinkModalOpen(false)}
                                className="rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                            >
                                Cancelar
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL 2: QUICK CREATE LOCAL INVENTORY PRODUCT */}
            {createModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                    <div className="w-full max-w-lg rounded-3xl bg-white p-6 shadow-2xl dark:bg-neutral-900 border border-slate-200 dark:border-neutral-800">
                        <div className="flex items-center justify-between border-b border-slate-100 pb-3 dark:border-neutral-800">
                            <div>
                                <h3 className="text-sm font-black text-slate-900 dark:text-white flex items-center gap-2">
                                    <span>⚡</span> Alta Rápida de Producto en Almacén
                                </h3>
                                <p className="text-xs text-slate-500 mt-0.5">
                                    Regístralo en tu inventario en segundos para descontar su stock físico.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setCreateModalOpen(false)}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                ✕
                            </button>
                        </div>

                        <form onSubmit={handleQuickCreateSubmit} className="mt-4 space-y-3 text-xs">
                            <div>
                                <label className="text-[11px] font-bold uppercase text-slate-500 block mb-1">
                                    Nombre del Producto *
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={quickProductData.name}
                                    onChange={(e) => setQuickProductData({ ...quickProductData, name: e.target.value })}
                                    className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="text-[11px] font-bold uppercase text-slate-500 block mb-1">
                                        SKU Local *
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        value={quickProductData.sku}
                                        onChange={(e) => setQuickProductData({ ...quickProductData, sku: e.target.value })}
                                        className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 font-mono text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="text-[11px] font-bold uppercase text-slate-500 block mb-1">
                                        Código de Barras (Opcional)
                                    </label>
                                    <input
                                        type="text"
                                        value={quickProductData.barcode}
                                        onChange={(e) => setQuickProductData({ ...quickProductData, barcode: e.target.value })}
                                        className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 font-mono text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-3 gap-3">
                                <div>
                                    <label className="text-[11px] font-bold uppercase text-slate-500 block mb-1">
                                        Marca
                                    </label>
                                    <input
                                        type="text"
                                        value={quickProductData.brand}
                                        onChange={(e) => setQuickProductData({ ...quickProductData, brand: e.target.value })}
                                        className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="text-[11px] font-bold uppercase text-slate-500 block mb-1">
                                        Peso (kg) *
                                    </label>
                                    <input
                                        type="number"
                                        step="0.05"
                                        min="0.01"
                                        required
                                        value={quickProductData.weight_kg}
                                        onChange={(e) => setQuickProductData({ ...quickProductData, weight_kg: parseFloat(e.target.value) || 1.0 })}
                                        className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="text-[11px] font-bold uppercase text-slate-500 block mb-1">
                                        Stock Físico Inicial
                                    </label>
                                    <input
                                        type="number"
                                        min="0"
                                        value={quickProductData.initial_stock}
                                        onChange={(e) => setQuickProductData({ ...quickProductData, initial_stock: parseInt(e.target.value, 10) || 0 })}
                                        className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white font-bold"
                                    />
                                </div>
                            </div>

                            {quickProductData.mlm && (
                                <div className="rounded-xl bg-slate-50 p-2.5 text-[11px] text-slate-600 dark:bg-neutral-800 dark:text-slate-400">
                                    🔗 Se vinculará automáticamente a la publicación FULL <strong>{quickProductData.mlm}</strong>.
                                </div>
                            )}

                            <div className="mt-4 flex justify-end gap-2 pt-2 border-t border-slate-100 dark:border-neutral-800">
                                <button
                                    type="button"
                                    onClick={() => setCreateModalOpen(false)}
                                    className="rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    disabled={quickCreating}
                                    className="rounded-xl bg-emerald-600 px-5 py-2 text-xs font-black text-white hover:bg-emerald-700 shadow-sm"
                                >
                                    {quickCreating ? 'Guardando...' : '⚡ Guardar y Dar de Alta'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AppShell>
    )
}

function round3(num) {
    return Math.round((num + Number.EPSILON) * 1000) / 1000
}
