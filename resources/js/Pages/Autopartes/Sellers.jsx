import { router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import { AUTOPARTES_CAPABILITIES, hasCapability } from '@/lib/autopartesCapabilities'
import Layout, { baseUrl, button, card, field, Pagination, secondaryButton } from './Layout'

export default function Sellers({ branch, capabilities = [], filters = {}, sellers, availableUsers = [] }) {
    const canManage = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.SELLERS_MANAGE)
    const [search, setSearch] = useState(filters.q || '')
    const [activeFilter, setActiveFilter] = useState(filters.active ?? '')
    const [editingSeller, setEditingSeller] = useState(null)

    const createForm = useForm({
        code: '',
        full_name: '',
        phone: '',
        user_id: '',
        notes: '',
        is_active: true,
    })

    const submitCreate = e => {
        e.preventDefault()
        createForm.post(`${baseUrl(branch)}/vendedores`, {
            onSuccess: () => createForm.reset(),
        })
    }

    const toggleSellerActive = seller => {
        router.patch(`${baseUrl(branch)}/vendedores/${seller.id}`, {
            is_active: !seller.is_active,
        })
    }

    return <Layout branch={branch} title={`Vendedores — ${branch.name}`}>
        {canManage && (
            <div className={card}>
                <h2 className="text-base font-bold">Registrar vendedor autorizado en {branch.name} ({branch.code})</h2>
                <p className="mt-1 text-xs text-slate-500">
                    El vendedor que atiende al cliente se registra por separado del cajero autenticado. Cada venta conserva una instantánea histórica del vendedor.
                </p>
                <form onSubmit={submitCreate} className="mt-4 grid gap-3 md:grid-cols-6">
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Código único *</label>
                        <input
                            type="text"
                            value={createForm.data.code}
                            onChange={e => createForm.setData('code', e.target.value)}
                            placeholder="VEND-HMO-01"
                            className={`${field} mt-1 w-full font-mono uppercase`}
                        />
                    </div>
                    <div className="md:col-span-2">
                        <label className="block text-xs font-medium text-slate-600">Nombre completo *</label>
                        <input
                            type="text"
                            value={createForm.data.full_name}
                            onChange={e => createForm.setData('full_name', e.target.value)}
                            placeholder="Nombre y apellidos"
                            className={`${field} mt-1 w-full`}
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Teléfono</label>
                        <input
                            type="text"
                            value={createForm.data.phone}
                            onChange={e => createForm.setData('phone', e.target.value)}
                            placeholder="662..."
                            className={`${field} mt-1 w-full`}
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Cuenta usuario (opcional)</label>
                        <select
                            value={createForm.data.user_id}
                            onChange={e => createForm.setData('user_id', e.target.value)}
                            className={`${field} mt-1 w-full`}
                        >
                            <option value="">Sin cuenta vinculada</option>
                            {availableUsers.map(u => (
                                <option key={u.id} value={u.id}>{u.name} ({u.email})</option>
                            ))}
                        </select>
                    </div>
                    <div className="flex items-end">
                        <button type="submit" disabled={createForm.processing} className={`${button} w-full`}>
                            Guardar vendedor
                        </button>
                    </div>
                </form>
            </div>
        )}

        <div className={card}>
            <form
                onSubmit={e => {
                    e.preventDefault()
                    router.get(`${baseUrl(branch)}/vendedores`, { q: search, active: activeFilter }, { preserveState: true })
                }}
                className="flex flex-wrap gap-3"
            >
                <input
                    type="text"
                    value={search}
                    onChange={e => setSearch(e.target.value)}
                    placeholder="Buscar por código o nombre..."
                    className={`${field} flex-1`}
                />
                <select value={activeFilter} onChange={e => setActiveFilter(e.target.value)} className={field}>
                    <option value="">Todos los estados</option>
                    <option value="1">Activos</option>
                    <option value="0">Inactivos</option>
                </select>
                <button type="submit" className={secondaryButton}>Filtrar</button>
            </form>

            <div className="mt-4 overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="border-b border-slate-200 text-xs uppercase text-slate-500">
                            <th className="py-2">Código</th>
                            <th className="py-2">Nombre completo</th>
                            <th className="py-2">Usuario vinculado</th>
                            <th className="py-2">Estado</th>
                            <th className="py-2 text-right">Ventas</th>
                            <th className="py-2 text-right">Venta Bruta</th>
                            <th className="py-2 text-right">Descuentos</th>
                            <th className="py-2 text-right">Venta Neta</th>
                            {canManage && <th className="py-2 text-right">Acciones</th>}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {(sellers?.data || []).map(s => (
                            <tr key={s.id}>
                                <td className="py-2.5 font-mono font-bold">{s.code}</td>
                                <td className="py-2.5">
                                    <div className="font-medium">{s.full_name}</div>
                                    {s.phone && <div className="text-xs text-slate-500">{s.phone}</div>}
                                </td>
                                <td className="py-2.5 text-xs text-slate-600">
                                    {s.user_name ? `${s.user_name} (${s.user_email})` : '—'}
                                </td>
                                <td className="py-2.5">
                                    <span className={`inline-block rounded px-2 py-0.5 text-xs font-semibold ${
                                        s.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700'
                                    }`}>
                                        {s.is_active ? 'Activo' : 'Inactivo'}
                                    </span>
                                </td>
                                <td className="py-2.5 text-right font-mono">{s.stats?.sales_count || 0}</td>
                                <td className="py-2.5 text-right font-mono">${s.stats?.gross_sales || '0.00'}</td>
                                <td className="py-2.5 text-right font-mono text-amber-700">-${s.stats?.total_discounts || '0.00'}</td>
                                <td className="py-2.5 text-right font-mono font-bold">${s.stats?.net_sales || '0.00'}</td>
                                {canManage && (
                                    <td className="py-2.5 text-right space-x-2">
                                        <button type="button" onClick={() => setEditingSeller(s)} className={secondaryButton}>
                                            Editar
                                        </button>
                                        <button type="button" onClick={() => toggleSellerActive(s)} className={secondaryButton}>
                                            {s.is_active ? 'Desactivar' : 'Activar'}
                                        </button>
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pagination data={sellers} />
        </div>

        {editingSeller && (
            <div className={card}>
                <h3 className="text-sm font-bold">Editar vendedor: {editingSeller.code}</h3>
                <form
                    onSubmit={e => {
                        e.preventDefault()
                        router.patch(`${baseUrl(branch)}/vendedores/${editingSeller.id}`, {
                            code: editingSeller.code,
                            full_name: editingSeller.full_name,
                            phone: editingSeller.phone || '',
                            user_id: editingSeller.user_id || '',
                            notes: editingSeller.notes || '',
                        }, {
                            onSuccess: () => setEditingSeller(null),
                        })
                    }}
                    className="mt-3 flex flex-wrap gap-3"
                >
                    <input
                        type="text"
                        value={editingSeller.code}
                        onChange={e => setEditingSeller({ ...editingSeller, code: e.target.value })}
                        className={`${field} font-mono uppercase`}
                    />
                    <input
                        type="text"
                        value={editingSeller.full_name}
                        onChange={e => setEditingSeller({ ...editingSeller, full_name: e.target.value })}
                        className={`${field} flex-1`}
                    />
                    <input
                        type="text"
                        value={editingSeller.phone || ''}
                        onChange={e => setEditingSeller({ ...editingSeller, phone: e.target.value })}
                        placeholder="Teléfono"
                        className={field}
                    />
                    <button type="submit" className={button}>Guardar cambios</button>
                    <button type="button" onClick={() => setEditingSeller(null)} className={secondaryButton}>Cancelar</button>
                </form>
            </div>
        )}
    </Layout>
}
