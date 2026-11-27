import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import axios from 'axios'
import AppShell from '@/Components/layout/AppShell'
import Pagination from '@/Components/ui/Pagination'
import { formatDateTime } from '@/lib/inventoryPresentation'
import { UOM_LABELS, operatingModeMeta } from '@/lib/warehouseDispatchScanner'

export default function ProvisionalIndex({
    provisionalItems,
    mergeTargets = [],
    filters = {},
    operatingMode = 'RECORD_ONLY',
    capabilities = {},
}) {
    const [search, setSearch] = useState(filters.search || '')
    const [status, setStatus] = useState(filters.status || 'pending')

    // Link modal state
    const [linkingItem, setLinkingItem] = useState(null)
    const [masterQuery, setMasterQuery] = useState('')
    const [masterCandidates, setMasterCandidates] = useState([])
    const [selectedMasterId, setSelectedMasterId] = useState('')
    const [linkNotes, setLinkNotes] = useState('')

    // Merge modal state
    const [mergingItem, setMergingItem] = useState(null)
    const [mergeTargetId, setMergeTargetId] = useState('')
    const [mergeReason, setMergeReason] = useState('')

    const modeMeta = operatingModeMeta(operatingMode)
    const isReadOnly = operatingMode === 'READ_ONLY'

    const applyFilter = (e) => {
        if (e) e.preventDefault()
        router.get('/almacen/salidas/provisionales', { search, status }, { preserveState: true, replace: true })
    }

    const searchMasterProducts = async (q) => {
        setMasterQuery(q)
        if (!q.trim()) {
            setMasterCandidates([])
            return
        }
        try {
            const { data } = await axios.get('/almacen/salidas/buscar-productos', {
                params: { q: q.trim(), mode: 'search' },
            })
            setMasterCandidates(data.registered || [])
        } catch {
            setMasterCandidates([])
        }
    }

    const submitLink = (e) => {
        e.preventDefault()
        if (!linkingItem || !selectedMasterId) return
        router.post(
            `/almacen/salidas/provisionales/${linkingItem.id}/vincular`,
            {
                inventory_product_id: Number(selectedMasterId),
                link_notes: linkNotes || null,
            },
            {
                onSuccess: () => {
                    setLinkingItem(null)
                    setSelectedMasterId('')
                    setLinkNotes('')
                    setMasterCandidates([])
                },
            },
        )
    }

    const submitMerge = (e) => {
        e.preventDefault()
        if (!mergingItem || !mergeTargetId) return
        router.post(
            `/almacen/salidas/provisionales/${mergingItem.id}/fusionar`,
            {
                target_provisional_item_id: Number(mergeTargetId),
                merge_reason: mergeReason,
            },
            {
                onSuccess: () => {
                    setMergingItem(null)
                    setMergeTargetId('')
                    setMergeReason('')
                },
            },
        )
    }

    return (
        <AppShell title="Productos Pendientes de Vincular">
            <Head title="Productos Provisionales — Salidas de Almacén" />

            <div className="space-y-4">
                <div className={`rounded-xl border px-4 py-2.5 text-xs ${modeMeta.badgeClass}`}>
                    <strong>{modeMeta.label}:</strong> {modeMeta.description}
                </div>

                <div>
                    <Link href="/almacen/salidas" className="text-xs font-semibold text-slate-500 hover:underline">
                        ← Volver a Salidas a Salones
                    </Link>
                    <h1 className="text-xl font-bold text-slate-900 dark:text-white">
                        Productos Provisionales Pendientes de Vincular
                    </h1>
                    <p className="text-xs text-slate-500">
                        Vincular una ficha provisional al catálogo maestro conserva los datos capturados originales y <strong>nunca</strong> descuenta inventario histórico ni dispara sincronizaciones a Mercado Libre.
                    </p>
                </div>

                <form onSubmit={applyFilter} className="flex flex-wrap gap-2 rounded-xl border border-slate-200 bg-white p-3 text-xs dark:border-neutral-800 dark:bg-neutral-900">
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Buscar por PROV-..., nombre, código, marca o tono..."
                        className="min-w-[240px] flex-1 rounded-lg border border-slate-300 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950"
                    />
                    <select
                        value={status}
                        onChange={(e) => setStatus(e.target.value)}
                        className="rounded-lg border border-slate-300 px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950"
                    >
                        <option value="pending">Pendientes de vincular</option>
                        <option value="LINKED">Vinculados</option>
                        <option value="MERGED">Fusionados</option>
                        <option value="all">Todos</option>
                    </select>
                    <button type="submit" className="rounded-lg bg-slate-900 px-4 py-2 font-bold text-white dark:bg-white dark:text-slate-900">
                        Filtrar
                    </button>
                </form>

                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-xs dark:divide-neutral-800">
                            <thead className="bg-slate-50 dark:bg-neutral-950">
                                <tr>
                                    <th className="px-3 py-2.5 text-left font-bold">Código</th>
                                    <th className="px-3 py-2.5 text-left font-bold">Producto provisional</th>
                                    <th className="px-3 py-2.5 text-left font-bold">Estado / Vínculo</th>
                                    <th className="px-3 py-2.5 text-right font-bold">Salidas</th>
                                    <th className="px-3 py-2.5 text-right font-bold">Piezas acumuladas</th>
                                    <th className="px-3 py-2.5 text-left font-bold">Última entrega</th>
                                    <th className="px-3 py-2.5 text-right font-bold">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                {(provisionalItems.data || []).length === 0 ? (
                                    <tr>
                                        <td colSpan={7} className="px-4 py-8 text-center text-slate-500">
                                            No hay fichas provisionales para este filtro.
                                        </td>
                                    </tr>
                                ) : (
                                    provisionalItems.data.map((item) => {
                                        const isPending = item.status === 'PENDING_LINK' || item.status === 'AMBIGUOUS_REVIEW'
                                        return (
                                            <tr key={item.id}>
                                                <td className="px-3 py-2.5 font-mono font-bold">{item.provisional_code}</td>
                                                <td className="px-3 py-2.5">
                                                    <div className="font-semibold text-slate-900 dark:text-white">{item.name}</div>
                                                    <div className="text-[11px] text-slate-500">
                                                        {[
                                                            item.barcode ? `CB: ${item.barcode}` : null,
                                                            item.known_sku ? `SKU: ${item.known_sku}` : null,
                                                            item.brand,
                                                            item.tone ? `Tono ${item.tone}` : null,
                                                            item.presentation,
                                                            UOM_LABELS[item.unit_of_measure] || item.unit_of_measure,
                                                        ].filter(Boolean).join(' · ')}
                                                    </div>
                                                </td>
                                                <td className="px-3 py-2.5">
                                                    <span className="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-700">
                                                        {item.status}
                                                    </span>
                                                    {item.linked_product && (
                                                        <div className="mt-0.5 text-[11px] text-emerald-700">
                                                            → {item.linked_product.sku} ({item.linked_product.name})
                                                        </div>
                                                    )}
                                                    {item.merged_into && (
                                                        <div className="mt-0.5 text-[11px] text-sky-700">
                                                            Fusionada en {item.merged_into.provisional_code}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2.5 text-right">{item.dispatches_count}</td>
                                                <td className="px-3 py-2.5 text-right">
                                                    <strong>{item.total_physical_units}</strong>
                                                    {item.unconverted_containers_qty > 0 && (
                                                        <div className="text-[10px] text-amber-700">
                                                            + {item.unconverted_containers_qty} cont. sin conv.
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2.5 text-slate-500">{formatDateTime(item.last_dispatched_at)}</td>
                                                <td className="px-3 py-2.5 text-right">
                                                    {isPending && !isReadOnly && (
                                                        <div className="flex justify-end gap-2">
                                                            {capabilities.can_link_provisional && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        setLinkingItem(item)
                                                                        setSelectedMasterId('')
                                                                        setLinkNotes('')
                                                                        searchMasterProducts(item.barcode || item.known_sku || item.tone || item.name)
                                                                    }}
                                                                    className="font-bold text-emerald-700 hover:underline"
                                                                >
                                                                    Vincular
                                                                </button>
                                                            )}
                                                            {capabilities.can_merge_provisional && (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        setMergingItem(item)
                                                                        setMergeTargetId('')
                                                                        setMergeReason('')
                                                                    }}
                                                                    className="font-bold text-sky-700 hover:underline"
                                                                >
                                                                    Fusionar
                                                                </button>
                                                            )}
                                                        </div>
                                                    )}
                                                </td>
                                            </tr>
                                        )
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>
                    {provisionalItems.links && <Pagination links={provisionalItems.links} />}
                </div>
            </div>

            {/* Link to Master Product Modal */}
            {linkingItem && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <form onSubmit={submitLink} className="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                        <h3 className="text-base font-bold">
                            Vincular {linkingItem.provisional_code} al catálogo maestro
                        </h3>
                        <p className="mt-1 rounded-lg bg-amber-50 p-2 text-xs text-amber-900">
                            <strong>Importante:</strong> Vincular NO descontará inventario histórico ni modificará Mercado Libre. Se conservarán los datos originales capturados en cada salida.
                        </p>
                        <div className="mt-3 space-y-2.5 text-xs">
                            <div>
                                <label className="font-bold">Buscar producto maestro SIMPLE</label>
                                <input
                                    type="text"
                                    value={masterQuery}
                                    onChange={(e) => searchMasterProducts(e.target.value)}
                                    placeholder="SKU, código, nombre o tono..."
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                />
                            </div>
                            <div className="max-h-48 space-y-1 overflow-y-auto rounded-lg border border-slate-200 p-2">
                                {masterCandidates.length === 0 ? (
                                    <div className="text-slate-500">Sin resultados. Escribe SKU o nombre del producto maestro.</div>
                                ) : (
                                    masterCandidates.map((p) => (
                                        <label key={p.inventory_product_id} className="flex cursor-pointer items-center gap-2 rounded p-1.5 hover:bg-slate-50">
                                            <input
                                                type="radio"
                                                name="master_product"
                                                value={p.inventory_product_id}
                                                checked={String(selectedMasterId) === String(p.inventory_product_id)}
                                                onChange={(e) => setSelectedMasterId(e.target.value)}
                                            />
                                            <span>
                                                <strong>{p.sku}</strong> · {p.name}
                                                {p.brand ? ` (${p.brand})` : ''}
                                            </span>
                                        </label>
                                    ))
                                )}
                            </div>
                            <div>
                                <label className="font-bold">Notas de vinculación (opcional)</label>
                                <input
                                    type="text"
                                    value={linkNotes}
                                    onChange={(e) => setLinkNotes(e.target.value)}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                />
                            </div>
                        </div>
                        <div className="mt-4 flex justify-end gap-2">
                            <button type="button" onClick={() => setLinkingItem(null)} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold">
                                Cancelar
                            </button>
                            <button type="submit" disabled={!selectedMasterId} className="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-bold text-white disabled:opacity-50">
                                Confirmar vinculación
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {/* Merge Modal */}
            {mergingItem && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <form onSubmit={submitMerge} className="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                        <h3 className="text-base font-bold">
                            Fusionar {mergingItem.provisional_code} en ficha canónica
                        </h3>
                        <p className="mt-1 text-xs text-slate-600">
                            Las salidas confirmadas conservarán su referencia original <strong>{mergingItem.provisional_code}</strong> y se agruparán mediante la ficha canónica destino.
                        </p>
                        <div className="mt-3 space-y-2.5 text-xs">
                            <div>
                                <label className="font-bold">Ficha destino canónica *</label>
                                <select
                                    required
                                    value={mergeTargetId}
                                    onChange={(e) => setMergeTargetId(e.target.value)}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                >
                                    <option value="">Selecciona ficha destino...</option>
                                    {mergeTargets
                                        .filter((t) => t.id !== mergingItem.id)
                                        .map((t) => (
                                            <option key={t.id} value={t.id}>
                                                {t.provisional_code} · {t.name} {t.tone ? `(Tono ${t.tone})` : ''} [{t.status}]
                                            </option>
                                        ))}
                                </select>
                            </div>
                            <div>
                                <label className="font-bold">Motivo de fusión *</label>
                                <textarea
                                    required
                                    minLength={5}
                                    rows={2}
                                    value={mergeReason}
                                    onChange={(e) => setMergeReason(e.target.value)}
                                    placeholder="Ej. Mismo tinte capturado dos veces sin código de barras..."
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                />
                            </div>
                        </div>
                        <div className="mt-4 flex justify-end gap-2">
                            <button type="button" onClick={() => setMergingItem(null)} className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold">
                                Cancelar
                            </button>
                            <button type="submit" className="rounded-lg bg-sky-600 px-4 py-2 text-xs font-bold text-white">
                                Fusionar fichas
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </AppShell>
    )
}
