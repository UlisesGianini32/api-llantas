import { router } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import { AUTOPARTES_CAPABILITIES, hasCapability } from '@/lib/autopartesCapabilities'
import { autopartesHttp, formatAutopartesHttpError } from '@/lib/autopartesHttp'
import Layout, { baseUrl, button, card, dangerButton, field, IdentityBadge, secondaryButton } from './Layout'

function makeIdempotencyKey(branchCode) {
    const rand = Math.random().toString(36).substring(2, 10).toUpperCase()
    return `POS-${branchCode}-${Date.now()}-${rand}`
}

export default function Pos({
    branch,
    capabilities = [],
    cashier,
    registers = [],
    activeShift = null,
    shiftSummary = null,
    shiftHistory = [],
    sellers = [],
    initialProducts = [],
    pendingApprovals = [],
    posConfig = {},
}) {
    const canSell = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.POS_SELL)
    const canManageShift = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.POS_SHIFT_MANAGE)
    const canApproveDiscount = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.DISCOUNTS_APPROVE)

    const barcodeInputRef = useRef(null)
    const [barcodeText, setBarcodeText] = useState('')
    const [scanFeedback, setScanFeedback] = useState(null)
    const [multipleCandidates, setMultipleCandidates] = useState([])

    const [searchQuery, setSearchQuery] = useState('')
    const [catalogResults, setCatalogResults] = useState(initialProducts)
    const [searching, setSearching] = useState(false)

    const [selectedSellerId, setSelectedSellerId] = useState(sellers[0]?.id ? String(sellers[0].id) : '')
    const [customerName, setCustomerName] = useState('')
    const [cartItems, setCartItems] = useState([])
    const [calculation, setCalculation] = useState(null)
    const [calcError, setCalcError] = useState(null)

    const [manualDiscountType, setManualDiscountType] = useState('')
    const [manualDiscountValue, setManualDiscountValue] = useState('')
    const [manualDiscountReason, setManualDiscountReason] = useState('')
    const [approvalToken, setApprovalToken] = useState('')
    const [requestedApproval, setRequestedApproval] = useState(null)

    const [paymentMethod, setPaymentMethod] = useState('cash')
    const [amountTendered, setAmountTendered] = useState('')
    const [paymentReference, setPaymentReference] = useState('')
    const [mixedCashAmount, setMixedCashAmount] = useState('')
    const [mixedCashTendered, setMixedCashTendered] = useState('')
    const [mixedCardAmount, setMixedCardAmount] = useState('')
    const [mixedCardReference, setMixedCardReference] = useState('')

    const [idempotencyKey, setIdempotencyKey] = useState(() => makeIdempotencyKey(branch.code))
    const [submittingSale, setSubmittingSale] = useState(false)
    const [lastReceipt, setLastReceipt] = useState(null)
    const [lastSale, setLastSale] = useState(null)
    const [paperType, setPaperType] = useState(posConfig?.default_thermal_width || '80mm')
    const [receiptFormat, setReceiptFormat] = useState('both')
    const [voucherPreviewTab, setVoucherPreviewTab] = useState('thermal')
    const [printStatusMsg, setPrintStatusMsg] = useState(null)

    const printHtmlSafely = (htmlContent, label) => {
        if (!htmlContent) return
        try {
            const printWin = window.open('', '_blank', 'width=900,height=800')
            if (!printWin) {
                setPrintStatusMsg(`La venta ${lastSale?.folio || ''} quedó registrada. Habilite ventanas emergentes o reimprima desde el historial.`)
                return
            }
            printWin.document.open()
            printWin.document.write(htmlContent)
            printWin.document.close()
            printWin.focus()
            printWin.print()
            setPrintStatusMsg(`Enviado a impresión: ${label}.`)
        } catch (_err) {
            setPrintStatusMsg(`Impresora no disponible al imprimir ${label}. La venta confirmada permanece registrada.`)
        }
    }

    const handlePrintSelection = async (mode, customPaperType = paperType) => {
        if (!lastSale) return
        try {
            const res = await autopartesHttp.get(`${baseUrl(branch)}/pos/ventas/${lastSale.id}/ticket`, {
                params: { paper_type: customPaperType, format: mode },
            })
            const bundle = res.data?.receipt || lastReceipt
            setLastReceipt(bundle)
            if (mode === 'thermal') {
                printHtmlSafely(bundle?.thermal?.html || bundle?.html, `Ticket Térmico (${customPaperType})`)
            } else if (mode === 'letter') {
                printHtmlSafely(bundle?.letter?.html, 'Comprobante Tamaño Carta (216 × 279 mm)')
            } else {
                printHtmlSafely(bundle?.thermal?.html, `Ticket Térmico (${customPaperType})`)
                setTimeout(() => {
                    printHtmlSafely(bundle?.letter?.html, 'Comprobante Tamaño Carta (216 × 279 mm)')
                }, 350)
            }
        } catch (err) {
            setPrintStatusMsg(formatAutopartesHttpError(err, 'Error al preparar la impresión; la venta confirmada permanece registrada.'))
        }
    }

    // Shift forms
    const [selectedRegisterId, setSelectedRegisterId] = useState(registers[0]?.id ? String(registers[0].id) : '')
    const [openingCash, setOpeningCash] = useState('500.00')
    const [shiftNotes, setShiftNotes] = useState('')
    const [closingCashCounted, setClosingCashCounted] = useState('')
    const [cashMovementType, setCashMovementType] = useState('IN')
    const [cashMovementAmount, setCashMovementAmount] = useState('')
    const [cashMovementReason, setCashMovementReason] = useState('')

    useEffect(() => {
        barcodeInputRef.current?.focus()
    }, [])

    useEffect(() => {
        if (cartItems.length === 0) {
            setCalculation(null)
            setCalcError(null)
            return
        }

        let cancelled = false
        const payload = {
            automotive_location_id: activeShift?.automotive_location_id || null,
            items: cartItems.map(i => ({
                automotive_part_id: i.automotive_part_id,
                automotive_location_id: i.automotive_location_id ?? i.location_id,
                quantity: i.quantity,
            })),
            manual_discount: manualDiscountType && Number(manualDiscountValue) > 0 ? {
                discount_type: manualDiscountType,
                discount_value: Number(manualDiscountValue),
                reason: manualDiscountReason,
                approval_token: approvalToken,
            } : null,
        }

        autopartesHttp.post(`${baseUrl(branch)}/pos/calcular`, payload)
            .then(res => {
                if (!cancelled && res.data?.calculation) {
                    setCalculation(res.data.calculation)
                    setCalcError(null)
                }
            })
            .catch(err => {
                if (!cancelled) {
                    setCalcError(formatAutopartesHttpError(err, 'Error al recalcular el carrito.'))
                }
            })

        return () => { cancelled = true }
    }, [cartItems, manualDiscountType, manualDiscountValue, manualDiscountReason, approvalToken, activeShift, branch])

    const addEligibleProductToCart = product => {
        if (!product.eligible) {
            setScanFeedback({
                type: 'error',
                message: `No elegible para venta: ${(product.reasons || []).join(' ')}`,
            })
            return
        }

        setCartItems(prev => {
            const idx = prev.findIndex(item => item.automotive_part_id === product.automotive_part_id)
            if (idx >= 0) {
                const nextQty = prev[idx].quantity + 1
                if (nextQty > product.available_stock) {
                    setScanFeedback({
                        type: 'warning',
                        message: `Stock insuficiente en ${branch.code} (${product.location_code}). Disponible: ${product.available_stock}.`,
                    })
                    return prev
                }
                const updated = [...prev]
                updated[idx] = { ...updated[idx], quantity: nextQty }
                return updated
            }
            return [...prev, { ...product, quantity: 1 }]
        })

        // Any cart change generates a fresh idempotency key for the new cart state
        setIdempotencyKey(makeIdempotencyKey(branch.code))
    }

    // USB scanner handler: Enter only searches/adds to cart, NEVER submits the sale form
    const handleBarcodeKeyDown = async e => {
        if (e.key !== 'Enter') return
        e.preventDefault()
        e.stopPropagation()

        const code = barcodeText.trim()
        if (!code) return

        try {
            const res = await autopartesHttp.post(`${baseUrl(branch)}/pos/escanear`, {
                code,
                automotive_location_id: activeShift?.automotive_location_id || null,
            })
            const data = res.data
            const matchedPart = data.part || data.product
            setBarcodeText('')

            if (data.status === 'exact_eligible' && matchedPart) {
                setMultipleCandidates([])
                addEligibleProductToCart(matchedPart)
                setScanFeedback({
                    type: 'success',
                    message: `Agregado: [${matchedPart.item_number || matchedPart.code}] ${matchedPart.description}`,
                })
            } else if (data.status === 'multiple' || data.status === 'ambiguous') {
                setMultipleCandidates(data.candidates || [])
                setScanFeedback({
                    type: 'warning',
                    message: data.message,
                })
            } else {
                setMultipleCandidates(data.candidates || [])
                setScanFeedback({
                    type: 'error',
                    message: data.message || 'Autoparte no disponible para venta.',
                })
            }
        } catch (err) {
            setScanFeedback({
                type: 'error',
                message: formatAutopartesHttpError(err, 'Error al consultar el código escaneado.'),
            })
        } finally {
            barcodeInputRef.current?.focus()
        }
    }

    const handleSearchCatalog = async e => {
        if (e) e.preventDefault()
        setSearching(true)
        try {
            const res = await autopartesHttp.get(`${baseUrl(branch)}/pos/productos`, {
                params: {
                    q: searchQuery,
                    automotive_location_id: activeShift?.automotive_location_id || undefined,
                },
            })
            setCatalogResults(res.data?.products || [])
        } catch (err) {
            setCatalogResults([])
            setScanFeedback({
                type: 'error',
                message: formatAutopartesHttpError(err, 'Error al buscar productos en el catálogo.'),
            })
        } finally {
            setSearching(false)
        }
    }

    const updateQuantity = (partId, newQuantity) => {
        const qty = parseInt(newQuantity, 10)
        if (Number.isNaN(qty) || qty <= 0) {
            removeFromCart(partId)
            return
        }
        setCartItems(prev => prev.map(item => {
            if (item.automotive_part_id !== partId) return item
            return { ...item, quantity: qty }
        }))
        setIdempotencyKey(makeIdempotencyKey(branch.code))
    }

    const removeFromCart = partId => {
        setCartItems(prev => prev.filter(item => item.automotive_part_id !== partId))
        setIdempotencyKey(makeIdempotencyKey(branch.code))
    }

    const handleRequestManualApproval = async () => {
        try {
            const res = await autopartesHttp.post(`${baseUrl(branch)}/pos/descuento-manual/solicitar`, {
                automotive_location_id: activeShift?.automotive_location_id || null,
                items: cartItems.map(i => ({
                    automotive_part_id: i.automotive_part_id,
                    automotive_location_id: i.automotive_location_id ?? i.location_id,
                    quantity: i.quantity,
                })),
                manual_discount: {
                    discount_type: manualDiscountType,
                    discount_value: Number(manualDiscountValue),
                    reason: manualDiscountReason,
                },
            })
            const approval = res.data?.approval
            setRequestedApproval(approval)
            if (approval?.status === 'approved' && approval?.token) {
                setApprovalToken(approval.token)
            }
        } catch (err) {
            setCalcError(formatAutopartesHttpError(err, 'No se pudo solicitar la aprobación.'))
        }
    }

    const handleConfirmSale = async () => {
        if (submittingSale || !canSell || !activeShift || cartItems.length === 0) return
        setSubmittingSale(true)
        setCalcError(null)

        const payload = {
            idempotency_key: idempotencyKey,
            automotive_pos_shift_id: activeShift.id,
            seller_id: Number(selectedSellerId),
            customer_name: customerName,
            payment_method: paymentMethod,
            paper_type: paperType,
            receipt_format: receiptFormat,
            amount_tendered: paymentMethod === 'cash' ? Number(amountTendered) : undefined,
            payment_reference: (paymentMethod === 'card' || paymentMethod === 'transfer') ? paymentReference : undefined,
            payments: paymentMethod === 'mixed' ? [
                {
                    method: 'cash',
                    amount: Number(mixedCashAmount || 0),
                    amount_tendered: Number(mixedCashTendered || mixedCashAmount || 0),
                },
                {
                    method: 'card',
                    amount: Number(mixedCardAmount || 0),
                    reference: mixedCardReference,
                },
            ] : undefined,
            items: cartItems.map(i => ({
                automotive_part_id: i.automotive_part_id,
                automotive_location_id: i.automotive_location_id ?? i.location_id,
                quantity: i.quantity,
            })),
            manual_discount: manualDiscountType && Number(manualDiscountValue) > 0 ? {
                discount_type: manualDiscountType,
                discount_value: Number(manualDiscountValue),
                reason: manualDiscountReason,
                approval_token: approvalToken,
            } : null,
        }

        try {
            const res = await autopartesHttp.post(`${baseUrl(branch)}/pos/ventas`, payload)
            setLastSale(res.data.sale)
            setLastReceipt(res.data.receipt)
            setCartItems([])
            setManualDiscountType('')
            setManualDiscountValue('')
            setManualDiscountReason('')
            setApprovalToken('')
            setRequestedApproval(null)
            setAmountTendered('')
            setPaymentReference('')
            setIdempotencyKey(makeIdempotencyKey(branch.code))
            router.reload({ only: ['activeShift', 'shiftSummary', 'initialProducts'] })
        } catch (err) {
            setCalcError(formatAutopartesHttpError(err, 'Error al confirmar la venta.'))
        } finally {
            setSubmittingSale(false)
            barcodeInputRef.current?.focus()
        }
    }

    const totalNum = Number(calculation?.total || 0)
    const tenderedNum = Number(amountTendered || 0)
    const changePreview = paymentMethod === 'cash' && tenderedNum >= totalNum
        ? (tenderedNum - totalNum).toFixed(2)
        : '0.00'

    return <Layout branch={branch} title={`Punto de venta — ${branch.name}`}>
        {/* Aviso de seguridad comercial cuando las políticas fiscales o de venta aún no están validadas */}
        {(!posConfig?.pos_sales_enabled || !posConfig?.fiscal_policy_validated) && (
            <div className="mb-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-xs text-amber-900 dark:border-amber-700 dark:bg-amber-950/60 dark:text-amber-200">
                <p className="font-bold uppercase">Seguridad Comercial Activa — Ventas Reales Bloqueadas</p>
                <p className="mt-1">
                    Las ventas reales en {branch.name} ({branch.code}) permanecen bloqueadas hasta disponer de precios MXN aprobados,
                    configuración fiscal validada y habilitación comercial expresa por administración.
                </p>
            </div>
        )}

        {/* Cabecera operativa de sucursal, caja, turno y cajero */}
        <div className="grid gap-4 lg:grid-cols-4">
            <div className={card}>
                <p className="text-xs font-semibold uppercase text-slate-500">Sucursal exclusiva</p>
                <p className="mt-1 text-lg font-bold text-indigo-700 dark:text-indigo-300">{branch.name} ({branch.code})</p>
                <p className="text-xs text-slate-500">Catálogo exclusivo: Autopartes</p>
            </div>
            <div className={card}>
                <p className="text-xs font-semibold uppercase text-slate-500">Caja y turno activo</p>
                {activeShift ? (
                    <>
                        <p className="mt-1 text-base font-bold text-emerald-700 dark:text-emerald-300">
                            {activeShift.cash_register?.code} · {activeShift.folio}
                        </p>
                        <p className="text-xs text-slate-500">
                            Ubicación: {activeShift.location?.code} · Efectivo esperado: ${shiftSummary?.expected_cash || activeShift.opening_cash} MXN
                        </p>
                    </>
                ) : (
                    <p className="mt-1 text-sm font-semibold text-red-600">Sin turno abierto — Abra caja para vender</p>
                )}
            </div>
            <div className={card}>
                <p className="text-xs font-semibold uppercase text-slate-500">Cajero autenticado (Sesión)</p>
                <p className="mt-1 text-base font-semibold">{cashier?.name}</p>
                <p className="text-xs text-slate-500">{cashier?.email}</p>
            </div>
            <div className={card}>
                <label className="block text-xs font-semibold uppercase text-slate-500">
                    Vendedor que atiende ({branch.code}) *
                </label>
                <select
                    value={selectedSellerId}
                    onChange={e => setSelectedSellerId(e.target.value)}
                    className={`${field} mt-1 w-full`}
                >
                    <option value="">-- Seleccione vendedor autorizado --</option>
                    {sellers.map(s => (
                        <option key={s.id} value={s.id}>{s.code} — {s.full_name}</option>
                    ))}
                </select>
            </div>
        </div>

        {/* Panel de Apertura / Cierre de Turno de Caja */}
        {canManageShift && (
            <div className={card}>
                {!activeShift ? (
                    <form
                        onSubmit={e => {
                            e.preventDefault()
                            router.post(`${baseUrl(branch)}/pos/turnos/abrir`, {
                                cash_register_id: selectedRegisterId,
                                opening_cash: openingCash,
                                notes: shiftNotes,
                            })
                        }}
                        className="flex flex-wrap items-end gap-3"
                    >
                        <div>
                            <label className="block text-xs font-medium text-slate-600">Caja de {branch.name}</label>
                            <select value={selectedRegisterId} onChange={e => setSelectedRegisterId(e.target.value)} className={field}>
                                {registers.map(r => (
                                    <option key={r.id} value={r.id}>{r.code} — {r.name}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="block text-xs font-medium text-slate-600">Fondo inicial MXN</label>
                            <input type="number" step="0.01" min="0" value={openingCash} onChange={e => setOpeningCash(e.target.value)} className={field} />
                        </div>
                        <div className="flex-1">
                            <label className="block text-xs font-medium text-slate-600">Observaciones de apertura</label>
                            <input type="text" value={shiftNotes} onChange={e => setShiftNotes(e.target.value)} placeholder="Opcional" className={`${field} w-full`} />
                        </div>
                        <button type="submit" className={button}>Abrir turno en {branch.code}</button>
                    </form>
                ) : (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <form
                            onSubmit={e => {
                                e.preventDefault()
                                router.post(`${baseUrl(branch)}/pos/turnos/${activeShift.id}/movimientos`, {
                                    type: cashMovementType,
                                    amount: cashMovementAmount,
                                    reason: cashMovementReason,
                                }, {
                                    onSuccess: () => {
                                        setCashMovementAmount('')
                                        setCashMovementReason('')
                                    },
                                })
                            }}
                            className="flex flex-wrap items-end gap-2"
                        >
                            <div>
                                <label className="block text-xs font-medium text-slate-600">Mov. Efectivo</label>
                                <select value={cashMovementType} onChange={e => setCashMovementType(e.target.value)} className={field}>
                                    <option value="IN">Entrada (+)</option>
                                    <option value="OUT">Retiro (-)</option>
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-slate-600">Importe MXN</label>
                                <input type="number" step="0.01" min="0.01" value={cashMovementAmount} onChange={e => setCashMovementAmount(e.target.value)} className={`${field} w-28`} />
                            </div>
                            <div className="flex-1">
                                <label className="block text-xs font-medium text-slate-600">Motivo obligatorio</label>
                                <input type="text" value={cashMovementReason} onChange={e => setCashMovementReason(e.target.value)} placeholder="Cambio, retiro autorizado..." className={`${field} w-full`} />
                            </div>
                            <button type="submit" className={secondaryButton}>Registrar efectivo</button>
                        </form>

                        <form
                            onSubmit={e => {
                                e.preventDefault()
                                router.post(`${baseUrl(branch)}/pos/turnos/${activeShift.id}/cerrar`, {
                                    closing_cash_counted: closingCashCounted,
                                })
                            }}
                            className="flex flex-wrap items-end justify-end gap-2"
                        >
                            <div>
                                <label className="block text-xs font-medium text-slate-600">Efectivo contado al cierre (MXN)</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={closingCashCounted}
                                    onChange={e => setClosingCashCounted(e.target.value)}
                                    placeholder={shiftSummary?.expected_cash || '0.00'}
                                    className={field}
                                />
                            </div>
                            <button type="submit" className={dangerButton}>Cerrar turno y arqueo</button>
                        </form>
                    </div>
                )}
            </div>
        )}

        <div className="grid gap-6 lg:grid-cols-12">
            {/* Columna izquierda: Lector USB + Buscador de autopartes */}
            <div className="space-y-5 lg:col-span-7">
                <div className={`${card} border-2 border-indigo-500`}>
                    <label className="block text-xs font-bold uppercase tracking-wider text-indigo-700 dark:text-indigo-300">
                        Lector USB de Códigos de Barras (Enter busca o agrega al carrito — NUNCA confirma cobro)
                    </label>
                    <div className="mt-2 flex gap-2">
                        <input
                            ref={barcodeInputRef}
                            type="text"
                            value={barcodeText}
                            onChange={e => setBarcodeText(e.target.value)}
                            onKeyDown={handleBarcodeKeyDown}
                            placeholder="Escanee código verificado, SKU, Item # o MFG Part # y presione Enter..."
                            className={`${field} flex-1 font-mono text-base`}
                        />
                        <button
                            type="button"
                            onClick={() => handleBarcodeKeyDown({ key: 'Enter', preventDefault: () => {}, stopPropagation: () => {} })}
                            className={button}
                        >
                            Buscar código
                        </button>
                    </div>
                    {scanFeedback && (
                        <div className={`mt-3 rounded-lg p-3 text-sm ${
                            scanFeedback.type === 'success'
                                ? 'bg-emerald-50 text-emerald-900'
                                : scanFeedback.type === 'warning'
                                    ? 'bg-amber-50 text-amber-900'
                                    : 'bg-red-50 text-red-900'
                        }`}>
                            {scanFeedback.message}
                        </div>
                    )}

                    {multipleCandidates.length > 1 && (
                        <div className="mt-3 space-y-2 rounded-lg border border-amber-300 bg-amber-50 p-3">
                            <p className="text-xs font-bold uppercase text-amber-900">
                                Candidatos encontrados (selección manual obligatoria — no se auto-selecciona el primero):
                            </p>
                            {multipleCandidates.map(cand => (
                                <div key={cand.automotive_part_id} className="flex items-center justify-between gap-2 rounded bg-white p-2 text-xs shadow-sm">
                                    <div>
                                        <p className="font-semibold">{cand.item_number} · MFG: {cand.manufacturer_part_number} · Código: {cand.code}</p>
                                        <p className="text-slate-600">{cand.description}</p>
                                        <div className="mt-1 flex gap-2">
                                            <IdentityBadge status={cand.identity_status} />
                                            <span>Stock {branch.code}: {cand.available_stock}</span>
                                            <span>Precio: {cand.sale_price_mxn ? `$${cand.sale_price_mxn} MXN` : 'Sin precio'}</span>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        disabled={!cand.eligible}
                                        onClick={() => {
                                            addEligibleProductToCart(cand)
                                            setMultipleCandidates([])
                                        }}
                                        className={button}
                                    >
                                        Seleccionar
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                {/* Buscador manual del catálogo de autopartes */}
                <div className={card}>
                    <form onSubmit={handleSearchCatalog} className="flex gap-2">
                        <input
                            type="text"
                            value={searchQuery}
                            onChange={e => setSearchQuery(e.target.value)}
                            placeholder="Buscar autoparte por Item #, MFG Part #, SKU, descripción o categoría..."
                            className={`${field} flex-1`}
                        />
                        <button type="submit" disabled={searching} className={secondaryButton}>
                            {searching ? 'Buscando...' : 'Buscar'}
                        </button>
                    </form>

                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead>
                                <tr className="border-b border-slate-200 text-slate-500">
                                    <th className="py-2">Item # / MFG</th>
                                    <th className="py-2">Descripción</th>
                                    <th className="py-2">Estado</th>
                                    <th className="py-2 text-right">Stock {branch.code}</th>
                                    <th className="py-2 text-right">Precio MXN</th>
                                    <th className="py-2 text-right">Acción</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {catalogResults.map(p => (
                                    <tr key={p.automotive_part_id}>
                                        <td className="py-2 font-mono">
                                            <div className="font-semibold">{p.item_number || p.code}</div>
                                            <div className="text-slate-500">MFG: {p.manufacturer_part_number || '—'}</div>
                                        </td>
                                        <td className="py-2">
                                            <div className="font-medium">{p.description}</div>
                                            <div className="text-slate-500">{p.category} · {p.vendor_name}</div>
                                            {!p.eligible && p.reasons?.length > 0 && (
                                                <div className="mt-0.5 text-red-600">{p.reasons.join(' ')}</div>
                                            )}
                                        </td>
                                        <td className="py-2"><IdentityBadge status={p.identity_status} /></td>
                                        <td className="py-2 text-right font-mono font-semibold">{p.available_stock}</td>
                                        <td className="py-2 text-right font-mono font-semibold">
                                            {p.sale_price_mxn ? `$${p.sale_price_mxn}` : <span className="text-red-600">No autorizado</span>}
                                        </td>
                                        <td className="py-2 text-right">
                                            <button
                                                type="button"
                                                disabled={!p.eligible}
                                                onClick={() => addEligibleProductToCart(p)}
                                                className={button}
                                            >
                                                + Carrito
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {/* Columna derecha: Carrito de compra, descuentos, método de pago y confirmación */}
            <div className="space-y-5 lg:col-span-5">
                <div className={card}>
                    <div className="flex items-center justify-between">
                        <h2 className="text-base font-bold">Carrito de venta ({branch.code})</h2>
                        <span className="font-mono text-xs text-slate-500">Clave idempotente activa</span>
                    </div>

                    {cartItems.length === 0 ? (
                        <p className="mt-4 text-sm text-slate-500">Escanee o seleccione autopartes autorizadas para iniciar la venta.</p>
                    ) : (
                        <div className="mt-3 space-y-3">
                            {(calculation?.items || cartItems).map(item => (
                                <div key={item.automotive_part_id} className="flex items-center justify-between gap-2 rounded-lg border border-slate-200 p-2.5 text-xs">
                                    <div className="flex-1">
                                        <p className="font-mono font-bold">{item.item_number || item.code} · MFG: {item.manufacturer_part_number || '—'}</p>
                                        <p className="text-slate-700 dark:text-neutral-300">{item.description}</p>
                                        <p className="text-slate-500">
                                            P.U. Autorizado: <strong>${item.unit_price_mxn || item.sale_price_mxn} MXN</strong> · Disp. {branch.code}: {item.available_stock}
                                        </p>
                                        {Number(item.discount_amount || 0) > 0 && (
                                            <p className="text-emerald-700">
                                                Descuento línea: -${item.discount_amount} MXN (Mayoreo: -${item.wholesale_discount_amount}, Manual: -${item.manual_discount_amount})
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <input
                                            type="number"
                                            min="1"
                                            max={item.available_stock}
                                            value={cartItems.find(c => c.automotive_part_id === item.automotive_part_id)?.quantity ?? item.quantity}
                                            onChange={e => updateQuantity(item.automotive_part_id, e.target.value)}
                                            className={`${field} w-16 text-center font-mono`}
                                        />
                                        <div className="w-20 text-right font-mono font-bold">
                                            ${item.subtotal || (Number(item.sale_price_mxn || 0) * item.quantity).toFixed(2)}
                                        </div>
                                        <button type="button" onClick={() => removeFromCart(item.automotive_part_id)} className={dangerButton}>✕</button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    {/* Descuento manual auditado */}
                    {cartItems.length > 0 && (
                        <div className="mt-4 space-y-2 border-t border-slate-200 pt-4 text-xs">
                            <p className="font-semibold text-slate-700 dark:text-neutral-200">
                                Descuento manual auditado (Límite cajero: {posConfig.cashier_max_manual_discount_percent}% / ${posConfig.cashier_max_manual_discount_amount} MXN)
                            </p>
                            <div className="grid grid-cols-2 gap-2">
                                <select value={manualDiscountType} onChange={e => setManualDiscountType(e.target.value)} className={field}>
                                    <option value="">Sin descuento manual</option>
                                    <option value="percentage">Porcentaje (%)</option>
                                    <option value="fixed_amount">Importe fijo ($ MXN)</option>
                                </select>
                                {manualDiscountType && (
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        value={manualDiscountValue}
                                        onChange={e => setManualDiscountValue(e.target.value)}
                                        placeholder="Valor de descuento"
                                        className={field}
                                    />
                                )}
                            </div>
                            {manualDiscountType && (
                                <>
                                    <input
                                        type="text"
                                        value={manualDiscountReason}
                                        onChange={e => setManualDiscountReason(e.target.value)}
                                        placeholder="Motivo obligatorio del descuento manual (mín. 5 caracteres)..."
                                        className={`${field} w-full`}
                                    />
                                    <div className="flex gap-2">
                                        <input
                                            type="text"
                                            value={approvalToken}
                                            onChange={e => setApprovalToken(e.target.value)}
                                            placeholder="Token de aprobación administrativa (si supera límite)"
                                            className={`${field} flex-1 font-mono text-xs`}
                                        />
                                        <button type="button" onClick={handleRequestManualApproval} className={secondaryButton}>
                                            {canApproveDiscount ? 'Autorizar directo' : 'Solicitar aprobación'}
                                        </button>
                                    </div>
                                    {requestedApproval && (
                                        <p className="text-indigo-700">
                                            Solicitud #{requestedApproval.id} ({requestedApproval.status}) — Token: <code>{requestedApproval.token}</code>
                                        </p>
                                    )}
                                </>
                            )}
                        </div>
                    )}

                    {/* Resumen numérico calculado en el servidor */}
                    {calculation && (
                        <div className="mt-4 space-y-1 border-t border-slate-200 pt-4 text-sm">
                            <div className="flex justify-between">
                                <span>Subtotal Bruto:</span>
                                <span className="font-mono">${calculation.subtotal} MXN</span>
                            </div>
                            {Number(calculation.wholesale_discount_amount) > 0 && (
                                <div className="flex justify-between text-emerald-700">
                                    <span>Descuento por Mayoreo:</span>
                                    <span className="font-mono">-${calculation.wholesale_discount_amount} MXN</span>
                                </div>
                            )}
                            {Number(calculation.manual_discount_amount) > 0 && (
                                <div className="flex justify-between text-emerald-700">
                                    <span>Descuento Manual Autorizado:</span>
                                    <span className="font-mono">-${calculation.manual_discount_amount} MXN</span>
                                </div>
                            )}
                            {Number(calculation.tax_amount) > 0 && (
                                <div className="flex justify-between">
                                    <span>Impuestos ({Number(calculation.tax_rate) * 100}%):</span>
                                    <span className="font-mono">${calculation.tax_amount} MXN</span>
                                </div>
                            )}
                            <div className="flex justify-between border-t border-slate-300 pt-2 text-lg font-bold">
                                <span>TOTAL A COBRAR:</span>
                                <span className="font-mono text-indigo-700 dark:text-indigo-300">${calculation.total} MXN</span>
                            </div>
                        </div>
                    )}

                    {calcError && (
                        <div role="alert" className="mt-3 rounded-lg border border-red-300 bg-red-50 p-3 text-xs text-red-800">
                            {calcError}
                        </div>
                    )}

                    {/* Método de pago y confirmación explícita */}
                    <div className="mt-4 space-y-3 border-t border-slate-200 pt-4 text-xs">
                        <div>
                            <label className="block font-semibold text-slate-700">Cliente (opcional)</label>
                            <input
                                type="text"
                                value={customerName}
                                onChange={e => setCustomerName(e.target.value)}
                                placeholder="Público en general / Taller"
                                className={`${field} mt-1 w-full`}
                            />
                        </div>

                        <div>
                            <label className="block font-semibold text-slate-700">Método de pago (Sin crédito)</label>
                            <select value={paymentMethod} onChange={e => setPaymentMethod(e.target.value)} className={`${field} mt-1 w-full`}>
                                <option value="cash">Efectivo (MXN)</option>
                                <option value="card">Tarjeta Débito / Crédito (Registro manual de referencia)</option>
                                <option value="transfer">Transferencia Bancaria (Registro manual de referencia)</option>
                                <option value="mixed">Pago Mixto (Efectivo + Tarjeta)</option>
                            </select>
                        </div>

                        {paymentMethod === 'cash' && (
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="block font-semibold text-slate-700">Efectivo recibido ($ MXN)</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={amountTendered}
                                        onChange={e => setAmountTendered(e.target.value)}
                                        placeholder={calculation?.total || '0.00'}
                                        className={`${field} mt-1 w-full font-mono`}
                                    />
                                </div>
                                <div>
                                    <label className="block font-semibold text-slate-700">Cambio a entregar</label>
                                    <div className="mt-1 rounded-lg bg-slate-100 px-3 py-2 font-mono text-sm font-bold text-emerald-700">
                                        ${changePreview} MXN
                                    </div>
                                </div>
                            </div>
                        )}

                        {(paymentMethod === 'card' || paymentMethod === 'transfer') && (
                            <div>
                                <label className="block font-semibold text-slate-700">
                                    Referencia / Autorización obligatoria (No existe cobro electrónico integrado)
                                </label>
                                <input
                                    type="text"
                                    value={paymentReference}
                                    onChange={e => setPaymentReference(e.target.value)}
                                    placeholder="Folio voucher terminal externa o clave de rastreo SPEI..."
                                    className={`${field} mt-1 w-full font-mono`}
                                />
                            </div>
                        )}

                        {paymentMethod === 'mixed' && (
                            <div className="grid grid-cols-2 gap-2">
                                <input
                                    type="number"
                                    step="0.01"
                                    value={mixedCashAmount}
                                    onChange={e => setMixedCashAmount(e.target.value)}
                                    placeholder="Importe Efectivo"
                                    className={field}
                                />
                                <input
                                    type="number"
                                    step="0.01"
                                    value={mixedCashTendered}
                                    onChange={e => setMixedCashTendered(e.target.value)}
                                    placeholder="Efectivo Recibido"
                                    className={field}
                                />
                                <input
                                    type="number"
                                    step="0.01"
                                    value={mixedCardAmount}
                                    onChange={e => setMixedCardAmount(e.target.value)}
                                    placeholder="Importe Tarjeta"
                                    className={field}
                                />
                                <input
                                    type="text"
                                    value={mixedCardReference}
                                    onChange={e => setMixedCardReference(e.target.value)}
                                    placeholder="Referencia Tarjeta"
                                    className={field}
                                />
                            </div>
                        )}

                        <div className="grid grid-cols-2 gap-2">
                            <div>
                                <label className="block font-semibold text-slate-700">Comprobante al cobrar</label>
                                <select value={receiptFormat} onChange={e => setReceiptFormat(e.target.value)} className={`${field} mt-1 w-full`}>
                                    <option value="both">Ambos (Térmico + Hoja Carta)</option>
                                    <option value="thermal">Ticket Térmico</option>
                                    <option value="letter">Comprobante Tamaño Carta (216 × 279 mm)</option>
                                </select>
                            </div>
                            <div>
                                <label className="block font-semibold text-slate-700">Ancho papel térmico</label>
                                <select value={paperType} onChange={e => setPaperType(e.target.value)} className={`${field} mt-1 w-full`}>
                                    <option value="80mm">80 mm (48 columnas)</option>
                                    <option value="58mm">58 mm (32 columnas)</option>
                                </select>
                            </div>
                        </div>

                        <button
                            type="button"
                            disabled={submittingSale || !canSell || !activeShift || !selectedSellerId || cartItems.length === 0}
                            onClick={handleConfirmSale}
                            className={`${button} w-full py-3 text-base`}
                        >
                            {submittingSale ? 'Procesando cobro...' : `Confirmar cobro en ${branch.name}`}
                        </button>
                    </div>
                </div>

                {/* Comprobantes confirmados post-venta: Ticket Térmico (58mm/80mm) y Hoja Tamaño Carta (216 × 279 mm) */}
                {lastReceipt && (
                    <div className={card}>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h3 className="text-sm font-bold text-emerald-700">
                                    Venta registrada: {lastSale?.folio}
                                </h3>
                                <p className="text-xs text-slate-500">
                                    ESTE COMPROBANTE NO ES FACTURA FISCAL CFDI · Seleccione el formato de impresión:
                                </p>
                            </div>
                            <select
                                value={paperType}
                                onChange={e => setPaperType(e.target.value)}
                                className={`${field} text-xs`}
                            >
                                <option value="80mm">Térmico 80 mm</option>
                                <option value="58mm">Térmico 58 mm</option>
                            </select>
                        </div>

                        <div className="mt-3 flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={() => handlePrintSelection('thermal', paperType)}
                                className={secondaryButton}
                            >
                                Imprimir Ticket Térmico ({paperType})
                            </button>
                            <button
                                type="button"
                                onClick={() => handlePrintSelection('letter', paperType)}
                                className={secondaryButton}
                            >
                                Imprimir Hoja Carta (216 × 279 mm / PDF)
                            </button>
                            <button
                                type="button"
                                onClick={() => handlePrintSelection('both', paperType)}
                                className={button}
                            >
                                Imprimir Ambos
                            </button>
                        </div>

                        {printStatusMsg && (
                            <p className="mt-2 text-xs font-medium text-indigo-700 dark:text-indigo-300">
                                {printStatusMsg}
                            </p>
                        )}

                        <div className="mt-3 flex gap-2 border-b border-slate-200 pb-2 text-xs">
                            <button
                                type="button"
                                onClick={() => setVoucherPreviewTab('thermal')}
                                className={`rounded px-2.5 py-1 font-semibold ${voucherPreviewTab === 'thermal' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700'}`}
                            >
                                Vista Ticket Térmico ({lastReceipt.paper_type || paperType})
                            </button>
                            <button
                                type="button"
                                onClick={() => setVoucherPreviewTab('letter')}
                                className={`rounded px-2.5 py-1 font-semibold ${voucherPreviewTab === 'letter' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700'}`}
                            >
                                Vista Tamaño Carta (216 × 279 mm)
                            </button>
                        </div>

                        {voucherPreviewTab === 'thermal' ? (
                            <pre className="mt-3 overflow-x-auto rounded-lg bg-slate-900 p-4 font-mono text-xs text-slate-100">
                                {lastReceipt.thermal?.text || lastReceipt.text}
                            </pre>
                        ) : (
                            <div
                                className="mt-3 max-h-96 overflow-y-auto rounded-lg border border-slate-200 bg-white p-3 text-slate-900"
                                dangerouslySetInnerHTML={{ __html: lastReceipt.letter?.html || '' }}
                            />
                        )}
                    </div>
                )}
            </div>
        </div>
    </Layout>
}
