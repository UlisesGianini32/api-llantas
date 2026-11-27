import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'
import { formatDateTime } from '@/lib/inventoryPresentation'
import {
    APPLY_STATUS_LABELS,
    DISPATCH_STATUS_LABELS,
    OPERATION_TYPE_LABELS,
    UOM_LABELS,
    generateIdempotencyKey,
    operatingModeMeta,
} from '@/lib/warehouseDispatchScanner'

export default function Show({ dispatch, operatingMode = 'RECORD_ONLY', capabilities = {} }) {
    const [confirming, setConfirming] = useState(false)
    const [confirmKey] = useState(() => generateIdempotencyKey())
    const [showConfirmModal, setShowConfirmModal] = useState(false)
    const [showCancelConfirmedModal, setShowCancelConfirmedModal] = useState(false)
    const [cancelReason, setCancelReason] = useState('')
    const [physicalSituation, setPhysicalSituation] = useState('NEVER_DELIVERED')

    const modeMeta = operatingModeMeta(operatingMode)
    const isReadOnly = operatingMode === 'READ_ONLY'
    const isDraft = dispatch.status === 'DRAFT'
    const isConfirmed = dispatch.status === 'CONFIRMED'

    const handleConfirm = () => {
        if (confirming) return
        setConfirming(true)
        router.post(
            `/almacen/salidas/${dispatch.id}/confirmar`,
            {
                confirm_idempotency_key: confirmKey,
                expected_draft_version: dispatch.draft_version,
            },
            {
                onFinish: () => {
                    setConfirming(false)
                    setShowConfirmModal(false)
                },
            },
        )
    }

    const handleCancelDraft = () => {
        if (!window.confirm('¿Descartar este borrador? No se generarán movimientos de inventario.')) return
        router.post(`/almacen/salidas/${dispatch.id}/cancelar-borrador`, {
            cancel_reason: 'Borrador descartado por el operador.',
        })
    }

    const handleCancelConfirmed = (e) => {
        e.preventDefault()
        router.post(
            `/almacen/salidas/${dispatch.id}/anular`,
            {
                cancel_reason: cancelReason,
                physical_situation: physicalSituation,
            },
            {
                onSuccess: () => {
                    setShowCancelConfirmedModal(false)
                    setCancelReason('')
                },
            },
        )
    }

    const showCostCols = capabilities.can_view_costs && Object.prototype.hasOwnProperty.call(dispatch, 'reference_subtotal_cost')
    const showStylistCols =
        capabilities.can_view_stylist_prices &&
        dispatch.operation_type === 'SALON_SALE' &&
        Object.prototype.hasOwnProperty.call(dispatch, 'reference_subtotal_stylist')

    return (
        <AppShell title={`Salida ${dispatch.dispatch_number}`}>
            <Head title={`Salida ${dispatch.dispatch_number}`} />

            <div className="space-y-4">
                <div className={`rounded-xl border px-4 py-2.5 text-xs ${modeMeta.badgeClass}`}>
                    <strong>{modeMeta.label}:</strong> {modeMeta.description}
                </div>

                {/* Header */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div className="flex items-center gap-2">
                            <Link href="/almacen/salidas" className="text-xs font-semibold text-slate-500 hover:underline">
                                ← Salidas a Salones
                            </Link>
                        </div>
                        <h1 className="mt-1 font-mono text-2xl font-extrabold text-slate-900 dark:text-white">
                            {dispatch.dispatch_number}
                        </h1>
                        <div className="mt-1 flex flex-wrap items-center gap-2 text-xs">
                            <span className="rounded-full bg-slate-200 px-2.5 py-0.5 font-bold text-slate-800 dark:bg-neutral-800 dark:text-neutral-200">
                                {DISPATCH_STATUS_LABELS[dispatch.status] || dispatch.status}
                            </span>
                            <span className="text-slate-600 dark:text-neutral-300">
                                {OPERATION_TYPE_LABELS[dispatch.operation_type] || dispatch.operation_type}
                            </span>
                            {dispatch.is_retroactive && (
                                <span className="rounded bg-purple-100 px-2 py-0.5 font-bold text-purple-700">
                                    Retroactiva (&gt;24h)
                                </span>
                            )}
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <a
                            href={`/almacen/salidas/${dispatch.id}/vale-pdf`}
                            target="_blank"
                            rel="noreferrer"
                            className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        >
                            Imprimir Vale PDF
                        </a>
                        {capabilities.can_show_prices_in_pdf && (
                            <a
                                href={`/almacen/salidas/${dispatch.id}/vale-pdf?include_prices=1`}
                                target="_blank"
                                rel="noreferrer"
                                className="rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-2 text-xs font-bold text-indigo-800 hover:bg-indigo-100"
                            >
                                Vale PDF con precios estilista
                            </a>
                        )}
                        {isDraft && !isReadOnly && capabilities.can_update_dispatch && (
                            <Link
                                href={`/almacen/salidas/${dispatch.id}/editar`}
                                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
                            >
                                Editar borrador
                            </Link>
                        )}
                        {isDraft && !isReadOnly && capabilities.can_cancel_draft && (
                            <button
                                type="button"
                                onClick={handleCancelDraft}
                                className="rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-100"
                            >
                                Descartar borrador
                            </button>
                        )}
                        {isDraft && !isReadOnly && (
                            <button
                                type="button"
                                onClick={() => setShowConfirmModal(true)}
                                className="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-emerald-700"
                            >
                                Confirmar entrega
                            </button>
                        )}
                        {isConfirmed && !isReadOnly && capabilities.can_cancel_confirmed_without_ledger && !dispatch.has_applied_ledger_items && (
                            <button
                                type="button"
                                onClick={() => setShowCancelConfirmedModal(true)}
                                className="rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-100"
                            >
                                Anular por error (sin movimiento)
                            </button>
                        )}
                    </div>
                </div>

                {/* Metadata */}
                <div className="grid grid-cols-1 gap-4 rounded-xl border border-slate-200 bg-white p-4 text-xs shadow-sm sm:grid-cols-2 lg:grid-cols-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <div>
                        <div className="font-bold uppercase text-slate-400">Salón / Destino</div>
                        <div className="mt-0.5 font-semibold text-slate-900 dark:text-white">
                            {dispatch.destination_name} ({dispatch.destination_code})
                        </div>
                    </div>
                    <div>
                        <div className="font-bold uppercase text-slate-400">Fecha efectiva de entrega</div>
                        <div className="mt-0.5 font-semibold">{formatDateTime(dispatch.effective_at)}</div>
                    </div>
                    <div>
                        <div className="font-bold uppercase text-slate-400">Entregó (Almacén)</div>
                        <div className="mt-0.5 font-semibold">{dispatch.delivered_by_name || '—'}</div>
                    </div>
                    <div>
                        <div className="font-bold uppercase text-slate-400">Recibió (Salón)</div>
                        <div className="mt-0.5 font-semibold">{dispatch.received_by_name || '—'}</div>
                    </div>
                    {dispatch.confirmed_at && (
                        <div>
                            <div className="font-bold uppercase text-slate-400">Confirmada</div>
                            <div className="mt-0.5">{formatDateTime(dispatch.confirmed_at)} · {dispatch.confirmed_by_name || '—'}</div>
                        </div>
                    )}
                    <div>
                        <div className="font-bold uppercase text-slate-400">Totales físicos</div>
                        <div className="mt-0.5 font-bold">
                            {dispatch.total_physical_units} pieza(s) en {dispatch.total_lines_count} partida(s)
                            {dispatch.unconverted_containers_count > 0 && (
                                <span className="ml-1 text-amber-600">
                                    + {dispatch.unconverted_containers_count} partida(s) en contenedor sin equivalencia
                                </span>
                            )}
                        </div>
                    </div>
                    {showCostCols && (
                        <div>
                            <div className="font-bold uppercase text-slate-400">Subtotal costo ref.</div>
                            <div className="mt-0.5 font-bold">
                                {dispatch.reference_subtotal_cost !== null
                                    ? `$${Number(dispatch.reference_subtotal_cost).toFixed(2)}`
                                    : 'Sin costo registrado'}
                                {!dispatch.reference_cost_complete && (
                                    <span className="ml-1 text-[10px] text-amber-600">(parcial)</span>
                                )}
                            </div>
                        </div>
                    )}
                    {showStylistCols && (
                        <div>
                            <div className="font-bold uppercase text-slate-400">Subtotal estilista ref.</div>
                            <div className="mt-0.5 font-bold">
                                {dispatch.reference_subtotal_stylist !== null
                                    ? `$${Number(dispatch.reference_subtotal_stylist).toFixed(2)}`
                                    : 'Sin precio'}
                                {!dispatch.reference_stylist_complete && (
                                    <span className="ml-1 text-[10px] text-amber-600">(parcial)</span>
                                )}
                            </div>
                        </div>
                    )}
                    {dispatch.notes && (
                        <div className="sm:col-span-2 lg:col-span-4">
                            <div className="font-bold uppercase text-slate-400">Observaciones</div>
                            <div className="mt-0.5">{dispatch.notes}</div>
                        </div>
                    )}
                    {dispatch.cancel_reason && (
                        <div className="sm:col-span-2 lg:col-span-4 rounded-lg bg-rose-50 p-2 text-rose-800">
                            <strong>Motivo de cancelación / anulación:</strong> {dispatch.cancel_reason}
                        </div>
                    )}
                </div>

                {/* Items table */}
                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-xs dark:divide-neutral-800">
                            <thead className="bg-slate-50 dark:bg-neutral-950">
                                <tr>
                                    <th className="px-3 py-2 text-left font-bold">#</th>
                                    <th className="px-3 py-2 text-left font-bold">Producto (Snapshot)</th>
                                    <th className="px-3 py-2 text-left font-bold">Ubicación</th>
                                    <th className="px-3 py-2 text-right font-bold">Cantidad</th>
                                    <th className="px-3 py-2 text-left font-bold">Identificación</th>
                                    <th className="px-3 py-2 text-left font-bold">Aplicación Inventario</th>
                                    <th className="px-3 py-2 text-left font-bold">Mercado Libre</th>
                                    {showCostCols && <th className="px-3 py-2 text-right font-bold">Costo ref.</th>}
                                    {showStylistCols && <th className="px-3 py-2 text-right font-bold">Estilista ref.</th>}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                {(dispatch.items || []).map((item, idx) => (
                                    <tr key={item.id}>
                                        <td className="px-3 py-2.5 text-slate-500">{idx + 1}</td>
                                        <td className="px-3 py-2.5">
                                            <div className="font-semibold text-slate-900 dark:text-white">{item.captured_name}</div>
                                            <div className="text-[11px] text-slate-500">
                                                {[
                                                    item.captured_sku ? `SKU: ${item.captured_sku}` : null,
                                                    item.captured_barcode ? `CB: ${item.captured_barcode}` : null,
                                                    item.provisional_code || null,
                                                    item.captured_brand || null,
                                                    item.captured_tone ? `Tono ${item.captured_tone}` : null,
                                                    item.captured_presentation || null,
                                                ].filter(Boolean).join(' · ')}
                                            </div>
                                            {item.line_notes && (
                                                <div className="mt-0.5 text-[11px] italic text-slate-500">{item.line_notes}</div>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5 font-mono">{item.location_code || '—'}</td>
                                        <td className="px-3 py-2.5 text-right">
                                            <div className="font-bold">
                                                {item.captured_quantity} {UOM_LABELS[item.captured_unit_of_measure] || item.captured_unit_of_measure}
                                            </div>
                                            {item.unit_conversion_factor > 1 && (
                                                <div className="text-[11px] text-slate-500">
                                                    ({item.normalized_physical_quantity} pzas)
                                                </div>
                                            )}
                                            {item.normalized_physical_quantity === null && (
                                                <div className="text-[11px] text-amber-600">Sin equivalencia</div>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <span className={`rounded px-2 py-0.5 text-[10px] font-bold ${
                                                item.identification_status === 'REGISTERED' || item.identification_status === 'LINKED_POST_DISPATCH'
                                                    ? 'bg-emerald-100 text-emerald-800'
                                                    : 'bg-amber-100 text-amber-800'
                                            }`}>
                                                {item.identification_status}
                                            </span>
                                            {item.master_sku && item.identification_status === 'LINKED_POST_DISPATCH' && (
                                                <div className="mt-0.5 text-[11px] text-emerald-700">→ {item.master_sku}</div>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5">
                                            <span className="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-700 dark:bg-neutral-800 dark:text-neutral-200">
                                                {APPLY_STATUS_LABELS[item.inventory_apply_status] || item.inventory_apply_status}
                                            </span>
                                            {item.inventory_movement_id && (
                                                <div className="mt-0.5">
                                                    <Link
                                                        href={`/almacen/movimientos/${item.inventory_movement_id}`}
                                                        className="text-[11px] font-semibold text-emerald-700 hover:underline"
                                                    >
                                                        Kardex #{item.inventory_movement_id}
                                                    </Link>
                                                </div>
                                            )}
                                            {item.inventory_resolution_notes && (
                                                <div className="mt-0.5 max-w-xs text-[11px] text-slate-500">
                                                    {item.inventory_resolution_notes}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-3 py-2.5 text-[11px] text-slate-500">
                                            {item.meli_sync_status}
                                        </td>
                                        {showCostCols && (
                                            <td className="px-3 py-2.5 text-right font-mono">
                                                {item.unit_cost_snapshot !== null && item.unit_cost_snapshot !== undefined
                                                    ? `$${Number(item.unit_cost_snapshot).toFixed(2)}`
                                                    : 'Sin costo'}
                                            </td>
                                        )}
                                        {showStylistCols && (
                                            <td className="px-3 py-2.5 text-right font-mono">
                                                {item.unit_price_stylist_snapshot !== null && item.unit_price_stylist_snapshot !== undefined
                                                    ? `$${Number(item.unit_price_stylist_snapshot).toFixed(2)}`
                                                    : 'Sin precio'}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Events audit log */}
                <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <h2 className="text-sm font-bold text-slate-900 dark:text-white">Bitácora de auditoría</h2>
                    <div className="mt-2 space-y-2 text-xs">
                        {(dispatch.events || []).map((ev) => (
                            <div key={ev.id} className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-1.5 dark:border-neutral-800">
                                <div>
                                    <span className="font-mono font-bold text-slate-800 dark:text-neutral-200">{ev.event_type}</span>
                                    {ev.from_status && ev.to_status && (
                                        <span className="ml-2 text-slate-500">{ev.from_status} → {ev.to_status}</span>
                                    )}
                                    {ev.reason && <span className="ml-2 text-slate-600">· {ev.reason}</span>}
                                </div>
                                <div className="text-slate-500">
                                    {ev.actor_name || 'Sistema'} · {formatDateTime(ev.occurred_at)}
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* Confirm Modal */}
            {showConfirmModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                        <h3 className="text-base font-bold text-slate-900 dark:text-white">
                            Confirmar salida {dispatch.dispatch_number}
                        </h3>
                        <p className="mt-2 text-xs text-slate-600 dark:text-neutral-300">
                            Destino: <strong>{dispatch.destination_name}</strong> · {dispatch.total_lines_count} partida(s) ·{' '}
                            <strong>{dispatch.total_physical_units} pieza(s) físicas</strong>.
                        </p>
                        <p className="mt-2 rounded-lg bg-slate-100 p-2 text-xs text-slate-700 dark:bg-neutral-800 dark:text-neutral-200">
                            {operatingMode === 'APPLY_LEDGER'
                                ? 'Las partidas registradas elegibles descontarán inventario de su ubicación origen. Si alguna carece de stock, quedará marcada como discrepancia sin bloquear la confirmación.'
                                : 'Modo sin descuento activo: esta confirmación documentará la entrega en historial con 0 movimientos en el kardex.'}
                        </p>
                        <div className="mt-4 flex justify-end gap-2">
                            <button
                                type="button"
                                disabled={confirming}
                                onClick={() => setShowConfirmModal(false)}
                                className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold"
                            >
                                Cancelar
                            </button>
                            <button
                                type="button"
                                disabled={confirming}
                                onClick={handleConfirm}
                                className="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-bold text-white hover:bg-emerald-700 disabled:opacity-50"
                            >
                                {confirming ? 'Confirmando...' : 'Confirmar entrega'}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* Cancel Confirmed (0 ledger movements only) Modal */}
            {showCancelConfirmedModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <form onSubmit={handleCancelConfirmed} className="w-full max-w-md rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                        <h3 className="text-base font-bold text-rose-700">Anular salida confirmada (sin movimientos)</h3>
                        <p className="mt-1 text-xs text-slate-600">
                            Solo permitido cuando la mercancía nunca salió físicamente y no existen movimientos aplicados en inventario.
                        </p>
                        <div className="mt-3 space-y-3 text-xs">
                            <div>
                                <label className="font-bold">Situación física verificada</label>
                                <select
                                    value={physicalSituation}
                                    onChange={(e) => setPhysicalSituation(e.target.value)}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                >
                                    <option value="NEVER_DELIVERED">La mercancía nunca salió del almacén (error de captura)</option>
                                    <option value="DELIVERED_AND_RETURNED">Sí fue entregada y regresó después (requiere Devolución Fase 2)</option>
                                </select>
                            </div>
                            <div>
                                <label className="font-bold">Motivo obligatorio</label>
                                <textarea
                                    required
                                    minLength={5}
                                    rows={3}
                                    value={cancelReason}
                                    onChange={(e) => setCancelReason(e.target.value)}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                    placeholder="Explica el error detectado..."
                                />
                            </div>
                        </div>
                        <div className="mt-4 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setShowCancelConfirmedModal(false)}
                                className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold"
                            >
                                Cerrar
                            </button>
                            <button
                                type="submit"
                                className="rounded-lg bg-rose-600 px-4 py-2 text-xs font-bold text-white hover:bg-rose-700"
                            >
                                Anular salida
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </AppShell>
    )
}
