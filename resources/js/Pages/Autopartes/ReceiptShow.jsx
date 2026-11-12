import { Link, router, useForm } from '@inertiajs/react'
import { useRef, useState } from 'react'
import { AUTOPARTES_CAPABILITIES, hasCapability } from '@/lib/autopartesCapabilities'
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

    const scanForm = useForm({
        scan_code: '',
        quantity: 1,
        entry_mode: 'scan',
        notes: '',
    })

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

    const handleScanSubmit = e => {
        e.preventDefault()
        if (!isDraft || !canDraft) return
        scanForm.post(`${receiptUrl}/partidas`, {
            preserveScroll: true,
            onSuccess: () => {
                scanForm.setData({
                    scan_code: '',
                    quantity: 1,
                    entry_mode: 'scan',
                    notes: '',
                })
                if (scanInputRef.current) {
                    scanInputRef.current.focus()
                }
            },
        })
    }

    const handleSelectCandidate = (candidate, mode = 'disambiguated_scan', qty = 1) => {
        if (!isDraft || !canDraft) return
        router.post(
            `${receiptUrl}/partidas`,
            {
                automotive_part_id: candidate.automotive_part_id,
                scan_code: candidate.scanned_code || scanForm.data.scan_code || null,
                quantity: qty,
                entry_mode: mode,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (scanInputRef.current) {
                        scanInputRef.current.focus()
                    }
                },
            }
        )
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
                <Link className="font-medium text-indigo-600 hover:underline" href={`${baseUrl(branch)}/entradas`}>
                    ← Volver a sesiones de recepción
                </Link>
                <div className="flex items-center gap-3">
                    <span
                        className={`rounded-lg px-3 py-1 text-sm font-semibold ${
                            receipt.status === 'confirmed'
                                ? 'bg-emerald-100 text-emerald-900'
                                : 'bg-amber-100 text-amber-900'
                        }`}
                    >
                        {statusLabel(receipt.status)}
                    </span>
                </div>
            </div>

            <div className={card}>
                <div className="grid grid-cols-2 gap-4 md:grid-cols-5 text-sm">
                    <div>
                        <p className="text-xs uppercase text-slate-500">Folio de recepción</p>
                        <p className="mt-1 font-mono font-bold">{receipt.folio}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase text-slate-500">Ubicación física ({branch.code})</p>
                        <p className="mt-1 font-mono font-semibold">
                            {receipt.location ? `${receipt.location.code} · ${receipt.location.name}` : '—'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs uppercase text-slate-500">Partidas / Unidades</p>
                        <p className="mt-1 font-bold text-indigo-700">
                            {receipt.total_items} partidas · {receipt.total_units} unidades
                        </p>
                    </div>
                    <div>
                        <p className="text-xs uppercase text-slate-500">Creado por</p>
                        <p className="mt-1">{receipt.created_by_name || '—'}</p>
                    </div>
                    <div>
                        <p className="text-xs uppercase text-slate-500">Confirmado por</p>
                        <p className="mt-1">{receipt.confirmed_by_name || 'En borrador (sin movimientos aún)'}</p>
                    </div>
                </div>
            </div>

            {lookupWarning && (
                <div role="status" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    <strong>Aviso de identificación:</strong> {lookupWarning}
                </div>
            )}

            {isDraft && canDraft && (
                <div className="grid gap-5 lg:grid-cols-2">
                    <form className={`${card} border-2 border-indigo-500 space-y-4`} onSubmit={handleScanSubmit}>
                        <div>
                            <h2 className="text-base font-semibold text-indigo-900 dark:text-indigo-200">
                                1. Escaneo rápido con lector USB (Modo Teclado)
                            </h2>
                            <p className="mt-1 text-xs text-slate-600 dark:text-neutral-400">
                                Coloque el cursor en el campo de código y escanee la etiqueta. Al enviar <code>Enter</code>, el lector busca y agrega únicamente la pieza al borrador (nunca confirma la recepción completa).
                            </p>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-4">
                            <label className="flex flex-col gap-1 text-sm sm:col-span-3">
                                Código de barras / SKU / Item # / MFG Part #
                                <input
                                    ref={scanInputRef}
                                    autoFocus
                                    required
                                    className={`${field} font-mono text-base`}
                                    value={scanForm.data.scan_code}
                                    onChange={e => scanForm.setData('scan_code', e.target.value)}
                                    placeholder="Escanee etiqueta aquí (conserva ceros iniciales)..."
                                />
                            </label>
                            <label className="flex flex-col gap-1 text-sm">
                                Cantidad (piezas/caja)
                                <input
                                    type="number"
                                    min={1}
                                    step={1}
                                    required
                                    className={`${field} font-mono text-base`}
                                    value={scanForm.data.quantity}
                                    onChange={e => scanForm.setData('quantity', e.target.value)}
                                />
                            </label>
                        </div>
                        <label className="flex flex-col gap-1 text-sm">
                            Nota de conteo para este escaneo (opcional)
                            <input
                                className={field}
                                value={scanForm.data.notes}
                                onChange={e => scanForm.setData('notes', e.target.value)}
                                placeholder="Ej. Caja cerrada con 6 piezas, estante A"
                            />
                        </label>
                        <button type="submit" className={button} disabled={scanForm.processing}>
                            {scanForm.processing ? 'Buscando y agregando…' : 'Buscar / Agregar al borrador (Enter)'}
                        </button>
                    </form>

                    <div className={`${card} space-y-4`}>
                        <div>
                            <h2 className="text-base font-semibold">
                                2. Búsqueda manual asistida (Piezas sin etiqueta legible)
                            </h2>
                            <p className="mt-1 text-xs text-slate-600 dark:text-neutral-400">
                                Si la pieza no trae código de barras legible, busque por número de parte grabado, descripción, proveedor o categoría.
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
                            <div className="max-h-72 overflow-y-auto rounded-lg border border-slate-200 p-2 text-xs space-y-2 dark:border-neutral-700">
                                {manualResults.map(cand => (
                                    <div
                                        key={cand.automotive_part_id}
                                        className="flex flex-wrap items-center justify-between gap-2 rounded border border-slate-100 p-2 dark:border-neutral-800"
                                    >
                                        <div className="max-w-md">
                                            <p className="font-mono font-semibold text-indigo-700">
                                                {cand.code} · Item #: {cand.item_number || '—'} · MFG: {cand.manufacturer_part_number || '—'}
                                            </p>
                                            <p className="font-medium">{cand.description}</p>
                                            <p className="text-slate-500">
                                                Fila Excel #{cand.source_row_number} · {cand.vendor_name} · Qty Excel: {cand.excel_qty} · Stock {branch.code}: {cand.branch_stock}
                                            </p>
                                            <div className="mt-1">
                                                <IdentityBadge status={cand.identity_status} />
                                            </div>
                                        </div>
                                        <div className="flex flex-col gap-1">
                                            {cand.is_receivable ? (
                                                <button
                                                    type="button"
                                                    className={button}
                                                    onClick={() => handleSelectCandidate(cand, 'manual_assisted', Number(scanForm.data.quantity) || 1)}
                                                >
                                                    Seleccionar (+{Number(scanForm.data.quantity) || 1})
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
                                                        Resolver ambigüedad primero
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

            {lookupDisambiguation && lookupDisambiguation.candidates?.length > 0 && (
                <div className={`${card} border-2 border-amber-400 space-y-4`}>
                    <div>
                        <h2 className="text-base font-semibold text-amber-900">
                            Resolución de coincidencias para el código escaneado: <code>{lookupDisambiguation.scanned_code}</code>
                        </h2>
                        <p className="mt-1 text-sm text-amber-800">
                            {lookupDisambiguation.warning ||
                                'Se encontraron múltiples registros o una identidad ambigua. El sistema nunca selecciona el primero automáticamente.'}
                        </p>
                    </div>
                    <div className="space-y-3">
                        {lookupDisambiguation.candidates.map(cand => (
                            <div
                                key={cand.automotive_part_id}
                                className="flex flex-wrap items-center justify-between gap-4 rounded-lg border border-amber-200 bg-amber-50/40 p-3 text-sm"
                            >
                                <div className="space-y-1 max-w-2xl">
                                    <p className="font-mono text-xs font-bold text-indigo-700">
                                        {cand.code} · Fila Excel #{cand.source_row_number} ({cand.source_sheet_name})
                                    </p>
                                    <p>
                                        Item #: <strong className="font-mono">{cand.item_number || '—'}</strong> · MFG Part #:{' '}
                                        <strong className="font-mono">{cand.manufacturer_part_number || '—'}</strong>
                                    </p>
                                    <p className="font-medium">{cand.description}</p>
                                    <p className="text-xs text-slate-600">
                                        Proveedor: {cand.vendor_name || '—'} · Categoría: {cand.category || '—'} · Qty Excel (doc):{' '}
                                        {cand.excel_qty} · Stock actual {branch.code}: <strong>{cand.branch_stock}</strong>
                                    </p>
                                    <IdentityBadge status={cand.identity_status} />
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {cand.is_receivable ? (
                                        isDraft && canDraft && (
                                            <button
                                                type="button"
                                                className={button}
                                                onClick={() =>
                                                    handleSelectCandidate(
                                                        cand,
                                                        'disambiguated_scan',
                                                        Number(scanForm.data.quantity) || 1
                                                    )
                                                }
                                            >
                                                Elegir esta autoparte (+{Number(scanForm.data.quantity) || 1})
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

            {canResolveIdentity && verifyingCandidate && (
                <form className={`${card} border-2 border-indigo-400 space-y-4`} onSubmit={handleVerifyCandidate}>
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <h3 className="font-semibold">
                                Resolver ambigüedad / Verificar etiqueta · {verifyingCandidate.code} (Fila Excel #{verifyingCandidate.source_row_number})
                            </h3>
                            <p className="text-sm text-slate-600">
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

            <div className={card}>
                <h2 className="mb-3 text-base font-semibold">
                    Partidas en esta sesión de recepción ({items.length})
                </h2>
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
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
                                        <p className="font-mono text-xs font-semibold text-indigo-700">{item.code}</p>
                                        <p className="text-xs">
                                            Item #: <strong className="font-mono">{item.item_number || '—'}</strong>
                                        </p>
                                        <p className="text-xs">
                                            MFG: <strong className="font-mono">{item.manufacturer_part_number || '—'}</strong>
                                        </p>
                                        {item.scanned_code && (
                                            <p className="mt-1 text-xs text-slate-500">
                                                Escaneado: <code className="font-mono">{item.scanned_code}</code>
                                            </p>
                                        )}
                                    </td>
                                    <td className="max-w-sm p-3">
                                        <p className="whitespace-pre-wrap font-medium">{item.description}</p>
                                        <p className="mt-1 text-xs text-slate-500">
                                            {item.vendor_name} · {item.category} · Fila #{item.source_row_number}
                                        </p>
                                        <div className="mt-1">
                                            <IdentityBadge status={item.identity_status} />
                                        </div>
                                        {item.notes && <p className="mt-1 text-xs text-slate-600">Nota: {item.notes}</p>}
                                    </td>
                                    <td className="p-3 text-xs">
                                        <span className="font-medium">{item.entry_mode}</span>
                                        <p className="text-slate-500">{item.scan_events_count} evento(s) consolidados</p>
                                    </td>
                                    <td className="p-3 font-mono text-xs text-slate-500">{item.excel_qty}</td>
                                    <td className="p-3 font-mono font-semibold">{item.stock_before}</td>
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
                                            <span className="font-mono text-base font-bold text-emerald-700">+{item.quantity}</span>
                                        )}
                                    </td>
                                    <td className="p-3 font-mono text-base font-bold text-indigo-700">{item.stock_after}</td>
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
                        <p className="p-8 text-center text-slate-500">
                            El borrador aún no tiene partidas. Escanee una etiqueta o use la búsqueda manual asistida.
                        </p>
                    )}
                </div>
            </div>

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
                            className="inline-flex items-center rounded-lg bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                            disabled={!confirmForm.data.confirmed || confirmForm.processing}
                        >
                            {confirmForm.processing
                                ? 'Confirmando y registrando movimientos…'
                                : `Confirmar recepción definitiva (${receipt.total_units} uds)`}
                        </button>
                    </div>
                </form>
            )}
        </Layout>
    )
}
