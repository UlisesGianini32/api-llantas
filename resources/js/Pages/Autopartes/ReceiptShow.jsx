import { Link, router, useForm } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import CameraBarcodeScanner from '@/Components/CameraBarcodeScanner'
import { AUTOPARTES_CAPABILITIES, hasCapability } from '@/lib/autopartesCapabilities'
import { autopartesHttp, formatAutopartesHttpError } from '@/lib/autopartesHttp'
import Layout, {
    IdentityBadge,
    baseUrl,
    button,
    card,
    dangerButton,
    field,
    secondaryButton,
    statusLabel,
} from './Layout'
import ReceiptItemModal from './ReceiptItemModal'

export default function ReceiptShow({
    branch,
    receipt,
    items = [],
    manualSearch = '',
    manualResults = [],
    lookupDisambiguation = null,
    lookupWarning = null,
    capabilities = [],
}) {
    const scanInputRef = useRef(null)
    const isDraft = receipt.status === 'draft'
    const canDraft = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.RECEIPT_DRAFT)
    const canConfirm = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.RECEIPT_CONFIRM)
    const canResolveIdentity = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.IDENTITY_RESOLVE)

    // Modal & Scanner states
    const [isCameraOpen, setIsCameraOpen] = useState(false)
    const [isModalOpen, setIsModalOpen] = useState(false)
    const [modalCandidate, setModalCandidate] = useState(null)
    const [modalEntryMode, setModalEntryMode] = useState('scan')
    const [modalScannedCode, setModalScannedCode] = useState('')
    const [scanCodeText, setScanCodeText] = useState('')
    const [isLookingUp, setIsLookingUp] = useState(false)
    const [isAddingItem, setIsAddingItem] = useState(false)
    const [scanFeedback, setScanFeedback] = useState(null)
    const [activeDisambiguation, setActiveDisambiguation] = useState(lookupDisambiguation)

    const [manualQuery, setManualQuery] = useState(manualSearch || '')
    const [editingQuantities, setEditingQuantities] = useState({})
    const [verifyingCandidate, setVerifyingCandidate] = useState(null)

    const verifyForm = useForm({
        verified_barcode: '',
        reason: '',
    })

    const confirmForm = useForm({
        confirmed: false,
        idempotency_key: receipt.suggested_idempotency_key,
        notes: receipt.notes || '',
    })

    const receiptUrl = `${baseUrl(branch)}/entradas/${receipt.id}`

    // Refocus scan input whenever modal closes or camera closes (on desktop)
    useEffect(() => {
        if (!isCameraOpen && !isModalOpen && scanInputRef.current) {
            scanInputRef.current.focus()
        }
    }, [isCameraOpen, isModalOpen])

    const performLookup = async (rawCode, mode = 'scan') => {
        const code = String(rawCode || '').trim()
        if (!code) return
        if (!isDraft || !canDraft) return
        if (isLookingUp || isAddingItem || isModalOpen) return

        setIsLookingUp(true)
        setScanFeedback(null)

        try {
            const res = await autopartesHttp.post(`${baseUrl(branch)}/entradas/buscar-codigo`, {
                code,
            })
            const data = res.data

            if (data.match_status === 'not_found' || !data.candidate) {
                if (data.requires_disambiguation && data.candidates?.length > 0) {
                    setActiveDisambiguation(data)
                    setScanFeedback({
                        type: 'warning',
                        message: data.warning || 'El código escaneado coincide con múltiples autopartes. Seleccione la correcta.',
                    })
                } else {
                    setScanFeedback({
                        type: 'error',
                        message: data.warning || `No se encontró ninguna autoparte con el código '${code}'.`,
                    })
                }
                return
            }

            if (data.match_status === 'ambiguous' || data.requires_disambiguation) {
                setActiveDisambiguation(data)
                setScanFeedback({
                    type: 'warning',
                    message: data.warning || 'Identidad ambigua o múltiples coincidencias detectadas.',
                })
                return
            }

            const candidate = data.candidate
            if (!candidate.is_receivable || candidate.identity_status === 'ambiguous') {
                setActiveDisambiguation(data)
                setScanFeedback({
                    type: 'warning',
                    message: 'Esta autoparte tiene identidad ambigua o recepción restringida. Debe resolver su identidad antes de recibir existencias físicas.',
                })
                return
            }

            // Candidato único y recepcionable encontrado
            setScanCodeText('')
            setModalCandidate(candidate)
            setModalScannedCode(code)
            setModalEntryMode(mode)
            setIsModalOpen(true)
        } catch (err) {
            setScanFeedback({
                type: 'error',
                message: formatAutopartesHttpError(err, `Error al consultar el código '${code}'.`),
            })
        } finally {
            setIsLookingUp(false)
        }
    }

    const handleCameraScan = decodedText => {
        performLookup(decodedText, 'scan')
    }

    const handleScanKeyDown = e => {
        if (e.key === 'Enter') {
            e.preventDefault()
            e.stopPropagation()
            if (isLookingUp || isAddingItem || isModalOpen) return
            performLookup(scanCodeText, 'scan')
        }
    }

    const handleScanFormSubmit = e => {
        e.preventDefault()
        e.stopPropagation()
        performLookup(scanCodeText, 'scan')
    }

    const handleSelectCandidateToModal = (candidate, mode = 'manual_assisted') => {
        if (!isDraft || !canDraft) return
        if (!candidate.is_receivable || candidate.identity_status === 'ambiguous') {
            setScanFeedback({
                type: 'warning',
                message: 'Esta autoparte tiene identidad ambigua o recepción restringida. Debe resolver su identidad antes de recibir existencias físicas.',
            })
            return
        }

        setModalCandidate(candidate)
        setModalScannedCode(candidate.scanned_code || candidate.verified_barcode || '')
        setModalEntryMode(mode)
        setIsModalOpen(true)
    }

    const handleModalClose = () => {
        setIsModalOpen(false)
        setModalCandidate(null)
        if (!isCameraOpen && scanInputRef.current) {
            scanInputRef.current.focus()
        }
    }

    const handleModalAdd = payload => {
        if (!isDraft || !canDraft || isAddingItem) return

        setIsAddingItem(true)
        router.post(`${receiptUrl}/partidas`, payload, {
            preserveScroll: true,
            onSuccess: () => {
                setIsModalOpen(false)
                setModalCandidate(null)
                setScanCodeText('')
                setScanFeedback({
                    type: 'success',
                    message: `Partida agregada al borrador (+${payload.quantity} piezas).`,
                })
                if (!isCameraOpen && scanInputRef.current) {
                    scanInputRef.current.focus()
                }
            },
            onError: errors => {
                const msg = Object.values(errors).flat().join(' ')
                setScanFeedback({
                    type: 'error',
                    message: msg || 'Error al agregar la partida al borrador.',
                })
            },
            onFinish: () => {
                setIsAddingItem(false)
            },
        })
    }

    const handleUpdateQuantity = item => {
        const nextQty = editingQuantities[item.id] ?? item.quantity
        router.patch(
            `${receiptUrl}/partidas/${item.id}`,
            { quantity: nextQty, notes: item.notes },
            { preserveScroll: true }
        )
    }

    const handleRemoveItem = item => {
        router.delete(`${receiptUrl}/partidas/${item.id}`, { preserveScroll: true })
    }

    const handleVerifyCandidate = e => {
        e.preventDefault()
        if (!verifyingCandidate) return
        verifyForm.post(`${baseUrl(branch)}/identidades/${verifyingCandidate.automotive_part_id}/verificar`, {
            preserveScroll: true,
            onSuccess: () => setVerifyingCandidate(null),
        })
    }

    const handleConfirmReceipt = e => {
        e.preventDefault()
        if (!isDraft || !canConfirm || !confirmForm.data.confirmed) return
        confirmForm.post(`${receiptUrl}/confirmar`)
    }

    return (
        <Layout branch={branch} title={`Recepción ${receipt.folio}`}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <Link className="font-medium text-indigo-600 hover:underline dark:text-indigo-400" href={`${baseUrl(branch)}/entradas`}>
                    ← Volver a sesiones de recepción
                </Link>
                <div className="flex items-center gap-3">
                    <span
                        className={`rounded-lg px-3 py-1 text-sm font-semibold ${
                            receipt.status === 'confirmed'
                                ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-300'
                                : 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-300'
                        }`}
                    >
                        {statusLabel(receipt.status)}
                    </span>
                </div>
            </div>

            {/* Ficha resumen de la recepción */}
            <div className={card}>
                <div className="grid grid-cols-2 gap-4 md:grid-cols-5 text-sm">
                    <div>
                        <p className="text-xs uppercase text-slate-500 dark:text-neutral-400">Folio de recepción</p>
                        <p className="mt-1 font-mono font-bold text-slate-900 dark:text-white">{receipt.folio}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase text-slate-500 dark:text-neutral-400">Ubicación física ({branch.code})</p>
                        <p className="mt-1 font-mono font-semibold text-slate-900 dark:text-white">
                            {receipt.location ? `${receipt.location.code} · ${receipt.location.name}` : '—'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs uppercase text-slate-500 dark:text-neutral-400">Partidas / Unidades</p>
                        <p className="mt-1 font-bold text-indigo-700 dark:text-indigo-400">
                            {receipt.total_items} partidas · {receipt.total_units} unidades
                        </p>
                    </div>
                    <div>
                        <p className="text-xs uppercase text-slate-500 dark:text-neutral-400">Creado por</p>
                        <p className="mt-1 text-slate-800 dark:text-neutral-200">{receipt.created_by_name || '—'}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase text-slate-500 dark:text-neutral-400">Confirmado por</p>
                        <p className="mt-1 text-slate-800 dark:text-neutral-200">{receipt.confirmed_by_name || 'En borrador (sin movimientos aún)'}</p>
                    </div>
                </div>
            </div>

            {/* Aviso general de backend */}
            {lookupWarning && (
                <div role="status" className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200">
                    <strong>Aviso de identificación:</strong> {lookupWarning}
                </div>
            )}

            {/* Feedback interactivo de escaneo */}
            {scanFeedback && (
                <div
                    role="alert"
                    className={`rounded-2xl p-4 text-sm flex items-center justify-between gap-3 shadow-xs ${
                        scanFeedback.type === 'success'
                            ? 'bg-emerald-50 text-emerald-900 border border-emerald-300 dark:bg-emerald-950/50 dark:text-emerald-200 dark:border-emerald-800'
                            : scanFeedback.type === 'warning'
                            ? 'bg-amber-50 text-amber-900 border border-amber-300 dark:bg-amber-950/50 dark:text-amber-200 dark:border-amber-800'
                            : 'bg-rose-50 text-rose-900 border border-rose-300 dark:bg-rose-950/50 dark:text-rose-200 dark:border-rose-800'
                    }`}
                >
                    <div className="flex items-center gap-2">
                        <span className="text-lg">
                            {scanFeedback.type === 'success' ? '✓' : scanFeedback.type === 'warning' ? '⚠️' : '✕'}
                        </span>
                        <span>{scanFeedback.message}</span>
                    </div>
                    <button
                        type="button"
                        onClick={() => setScanFeedback(null)}
                        className="rounded-lg p-1 text-xs font-bold opacity-60 hover:opacity-100"
                        title="Cerrar aviso"
                    >
                        ✕
                    </button>
                </div>
            )}

            {/* Secciones de captura activa cuando está en borrador */}
            {isDraft && canDraft && (
                <div className="grid gap-5 lg:grid-cols-2">
                    {/* Tarjeta de escáner USB / Bluetooth y Celular */}
                    <div className={`${card} border-2 border-indigo-500/80 space-y-4`}>
                        <div className="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 pb-3 border-b border-slate-100 dark:border-neutral-800">
                            <div>
                                <h2 className="text-base font-semibold text-indigo-900 dark:text-indigo-200">
                                    1. Escaneo por cámara o lector HID
                                </h2>
                                <p className="mt-0.5 text-xs text-slate-600 dark:text-neutral-400">
                                    Escanee la etiqueta de la caja para abrir el modal de recepción con selector grande.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setIsCameraOpen(true)}
                                className="inline-flex items-center justify-center gap-2 rounded-2xl bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-indigo-700 active:scale-95 transition dark:bg-indigo-500 dark:hover:bg-indigo-600"
                            >
                                <span>📷</span>
                                <span>Escanear con cámara</span>
                            </button>
                        </div>

                        {/* Entrada para lector de código de barras USB / Bluetooth */}
                        <form onSubmit={handleScanFormSubmit} className="space-y-3">
                            <label className="flex flex-col gap-1 text-sm font-medium">
                                <span>Código de barras / SKU / Item # / MFG Part # (Lector USB / Bluetooth)</span>
                                <div className="flex gap-2">
                                    <input
                                        ref={scanInputRef}
                                        autoFocus
                                        required
                                        className={`${field} font-mono text-base flex-1`}
                                        value={scanCodeText}
                                        onChange={e => setScanCodeText(e.target.value)}
                                        onKeyDown={handleScanKeyDown}
                                        disabled={isLookingUp || isAddingItem}
                                        placeholder="Escanee con pistola o escriba código aquí..."
                                    />
                                    <button
                                        type="submit"
                                        className={button}
                                        disabled={isLookingUp || isAddingItem || !scanCodeText.trim()}
                                    >
                                        {isLookingUp ? 'Buscando…' : 'Buscar / Detectar'}
                                    </button>
                                </div>
                            </label>
                            <p className="text-[11px] text-slate-500 dark:text-neutral-400">
                                Compatible con lectores HID en modo teclado (con o sin sufijo Enter). Al detectar la pieza abrirá automáticamente el modal con selector de cantidad.
                            </p>
                        </form>
                    </div>

                    {/* Tarjeta de búsqueda manual asistida */}
                    <div className={`${card} space-y-4`}>
                        <div>
                            <h2 className="text-base font-semibold text-slate-900 dark:text-white">
                                2. Búsqueda manual asistida (Piezas sin etiqueta legible)
                            </h2>
                            <p className="mt-1 text-xs text-slate-600 dark:text-neutral-400">
                                Si la pieza no trae código de barras legible, busque por número de parte grabado, descripción o proveedor.
                            </p>
                        </div>
                        <form
                            className="flex gap-2"
                            onSubmit={e => {
                                e.preventDefault()
                                router.get(receiptUrl, { manual_search: manualQuery }, { preserveState: true, preserveScroll: true })
                            }}
                        >
                            <input
                                className={`${field} flex-1`}
                                value={manualQuery}
                                onChange={e => setManualQuery(e.target.value)}
                                placeholder="Buscar por Item #, MFG Part #, descripción o proveedor..."
                            />
                            <button type="submit" className={secondaryButton}>
                                Buscar en catálogo
                            </button>
                        </form>

                        {manualResults.length > 0 && (
                            <div className="max-h-72 overflow-y-auto rounded-2xl border border-slate-200 p-2 text-xs space-y-2 dark:border-neutral-700">
                                {manualResults.map(cand => (
                                    <div
                                        key={cand.automotive_part_id}
                                        className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-100 p-2.5 dark:border-neutral-800"
                                    >
                                        <div className="max-w-md">
                                            <p className="font-mono font-semibold text-indigo-700 dark:text-indigo-400">
                                                {cand.code} · Item #: {cand.item_number || '—'} · MFG: {cand.manufacturer_part_number || '—'}
                                            </p>
                                            <p className="font-medium text-slate-900 dark:text-white">{cand.description}</p>
                                            <p className="text-slate-500 dark:text-neutral-400">
                                                Fila Excel #{cand.source_row_number} · {cand.vendor_name} · Qty Excel: {cand.excel_qty} · Stock {branch.code}: {cand.branch_stock}
                                            </p>
                                            <div className="mt-1">
                                                <IdentityBadge status={cand.identity_status} />
                                            </div>
                                        </div>
                                        <div className="flex flex-col gap-1">
                                            {cand.is_receivable && cand.identity_status !== 'ambiguous' ? (
                                                <button
                                                    type="button"
                                                    className={button}
                                                    onClick={() => handleSelectCandidateToModal(cand, 'manual_assisted')}
                                                >
                                                    Seleccionar
                                                </button>
                                            ) : (
                                                canResolveIdentity && (
                                                    <button
                                                        type="button"
                                                        className={secondaryButton}
                                                        onClick={() => {
                                                            setVerifyingCandidate(cand)
                                                            verifyForm.setData({
                                                                verified_barcode: cand.verified_barcode || '',
                                                                reason: '',
                                                            })
                                                        }}
                                                    >
                                                        Resolver ambigüedad
                                                    </button>
                                                )
                                            )}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* Desambiguación de códigos */}
            {activeDisambiguation && activeDisambiguation.candidates?.length > 0 && (
                <div className={`${card} border-2 border-amber-400 space-y-4`}>
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <h2 className="text-base font-semibold text-amber-900 dark:text-amber-200">
                                Resolución de coincidencias para el código escaneado: <code>{activeDisambiguation.scanned_code}</code>
                            </h2>
                            <p className="mt-1 text-sm text-amber-800 dark:text-amber-300">
                                {activeDisambiguation.warning ||
                                    'Se encontraron múltiples registros o una identidad ambigua. El sistema nunca selecciona el primero automáticamente.'}
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => setActiveDisambiguation(null)}
                            className="rounded-lg p-1 text-xs font-bold text-amber-800 hover:bg-amber-100 dark:text-amber-300 dark:hover:bg-amber-900"
                        >
                            ✕ Cerrar
                        </button>
                    </div>
                    <div className="space-y-3">
                        {activeDisambiguation.candidates.map(cand => (
                            <div
                                key={cand.automotive_part_id}
                                className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-amber-200 bg-amber-50/40 p-3.5 text-sm dark:border-amber-800 dark:bg-amber-950/20"
                            >
                                <div className="space-y-1 max-w-2xl">
                                    <p className="font-mono text-xs font-bold text-indigo-700 dark:text-indigo-400">
                                        {cand.code} · Fila Excel #{cand.source_row_number} ({cand.source_sheet_name})
                                    </p>
                                    <p>
                                        Item #: <strong className="font-mono">{cand.item_number || '—'}</strong> · MFG Part #:{' '}
                                        <strong className="font-mono">{cand.manufacturer_part_number || '—'}</strong>
                                    </p>
                                    <p className="font-medium text-slate-900 dark:text-white">{cand.description}</p>
                                    <p className="text-xs text-slate-600 dark:text-neutral-400">
                                        Proveedor: {cand.vendor_name || '—'} · Categoría: {cand.category || '—'} · Qty Excel (doc):{' '}
                                        {cand.excel_qty} · Stock actual {branch.code}: <strong>{cand.branch_stock}</strong>
                                    </p>
                                    <IdentityBadge status={cand.identity_status} />
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {cand.is_receivable && cand.identity_status !== 'ambiguous' ? (
                                        isDraft && canDraft && (
                                            <button
                                                type="button"
                                                className={button}
                                                onClick={() => handleSelectCandidateToModal(cand, 'disambiguated_scan')}
                                            >
                                                Elegir esta autoparte
                                            </button>
                                        )
                                    ) : (
                                        canResolveIdentity && (
                                            <button
                                                type="button"
                                                className={secondaryButton}
                                                onClick={() => {
                                                    setVerifyingCandidate(cand)
                                                    verifyForm.setData({
                                                        verified_barcode: cand.verified_barcode || '',
                                                        reason: '',
                                                    })
                                                }}
                                            >
                                                Verificar identidad para habilitar recepción
                                            </button>
                                        )
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Formulario para resolver identidad cuando hay permisos */}
            {canResolveIdentity && verifyingCandidate && (
                <form className={`${card} border-2 border-indigo-400 space-y-4`} onSubmit={handleVerifyCandidate}>
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <h3 className="font-semibold text-slate-900 dark:text-white">
                                Resolver ambigüedad / Verificar etiqueta · {verifyingCandidate.code} (Fila Excel #{verifyingCandidate.source_row_number})
                            </h3>
                            <p className="text-sm text-slate-600 dark:text-neutral-400">
                                Item #: {verifyingCandidate.item_number} · MFG Part #: {verifyingCandidate.manufacturer_part_number} ·{' '}
                                {verifyingCandidate.description}
                            </p>
                        </div>
                        <button type="button" className={secondaryButton} onClick={() => setVerifyingCandidate(null)}>
                            Cerrar
                        </button>
                    </div>
                    <div className="grid gap-4 md:grid-cols-2">
                        <label className="flex flex-col gap-1 text-sm">
                            Código de barras físico único (opcional)
                            <input
                                className={field}
                                value={verifyForm.data.verified_barcode}
                                onChange={e => verifyForm.setData('verified_barcode', e.target.value)}
                                placeholder="Código único de la etiqueta física si existe"
                            />
                        </label>
                        <label className="flex flex-col gap-1 text-sm">
                            Evidencia de resolución (obligatorio, mín. 5 caracteres)
                            <input
                                required
                                minLength={5}
                                className={field}
                                value={verifyForm.data.reason}
                                onChange={e => verifyForm.setData('reason', e.target.value)}
                                placeholder="Ej. Confirmado con número de fabricante grabado en pieza física"
                            />
                        </label>
                    </div>
                    <div className="flex justify-end gap-2">
                        <button type="button" className={secondaryButton} onClick={() => setVerifyingCandidate(null)}>
                            Cancelar
                        </button>
                        <button type="submit" className={button} disabled={verifyForm.processing}>
                            Guardar verificación y desbloquear recepción
                        </button>
                    </div>
                </form>
            )}

            {/* Tabla de partidas del borrador */}
            <div className={card}>
                <h2 className="mb-3 text-base font-semibold text-slate-900 dark:text-white">
                    Partidas en esta sesión de recepción ({items.length})
                </h2>
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-slate-200 text-xs uppercase text-slate-500 dark:border-neutral-800 dark:text-neutral-400">
                            <tr>
                                <th className="p-3">SKU / Códigos</th>
                                <th className="p-3">Descripción / Proveedor</th>
                                <th className="p-3">Modo / Conteos</th>
                                <th className="p-3">Qty Excel (Doc)</th>
                                <th className="p-3">Stock antes</th>
                                <th className="p-3">Cantidad a recibir</th>
                                <th className="p-3">Stock después</th>
                                {isDraft && canDraft && <th className="p-3">Acciones</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {items.map(item => (
                                <tr key={item.id} className="border-b border-slate-100 align-top dark:border-neutral-800">
                                    <td className="p-3">
                                        <p className="font-mono text-xs font-semibold text-indigo-700 dark:text-indigo-400">{item.code}</p>
                                        <p className="text-xs text-slate-800 dark:text-neutral-200">
                                            Item #: <strong className="font-mono">{item.item_number || '—'}</strong>
                                        </p>
                                        <p className="text-xs text-slate-800 dark:text-neutral-200">
                                            MFG: <strong className="font-mono">{item.manufacturer_part_number || '—'}</strong>
                                        </p>
                                        {item.scanned_code && (
                                            <p className="mt-1 text-xs text-slate-500 dark:text-neutral-400">
                                                Escaneado: <code className="font-mono">{item.scanned_code}</code>
                                            </p>
                                        )}
                                    </td>
                                    <td className="max-w-sm p-3">
                                        <p className="whitespace-pre-wrap font-medium text-slate-900 dark:text-white">{item.description}</p>
                                        <p className="mt-1 text-xs text-slate-500 dark:text-neutral-400">
                                            {item.vendor_name} · {item.category} · Fila #{item.source_row_number}
                                        </p>
                                        <div className="mt-1">
                                            <IdentityBadge status={item.identity_status} />
                                        </div>
                                        {item.notes && <p className="mt-1 text-xs text-slate-600 dark:text-neutral-400">Nota: {item.notes}</p>}
                                    </td>
                                    <td className="p-3 text-xs">
                                        <span className="font-medium text-slate-900 dark:text-white">{item.entry_mode}</span>
                                        <p className="text-slate-500 dark:text-neutral-400">{item.scan_events_count} evento(s) consolidados</p>
                                    </td>
                                    <td className="p-3 font-mono text-xs text-slate-500 dark:text-neutral-400">{item.excel_qty}</td>
                                    <td className="p-3 font-mono font-semibold text-slate-800 dark:text-neutral-200">{item.stock_before}</td>
                                    <td className="p-3">
                                        {isDraft && canDraft ? (
                                            <div className="flex items-center gap-2">
                                                <input
                                                    type="number"
                                                    min={1}
                                                    step={1}
                                                    className={`${field} w-24 font-mono`}
                                                    value={editingQuantities[item.id] ?? item.quantity}
                                                    onChange={e =>
                                                        setEditingQuantities({
                                                            ...editingQuantities,
                                                            [item.id]: e.target.value,
                                                        })
                                                    }
                                                />
                                                <button
                                                    type="button"
                                                    className={secondaryButton}
                                                    onClick={() => handleUpdateQuantity(item)}
                                                >
                                                    Guardar
                                                </button>
                                            </div>
                                        ) : (
                                            <span className="font-mono text-base font-bold text-emerald-700 dark:text-emerald-400">+{item.quantity}</span>
                                        )}
                                    </td>
                                    <td className="p-3 font-mono text-base font-bold text-indigo-700 dark:text-indigo-400">{item.stock_after}</td>
                                    {isDraft && canDraft && (
                                        <td className="p-3">
                                            <button
                                                type="button"
                                                className={dangerButton}
                                                onClick={() => handleRemoveItem(item)}
                                            >
                                                Quitar
                                            </button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {items.length === 0 && (
                        <p className="p-8 text-center text-slate-500 dark:text-neutral-400">
                            El borrador aún no tiene partidas. Escanee una etiqueta o use la búsqueda manual asistida.
                        </p>
                    )}
                </div>
            </div>

            {/* Confirmación definitiva autorizada */}
            {isDraft && canConfirm && items.length > 0 && (
                <form
                    className={`${card} border-2 border-emerald-500 space-y-4`}
                    onSubmit={handleConfirmReceipt}
                    onKeyDown={e => {
                        if (e.key === 'Enter') {
                            e.preventDefault()
                        }
                    }}
                >
                    <h2 className="text-base font-semibold text-emerald-900 dark:text-emerald-200">
                        3. Confirmación definitiva de entrada física en {branch.name}
                    </h2>
                    <p className="text-sm text-slate-600 dark:text-neutral-300">
                        Al confirmar esta recepción se generarán <strong>{items.length}</strong> movimientos tipo <code>RECEIPT</code> por un total de{' '}
                        <strong>{receipt.total_units}</strong> unidades en la ubicación{' '}
                        <strong>{receipt.location?.code}</strong>. Esta operación es transaccional e idempotente; una vez confirmada no podrá editarse ni confirmarse dos veces.
                    </p>
                    <label className="flex items-center gap-2 text-sm font-medium">
                        <input
                            type="checkbox"
                            checked={confirmForm.data.confirmed}
                            onChange={e => confirmForm.setData('confirmed', e.target.checked)}
                        />
                        He verificado físicamente las piezas y cantidades contadas en {branch.name} y autorizo el ingreso al inventario.
                    </label>
                    <div>
                        <button
                            type="submit"
                            className="inline-flex items-center rounded-2xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 active:scale-95 disabled:opacity-50 transition"
                            disabled={!confirmForm.data.confirmed || confirmForm.processing}
                        >
                            {confirmForm.processing
                                ? 'Confirmando y registrando movimientos…'
                                : `Confirmar recepción definitiva (${receipt.total_units} uds)`}
                        </button>
                    </div>
                </form>
            )}

            {/* Componente modal de cámara */}
            <CameraBarcodeScanner
                isOpen={isCameraOpen}
                isPaused={isModalOpen || isLookingUp}
                onScan={handleCameraScan}
                onClose={() => setIsCameraOpen(false)}
                title="Escanear Autoparte con Cámara"
            />

            {/* Modal de recepción con producto detectado y selector grande */}
            <ReceiptItemModal
                isOpen={isModalOpen}
                candidate={modalCandidate}
                receipt={receipt}
                branch={branch}
                entryMode={modalEntryMode}
                scannedCode={modalScannedCode}
                onClose={handleModalClose}
                onAdd={handleModalAdd}
                isSubmitting={isAddingItem}
                maxQuantity={5000}
            />
        </Layout>
    )
}
