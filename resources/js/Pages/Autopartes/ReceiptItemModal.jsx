import { useEffect, useRef, useState } from 'react'
import { IdentityBadge } from './Layout'

export default function ReceiptItemModal({
    isOpen,
    candidate,
    receipt,
    branch,
    entryMode = 'scan',
    scannedCode = '',
    onClose,
    onAdd,
    isSubmitting = false,
    maxQuantity = 5000,
}) {
    const [quantity, setQuantity] = useState(1)
    const [notes, setNotes] = useState('')
    const inputRef = useRef(null)

    // Reset state whenever a new candidate is loaded or modal opens
    useEffect(() => {
        if (isOpen && candidate) {
            setQuantity(1)
            setNotes('')
            // Focus on quantity input after a brief tick
            setTimeout(() => {
                inputRef.current?.focus()
                inputRef.current?.select()
            }, 50)
        }
    }, [isOpen, candidate])

    if (!isOpen || !candidate) {
        return null
    }

    const isReceivable = Boolean(candidate.is_receivable && candidate.identity_status !== 'ambiguous')

    const handleQuantityChange = e => {
        const val = e.target.value
        if (val === '') {
            setQuantity('')
            return
        }
        const parsed = parseInt(val, 10)
        if (Number.isNaN(parsed)) return
        setQuantity(Math.min(maxQuantity, Math.max(1, parsed)))
    }

    const handleQuantityBlur = () => {
        if (quantity === '' || quantity < 1 || Number.isNaN(Number(quantity))) {
            setQuantity(1)
        } else if (quantity > maxQuantity) {
            setQuantity(maxQuantity)
        }
    }

    const addQuickQuantity = amount => {
        setQuantity(prev => {
            const current = typeof prev === 'number' && !Number.isNaN(prev) ? prev : 1
            return Math.min(maxQuantity, current + amount)
        })
    }

    const handleSubmit = e => {
        if (e) {
            e.preventDefault()
            e.stopPropagation()
        }
        if (!isReceivable || isSubmitting) return

        const finalQty = typeof quantity === 'number' && quantity >= 1 ? Math.floor(quantity) : 1
        if (finalQty < 1 || finalQty > maxQuantity) return

        onAdd({
            automotive_part_id: candidate.automotive_part_id,
            scan_code: scannedCode || candidate.scanned_code || candidate.verified_barcode || null,
            quantity: finalQty,
            entry_mode: entryMode,
            notes: notes.trim() !== '' ? notes.trim() : null,
        })
    }

    const handleKeyDown = e => {
        if (e.key === 'Escape') {
            e.preventDefault()
            onClose()
        } else if (e.key === 'Enter') {
            e.preventDefault()
            handleSubmit()
        }
    }

    const locationName = receipt?.location?.name || 'Área de Recepción Hermosillo'
    const locationCode = receipt?.location?.code || 'HMO-REC-01'

    return (
        <div
            className="fixed inset-0 z-[70] flex items-center justify-center p-3 sm:p-4 bg-black/75 backdrop-blur-xs"
            role="dialog"
            aria-modal="true"
            aria-labelledby="reception-modal-title"
            onKeyDown={handleKeyDown}
        >
            <div className="relative flex w-full max-w-lg flex-col overflow-hidden rounded-3xl bg-white shadow-2xl border border-slate-200 text-slate-900 dark:bg-neutral-900 dark:border-neutral-800 dark:text-white max-h-[92vh]">
                {/* Header */}
                <div className="flex items-start justify-between border-b border-slate-100 bg-slate-50/70 px-5 py-4 dark:border-neutral-800 dark:bg-neutral-950/50">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-3 py-0.5 text-xs font-bold text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                <span>✓</span> Producto detectado
                            </span>
                            <IdentityBadge status={candidate.identity_status} />
                        </div>
                        <h2 id="reception-modal-title" className="text-base sm:text-lg font-bold leading-tight line-clamp-2">
                            {candidate.description || 'Sin descripción'}
                        </h2>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={isSubmitting}
                        className="rounded-xl p-1.5 text-slate-400 hover:bg-slate-200 hover:text-slate-700 dark:text-neutral-400 dark:hover:bg-neutral-800 dark:hover:text-white"
                        title="Cerrar modal"
                    >
                        ✕
                    </button>
                </div>

                {/* Content body */}
                <form onSubmit={handleSubmit} className="overflow-y-auto px-5 py-4 space-y-4">
                    {/* Alerta si es identidad ambigua o no recepcionable */}
                    {!isReceivable && (
                        <div
                            role="alert"
                            className="rounded-2xl border border-amber-300 bg-amber-50 p-3.5 text-xs text-amber-900 dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200"
                        >
                            <p className="font-bold flex items-center gap-1.5">
                                <span>⚠️</span> Autoparte con recepción restringida
                            </p>
                            <p className="mt-1">
                                Esta pieza tiene identidad ambigua o recepción bloqueada. No puede agregarse al borrador hasta que su identidad sea verificada y resuelta con evidencia.
                            </p>
                        </div>
                    )}

                    {/* Metadata del producto */}
                    <div className="grid grid-cols-2 gap-2 text-xs rounded-2xl border border-slate-200 bg-slate-50/50 p-3 dark:border-neutral-800 dark:bg-neutral-800/30">
                        <div>
                            <span className="text-[10px] uppercase font-semibold text-slate-500 dark:text-neutral-400 block">
                                Código / SKU
                            </span>
                            <span className="font-mono font-bold text-indigo-700 dark:text-indigo-400 text-sm">
                                {candidate.code}
                            </span>
                        </div>
                        <div>
                            <span className="text-[10px] uppercase font-semibold text-slate-500 dark:text-neutral-400 block">
                                Item #
                            </span>
                            <span className="font-mono font-bold text-slate-800 dark:text-slate-200 text-sm">
                                {candidate.item_number || '—'}
                            </span>
                        </div>
                        <div>
                            <span className="text-[10px] uppercase font-semibold text-slate-500 dark:text-neutral-400 block">
                                MFG Part #
                            </span>
                            <span className="font-mono font-bold text-slate-800 dark:text-slate-200">
                                {candidate.manufacturer_part_number || '—'}
                            </span>
                        </div>
                        <div>
                            <span className="text-[10px] uppercase font-semibold text-slate-500 dark:text-neutral-400 block">
                                Proveedor / Marca
                            </span>
                            <span className="font-medium truncate block">
                                {candidate.vendor_name || '—'}
                            </span>
                        </div>
                        {candidate.verified_barcode ? (
                            <div className="col-span-2">
                                <span className="text-[10px] uppercase font-semibold text-slate-500 dark:text-neutral-400 block">
                                    Código de barras verificado
                                </span>
                                <span className="font-mono font-bold text-emerald-700 dark:text-emerald-400">
                                    🏷️ {candidate.verified_barcode}
                                </span>
                            </div>
                        ) : scannedCode ? (
                            <div className="col-span-2">
                                <span className="text-[10px] uppercase font-semibold text-slate-500 dark:text-neutral-400 block">
                                    Código escaneado
                                </span>
                                <span className="font-mono text-slate-700 dark:text-neutral-300">
                                    {scannedCode}
                                </span>
                            </div>
                        ) : null}
                    </div>

                    {/* Destino físico */}
                    <div className="rounded-2xl border border-indigo-100 bg-indigo-50/40 p-3 text-xs dark:border-indigo-900/40 dark:bg-indigo-950/20">
                        <div className="flex items-center justify-between">
                            <span className="font-semibold text-indigo-950 dark:text-indigo-200">Destino de recepción:</span>
                            <span className="font-bold text-indigo-700 dark:text-indigo-300">Almacén Hermosillo</span>
                        </div>
                        <div className="mt-1 flex items-center justify-between text-indigo-900/80 dark:text-indigo-300/80">
                            <span>Ubicación física:</span>
                            <span className="font-mono font-semibold">
                                {locationCode} · {locationName}
                            </span>
                        </div>
                    </div>

                    {/* Selector grande de cantidad */}
                    <div className="space-y-3 pt-1">
                        <label
                            htmlFor="reception-modal-qty"
                            className="block text-center text-xs uppercase font-bold text-slate-600 dark:text-neutral-300"
                        >
                            Cantidad a recibir (piezas)
                        </label>
                        <div className="flex items-center justify-center gap-3">
                            <button
                                type="button"
                                onClick={() => setQuantity(q => Math.max(1, (Number(q) || 1) - 1))}
                                disabled={quantity <= 1 || isSubmitting || !isReceivable}
                                className="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-2xl font-bold text-slate-700 hover:bg-slate-200 active:scale-95 disabled:opacity-40 transition dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                aria-label="Disminuir una pieza"
                            >
                                −
                            </button>
                            <input
                                id="reception-modal-qty"
                                ref={inputRef}
                                type="number"
                                min={1}
                                max={maxQuantity}
                                step={1}
                                required
                                disabled={isSubmitting || !isReceivable}
                                value={quantity}
                                onChange={handleQuantityChange}
                                onBlur={handleQuantityBlur}
                                className="h-14 w-32 rounded-2xl border-2 border-indigo-500 bg-white text-center font-mono text-3xl sm:text-4xl font-bold text-slate-900 shadow-sm focus:border-indigo-600 focus:outline-none dark:bg-neutral-900 dark:text-white"
                            />
                            <button
                                type="button"
                                onClick={() => setQuantity(q => Math.min(maxQuantity, (Number(q) || 1) + 1))}
                                disabled={quantity >= maxQuantity || isSubmitting || !isReceivable}
                                className="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-2xl font-bold text-slate-700 hover:bg-slate-200 active:scale-95 disabled:opacity-40 transition dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                aria-label="Aumentar una pieza"
                            >
                                +
                            </button>
                        </div>

                        {/* Botones de incremento rápido */}
                        <div>
                            <p className="mb-1.5 text-center text-[11px] font-medium text-slate-500 dark:text-neutral-400">
                                Accesos rápidos (tocar para sumar):
                            </p>
                            <div className="flex flex-wrap items-center justify-center gap-1.5">
                                {[1, 5, 10, 12, 24, 48, 100].map(n => (
                                    <button
                                        key={n}
                                        type="button"
                                        onClick={() => addQuickQuantity(n)}
                                        disabled={isSubmitting || !isReceivable || quantity >= maxQuantity}
                                        className="rounded-xl border border-slate-200 bg-white px-2.5 sm:px-3 py-1.5 font-mono text-xs font-bold text-slate-700 shadow-xs hover:border-indigo-400 hover:bg-indigo-50 hover:text-indigo-700 active:scale-95 disabled:opacity-40 transition dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                                    >
                                        +{n}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>

                    {/* Referencia documental y stock actual */}
                    <div className="grid grid-cols-2 gap-2 text-xs">
                        <div className="rounded-2xl border border-blue-100 bg-blue-50/60 p-2.5 dark:border-blue-900/40 dark:bg-blue-950/30">
                            <span className="text-[10px] uppercase font-bold text-blue-700 dark:text-blue-300 block">
                                Cantidad documental Excel
                            </span>
                            <span className="text-base font-mono font-bold text-blue-900 dark:text-blue-200">
                                {candidate.excel_qty ?? '0'} piezas
                            </span>
                            <p className="text-[10px] text-blue-600 dark:text-blue-400 mt-0.5">
                                Referencia documental (no altera stock)
                            </p>
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-2.5 dark:border-neutral-800 dark:bg-neutral-800/40">
                            <span className="text-[10px] uppercase font-bold text-slate-600 dark:text-neutral-400 block">
                                Stock actual ({branch?.code || 'HMO'})
                            </span>
                            <span className="text-base font-mono font-bold text-slate-900 dark:text-white">
                                {candidate.branch_stock ?? 0} piezas
                            </span>
                            <p className="text-[10px] text-slate-500 mt-0.5">
                                En almacén físico
                            </p>
                        </div>
                    </div>

                    {/* Nota de conteo */}
                    <div>
                        <label
                            htmlFor="reception-modal-notes"
                            className="block text-xs font-medium text-slate-600 dark:text-neutral-400 mb-1"
                        >
                            Nota de conteo (opcional)
                        </label>
                        <input
                            id="reception-modal-notes"
                            type="text"
                            value={notes}
                            onChange={e => setNotes(e.target.value)}
                            disabled={isSubmitting || !isReceivable}
                            placeholder="Ej. Caja sellada #2, lote de proveedor"
                            className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm shadow-xs focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                            maxLength={1000}
                        />
                    </div>

                    {/* Footer Actions */}
                    <div className="flex flex-col-reverse sm:flex-row items-center justify-end gap-2 pt-3 border-t border-slate-100 dark:border-neutral-800">
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={isSubmitting}
                            className="w-full sm:w-auto rounded-xl border border-slate-300 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 active:scale-95 disabled:opacity-50 transition dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            disabled={isSubmitting || !isReceivable || quantity < 1 || quantity > maxQuantity}
                            className="w-full sm:w-auto rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-bold text-white shadow-sm hover:bg-indigo-700 active:scale-95 disabled:opacity-50 transition dark:bg-indigo-500 dark:hover:bg-indigo-600 flex items-center justify-center gap-2"
                        >
                            {isSubmitting ? (
                                <>
                                    <span className="inline-block animate-spin">⏳</span>
                                    <span>Agregando al borrador…</span>
                                </>
                            ) : (
                                <>
                                    <span>✓ Agregar al borrador</span>
                                    <span className="font-mono text-xs opacity-80">({quantity} uds)</span>
                                </>
                            )}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    )
}
