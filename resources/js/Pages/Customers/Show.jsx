import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

export default function CustomersShow({
    customer = {},
    total_debt = 0,
    has_overdue = false,
    available_credit = 0,
}) {
    const [paymentModalOpen, setPaymentModalOpen] = useState(false)
    const [selectedSaleId, setSelectedSaleId] = useState(null)
    const [paymentAmount, setPaymentAmount] = useState('')
    const [paymentMethod, setPaymentMethod] = useState('cash')
    const [paymentNotes, setPaymentNotes] = useState('')
    const [submittingPayment, setSubmittingPayment] = useState(false)

    // Edit customer modal
    const [editModalOpen, setEditModalOpen] = useState(false)
    const [formCustomer, setFormCustomer] = useState({
        name: customer.name || '',
        business_name: customer.business_name || '',
        phone: customer.phone || '',
        email: customer.email || '',
        address: customer.address || '',
        credit_limit: customer.credit_limit ? String(customer.credit_limit) : '5000',
        credit_days_default: customer.credit_days_default || 15,
        notes: customer.notes || '',
    })

    const openPaymentForSale = (sale) => {
        setSelectedSaleId(sale.id)
        setPaymentAmount(String(sale.balance_due))
        setPaymentMethod('cash')
        setPaymentNotes('')
        setPaymentModalOpen(true)
    }

    const openGeneralPayment = () => {
        setSelectedSaleId(null)
        setPaymentAmount(total_debt > 0 ? String(total_debt) : '')
        setPaymentMethod('cash')
        setPaymentNotes('')
        setPaymentModalOpen(true)
    }

    const handleSavePayment = (e) => {
        e.preventDefault()
        if (!paymentAmount || Number(paymentAmount) <= 0) return

        setSubmittingPayment(true)
        router.post(
            `/pos/clientes/${customer.id}/pagos`,
            {
                pos_sale_id: selectedSaleId,
                amount: Number(paymentAmount),
                payment_method: paymentMethod,
                notes: paymentNotes,
            },
            {
                onSuccess: () => {
                    setPaymentModalOpen(false)
                    setSubmittingPayment(false)
                },
                onError: () => {
                    setSubmittingPayment(false)
                },
            }
        )
    }

    const handleUpdateCustomer = (e) => {
        e.preventDefault()
        router.put(`/pos/clientes/${customer.id}`, formCustomer, {
            onSuccess: () => setEditModalOpen(false),
        })
    }

    const creditSales = customer.sales?.filter((s) => s.payment_method === 'credit') || []
    const otherSales = customer.sales?.filter((s) => s.payment_method !== 'credit') || []

    return (
        <AppShell>
            <Head title={`Cliente - ${customer.name}`} />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-6">
                {/* BACK BUTTON & TOP BAR */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Link
                        href="/pos/clientes"
                        className="inline-flex items-center gap-1.5 text-xs font-bold text-slate-600 hover:text-indigo-600 dark:text-slate-400 dark:hover:text-indigo-400"
                    >
                        ← Volver a Cartera de Clientes
                    </Link>
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={() => setEditModalOpen(true)}
                            className="rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-300"
                        >
                            ✏️ Editar Datos
                        </button>
                        {total_debt > 0 && (
                            <button
                                type="button"
                                onClick={openGeneralPayment}
                                className="rounded-xl bg-emerald-600 px-4 py-1.5 text-xs font-bold text-white shadow hover:bg-emerald-700 transition"
                            >
                                💵 Registrar Abono
                            </button>
                        )}
                    </div>
                </div>

                {/* CUSTOMER PROFILE CARD */}
                <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-2xl font-extrabold text-slate-900 dark:text-white">
                                    {customer.name}
                                </h1>
                                {total_debt <= 0 ? (
                                    <span className="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                                        ✓ Al día / Sin Deuda
                                    </span>
                                ) : has_overdue ? (
                                    <span className="rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-bold text-red-800 dark:bg-red-950 dark:text-red-300 animate-pulse">
                                        ⚠️ Crédito Vencido
                                    </span>
                                ) : (
                                    <span className="rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-bold text-blue-800 dark:bg-blue-950 dark:text-blue-300">
                                        🟢 Al Corriente
                                    </span>
                                )}
                            </div>
                            {customer.business_name && (
                                <p className="text-sm font-semibold text-indigo-600 dark:text-indigo-400 mt-0.5">
                                    🏪 {customer.business_name}
                                </p>
                            )}

                            <div className="mt-3 flex flex-wrap gap-4 text-xs text-slate-600 dark:text-slate-400">
                                {customer.phone && (
                                    <span>
                                        📞 <strong>{customer.phone}</strong>
                                    </span>
                                )}
                                {customer.email && (
                                    <span>
                                        ✉️ <strong>{customer.email}</strong>
                                    </span>
                                )}
                                {customer.address && (
                                    <span>
                                        📍 <strong>{customer.address}</strong>
                                    </span>
                                )}
                            </div>
                        </div>

                        {/* FINANCIAL METRICS PILLS */}
                        <div className="grid grid-cols-3 gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs dark:border-neutral-800 dark:bg-neutral-950">
                            <div>
                                <span className="block text-[10px] text-slate-400 uppercase font-bold">Límite Autorizado</span>
                                <span className="font-mono text-sm font-extrabold text-slate-800 dark:text-slate-200">
                                    ${Number(customer.credit_limit).toFixed(2)}
                                </span>
                            </div>
                            <div>
                                <span className="block text-[10px] text-slate-400 uppercase font-bold">Saldo Deudor</span>
                                <span className={`font-mono text-sm font-extrabold ${total_debt > 0 ? (has_overdue ? 'text-red-600' : 'text-indigo-600') : 'text-emerald-600'}`}>
                                    ${Number(total_debt).toFixed(2)}
                                </span>
                            </div>
                            <div>
                                <span className="block text-[10px] text-slate-400 uppercase font-bold">Crédito Disponible</span>
                                <span className="font-mono text-sm font-extrabold text-emerald-600">
                                    ${Number(available_credit).toFixed(2)}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                {/* ACTIVE CREDIT SALES TABLE */}
                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900 space-y-4">
                    <div className="flex items-center justify-between border-b pb-3 dark:border-neutral-800">
                        <h2 className="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>💳</span> Notas de Venta a Crédito ({creditSales.length})
                        </h2>
                    </div>

                    {creditSales.length === 0 ? (
                        <p className="py-8 text-center text-xs text-slate-400">
                            Este cliente no tiene notas de venta a crédito registradas.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead className="border-b bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:border-neutral-800 dark:bg-neutral-950 dark:text-slate-400">
                                    <tr>
                                        <th className="p-3">Folio</th>
                                        <th className="p-3">Fecha</th>
                                        <th className="p-3">Plazo</th>
                                        <th className="p-3">Vencimiento</th>
                                        <th className="p-3 text-right">Total</th>
                                        <th className="p-3 text-right">Abonado</th>
                                        <th className="p-3 text-right">Saldo Deudor</th>
                                        <th className="p-3 text-center">Estado</th>
                                        <th className="p-3 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                    {creditSales.map((sale) => {
                                        const isOverdue = sale.balance_due > 0 && sale.credit_due_date && new Date(sale.credit_due_date) < new Date(new Date().setHours(0,0,0,0))
                                        return (
                                            <tr key={sale.id} className="hover:bg-slate-50/60 dark:hover:bg-neutral-800/40">
                                                <td className="p-3 font-mono font-bold text-indigo-600 dark:text-indigo-400">
                                                    {sale.sale_number}
                                                </td>
                                                <td className="p-3 text-slate-600 dark:text-slate-400">
                                                    {new Date(sale.created_at).toLocaleDateString('es-MX')}
                                                </td>
                                                <td className="p-3 text-slate-700 dark:text-slate-300 font-semibold">
                                                    {sale.credit_days || 15} días
                                                </td>
                                                <td className="p-3">
                                                    <span className={isOverdue ? 'font-bold text-red-600' : 'text-slate-700 dark:text-slate-300'}>
                                                        {sale.credit_due_date ? new Date(sale.credit_due_date).toLocaleDateString('es-MX') : '—'}
                                                    </span>
                                                </td>
                                                <td className="p-3 text-right font-mono font-medium">
                                                    ${Number(sale.total).toFixed(2)}
                                                </td>
                                                <td className="p-3 text-right font-mono text-emerald-600">
                                                    ${Number(sale.amount_paid || 0).toFixed(2)}
                                                </td>
                                                <td className="p-3 text-right font-mono font-bold">
                                                    <span className={sale.balance_due > 0 ? (isOverdue ? 'text-red-600' : 'text-slate-900 dark:text-white') : 'text-slate-400'}>
                                                        ${Number(sale.balance_due).toFixed(2)}
                                                    </span>
                                                </td>
                                                <td className="p-3 text-center">
                                                    {sale.balance_due <= 0 ? (
                                                        <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                                                            Liquidada
                                                        </span>
                                                    ) : isOverdue ? (
                                                        <span className="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-bold text-red-800 dark:bg-red-950 dark:text-red-300">
                                                            Vencida
                                                        </span>
                                                    ) : (
                                                        <span className="rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-bold text-blue-800 dark:bg-blue-950 dark:text-blue-300">
                                                            Pendiente
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="p-3 text-right">
                                                    <div className="flex items-center justify-end gap-1.5">
                                                        <a
                                                            href={`/pos/sales/${sale.id}/voucher`}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="rounded-lg border border-slate-300 px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                                                        >
                                                            📄 Pagaré / PDF
                                                        </a>
                                                        {sale.balance_due > 0 && (
                                                            <button
                                                                type="button"
                                                                onClick={() => openPaymentForSale(sale)}
                                                                className="rounded-lg bg-emerald-600 px-2 py-1 text-[11px] font-bold text-white hover:bg-emerald-700"
                                                            >
                                                                💵 Abonar
                                                            </button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        )
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                {/* PAYMENT HISTORY (ABONOS) TABLE */}
                <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900 space-y-4">
                    <div className="flex items-center justify-between border-b pb-3 dark:border-neutral-800">
                        <h2 className="text-sm font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>🧾</span> Historial de Abonos y Pagos ({customer.payments?.length || 0})
                        </h2>
                    </div>

                    {!customer.payments || customer.payments.length === 0 ? (
                        <p className="py-6 text-center text-xs text-slate-400">
                            No hay abonos registrados para este cliente aún.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead className="border-b bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:border-neutral-800 dark:bg-neutral-950 dark:text-slate-400">
                                    <tr>
                                        <th className="p-3">Recibo / Folio</th>
                                        <th className="p-3">Fecha y Hora</th>
                                        <th className="p-3">Forma de Pago</th>
                                        <th className="p-3">Aplicado a Nota</th>
                                        <th className="p-3 text-right">Monto Abonado</th>
                                        <th className="p-3">Recibió</th>
                                        <th className="p-3">Notas</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                    {customer.payments.map((pay) => (
                                        <tr key={pay.id} className="hover:bg-slate-50/60 dark:hover:bg-neutral-800/40">
                                            <td className="p-3 font-mono font-bold text-slate-900 dark:text-white">
                                                {pay.receipt_number || `REC-${pay.id}`}
                                            </td>
                                            <td className="p-3 text-slate-600 dark:text-slate-400">
                                                {new Date(pay.payment_date || pay.created_at).toLocaleString('es-MX')}
                                            </td>
                                            <td className="p-3">
                                                <span className="rounded-md bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700 dark:bg-neutral-800 dark:text-slate-300">
                                                    {pay.payment_method === 'cash' ? '💵 Efectivo' : pay.payment_method === 'card' ? '💳 Tarjeta' : '📱 Transferencia'}
                                                </span>
                                            </td>
                                            <td className="p-3 font-mono text-indigo-600 dark:text-indigo-400">
                                                {pay.sale?.sale_number || 'Cuenta general'}
                                            </td>
                                            <td className="p-3 text-right font-mono font-extrabold text-emerald-600">
                                                +${Number(pay.amount).toFixed(2)}
                                            </td>
                                            <td className="p-3 text-slate-600 dark:text-slate-400">
                                                {pay.user?.name || 'Caja'}
                                            </td>
                                            <td className="p-3 text-slate-500 italic max-w-xs truncate">
                                                {pay.notes || '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>

            {/* MODAL: REGISTRAR ABONO */}
            {paymentModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl dark:bg-neutral-900">
                        <div className="flex items-center justify-between border-b pb-3 dark:border-neutral-800">
                            <div>
                                <h3 className="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                    <span>💵</span> Registrar Abono
                                </h3>
                                <p className="text-xs text-slate-500">Cliente: <strong>{customer.name}</strong></p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setPaymentModalOpen(false)}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                ✕
                            </button>
                        </div>

                        <form onSubmit={handleSavePayment} className="mt-4 space-y-3 text-xs">
                            <div>
                                <label className="block font-bold text-slate-700 dark:text-slate-300">
                                    Monto a abonar ($) *:
                                </label>
                                <div className="mt-1 flex items-center rounded-xl border border-slate-300 bg-white px-3 py-2 font-mono text-base font-bold dark:border-neutral-700 dark:bg-neutral-950 dark:text-white">
                                    <span className="text-slate-400 mr-1">$</span>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        required
                                        placeholder="0.00"
                                        value={paymentAmount}
                                        onChange={(e) => setPaymentAmount(e.target.value)}
                                        className="w-full bg-transparent outline-none"
                                    />
                                </div>
                            </div>

                            <div>
                                <label className="block font-semibold text-slate-700 dark:text-slate-300">
                                    Método de pago:
                                </label>
                                <div className="mt-1 grid grid-cols-3 gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setPaymentMethod('cash')}
                                        className={`rounded-xl border p-2 text-center font-bold transition ${
                                            paymentMethod === 'cash'
                                                ? 'border-indigo-600 bg-indigo-50 text-indigo-700 dark:border-indigo-500 dark:bg-indigo-950/40 dark:text-indigo-300'
                                                : 'border-slate-200 text-slate-600 dark:border-neutral-800'
                                        }`}
                                    >
                                        💵 Efectivo
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setPaymentMethod('card')}
                                        className={`rounded-xl border p-2 text-center font-bold transition ${
                                            paymentMethod === 'card'
                                                ? 'border-indigo-600 bg-indigo-50 text-indigo-700 dark:border-indigo-500 dark:bg-indigo-950/40 dark:text-indigo-300'
                                                : 'border-slate-200 text-slate-600 dark:border-neutral-800'
                                        }`}
                                    >
                                        💳 Tarjeta
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setPaymentMethod('transfer')}
                                        className={`rounded-xl border p-2 text-center font-bold transition ${
                                            paymentMethod === 'transfer'
                                                ? 'border-indigo-600 bg-indigo-50 text-indigo-700 dark:border-indigo-500 dark:bg-indigo-950/40 dark:text-indigo-300'
                                                : 'border-slate-200 text-slate-600 dark:border-neutral-800'
                                        }`}
                                    >
                                        📱 Transf.
                                    </button>
                                </div>
                            </div>

                            <div>
                                <label className="block font-semibold text-slate-700 dark:text-slate-300">
                                    Notas u observaciones:
                                </label>
                                <textarea
                                    rows={2}
                                    placeholder="ej. Transferencia, pago en tienda..."
                                    value={paymentNotes}
                                    onChange={(e) => setPaymentNotes(e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-slate-300 p-2 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div className="flex justify-end gap-2 pt-2 border-t dark:border-neutral-800">
                                <button
                                    type="button"
                                    onClick={() => setPaymentModalOpen(false)}
                                    className="rounded-xl border px-3 py-2 font-semibold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    disabled={submittingPayment}
                                    className="rounded-xl bg-emerald-600 px-4 py-2 font-bold text-white shadow hover:bg-emerald-700 disabled:opacity-40"
                                >
                                    {submittingPayment ? 'Registrando...' : 'Confirmar Abono'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* MODAL: EDIT CUSTOMER */}
            {editModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                    <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl dark:bg-neutral-900">
                        <div className="flex items-center justify-between border-b pb-3 dark:border-neutral-800">
                            <h3 className="text-base font-bold text-slate-900 dark:text-white">
                                Editar Información de Cliente
                            </h3>
                            <button
                                type="button"
                                onClick={() => setEditModalOpen(false)}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                ✕
                            </button>
                        </div>

                        <form onSubmit={handleUpdateCustomer} className="mt-4 space-y-3 text-xs">
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="block font-semibold text-slate-700 dark:text-slate-300">Nombre *:</label>
                                    <input
                                        type="text"
                                        required
                                        value={formCustomer.name}
                                        onChange={(e) => setFormCustomer({ ...formCustomer, name: e.target.value })}
                                        className="mt-1 w-full rounded-xl border border-slate-300 p-2 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="block font-semibold text-slate-700 dark:text-slate-300">Salón / Negocio:</label>
                                    <input
                                        type="text"
                                        value={formCustomer.business_name}
                                        onChange={(e) => setFormCustomer({ ...formCustomer, business_name: e.target.value })}
                                        className="mt-1 w-full rounded-xl border border-slate-300 p-2 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="block font-semibold text-slate-700 dark:text-slate-300">Teléfono / WhatsApp:</label>
                                    <input
                                        type="text"
                                        value={formCustomer.phone}
                                        onChange={(e) => setFormCustomer({ ...formCustomer, phone: e.target.value })}
                                        className="mt-1 w-full rounded-xl border border-slate-300 p-2 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="block font-semibold text-slate-700 dark:text-slate-300">Correo Electrónico:</label>
                                    <input
                                        type="email"
                                        value={formCustomer.email}
                                        onChange={(e) => setFormCustomer({ ...formCustomer, email: e.target.value })}
                                        className="mt-1 w-full rounded-xl border border-slate-300 p-2 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                </div>
                            </div>

                            <div>
                                <label className="block font-semibold text-slate-700 dark:text-slate-300">Dirección:</label>
                                <input
                                    type="text"
                                    value={formCustomer.address}
                                    onChange={(e) => setFormCustomer({ ...formCustomer, address: e.target.value })}
                                    className="mt-1 w-full rounded-xl border border-slate-300 p-2 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="block font-semibold text-slate-700 dark:text-slate-300">Límite de crédito ($):</label>
                                    <input
                                        type="number"
                                        step="100"
                                        value={formCustomer.credit_limit}
                                        onChange={(e) => setFormCustomer({ ...formCustomer, credit_limit: e.target.value })}
                                        className="mt-1 w-full rounded-xl border border-slate-300 p-2 font-mono dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="block font-semibold text-slate-700 dark:text-slate-300">Plazo preferido:</label>
                                    <select
                                        value={formCustomer.credit_days_default}
                                        onChange={(e) => setFormCustomer({ ...formCustomer, credit_days_default: Number(e.target.value) })}
                                        className="mt-1 w-full rounded-xl border border-slate-300 p-2 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    >
                                        <option value={7}>7 Días</option>
                                        <option value={15}>15 Días</option>
                                        <option value={30}>30 Días</option>
                                    </select>
                                </div>
                            </div>

                            <div className="flex justify-end gap-2 pt-2 border-t dark:border-neutral-800">
                                <button
                                    type="button"
                                    onClick={() => setEditModalOpen(false)}
                                    className="rounded-xl border px-3 py-2 font-semibold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    className="rounded-xl bg-indigo-600 px-4 py-2 font-bold text-white shadow hover:bg-indigo-700"
                                >
                                    Guardar Cambios
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AppShell>
    )
}
