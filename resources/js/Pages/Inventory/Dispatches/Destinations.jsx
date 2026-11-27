import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'
import { formatDateTime } from '@/lib/inventoryPresentation'
import { OPERATION_TYPE_LABELS, operatingModeMeta } from '@/lib/warehouseDispatchScanner'

const DEST_TYPE_LABELS = {
    SALON: 'Salón de belleza',
    INTERNAL_AREA: 'Área interna',
    BRANCH: 'Sucursal',
    OTHER: 'Otro destino',
}

export default function Destinations({
    destinations = [],
    destinationTypes = [],
    operationTypes = [],
    operatingMode = 'RECORD_ONLY',
    capabilities = {},
}) {
    const [showModal, setShowModal] = useState(false)
    const [editing, setEditing] = useState(null)
    const [form, setForm] = useState({
        code: '',
        name: '',
        destination_type: 'SALON',
        contact_name: '',
        phone: '',
        address: '',
        default_operation_type: 'INTERNAL_CONSUMPTION',
        notes: '',
    })

    const modeMeta = operatingModeMeta(operatingMode)
    const canManage = Boolean(capabilities.can_manage_destinations) && operatingMode !== 'READ_ONLY'

    const openCreate = () => {
        setEditing(null)
        setForm({
            code: '',
            name: '',
            destination_type: 'SALON',
            contact_name: '',
            phone: '',
            address: '',
            default_operation_type: 'INTERNAL_CONSUMPTION',
            notes: '',
        })
        setShowModal(true)
    }

    const openEdit = (dest) => {
        setEditing(dest)
        setForm({
            code: dest.code || '',
            name: dest.name || '',
            destination_type: dest.destination_type || 'SALON',
            contact_name: dest.contact_name || '',
            phone: dest.phone || '',
            address: dest.address || '',
            default_operation_type: dest.default_operation_type || '',
            notes: dest.notes || '',
        })
        setShowModal(true)
    }

    const handleSave = (e) => {
        e.preventDefault()
        if (editing) {
            router.put(`/almacen/salidas/destinos/${editing.id}`, form, {
                onSuccess: () => setShowModal(false),
            })
        } else {
            router.post('/almacen/salidas/destinos', form, {
                onSuccess: () => setShowModal(false),
            })
        }
    }

    const handleToggle = (dest) => {
        router.patch(`/almacen/salidas/destinos/${dest.id}/estado`)
    }

    return (
        <AppShell title="Destinos y Salones">
            <Head title="Destinos y Salones — Salidas de Almacén" />

            <div className="space-y-4">
                <div className={`rounded-xl border px-4 py-2.5 text-xs ${modeMeta.badgeClass}`}>
                    <strong>{modeMeta.label}:</strong> {modeMeta.description}
                </div>

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <Link href="/almacen/salidas" className="text-xs font-semibold text-slate-500 hover:underline">
                            ← Volver a Salidas a Salones
                        </Link>
                        <h1 className="text-xl font-bold text-slate-900 dark:text-white">
                            Destinos y Salones de Belleza
                        </h1>
                    </div>
                    {canManage && (
                        <button
                            type="button"
                            onClick={openCreate}
                            className="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-bold text-white hover:bg-emerald-700"
                        >
                            + Nuevo destino / salón
                        </button>
                    )}
                </div>

                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-xs dark:divide-neutral-800">
                            <thead className="bg-slate-50 dark:bg-neutral-950">
                                <tr>
                                    <th className="px-3 py-2.5 text-left font-bold">Código</th>
                                    <th className="px-3 py-2.5 text-left font-bold">Nombre / Contacto</th>
                                    <th className="px-3 py-2.5 text-left font-bold">Tipo</th>
                                    <th className="px-3 py-2.5 text-left font-bold">Estado</th>
                                    <th className="px-3 py-2.5 text-right font-bold">Salidas confirmadas</th>
                                    <th className="px-3 py-2.5 text-right font-bold">Piezas entregadas</th>
                                    <th className="px-3 py-2.5 text-left font-bold">Última entrega</th>
                                    <th className="px-3 py-2.5 text-right font-bold">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                {destinations.length === 0 ? (
                                    <tr>
                                        <td colSpan={8} className="px-4 py-8 text-center text-slate-500">
                                            Aún no hay destinos registrados.
                                        </td>
                                    </tr>
                                ) : (
                                    destinations.map((dest) => (
                                        <tr key={dest.id}>
                                            <td className="px-3 py-2.5 font-mono font-bold">{dest.code}</td>
                                            <td className="px-3 py-2.5">
                                                <div className="font-semibold text-slate-900 dark:text-white">{dest.name}</div>
                                                <div className="text-[11px] text-slate-500">
                                                    {[dest.contact_name, dest.phone].filter(Boolean).join(' · ') || '—'}
                                                </div>
                                            </td>
                                            <td className="px-3 py-2.5">
                                                {DEST_TYPE_LABELS[dest.destination_type] || dest.destination_type}
                                            </td>
                                            <td className="px-3 py-2.5">
                                                <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${
                                                    dest.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600'
                                                }`}>
                                                    {dest.is_active ? 'Activo' : 'Inactivo'}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2.5 text-right font-bold">{dest.confirmed_dispatches_count}</td>
                                            <td className="px-3 py-2.5 text-right">{dest.confirmed_physical_units_sum}</td>
                                            <td className="px-3 py-2.5 text-slate-500">{formatDateTime(dest.last_dispatch_at)}</td>
                                            <td className="px-3 py-2.5 text-right">
                                                <div className="flex justify-end gap-2">
                                                    <Link
                                                        href={`/almacen/salidas?destination_id=${dest.id}`}
                                                        className="font-semibold text-sky-700 hover:underline"
                                                    >
                                                        Historial
                                                    </Link>
                                                    {canManage && (
                                                        <>
                                                            <button
                                                                type="button"
                                                                onClick={() => openEdit(dest)}
                                                                className="font-semibold text-slate-700 hover:underline"
                                                            >
                                                                Editar
                                                            </button>
                                                            <button
                                                                type="button"
                                                                onClick={() => handleToggle(dest)}
                                                                className="font-semibold text-amber-700 hover:underline"
                                                            >
                                                                {dest.is_active ? 'Desactivar' : 'Activar'}
                                                            </button>
                                                        </>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {showModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <form onSubmit={handleSave} className="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                        <h3 className="text-base font-bold">
                            {editing ? `Editar destino ${editing.code}` : 'Nuevo destino / salón'}
                        </h3>
                        <div className="mt-3 grid grid-cols-1 gap-2.5 text-xs sm:grid-cols-2">
                            <div>
                                <label className="font-bold">Código único *</label>
                                <input
                                    required
                                    type="text"
                                    value={form.code}
                                    onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })}
                                    placeholder="SALON-NORTE"
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                />
                            </div>
                            <div>
                                <label className="font-bold">Tipo *</label>
                                <select
                                    value={form.destination_type}
                                    onChange={(e) => setForm({ ...form, destination_type: e.target.value })}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                >
                                    {destinationTypes.map((t) => (
                                        <option key={t} value={t}>
                                            {DEST_TYPE_LABELS[t] || t}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="sm:col-span-2">
                                <label className="font-bold">Nombre *</label>
                                <input
                                    required
                                    type="text"
                                    value={form.name}
                                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                                    placeholder="Salón Sucursal Norte"
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                />
                            </div>
                            <div>
                                <label className="font-bold">Responsable / Contacto</label>
                                <input
                                    type="text"
                                    value={form.contact_name}
                                    onChange={(e) => setForm({ ...form, contact_name: e.target.value })}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                />
                            </div>
                            <div>
                                <label className="font-bold">Teléfono</label>
                                <input
                                    type="text"
                                    value={form.phone}
                                    onChange={(e) => setForm({ ...form, phone: e.target.value })}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                />
                            </div>
                            <div className="sm:col-span-2">
                                <label className="font-bold">Operación predeterminada</label>
                                <select
                                    value={form.default_operation_type}
                                    onChange={(e) => setForm({ ...form, default_operation_type: e.target.value })}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                >
                                    <option value="">Ninguna</option>
                                    {operationTypes.map((op) => (
                                        <option key={op} value={op}>
                                            {OPERATION_TYPE_LABELS[op] || op}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="sm:col-span-2">
                                <label className="font-bold">Notas</label>
                                <textarea
                                    rows={2}
                                    value={form.notes}
                                    onChange={(e) => setForm({ ...form, notes: e.target.value })}
                                    className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2"
                                />
                            </div>
                        </div>
                        <div className="mt-4 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setShowModal(false)}
                                className="rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold"
                            >
                                Cancelar
                            </button>
                            <button
                                type="submit"
                                className="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-bold text-white hover:bg-emerald-700"
                            >
                                Guardar
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </AppShell>
    )
}
