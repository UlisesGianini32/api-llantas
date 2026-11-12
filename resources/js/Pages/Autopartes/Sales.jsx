import { router } from '@inertiajs/react'
import { useState } from 'react'
import { AUTOPARTES_CAPABILITIES, hasCapability } from '@/lib/autopartesCapabilities'
import { autopartesHttp, formatAutopartesHttpError } from '@/lib/autopartesHttp'
import Layout, { baseUrl, button, card, dangerButton, field, Pagination, secondaryButton } from './Layout'

export default function Sales({ branch, capabilities = [], filters = {}, sales, sellers = [] }) {
    const canCancel = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.SALES_CANCEL)
    const canReturn = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.SALES_RETURN)

    const [folio, setFolio] = useState(filters.folio || '')
    const [sellerId, setSellerId] = useState(filters.seller_id || '')
    const [paymentMethod, setPaymentMethod] = useState(filters.payment_method || '')
    const [status, setStatus] = useState(filters.status || '')
    const [dateFrom, setDateFrom] = useState(filters.date_from || '')
    const [dateTo, setDateTo] = useState(filters.date_to || '')

    const [activeSaleForCancel, setActiveSaleForCancel] = useState(null)
    const [cancelReason, setCancelReason] = useState('')

    const [activeSaleForReturn, setActiveSaleForReturn] = useState(null)
    const [returnQuantities, setReturnQuantities] = useState({})
    const [returnReason, setReturnReason] = useState('')
    const [returnRefundMethod, setReturnRefundMethod] = useState('cash')

    const [reprintPreview, setReprintPreview] = useState(null)
    const [reprintPaperType, setReprintPaperType] = useState('80mm')
    const [reprintTab, setReprintTab] = useState('thermal')
    const [reprintError, setReprintError] = useState(null)

    const handleReprint = async (sale, format = 'both', paperType = reprintPaperType) => {
        setReprintError(null)
        try {
            const res = await autopartesHttp.post(`${baseUrl(branch)}/pos/ventas/${sale.id}/reimprimir`, {
                format,
                paper_type: paperType,
            })
            setReprintPreview(res.data?.receipt)
            if (format === 'letter') {
                setReprintTab('letter')
            } else {
                setReprintTab('thermal')
            }
        } catch (err) {
            setReprintError(formatAutopartesHttpError(err, 'No fue posible cargar la reimpresión del comprobante.'))
        }
    }

    const printHtmlWindow = html => {
        if (!html) return
        try {
            const win = window.open('', '_blank', 'width=900,height=800')
            if (!win) return
            win.document.open()
            win.document.write(html)
            win.document.close()
            win.focus()
            win.print()
        } catch (_err) {
            // Printing failure never alters the confirmed sale
        }
    }

    const submitCancel = e => {
        e.preventDefault()
        if (!activeSaleForCancel) return
        router.post(`${baseUrl(branch)}/ventas/${activeSaleForCancel.id}/cancelar`, {
            reason: cancelReason,
            idempotency_key: `CANCEL-${activeSaleForCancel.id}-${Date.now()}`,
        }, {
            onSuccess: () => {
                setActiveSaleForCancel(null)
                setCancelReason('')
            },
        })
    }

    const openReturnModal = sale => {
        setActiveSaleForReturn(sale)
        const initial = {}
        ;(sale.items || []).forEach(item => {
            const rem = Math.max(0, Number(item.quantity) - Number(item.returned_quantity || 0))
            initial[item.id] = rem > 0 ? 1 : 0
        })
        setReturnQuantities(initial)
        setReturnReason('')
    }

    const submitReturn = e => {
        e.preventDefault()
        if (!activeSaleForReturn) return
        const items = Object.entries(returnQuantities)
            .map(([saleItemId, qty]) => ({
                automotive_pos_sale_item_id: Number(saleItemId),
                quantity: Number(qty),
            }))
            .filter(r => r.quantity > 0)

        router.post(`${baseUrl(branch)}/ventas/${activeSaleForReturn.id}/devolver`, {
            reason: returnReason,
            refund_method: returnRefundMethod,
            idempotency_key: `RET-${activeSaleForReturn.id}-${Date.now()}`,
            items,
        }, {
            onSuccess: () => {
                setActiveSaleForReturn(null)
                setReturnReason('')
            },
        })
    }

    return <Layout branch={branch} title={`Ventas — ${branch.name}`}>
        {reprintError && (
            <div className="mb-3 rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-xs font-semibold text-red-800">
                {reprintError}
            </div>
        )}
        <div className={card}>
            <form
                onSubmit={e => {
                    e.preventDefault()
                    router.get(`${baseUrl(branch)}/ventas`, {
                        folio,
                        seller_id: sellerId,
                        payment_method: paymentMethod,
                        status,
                        date_from: dateFrom,
                        date_to: dateTo,
                    }, { preserveState: true })
                }}
                className="grid gap-2 md:grid-cols-6"
            >
                <input
                    type="text"
                    value={folio}
                    onChange={e => setFolio(e.target.value)}
                    placeholder="Folio VTA-HMO..."
                    className={`${field} font-mono`}
                />
                <select value={sellerId} onChange={e => setSellerId(e.target.value)} className={field}>
                    <option value="">Todos los vendedores</option>
                    {sellers.map(s => <option key={s.id} value={s.id}>{s.code} — {s.full_name}</option>)}
                </select>
                <select value={paymentMethod} onChange={e => setPaymentMethod(e.target.value)} className={field}>
                    <option value="">Todos los métodos</option>
                    <option value="cash">Efectivo</option>
                    <option value="card">Tarjeta</option>
                    <option value="transfer">Transferencia</option>
                    <option value="mixed">Mixto</option>
                </select>
                <select value={status} onChange={e => setStatus(e.target.value)} className={field}>
                    <option value="">Todos los estados</option>
                    <option value="completed">Completada</option>
                    <option value="partially_returned">Devuelta parcial</option>
                    <option value="returned">Devuelta total</option>
                    <option value="cancelled">Cancelada</option>
                </select>
                <input type="date" value={dateFrom} onChange={e => setDateFrom(e.target.value)} className={field} />
                <div className="flex gap-2">
                    <input type="date" value={dateTo} onChange={e => setDateTo(e.target.value)} className={`${field} flex-1`} />
                    <button type="submit" className={secondaryButton}>Filtrar</button>
                </div>
            </form>

            <div className="mt-4 overflow-x-auto">
                <table className="w-full text-left text-xs">
                    <thead>
                        <tr className="border-b border-slate-200 uppercase text-slate-500">
                            <th className="py-2">Folio / Fecha</th>
                            <th className="py-2">Vendedor / Cajero</th>
                            <th className="py-2">Caja / Turno</th>
                            <th className="py-2">Partidas</th>
                            <th className="py-2 text-right">Descuento</th>
                            <th className="py-2 text-right">Total MXN</th>
                            <th className="py-2">Pago / Estado</th>
                            <th className="py-2 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {(sales?.data || []).map(sale => (
                            <tr key={sale.id}>
                                <td className="py-2.5 font-mono">
                                    <div className="font-bold">{sale.folio}</div>
                                    <div className="text-slate-500">{sale.created_at}</div>
                                </td>
                                <td className="py-2.5">
                                    <div className="font-semibold">{sale.seller_code_snapshot} — {sale.seller_name_snapshot}</div>
                                    <div className="text-slate-500">Cajero: {sale.cashier_name || sale.cashier_name_snapshot || '—'}</div>
                                </td>
                                <td className="py-2.5 font-mono">
                                    <div>{sale.cash_register?.code}</div>
                                    <div className="text-slate-500">{sale.shift?.folio}</div>
                                </td>
                                <td className="py-2.5">
                                    {(sale.items || []).map(item => (
                                        <div key={item.id}>
                                            <span className="font-mono font-semibold">{item.quantity}x</span> [{item.item_number_snapshot || item.part_code_snapshot}] {item.description_snapshot}
                                            {Number(item.returned_quantity) > 0 && (
                                                <span className="ml-1 text-amber-700">(Dev: {item.returned_quantity})</span>
                                            )}
                                        </div>
                                    ))}
                                </td>
                                <td className="py-2.5 text-right font-mono text-emerald-700">-${sale.discount_amount}</td>
                                <td className="py-2.5 text-right font-mono font-bold">
                                    <div>${sale.total}</div>
                                    {Number(sale.refunded_amount) > 0 && (
                                        <div className="text-red-600">Reemb: -${sale.refunded_amount}</div>
                                    )}
                                </td>
                                <td className="py-2.5">
                                    <div className="uppercase font-medium">{sale.payment_method}</div>
                                    <div className="text-slate-600">{sale.status}</div>
                                </td>
                                <td className="py-2.5 text-right space-x-1">
                                    <button type="button" onClick={() => handleReprint(sale, 'thermal', reprintPaperType)} className={secondaryButton}>
                                        Térmico ({sale.reprint_count})
                                    </button>
                                    <button type="button" onClick={() => handleReprint(sale, 'letter', reprintPaperType)} className={secondaryButton}>
                                        Carta 216×279mm
                                    </button>
                                    {canReturn && sale.status !== 'cancelled' && sale.status !== 'returned' && (
                                        <button type="button" onClick={() => openReturnModal(sale)} className={secondaryButton}>
                                            Devolver
                                        </button>
                                    )}
                                    {canCancel && sale.status === 'completed' && (
                                        <button type="button" onClick={() => setActiveSaleForCancel(sale)} className={dangerButton}>
                                            Cancelar
                                        </button>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination data={sales} />
        </div>

        {/* Modal de cancelación completa */}
        {activeSaleForCancel && (
            <div className={card}>
                <h3 className="text-sm font-bold text-red-700">
                    Cancelar venta completa: {activeSaleForCancel.folio} (${activeSaleForCancel.total} MXN)
                </h3>
                <form onSubmit={submitCancel} className="mt-3 flex gap-2">
                    <input
                        type="text"
                        value={cancelReason}
                        onChange={e => setCancelReason(e.target.value)}
                        placeholder="Motivo obligatorio de cancelación (reingresa stock a ubicación original)..."
                        className={`${field} flex-1`}
                    />
                    <button type="submit" className={dangerButton}>Confirmar cancelación</button>
                    <button type="button" onClick={() => setActiveSaleForCancel(null)} className={secondaryButton}>Cerrar</button>
                </form>
            </div>
        )}

        {/* Modal de devolución parcial o total por partida */}
        {activeSaleForReturn && (
            <div className={card}>
                <h3 className="text-sm font-bold">
                    Registrar devolución auditada: {activeSaleForReturn.folio}
                </h3>
                <form onSubmit={submitReturn} className="mt-3 space-y-3 text-xs">
                    {(activeSaleForReturn.items || []).map(item => {
                        const rem = Math.max(0, Number(item.quantity) - Number(item.returned_quantity || 0))
                        return (
                            <div key={item.id} className="flex items-center justify-between gap-3 border-b border-slate-100 pb-2">
                                <div>
                                    <span className="font-mono font-bold">{item.item_number_snapshot || item.part_code_snapshot}</span> — {item.description_snapshot}
                                    <span className="ml-2 text-slate-500">(Vendidas: {item.quantity}, Disponibles p/devolver: {rem})</span>
                                </div>
                                <input
                                    type="number"
                                    min="0"
                                    max={rem}
                                    value={returnQuantities[item.id] ?? 0}
                                    onChange={e => setReturnQuantities({ ...returnQuantities, [item.id]: e.target.value })}
                                    className={`${field} w-20 font-mono`}
                                />
                            </div>
                        )
                    })}
                    <div className="flex flex-wrap gap-2">
                        <select value={returnRefundMethod} onChange={e => setReturnRefundMethod(e.target.value)} className={field}>
                            <option value="cash">Reembolso en Efectivo</option>
                            <option value="card_reversal">Reverso Tarjeta</option>
                            <option value="transfer_reversal">Transferencia</option>
                            <option value="original_method">Método original</option>
                        </select>
                        <input
                            type="text"
                            value={returnReason}
                            onChange={e => setReturnReason(e.target.value)}
                            placeholder="Motivo obligatorio de la devolución..."
                            className={`${field} flex-1`}
                        />
                        <button type="submit" className={button}>Procesar devolución</button>
                        <button type="button" onClick={() => setActiveSaleForReturn(null)} className={secondaryButton}>Cerrar</button>
                    </div>
                </form>
            </div>
        )}

        {reprintPreview && (
            <div className={card}>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h3 className="text-sm font-bold">
                            Reimpresión autorizada (#{reprintPreview.reprint_count}) — Folio {reprintPreview.data?.folio}
                        </h3>
                        <p className="text-xs text-slate-500">
                            ESTE COMPROBANTE NO ES FACTURA FISCAL CFDI · Los importes provienen de la instantánea histórica de la venta
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <select
                            value={reprintPaperType}
                            onChange={e => setReprintPaperType(e.target.value)}
                            className={`${field} text-xs`}
                        >
                            <option value="80mm">Térmico 80 mm</option>
                            <option value="58mm">Térmico 58 mm</option>
                        </select>
                        <button
                            type="button"
                            onClick={() => printHtmlWindow(reprintPreview.thermal?.html || reprintPreview.html)}
                            className={secondaryButton}
                        >
                            Imprimir Térmico ({reprintPreview.paper_type || reprintPaperType})
                        </button>
                        <button
                            type="button"
                            onClick={() => printHtmlWindow(reprintPreview.letter?.html)}
                            className={secondaryButton}
                        >
                            Imprimir Carta (216 × 279 mm)
                        </button>
                        <button
                            type="button"
                            onClick={() => {
                                printHtmlWindow(reprintPreview.thermal?.html || reprintPreview.html)
                                setTimeout(() => printHtmlWindow(reprintPreview.letter?.html), 350)
                            }}
                            className={button}
                        >
                            Imprimir Ambos
                        </button>
                        <button type="button" onClick={() => setReprintPreview(null)} className={secondaryButton}>
                            Cerrar
                        </button>
                    </div>
                </div>

                <div className="mt-3 flex gap-2 border-b border-slate-200 pb-2 text-xs">
                    <button
                        type="button"
                        onClick={() => setReprintTab('thermal')}
                        className={`rounded px-2.5 py-1 font-semibold ${reprintTab === 'thermal' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700'}`}
                    >
                        Ticket Térmico ({reprintPreview.paper_type || reprintPaperType})
                    </button>
                    <button
                        type="button"
                        onClick={() => setReprintTab('letter')}
                        className={`rounded px-2.5 py-1 font-semibold ${reprintTab === 'letter' ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-700'}`}
                    >
                        Comprobante Tamaño Carta (216 × 279 mm)
                    </button>
                </div>

                {reprintTab === 'thermal' ? (
                    <pre className="mt-3 overflow-x-auto rounded-lg bg-slate-900 p-4 font-mono text-xs text-slate-100">
                        {reprintPreview.thermal?.text || reprintPreview.text}
                    </pre>
                ) : (
                    <div
                        className="mt-3 max-h-96 overflow-y-auto rounded-lg border border-slate-200 bg-white p-3 text-slate-900"
                        dangerouslySetInnerHTML={{ __html: reprintPreview.letter?.html || '' }}
                    />
                )}
            </div>
        )}
    </Layout>
}
