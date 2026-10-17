import AppShell from '@/Components/layout/AppShell'
import CameraBarcodeScanner from '@/Components/CameraBarcodeScanner'
import { Head, Link, router } from '@inertiajs/react'
import axios from 'axios'
import { useEffect, useMemo, useRef, useState } from 'react'

// Simple audio beep using Web Audio API for mobile/desktop feedback
const playBeep = (type = 'success') => {
    try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)()
        const osc = audioCtx.createOscillator()
        const gain = audioCtx.createGain()
        osc.connect(gain)
        gain.connect(audioCtx.destination)

        if (type === 'success') {
            osc.frequency.setValueAtTime(880, audioCtx.currentTime) // A5
            gain.gain.setValueAtTime(0.15, audioCtx.currentTime)
            osc.start()
            osc.stop(audioCtx.currentTime + 0.12)
        } else if (type === 'warning') {
            osc.frequency.setValueAtTime(440, audioCtx.currentTime)
            gain.gain.setValueAtTime(0.2, audioCtx.currentTime)
            osc.start()
            osc.stop(audioCtx.currentTime + 0.25)
        }
    } catch {
        // AudioContext might be blocked until user gesture, ignore
    }
}

const extractShelfCode = (code) => {
    if (!code) return ''
    const match = String(code).trim().match(/^([A-Za-z]+[0-9]+)/)
    return match ? match[1].toUpperCase() : String(code).trim().toUpperCase()
}

