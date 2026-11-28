import { useEffect, useRef, useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import axios from 'axios'
import AppShell from '@/Components/layout/AppShell'
import CameraBarcodeScanner from '@/Components/CameraBarcodeScanner'
import {
    INDIVIDUAL_UNITS,
    OPERATION_TYPE_LABELS,
    UOM_LABELS,
    computeNormalizedPhysicalQuantity,
    generateIdempotencyKey,
    isIndividualUnit,
    operatingModeMeta,
    shouldIgnoreCameraDuplicateScan,
    summarizeLines,
    upsertDispatchLine,
} from '@/lib/warehouseDispatchScanner'

function toLocalDateTimeInput(isoUtc) {
    if (!isoUtc) {
        const now = new Date()
        const pad = (n) => String(n).padStart(2, '0')
        return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`
    }
    const d = new Date(isoUtc)
    if (Number.isNaN(d.getTime())) return ''
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'America/Hermosillo',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    })
        .formatToParts(d)
        .reduce((acc, p) => {
            acc[p.type] = p.value
            return acc
        }, {})
    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`
}

export default function Form({
    dispatch = null,
    destinations = [],
    locations = [],
    operationTypes = [],
    unitsOfMeasure = [],
    operatingMode = 'RECORD_ONLY',
    retroactiveToleranceHours = 24,
    capabilities = {},
}) {
    const isEdit = Boolean(dispatch)
    const modeMeta = operatingModeMeta(operatingMode)
    const isReadOnly = operatingMode === 'READ_ONLY'

    const [createKey] = useState(() => generateIdempotencyKey())
    const [destinationId, setDestinationId] = useState(dispatch?.destination_id ? String(dispatch.destination_id) : '')
    const [operationType, setOperationType] = useState(dispatch?.operation_type || 'INTERNAL_CONSUMPTION')
    const [sourceLocationId, setSourceLocationId] = useState(dispatch?.source_location_id ? String(dispatch.source_location_id) : '')
    const [effectiveAt, setEffectiveAt] = useState(toLocalDateTimeInput(dispatch?.effective_at))
    const [retroactiveReason, setRetroactiveReason] = useState(dispatch?.retroactive_reason || '')
    const [receivedByName, setReceivedByName] = useState(dispatch?.received_by_name || '')
    const [notes, setNotes] = useState(dispatch?.notes || '')
    const [lines, setLines] = useState(() =>
        (dispatch?.items || []).map((item) => ({
            kind: item.inventory_product_id ? 'registered' : 'provisional',
            inventory_product_id: item.inventory_product_id,
            provisional_item_id: item.provisional_item_id,
            inventory_location_id: item.inventory_location_id,
            capture_method: item.capture_method || 'TEXT_SEARCH',
            captured_barcode: item.captured_barcode || '',
            captured_sku: item.captured_sku || '',
            captured_name: item.captured_name || '',
            captured_brand: item.captured_brand || '',
            captured_line: item.captured_line || '',
            captured_tone: item.captured_tone || '',
            captured_presentation: item.captured_presentation || '',
            captured_quantity: item.captured_quantity || 1,
            captured_unit_of_measure: item.captured_unit_of_measure || 'UNIT',
            unit_conversion_factor: item.unit_conversion_factor,
            normalized_physical_quantity: item.normalized_physical_quantity,
            is_ambiguous: item.identification_status === 'AMBIGUOUS_PENDING',
            line_notes: item.line_notes || '',
            location_stocks: [],
            available_global: null,
        })),
    )

    // Scanner & search state
    const scanInputRef = useRef(null)
    const lastCameraScanRef = useRef({ code: '', at: 0 })
    const [scanCode, setScanCode] = useState('')
    const [cameraOpen, setCameraOpen] = useState(false)
    const [searchQuery, setSearchQuery] = useState('')
    const [searchBrand, setSearchBrand] = useState('')
    const [searchTone, setSearchTone] = useState('')
    const [searchResults, setSearchResults] = useState({ registered: [], provisional: [] })
    const [searching, setSearching] = useState(false)
    const [scanFeedback, setScanFeedback] = useState(null)
    const [ambiguousModal, setAmbiguousModal] = useState(null)
    const [showProvisionalDrawer, setShowProvisionalDrawer] = useState(false)
    const [provisionalDraft, setProvisionalDraft] = useState({
        captured_barcode: '',
        captured_sku: '',
        captured_name: '',
        captured_brand: '',
        captured_line: '',
        captured_tone: '',
        captured_presentation: '',
        captured_quantity: 1,
        captured_unit_of_measure: 'UNIT',
        unit_conversion_factor: '',
        is_ambiguous: false,
        line_notes: '',
    })
    const [submitting, setSubmitting] = useState(false)
    const [errorBanner, setErrorBanner] = useState(null)

    const totals = summarizeLines(lines)
    const isCameraPaused = Boolean(ambiguousModal) || showProvisionalDrawer

    useEffect(() => {
        if (!searchQuery.trim() && !searchBrand.trim() && !searchTone.trim()) {
            setSearchResults({ registered: [], provisional: [] })
            return
        }

        const handle = setTimeout(async () => {
            setSearching(true)
            try {
                const { data } = await axios.get('/almacen/salidas/buscar-productos', {
                    params: {
                        q: searchQuery.trim(),
                        mode: 'search',
                        brand: searchBrand.trim() || undefined,
                        tone: searchTone.trim() || undefined,
                        location_id: sourceLocationId || undefined,
                    },
                })
                setSearchResults({
                    registered: data.registered || [],
                    provisional: data.provisional || [],
                })
            } catch {
                // Keep UI usable if transient search fails
            } finally {
                setSearching(false)
            }
        }, 250)

        return () => clearTimeout(handle)
    }, [searchQuery, searchBrand, searchTone, sourceLocationId])

    const addRegisteredProduct = (product, captureMethod = 'TEXT_SEARCH') => {
        const chosenLocationId = sourceLocationId
            ? Number(sourceLocationId)
            : (product.suggested_location_id || null)
        const { lines: next } = upsertDispatchLine(lines, {
            kind: 'registered',
            inventory_product_id: product.inventory_product_id,
            provisional_item_id: null,
            inventory_location_id: chosenLocationId,
            capture_method: captureMethod,
            captured_barcode: product.barcode || '',
            captured_sku: product.sku || '',
            captured_name: product.name || '',
            captured_brand: product.brand || '',
            captured_line: '',
            captured_tone: '',
            captured_presentation: '',
            captured_quantity: 1,
            captured_unit_of_measure: 'UNIT',
            unit_conversion_factor: 1,
            is_ambiguous: false,
            line_notes: '',
            location_stocks: product.location_stocks || [],
            available_global: product.available_global,
            is_active: product.is_active,
        })
        setLines(next)
        setScanFeedback({
            type: product.is_active === false ? 'warning' : 'success',
            message:
                product.is_active === false
                    ? `Agregado ${product.name} (INACTIVO: no descontará inventario automáticamente).`
                    : `Agregado: ${product.name} (${product.sku}).`,
        })
    }

    const addExistingProvisional = (prov, captureMethod = 'TEXT_SEARCH') => {
        const { lines: next } = upsertDispatchLine(lines, {
            kind: 'provisional',
            inventory_product_id: null,
            provisional_item_id: prov.provisional_item_id,
            inventory_location_id: sourceLocationId ? Number(sourceLocationId) : null,
            capture_method: captureMethod,
            captured_barcode: prov.barcode || '',
            captured_sku: prov.known_sku || '',
            captured_name: prov.name || '',
            captured_brand: prov.brand || '',
            captured_line: prov.line || '',
            captured_tone: prov.tone || '',
            captured_presentation: prov.presentation || '',
            captured_quantity: 1,
            captured_unit_of_measure: prov.unit_of_measure || 'UNIT',
            unit_conversion_factor: isIndividualUnit(prov.unit_of_measure)
                ? 1
                : (prov.default_units_per_container || null),
            is_ambiguous: prov.status === 'AMBIGUOUS_REVIEW',
            line_notes: '',
            provisional_code: prov.provisional_code,
        })
        setLines(next)
        setScanFeedback({ type: 'info', message: `Agregada ficha provisional ${prov.provisional_code}: ${prov.name}.` })
    }

    const openProvisionalDrawerWithCode = (code = '', isAmbiguous = false) => {
        setProvisionalDraft({
            captured_barcode: code,
            captured_sku: '',
            captured_name: '',
            captured_brand: '',
            captured_line: '',
            captured_tone: '',
            captured_presentation: '',
            captured_quantity: 1,
            captured_unit_of_measure: 'UNIT',
            unit_conversion_factor: '',
            is_ambiguous: isAmbiguous,
            line_notes: '',
        })
        setShowProvisionalDrawer(true)
    }

    const processScanCode = async (rawCode, fromCamera = false) => {
        const code = String(rawCode || '').trim()
        if (!code) return

        if (fromCamera) {
            const now = Date.now()
            if (shouldIgnoreCameraDuplicateScan(lastCameraScanRef.current, code, now, 1500)) {
                return
            }
            lastCameraScanRef.current = { code, at: now }
        }

        try {
            const { data } = await axios.get('/almacen/salidas/buscar-productos', {
                params: {
                    q: code,
                    mode: 'scan',
                    location_id: sourceLocationId || undefined,
                },
            })

            if (data.match_status === 'EXACT_REGISTERED' || data.match_status === 'INACTIVE_PRODUCT') {
                addRegisteredProduct(data.item, 'BARCODE_SCAN')
                return
            }

            if (data.match_status === 'EXACT_PROVISIONAL') {
                addExistingProvisional(data.item, 'BARCODE_SCAN')
                return
            }

            if (data.match_status === 'BLOCKED_KIT') {
                setScanFeedback({ type: 'error', message: data.message })
                return
            }

            if (data.match_status === 'AMBIGUOUS' || data.match_status === 'AMBIGUOUS_PROVISIONAL') {
                setAmbiguousModal({
                    code,
                    message: data.message,
                    kind: data.match_status === 'AMBIGUOUS' ? 'registered' : 'provisional',
                    candidates: data.candidates || [],
                })
                return
            }

            setScanFeedback({ type: 'warning', message: data.message || `Código ${code} no encontrado.` })
            openProvisionalDrawerWithCode(code, false)
        } catch {
            setScanFeedback({ type: 'error', message: 'Error consultando el código escaneado.' })
        }
    }

    const handleHidKeyDown = (e) => {
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault()
            e.stopPropagation()
            const code = scanCode.trim()
            setScanCode('')
            if (code) {
                processScanCode(code, false)
            }
        }
    }

    const handleAddManualProvisional = (e) => {
        e.preventDefault()
        if (!provisionalDraft.captured_name.trim()) return

        const uom = provisionalDraft.captured_unit_of_measure || 'UNIT'
        const conv = isIndividualUnit(uom)
            ? 1
            : (provisionalDraft.unit_conversion_factor ? Number(provisionalDraft.unit_conversion_factor) : null)

        const { lines: next } = upsertDispatchLine(lines, {
            kind: 'provisional',
            inventory_product_id: null,
            provisional_item_id: null,
            inventory_location_id: sourceLocationId ? Number(sourceLocationId) : null,
            capture_method: 'MANUAL_PROVISIONAL',
            ...provisionalDraft,
            captured_quantity: Math.max(1, Number(provisionalDraft.captured_quantity || 1)),
            captured_unit_of_measure: uom,
            unit_conversion_factor: conv,
        })
        setLines(next)
        setShowProvisionalDrawer(false)
        setScanFeedback({ type: 'info', message: `Partida provisional agregada: ${provisionalDraft.captured_name}` })
    }

    const updateLineField = (index, field, value) => {
        setLines((prev) =>
            prev.map((line, i) => {
                if (i !== index) return line
                const updated = { ...line, [field]: value }
                if (field === 'captured_unit_of_measure' && isIndividualUnit(value)) {
                    updated.unit_conversion_factor = 1
                }
                updated.normalized_physical_quantity = computeNormalizedPhysicalQuantity(
                    updated.captured_quantity,
                    updated.captured_unit_of_measure,
                    updated.unit_conversion_factor,
                )
                return updated
            }),
        )
    }

    const removeLine = (index) => {
        setLines((prev) => prev.filter((_, i) => i !== index))
    }

    const handleDestinationChange = (value) => {
        setDestinationId(value)
        const selected = destinations.find((d) => String(d.id) === String(value))
        if (selected?.default_operation_type && !isEdit) {
            setOperationType(selected.default_operation_type)
        }
        if (selected?.contact_name && !receivedByName) {
            setReceivedByName(selected.contact_name)
        }
    }

    const isProbablyRetroactive = (() => {
        if (!effectiveAt) return false
        const selectedDate = new Date(effectiveAt)
        if (Number.isNaN(selectedDate.getTime())) return false
        return Math.abs(Date.now() - selectedDate.getTime()) > retroactiveToleranceHours * 3600 * 1000
    })()

    const handleSubmit = (e) => {
        e.preventDefault()
        if (submitting || isReadOnly) return
        setErrorBanner(null)

        if (!destinationId) {
            setErrorBanner('Selecciona un salón o destino.')
            return
        }
        if (lines.length === 0) {
            setErrorBanner('Agrega al menos una partida antes de guardar.')
            return
        }

        const payload = {
            create_idempotency_key: isEdit ? undefined : createKey,
            expected_draft_version: isEdit ? dispatch.draft_version : undefined,
            destination_id: Number(destinationId),
            source_location_id: sourceLocationId ? Number(sourceLocationId) : null,
            operation_type: operationType,
            effective_at: effectiveAt ? effectiveAt.replace('T', ' ') + ':00' : null,
            retroactive_reason: isProbablyRetroactive ? retroactiveReason : null,
            received_by_name: receivedByName || null,
            notes: notes || null,
            items: lines.map((line) => ({
                kind: line.kind,
                inventory_product_id: line.inventory_product_id || null,
                provisional_item_id: line.provisional_item_id || null,
                inventory_location_id: line.inventory_location_id ? Number(line.inventory_location_id) : null,
                capture_method: line.capture_method,
                captured_barcode: line.captured_barcode || null,
                captured_sku: line.captured_sku || null,
                captured_name: line.captured_name,
                captured_brand: line.captured_brand || null,
                captured_line: line.captured_line || null,
                captured_tone: line.captured_tone || null,
                captured_presentation: line.captured_presentation || null,
                captured_quantity: Math.max(1, Number(line.captured_quantity || 1)),
                captured_unit_of_measure: line.captured_unit_of_measure || 'UNIT',
                unit_conversion_factor: isIndividualUnit(line.captured_unit_of_measure)
                    ? 1
                    : (line.unit_conversion_factor ? Number(line.unit_conversion_factor) : null),
                is_ambiguous: Boolean(line.is_ambiguous),
                line_notes: line.line_notes || null,
            })),
        }

        setSubmitting(true)
        const options = {
            onError: (errors) => {
                const firstErr = Object.values(errors)[0]
                setErrorBanner(firstErr || 'Revisa los campos del formulario.')
            },
            onFinish: () => setSubmitting(false),
        }

        if (isEdit) {
            router.put(`/almacen/salidas/${dispatch.id}`, payload, options)
        } else {
            router.post('/almacen/salidas', payload, options)
        }
    }

    return (
        <AppShell title={isEdit ? `Editar ${dispatch.dispatch_number}` : 'Nueva Salida de Almacén'}>
            <Head title={isEdit ? `Editar ${dispatch.dispatch_number}` : 'Nueva Salida de Almacén'} />

            <form onSubmit={(e) => e.preventDefault()} className="space-y-4">
                <div className={`rounded-xl border px-4 py-2.5 text-xs ${modeMeta.badgeClass}`}>
                    <strong>{modeMeta.label}:</strong> {modeMeta.description}
                </div>

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <Link href="/almacen/salidas" className="text-xs font-semibold text-slate-500 hover:underline">
                            ← Volver al historial
                        </Link>
                        <h1 className="text-xl font-bold text-slate-900 dark:text-white">
                            {isEdit ? `Editar Borrador ${dispatch.dispatch_number}` : 'Nueva Salida de Almacén'}
                        </h1>
                    </div>
                    <button
                        type="button"
                        onClick={handleSubmit}
                        disabled={submitting || isReadOnly || lines.length === 0}
                        className="rounded-lg bg-emerald-600 px-5 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50"
                    >
                        {submitting ? 'Guardando...' : isEdit ? 'Guardar cambios del borrador' : 'Guardar borrador y revisar'}
                    </button>
                </div>

                {errorBanner && (
                    <div className="rounded-lg border border-rose-300 bg-rose-50 px-4 py-2.5 text-xs font-semibold text-rose-800">
                        {errorBanner}
                    </div>
                )}

                {/* Header fields */}
                <div className="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-white p-4 text-xs shadow-sm sm:grid-cols-2 lg:grid-cols-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <div>
                        <label className="font-bold text-slate-700 dark:text-neutral-200">1. Salón / Destino *</label>
                        <select
                            required
                            value={destinationId}
                            onChange={(e) => handleDestinationChange(e.target.value)}
                            className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <option value="">Selecciona un salón...</option>
                            {destinations.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.name} ({d.code})
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="font-bold text-slate-700 dark:text-neutral-200">2. Tipo de salida *</label>
                        <select
                            required
                            value={operationType}
                            onChange={(e) => setOperationType(e.target.value)}
                            className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            {operationTypes.map((op) => (
                                <option key={op} value={op}>
                                    {OPERATION_TYPE_LABELS[op] || op}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="font-bold text-slate-700 dark:text-neutral-200">Ubicación origen sugerida</label>
                        <select
                            value={sourceLocationId}
                            onChange={(e) => setSourceLocationId(e.target.value)}
                            className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <option value="">Según cada producto</option>
                            {locations.map((loc) => (
                                <option key={loc.id} value={loc.id}>
                                    {loc.code}{loc.name ? ` · ${loc.name}` : ''}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div>
                        <label className="font-bold text-slate-700 dark:text-neutral-200">Fecha efectiva (Hermosillo)</label>
                        <input
                            type="datetime-local"
                            value={effectiveAt}
                            onChange={(e) => setEffectiveAt(e.target.value)}
                            className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950"
                        />
                    </div>
                    <div>
                        <label className="font-bold text-slate-700 dark:text-neutral-200">Quien recibe en salón</label>
                        <input
                            type="text"
                            value={receivedByName}
                            onChange={(e) => setReceivedByName(e.target.value)}
                            placeholder="Nombre de quien recibe..."
                            className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950"
                        />
                    </div>
                    <div className="sm:col-span-2 lg:col-span-3">
                        <label className="font-bold text-slate-700 dark:text-neutral-200">Observaciones generales</label>
                        <input
                            type="text"
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            placeholder="Comentarios de entrega..."
                            className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950"
                        />
                    </div>
                    {isProbablyRetroactive && (
                        <div className="sm:col-span-2 lg:col-span-4 rounded-lg border border-purple-300 bg-purple-50 p-3">
                            <label className="font-bold text-purple-900">
                                Salida retroactiva (&gt;{retroactiveToleranceHours}h) — Motivo obligatorio{' '}
                                {!capabilities.can_create_retroactive && '(Requiere Operaciones/Admin)'}
                            </label>
                            <input
                                type="text"
                                required
                                value={retroactiveReason}
                                onChange={(e) => setRetroactiveReason(e.target.value)}
                                placeholder="Motivo por el que se captura fuera de las 24 horas..."
                                className="mt-1 w-full rounded-lg border border-purple-300 bg-white px-3 py-2"
                            />
                        </div>
                    )}
                </div>

                {/* Fast Scan + Search Bar */}
                <div className="space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-sm font-bold text-slate-900 dark:text-white">
                            3. Captura rápida (Escáner HID / Cámara / Búsqueda / Provisional)
                        </h2>
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => setCameraOpen((v) => !v)}
                                className="rounded-lg border border-slate-300 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-100"
                            >
                                {cameraOpen ? 'Cerrar cámara' : 'Escanear con cámara'}
                            </button>
                            <button
                                type="button"
                                onClick={() => openProvisionalDrawerWithCode('', false)}
                                className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-1.5 text-xs font-bold text-amber-800 hover:bg-amber-100"
                            >
                                + Producto provisional manual
                            </button>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-2 lg:grid-cols-4">
                        <input
                            ref={scanInputRef}
                            type="text"
                            autoFocus
                            value={scanCode}
                            onChange={(e) => setScanCode(e.target.value)}
                            onKeyDown={handleHidKeyDown}
                            placeholder="Pistola HID: escanea código y Enter..."
                            className="rounded-lg border-2 border-emerald-400 px-3 py-2 text-xs font-semibold dark:bg-neutral-950"
                        />
                        <input
                            type="text"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            placeholder="Buscar por SKU, nombre o código..."
                            className="rounded-lg border border-slate-300 px-3 py-2 text-xs dark:border-neutral-700 dark:bg-neutral-950"
                        />
                        <input
                            type="text"
                            value={searchBrand}
                            onChange={(e) => setSearchBrand(e.target.value)}
                            placeholder="Filtrar marca (ej. Igora, Wella)"
                            className="rounded-lg border border-slate-300 px-3 py-2 text-xs dark:border-neutral-700 dark:bg-neutral-950"
                        />
                        <input
                            type="text"
                            value={searchTone}
                            onChange={(e) => setSearchTone(e.target.value)}
                            placeholder="Tono tinte (ej. 0-22, 7-77)"
                            className="rounded-lg border border-slate-300 px-3 py-2 text-xs dark:border-neutral-700 dark:bg-neutral-950"
                        />
                    </div>

                    {scanFeedback && (
                        <div className={`rounded-lg px-3 py-2 text-xs font-semibold ${
                            scanFeedback.type === 'error'
                                ? 'bg-rose-50 text-rose-800'
                                : scanFeedback.type === 'warning'
                                    ? 'bg-amber-50 text-amber-800'
                                    : 'bg-emerald-50 text-emerald-800'
                        }`}>
                            {scanFeedback.message}
                        </div>
                    )}

                    {/* Search Results Dropdown */}
                    {(searching || searchResults.registered.length > 0 || searchResults.provisional.length > 0) && (
                        <div className="max-h-64 overflow-y-auto rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs dark:border-neutral-700 dark:bg-neutral-950">
                            {searching && <div className="p-2 text-slate-500">Buscando...</div>}
                            {searchResults.registered.map((p) => (
                                <button
                                    key={`reg-${p.inventory_product_id}`}
                                    type="button"
                                    onClick={() => addRegisteredProduct(p, 'TEXT_SEARCH')}
                                    className="flex w-full items-center justify-between rounded-md px-2.5 py-2 text-left hover:bg-white dark:hover:bg-neutral-800"
                                >
                                    <div>
                                        <span className="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-bold text-emerald-800">
                                            REGISTRADO
                                        </span>
                                        {!p.is_active && (
                                            <span className="ml-1 rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-bold text-slate-700">
                                                INACTIVO
                                            </span>
                                        )}
                                        <span className="ml-2 font-semibold">{p.name}</span>
                                        <span className="ml-2 font-mono text-slate-500">
                                            {p.sku}{p.barcode ? ` · ${p.barcode}` : ''}
                                        </span>
                                    </div>
                                    <div className="text-right text-[11px] text-slate-600">
                                        Disp. global: <strong>{p.available_global}</strong>
                                        {p.primary_location_code && <span className="ml-2">Ubic: {p.primary_location_code}</span>}
                                    </div>
                                </button>
                            ))}
                            {searchResults.provisional.map((prov) => (
                                <button
                                    key={`prov-${prov.provisional_item_id}`}
                                    type="button"
                                    onClick={() => addExistingProvisional(prov, 'TEXT_SEARCH')}
                                    className="flex w-full items-center justify-between rounded-md px-2.5 py-2 text-left hover:bg-white dark:hover:bg-neutral-800"
                                >
                                    <div>
                                        <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-800">
                                            PROVISIONAL · {prov.provisional_code}
                                        </span>
                                        <span className="ml-2 font-semibold">{prov.name}</span>
                                        <span className="ml-2 text-slate-500">
                                            {[prov.brand, prov.tone ? `Tono ${prov.tone}` : null, prov.presentation].filter(Boolean).join(' · ')}
                                        </span>
                                    </div>
                                    <span className="text-[11px] font-semibold text-amber-700">Reutilizar</span>
                                </button>
                            ))}
                        </div>
                    )}
                </div>

                {/* Lines table */}
                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3 text-xs dark:border-neutral-800">
                        <strong>Partidas capturadas ({totals.totalLines})</strong>
                        <div className="flex flex-wrap gap-3">
                            <span>Piezas físicas: <strong>{totals.totalPhysicalUnits}</strong></span>
                            {totals.unconvertedContainersCount > 0 && (
                                <span className="text-amber-700">
                                    Contenedores sin equivalencia: <strong>{totals.unconvertedContainersQty}</strong> ({totals.unconvertedContainersCount} partida/s)
                                </span>
                            )}
                            <span>Registradas: <strong>{totals.registeredCount}</strong></span>
                            <span>Provisionales: <strong>{totals.provisionalCount}</strong></span>
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-xs dark:divide-neutral-800">
                            <thead className="bg-slate-50 dark:bg-neutral-950">
                                <tr>
                                    <th className="px-3 py-2 text-left font-bold">Producto</th>
                                    <th className="px-3 py-2 text-left font-bold">Ubicación origen (editable)</th>
                                    <th className="px-3 py-2 text-left font-bold">Unidad / Conv.</th>
                                    <th className="px-3 py-2 text-right font-bold">Cantidad</th>
                                    <th className="px-3 py-2 text-left font-bold">Notas</th>
                                    <th className="px-3 py-2 text-right font-bold">Quitar</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                {lines.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-8 text-center text-slate-500">
                                            Escanea un código de barras, busca en el catálogo o captura un producto provisional.
                                        </td>
                                    </tr>
                                ) : (
                                    lines.map((line, idx) => (
                                        <tr key={idx}>
                                            <td className="px-3 py-2.5">
                                                <div className="flex items-center gap-1.5">
                                                    <span className={`rounded px-1.5 py-0.5 text-[10px] font-bold ${
                                                        line.kind === 'registered'
                                                            ? 'bg-emerald-100 text-emerald-800'
                                                            : 'bg-amber-100 text-amber-800'
                                                    }`}>
                                                        {line.kind === 'registered' ? 'REGISTRADO' : 'PROVISIONAL'}
                                                    </span>
                                                    <span className="font-semibold">{line.captured_name}</span>
                                                </div>
                                                <div className="mt-0.5 text-[11px] text-slate-500">
                                                    {[line.captured_sku, line.captured_barcode, line.captured_brand, line.captured_tone ? `Tono ${line.captured_tone}` : null].filter(Boolean).join(' · ')}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <select
                                                    value={line.inventory_location_id || ''}
                                                    onChange={(e) =>
                                                        updateLineField(
                                                            idx,
                                                            'inventory_location_id',
                                                            e.target.value ? Number(e.target.value) : null,
                                                        )
                                                    }
                                                    className="w-40 rounded border border-slate-300 px-2 py-1 text-xs dark:border-neutral-700 dark:bg-neutral-950"
                                                >
                                                    <option value="">Sin ubicación</option>
                                                    {locations.map((loc) => (
                                                        <option key={loc.id} value={loc.id}>
                                                            {loc.code}
                                                        </option>
                                                    ))}
                                                </select>
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {line.kind === 'registered' ? (
                                                    <span>{UOM_LABELS[line.captured_unit_of_measure] || 'Pieza'} (1:1)</span>
                                                ) : (
                                                    <div className="flex items-center gap-1">
                                                        <select
                                                            value={line.captured_unit_of_measure}
                                                            onChange={(e) => updateLineField(idx, 'captured_unit_of_measure', e.target.value)}
                                                            className="rounded border border-slate-300 px-1.5 py-1 text-xs"
                                                        >
                                                            {unitsOfMeasure.map((u) => (
                                                                <option key={u} value={u}>
                                                                    {UOM_LABELS[u] || u}
                                                                </option>
                                                            ))}
                                                        </select>
                                                        {!isIndividualUnit(line.captured_unit_of_measure) && (
                                                            <input
                                                                type="number"
                                                                min={2}
                                                                placeholder="Pzas/caja"
                                                                value={line.unit_conversion_factor || ''}
                                                                onChange={(e) =>
                                                                    updateLineField(
                                                                        idx,
                                                                        'unit_conversion_factor',
                                                                        e.target.value ? Number(e.target.value) : null,
                                                                    )
                                                                }
                                                                className="w-20 rounded border border-amber-300 px-1.5 py-1 text-xs"
                                                            />
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-3 py-2.5 text-right">
                                                <input
                                                    type="number"
                                                    min={1}
                                                    value={line.captured_quantity}
                                                    onChange={(e) => updateLineField(idx, 'captured_quantity', Math.max(1, Number(e.target.value || 1)))}
                                                    className="w-20 rounded border border-slate-300 px-2 py-1 text-right font-bold dark:border-neutral-700 dark:bg-neutral-950"
                                                />
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <input
                                                    type="text"
                                                    value={line.line_notes || ''}
                                                    onChange={(e) => updateLineField(idx, 'line_notes', e.target.value)}
                                                    placeholder="Opcional"
                                                    className="w-36 rounded border border-slate-300 px-2 py-1 text-xs dark:border-neutral-700 dark:bg-neutral-950"
                                                />
                                            </td>
                                            <td className="px-3 py-2.5 text-right">
                                                <button
                                                    type="button"
                                                    onClick={() => removeLine(idx)}
                                                    className="font-bold text-rose-600 hover:underline"
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
            </form>

            {/* Camera Scanner */}
            <CameraBarcodeScanner
                isOpen={cameraOpen}
                isPaused={isCameraPaused}
                onClose={() => setCameraOpen(false)}
                onScan={(code) => processScanCode(code, true)}
                title="Escanear producto para Salida de Almacén"
            />

            {/* Ambiguity Selector Modal */}
            {ambiguousModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                        <h3 className="text-base font-bold text-amber-800">Coincidencia ambigua: {ambiguousModal.code}</h3>
                        <p className="mt-1 text-xs text-slate-600">{ambiguousModal.message}</p>
                        <div className="mt-3 max-h-64 space-y-2 overflow-y-auto text-xs">
                            {ambiguousModal.candidates.map((cand, idx) => (
                                <button
                                    key={idx}
                                    type="button"
                                    onClick={() => {
                                        if (ambiguousModal.kind === 'registered') {
                                            addRegisteredProduct(cand, 'BARCODE_SCAN')
                                        } else {
                                            addExistingProvisional(cand, 'BARCODE_SCAN')
                                        }
                                        setAmbiguousModal(null)
                                    }}
                                    className="flex w-full items-center justify-between rounded-lg border border-slate-200 p-2.5 text-left hover:bg-slate-50"
                                >
                                    <div>
                                        <div className="font-bold">{cand.name}</div>
                                        <div className="text-[11px] text-slate-500">
                                            {cand.sku || cand.provisional_code} · {cand.brand || 'Sin marca'}
                                        </div>
                                    </div>
                                    <span className="font-bold text-emerald-700">Seleccionar</span>
                                </button>
                            ))}
                        </div>
                        <div className="mt-4 flex justify-between">
                            <button
                                type="button"
                                onClick={() => {
                                    const code = ambiguousModal.code
                                    setAmbiguousModal(null)
                                    openProvisionalDrawerWithCode(code, true)
                                }}
                                className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-bold text-amber-800"
                            >
                                Capturar como provisional (sin vincular)
                            </button>
                            <button
                                type="button"
                                onClick={() => setAmbiguousModal(null)}
                                className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold"
                            >
                                Cerrar
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Quick Provisional Drawer Modal */}
            {showProvisionalDrawer && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <form
                        onSubmit={handleAddManualProvisional}
                        className="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900"
                    >
                        <h3 className="text-base font-bold text-amber-900 dark:text-amber-300">
                            Registrar producto provisional (sin crear producto maestro)
                        </h3>
                        <p className="mt-1 text-xs text-slate-500">
                            Se conservarán los datos exactos capturados y podrá vincularse posteriormente al catálogo maestro.
                        </p>
                        <div className="mt-3 grid grid-cols-1 gap-2.5 text-xs sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <label className="font-bold">Nombre descriptivo *</label>
                                <input
                                    required
                                    type="text"
                                    value={provisionalDraft.captured_name}
                                    onChange={(e) => setProvisionalDraft({ ...provisionalDraft, captured_name: e.target.value })}
                                    placeholder="Ej. Tinte Igora Royal 0-22 60ml"
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                />
                            </div>
                            <div>
                                <label className="font-bold">Código de barras</label>
                                <input
                                    type="text"
                                    value={provisionalDraft.captured_barcode}
                                    onChange={(e) => setProvisionalDraft({ ...provisionalDraft, captured_barcode: e.target.value })}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                />
                            </div>
                            <div>
                                <label className="font-bold">SKU conocido</label>
                                <input
                                    type="text"
                                    value={provisionalDraft.captured_sku}
                                    onChange={(e) => setProvisionalDraft({ ...provisionalDraft, captured_sku: e.target.value })}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                />
                            </div>
                            <div>
                                <label className="font-bold">Marca</label>
                                <input
                                    type="text"
                                    value={provisionalDraft.captured_brand}
                                    onChange={(e) => setProvisionalDraft({ ...provisionalDraft, captured_brand: e.target.value })}
                                    placeholder="Schwarzkopf, Wella..."
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                />
                            </div>
                            <div>
                                <label className="font-bold">Tono (si aplica)</label>
                                <input
                                    type="text"
                                    value={provisionalDraft.captured_tone}
                                    onChange={(e) => setProvisionalDraft({ ...provisionalDraft, captured_tone: e.target.value })}
                                    placeholder="0-22, 7-77..."
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                />
                            </div>
                            <div>
                                <label className="font-bold">Presentación</label>
                                <input
                                    type="text"
                                    value={provisionalDraft.captured_presentation}
                                    onChange={(e) => setProvisionalDraft({ ...provisionalDraft, captured_presentation: e.target.value })}
                                    placeholder="60 ml, 1000 ml..."
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                />
                            </div>
                            <div>
                                <label className="font-bold">Unidad de medida</label>
                                <select
                                    value={provisionalDraft.captured_unit_of_measure}
                                    onChange={(e) =>
                                        setProvisionalDraft({
                                            ...provisionalDraft,
                                            captured_unit_of_measure: e.target.value,
                                            unit_conversion_factor: INDIVIDUAL_UNITS.includes(e.target.value) ? '' : provisionalDraft.unit_conversion_factor,
                                        })
                                    }
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                >
                                    {unitsOfMeasure.map((u) => (
                                        <option key={u} value={u}>
                                            {UOM_LABELS[u] || u}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="font-bold">Cantidad *</label>
                                <input
                                    required
                                    type="number"
                                    min={1}
                                    value={provisionalDraft.captured_quantity}
                                    onChange={(e) => setProvisionalDraft({ ...provisionalDraft, captured_quantity: Number(e.target.value || 1) })}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                />
                            </div>
                            {!isIndividualUnit(provisionalDraft.captured_unit_of_measure) && (
                                <div>
                                    <label className="font-bold text-amber-800">Piezas por caja/paquete (opcional)</label>
                                    <input
                                        type="number"
                                        min={2}
                                        value={provisionalDraft.unit_conversion_factor}
                                        onChange={(e) => setProvisionalDraft({ ...provisionalDraft, unit_conversion_factor: e.target.value })}
                                        placeholder="Vacío si se desconoce"
                                        className="mt-1 w-full rounded-lg border border-amber-300 px-3 py-2"
                                    />
                                </div>
                            )}
                            <div className="sm:col-span-2">
                                <label className="font-bold">Observaciones</label>
                                <input
                                    type="text"
                                    value={provisionalDraft.line_notes}
                                    onChange={(e) => setProvisionalDraft({ ...provisionalDraft, line_notes: e.target.value })}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                />
                            </div>
                        </div>
                        <div className="mt-4 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setShowProvisionalDrawer(false)}
                                className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                className="rounded-lg bg-amber-600 px-4 py-2 text-xs font-bold text-white hover:bg-amber-700"
                            >
                                Agregar partida provisional
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </AppShell>
    )
}
