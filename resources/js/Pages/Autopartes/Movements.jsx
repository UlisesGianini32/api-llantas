import { router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import { AUTOPARTES_CAPABILITIES, hasCapability } from '@/lib/autopartesCapabilities'
import Layout, {
    IdentityBadge,
    Pagination,
    baseUrl,
    button,
    card,
    field,
    secondaryButton,
    statusLabel,
} from './Layout'

export default function Movements({
    branch,
    defaultLocationId,
    locations = [],
    actors = [],
    movements,
    recentAdjustments = [],
    adjustmentSearch = '',
    adjustmentCandidates = [],
    suggestedIdempotencyKey = '',
    filters,
    capabilities = [],
}) {
    const canCreateAdjustment = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.ADJUSTMENT_CREATE)
    const canApproveAdjustment = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.ADJUSTMENT_APPROVE)

    const [filterValues, setFilterValues] = useState({
        search: filters.search || '',
        automotive_part_id: filters.automotive_part_id || '',
        user_id: filters.user_id || '',
        automotive_location_id: filters.automotive_location_id || '',
        type: filters.type || '',
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
    })

    const [adjSearchQuery, setAdjSearchQuery] = useState(adjustmentSearch || '')
    const [selectedProduct, setSelectedProduct] = useState(null)

    const adjForm = useForm({
        automotive_part_id: '',
        automotive_location_id: defaultLocationId || (locations[0]?.id ?? ''),
        type: 'ADJUSTMENT_IN',
        quantity: 1,
        reason: '',
        notes: '',
        idempotency_key: suggestedIdempotencyKey,
        auto_approve: canApproveAdjustment,
    })

    const changeFilter = key => e => setFilterValues({ ...filterValues, [key]: e.target.value })

    const selectForAdjustment = cand => {
        setSelectedProduct(cand)
        adjForm.setData('automotive_part_id', cand.automotive_part_id)
    }

    const submitAdjustment = e => {
        e.preventDefault()
        adjForm.post(`${baseUrl(branch)}/ajustes`, {
            preserveScroll: true,
            onSuccess: () => {
                setSelectedProduct(null)
                adjForm.setData({
                    ...adjForm.data,
                    automotive_part_id: '',
                    quantity: 1,
                    reason: '',
                    notes: '',
                    idempotency_key: `adj-${branch.code}-${Date.now()}`,
                })
            },
        })
    }

    return (
        <Layout branch={branch} title={`Movimientos — ${branch.name}`}>
            {canCreateAdjustment && (
                <div className={`${card} space-y-4`}>
                    <div>
                        <h2 className="text-base font-semibold">
                            Registrar ajuste auditado de inventario ({branch.name})
                        </h2>
                        <p className="mt-1 text-xs text-slate-600 dark:text-neutral-400">
                            Los ajustes de entrada (<code>ADJUSTMENT_IN</code>) y salida (<code>ADJUSTMENT_OUT</code>) requieren permiso especial, motivo obligatorio y validación estricta para impedir stock negativo.
                        </p>
                    </div>

                    <form
                        className="flex flex-wrap gap-2"
                        onSubmit={e => {
                            e.preventDefault()
                            router.get(
                                `${baseUrl(branch)}/movimientos`,
                                { ...filterValues, adjustment_search: adjSearchQuery },
                                { preserveState: true, preserveScroll: true }
                            )
                        }}
                    >
                        <input
                            className={`${field} flex-1 min-w-64`}
                            value={adjSearchQuery}
                            onChange={e => setAdjSearchQuery(e.target.value)}
                            placeholder="Buscar autoparte a ajustar por SKU, Item #, MFG Part # o descripción..."
                        />
                        <button type="submit" className={secondaryButton}>
                            Buscar autoparte
                        </button>
                    </form>

                    {adjustmentCandidates.length > 0 && !selectedProduct && (
                        <div className="max-h-56 overflow-y-auto rounded-lg border border-slate-200 p-2 text-xs space-y-2 dark:border-neutral-700">
                            {adjustmentCandidates.map(cand => (
                                <div
                                    key={cand.automotive_part_id}
                                    className="flex flex-wrap items-center justify-between gap-2 rounded border border-slate-100 p-2 dark:border-neutral-800"
                                >
                                    <div>
                                        <p className="font-mono font-semibold text-indigo-700">
                                            {cand.code} · Item #: {cand.item_number || '—'} · MFG: {cand.manufacturer_part_number || '—'}
                                        </p>
                                        <p className="font-medium">{cand.description}</p>
                                        <p className="text-slate-500">
                                            Stock actual {branch.code}: <strong>{cand.branch_stock}</strong> ·{' '}
                                            <IdentityBadge status={cand.identity_status} />
                                        </p>
                                    </div>
                                    {cand.is_receivable ? (
                                        <button type="button" className={button} onClick={() => selectForAdjustment(cand)}>
                                            Seleccionar para ajuste
                                        </button>
                                    ) : (
                                        <span className="text-amber-700 font-medium">Requiere verificar identidad primero</span>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}

                    {selectedProduct && (
                        <form className="rounded-lg border border-indigo-200 bg-indigo-50/40 p-4 space-y-4" onSubmit={submitAdjustment}>
                            <div className="flex items-start justify-between gap-3">
                                <div className="text-sm">
                                    <p className="font-mono font-bold text-indigo-700">{selectedProduct.code}</p>
                                    <p className="font-medium">
                                        Item #: {selectedProduct.item_number || '—'} · MFG: {selectedProduct.manufacturer_part_number || '—'} ·{' '}
                                        {selectedProduct.description}
                                    </p>
                                    <p className="text-xs text-slate-600">
                                        Stock físico actual en {branch.code}: <strong>{selectedProduct.branch_stock} uds</strong>
                                    </p>
                                </div>
                                <button type="button" className={secondaryButton} onClick={() => setSelectedProduct(null)}>
                                    Cambiar pieza
                                </button>
                            </div>

                            <div className="grid gap-3 md:grid-cols-4">
                                <label className="flex flex-col gap-1 text-sm">
                                    Ubicación ({branch.code})
                                    <select
                                        required
                                        className={field}
                                        value={adjForm.data.automotive_location_id}
                                        onChange={e => adjForm.setData('automotive_location_id', e.target.value)}
                                    >
                                        {locations.map(loc => (
                                            <option key={loc.id} value={loc.id}>
                                                {loc.code} · {loc.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="flex flex-col gap-1 text-sm">
                                    Tipo de ajuste
                                    <select
                                        required
                                        className={field}
                                        value={adjForm.data.type}
                                        onChange={e => adjForm.setData('type', e.target.value)}
                                    >
                                        <option value="ADJUSTMENT_IN">ADJUSTMENT_IN (+ Entrada por ajuste)</option>
                                        <option value="ADJUSTMENT_OUT">ADJUSTMENT_OUT (- Salida por ajuste)</option>
                                    </select>
                                </label>
                                <label className="flex flex-col gap-1 text-sm">
                                    Cantidad (entero &gt; 0)
                                    <input
                                        type="number"
                                        min={1}
                                        step={1}
                                        required
                                        className={`${field} font-mono`}
                                        value={adjForm.data.quantity}
                                        onChange={e => adjForm.setData('quantity', e.target.value)}
                                    />
                                </label>
                                <label className="flex flex-col gap-1 text-sm">
                                    Motivo obligatorio (mín. 5 caracteres)
                                    <input
                                        required
                                        minLength={5}
                                        className={field}
                                        value={adjForm.data.reason}
                                        onChange={e => adjForm.setData('reason', e.target.value)}
                                        placeholder="Ej. Corrección por reconteo físico auditado"
                                    />
                                </label>
                            </div>

                            <div className="grid gap-3 md:grid-cols-2 items-center">
                                <label className="flex flex-col gap-1 text-sm">
                                    Observaciones adicionales (opcional)
                                    <input
                                        className={field}
                                        value={adjForm.data.notes}
                                        onChange={e => adjForm.setData('notes', e.target.value)}
                                        placeholder="Detalle de auditoría, acta o responsable"
                                    />
                                </label>
                                {canApproveAdjustment && (
                                    <label className="flex items-center gap-2 text-sm mt-4">
                                        <input
                                            type="checkbox"
                                            checked={adjForm.data.auto_approve}
                                            onChange={e => adjForm.setData('auto_approve', e.target.checked)}
                                        />
                                        Aprobar y aplicar movimiento inmediatamente
                                    </label>
                                )}
                            </div>

                            <button type="submit" className={button} disabled={adjForm.processing}>
                                {adjForm.processing ? 'Registrando ajuste…' : 'Guardar ajuste auditado'}
                            </button>
                        </form>
                    )}
                </div>
            )}

            {recentAdjustments.length > 0 && (
                <div className={card}>
                    <h2 className="mb-3 text-base font-semibold">Ajustes auditados recientes ({branch.code})</h2>
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead className="border-b border-slate-200 uppercase text-slate-500">
                                <tr>
                                    <th className="p-2">Folio</th>
                                    <th className="p-2">Estado</th>
                                    <th className="p-2">Autoparte</th>
                                    <th className="p-2">Tipo / Cantidad</th>
                                    <th className="p-2">Antes → Después</th>
                                    <th className="p-2">Motivo</th>
                                    <th className="p-2">Creado / Aprobado</th>
                                    {canApproveAdjustment && <th className="p-2">Acción</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {recentAdjustments.map(adj => (
                                    <tr key={adj.id} className="border-b border-slate-100 dark:border-neutral-800">
                                        <td className="p-2 font-mono font-semibold">{adj.folio}</td>
                                        <td className="p-2">{statusLabel(adj.status)}</td>
                                        <td className="p-2">
                                            <span className="font-mono">{adj.code}</span> · {adj.item_number} · {adj.description}
                                        </td>
                                        <td className="p-2 font-mono font-bold">
                                            {adj.type === 'ADJUSTMENT_IN' ? `+${adj.quantity}` : `-${adj.quantity}`} ({adj.type})
                                        </td>
                                        <td className="p-2 font-mono">
                                            {adj.stock_before !== null ? `${adj.stock_before} → ${adj.stock_after}` : 'Pendiente'}
                                        </td>
                                        <td className="p-2">{adj.reason}</td>
                                        <td className="p-2">
                                            {adj.created_by_name}
                                            {adj.approved_by_name ? ` / Aprobó: ${adj.approved_by_name}` : ''}
                                        </td>
                                        {canApproveAdjustment && (
                                            <td className="p-2">
                                                {adj.status === 'pending' && (
                                                    <button
                                                        type="button"
                                                        className={button}
                                                        onClick={() =>
                                                            router.post(
                                                                `${baseUrl(branch)}/ajustes/${adj.id}/aprobar`,
                                                                {},
                                                                { preserveScroll: true }
                                                            )
                                                        }
                                                    >
                                                        Aprobar
                                                    </button>
                                                )}
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            <form
                className={`${card} flex flex-wrap items-end gap-3`}
                onSubmit={e => {
                    e.preventDefault()
                    router.get(`${baseUrl(branch)}/movimientos`, filterValues, { preserveState: true })
                }}
            >
                <label className="flex min-w-56 flex-1 flex-col gap-1 text-sm">
                    Buscar (Folio, SKU, Item #, MFG Part #, descripción)
                    <input
                        className={field}
                        value={filterValues.search}
                        onChange={changeFilter('search')}
                        placeholder="Ej. REC-HMO, ADJ-HMO, 15670445"
                    />
                </label>
                <label className="flex flex-col gap-1 text-sm">
                    Tipo de movimiento
                    <select className={field} value={filterValues.type} onChange={changeFilter('type')}>
                        <option value="">Todos</option>
                        <option value="RECEIPT">RECEIPT (Entrada por recepción)</option>
                        <option value="ADJUSTMENT_IN">ADJUSTMENT_IN (+ Ajuste entrada)</option>
                        <option value="ADJUSTMENT_OUT">ADJUSTMENT_OUT (- Ajuste salida)</option>
                        <option value="SALE">SALE (- Salida por venta POS)</option>
                        <option value="RETURN">RETURN (+ Devolución POS)</option>
                        <option value="CANCEL">CANCEL (+ Cancelación POS)</option>
                    </select>
                </label>
                <label className="flex flex-col gap-1 text-sm">
                    Ubicación {branch.code}
                    <select className={field} value={filterValues.automotive_location_id} onChange={changeFilter('automotive_location_id')}>
                        <option value="">Todas</option>
                        {locations.map(loc => (
                            <option key={loc.id} value={loc.id}>
                                {loc.code} · {loc.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex flex-col gap-1 text-sm">
                    Usuario
                    <select className={field} value={filterValues.user_id} onChange={changeFilter('user_id')}>
                        <option value="">Todos</option>
                        {actors.map(actor => (
                            <option key={actor.id} value={actor.id}>
                                {actor.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex flex-col gap-1 text-sm">
                    Desde
                    <input type="date" className={field} value={filterValues.date_from} onChange={changeFilter('date_from')} />
                </label>
                <label className="flex flex-col gap-1 text-sm">
                    Hasta
                    <input type="date" className={field} value={filterValues.date_to} onChange={changeFilter('date_to')} />
                </label>
                <button type="submit" className={button}>
                    Filtrar historial
                </button>
            </form>

            <div className={card}>
                <h2 className="mb-3 text-base font-semibold">Bitácora inmutable de movimientos físicos ({branch.name})</h2>
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                            <tr>
                                <th className="p-3">Folio / ID</th>
                                <th className="p-3">Fecha y hora</th>
                                <th className="p-3">Usuario</th>
                                <th className="p-3">Producto / Identificadores</th>
                                <th className="p-3">Tipo</th>
                                <th className="p-3">Cantidad</th>
                                <th className="p-3">Saldo anterior</th>
                                <th className="p-3">Saldo posterior</th>
                                <th className="p-3">Sucursal / Ubicación</th>
                                <th className="p-3">Motivo y observaciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            {movements.data.map(mov => (
                                <tr key={mov.id} className="border-b border-slate-100 align-top dark:border-neutral-800">
                                    <td className="p-3 font-mono text-xs font-bold text-indigo-700">
                                        {mov.folio}
                                        <p className="font-normal text-slate-400">#{mov.id}</p>
                                    </td>
                                    <td className="p-3 text-xs whitespace-nowrap">{mov.occurred_at}</td>
                                    <td className="p-3 text-xs font-medium">{mov.user_name}</td>
                                    <td className="max-w-xs p-3 text-xs">
                                        <p className="font-mono font-semibold text-indigo-700">{mov.code}</p>
                                        <p>
                                            Item #: <strong className="font-mono">{mov.item_number || '—'}</strong> · MFG:{' '}
                                            <strong className="font-mono">{mov.manufacturer_part_number || '—'}</strong>
                                        </p>
                                        <p className="mt-1 font-medium">{mov.description}</p>
                                    </td>
                                    <td className="p-3">
                                        <span className="rounded bg-slate-100 px-2 py-0.5 font-mono text-xs font-semibold dark:bg-neutral-800">
                                            {mov.type}
                                        </span>
                                    </td>
                                    <td
                                        className={`p-3 font-mono text-base font-bold ${
                                            mov.quantity > 0 ? 'text-emerald-700' : 'text-red-700'
                                        }`}
                                    >
                                        {mov.quantity > 0 ? `+${mov.quantity}` : mov.quantity}
                                    </td>
                                    <td className="p-3 font-mono">{mov.stock_before}</td>
                                    <td className="p-3 font-mono font-bold text-indigo-700">{mov.stock_after}</td>
                                    <td className="p-3 text-xs font-mono">
                                        {mov.branch_code} · {mov.location_code}
                                        <p className="font-sans text-slate-400">{mov.location_name}</p>
                                    </td>
                                    <td className="max-w-xs p-3 text-xs">
                                        <p className="font-medium">{mov.reason}</p>
                                        {mov.notes && <p className="mt-1 text-slate-500">{mov.notes}</p>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {movements.data.length === 0 && (
                        <p className="p-8 text-center text-slate-500">
                            No hay movimientos registrados para los filtros seleccionados.
                        </p>
                    )}
                </div>
                <Pagination data={movements} />
            </div>
        </Layout>
    )
}
