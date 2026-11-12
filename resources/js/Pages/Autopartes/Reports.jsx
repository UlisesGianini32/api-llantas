import { router } from '@inertiajs/react'
import { useState } from 'react'
import { AUTOPARTES_CAPABILITIES, hasCapability } from '@/lib/autopartesCapabilities'
import Layout, { baseUrl, button, card, field, secondaryButton } from './Layout'

export default function Reports({ branch, capabilities = [], filters = {}, report = {}, sellers = [] }) {
    const canExport = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.REPORTS_EXPORT)
    const [dateFrom, setDateFrom] = useState(filters.date_from || '')
    const [dateTo, setDateTo] = useState(filters.date_to || '')
    const [sellerId, setSellerId] = useState(filters.seller_id || '')

    const summary = report.summary || {}
    const bySeller = report.by_seller || []
    const byPaymentMethod = report.by_payment_method || []
    const byDay = report.by_day || []

    const exportHref = `${baseUrl(branch)}/reportes/exportar?date_from=${encodeURIComponent(dateFrom)}&date_to=${encodeURIComponent(dateTo)}&seller_id=${encodeURIComponent(sellerId)}`

    return <Layout branch={branch} title={`Reportes por vendedor — ${branch.name}`}>
        <div className={card}>
            <form
                onSubmit={e => {
                    e.preventDefault()
                    router.get(`${baseUrl(branch)}/reportes`, {
                        date_from: dateFrom,
                        date_to: dateTo,
                        seller_id: sellerId,
                    }, { preserveState: true })
                }}
                className="flex flex-wrap items-end gap-3"
            >
                <div>
                    <label className="block text-xs font-medium text-slate-600">Desde</label>
                    <input type="date" value={dateFrom} onChange={e => setDateFrom(e.target.value)} className={field} />
                </div>
                <div>
                    <label className="block text-xs font-medium text-slate-600">Hasta</label>
                    <input type="date" value={dateTo} onChange={e => setDateTo(e.target.value)} className={field} />
                </div>
                <div className="flex-1">
                    <label className="block text-xs font-medium text-slate-600">Vendedor ({branch.code})</label>
                    <select value={sellerId} onChange={e => setSellerId(e.target.value)} className={`${field} w-full`}>
                        <option value="">Todos los vendedores de {branch.name}</option>
                        {sellers.map(s => <option key={s.id} value={s.id}>{s.code} — {s.full_name}</option>)}
                    </select>
                </div>
                <button type="submit" className={button}>Consultar reporte</button>
                {canExport && (
                    <a href={exportHref} className={secondaryButton}>
                        Exportar CSV autorizado
                    </a>
                )}
            </form>
        </div>

        {/* Tarjetas de resumen exclusivo de Hermosillo */}
        <div className="grid gap-4 md:grid-cols-5">
            <div className={card}>
                <p className="text-xs font-semibold uppercase text-slate-500">Unidades Netas Vendidas</p>
                <p className="mt-1 font-mono text-2xl font-bold">{summary.net_units ?? 0}</p>
                <p className="text-xs text-slate-500">Vendidas: {summary.units_sold ?? 0} · Devueltas: {summary.units_returned ?? 0}</p>
            </div>
            <div className={card}>
                <p className="text-xs font-semibold uppercase text-slate-500">Venta Bruta ({branch.code})</p>
                <p className="mt-1 font-mono text-2xl font-bold">${summary.gross_sales ?? '0.00'}</p>
                <p className="text-xs text-slate-500">{summary.sales_count ?? 0} operaciones activas</p>
            </div>
            <div className={card}>
                <p className="text-xs font-semibold uppercase text-slate-500">Descuentos Concedidos</p>
                <p className="mt-1 font-mono text-2xl font-bold text-amber-700">-${summary.total_discounts ?? '0.00'}</p>
                <p className="text-xs text-slate-500">Mayoreo: -${summary.wholesale_discounts ?? '0.00'} · Manual: -${summary.manual_discounts ?? '0.00'}</p>
            </div>
            <div className={card}>
                <p className="text-xs font-semibold uppercase text-slate-500">Cancelaciones / Reembolsos</p>
                <p className="mt-1 font-mono text-2xl font-bold text-red-600">-${summary.refunded_amount ?? '0.00'}</p>
                <p className="text-xs text-slate-500">Canceladas: {summary.cancelled_count ?? 0} · Con dev.: {summary.returned_count ?? 0}</p>
            </div>
            <div className={card}>
                <p className="text-xs font-semibold uppercase text-slate-500">Venta Neta ({branch.code})</p>
                <p className="mt-1 font-mono text-2xl font-bold text-emerald-700">${summary.net_sales ?? '0.00'} MXN</p>
                <p className="text-xs text-slate-500">Exclusivo Autopartes {branch.name}</p>
            </div>
        </div>

        {/* Desglose por vendedor */}
        <div className={card}>
            <h3 className="text-sm font-bold">Desempeño por vendedor ({branch.name})</h3>
            <div className="mt-3 overflow-x-auto">
                <table className="w-full text-left text-xs">
                    <thead>
                        <tr className="border-b border-slate-200 uppercase text-slate-500">
                            <th className="py-2">Código</th>
                            <th className="py-2">Vendedor</th>
                            <th className="py-2 text-right">Ventas</th>
                            <th className="py-2 text-right">Canceladas</th>
                            <th className="py-2 text-right">Unidades Netas</th>
                            <th className="py-2 text-right">Venta Bruta</th>
                            <th className="py-2 text-right">Desc. Mayoreo</th>
                            <th className="py-2 text-right">Desc. Manual</th>
                            <th className="py-2 text-right">Reembolsos</th>
                            <th className="py-2 text-right">Venta Neta MXN</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {bySeller.map(row => (
                            <tr key={row.seller_id}>
                                <td className="py-2 font-mono font-bold">{row.seller_code}</td>
                                <td className="py-2 font-medium">{row.seller_name}</td>
                                <td className="py-2 text-right font-mono">{row.sales_count}</td>
                                <td className="py-2 text-right font-mono text-red-600">{row.cancelled_count}</td>
                                <td className="py-2 text-right font-mono">{row.net_units} ({row.units_sold} - {row.units_returned})</td>
                                <td className="py-2 text-right font-mono">${row.gross_sales}</td>
                                <td className="py-2 text-right font-mono text-amber-700">-${row.wholesale_discounts}</td>
                                <td className="py-2 text-right font-mono text-amber-700">-${row.manual_discounts}</td>
                                <td className="py-2 text-right font-mono text-red-600">-${row.refunded_amount}</td>
                                <td className="py-2 text-right font-mono font-bold text-emerald-700">${row.net_sales}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>

        {/* Desglose por método de pago y por día */}
        <div className="grid gap-4 md:grid-cols-2">
            <div className={card}>
                <h3 className="text-sm font-bold">Cobros por método de pago</h3>
                <div className="mt-3 space-y-2 text-xs">
                    {byPaymentMethod.map(m => (
                        <div key={m.method} className="flex justify-between border-b border-slate-100 pb-1.5">
                            <span className="font-medium">{m.label} ({m.count} cobros)</span>
                            <span className="font-mono font-bold">${m.amount} MXN</span>
                        </div>
                    ))}
                </div>
            </div>
            <div className={card}>
                <h3 className="text-sm font-bold">Ventas por día ({branch.code})</h3>
                <div className="mt-3 space-y-2 text-xs">
                    {byDay.map(d => (
                        <div key={d.date} className="flex justify-between border-b border-slate-100 pb-1.5">
                            <span className="font-mono">{d.date} ({d.sales_count} ventas, {d.units_sold} u.)</span>
                            <span className="font-mono font-bold text-emerald-700">${d.net_sales} MXN</span>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    </Layout>
}
