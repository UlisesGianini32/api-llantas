import { router, useForm } from '@inertiajs/react'
import { AUTOPARTES_CAPABILITIES, hasCapability } from '@/lib/autopartesCapabilities'
import Layout, { baseUrl, button, card, field, secondaryButton } from './Layout'

export default function Discounts({
    branch,
    capabilities = [],
    rules = [],
    categories = [],
    approvals = [],
    commercialPolicy = {},
    manualDiscountPolicy = {},
}) {
    const canManage = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.DISCOUNTS_MANAGE)
    const canApprove = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.DISCOUNTS_APPROVE)

    const policyForm = useForm({
        pos_sales_enabled: Boolean(commercialPolicy?.pos_sales_enabled),
        fiscal_policy_validated: Boolean(commercialPolicy?.fiscal_policy_validated),
        fiscal_regime_notes: commercialPolicy?.fiscal_regime_notes || '',
        tax_enabled: Boolean(commercialPolicy?.tax_enabled),
        tax_rate: commercialPolicy?.tax_rate ?? '0.0000',
        manual_discounts_enabled: Boolean(commercialPolicy?.manual_discounts_enabled),
        cashier_max_discount_percent: commercialPolicy?.cashier_max_discount_percent ?? '0.00',
        cashier_max_discount_amount: commercialPolicy?.cashier_max_discount_amount ?? '0.00',
        default_thermal_width: commercialPolicy?.default_thermal_width || '80mm',
        reason: '',
    })

    const submitPolicy = e => {
        e.preventDefault()
        policyForm.patch(`${baseUrl(branch)}/descuentos/politica-comercial`, {
            onSuccess: () => policyForm.setData('reason', ''),
        })
    }

    const form = useForm({
        name: '',
        min_quantity: 5,
        max_quantity: '',
        discount_type: 'percentage',
        discount_value: '',
        scope_type: 'same_product',
        eligible_categories: [],
        starts_at: '',
        ends_at: '',
        priority: 100,
        stackable: false,
        allow_manual_combination: false,
        is_active: true,
        notes: '',
    })

    const submitCreate = e => {
        e.preventDefault()
        form.post(`${baseUrl(branch)}/descuentos`, {
            onSuccess: () => form.reset(),
        })
    }

    const toggleRuleActive = rule => {
        router.patch(`${baseUrl(branch)}/descuentos/${rule.id}`, {
            is_active: !rule.is_active,
        })
    }

    return <Layout branch={branch} title={`Descuentos por mayoreo — ${branch.name}`}>
        <div className={card}>
            <h2 className="text-base font-bold">Política de descuentos y seguridad comercial en {branch.name} ({branch.code})</h2>
            <p className="mt-1 text-xs text-slate-600 dark:text-neutral-300">
                <strong>DESCUENTOS — PENDIENTES DE CONFIGURACIÓN COMERCIAL:</strong> Inicialmente no existen reglas de mayoreo activas (0% automático) y los descuentos manuales permanecen{' '}
                <strong>{manualDiscountPolicy.manual_discounts_enabled ? 'HABILITADOS' : 'DESHABILITADOS'}</strong> hasta configurar capacidades y límites autorizados.
                Límite configurado para cajero: <strong>{manualDiscountPolicy.cashier_max_percent ?? 0}%</strong> o <strong>${manualDiscountPolicy.cashier_max_amount ?? 0} MXN</strong>.
            </p>
        </div>

        {canManage && (
            <div className={card}>
                <h3 className="text-sm font-bold">Configuración de política comercial, fiscal y límites de descuentos ({branch.code})</h3>
                <form onSubmit={submitPolicy} className="mt-3 grid gap-3 md:grid-cols-4 text-xs">
                    <label className="flex items-center gap-2 font-medium">
                        <input
                            type="checkbox"
                            checked={policyForm.data.fiscal_policy_validated}
                            onChange={e => policyForm.setData('fiscal_policy_validated', e.target.checked)}
                        />
                        Política fiscal validada
                    </label>
                    <label className="flex items-center gap-2 font-medium">
                        <input
                            type="checkbox"
                            checked={policyForm.data.pos_sales_enabled}
                            onChange={e => policyForm.setData('pos_sales_enabled', e.target.checked)}
                        />
                        Habilitar ventas reales en POS
                    </label>
                    <label className="flex items-center gap-2 font-medium">
                        <input
                            type="checkbox"
                            checked={policyForm.data.manual_discounts_enabled}
                            onChange={e => policyForm.setData('manual_discounts_enabled', e.target.checked)}
                        />
                        Habilitar descuentos manuales
                    </label>
                    <label className="flex items-center gap-2 font-medium">
                        <input
                            type="checkbox"
                            checked={policyForm.data.tax_enabled}
                            onChange={e => policyForm.setData('tax_enabled', e.target.checked)}
                        />
                        Desglosar impuesto en ventas
                    </label>
                    <div>
                        <label className="block font-medium text-slate-600">% Máx. descuento cajero</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            max="99.99"
                            value={policyForm.data.cashier_max_discount_percent}
                            onChange={e => policyForm.setData('cashier_max_discount_percent', e.target.value)}
                            className={`${field} mt-1 w-full font-mono`}
                        />
                    </div>
                    <div>
                        <label className="block font-medium text-slate-600">Monto máx. cajero ($ MXN)</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            value={policyForm.data.cashier_max_discount_amount}
                            onChange={e => policyForm.setData('cashier_max_discount_amount', e.target.value)}
                            className={`${field} mt-1 w-full font-mono`}
                        />
                    </div>
                    <div>
                        <label className="block font-medium text-slate-600">Tasa impuesto (ej. 0.1600)</label>
                        <input
                            type="number"
                            step="0.0001"
                            min="0"
                            max="1"
                            value={policyForm.data.tax_rate}
                            onChange={e => policyForm.setData('tax_rate', e.target.value)}
                            className={`${field} mt-1 w-full font-mono`}
                        />
                    </div>
                    <div>
                        <label className="block font-medium text-slate-600">Ancho térmico predeterminado</label>
                        <select
                            value={policyForm.data.default_thermal_width}
                            onChange={e => policyForm.setData('default_thermal_width', e.target.value)}
                            className={`${field} mt-1 w-full`}
                        >
                            <option value="80mm">80 mm</option>
                            <option value="58mm">58 mm</option>
                        </select>
                    </div>
                    <div className="md:col-span-3">
                        <label className="block font-medium text-slate-600">Motivo de actualización de política comercial *</label>
                        <input
                            type="text"
                            value={policyForm.data.reason}
                            onChange={e => policyForm.setData('reason', e.target.value)}
                            placeholder="Ej. Autorización de dirección comercial para límites de descuento..."
                            className={`${field} mt-1 w-full`}
                        />
                    </div>
                    <div className="flex items-end">
                        <button type="submit" disabled={policyForm.processing} className={`${button} w-full`}>
                            Guardar política comercial
                        </button>
                    </div>
                </form>
            </div>
        )}

        {canManage && (
            <div className={card}>
                <h3 className="text-sm font-bold">Crear regla de descuento por mayoreo ({branch.code})</h3>
                <form onSubmit={submitCreate} className="mt-3 grid gap-3 md:grid-cols-4">
                    <div className="md:col-span-2">
                        <label className="block text-xs font-medium text-slate-600">Nombre de la regla *</label>
                        <input
                            type="text"
                            value={form.data.name}
                            onChange={e => form.setData('name', e.target.value)}
                            placeholder="Ej. Mayoreo Taller Nivel 1"
                            className={`${field} mt-1 w-full`}
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Alcance de cantidad *</label>
                        <select value={form.data.scope_type} onChange={e => form.setData('scope_type', e.target.value)} className={`${field} mt-1 w-full`}>
                            <option value="same_product">Unidades del mismo producto</option>
                            <option value="category">Unidades por categoría</option>
                            <option value="eligible_cart_total">Total de unidades elegibles del carrito</option>
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Prioridad (menor = primero)</label>
                        <input
                            type="number"
                            value={form.data.priority}
                            onChange={e => form.setData('priority', e.target.value)}
                            className={`${field} mt-1 w-full font-mono`}
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Cantidad mínima *</label>
                        <input
                            type="number"
                            min="1"
                            value={form.data.min_quantity}
                            onChange={e => form.setData('min_quantity', e.target.value)}
                            className={`${field} mt-1 w-full font-mono`}
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Cantidad máxima (opcional)</label>
                        <input
                            type="number"
                            min="1"
                            value={form.data.max_quantity}
                            onChange={e => form.setData('max_quantity', e.target.value)}
                            placeholder="Sin tope"
                            className={`${field} mt-1 w-full font-mono`}
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Tipo de descuento *</label>
                        <select value={form.data.discount_type} onChange={e => form.setData('discount_type', e.target.value)} className={`${field} mt-1 w-full`}>
                            <option value="percentage">Porcentaje (%)</option>
                            <option value="fixed_amount">Importe fijo ($ MXN)</option>
                        </select>
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Valor de descuento *</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0.01"
                            value={form.data.discount_value}
                            onChange={e => form.setData('discount_value', e.target.value)}
                            className={`${field} mt-1 w-full font-mono`}
                        />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Vigente desde</label>
                        <input type="date" value={form.data.starts_at} onChange={e => form.setData('starts_at', e.target.value)} className={`${field} mt-1 w-full`} />
                    </div>
                    <div>
                        <label className="block text-xs font-medium text-slate-600">Vence el</label>
                        <input type="date" value={form.data.ends_at} onChange={e => form.setData('ends_at', e.target.value)} className={`${field} mt-1 w-full`} />
                    </div>
                    <div className="flex items-center gap-4 pt-5 md:col-span-2">
                        <label className="flex items-center gap-1.5 text-xs">
                            <input type="checkbox" checked={form.data.stackable} onChange={e => form.setData('stackable', e.target.checked)} />
                            Acumulable con otras reglas
                        </label>
                        <label className="flex items-center gap-1.5 text-xs">
                            <input
                                type="checkbox"
                                checked={form.data.allow_manual_combination}
                                onChange={e => form.setData('allow_manual_combination', e.target.checked)}
                            />
                            Permitir combinar con desc. manual
                        </label>
                        <button type="submit" disabled={form.processing} className={button}>
                            Guardar regla
                        </button>
                    </div>
                </form>
            </div>
        )}

        <div className={card}>
            <h3 className="text-sm font-bold">Reglas de descuento por mayoreo configuradas ({rules.length})</h3>
            {rules.length === 0 ? (
                <p className="mt-3 text-xs text-slate-500">
                    No hay reglas de mayoreo registradas en {branch.name}. El sistema aplica 0% de descuento automático por defecto.
                </p>
            ) : (
                <div className="mt-3 overflow-x-auto">
                    <table className="w-full text-left text-xs">
                        <thead>
                            <tr className="border-b border-slate-200 uppercase text-slate-500">
                                <th className="py-2">Prioridad</th>
                                <th className="py-2">Nombre</th>
                                <th className="py-2">Alcance</th>
                                <th className="py-2">Rango unidades</th>
                                <th className="py-2">Beneficio</th>
                                <th className="py-2">Combinación</th>
                                <th className="py-2">Estado</th>
                                {canManage && <th className="py-2 text-right">Acciones</th>}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rules.map(r => (
                                <tr key={r.id}>
                                    <td className="py-2 font-mono">{r.priority}</td>
                                    <td className="py-2 font-semibold">{r.name}</td>
                                    <td className="py-2">{r.scope_type}</td>
                                    <td className="py-2 font-mono">{r.min_quantity} a {r.max_quantity ?? '∞'} pzs</td>
                                    <td className="py-2 font-mono font-bold text-emerald-700">
                                        {r.discount_type === 'percentage' ? `${r.discount_value}%` : `$${r.discount_value} MXN`}
                                    </td>
                                    <td className="py-2">
                                        {r.allow_manual_combination ? 'Permite manual' : 'Bloquea manual'}
                                    </td>
                                    <td className="py-2">
                                        <span className={`inline-block rounded px-2 py-0.5 font-semibold ${
                                            r.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700'
                                        }`}>
                                            {r.is_active ? 'Activa' : 'Inactiva'}
                                        </span>
                                    </td>
                                    {canManage && (
                                        <td className="py-2 text-right">
                                            <button type="button" onClick={() => toggleRuleActive(r)} className={secondaryButton}>
                                                {r.is_active ? 'Desactivar' : 'Activar'}
                                            </button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>

        {canApprove && (
            <div className={card}>
                <h3 className="text-sm font-bold">Solicitudes de descuento manual en punto de venta</h3>
                <div className="mt-3 overflow-x-auto">
                    <table className="w-full text-left text-xs">
                        <thead>
                            <tr className="border-b border-slate-200 uppercase text-slate-500">
                                <th className="py-2">ID / Token</th>
                                <th className="py-2">Solicitante</th>
                                <th className="py-2">Descuento</th>
                                <th className="py-2">Motivo</th>
                                <th className="py-2">Estado</th>
                                <th className="py-2 text-right">Acción</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {approvals.map(a => (
                                <tr key={a.id}>
                                    <td className="py-2 font-mono">{a.token}</td>
                                    <td className="py-2">{a.requested_by_name || '—'}</td>
                                    <td className="py-2 font-mono font-bold">
                                        ${a.computed_discount_amount} MXN ({Number(a.discount_percentage_effective).toFixed(2)}%)
                                    </td>
                                    <td className="py-2">{a.reason}</td>
                                    <td className="py-2 font-semibold">{a.status}</td>
                                    <td className="py-2 text-right">
                                        {a.status === 'pending' && (
                                            <button
                                                type="button"
                                                onClick={() => router.post(`${baseUrl(branch)}/pos/descuento-manual/${a.id}/aprobar`)}
                                                className={button}
                                            >
                                                Aprobar descuento
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        )}
    </Layout>
}
