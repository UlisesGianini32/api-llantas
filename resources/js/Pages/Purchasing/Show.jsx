import { useState, useMemo } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

function formatCurrency(val) {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    }).format(val || 0)
}

function formatDate(dateStr) {
    if (!dateStr) return '-'
    const d = new Date(dateStr)
    return d.toLocaleDateString('es-MX', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    })
}

function formatDateTime(dateStr) {
    if (!dateStr) return '-'
    const d = new Date(dateStr)
    return d.toLocaleDateString('es-MX', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}

const STATUS_BADGES = {
    DRAFT: {
        label: 'Borrador',
        bg: 'bg-slate-100 text-slate-700 border-slate-300 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700',
    },
    ORDERED: {
        label: 'Colocada (En camino)',
        bg: 'bg-blue-100 text-blue-800 border-blue-300 dark:bg-blue-900/40 dark:text-blue-300 dark:border-blue-700',
    },
    PARTIAL: {
        label: 'Recepción Parcial',
        bg: 'bg-amber-100 text-amber-800 border-amber-300 dark:bg-amber-900/40 dark:text-amber-300 dark:border-amber-700',
    },
    RECEIVED: {
        label: 'Recibida Completa',
        bg: 'bg-emerald-100 text-emerald-800 border-emerald-300 dark:bg-emerald-900/40 dark:text-emerald-300 dark:border-emerald-700',
    },
    CANCELLED: {
        label: 'Cancelada',
        bg: 'bg-rose-100 text-rose-800 border-rose-300 dark:bg-rose-900/40 dark:text-rose-300 dark:border-rose-700',
    },
}

export default function PurchasingShow({ order, locations = [] }) {
    const badge = STATUS_BADGES[order.status] || STATUS_BADGES.DRAFT

    // Modal states
    const [orderModalOpen, setOrderModalOpen] = useState(false)
    const [orderQuoteRef, setOrderQuoteRef] = useState(order.supplier_quote_reference || '')
    const [orderDeliveryDate, setOrderDeliveryDate] = useState(order.expected_delivery_date || '')
    const [orderingSubmitting, setOrderingSubmitting] = useState(false)

    const [receiveModalOpen, setReceiveModalOpen] = useState(
        typeof window !== 'undefined' && window.location.hash === '#recibir' && (order.status === 'ORDERED' || order.status === 'PARTIAL')
    )
    const [receiveLocationId, setReceiveLocationId] = useState(order.inventory_location_id || locations[0]?.id || '')
    const [receiveCarrier, setReceiveCarrier] = useState('')
    const [receiveTracking, setReceiveTracking] = useState('')
    const [receiveNotes, setReceiveNotes] = useState('')
    const [receivingItems, setReceivingItems] = useState(
        (order.items || []).map((item) => ({
            item_id: item.id,
            sku: item.product?.sku || item.id,
            name: item.product?.name || '',
            pending: Math.max(0, item.quantity_ordered - item.quantity_received),
            quantity_received: Math.max(0, item.quantity_ordered - item.quantity_received),
        }))
    )
    const [receiveSubmitting, setReceiveSubmitting] = useState(false)

    const [cancelModalOpen, setCancelModalOpen] = useState(false)
    const [cancelReason, setCancelReason] = useState('')
    const [cancelSubmitting, setCancelSubmitting] = useState(false)

    const percentReceived = order.total_units_ordered > 0
        ? Math.min(100, Math.round((order.total_units_received / order.total_units_ordered) * 100))
        : 0

    // Handle "Mark as Ordered"
    const handleMarkOrdered = (e) => {
        e.preventDefault()
        setOrderingSubmitting(true)
        router.post(
            `/compras/ordenes/${order.id}/ordenar`,
            {
                supplier_quote_reference: orderQuoteRef,
                expected_delivery_date: orderDeliveryDate,
            },
            {
                onFinish: () => {
                    setOrderingSubmitting(false)
                    setOrderModalOpen(false)
                },
            }
        )
    }

    // Handle "Receive Items"
    const handleReceiveSubmit = (e) => {
        e.preventDefault()
        setReceiveSubmitting(true)

        const payloadItems = receivingItems
            .filter((item) => parseInt(item.quantity_received) > 0)
            .map((item) => ({
                item_id: item.item_id,
                quantity_received: parseInt(item.quantity_received),
            }))

        if (payloadItems.length === 0) {
            alert('Debe ingresar al menos una cantidad mayor a 0 para recibir.')
            setReceiveSubmitting(false)
            return
        }

        router.post(
            `/compras/ordenes/${order.id}/recibir`,
            {
                inventory_location_id: receiveLocationId,
                carrier: receiveCarrier,
                tracking_number: receiveTracking,
                notes: receiveNotes,
                items: payloadItems,
            },
            {
                onFinish: () => {
                    setReceiveSubmitting(false)
                    setReceiveModalOpen(false)
                },
            }
        )
    }

    // Handle "Cancel Order"
    const handleCancelSubmit = (e) => {
        e.preventDefault()
        if (!cancelReason.trim()) return

        setCancelSubmitting(true)
        router.post(
            `/compras/ordenes/${order.id}/cancelar`,
            { reason: cancelReason },
            {
                onFinish: () => {
                    setCancelSubmitting(false)
                    setCancelModalOpen(false)
                },
            }
        )
    }

    const printOrder = () => {
        window.print()
    }

    return (
        <AppShell title={`Orden de Compra ${order.order_number}`}>
            <Head title={`Orden ${order.order_number} - ${order.supplier_name}`} />

            <div className="space-y-6">
                {/* Top Action Bar (hidden when printing) */}
                <div className="print:hidden flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <Link href="/compras/ordenes" className="hover:text-indigo-600 dark:hover:text-indigo-400">
                                Órdenes de Compra
                            </Link>
                            <span>/</span>
                            <span className="font-semibold text-slate-800 dark:text-slate-200">
                                {order.order_number}
                            </span>
                        </div>
                        <div className="mt-1 flex items-center gap-3">
                            <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                                {order.order_number}
                            </h1>
                            <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-bold ${badge.bg}`}>
                                {badge.label}
                            </span>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2.5">
                        <button
                            type="button"
                            onClick={printOrder}
                            className="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200 dark:hover:bg-neutral-700"
                        >
                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                            </svg>
                            Imprimir Formato PO
                        </button>

                        {order.status === 'DRAFT' && (
                            <>
                                <button
                                    type="button"
                                    onClick={() => setOrderModalOpen(true)}
                                    className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700"
                                >
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                    </svg>
                                    Colocar Pedido a Proveedor
                                </button>

                                <button
                                    type="button"
                                    onClick={() => setCancelModalOpen(true)}
                                    className="inline-flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2 text-sm font-medium text-rose-700 transition hover:bg-rose-100 dark:border-rose-900/50 dark:bg-rose-950/20 dark:text-rose-400"
                                >
                                    Cancelar Orden
                                </button>
                            </>
                        )}

                        {(order.status === 'ORDERED' || order.status === 'PARTIAL') && (
                            <>
                                <button
                                    type="button"
                                    onClick={() => setReceiveModalOpen(true)}
                                    className="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700"
                                >
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                                    </svg>
                                    Recibir Mercancía en Almacén
                                </button>

                                {order.total_units_received === 0 && (
                                    <button
                                        type="button"
                                        onClick={() => setCancelModalOpen(true)}
                                        className="rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2 text-sm font-medium text-rose-700 hover:bg-rose-100 dark:border-rose-900/50 dark:bg-rose-950/20 dark:text-rose-400"
                                    >
                                        Cancelar
                                    </button>
                                )}
                            </>
                        )}
                    </div>
                </div>

                {/* Progress Summary Header */}
                <div className="print:hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Proveedor</p>
                            <p className="mt-1 text-base font-bold text-slate-900 dark:text-white">{order.supplier_name}</p>
                            {order.brand && <p className="text-xs text-indigo-600 dark:text-indigo-400 font-medium">Marca: {order.brand}</p>}
                        </div>

                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Almacén Destino</p>
                            <p className="mt-1 text-base font-bold text-slate-900 dark:text-white">
                                {order.location ? order.location.name : 'Sin asignar'}
                            </p>
                            <p className="text-xs text-slate-500">Cód: {order.location?.code}</p>
                        </div>

                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Progreso de Entrada</p>
                            <div className="mt-1 flex items-baseline gap-2">
                                <span className="text-xl font-bold text-slate-900 dark:text-white">
                                    {order.total_units_received} / {order.total_units_ordered}
                                </span>
                                <span className="text-xs font-semibold text-slate-500">unidades ({percentReceived}%)</span>
                            </div>
                            <div className="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-neutral-800">
                                <div
                                    className={`h-full ${percentReceived === 100 ? 'bg-emerald-500' : percentReceived > 0 ? 'bg-amber-500' : 'bg-slate-300'}`}
                                    style={{ width: `${percentReceived}%` }}
                                />
                            </div>
                        </div>

                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Inversión Total</p>
                            <p className="mt-1 text-xl font-bold text-indigo-600 dark:text-indigo-400">
                                {formatCurrency(order.total_cost)}
                            </p>
                            <p className="text-xs text-slate-500">
                                Fecha esperada: {formatDate(order.expected_delivery_date)}
                            </p>
                        </div>
                    </div>
                </div>

                {/* Formal Printable Purchase Order Sheet */}
                <div className="rounded-2xl border border-slate-200 bg-white p-8 shadow-sm print:border-none print:shadow-none print:p-0 dark:border-neutral-800 dark:bg-neutral-900">
                    {/* Header info */}
                    <div className="border-b border-slate-200 pb-6 dark:border-neutral-800">
                        <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                            <div>
                                <div className="flex items-center gap-3">
                                    <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-900 text-white font-bold">
                                        LL
                                    </div>
                                    <div>
                                        <h2 className="text-xl font-extrabold text-slate-900 dark:text-white">
                                            LLANTAS Y RINES DE SONORA
                                        </h2>
                                        <p className="text-xs text-slate-500 dark:text-slate-400">
                                            RFC: LRS-120304-XYZ • Hermosillo, Sonora, México
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div className="text-right">
                                <h3 className="text-2xl font-black tracking-tight text-slate-900 dark:text-white">
                                    ORDEN DE COMPRA
                                </h3>
                                <p className="text-sm font-bold text-indigo-600 dark:text-indigo-400">
                                    {order.order_number}
                                </p>
                                <p className="mt-1 text-xs text-slate-500">
                                    Fecha emisión: {formatDate(order.ordered_at || order.created_at)}
                                </p>
                            </div>
                        </div>

                        {/* Supplier & Delivery info grid */}
                        <div className="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <div className="rounded-xl border border-slate-200 bg-slate-50/50 p-4 dark:border-neutral-800 dark:bg-neutral-950/40">
                                <p className="text-xs font-bold uppercase tracking-wider text-slate-500">Datos del Proveedor</p>
                                <p className="mt-1 text-base font-bold text-slate-900 dark:text-white">{order.supplier_name}</p>
                                {order.brand && (
                                    <p className="text-xs font-semibold text-indigo-600 dark:text-indigo-400">
                                        Línea / Marca: {order.brand}
                                    </p>
                                )}
                                {order.supplier_quote_reference && (
                                    <p className="mt-1 text-xs text-slate-600 dark:text-slate-300">
                                        <span className="font-semibold">Cotización Proveedor:</span> {order.supplier_quote_reference}
                                    </p>
                                )}
                            </div>

                            <div className="rounded-xl border border-slate-200 bg-slate-50/50 p-4 dark:border-neutral-800 dark:bg-neutral-950/40">
                                <p className="text-xs font-bold uppercase tracking-wider text-slate-500">Lugar de Entrega / Almacén</p>
                                <p className="mt-1 text-base font-bold text-slate-900 dark:text-white">
                                    {order.location ? `${order.location.name} (${order.location.code})` : 'Almacén General SBS'}
                                </p>
                                <p className="text-xs text-slate-600 dark:text-slate-300">
                                    <span className="font-semibold">Fecha estimada recepción:</span> {formatDate(order.expected_delivery_date)}
                                </p>
                                {order.buyer && (
                                    <p className="mt-1 text-xs text-slate-500">
                                        Comprador: {order.buyer.name} ({order.buyer.email})
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Items table */}
                    <div className="mt-6 overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50/75 text-xs font-bold uppercase tracking-wider text-slate-700 dark:border-neutral-800 dark:bg-neutral-950 dark:text-slate-300">
                                <tr>
                                    <th className="px-3 py-3">#</th>
                                    <th className="px-3 py-3">SKU</th>
                                    <th className="px-3 py-3">Descripción / Producto</th>
                                    <th className="px-3 py-3 text-center">Pedidas</th>
                                    <th className="px-3 py-3 text-center">Recibidas</th>
                                    <th className="px-3 py-3 text-center">Saldo</th>
                                    <th className="px-3 py-3 text-right">Costo Unit.</th>
                                    <th className="px-3 py-3 text-right">Importe</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                {(order.items || []).map((item, idx) => {
                                    const pending = Math.max(0, item.quantity_ordered - item.quantity_received)
                                    return (
                                        <tr key={item.id} className="hover:bg-slate-50/30 dark:hover:bg-neutral-800/30">
                                            <td className="px-3 py-3 text-xs text-slate-400">{idx + 1}</td>
                                            <td className="px-3 py-3 font-bold text-slate-900 dark:text-white">
                                                {item.product?.sku || item.sku || '-'}
                                            </td>
                                            <td className="px-3 py-3 text-slate-800 dark:text-slate-200">
                                                <p className="font-medium">{item.product?.name || item.name || '-'}</p>
                                                {item.product?.brand && (
                                                    <span className="text-xs text-slate-400">Marca: {item.product.brand}</span>
                                                )}
                                            </td>
                                            <td className="px-3 py-3 text-center font-bold text-slate-900 dark:text-white">
                                                {item.quantity_ordered}
                                            </td>
                                            <td className="px-3 py-3 text-center font-semibold text-emerald-600 dark:text-emerald-400">
                                                {item.quantity_received}
                                            </td>
                                            <td className="px-3 py-3 text-center font-semibold text-amber-600 dark:text-amber-400">
                                                {pending}
                                            </td>
                                            <td className="px-3 py-3 text-right font-medium text-slate-900 dark:text-white">
                                                {formatCurrency(item.unit_cost)}
                                            </td>
                                            <td className="px-3 py-3 text-right font-bold text-slate-900 dark:text-white">
                                                {formatCurrency(item.total_cost)}
                                            </td>
                                        </tr>
                                    )
                                })}
                            </tbody>
                        </table>
                    </div>

                    {/* Financial summary & Notes */}
                    <div className="mt-6 flex flex-col justify-between gap-6 border-t border-slate-200 pt-6 sm:flex-row dark:border-neutral-800">
                        <div className="flex-1">
                            <h4 className="text-xs font-bold uppercase tracking-wider text-slate-500">Notas / Términos de Entrega</h4>
                            <p className="mt-1 text-sm text-slate-700 dark:text-slate-300">
                                {order.notes || 'Sin observaciones adicionales registradas.'}
                            </p>
                            {order.cancelled_at && (
                                <div className="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-300">
                                    <p className="font-bold">Orden Cancelada:</p>
                                    <p>{order.cancellation_reason}</p>
                                    <p className="mt-1 text-slate-500">Por: {order.cancelled_by?.name} en {formatDateTime(order.cancelled_at)}</p>
                                </div>
                            )}
                        </div>

                        <div className="w-full sm:w-72 space-y-2 text-sm">
                            <div className="flex justify-between text-slate-600 dark:text-slate-400">
                                <span>Subtotal:</span>
                                <span className="font-semibold text-slate-900 dark:text-white">{formatCurrency(order.subtotal)}</span>
                            </div>
                            <div className="flex justify-between text-slate-600 dark:text-slate-400">
                                <span>Flete / Envío:</span>
                                <span className="font-semibold text-slate-900 dark:text-white">{formatCurrency(order.shipping_cost)}</span>
                            </div>
                            <div className="flex justify-between text-slate-600 dark:text-slate-400">
                                <span>Impuestos / IVA:</span>
                                <span className="font-semibold text-slate-900 dark:text-white">{formatCurrency(order.tax_amount)}</span>
                            </div>
                            <div className="border-t border-slate-200 pt-2 flex justify-between text-base font-bold text-slate-900 dark:border-neutral-700 dark:text-white">
                                <span>Total Orden:</span>
                                <span className="text-lg text-indigo-600 dark:text-indigo-400">{formatCurrency(order.total_cost)}</span>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Physical Warehouse Receptions History (print:hidden) */}
                <div className="print:hidden rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex items-center justify-between">
                        <div>
                            <h3 className="text-base font-bold text-slate-900 dark:text-white">
                                Historial de Recepciones Físicas en Almacén ({order.receipts?.length || 0})
                            </h3>
                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                Cada recepción genera automáticamente un movimiento de entrada física comprobable en el kardex.
                            </p>
                        </div>

                        {(order.status === 'ORDERED' || order.status === 'PARTIAL') && (
                            <button
                                type="button"
                                onClick={() => setReceiveModalOpen(true)}
                                className="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-emerald-700"
                            >
                                + Nueva Recepción
                            </button>
                        )}
                    </div>

                    <div className="mt-4 space-y-4">
                        {!order.receipts || order.receipts.length === 0 ? (
                            <p className="py-6 text-center text-xs text-slate-400">
                                Aún no se han registrado ingresos físicos para esta orden de compra.
                            </p>
                        ) : (
                            order.receipts.map((rcpt) => (
                                <div
                                    key={rcpt.id}
                                    className="rounded-xl border border-slate-200 bg-slate-50/50 p-4 dark:border-neutral-800 dark:bg-neutral-950/40"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200/80 pb-3 dark:border-neutral-800">
                                        <div className="flex items-center gap-3">
                                            <span className="font-mono text-sm font-bold text-emerald-700 dark:text-emerald-400">
                                                {rcpt.receipt_number}
                                            </span>
                                            <span className="text-xs text-slate-500">
                                                {formatDateTime(rcpt.received_at)}
                                            </span>
                                            <span className="text-xs font-medium text-slate-600 dark:text-slate-400">
                                                Recibió: {rcpt.received_by?.name || 'Sistema'}
                                            </span>
                                        </div>

                                        <div className="flex items-center gap-3 text-xs">
                                            {rcpt.carrier && (
                                                <span className="rounded bg-slate-200/70 px-2 py-0.5 font-medium text-slate-700 dark:bg-neutral-800 dark:text-slate-300">
                                                    Transporte: {rcpt.carrier} {rcpt.tracking_number && `(${rcpt.tracking_number})`}
                                                </span>
                                            )}
                                            <span className="font-bold text-slate-900 dark:text-white">
                                                {rcpt.total_units_received} unidades ingresadas
                                            </span>
                                        </div>
                                    </div>

                                    {/* Items in this receipt */}
                                    <div className="mt-3">
                                        <table className="w-full text-left text-xs">
                                            <thead>
                                                <tr className="text-slate-500">
                                                    <th className="py-1">SKU</th>
                                                    <th className="py-1">Producto</th>
                                                    <th className="py-1 text-right">Unidades Recibidas</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-900">
                                                {(rcpt.items || []).map((ri) => (
                                                    <tr key={ri.id}>
                                                        <td className="py-1.5 font-mono font-bold text-slate-900 dark:text-white">
                                                            {ri.product?.sku || '-'}
                                                        </td>
                                                        <td className="py-1.5 text-slate-700 dark:text-slate-300">
                                                            {ri.product?.name || '-'}
                                                        </td>
                                                        <td className="py-1.5 text-right font-bold text-emerald-600 dark:text-emerald-400">
                                                            +{ri.quantity_received}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>

                                    {rcpt.notes && (
                                        <p className="mt-2 text-xs italic text-slate-500">
                                            Nota: {rcpt.notes}
                                        </p>
                                    )}
                                </div>
                            ))
                        )}
                    </div>
                </div>
            </div>

            {/* Modal: Mark as Ordered */}
            {orderModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                        <h3 className="text-lg font-bold text-slate-900 dark:text-white">
                            Colocar Orden al Proveedor
                        </h3>
                        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Cambia el estado de la orden a "Colocada" (en camino).
                        </p>

                        <form onSubmit={handleMarkOrdered} className="mt-4 space-y-4">
                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                    No. de Cotización / Confirmación del Proveedor
                                </label>
                                <input
                                    type="text"
                                    value={orderQuoteRef}
                                    onChange={(e) => setOrderQuoteRef(e.target.value)}
                                    placeholder="Ej. COT-88123 / PO-MICHELIN-102"
                                    className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm text-slate-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                    Fecha Estimada de Llegada al Almacén
                                </label>
                                <input
                                    type="date"
                                    value={orderDeliveryDate}
                                    onChange={(e) => setOrderDeliveryDate(e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm text-slate-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                />
                            </div>

                            <div className="mt-6 flex justify-end gap-3">
                                <button
                                    type="button"
                                    onClick={() => setOrderModalOpen(false)}
                                    className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    disabled={orderingSubmitting}
                                    className="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                                >
                                    {orderingSubmitting ? 'Enviando...' : 'Confirmar Orden Colocada'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Modal: Receive Merchandise */}
            {receiveModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                    <div className="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                        <div className="flex items-center justify-between border-b border-slate-200 pb-3 dark:border-neutral-800">
                            <div>
                                <h3 className="text-lg font-bold text-slate-900 dark:text-white">
                                    Recepción Física de Mercancía en Almacén
                                </h3>
                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                    Ingrese las cantidades que llegaron físicamente en este embarque.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setReceiveModalOpen(false)}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <form onSubmit={handleReceiveSubmit} className="mt-4 space-y-4">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div>
                                    <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                        Almacén Receptor
                                    </label>
                                    <select
                                        value={receiveLocationId}
                                        onChange={(e) => setReceiveLocationId(e.target.value)}
                                        className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-1.5 text-sm text-slate-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                    >
                                        {locations.map((loc) => (
                                            <option key={loc.id} value={loc.id}>
                                                {loc.name} ({loc.code})
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                        Línea de Transporte
                                    </label>
                                    <input
                                        type="text"
                                        value={receiveCarrier}
                                        onChange={(e) => setReceiveCarrier(e.target.value)}
                                        placeholder="Ej. Castores, Paquetexpress..."
                                        className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-1.5 text-sm text-slate-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                        Número de Guía / Rastreo
                                    </label>
                                    <input
                                        type="text"
                                        value={receiveTracking}
                                        onChange={(e) => setReceiveTracking(e.target.value)}
                                        placeholder="Ej. 1198273645"
                                        className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-1.5 text-sm text-slate-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                    />
                                </div>
                            </div>

                            {/* Items table for reception */}
                            <div className="mt-4">
                                <div className="flex items-center justify-between mb-2">
                                    <span className="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                        Cantidades Recibidas
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setReceivingItems(
                                                receivingItems.map((item) => ({
                                                    ...item,
                                                    quantity_received: item.pending,
                                                }))
                                            )
                                        }}
                                        className="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                                    >
                                        Recibir todo el saldo pendiente
                                    </button>
                                </div>

                                <div className="max-h-60 overflow-y-auto rounded-xl border border-slate-200 dark:border-neutral-800">
                                    <table className="w-full text-left text-xs">
                                        <thead className="bg-slate-50 text-slate-600 dark:bg-neutral-950 dark:text-slate-400">
                                            <tr>
                                                <th className="px-3 py-2">SKU</th>
                                                <th className="px-3 py-2">Producto</th>
                                                <th className="px-3 py-2 text-center">Pendiente</th>
                                                <th className="px-3 py-2 text-center w-28">A Recibir Hoy</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                            {receivingItems.map((item, idx) => (
                                                <tr key={item.item_id}>
                                                    <td className="px-3 py-2 font-mono font-bold text-slate-900 dark:text-white">
                                                        {item.sku}
                                                    </td>
                                                    <td className="px-3 py-2 text-slate-700 dark:text-slate-300">
                                                        {item.name}
                                                    </td>
                                                    <td className="px-3 py-2 text-center font-bold text-amber-600">
                                                        {item.pending}
                                                    </td>
                                                    <td className="px-3 py-2 text-center">
                                                        <input
                                                            type="number"
                                                            min={0}
                                                            max={item.pending}
                                                            value={item.quantity_received}
                                                            onChange={(e) => {
                                                                const val = Math.max(0, parseInt(e.target.value) || 0)
                                                                const updated = [...receivingItems]
                                                                updated[idx].quantity_received = val
                                                                setReceivingItems(updated)
                                                            }}
                                                            className="w-20 rounded-lg border border-slate-300 px-2 py-1 text-center font-bold text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                                        />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                    Notas de Recepción (Estado del producto, condiciones del empaque, etc.)
                                </label>
                                <textarea
                                    rows={2}
                                    value={receiveNotes}
                                    onChange={(e) => setReceiveNotes(e.target.value)}
                                    placeholder="Comentarios adicionales sobre la descarga..."
                                    className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm text-slate-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                />
                            </div>

                            <div className="mt-6 flex justify-end gap-3 border-t border-slate-200 pt-4 dark:border-neutral-800">
                                <button
                                    type="button"
                                    onClick={() => setReceiveModalOpen(false)}
                                    className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    disabled={receiveSubmitting}
                                    className="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50"
                                >
                                    {receiveSubmitting ? 'Procesando Entrada...' : 'Confirmar e Ingresar a Almacén'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Modal: Cancel Order */}
            {cancelModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                        <h3 className="text-lg font-bold text-rose-600 dark:text-rose-400">
                            Cancelar Orden de Compra
                        </h3>
                        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Esta acción cancelará la orden {order.order_number}. Indique el motivo.
                        </p>

                        <form onSubmit={handleCancelSubmit} className="mt-4 space-y-4">
                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                    Motivo de Cancelación *
                                </label>
                                <textarea
                                    required
                                    rows={3}
                                    value={cancelReason}
                                    onChange={(e) => setCancelReason(e.target.value)}
                                    placeholder="Ej. Falta de stock con proveedor, cancelación de común acuerdo..."
                                    className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm text-slate-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                />
                            </div>

                            <div className="mt-6 flex justify-end gap-3">
                                <button
                                    type="button"
                                    onClick={() => setCancelModalOpen(false)}
                                    className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800"
                                >
                                    Regresar
                                </button>
                                <button
                                    type="submit"
                                    disabled={cancelSubmitting || !cancelReason.trim()}
                                    className="rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 disabled:opacity-50"
                                >
                                    {cancelSubmitting ? 'Cancelando...' : 'Confirmar Cancelación'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AppShell>
    )
}