export default function ShelfAuditCreate({
    shelves = [],
    brands = [],
    initialAuditType = 'shelf',
    preselectedShelfCode,
    preselectedBrand,
    initialProducts = [],
    resumeAudit = null,
}) {
    // Current wizard step: 'counting' (Paso 1: Escaneo y Conteo) | 'review' (Paso 2: Lista y Revisión de Diferencias)
    const [currentStep, setCurrentStep] = useState('counting')

    // Mode: 'shelf' | 'brand'
    const [auditType, setAuditType] = useState(
        resumeAudit?.audit_type || initialAuditType || 'shelf'
    )

    // Selected Shelf & Brand
    const [selectedShelfCode, setSelectedShelfCode] = useState(
        resumeAudit?.shelf_code || preselectedShelfCode || (shelves[0]?.shelf_code ?? '')
    )
    const [selectedBrand, setSelectedBrand] = useState(
        resumeAudit?.brand_name || preselectedBrand || (brands[0]?.brand ?? '')
    )

    const [isLoadingProducts, setIsLoadingProducts] = useState(false)
    const [auditNotes, setAuditNotes] = useState(resumeAudit?.notes || '')
    const [isSubmitting, setIsSubmitting] = useState(false)

    // Items list for the audit
    const [items, setItems] = useState(() => {
        return (initialProducts || []).map((p) => {
            const counted = Number(p.counted_quantity || 0)
            const wasScanned = Boolean(p.scanned || counted > 0)
            return {
                product_id: p.product_id || p.id,
                sku: p.sku,
                barcode: p.barcode,
                barcode_secondary: p.barcode_secondary,
                name: p.name,
                brand: p.brand,
                primary_location: p.primary_location || p.primary_location_code,
                system_quantity: Number(p.system_quantity || p.system_stock || 0),
                location_breakdown: p.location_breakdown || '',
                counted_quantity: counted,
                scanned: wasScanned,
                last_scanned_at: wasScanned ? 1 : 0,
                notes: p.notes || '',
                is_extra: Boolean(p.is_extra),
            }
        })
    })

    // Feedback message
    const [scanFeedback, setScanFeedback] = useState(null)
    const [highlightedProductId, setHighlightedProductId] = useState(null)

    // Barcode scanner gun input
    const [gunBarcode, setGunBarcode] = useState('')
    const gunInputRef = useRef(null)

    // Mismatch warning modal when scanning a product that does not belong to this shelf or brand
    const [mismatchModal, setMismatchModal] = useState(null)

    // Camera scanner modal
    const [isCameraOpen, setIsCameraOpen] = useState(false)
    const [keepCameraAfterConfirm, setKeepCameraAfterConfirm] = useState(false)

    // Modal de Cantidad al escanear (Requisito clave: ventana con cantidad en sistema y editable)
    const [scanModalProduct, setScanModalProduct] = useState(null)
    const [modalCount, setModalCount] = useState(1)
    const [modalNotes, setModalNotes] = useState('')
    const modalInputRef = useRef(null)

    // Manual search catalog
    const [catalogSearch, setCatalogSearch] = useState('')
    const [catalogResults, setCatalogResults] = useState([])
    const [isSearchingCatalog, setIsSearchingCatalog] = useState(false)
    const [showCatalogSearch, setShowCatalogSearch] = useState(false)

    // Table filter in Step 2: 'all' | 'diff' | 'unscanned' | 'over' | 'short' | 'exact'
    const [tableFilter, setTableFilter] = useState('all')
    const [itemSearch, setItemSearch] = useState('')

    // Confirmation modal for applying adjustment
    const [showApplyModal, setShowApplyModal] = useState(false)

    // Find current selected shelf details
    const currentShelf = useMemo(() => {
        return shelves.find((sh) => sh.shelf_code === selectedShelfCode) || shelves[0] || null
    }, [shelves, selectedShelfCode])

    // Find current selected brand details
    const currentBrandObj = useMemo(() => {
        return brands.find((b) => b.brand === selectedBrand) || brands[0] || null
    }, [brands, selectedBrand])

    // Auto-focus barcode input in counting step
    useEffect(() => {
        if (currentStep === 'counting' && !scanModalProduct && !isCameraOpen && !showCatalogSearch) {
            gunInputRef.current?.focus()
        }
    }, [currentStep, scanModalProduct, isCameraOpen, showCatalogSearch, selectedShelfCode, selectedBrand])

    // Focus input inside scan quantity modal
    useEffect(() => {
        if (scanModalProduct) {
            setTimeout(() => {
                modalInputRef.current?.focus()
                modalInputRef.current?.select()
            }, 80)
        }
    }, [scanModalProduct])

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
                    scanned: false,
                    last_scanned_at: 0,
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

    // Load products when brand selection changes
    const handleBrandChange = async (newBrand) => {
        setSelectedBrand(newBrand)
        if (!newBrand) {
            setItems([])
            return
        }

        setIsLoadingProducts(true)
        try {
            const resp = await axios.get(`/almacen/auditorias/marca/${encodeURIComponent(newBrand)}/productos`)
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
                    scanned: false,
                    last_scanned_at: 0,
                    notes: '',
                    is_extra: false,
                }))
                setItems(mapped)
                setScanFeedback({ type: 'info', text: `Cargados ${mapped.length} productos de la marca "${newBrand}".` })
            }
        } catch (err) {
            console.error('Error al cargar productos de la marca:', err)
            setScanFeedback({ type: 'error', text: 'Error al cargar productos de la marca.' })
        } finally {
            setIsLoadingProducts(false)
        }
    }

    // Switch between 'shelf' and 'brand' mode
    const handleModeSwitch = (newMode) => {
        if (newMode === auditType) return
        setAuditType(newMode)
        if (newMode === 'shelf') {
            handleShelfChange(selectedShelfCode || shelves[0]?.shelf_code)
        } else {
            handleBrandChange(selectedBrand || brands[0]?.brand)
        }
    }

    // Triggered upon reading a barcode (from Gun, Camera or Catalog Search)
    // Opens the modal with product details, system quantity and editable count!
    const openScanModal = (product, fromCamera = false) => {
        playBeep('success')
        // Pre-fill count: if user already counted something, preserve it. If 0, suggest system_quantity or 1
        const initialCount = product.scanned
            ? product.counted_quantity
            : (product.system_quantity > 0 ? product.system_quantity : 1)

        setScanModalProduct(product)
        setModalCount(initialCount)
        setModalNotes(product.notes || '')
        setKeepCameraAfterConfirm(fromCamera)
    }

    // Remove an item from the audit or reset its count if it's an expected product
    const removeItem = (productId) => {
        const item = items.find((it) => it.product_id === productId)
        if (!item) return

        if (item.is_extra) {
            if (confirm(`¿Deseas eliminar "${item.name}" de esta auditoría? No pertenece a este anaquel/marca.`)) {
                setItems((prev) => prev.filter((it) => it.product_id !== productId))
                setScanFeedback({ type: 'info', text: `Se eliminó "${item.name}" de la lista.` })
            }
        } else {
            if (confirm(`¿Deseas reiniciar a 0 el conteo físico de "${item.name}"?`)) {
                setItems((prev) =>
                    prev.map((it) =>
                        it.product_id === productId
                            ? { ...it, counted_quantity: 0, scanned: false, notes: '' }
                            : it
                    )
                )
                setScanFeedback({ type: 'info', text: `Se reinició a 0 el conteo de "${item.name}".` })
            }
        }
    }

    // Process a scanned barcode (from gun or camera)
    const processBarcode = async (rawCode, fromCamera = false) => {
        const code = String(rawCode || '').trim()
        if (!code) return

        const codeLower = code.toLowerCase()

        // 1. Check if product already exists in current list
        const existing = items.find((it) => {
            return (
                (it.barcode && it.barcode.toLowerCase() === codeLower) ||
                (it.barcode_secondary && it.barcode_secondary.toLowerCase() === codeLower) ||
                (it.sku && it.sku.toLowerCase() === codeLower)
            )
        })

        if (existing) {
            openScanModal(existing, fromCamera)
            return
        }

        // 2. Not found in current list -> search catalog in DB
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
                // Validación estricta: Bloquear si pertenece a otro anaquel o a otra marca
                if (auditType === 'shelf') {
                    const matchedLocCode = matched.primary_location?.code || ''
                    const matchedShelfCode = extractShelfCode(matchedLocCode)
                    const targetShelf = extractShelfCode(selectedShelfCode)

                    if (matchedShelfCode && targetShelf && matchedShelfCode !== targetShelf) {
                        playBeep('warning')
                        setMismatchModal({
                            title: '⛔ Producto No Pertenece a este Anaquel',
                            product: matched,
                            expectedTarget: `Anaquel ${targetShelf}`,
                            actualTarget: `Anaquel ${matchedShelfCode} (Ubicación: ${matchedLocCode})`,
                            reason: 'shelf',
                            message: `Este producto tiene asignada la ubicación "${matchedLocCode}" (Anaquel ${matchedShelfCode}) en el catálogo. No corresponde al Anaquel ${targetShelf} que estás auditando.`,
                        })
                        setScanFeedback({
                            type: 'error',
                            text: `⛔ Bloqueado: "${matched.name}" pertenece al Anaquel ${matchedShelfCode} (${matchedLocCode}), no a ${targetShelf}.`,
                        })
                        return
                    }
                }

                if (auditType === 'brand' && selectedBrand) {
                    const matchedBrand = String(matched.brand || '').trim().toLowerCase()
                    const targetBrand = String(selectedBrand || '').trim().toLowerCase()

                    if (matchedBrand && targetBrand && matchedBrand !== targetBrand) {
                        playBeep('warning')
                        setMismatchModal({
                            title: '⛔ Producto de Otra Marca',
                            product: matched,
                            expectedTarget: `Marca ${selectedBrand}`,
                            actualTarget: `Marca ${matched.brand || 'Sin marca'}`,
                            reason: 'brand',
                            message: `Este producto pertenece a la marca "${matched.brand}", no a la marca "${selectedBrand}" que estás auditando.`,
                        })
                        setScanFeedback({
                            type: 'error',
                            text: `⛔ Bloqueado: "${matched.name}" es marca "${matched.brand}", no "${selectedBrand}".`,
                        })
                        return
                    }
                }

                const newItem = {
                    product_id: matched.id,
                    sku: matched.sku,
                    barcode: matched.barcode,
                    barcode_secondary: matched.barcode_secondary,
                    name: matched.name,
                    brand: matched.brand,
                    primary_location: matched.primary_location?.code || null,
                    system_quantity: 0,
                    location_breakdown: auditType === 'shelf'
                        ? 'Sin stock previo en este anaquel'
                        : 'Sin stock previo en esta marca',
                    counted_quantity: 0,
                    scanned: false,
                    last_scanned_at: 0,
                    notes: '',
                    is_extra: true,
                }

                // Add to items list and open modal
                setItems((prev) => [newItem, ...prev])
                openScanModal(newItem, fromCamera)
            } else {
                playBeep('warning')
                setScanFeedback({
                    type: 'error',
                    text: `Código "${code}" no encontrado en catálogo. Agrégalo primero al catálogo maestro.`,
                })
            }
        } catch (err) {
            console.error('Error al buscar en catálogo:', err)
            setScanFeedback({ type: 'error', text: 'Error al buscar el código en el catálogo.' })
        }
    }

    // Submit the scan quantity modal
    const handleConfirmScanModal = (andReopenCamera = false) => {
        if (!scanModalProduct) return

        const validatedCount = Math.max(0, parseInt(modalCount, 10) || 0)
        const targetId = scanModalProduct.product_id

        setItems((prev) =>
            prev.map((it) => {
                if (it.product_id === targetId) {
                    return {
                        ...it,
                        counted_quantity: validatedCount,
                        scanned: true,
                        last_scanned_at: Date.now(),
                        notes: modalNotes,
                    }
                }
                return it
            })
        )

        setHighlightedProductId(targetId)
        setScanFeedback({
            type: 'success',
            text: `✓ Registrado: ${validatedCount} pza(s) de "${scanModalProduct.name}".`,
        })

        const shouldOpenCam = andReopenCamera || keepCameraAfterConfirm
        setScanModalProduct(null)

        if (shouldOpenCam) {
            setTimeout(() => setIsCameraOpen(true), 250)
        } else {
            setTimeout(() => gunInputRef.current?.focus(), 100)
        }

        setTimeout(() => setHighlightedProductId(null), 2500)
    }

    // Handle Gun submit
    const handleGunSubmit = (e) => {
        e.preventDefault()
        const code = gunBarcode.trim()
        if (!code) return
        setGunBarcode('')
        processBarcode(code, false)
    }

    // Search catalog manually
    const handleSearchCatalog = async (e) => {
        e?.preventDefault()
        const q = catalogSearch.trim()
        if (!q) return

        setIsSearchingCatalog(true)
        try {
            const resp = await axios.get('/almacen/auditorias/buscar-catalogo', { params: { q } })
            setCatalogResults(resp.data || [])
        } catch (err) {
            console.error('Error buscando catálogo:', err)
        } finally {
            setIsSearchingCatalog(false)
        }
    }

    const addCatalogProductToAudit = (product) => {
        const existing = items.find((it) => it.product_id === product.id)
        if (existing) {
            setShowCatalogSearch(false)
            openScanModal(existing, false)
            return
        }

        // Validación estricta: Bloquear si pertenece a otro anaquel o marca
        if (auditType === 'shelf') {
            const matchedLocCode = product.primary_location?.code || ''
            const matchedShelfCode = extractShelfCode(matchedLocCode)
            const targetShelf = extractShelfCode(selectedShelfCode)

            if (matchedShelfCode && targetShelf && matchedShelfCode !== targetShelf) {
                playBeep('warning')
                setShowCatalogSearch(false)
                setMismatchModal({
                    title: '⛔ Producto No Pertenece a este Anaquel',
                    product,
                    expectedTarget: `Anaquel ${targetShelf}`,
                    actualTarget: `Anaquel ${matchedShelfCode} (Ubicación: ${matchedLocCode})`,
                    reason: 'shelf',
                    message: `Este producto tiene asignada la ubicación "${matchedLocCode}" (Anaquel ${matchedShelfCode}) en el catálogo. No corresponde al Anaquel ${targetShelf} que estás auditando.`,
                })
                return
            }
        }

        if (auditType === 'brand' && selectedBrand) {
            const matchedBrand = String(product.brand || '').trim().toLowerCase()
            const targetBrand = String(selectedBrand || '').trim().toLowerCase()

            if (matchedBrand && targetBrand && matchedBrand !== targetBrand) {
                playBeep('warning')
                setShowCatalogSearch(false)
                setMismatchModal({
                    title: '⛔ Producto de Otra Marca',
                    product,
                    expectedTarget: `Marca ${selectedBrand}`,
                    actualTarget: `Marca ${product.brand || 'Sin marca'}`,
                    reason: 'brand',
                    message: `Este producto pertenece a la marca "${product.brand}", no a la marca "${selectedBrand}" que estás auditando.`,
                })
                return
            }
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
            location_breakdown: 'Producto agregado manualmente',
            counted_quantity: 0,
            scanned: false,
            last_scanned_at: 0,
            notes: '',
            is_extra: true,
        }

        setItems((prev) => [newItem, ...prev])
        setShowCatalogSearch(false)
        openScanModal(newItem, false)
    }

    // Calculate live totals
    const totals = useMemo(() => {
        let expectedPieces = 0
        let countedPieces = 0
        let matchedCount = 0
        let surplusCount = 0
        let missingCount = 0
        let unscannedCount = 0

        items.forEach((it) => {
            const sys = Number(it.system_quantity || 0)
            const count = Number(it.counted_quantity || 0)
            const diff = count - sys

            expectedPieces += sys
            countedPieces += count

            if (!it.scanned && it.counted_quantity === 0) {
                unscannedCount++
            }

            if (diff === 0) {
                matchedCount++
            } else if (diff > 0) {
                surplusCount++
            } else {
                missingCount++
            }
        })

        const diffPieces = countedPieces - expectedPieces

        return {
            totalProducts: items.length,
            scannedProducts: items.filter((it) => it.scanned || it.counted_quantity > 0).length,
            unscannedCount,
            expectedPieces,
            countedPieces,
            diffPieces,
            matchedCount,
            surplusCount,
            missingCount,
            hasDiscrepancies: surplusCount > 0 || missingCount > 0,
        }
    }, [items])

    // List of scanned items (sorted by last scanned first for Step 1 review)
    const scannedItems = useMemo(() => {
        return items
            .filter((it) => it.scanned || it.counted_quantity > 0)
            .sort((a, b) => (b.last_scanned_at || 0) - (a.last_scanned_at || 0))
    }, [items])

    // Filter items for Step 2 table
    const filteredReviewItems = useMemo(() => {
        return items.filter((it) => {
            const sys = Number(it.system_quantity || 0)
            const count = Number(it.counted_quantity || 0)
            const diff = count - sys

            // Tab filter
            if (tableFilter === 'diff' && diff === 0) return false
            if (tableFilter === 'unscanned' && (it.scanned || it.counted_quantity > 0)) return false
            if (tableFilter === 'over' && diff <= 0) return false
            if (tableFilter === 'short' && diff >= 0) return false
            if (tableFilter === 'exact' && diff !== 0) return false

            // Text search
            if (itemSearch.trim()) {
                const q = itemSearch.toLowerCase().trim()
                const name = (it.name || '').toLowerCase()
                const sku = (it.sku || '').toLowerCase()
                const bc = (it.barcode || '').toLowerCase()
                const brand = (it.brand || '').toLowerCase()
                return name.includes(q) || sku.includes(q) || bc.includes(q) || brand.includes(q)
            }

            return true
        })
    }, [items, tableFilter, itemSearch])

    // Submit audit (Draft or Apply)
    const submitAudit = (applyImmediately = false) => {
        if (items.length === 0) {
            alert('No hay productos en la auditoría para guardar.')
            return
        }

        setIsSubmitting(true)

        const payload = {
            audit_type: auditType,
            shelf_code: auditType === 'shelf' ? selectedShelfCode : null,
            brand_name: auditType === 'brand' ? selectedBrand : null,
            inventory_location_id: auditType === 'shelf' ? (currentShelf?.primary_location_id || null) : null,
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

        if (resumeAudit?.id) {
            router.put(`/almacen/auditorias/${resumeAudit.id}`, payload, {
                onFinish: () => {
                    setIsSubmitting(false)
                    setShowApplyModal(false)
                },
                onError: (errors) => {
                    console.error('Error al actualizar auditoría:', errors)
                    alert('Error al guardar: ' + (Object.values(errors)[0] || 'Verifica los campos.'))
                },
            })
            return
        }

        router.post('/almacen/auditorias', payload, {
            onFinish: () => {
                setIsSubmitting(false)
                setShowApplyModal(false)
            },
            onError: (errors) => {
                console.error('Error al guardar auditoría:', errors)
                alert('Error al guardar: ' + (Object.values(errors)[0] || 'Verifica los campos.'))
            },
        })
    }

    const currentTitle = resumeAudit
        ? `Reanudar Conteo - ${resumeAudit.audit_number} (${auditType === 'shelf' ? `Anaquel ${selectedShelfCode}` : `Marca ${selectedBrand}`})`
        : (auditType === 'shelf'
            ? `Auditar Anaquel: ${selectedShelfCode || 'Seleccionar'}`
            : `Auditar Marca: ${selectedBrand || 'Seleccionar'}`)

    return (
        <AppShell title={currentTitle}>
            <Head title={`Conteo Físico - ${currentTitle}`} />

            <div className="space-y-6 pb-20">
                {/* Top Breadcrumb & Step Navigation */}
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
                            <span className="rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                {currentStep === 'counting' ? 'Paso 1: Escaneo y Conteo' : 'Paso 2: Revisión de Diferencias'}
                            </span>
                        </div>
                        <h1 className="mt-1 text-2xl font-bold tracking-tight text-slate-900 dark:text-white sm:text-3xl">
                            {currentTitle}
                        </h1>
                        <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            {auditType === 'shelf'
                                ? `Incluye todas las casillas de este anaquel: ${currentShelf?.sub_locations?.join(', ') || selectedShelfCode}`
                                : `Auditoría global de catálogo y almacén para la marca ${selectedBrand}`}
                        </p>
                    </div>

                    {/* Step Switcher Buttons */}
                    <div className="flex items-center gap-2">
                        {currentStep === 'counting' ? (
                            <button
                                type="button"
                                onClick={() => setCurrentStep('review')}
                                className="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-indigo-700 active:scale-95"
                            >
                                <span>Revisar Conteo y Diferencias ({totals.scannedProducts}/{totals.totalProducts})</span>
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                                </svg>
                            </button>
                        ) : (
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => setCurrentStep('counting')}
                                    className="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                                >
                                    <svg className="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                                    </svg>
                                    <span>← Volver a Escanear</span>
                                </button>
                                <button
                                    type="button"
                                    disabled={isSubmitting}
                                    onClick={() => submitAudit(false)}
                                    className="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                                >
                                    Guardar Borrador
                                </button>
                                <button
                                    type="button"
                                    disabled={isSubmitting}
                                    onClick={() => setShowApplyModal(true)}
                                    className="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700 active:scale-95 disabled:opacity-50"
                                >
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                                    </svg>
                                    <span>⚡ Aplicar Ajuste Ahora</span>
                                </button>
                            </div>
                        )}
                    </div>
                </div>

                {/* Resume Audit Banner */}
                {resumeAudit && (
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-2xl border border-indigo-200 bg-indigo-50/80 p-4 dark:border-indigo-900/60 dark:bg-indigo-950/40 shadow-xs">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white font-bold text-lg shadow-sm">
                                📝
                            </div>
                            <div>
                                <h3 className="text-sm font-bold text-indigo-950 dark:text-indigo-200">
                                    Reanudando Conteo de Borrador: <span className="font-mono text-indigo-600 dark:text-indigo-400 font-black">{resumeAudit.audit_number}</span>
                                </h3>
                                <p className="text-xs text-indigo-800 dark:text-indigo-300">
                                    {auditType === 'shelf' ? `Anaquel ${selectedShelfCode}` : `Marca ${selectedBrand}`}. Se han precargado los productos y cantidades contadas previamente.
                                </p>
                            </div>
                        </div>
                        <Link
                            href={`/almacen/auditorias/${resumeAudit.id}`}
                            className="inline-flex items-center gap-1.5 self-start sm:self-auto rounded-xl border border-indigo-300 bg-white px-3.5 py-1.5 text-xs font-bold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:bg-neutral-900 dark:text-indigo-300 transition"
                        >
                            <span>Ver Ficha Detallada →</span>
                        </Link>
                    </div>
                )}

                {/* Audit Mode Selector & Scope Selector */}
                <div className="grid grid-cols-1 gap-4 lg:grid-cols-12">
                    {/* Left: Mode Toggle (Por Anaquel vs Por Marca) */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs dark:border-neutral-800 dark:bg-neutral-900 lg:col-span-5">
                        <div className="flex items-center justify-between">
                            <label className="block text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                1. Modalidad de Inventario
                            </label>
                            {resumeAudit && (
                                <span className="text-[10px] font-bold text-amber-600 dark:text-amber-400">
                                    (Fijado por Borrador)
                                </span>
                            )}
                        </div>
                        <div className="mt-2 grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                disabled={Boolean(resumeAudit)}
                                onClick={() => handleModeSwitch('shelf')}
                                className={`flex items-center justify-center gap-2 rounded-xl py-2.5 text-xs font-bold transition ${
                                    auditType === 'shelf'
                                        ? 'bg-indigo-600 text-white shadow-sm'
                                        : 'border border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-300'
                                } ${resumeAudit ? 'cursor-not-allowed opacity-80' : ''}`}
                            >
                                <span>📦 Por Anaquel</span>
                                <span className="rounded-full bg-indigo-500/30 px-1.5 py-0.2 text-[10px]">
                                    {shelves.length}
                                </span>
                            </button>
                            <button
                                type="button"
                                disabled={Boolean(resumeAudit)}
                                onClick={() => handleModeSwitch('brand')}
                                className={`flex items-center justify-center gap-2 rounded-xl py-2.5 text-xs font-bold transition ${
                                    auditType === 'brand'
                                        ? 'bg-indigo-600 text-white shadow-sm'
                                        : 'border border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-300'
                                } ${resumeAudit ? 'cursor-not-allowed opacity-80' : ''}`}
                            >
                                <span>🏷️ Por Marca</span>
                                <span className="rounded-full bg-indigo-500/30 px-1.5 py-0.2 text-[10px]">
                                    {brands.length}
                                </span>
                            </button>
                        </div>

                        {/* Dropdown according to selected mode */}
                        <div className="mt-3">
                            {auditType === 'shelf' ? (
                                <div>
                                    <label className="text-[11px] font-semibold text-slate-600 dark:text-slate-300">
                                        Selecciona el Anaquel a contar:
                                    </label>
                                    <select
                                        value={selectedShelfCode}
                                        disabled={isLoadingProducts || Boolean(resumeAudit)}
                                        onChange={(e) => handleShelfChange(e.target.value)}
                                        className="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-900 focus:border-indigo-500 focus:outline-none disabled:bg-slate-100 disabled:cursor-not-allowed dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    >
                                        {shelves.map((sh) => (
                                            <option key={sh.shelf_code} value={sh.shelf_code}>
                                                📍 {sh.display_label}
                                            </option>
                                        ))}
                                    </select>
                                    {currentShelf && (
                                        <p className="mt-1.5 text-[11px] text-emerald-700 dark:text-emerald-400 font-medium">
                                            ✓ Agrupa {currentShelf.sub_locations_count} casillas: {currentShelf.sub_locations?.join(', ')}
                                        </p>
                                    )}
                                </div>
                            ) : (
                                <div>
                                    <label className="text-[11px] font-semibold text-slate-600 dark:text-slate-300">
                                        Selecciona la Marca a contar:
                                    </label>
                                    <select
                                        value={selectedBrand}
                                        disabled={isLoadingProducts || Boolean(resumeAudit)}
                                        onChange={(e) => handleBrandChange(e.target.value)}
                                        className="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-900 focus:border-indigo-500 focus:outline-none disabled:bg-slate-100 disabled:cursor-not-allowed dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    >
                                        {brands.map((b) => (
                                            <option key={b.brand} value={b.brand}>
                                                🏷️ {b.display_label}
                                            </option>
                                        ))}
                                    </select>
                                    {currentBrandObj && (
                                        <p className="mt-1.5 text-[11px] text-indigo-700 dark:text-indigo-400 font-medium">
                                            ✓ {items.length} productos en el catálogo de esta marca.
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Right: Barcode Scanner & Mobile Camera Area */}
                    <div className="rounded-2xl border-2 border-indigo-200 bg-gradient-to-br from-indigo-50/50 to-white p-4 shadow-sm dark:border-indigo-900/40 dark:from-neutral-900 dark:to-neutral-900 lg:col-span-7">
                        <div className="flex items-center justify-between">
                            <span className="text-xs font-bold uppercase tracking-wider text-indigo-900 dark:text-indigo-300">
                                2. Escáner de Código de Barras (Pistola USB / Bluetooth o Celular)
                            </span>
                            <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-bold text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300">
                                Abre ventana con stock del sistema
                            </span>
                        </div>

                        {/* Scanner Form */}
                        <form onSubmit={handleGunSubmit} className="mt-2.5 flex flex-wrap gap-2">
                            <div className="relative min-w-0 flex-1">
                                <input
                                    ref={gunInputRef}
                                    type="text"
                                    value={gunBarcode}
                                    onChange={(e) => setGunBarcode(e.target.value)}
                                    placeholder="Dispara con la pistola o escribe SKU / código y presiona Enter..."
                                    className="w-full rounded-xl border-2 border-indigo-400 bg-white py-2.5 pl-3.5 pr-20 text-xs font-semibold text-slate-900 placeholder-slate-400 focus:border-indigo-600 focus:outline-none dark:border-indigo-600 dark:bg-neutral-950 dark:text-white"
                                />
                                <button
                                    type="submit"
                                    className="absolute right-1.5 top-1.5 rounded-lg bg-indigo-600 px-2.5 py-1.5 text-[11px] font-bold text-white transition hover:bg-indigo-700 active:scale-95"
                                >
                                    + Registrar
                                </button>
                            </div>

                            {/* Camera Button for Phone / Mobile */}
                            <button
                                type="button"
                                onClick={() => setIsCameraOpen(true)}
                                className="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 shadow-xs transition hover:bg-slate-50 active:scale-95 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                                title="Abrir cámara del celular para escanear"
                            >
                                <svg className="h-4 w-4 text-indigo-600 dark:text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" />
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z" />
                                </svg>
                                <span>Cámara Celular</span>
                            </button>

                            {/* Extra Catalog Button */}
                            <button
                                type="button"
                                onClick={() => setShowCatalogSearch(true)}
                                className="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 shadow-xs transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                            >
                                <svg className="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                </svg>
                                <span>Buscar Extra</span>
                            </button>
                        </form>

                        {/* Live feedback */}
                        {scanFeedback && (
                            <div
                                className={`mt-2.5 flex items-center justify-between rounded-xl p-2.5 text-xs font-bold ${
                                    scanFeedback.type === 'success'
                                        ? 'border border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800/40 dark:bg-emerald-950/40 dark:text-emerald-300'
                                        : scanFeedback.type === 'error'
                                        ? 'border border-rose-300 bg-rose-50 text-rose-800 dark:border-rose-800/40 dark:bg-rose-950/40 dark:text-rose-300'
                                        : 'border border-indigo-200 bg-indigo-50 text-indigo-800 dark:border-indigo-800/40 dark:bg-indigo-950/40 dark:text-indigo-300'
                                }`}
                            >
                                <span>{scanFeedback.text}</span>
                                <button
                                    type="button"
                                    onClick={() => setScanFeedback(null)}
                                    className="ml-2 text-slate-400 hover:text-slate-600"
                                >
                                    ✕
                                </button>
                            </div>
                        )}
                    </div>
                </div>

                {/* Progress & KPI Summary Bar */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-6">
                    <div className="rounded-2xl border border-slate-200 bg-white p-3.5 shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-[11px] font-bold uppercase text-slate-500 dark:text-neutral-400">Total Esperados</span>
                        <p className="mt-1 text-2xl font-black text-slate-900 dark:text-white">
                            {totals.totalProducts}
                        </p>
                    </div>

                    <div className="rounded-2xl border border-indigo-200 bg-indigo-50/40 p-3.5 shadow-xs dark:border-indigo-900/40 dark:bg-indigo-950/20">
                        <span className="text-[11px] font-bold uppercase text-indigo-700 dark:text-indigo-400">Escaneados</span>
                        <p className="mt-1 text-2xl font-black text-indigo-700 dark:text-indigo-300">
                            {totals.scannedProducts} <span className="text-xs font-semibold text-slate-400">/ {totals.totalProducts}</span>
                        </p>
                    </div>

                    <div className="rounded-2xl border border-emerald-200 bg-emerald-50/40 p-3.5 shadow-xs dark:border-emerald-900/40 dark:bg-emerald-950/20">
                        <span className="text-[11px] font-bold uppercase text-emerald-700 dark:text-emerald-400">Exactos</span>
                        <p className="mt-1 text-2xl font-black text-emerald-700 dark:text-emerald-300">
                            {totals.matchedCount}
                        </p>
                    </div>

                    <div className="rounded-2xl border border-blue-200 bg-blue-50/40 p-3.5 shadow-xs dark:border-blue-900/40 dark:bg-blue-950/20">
                        <span className="text-[11px] font-bold uppercase text-blue-700 dark:text-blue-400">Sobrantes (+)</span>
                        <p className="mt-1 text-2xl font-black text-blue-700 dark:text-blue-300">
                            {totals.surplusCount}
                        </p>
                    </div>

                    <div className="rounded-2xl border border-rose-200 bg-rose-50/40 p-3.5 shadow-xs dark:border-rose-900/40 dark:bg-rose-950/20">
                        <span className="text-[11px] font-bold uppercase text-rose-700 dark:text-rose-400">Faltantes (-)</span>
                        <p className="mt-1 text-2xl font-black text-rose-700 dark:text-rose-300">
                            {totals.missingCount}
                        </p>
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-white p-3.5 shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-[11px] font-bold uppercase text-slate-500 dark:text-neutral-400">Dif. Neta</span>
                        <p className={`mt-1 text-2xl font-black ${totals.diffPieces > 0 ? 'text-blue-600' : totals.diffPieces < 0 ? 'text-rose-600' : 'text-slate-700 dark:text-slate-300'}`}>
                            {totals.diffPieces > 0 ? `+${totals.diffPieces}` : totals.diffPieces} <span className="text-xs font-semibold text-slate-400">uds</span>
                        </p>
                    </div>
                </div>

                {/* ========================================================================= */}
                {/* PASO 1: MODO CONTEO Y ESCANEO ACTIVO                                     */}
                {/* ========================================================================= */}
                {currentStep === 'counting' && (
                    <div className="space-y-4">
                        <div className="flex flex-col justify-between gap-3 rounded-2xl border border-indigo-200 bg-indigo-50/30 p-4 dark:border-indigo-900/30 dark:bg-indigo-950/20 sm:flex-row sm:items-center">
                            <div>
                                <h2 className="text-base font-bold text-slate-900 dark:text-white">
                                    Productos Escaneados en esta Sesión ({scannedItems.length})
                                </h2>
                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                    Conforme escaneas con la pistola o cámara, los productos se agregan aquí con la cantidad que confirmaste.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setCurrentStep('review')}
                                className="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-indigo-700 active:scale-95"
                            >
                                <span>Ver Lista Completa y Diferencias ({totals.scannedProducts}/{totals.totalProducts}) →</span>
                            </button>
                        </div>

                        {scannedItems.length === 0 ? (
                            <div className="rounded-2xl border-2 border-dashed border-slate-300 p-12 text-center dark:border-neutral-700">
                                <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-100 text-indigo-600 dark:bg-indigo-950 dark:text-indigo-300">
                                    <svg className="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 013.75 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 01-1.125-1.125v-4.5zM14.625 3.75c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5c-.621 0-1.125-.504-1.125-1.125v-4.5c0-.621.504-1.125 1.125-1.125h4.5zM14.625 14.625c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 01-1.125-1.125v-4.5c0-.621.504-1.125 1.125-1.125h4.5z" />
                                    </svg>
                                </div>
                                <h3 className="mt-3 text-sm font-bold text-slate-800 dark:text-slate-200">
                                    Aún no has escaneado ningún producto
                                </h3>
                                <p className="mt-1 text-xs text-slate-500 dark:text-slate-400 max-w-md mx-auto">
                                    Apunta tu pistola de código de barras o pulsa <strong>"Cámara Celular"</strong> para comenzar a contar. Se abrirá una ventana para ingresar la cantidad exacta contada.
                                </p>
                            </div>
                        ) : (
                            <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                                {scannedItems.map((it) => {
                                    const diff = it.counted_quantity - it.system_quantity
                                    const isHighlighted = highlightedProductId === it.product_id

                                    return (
                                        <div
                                            key={it.product_id}
                                            onClick={() => openScanModal(it, false)}
                                            className={`cursor-pointer rounded-2xl border p-3.5 shadow-xs transition hover:border-indigo-400 ${
                                                isHighlighted
                                                    ? 'border-emerald-500 bg-emerald-50/50 ring-2 ring-emerald-400 dark:bg-emerald-950/30'
                                                    : 'border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900'
                                            }`}
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-1.5">
                                                        <p className="line-clamp-2 text-xs font-bold text-slate-900 dark:text-white">
                                                            {it.name}
                                                        </p>
                                                        {it.is_extra && (
                                                            <span className="shrink-0 rounded bg-amber-100 px-1.5 py-0.2 text-[10px] font-bold text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                                                Extra
                                                            </span>
                                                        )}
                                                    </div>
                                                    <div className="mt-1 flex flex-wrap items-center gap-1.5 font-mono text-[11px] text-slate-500 dark:text-slate-400">
                                                        <span>SKU: {it.sku}</span>
                                                        {it.brand && (
                                                            <span className="rounded bg-slate-100 px-1 py-0.2 text-[10px] text-slate-700 dark:bg-neutral-800 dark:text-slate-300">
                                                                {it.brand}
                                                            </span>
                                                        )}
                                                        {it.primary_location && (
                                                            <span className="rounded bg-slate-100 px-1 py-0.2 text-[10px] text-slate-600 dark:bg-neutral-800 dark:text-slate-400">
                                                                📍 {it.primary_location}
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>

                                                <div className="flex items-center gap-1">
                                                    <button
                                                        type="button"
                                                        onClick={(e) => {
                                                            e.stopPropagation()
                                                            openScanModal(it, false)
                                                        }}
                                                        className="rounded-lg bg-indigo-50 p-1.5 text-indigo-600 hover:bg-indigo-100 dark:bg-indigo-950/60 dark:text-indigo-400"
                                                        title="Modificar cantidad"
                                                    >
                                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                                        </svg>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={(e) => {
                                                            e.stopPropagation()
                                                            removeItem(it.product_id)
                                                        }}
                                                        className="rounded-lg bg-rose-50 p-1.5 text-rose-600 hover:bg-rose-100 dark:bg-rose-950/60 dark:text-rose-400"
                                                        title={it.is_extra ? 'Eliminar este producto de la auditoría' : 'Reiniciar conteo a 0'}
                                                    >
                                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                        </svg>
                                                    </button>
                                                </div>
                                            </div>

                                            {/* Quantities comparison */}
                                            <div className="mt-3 flex items-center justify-between border-t border-slate-100 pt-2.5 text-xs dark:border-neutral-800">
                                                <div>
                                                    <span className="text-[10px] uppercase text-slate-400 font-semibold">Sistema:</span>
                                                    <span className="ml-1 font-bold text-slate-700 dark:text-slate-300">{it.system_quantity}</span>
                                                </div>
                                                <div className="rounded-lg bg-indigo-50 px-2 py-0.5 font-bold text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300">
                                                    Contado: {it.counted_quantity}
                                                </div>
                                                <div>
                                                    <span
                                                        className={`rounded-full px-2 py-0.5 text-[10px] font-black ${
                                                            diff === 0
                                                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                                                                : diff > 0
                                                                ? 'bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300'
                                                                : 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300'
                                                        }`}
                                                    >
                                                        {diff > 0 ? `+${diff}` : diff}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    )
                                })}
                            </div>
                        )}
                    </div>
                )}

                {/* ========================================================================= */}
                {/* PASO 2: MODO LISTA Y REVISIÓN DE DIFERENCIAS                              */}
                {/* ========================================================================= */}
                {currentStep === 'review' && (
                    <div className="space-y-4">
                        {/* Header bar with Back-to-Scan CTA */}
                        <div className="flex flex-col justify-between gap-3 rounded-2xl border-2 border-indigo-300 bg-white p-4 shadow-sm dark:border-indigo-800 dark:bg-neutral-900 sm:flex-row sm:items-center">
                            <div>
                                <span className="inline-flex items-center gap-1.5 rounded-md bg-indigo-100 px-2 py-0.5 text-xs font-black text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300">
                                    📋 Lista Completa & Auditoría de Diferencias
                                </span>
                                <p className="mt-1 text-xs text-slate-600 dark:text-slate-300">
                                    Revisa si se te pasó algún producto. Si detectas faltantes no contados, pulsa <strong>"Volver a Escanear"</strong> para seguir pasando la pistola o cámara.
                                </p>
                            </div>

                            <button
                                type="button"
                                onClick={() => setCurrentStep('counting')}
                                className="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-indigo-700 active:scale-95"
                            >
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                                </svg>
                                <span>← ¿Se te pasó alguno? Volver a Escanear</span>
                            </button>
                        </div>

                        {/* Filter Tabs & Search */}
                        <div className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-3.5 shadow-xs dark:border-neutral-800 dark:bg-neutral-900 sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex flex-wrap gap-1.5">
                                <button
                                    type="button"
                                    onClick={() => setTableFilter('all')}
                                    className={`rounded-xl px-3 py-1.5 text-xs font-bold transition ${
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
                                    className={`rounded-xl px-3 py-1.5 text-xs font-bold transition ${
                                        tableFilter === 'diff'
                                            ? 'bg-amber-600 text-white'
                                            : 'bg-amber-50 text-amber-800 hover:bg-amber-100 dark:bg-amber-950/40 dark:text-amber-300'
                                    }`}
                                >
                                    Con Diferencia ({totals.surplusCount + totals.missingCount})
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setTableFilter('unscanned')}
                                    className={`rounded-xl px-3 py-1.5 text-xs font-bold transition ${
                                        tableFilter === 'unscanned'
                                            ? 'bg-rose-600 text-white'
                                            : 'bg-rose-50 text-rose-800 hover:bg-rose-100 dark:bg-rose-950/40 dark:text-rose-300'
                                    }`}
                                >
                                    ❌ No Escaneados ({totals.unscannedCount})
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setTableFilter('over')}
                                    className={`rounded-xl px-3 py-1.5 text-xs font-bold transition ${
                                        tableFilter === 'over'
                                            ? 'bg-blue-600 text-white'
                                            : 'bg-blue-50 text-blue-800 hover:bg-blue-100 dark:bg-blue-950/40 dark:text-blue-300'
                                    }`}
                                >
                                    Sobrantes (+{totals.surplusCount})
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setTableFilter('short')}
                                    className={`rounded-xl px-3 py-1.5 text-xs font-bold transition ${
                                        tableFilter === 'short'
                                            ? 'bg-rose-600 text-white'
                                            : 'bg-rose-50 text-rose-800 hover:bg-rose-100 dark:bg-rose-950/40 dark:text-rose-300'
                                    }`}
                                >
                                    Faltantes (-{totals.missingCount})
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setTableFilter('exact')}
                                    className={`rounded-xl px-3 py-1.5 text-xs font-bold transition ${
                                        tableFilter === 'exact'
                                            ? 'bg-emerald-600 text-white'
                                            : 'bg-emerald-50 text-emerald-800 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300'
                                    }`}
                                >
                                    Exactos ({totals.matchedCount})
                                </button>
                            </div>

                            <div className="w-full sm:w-64">
                                <input
                                    type="text"
                                    value={itemSearch}
                                    onChange={(e) => setItemSearch(e.target.value)}
                                    placeholder="Filtrar en esta lista..."
                                    className="w-full rounded-xl border border-slate-300 px-3 py-1.5 text-xs text-slate-900 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>
                        </div>

                        {/* Full Comparison Table */}
                        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="overflow-x-auto">
                                <table className="min-w-full divide-y divide-slate-200 text-xs dark:divide-neutral-800">
                                    <thead className="bg-slate-50 text-left font-bold uppercase tracking-wider text-slate-500 dark:bg-neutral-950 dark:text-slate-400">
                                        <tr>
                                            <th className="px-4 py-3">Producto / SKU / Código</th>
                                            <th className="px-4 py-3 text-center">Stock Sistema</th>
                                            <th className="px-4 py-3 text-center">Físico Contado</th>
                                            <th className="px-4 py-3 text-center">Diferencia</th>
                                            <th className="px-4 py-3">Observaciones</th>
                                            <th className="px-4 py-3 text-right">Modificar</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                        {filteredReviewItems.length === 0 ? (
                                            <tr>
                                                <td colSpan="6" className="px-4 py-12 text-center text-slate-400">
                                                    No hay productos que coincidan con el filtro seleccionado.
                                                </td>
                                            </tr>
                                        ) : (
                                            filteredReviewItems.map((it) => {
                                                const diff = it.counted_quantity - it.system_quantity
                                                const isUnscanned = !it.scanned && it.counted_quantity === 0

                                                return (
                                                    <tr
                                                        key={it.product_id}
                                                        className={`transition hover:bg-slate-50/60 dark:hover:bg-neutral-800/40 ${
                                                            isUnscanned ? 'bg-rose-50/20' : ''
                                                        }`}
                                                    >
                                                        {/* Product info */}
                                                        <td className="px-4 py-3">
                                                            <div className="flex items-start gap-2">
                                                                <div className="min-w-0 flex-1">
                                                                    <div className="flex items-center gap-1.5">
                                                                        <span className="font-bold text-slate-900 dark:text-white">
                                                                            {it.name}
                                                                        </span>
                                                                        {it.is_extra && (
                                                                            <span className="rounded bg-amber-100 px-1.5 py-0.2 text-[10px] font-bold text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                                                                Extra
                                                                            </span>
                                                                        )}
                                                                        {isUnscanned && (
                                                                            <span className="rounded bg-rose-100 px-1.5 py-0.2 text-[10px] font-bold text-rose-800 dark:bg-rose-950 dark:text-rose-300">
                                                                                No Escaneado
                                                                            </span>
                                                                        )}
                                                                    </div>
                                                                    <div className="mt-0.5 flex flex-wrap items-center gap-2 font-mono text-[11px] text-slate-500 dark:text-slate-400">
                                                                        <span>SKU: {it.sku}</span>
                                                                        {it.barcode && <span>• Cód: {it.barcode}</span>}
                                                                        {it.brand && (
                                                                            <span className="text-indigo-600 dark:text-indigo-400">
                                                                                [{it.brand}]
                                                                            </span>
                                                                        )}
                                                                        {it.primary_location && (
                                                                            <span className="rounded bg-slate-100 px-1 py-0.2 text-[10px] text-slate-600 dark:bg-neutral-800 dark:text-slate-300">
                                                                                Ubicación: {it.primary_location}
                                                                            </span>
                                                                        )}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </td>

                                                        {/* System stock */}
                                                        <td className="px-4 py-3 text-center">
                                                            <span className="font-mono text-sm font-black text-slate-800 dark:text-white">
                                                                {it.system_quantity}
                                                            </span>
                                                            {it.location_breakdown && (
                                                                <p className="mt-0.5 text-[10px] text-slate-400 truncate max-w-[120px] mx-auto">
                                                                    {it.location_breakdown}
                                                                </p>
                                                            )}
                                                        </td>

                                                        {/* Counted quantity with micro controls */}
                                                        <td className="px-4 py-3 text-center">
                                                            <div className="inline-flex items-center gap-1">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        const val = Math.max(0, it.counted_quantity - 1)
                                                                        setItems((prev) =>
                                                                            prev.map((p) =>
                                                                                p.product_id === it.product_id
                                                                                    ? { ...p, counted_quantity: val, scanned: true }
                                                                                    : p
                                                                            )
                                                                        )
                                                                    }}
                                                                    className="h-6 w-6 rounded border border-slate-300 text-xs font-bold text-slate-600 hover:bg-slate-100 dark:border-neutral-700 dark:text-slate-300"
                                                                >
                                                                    -
                                                                </button>
                                                                <input
                                                                    type="number"
                                                                    min="0"
                                                                    value={it.counted_quantity}
                                                                    onChange={(e) => {
                                                                        const val = Math.max(0, parseInt(e.target.value, 10) || 0)
                                                                        setItems((prev) =>
                                                                            prev.map((p) =>
                                                                                p.product_id === it.product_id
                                                                                    ? { ...p, counted_quantity: val, scanned: true }
                                                                                    : p
                                                                            )
                                                                        )
                                                                    }}
                                                                    className="w-14 rounded border border-slate-300 py-1 text-center font-mono text-xs font-black text-slate-900 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                                                />
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        const val = it.counted_quantity + 1
                                                                        setItems((prev) =>
                                                                            prev.map((p) =>
                                                                                p.product_id === it.product_id
                                                                                    ? { ...p, counted_quantity: val, scanned: true }
                                                                                    : p
                                                                            )
                                                                        )
                                                                    }}
                                                                    className="h-6 w-6 rounded border border-slate-300 text-xs font-bold text-slate-600 hover:bg-slate-100 dark:border-neutral-700 dark:text-slate-300"
                                                                >
                                                                    +
                                                                </button>
                                                            </div>
                                                        </td>

                                                        {/* Difference badge */}
                                                        <td className="px-4 py-3 text-center">
                                                            <span
                                                                className={`inline-block rounded-full px-2.5 py-0.5 font-mono text-xs font-black ${
                                                                    diff === 0
                                                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                                                                        : diff > 0
                                                                        ? 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300'
                                                                        : 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300'
                                                                }`}
                                                            >
                                                                {diff === 0 ? 'Exacto (0)' : diff > 0 ? `+${diff} Sobrante` : `${diff} Faltante`}
                                                            </span>
                                                        </td>

                                                        {/* Notes input */}
                                                        <td className="px-4 py-3">
                                                            <input
                                                                type="text"
                                                                value={it.notes || ''}
                                                                onChange={(e) => {
                                                                    const val = e.target.value
                                                                    setItems((prev) =>
                                                                        prev.map((p) =>
                                                                            p.product_id === it.product_id
                                                                                ? { ...p, notes: val }
                                                                                : p
                                                                        )
                                                                    )
                                                                }}
                                                                placeholder="Motivo o detalle (ej. caja rota)..."
                                                                className="w-full rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] text-slate-700 placeholder-slate-400 focus:border-indigo-500 focus:outline-none dark:border-neutral-800 dark:bg-neutral-950 dark:text-slate-300"
                                                            />
                                                        </td>

                                                        {/* Edit in modal button & Remove/Reset button */}
                                                        <td className="px-4 py-3 text-right">
                                                            <div className="flex items-center justify-end gap-1.5">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => openScanModal(it, false)}
                                                                    className="rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-[11px] font-bold text-indigo-600 hover:bg-indigo-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-indigo-400"
                                                                    title="Abrir ventana para ingresar conteo"
                                                                >
                                                                    Ventana ✏️
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    onClick={() => removeItem(it.product_id)}
                                                                    className={`rounded-lg border px-2 py-1 text-[11px] font-bold transition ${
                                                                        it.is_extra
                                                                            ? 'border-rose-300 bg-rose-50 text-rose-700 hover:bg-rose-100 dark:border-rose-900/60 dark:bg-rose-950/60 dark:text-rose-300'
                                                                            : 'border-slate-200 bg-slate-50 text-slate-600 hover:bg-slate-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-400'
                                                                    }`}
                                                                    title={it.is_extra ? 'Eliminar este producto de la auditoría (no pertenece a este anaquel)' : 'Reiniciar conteo físico a 0'}
                                                                >
                                                                    {it.is_extra ? '🗑️ Quitar' : '↺ Reset'}
                                                                </button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                )
                                            })
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        {/* Audit Global Notes */}
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                            <label className="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300">
                                Observaciones Generales de la Auditoría (Opcional):
                            </label>
                            <textarea
                                rows={2}
                                value={auditNotes}
                                onChange={(e) => setAuditNotes(e.target.value)}
                                placeholder="Ej: Conteo realizado por revisión semanal de stock..."
                                className="mt-1.5 w-full rounded-xl border border-slate-300 p-2.5 text-xs text-slate-900 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                            />
                        </div>

                        {/* Bottom action buttons */}
                        <div className="flex flex-col-reverse justify-between gap-3 pt-2 sm:flex-row sm:items-center">
                            <button
                                type="button"
                                onClick={() => setCurrentStep('counting')}
                                className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-5 py-3 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                            >
                                <span>← Volver a Escanear</span>
                            </button>

                            <div className="flex items-center gap-2.5">
                                <button
                                    type="button"
                                    disabled={isSubmitting}
                                    onClick={() => submitAudit(false)}
                                    className="rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm font-bold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                                >
                                    Guardar Borrador
                                </button>
                                <button
                                    type="button"
                                    disabled={isSubmitting}
                                    onClick={() => setShowApplyModal(true)}
                                    className="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-6 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-700 active:scale-95 disabled:opacity-50"
                                >
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                                    </svg>
                                    <span>⚡ Aplicar Ajuste Ahora</span>
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>

            {/* ========================================================================= */}
            {/* MODAL CLAVE: VENTANA EMERGENTE AL ESCANEAR CON STOCK DEL SISTEMA Y EDICIÓN */}
            {/* ========================================================================= */}
            {scanModalProduct && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs">
                    <div className="w-full max-w-lg overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl dark:border-neutral-800 dark:bg-neutral-900 animate-in fade-in zoom-in-95 duration-150">
                        {/* Modal Header */}
                        <div className="flex items-center justify-between border-b border-slate-100 bg-slate-50/70 px-6 py-4 dark:border-neutral-800 dark:bg-neutral-950/60">
                            <div className="flex items-center gap-2">
                                <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-sm">
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2.5}>
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 013.75 9.375v-4.5z" />
                                    </svg>
                                </span>
                                <div>
                                    <h3 className="text-sm font-bold text-slate-900 dark:text-white">
                                        Producto Escaneado
                                    </h3>
                                    <p className="text-[11px] text-slate-500 dark:text-slate-400">
                                        Ingresa o modifica la cantidad física contada
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setScanModalProduct(null)}
                                className="rounded-xl p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-neutral-800 dark:hover:text-slate-200"
                            >
                                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        {/* Modal Body */}
                        <div className="p-6 space-y-5">
                            {/* Product Card */}
                            <div className="rounded-2xl border border-slate-200 bg-slate-50/50 p-4 dark:border-neutral-800 dark:bg-neutral-950">
                                <p className="text-sm font-bold text-slate-900 dark:text-white leading-snug">
                                    {scanModalProduct.name}
                                </p>
                                <div className="mt-2 flex flex-wrap items-center gap-2 font-mono text-xs text-slate-500 dark:text-slate-400">
                                    <span className="font-bold">SKU: {scanModalProduct.sku}</span>
                                    {scanModalProduct.barcode && <span>• Cód: {scanModalProduct.barcode}</span>}
                                    {scanModalProduct.brand && (
                                        <span className="rounded bg-indigo-50 px-1.5 py-0.5 text-[11px] font-bold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                                            {scanModalProduct.brand}
                                        </span>
                                    )}
                                </div>
                                {scanModalProduct.location_breakdown && (
                                    <p className="mt-1.5 text-xs text-slate-500 dark:text-slate-400">
                                        📍 <span className="font-semibold text-slate-700 dark:text-slate-300">{scanModalProduct.location_breakdown}</span>
                                    </p>
                                )}
                            </div>

                            {/* System Stock Card vs Count Input */}
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                {/* Stock en Sistema (Informativo y destacado) */}
                                <div className="rounded-2xl border-2 border-indigo-200 bg-indigo-50/40 p-4 text-center dark:border-indigo-900/40 dark:bg-indigo-950/20">
                                    <span className="text-xs font-bold uppercase tracking-wider text-indigo-800 dark:text-indigo-300">
                                        Stock en Sistema
                                    </span>
                                    <p className="mt-1 font-mono text-3xl font-black text-indigo-900 dark:text-white">
                                        {scanModalProduct.system_quantity}
                                    </p>
                                    <span className="text-[11px] font-semibold text-indigo-600 dark:text-indigo-400">
                                        piezas esperadas
                                    </span>
                                </div>

                                {/* Cantidad Física Contada (Editable) */}
                                <div className="rounded-2xl border-2 border-emerald-400 bg-emerald-50/30 p-4 text-center dark:border-emerald-700 dark:bg-emerald-950/20">
                                    <span className="text-xs font-bold uppercase tracking-wider text-emerald-800 dark:text-emerald-300">
                                        Físico Contado
                                    </span>
                                    <div className="mt-1 flex items-center justify-center gap-1.5">
                                        <button
                                            type="button"
                                            onClick={() => setModalCount((prev) => Math.max(0, (parseInt(prev, 10) || 0) - 1))}
                                            className="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-300 bg-white font-mono text-lg font-bold text-slate-700 shadow-xs hover:bg-slate-100 active:scale-95 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                                        >
                                            -
                                        </button>
                                        <input
                                            ref={modalInputRef}
                                            type="number"
                                            min="0"
                                            value={modalCount}
                                            onChange={(e) => setModalCount(e.target.value)}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter') {
                                                    e.preventDefault()
                                                    handleConfirmScanModal(false)
                                                }
                                            }}
                                            className="w-20 rounded-xl border-2 border-emerald-500 bg-white py-1 text-center font-mono text-2xl font-black text-slate-900 focus:border-emerald-600 focus:outline-none dark:bg-neutral-950 dark:text-white"
                                        />
                                        <button
                                            type="button"
                                            onClick={() => setModalCount((prev) => (parseInt(prev, 10) || 0) + 1)}
                                            className="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-300 bg-white font-mono text-lg font-bold text-slate-700 shadow-xs hover:bg-slate-100 active:scale-95 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                                        >
                                            +
                                        </button>
                                    </div>
                                    <span className="text-[11px] font-semibold text-emerald-700 dark:text-emerald-400">
                                        piezas reales
                                    </span>
                                </div>
                            </div>

                            {/* Quick Shortcuts */}
                            <div className="flex flex-wrap items-center justify-center gap-2 pt-1">
                                <button
                                    type="button"
                                    onClick={() => setModalCount(scanModalProduct.system_quantity)}
                                    className="rounded-xl border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-bold text-indigo-700 hover:bg-indigo-100 dark:border-indigo-800 dark:bg-indigo-950/60 dark:text-indigo-300"
                                >
                                    = Igual al Sistema ({scanModalProduct.system_quantity})
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setModalCount(0)}
                                    className="rounded-xl border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-bold text-rose-700 hover:bg-rose-100 dark:border-rose-800 dark:bg-rose-950/60 dark:text-rose-300"
                                >
                                    Poner en 0
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setModalCount((prev) => (parseInt(prev, 10) || 0) + 5)}
                                    className="rounded-xl border border-slate-200 bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700 hover:bg-slate-200 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                                >
                                    +5
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setModalCount((prev) => (parseInt(prev, 10) || 0) + 10)}
                                    className="rounded-xl border border-slate-200 bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700 hover:bg-slate-200 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                                >
                                    +10
                                </button>
                            </div>

                            {/* Live Difference summary inside modal */}
                            {(() => {
                                const countVal = parseInt(modalCount, 10) || 0
                                const diff = countVal - scanModalProduct.system_quantity
                                return (
                                    <div
                                        className={`rounded-xl p-3 text-center text-xs font-bold ${
                                            diff === 0
                                                ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                                                : diff > 0
                                                ? 'bg-blue-50 text-blue-800 dark:bg-blue-950/40 dark:text-blue-300'
                                                : 'bg-rose-50 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300'
                                        }`}
                                    >
                                        {diff === 0
                                            ? '✓ El conteo físico coincide exactamente con el sistema (0 diferencia).'
                                            : diff > 0
                                            ? `📈 Tienes un SOBRANTE de +${diff} pieza(s) respecto al sistema.`
                                            : `📉 Tienes un FALTANTE de ${diff} pieza(s) respecto al sistema.`}
                                    </div>
                                )
                            })()}

                            {/* Modal Notes input */}
                            <div>
                                <label className="block text-[11px] font-bold text-slate-600 dark:text-slate-400">
                                    Nota para este producto (opcional):
                                </label>
                                <input
                                    type="text"
                                    value={modalNotes}
                                    onChange={(e) => setModalNotes(e.target.value)}
                                    placeholder="Ej: Producto en buen estado, sin etiqueta, etc."
                                    className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-1.5 text-xs text-slate-900 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>
                        </div>

                        {/* Modal Footer */}
                        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 bg-slate-50/70 px-6 py-4 dark:border-neutral-800 dark:bg-neutral-950/60">
                            <button
                                type="button"
                                onClick={() => setScanModalProduct(null)}
                                className="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 shadow-xs hover:bg-slate-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                            >
                                Cancelar (Esc)
                            </button>

                            <div className="flex items-center gap-2">
                                {keepCameraAfterConfirm && (
                                    <button
                                        type="button"
                                        onClick={() => handleConfirmScanModal(true)}
                                        className="inline-flex items-center gap-1.5 rounded-xl border border-indigo-300 bg-indigo-50 px-3.5 py-2.5 text-xs font-bold text-indigo-700 shadow-xs hover:bg-indigo-100 dark:border-indigo-800 dark:bg-indigo-950 dark:text-indigo-300"
                                    >
                                        <span>✓ Guardar y seguir con Cámara 📷</span>
                                    </button>
                                )}
                                <button
                                    type="button"
                                    onClick={() => handleConfirmScanModal(false)}
                                    className="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-5 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-emerald-700 active:scale-95"
                                >
                                    <span>Confirmar y Agregar (Enter) ✓</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* ========================================================================= */}
            {/* MODAL: BÚSQUEDA MANUAL EN CATÁLOGO                                        */}
            {/* ========================================================================= */}
            {showCatalogSearch && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs">
                    <div className="w-full max-w-xl overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-2xl dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="flex items-center justify-between border-b border-slate-100 px-6 py-4 dark:border-neutral-800">
                            <h3 className="text-sm font-bold text-slate-900 dark:text-white">
                                Buscar Producto Extra en Catálogo
                            </h3>
                            <button
                                type="button"
                                onClick={() => setShowCatalogSearch(false)}
                                className="rounded-xl p-1.5 text-slate-400 hover:text-slate-600"
                            >
                                ✕
                            </button>
                        </div>
                        <div className="p-6 space-y-4">
                            <form onSubmit={handleSearchCatalog} className="flex gap-2">
                                <input
                                    type="text"
                                    value={catalogSearch}
                                    onChange={(e) => setCatalogSearch(e.target.value)}
                                    placeholder="Escribe nombre, SKU o código de barras..."
                                    className="flex-1 rounded-xl border border-slate-300 px-3.5 py-2 text-xs text-slate-900 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                                <button
                                    type="submit"
                                    disabled={isSearchingCatalog}
                                    className="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white hover:bg-indigo-700 disabled:opacity-50"
                                >
                                    {isSearchingCatalog ? 'Buscando...' : 'Buscar'}
                                </button>
                            </form>

                            <div className="max-h-60 overflow-y-auto divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-neutral-800 dark:border-neutral-800">
                                {catalogResults.length === 0 ? (
                                    <p className="p-4 text-center text-xs text-slate-400">
                                        Escribe un término para buscar productos en el catálogo.
                                    </p>
                                ) : (
                                    catalogResults.map((p) => (
                                        <div
                                            key={p.id}
                                            onClick={() => addCatalogProductToAudit(p)}
                                            className="flex cursor-pointer items-center justify-between p-3 text-xs hover:bg-indigo-50/50 dark:hover:bg-neutral-800/50"
                                        >
                                            <div className="min-w-0 flex-1 pr-2">
                                                <p className="font-bold text-slate-900 dark:text-white truncate">{p.name}</p>
                                                <p className="font-mono text-[11px] text-slate-500">
                                                    SKU: {p.sku} {p.barcode && `| Cód: ${p.barcode}`}
                                                </p>
                                            </div>
                                            <button
                                                type="button"
                                                className="shrink-0 rounded-lg bg-indigo-600 px-2.5 py-1 text-[11px] font-bold text-white"
                                            >
                                                + Seleccionar
                                            </button>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* ========================================================================= */}
            {/* MODAL DE CONFIRMACIÓN: APLICAR AJUSTE DE INVENTARIO                        */}
            {/* ========================================================================= */}
            {showApplyModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs">
                    <div className="w-full max-w-md overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-300">
                            <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                            </svg>
                        </div>
                        <h3 className="mt-4 text-center text-lg font-bold text-slate-900 dark:text-white">
                            ¿Aplicar Ajuste Físico al Inventario?
                        </h3>
                        <p className="mt-2 text-center text-xs text-slate-500 dark:text-slate-400">
                            Se registrarán movimientos de ajuste en el almacén para que el stock del sistema quede idéntico a lo que contaste:
                        </p>

                        <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-3.5 text-xs font-semibold text-slate-700 dark:border-neutral-800 dark:bg-neutral-950 dark:text-slate-300 space-y-1">
                            <div className="flex justify-between">
                                <span>Modalidad:</span>
                                <span className="font-bold text-slate-900 dark:text-white">
                                    {auditType === 'shelf' ? `Anaquel ${selectedShelfCode}` : `Marca ${selectedBrand}`}
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span>Piezas contadas físicamente:</span>
                                <span className="font-bold text-emerald-600">{totals.countedPieces} uds</span>
                            </div>
                            <div className="flex justify-between">
                                <span>Diferencia neta a mover:</span>
                                <span className={`font-bold ${totals.diffPieces > 0 ? 'text-blue-600' : totals.diffPieces < 0 ? 'text-rose-600' : 'text-slate-700'}`}>
                                    {totals.diffPieces > 0 ? `+${totals.diffPieces}` : totals.diffPieces} uds
                                </span>
                            </div>
                        </div>

                        <div className="mt-6 flex justify-end gap-2.5">
                            <button
                                type="button"
                                disabled={isSubmitting}
                                onClick={() => setShowApplyModal(false)}
                                className="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-300"
                            >
                                Cancelar
                            </button>
                            <button
                                type="button"
                                disabled={isSubmitting}
                                onClick={() => submitAudit(true)}
                                className="rounded-xl bg-emerald-600 px-5 py-2.5 text-xs font-bold text-white hover:bg-emerald-700 active:scale-95 disabled:opacity-50"
                            >
                                {isSubmitting ? 'Aplicando Ajuste...' : 'Sí, Ajustar Inventario Ahora'}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* ========================================================================= */}
            {/* MODAL DE ADVERTENCIA: PRODUCTO FUERA DE ANAQUEL O MARCA                   */}
            {/* ========================================================================= */}
            {mismatchModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-xs animate-in fade-in duration-150">
                    <div className="w-full max-w-md overflow-hidden rounded-3xl border-2 border-rose-300 bg-white p-6 shadow-2xl dark:border-rose-800 dark:bg-neutral-900">
                        <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-rose-100 text-rose-600 dark:bg-rose-950 dark:text-rose-300">
                            <span className="text-3xl">⛔</span>
                        </div>

                        <h3 className="mt-4 text-center text-lg font-black text-rose-900 dark:text-rose-200">
                            {mismatchModal.title}
                        </h3>

                        <p className="mt-2 text-center text-xs text-slate-600 dark:text-slate-300">
                            {mismatchModal.message}
                        </p>

                        {/* Product Card Inside Modal */}
                        <div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50 p-3.5 dark:border-neutral-800 dark:bg-neutral-950">
                            <p className="text-xs font-bold text-slate-900 dark:text-white line-clamp-2">
                                {mismatchModal.product?.name}
                            </p>
                            <div className="mt-1 flex flex-wrap gap-2 font-mono text-[11px] text-slate-500 dark:text-slate-400">
                                <span>SKU: {mismatchModal.product?.sku}</span>
                                {mismatchModal.product?.barcode && <span>• Cód: {mismatchModal.product.barcode}</span>}
                            </div>

                            <div className="mt-3 grid grid-cols-2 gap-2 border-t border-slate-200 pt-2 text-xs dark:border-neutral-800">
                                <div className="rounded-xl bg-rose-50 p-2 dark:bg-rose-950/40">
                                    <span className="block text-[10px] font-bold uppercase text-rose-700 dark:text-rose-400">Ubicación Actual:</span>
                                    <span className="font-bold text-rose-900 dark:text-rose-200">{mismatchModal.actualTarget}</span>
                                </div>
                                <div className="rounded-xl bg-indigo-50 p-2 dark:bg-indigo-950/40">
                                    <span className="block text-[10px] font-bold uppercase text-indigo-700 dark:text-indigo-400">Anaquel Auditado:</span>
                                    <span className="font-bold text-indigo-900 dark:text-indigo-200">{mismatchModal.expectedTarget}</span>
                                </div>
                            </div>
                        </div>

                        <p className="mt-3 text-center text-[11px] font-medium text-slate-500 dark:text-slate-400">
                            👉 Por favor regresa este producto a su lugar físico correcto para evitar desajustes de inventario.
                        </p>

                        <div className="mt-5 flex justify-center">
                            <button
                                type="button"
                                autoFocus
                                onClick={() => {
                                    setMismatchModal(null)
                                    setTimeout(() => gunInputRef.current?.focus(), 100)
                                }}
                                className="w-full rounded-xl bg-slate-900 py-3 text-xs font-bold text-white shadow-sm hover:bg-slate-800 active:scale-95 dark:bg-white dark:text-slate-900"
                            >
                                Entendido, no agregar este producto
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* ========================================================================= */}
            {/* ESCÁNER DE CÓDIGO DE BARRAS CON CÁMARA (CELULAR / WEB)                    */}
            {/* ========================================================================= */}
            <CameraBarcodeScanner
                isOpen={isCameraOpen}
                onClose={() => setIsCameraOpen(false)}
                onScan={(code) => {
                    setIsCameraOpen(false)
                    processBarcode(code, true)
                }}
                title={
                    auditType === 'shelf'
                        ? `Escanear con Cámara (Anaquel ${selectedShelfCode})`
                        : `Escanear con Cámara (Marca ${selectedBrand})`
                }
            />
        </AppShell>
    )
}
