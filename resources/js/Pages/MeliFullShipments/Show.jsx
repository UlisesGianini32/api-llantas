import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

function formatDate(dateStr) {
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

function formatCurrency(val) {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    }).format(val || 0)
}

const STATUS_CONFIG = {
    DRAFT: {
        label: 'Borrador / Preparación',
        bg: 'bg-slate-100 text-slate-700 border-slate-300 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700',
        desc: 'Cajas en preparación. Aún no se descuentan del almacén local.',
    },
    PACKED: {
        label: 'Cajas Selladas',
        bg: 'bg-indigo-100 text-indigo-800 border-indigo-300 dark:bg-indigo-900/40 dark:text-indigo-300 dark:border-indigo-700',
        desc: 'Cajas de 30 unidades empacadas y rotuladas. Listo para despacho con ENVIA.',
    },
    IN_TRANSIT: {
        label: 'En Tránsito (ENVIA)',
        bg: 'bg-blue-100 text-blue-800 border-blue-300 dark:bg-blue-900/40 dark:text-blue-300 dark:border-blue-700',
        desc: 'En camino hacia el CEDIS MeLi. La salida física del almacén ya fue registrada.',
    },
    DELIVERED: {
        label: 'Entregado en CEDIS MeLi',
        bg: 'bg-purple-100 text-purple-800 border-purple-300 dark:bg-purple-900/40 dark:text-purple-300 dark:border-purple-700',
        desc: 'Entregado por paquetería en la bodega MeLi. En espera de conteo/check-in.',
    },
    RECEIVED: {
        label: 'Recibido Completo',
        bg: 'bg-emerald-100 text-emerald-800 border-emerald-300 dark:bg-emerald-900/40 dark:text-emerald-300 dark:border-emerald-700',
        desc: 'Inspección completada por MeLi sin incidencias. Unidades disponibles para venta.',
    },
    DISCREPANCY: {
        label: 'Con Discrepancias / Faltantes',
        bg: 'bg-amber-100 text-amber-800 border-amber-300 dark:bg-amber-900/40 dark:text-amber-300 dark:border-amber-700',
        desc: 'Se reportaron unidades dañadas o faltantes durante la recepción en CEDIS MeLi.',
    },
    CANCELLED: {
        label: 'Cancelado',
        bg: 'bg-rose-100 text-rose-800 border-rose-300 dark:bg-rose-900/40 dark:text-rose-300 dark:border-rose-700',
        desc: 'Envío cancelado.',
    },
}

