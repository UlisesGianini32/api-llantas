import { router } from '@inertiajs/react'
import { useState } from 'react'
import { AUTOPARTES_CAPABILITIES, hasCapability } from '@/lib/autopartesCapabilities'
import { autopartesHttp, formatAutopartesHttpError } from '@/lib/autopartesHttp'
import Layout, { baseUrl, button, card, field, IdentityBadge, Pagination, secondaryButton } from './Layout'

export default function Prices({
    branch,
    capabilities = [],
    filters = {},
    parts,
    statusCounts = {},
    categories = [],
    recentHistory = [],
}) {
    const canManage = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.PRICES_MANAGE)
    const [search, setSearch] = useState(filters.q || '')
    const [category, setCategory] = useState(filters.category || '')
    const [priceStatus, setPriceStatus] = useState(filters.price_status || '')

    const [editingPart, setEditingPart] = useState(null)
    const [priceInput, setPriceInput] = useState('')
    const [statusInput, setStatusInput] = useState('approved')
    const [sellableInput, setSellableInput] = useState(true)
    const [blockedInput, setBlockedInput] = useState(false)
    const [reasonInput, setReasonInput] = useState('')

    // Batch update state
    const [batchRows, setBatchRows] = useState([])
    const [batchReason, setBatchReason] = useState('')
    const [batchPreview, setBatchPreview] = useState(null)
    const [batchError, setBatchError] = useState(null)

    // Bulk CSV/text import state
    const [importRawText, setImportRawText] = useState('')
    const [importStatus, setImportStatus] = useState('approved')
    const [importSellable, setImportSellable] = useState(true)
    const [importReason, setImportReason] = useState('')
    const [importPreview, setImportPreview] = useState(null)
    const [importError, setImportError] = useState(null)

    const openEditModal = part => {
        setEditingPart(part)
        setPriceInput(part.sale_price_mxn || '')
        setStatusInput(part.price_status === 'pending_approval' ? 'pending_approval' : 'approved')
        setSellableInput(Boolean(part.is_sellable))
        setBlockedInput(Boolean(part.sale_blocked))
        setReasonInput('')
    }

    const submitSinglePrice = e => {
        e.preventDefault()
        if (!editingPart) return
        router.post(`${baseUrl(branch)}/precios/${editingPart.part_id}`, {
            sale_price_mxn: priceInput,
            price_status: statusInput,
            is_sellable: statusInput === 'approved' ? sellableInput : false,
            sale_blocked: blockedInput,
            reason: reasonInput,
        }, {
            onSuccess: () => setEditingPart(null),
        })
    }

    const handlePreviewImport = async () => {
        setImportError(null)
        try {
            const res = await autopartesHttp.post(`${baseUrl(branch)}/precios/importar/vista-previa`, {
                raw_text: importRawText,
                price_status: importStatus,
                is_sellable: importStatus === 'approved' ? importSellable : false,
                reason: importReason,
            })
            setImportPreview(res.data?.preview)
        } catch (err) {
            setImportError(formatAutopartesHttpError(err, 'Error al validar la importación masiva.'))
        }
    }

    const handleConfirmImport = () => {
        router.post(`${baseUrl(branch)}/precios/importar/confirmar`, {
            raw_text: importRawText,
            price_status: importStatus,
            is_sellable: importStatus === 'approved' ? importSellable : false,
            reason: importReason,
        }, {
            onSuccess: () => {
                setImportRawText('')
                setImportReason('')
                setImportPreview(null)
            },
        })
    }

    const addPartToBatch = part => {
        if (batchRows.some(r => r.part_id === part.part_id)) return
        setBatchRows(prev => [...prev, {
            part_id: part.part_id,
            item_number: part.item_number,
            description: part.description,
            documental_retail_usd: part.documental_retail_usd,
            sale_price_mxn: part.sale_price_mxn || '',
            is_sellable: true,
        }])
    }

    const handlePreviewBatch = async () => {
        setBatchError(null)
        try {
            const res = await autopartesHttp.post(`${baseUrl(branch)}/precios/lote/vista-previa`, {
                reason: batchReason,
                items: batchRows,
            })
            setBatchPreview(res.data?.preview)
        } catch (err) {
            setBatchPreview(null)
            setBatchError(formatAutopartesHttpError(err, 'Error al generar la vista previa del lote.'))
        }
    }

    const handleApplyBatch = () => {
        router.post(`${baseUrl(branch)}/precios/lote/aplicar`, {
            reason: batchReason,
            items: batchRows,
        }, {
            onSuccess: () => {
                setBatchRows([])
                setBatchReason('')
                setBatchPreview(null)
            },
        })
    }

    return <Layout branch={branch} title={`Precios de autopartes — ${branch.name}`}>
        {/* Resumen de estados de precios comerciales (PASO 7.1) */}
        <div className="mb-4 grid gap-3 sm:grid-cols-4">
            <div className={card}>
                <p className="text-xs font-semibold uppercase text-slate-500">Sin precio (unpriced)</p>
                <p className="mt-1 text-xl font-bold text-red-600">{statusCounts.unpriced ?? 0}</p>
            </div>
            <div className={card}>
                <p className="text-xs font-semibold uppercase text-slate-500">Pendientes de aprobación</p>
                <p className="mt-1 text-xl font-bold text-amber-600">{statusCounts.pending_approval ?? 0}</p>
            </div>
            <div className={card}>
                <p className="text-xs font-semibold uppercase text-slate-500">Con precio aprobado</p>
                <p className="mt-1 text-xl font-bold text-emerald-700">{statusCounts.approved ?? 0}</p>
            </div>
            <div className={card}>
                <p className="text-xs font-semibold uppercase text-slate-500">Habilitadas venta POS</p>
                <p className="mt-1 text-xl font-bold text-indigo-700">{statusCounts.sellable ?? 0}</p>
            </div>
        </div>

        <div className={card}>
            <p className="text-xs text-slate-600 dark:text-neutral-300">
                <strong>PRECIOS — PENDIENTES DE DEFINICIÓN COMERCIAL:</strong> Los campos <code>Retail</code> y <code>Ext. Retail</code> del Excel se muestran únicamente como referencia documental inmutable y <strong>nunca</strong> se convierten automáticamente ni se usan como respaldo. Todo producto permanece no vendible hasta tener un precio comercial aprobado en MXN.
            </p>
            <form
                onSubmit={e => {
                    e.preventDefault()
                    router.get(`${baseUrl(branch)}/precios`, {
                        q: search,
                        category,
                        price_status: priceStatus,
                    }, { preserveState: true })
                }}
                className="mt-3 flex flex-wrap gap-2"
            >
                <input
                    type="text"
                    value={search}
                    onChange={e => setSearch(e.target.value)}
                    placeholder="Buscar por Item #, MFG Part #, SKU, descripción o proveedor..."
                    className={`${field} flex-1`}
                />
                <select value={category} onChange={e => setCategory(e.target.value)} className={field}>
                    <option value="">Todas las categorías</option>
                    {categories.map(c => <option key={c} value={c}>{c}</option>)}
                </select>
                <select value={priceStatus} onChange={e => setPriceStatus(e.target.value)} className={field}>
                    <option value="">Todos los estados de precio</option>
                    <option value="unpriced">Sin precio (unpriced)</option>
                    <option value="pending_approval">Pendientes de aprobación (pending_approval)</option>
                    <option value="approved">Con precio aprobado (approved)</option>
                    <option value="sellable">Habilitados para venta en {branch.code}</option>
                </select>
                <button type="submit" className={secondaryButton}>Buscar</button>
            </form>

            <div className="mt-4 overflow-x-auto">
                <table className="w-full text-left text-xs">
                    <thead>
                        <tr className="border-b border-slate-200 uppercase text-slate-500">
                            <th className="py-2">Item # / MFG</th>
                            <th className="py-2">Descripción / Categoría</th>
                            <th className="py-2">Identidad</th>
                            <th className="py-2 text-right">Ref. Excel Retail / Ext.</th>
                            <th className="py-2 text-right">Stock {branch.code}</th>
                            <th className="py-2 text-right">Precio Venta MXN</th>
                            <th className="py-2">Disponible POS</th>
                            {canManage && <th className="py-2 text-right">Acciones</th>}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {(parts?.data || []).map(p => (
                            <tr key={p.part_id}>
                                <td className="py-2.5 font-mono">
                                    <div className="font-bold">{p.item_number || p.code}</div>
                                    <div className="text-slate-500">MFG: {p.manufacturer_part_number || '—'}</div>
                                </td>
                                <td className="py-2.5">
                                    <div className="font-medium">{p.description}</div>
                                    <div className="text-slate-500">{p.category} · {p.vendor_name}</div>
                                </td>
                                <td className="py-2.5"><IdentityBadge status={p.identity_status} /></td>
                                <td className="py-2.5 text-right font-mono text-slate-500">
                                    <div>Retail: {p.documental_retail_usd ?? '—'}</div>
                                    <div>Ext: {p.documental_ext_retail_usd ?? '—'}</div>
                                </td>
                                <td className="py-2.5 text-right font-mono font-semibold">{p.available_stock}</td>
                                <td className="py-2.5 text-right font-mono font-bold">
                                    <div>
                                        {p.sale_price_mxn ? `$${p.sale_price_mxn} MXN` : <span className="text-red-600">Sin precio</span>}
                                    </div>
                                    <div className="mt-0.5 text-[11px] font-semibold">
                                        {p.price_status === 'approved' && <span className="text-emerald-700">Aprobado</span>}
                                        {p.price_status === 'pending_approval' && <span className="text-amber-600">Pendiente aprobación</span>}
                                        {(!p.price_status || p.price_status === 'unpriced') && <span className="text-slate-400">No definido</span>}
                                    </div>
                                </td>
                                <td className="py-2.5">
                                    <span className={`inline-block rounded px-2 py-0.5 text-xs font-semibold ${
                                        p.eligible_for_pos ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-700'
                                    }`}>
                                        {p.eligible_for_pos ? 'Vendible en HMO' : 'No vendible'}
                                    </span>
                                </td>
                                {canManage && (
                                    <td className="py-2.5 text-right space-x-1">
                                        <button type="button" onClick={() => openEditModal(p)} className={secondaryButton}>
                                            Configurar MXN
                                        </button>
                                        <button type="button" onClick={() => addPartToBatch(p)} className={secondaryButton}>
                                            + Lote
                                        </button>
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination data={parts} />
        </div>

        {/* Configuración individual de precio MXN */}
        {editingPart && (
            <div className={card}>
                <h3 className="text-sm font-bold">
                    Configurar precio MXN en {branch.name}: [{editingPart.item_number}] {editingPart.description}
                </h3>
                <p className="mt-1 text-xs text-slate-500">
                    Referencia documental Excel (No se usa automáticamente): Retail = {editingPart.documental_retail_usd ?? 'N/A'} | Ext. Retail = {editingPart.documental_ext_retail_usd ?? 'N/A'}
                </p>
                <form onSubmit={submitSinglePrice} className="mt-3 grid gap-3 md:grid-cols-6">
                    <div>
                        <label className="block text-xs font-semibold">Precio comercial MXN *</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0.01"
                            value={priceInput}
                            onChange={e => setPriceInput(e.target.value)}
                            className={`${field} mt-1 w-full font-mono`}
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-semibold">Estado de aprobación *</label>
                        <select value={statusInput} onChange={e => setStatusInput(e.target.value)} className={`${field} mt-1 w-full`}>
                            <option value="approved">Precio aprobado</option>
                            <option value="pending_approval">Pendiente de aprobación</option>
                        </select>
                    </div>
                    <div className="flex items-center gap-4 pt-5">
                        <label className="flex items-center gap-1.5 text-xs font-medium">
                            <input
                                type="checkbox"
                                checked={statusInput === 'approved' ? sellableInput : false}
                                disabled={statusInput !== 'approved'}
                                onChange={e => setSellableInput(e.target.checked)}
                            />
                            Habilitar venta en {branch.code}
                        </label>
                        <label className="flex items-center gap-1.5 text-xs font-medium text-red-700">
                            <input type="checkbox" checked={blockedInput} onChange={e => setBlockedInput(e.target.checked)} />
                            Bloqueo admin.
                        </label>
                    </div>
                    <div className="md:col-span-2">
                        <label className="block text-xs font-semibold">Motivo de modificación *</label>
                        <input
                            type="text"
                            value={reasonInput}
                            onChange={e => setReasonInput(e.target.value)}
                            placeholder="Ej. Lista comercial autorizada Hermosillo..."
                            className={`${field} mt-1 w-full`}
                        />
                    </div>
                    <div className="flex items-end gap-2">
                        <button type="submit" className={button}>Guardar</button>
                        <button type="button" onClick={() => setEditingPart(null)} className={secondaryButton}>Cancelar</button>
                    </div>
                </form>
            </div>
        )}

        {/* Importación masiva de precios MXN con vista previa, validación y confirmación explícita */}
        {canManage && (
            <div className={card}>
                <h3 className="text-sm font-bold">
                    Importación masiva de precios comerciales MXN (Vista previa, validación y confirmación explícita)
                </h3>
                <p className="mt-1 text-xs text-slate-500">
                    Pegue líneas en formato <code>Item # o SKU, Precio MXN, Estado (approved|pending_approval), Vendible (1|0)</code>. No modifica las 21 columnas del Excel original.
                </p>
                <div className="mt-3 grid gap-3 md:grid-cols-3">
                    <div className="md:col-span-2">
                        <textarea
                            rows={3}
                            value={importRawText}
                            onChange={e => setImportRawText(e.target.value)}
                            placeholder={'ITM-8801, 1450.00, approved, 1\nITM-8802, 980.00, pending_approval, 0'}
                            className={`${field} w-full font-mono text-xs`}
                        />
                    </div>
                    <div className="space-y-2 text-xs">
                        <div>
                            <label className="block font-semibold">Estado predeterminado</label>
                            <select value={importStatus} onChange={e => setImportStatus(e.target.value)} className={`${field} mt-1 w-full`}>
                                <option value="approved">Precio aprobado (approved)</option>
                                <option value="pending_approval">Pendiente de aprobación (pending_approval)</option>
                            </select>
                        </div>
                        <label className="flex items-center gap-1.5 font-medium">
                            <input
                                type="checkbox"
                                checked={importStatus === 'approved' ? importSellable : false}
                                disabled={importStatus !== 'approved'}
                                onChange={e => setImportSellable(e.target.checked)}
                            />
                            Habilitar para venta local si está aprobado
                        </label>
                        <input
                            type="text"
                            value={importReason}
                            onChange={e => setImportReason(e.target.value)}
                            placeholder="Motivo obligatorio de importación masiva..."
                            className={`${field} w-full`}
                        />
                        <button type="button" onClick={handlePreviewImport} className={`${secondaryButton} w-full`}>
                            Validar y generar vista previa
                        </button>
                    </div>
                </div>
                {importError && <p className="mt-2 text-xs font-semibold text-red-600">{importError}</p>}
                {importPreview && (
                    <div className="mt-3 rounded-lg border border-slate-200 p-3 text-xs">
                        <div className="flex items-center justify-between">
                            <p className="font-semibold">
                                Vista previa: {importPreview.valid_rows} válidas · {importPreview.error_rows} con errores
                            </p>
                            {importPreview.can_commit && (
                                <button type="button" onClick={handleConfirmImport} className={button}>
                                    Confirmar importación masiva ({importPreview.valid_rows})
                                </button>
                            )}
                        </div>
                        <div className="mt-2 max-h-56 overflow-y-auto">
                            <table className="w-full text-left text-xs">
                                <thead>
                                    <tr className="border-b border-slate-200 text-slate-500">
                                        <th className="py-1">Identificador</th>
                                        <th className="py-1">Descripción</th>
                                        <th className="py-1 text-right">Ref. Excel</th>
                                        <th className="py-1 text-right">Precio Anterior</th>
                                        <th className="py-1 text-right">Precio Nuevo MXN</th>
                                        <th className="py-1">Estado Nuevo</th>
                                        <th className="py-1">Validación</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {(importPreview.rows || []).map(r => (
                                        <tr key={r.index} className="border-b border-slate-100">
                                            <td className="py-1 font-mono font-bold">{r.identifier}</td>
                                            <td className="py-1">{r.description || '—'}</td>
                                            <td className="py-1 text-right font-mono">{r.documental_retail_usd ?? '—'}</td>
                                            <td className="py-1 text-right font-mono">{r.previous_price_mxn ? `$${r.previous_price_mxn}` : 'Sin precio'}</td>
                                            <td className="py-1 text-right font-mono font-bold">{r.new_price_mxn ? `$${r.new_price_mxn}` : '—'}</td>
                                            <td className="py-1">{r.new_price_status}</td>
                                            <td className="py-1">
                                                {r.valid ? (
                                                    <span className="font-semibold text-emerald-700">OK</span>
                                                ) : (
                                                    <span className="font-semibold text-red-600">{(r.errors || []).join(' ')}</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        )}

        {/* Actualización masiva controlada con vista previa */}
        {canManage && batchRows.length > 0 && (
            <div className={card}>
                <h3 className="text-sm font-bold">Actualización masiva controlada de precios MXN ({batchRows.length} partidas)</h3>
                <div className="mt-3 space-y-2">
                    {batchRows.map((row, idx) => (
                        <div key={row.part_id} className="flex items-center gap-3 text-xs">
                            <span className="w-32 font-mono font-bold">{row.item_number}</span>
                            <span className="flex-1 truncate">{row.description}</span>
                            <span className="text-slate-500">Ref Excel: {row.documental_retail_usd ?? '—'}</span>
                            <input
                                type="number"
                                step="0.01"
                                min="0.01"
                                value={row.sale_price_mxn}
                                onChange={e => {
                                    const copy = [...batchRows]
                                    copy[idx] = { ...copy[idx], sale_price_mxn: e.target.value }
                                    setBatchRows(copy)
                                }}
                                placeholder="Precio MXN"
                                className={`${field} w-32 font-mono`}
                            />
                        </div>
                    ))}
                </div>
                <div className="mt-3 flex gap-2">
                    <input
                        type="text"
                        value={batchReason}
                        onChange={e => setBatchReason(e.target.value)}
                        placeholder="Motivo obligatorio para el lote de precios..."
                        className={`${field} flex-1`}
                    />
                    <button type="button" onClick={handlePreviewBatch} className={secondaryButton}>
                        Generar vista previa
                    </button>
                    {batchPreview?.can_commit && (
                        <button type="button" onClick={handleApplyBatch} className={button}>
                            Confirmar lote ({batchPreview.valid_rows} válidos)
                        </button>
                    )}
                </div>
                {batchError && <p className="mt-2 text-xs font-semibold text-red-600">{batchError}</p>}
            </div>
        )}

        {/* Historial reciente de precios */}
        <div className={card}>
            <h3 className="text-sm font-bold">Bitácora inmutable de modificaciones de precios ({branch.code})</h3>
            <div className="mt-3 overflow-x-auto">
                <table className="w-full text-left text-xs">
                    <thead>
                        <tr className="border-b border-slate-200 text-slate-500">
                            <th className="py-1.5">Fecha</th>
                            <th className="py-1.5">Autoparte</th>
                            <th className="py-1.5 text-right">Precio Anterior</th>
                            <th className="py-1.5 text-right">Precio Nuevo</th>
                            <th className="py-1.5">Disponibilidad</th>
                            <th className="py-1.5">Motivo</th>
                            <th className="py-1.5">Usuario</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {recentHistory.map(h => (
                            <tr key={h.id}>
                                <td className="py-1.5 font-mono">{h.created_at}</td>
                                <td className="py-1.5 font-mono">{h.part?.item_number} — {h.part?.description}</td>
                                <td className="py-1.5 text-right font-mono">{h.previous_price_mxn ? `$${h.previous_price_mxn}` : '—'}</td>
                                <td className="py-1.5 text-right font-mono font-bold">{h.new_price_mxn ? `$${h.new_price_mxn}` : '—'}</td>
                                <td className="py-1.5">{h.new_is_sellable ? 'Vendible' : 'No vendible'}</td>
                                <td className="py-1.5">{h.reason}</td>
                                <td className="py-1.5">{h.changed_by_name || '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    </Layout>
}