export default function MeliFullShipmentsShow({
    shipment = {},
    warehouses = {},
    carriers = [],
}) {
    const [dispatchModalOpen, setDispatchModalOpen] = useState(false)
    const [dispatching, setDispatching] = useState(false)

    const [revertModalOpen, setRevertModalOpen] = useState(false)
    const [reverting, setReverting] = useState(false)

    const [receiveModalOpen, setReceiveModalOpen] = useState(false)
    const [receptions, setReceptions] = useState(
        (shipment.items || []).map((it) => ({
            item_id: it.id,
            sku: it.sku,
            product_name: it.product_name,
            quantity_sent: it.quantity_sent,
            quantity_received: it.quantity_received || it.quantity_sent,
            quantity_damaged: it.quantity_damaged || 0,
            quantity_missing: it.quantity_missing || 0,
            notes: it.notes || '',
        }))
    )
    const [receiveNotes, setReceiveNotes] = useState('')
    const [receiving, setReceiving] = useState(false)

    const statusInfo = STATUS_CONFIG[shipment.status] || STATUS_CONFIG.DRAFT

    // Confirm and execute dispatch
    const handleDispatchConfirm = () => {
        setDispatching(true)
        router.post(
            `/meli/full/envios/${shipment.id}/despachar`,
            {},
            {
                onSuccess: () => {
                    setDispatchModalOpen(false)
                    setDispatching(false)
                },
                onError: () => {
                    setDispatching(false)
                },
            }
        )
    }

    // Confirm and execute revert
    const handleRevertConfirm = () => {
        setReverting(true)
        router.post(
            `/meli/full/envios/${shipment.id}/revertir`,
            {},
            {
                onSuccess: () => {
                    setRevertModalOpen(false)
                    setReverting(false)
                },
                onError: () => {
                    setReverting(false)
                },
            }
        )
    }

    // Update reception counts
    const updateReception = (index, field, value) => {
        const updated = [...receptions]
        const val = parseInt(value, 10) || 0
        updated[index][field] = val

        // Auto-calculate missing
        if (field === 'quantity_received' || field === 'quantity_damaged') {
            const sent = updated[index].quantity_sent
            const rec = field === 'quantity_received' ? val : updated[index].quantity_received
            const dam = field === 'quantity_damaged' ? val : updated[index].quantity_damaged
            updated[index].quantity_missing = Math.max(0, sent - (rec + dam))
        }

        setReceptions(updated)
    }

    // Submit MeLi reception
    const handleReceiveSubmit = (e) => {
        e.preventDefault()
        setReceiving(true)
        router.post(
            `/meli/full/envios/${shipment.id}/recibir`,
            {
                receptions: receptions.map((r) => ({
                    item_id: r.item_id,
                    quantity_received: r.quantity_received,
                    quantity_damaged: r.quantity_damaged,
                    quantity_missing: r.quantity_missing,
                    notes: r.notes,
                })),
                notes: receiveNotes,
            },
            {
                onSuccess: () => {
                    setReceiveModalOpen(false)
                    setReceiving(false)
                },
                onError: () => {
                    setReceiving(false)
                },
            }
        )
    }

    return (
        <AppShell>
            <Head title={`Envío ${shipment.shipment_code} - Mercado Libre FULL`} />

            <div className="space-y-6 p-4 sm:p-6 lg:p-8">
                {/* BREADCRUMB & TOP CONTROLS */}
                <div className="flex flex-col gap-2">
                    <div className="flex items-center gap-2 text-xs text-slate-500">
                        <Link href="/meli/full/envios" className="hover:underline">
                            Envíos FULL
                        </Link>
                        <span>/</span>
                        <span className="font-mono font-bold text-slate-800 dark:text-slate-200">
                            {shipment.shipment_code}
                        </span>
                    </div>

                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div className="flex items-center gap-3">
                                <h1 className="text-2xl font-black text-slate-900 dark:text-white font-mono">
                                    {shipment.shipment_code}
                                </h1>
                                <span
                                    className={`rounded-full border px-3 py-0.5 text-xs font-black ${statusInfo.bg}`}
                                >
                                    {statusInfo.label}
                                </span>
                            </div>
                            <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                {statusInfo.desc}
                            </p>
                        </div>

                        {/* ACTIONS BAR */}
                        <div className="flex flex-wrap items-center gap-2">
                            <a
                                href={`/meli/full/envios/${shipment.id}/rotulos`}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                            >
                                <span>📦</span> Imprimir Rótulos de Cajas MeLi
                            </a>

                            <a
                                href={`/meli/full/envios/${shipment.id}/etiquetas-productos`}
                                target="_blank"
                                rel="noreferrer"
                                className="inline-flex items-center gap-1.5 rounded-xl border border-indigo-200 bg-indigo-50 px-3.5 py-2 text-xs font-bold text-indigo-700 hover:bg-indigo-100 dark:border-indigo-900 dark:bg-indigo-950 dark:text-indigo-300"
                            >
                                <span>🏷️</span> Imprimir Stickers de Producto
                            </a>

                            {/* DISPATCH ACTION (TRANSFER_OUT) */}
                            {['DRAFT', 'PACKED'].includes(shipment.status) && (
                                <button
                                    type="button"
                                    onClick={() => setDispatchModalOpen(true)}
                                    className="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-emerald-700"
                                >
                                    <span>🚚</span> Despachar Envío (Salida Almacén)
                                </button>
                            )}

                            {/* REVERT DISPATCH ACTION */}
                            {['IN_TRANSIT'].includes(shipment.status) && (
                                <button
                                    type="button"
                                    onClick={() => setRevertModalOpen(true)}
                                    className="inline-flex items-center gap-1.5 rounded-xl border border-amber-300 bg-amber-50 px-3.5 py-2 text-xs font-bold text-amber-800 hover:bg-amber-100 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300"
                                >
                                    <span>↩️</span> Revertir Salida
                                </button>
                            )}

                            {/* RECEIVE AT MELI ACTION */}
                            {['IN_TRANSIT', 'DELIVERED', 'DISCREPANCY'].includes(shipment.status) && (
                                <button
                                    type="button"
                                    onClick={() => setReceiveModalOpen(true)}
                                    className="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-indigo-700"
                                >
                                    <span>📥</span> Registrar Recepción MeLi (Inspección)
                                </button>
                            )}
                        </div>
                    </div>
                </div>

                {/* SHIPMENT SUMMARY CARDS */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {/* WAREHOUSE DESTINATION */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-slate-500">
                            🏢 Bodega MeLi Destino
                        </span>
                        <div className="mt-1 flex items-baseline gap-2">
                            <span className="rounded-lg bg-amber-100 px-2 py-0.5 font-mono text-base font-black text-amber-900 dark:bg-amber-950 dark:text-amber-200">
                                {shipment.meli_warehouse_code}
                            </span>
                        </div>
                        <p className="mt-1 text-xs text-slate-700 dark:text-slate-300 font-semibold">
                            {shipment.meli_warehouse_name}
                        </p>
                        {shipment.meli_shipment_id && (
                            <p className="mt-0.5 text-[11px] text-slate-500">
                                Cita MeLi: <strong>#{shipment.meli_shipment_id}</strong>
                            </p>
                        )}
                    </div>

                    {/* ENVIA LOGISTICS */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-slate-500">
                            🚚 Logística & Guía ENVIA
                        </span>
                        <div className="mt-1 flex items-center gap-2">
                            <span className="rounded bg-blue-100 px-2 py-0.5 text-xs font-bold text-blue-800 dark:bg-blue-950 dark:text-blue-300">
                                {shipment.envia_carrier || 'ENVIA'}
                            </span>
                            <span className="font-mono text-sm font-bold text-slate-900 dark:text-white">
                                {shipment.envia_tracking_number || 'Sin guía'}
                            </span>
                        </div>
                        {shipment.envia_tracking_url ? (
                            <a
                                href={shipment.envia_tracking_url}
                                target="_blank"
                                rel="noreferrer"
                                className="mt-1.5 inline-flex items-center gap-1 text-xs font-bold text-blue-600 hover:underline dark:text-blue-400"
                            >
                                <span>🔗</span> Rastrear en ENVIA
                            </a>
                        ) : (
                            <p className="mt-1 text-[11px] text-slate-400 italic">Sin enlace de rastreo</p>
                        )}
                        {shipment.envia_cost > 0 && (
                            <p className="mt-0.5 text-[11px] text-slate-500">
                                Costo envío: <strong>{formatCurrency(shipment.envia_cost)}</strong>
                            </p>
                        )}
                    </div>

                    {/* BOXES, BULTOS & WEIGHT */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-slate-500">
                            📦 Bultos, Cajas & Peso Total
                        </span>
                        <div className="mt-1 flex flex-wrap items-baseline gap-2 font-mono">
                            <span className="text-2xl font-black text-slate-900 dark:text-white">
                                {shipment.total_bultos || (shipment.boxes ? new Set(shipment.boxes.map(b => b.bulto_number || 1)).size : 1)}
                            </span>
                            <span className="text-xs text-slate-500 font-sans font-bold">
                                bultos ENVIA
                            </span>
                            <span className="text-slate-300">·</span>
                            <span className="text-2xl font-black text-slate-900 dark:text-white">
                                {shipment.total_boxes}
                            </span>
                            <span className="text-xs text-slate-500 font-sans font-bold">
                                cajas (30 kg)
                            </span>
                            <span className="text-slate-300">·</span>
                            <span className="text-2xl font-black text-indigo-600 dark:text-indigo-400">
                                {parseFloat(shipment.total_weight_kg || 0).toFixed(2)}
                            </span>
                            <span className="text-xs text-slate-500 font-sans font-bold">
                                kg
                            </span>
                        </div>
                        <p className="mt-1 text-[11px] text-slate-500 font-semibold">
                            Total piezas: <strong className="text-slate-800 dark:text-slate-200">{shipment.total_units} uds</strong>
                        </p>
                    </div>

                    {/* RECEPTION RESULTS */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-slate-500">
                            📊 Conteo de Recepción MeLi
                        </span>
                        {shipment.received_at ? (
                            <div className="mt-1 space-y-1 font-mono text-xs">
                                <div className="flex items-center justify-between text-emerald-600 dark:text-emerald-400 font-bold">
                                    <span>✓ Conformes:</span>
                                    <span>{shipment.total_units_received} uds</span>
                                </div>
                                <div className="flex items-center justify-between text-rose-600 dark:text-rose-400 font-bold">
                                    <span>⚠️ Dañadas:</span>
                                    <span>{shipment.total_units_damaged} uds</span>
                                </div>
                                <div className="flex items-center justify-between text-amber-600 dark:text-amber-400 font-bold">
                                    <span>❌ Faltantes:</span>
                                    <span>{shipment.total_units_missing} uds</span>
                                </div>
                            </div>
                        ) : (
                            <div className="mt-2 text-xs text-slate-400 italic">
                                Pendiente de recepción e inspección por el CEDIS MeLi.
                            </div>
                        )}
                    </div>
                </div>

                {/* NOTES & DATES TIMELINE */}
                <div className="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 dark:border-neutral-800 dark:bg-neutral-900/60">
                    <div className="flex flex-wrap items-center justify-between gap-4 text-xs">
                        <div className="flex flex-wrap items-center gap-4 text-slate-600 dark:text-slate-400">
                            <span>📅 Creado: <strong>{formatDate(shipment.created_at)}</strong></span>
                            {shipment.shipped_at && (
                                <span>🚚 Despachado: <strong>{formatDate(shipment.shipped_at)}</strong></span>
                            )}
                            {shipment.received_at && (
                                <span>📥 Recibido MeLi: <strong>{formatDate(shipment.received_at)}</strong></span>
                            )}
                            {shipment.user && (
                                <span>👤 Creado por: <strong>{shipment.user.name}</strong></span>
                            )}
                        </div>

                        {shipment.notes && (
                            <div className="text-slate-700 dark:text-slate-300">
                                <strong>Notas:</strong> {shipment.notes}
                            </div>
                        )}
                    </div>
                </div>

                {/* BOXES DETAIL (EACH 30-KG BOX / BULTO) */}
                <div className="space-y-4">
                    <div className="flex items-center justify-between">
                        <h2 className="text-base font-black text-slate-900 dark:text-white flex items-center gap-2">
                            <span>📦</span> Desglose de Cajas de 30 kg y Bultos ({shipment.boxes?.length || 0} bultos / {shipment.total_boxes} cajas)
                        </h2>
                    </div>

                    <div className="space-y-4">
                        {(shipment.boxes || []).map((box) => {
                            const boxWeight = parseFloat(box.weight_kg) || 0
                            const boxCapacityKg = parseFloat(box.capacity_kg) || (box.boxes_in_bulto || 1) * 30.00
                            const isOverWeight = boxWeight > boxCapacityKg

                            return (
                                <div
                                    key={box.id}
                                    className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                                >
                                    {/* BOX BAR */}
                                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-slate-100 pb-3 dark:border-neutral-800">
                                        <div className="flex items-center gap-3">
                                            <div className="rounded-xl bg-slate-100 p-2 text-lg font-black text-slate-700 dark:bg-neutral-800 dark:text-slate-200">
                                                📦
                                            </div>
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <h3 className="text-sm font-black text-slate-900 dark:text-white">
                                                        CAJA #{box.box_number} {box.boxes_in_bulto > 1 ? `(${box.boxes_in_bulto} cajas de 30 kg)` : ''} · BULTO ENVIA #{box.bulto_number || 1}
                                                    </h3>
                                                    <span className="font-mono text-xs text-slate-400">
                                                        ({box.box_code})
                                                    </span>
                                                    <span
                                                        className={`rounded-full px-2 py-0.5 text-[10px] font-black ${
                                                            isOverWeight
                                                                ? 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300'
                                                                : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                                                        }`}
                                                    >
                                                        ⚖️ {boxWeight.toFixed(2)} / {boxCapacityKg.toFixed(2)} kg
                                                    </span>
                                                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600 dark:bg-neutral-800 dark:text-slate-300">
                                                        {box.units_count} piezas
                                                    </span>
                                                </div>
                                                <p className="text-[11px] text-slate-500 mt-0.5">
                                                    Medidas: {box.dimensions || '40x30x30 cm'} · Capacidad máx: {boxCapacityKg.toFixed(2)} kg ({box.boxes_in_bulto || 1} caja(s) de 30 kg)
                                                </p>
                                            </div>
                                        </div>

                                        <a
                                            href={`/meli/full/envios/${shipment.id}/cajas/${box.id}/rotulo`}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="inline-flex items-center gap-1 rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-300"
                                        >
                                            <span>🏷️</span> Imprimir Rótulo de este Bulto
                                        </a>
                                    </div>

                                    {/* ITEMS IN THIS BOX */}
                                    <div className="mt-3 overflow-x-auto">
                                        <table className="w-full text-left text-xs">
                                            <thead className="text-[11px] font-bold uppercase text-slate-400">
                                                <tr>
                                                    <th className="py-2 pr-3">SKU</th>
                                                    <th className="py-2 pr-3">Producto</th>
                                                    <th className="py-2 pr-3 text-center">Enviadas</th>
                                                    <th className="py-2 pr-3 text-center">Peso Unit.</th>
                                                    <th className="py-2 pr-3 text-center">Peso Total</th>
                                                    {shipment.received_at && (
                                                        <>
                                                            <th className="py-2 pr-3 text-center text-emerald-600 dark:text-emerald-400">Recibidas Bien</th>
                                                            <th className="py-2 pr-3 text-center text-rose-600 dark:text-rose-400">Dañadas</th>
                                                            <th className="py-2 pr-3 text-center text-amber-600 dark:text-amber-400">Faltantes</th>
                                                        </>
                                                    )}
                                                    <th className="py-2 text-right">Notas</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100 font-medium dark:divide-neutral-800">
                                                {(box.items || []).map((item) => {
                                                    const unitW = parseFloat(item.unit_weight_kg) || 1.0
                                                    const totW = parseFloat(item.total_weight_kg) || (item.quantity_sent * unitW)

                                                    return (
                                                        <tr key={item.id} className="hover:bg-slate-50/50 dark:hover:bg-neutral-950/20">
                                                            <td className="py-2.5 pr-3 font-mono font-bold text-indigo-600 dark:text-indigo-400">
                                                                {item.sku}
                                                            </td>
                                                            <td className="py-2.5 pr-3 font-semibold text-slate-800 dark:text-slate-200">
                                                                <div className="flex items-center gap-1.5">
                                                                    <span>{item.product_name}</span>
                                                                    {item.requires_labeling && (
                                                                        <span className="rounded bg-indigo-100 px-1.5 py-0.5 text-[9px] font-black text-indigo-800 dark:bg-indigo-950 dark:text-indigo-300">
                                                                            🏷️ Sticker MeLi
                                                                        </span>
                                                                    )}
                                                                </div>
                                                                {item.inventoryProduct?.brand && (
                                                                    <span className="text-[10px] text-slate-400 block">
                                                                        {item.inventoryProduct.brand}
                                                                    </span>
                                                                )}
                                                            </td>
                                                            <td className="py-2.5 pr-3 text-center font-mono font-extrabold text-slate-900 dark:text-white">
                                                                {item.quantity_sent}
                                                            </td>
                                                            <td className="py-2.5 pr-3 text-center font-mono text-slate-500">
                                                                {unitW.toFixed(2)} kg
                                                            </td>
                                                            <td className="py-2.5 pr-3 text-center font-mono font-bold text-slate-800 dark:text-slate-200">
                                                                {totW.toFixed(2)} kg
                                                            </td>
                                                            {shipment.received_at && (
                                                                <>
                                                                    <td className="py-2.5 pr-3 text-center font-mono font-bold text-emerald-600 dark:text-emerald-400">
                                                                        {item.quantity_received}
                                                                    </td>
                                                                    <td className="py-2.5 pr-3 text-center font-mono font-bold text-rose-600 dark:text-rose-400">
                                                                        {item.quantity_damaged}
                                                                    </td>
                                                                    <td className="py-2.5 pr-3 text-center font-mono font-bold text-amber-600 dark:text-amber-400">
                                                                        {item.quantity_missing}
                                                                    </td>
                                                                </>
                                                            )}
                                                            <td className="py-2.5 text-right text-slate-400 text-[11px]">
                                                                {item.notes || '-'}
                                                            </td>
                                                        </tr>
                                                    )
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            )
                        })}
                    </div>
                </div>


                {/* MODAL: DISPATCH CONFIRMATION */}
                {dispatchModalOpen && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                        <div className="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl dark:bg-neutral-900 border border-slate-200 dark:border-neutral-800">
                            <h3 className="text-base font-black text-slate-900 dark:text-white flex items-center gap-2">
                                <span>🚚</span> Confirmar Salida y Despacho
                            </h3>
                            <p className="mt-2 text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                                Estás por marcar el envío <strong>{shipment.shipment_code}</strong> como <strong>EN TRÁNSITO</strong> hacia la bodega MeLi <strong>{shipment.meli_warehouse_code}</strong> con la guía ENVIA <strong>{shipment.envia_carrier} {shipment.envia_tracking_number}</strong>.
                            </p>
                            <div className="mt-3 rounded-2xl bg-amber-50 p-3 text-xs font-semibold text-amber-800 dark:bg-amber-950/40 dark:text-amber-300">
                                ⚠️ Se generará un movimiento de salida (<strong>TRANSFER_OUT</strong>) en el almacén local por un total de <strong>{shipment.total_units} unidades</strong> en {shipment.total_boxes} cajas.
                            </div>

                            <div className="mt-5 flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => setDispatchModalOpen(false)}
                                    className="rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="button"
                                    onClick={handleDispatchConfirm}
                                    disabled={dispatching}
                                    className="rounded-xl bg-emerald-600 px-5 py-2 text-xs font-bold text-white hover:bg-emerald-700 disabled:opacity-50"
                                >
                                    {dispatching ? 'Despachando...' : 'Confirmar Salida de Almacén'}
                                </button>
                            </div>
                        </div>
                    </div>
                )}

                {/* MODAL: REVERT CONFIRMATION */}
                {revertModalOpen && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                        <div className="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl dark:bg-neutral-900 border border-slate-200 dark:border-neutral-800">
                            <h3 className="text-base font-black text-rose-600 dark:text-rose-400 flex items-center gap-2">
                                <span>↩️</span> Revertir Salida de Almacén
                            </h3>
                            <p className="mt-2 text-xs text-slate-600 dark:text-slate-300 leading-relaxed">
                                ¿Deseas reintegrar las <strong>{shipment.total_units} piezas</strong> de vuelta al inventario físico local?
                            </p>
                            <div className="mt-3 rounded-2xl bg-slate-50 p-3 text-xs text-slate-600 dark:bg-neutral-800 dark:text-slate-300">
                                Se registrarán movimientos <strong>TRANSFER_IN</strong> cancelando la salida y el envío volverá a estado Borrador.
                            </div>

                            <div className="mt-5 flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => setRevertModalOpen(false)}
                                    className="rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="button"
                                    onClick={handleRevertConfirm}
                                    disabled={reverting}
                                    className="rounded-xl bg-rose-600 px-5 py-2 text-xs font-bold text-white hover:bg-rose-700 disabled:opacity-50"
                                >
                                    {reverting ? 'Reintegrando...' : 'Reintegrar Stock a Almacén'}
                                </button>
                            </div>
                        </div>
                    </div>
                )}

                {/* MODAL: RECEIVE INSPECTION AT MELI */}
                {receiveModalOpen && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                        <div className="w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-3xl bg-white p-6 shadow-2xl dark:bg-neutral-900 border border-slate-200 dark:border-neutral-800">
                            <div className="flex items-center justify-between border-b border-slate-100 pb-3 dark:border-neutral-800">
                                <div>
                                    <h3 className="text-base font-black text-slate-900 dark:text-white flex items-center gap-2">
                                        <span>📥</span> Inspección & Recepción en Bodega MeLi
                                    </h3>
                                    <p className="text-xs text-slate-500">
                                        Registra las unidades que MeLi ingresó a stock disponible, piezas dañadas en tránsito o faltantes.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setReceiveModalOpen(false)}
                                    className="text-slate-400 hover:text-slate-600 text-lg font-bold"
                                >
                                    ✕
                                </button>
                            </div>

                            <form onSubmit={handleReceiveSubmit} className="mt-4 space-y-4">
                                <div className="overflow-x-auto rounded-xl border border-slate-200 dark:border-neutral-800">
                                    <table className="w-full text-left text-xs">
                                        <thead className="bg-slate-50 border-b border-slate-200 text-[11px] font-bold uppercase text-slate-500 dark:border-neutral-800 dark:bg-neutral-950">
                                            <tr>
                                                <th className="p-3">SKU & Producto</th>
                                                <th className="p-3 text-center">Enviadas</th>
                                                <th className="p-3 text-center text-emerald-700 dark:text-emerald-400">Recibidas Bien</th>
                                                <th className="p-3 text-center text-rose-700 dark:text-rose-400">Dañadas</th>
                                                <th className="p-3 text-center text-amber-700 dark:text-amber-400">Faltantes</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100 font-medium dark:divide-neutral-800">
                                            {receptions.map((item, idx) => (
                                                <tr key={item.item_id}>
                                                    <td className="p-3">
                                                        <span className="font-mono font-bold text-indigo-600 dark:text-indigo-400 block">
                                                            {item.sku}
                                                        </span>
                                                        <span className="text-[11px] text-slate-700 dark:text-slate-300">
                                                            {item.product_name}
                                                        </span>
                                                    </td>
                                                    <td className="p-3 text-center font-mono font-bold text-slate-900 dark:text-white">
                                                        {item.quantity_sent}
                                                    </td>
                                                    <td className="p-3 text-center">
                                                        <input
                                                            type="number"
                                                            min="0"
                                                            value={item.quantity_received}
                                                            onChange={(e) => updateReception(idx, 'quantity_received', e.target.value)}
                                                            className="w-20 rounded-lg border border-emerald-300 bg-emerald-50/50 px-2 py-1 text-center font-mono text-xs font-bold text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200"
                                                            required
                                                        />
                                                    </td>
                                                    <td className="p-3 text-center">
                                                        <input
                                                            type="number"
                                                            min="0"
                                                            value={item.quantity_damaged}
                                                            onChange={(e) => updateReception(idx, 'quantity_damaged', e.target.value)}
                                                            className="w-20 rounded-lg border border-rose-300 bg-rose-50/50 px-2 py-1 text-center font-mono text-xs font-bold text-rose-900 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-200"
                                                        />
                                                    </td>
                                                    <td className="p-3 text-center">
                                                        <input
                                                            type="number"
                                                            min="0"
                                                            value={item.quantity_missing}
                                                            onChange={(e) => updateReception(idx, 'quantity_missing', e.target.value)}
                                                            className="w-20 rounded-lg border border-amber-300 bg-amber-50/50 px-2 py-1 text-center font-mono text-xs font-bold text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
                                                        />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>

                                <div>
                                    <label className="block text-xs font-bold text-slate-600 dark:text-slate-400 mb-1">
                                        Observaciones de Recepción / Incidencias MeLi:
                                    </label>
                                    <textarea
                                        rows={3}
                                        placeholder="Ej: MeLi reportó 1 caja con empaque dañado por paquetería..."
                                        value={receiveNotes}
                                        onChange={(e) => setReceiveNotes(e.target.value)}
                                        className="w-full rounded-xl border border-slate-300 bg-white p-3 text-xs dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                    />
                                </div>

                                <div className="flex items-center justify-end gap-2 pt-2 border-t border-slate-100 dark:border-neutral-800">
                                    <button
                                        type="button"
                                        onClick={() => setReceiveModalOpen(false)}
                                        className="rounded-xl border border-slate-300 px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                                    >
                                        Cancelar
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={receiving}
                                        className="rounded-xl bg-indigo-600 px-5 py-2 text-xs font-bold text-white hover:bg-indigo-700 disabled:opacity-50"
                                    >
                                        {receiving ? 'Guardando...' : 'Guardar Inspección'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}
            </div>
        </AppShell>
    )
}
