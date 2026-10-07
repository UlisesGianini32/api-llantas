import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

export default function CustomersIndex({
    customers = { data: [], links: [] },
    summary = {},
    urgentSales = [],
    filters = { filter: 'all', q: '' },
}) {
    const [searchTerm, setSearchTerm] = useState(filters.q || '')
    const [currentFilter, setCurrentFilter] = useState(filters.filter || 'all')

    // Modals
    const [createModalOpen, setCreateModalOpen] = useState(false)
    const [editCustomer, setEditCustomer] = useState(null)
    const [paymentCustomer, setPaymentCustomer] = useState(null)

    // Form: Create / Edit Customer
    const [formCustomer, setFormCustomer] = useState({
        name: '',
        business_name: '',
        phone: '',
        email: '',
        address: '',
        credit_limit: '5000',
        credit_days_default: 15,
        notes: '',
    })

    // Form: Abono / Payment
    const [paymentAmount, setPaymentAmount] = useState('')
    const [paymentMethod, setPaymentMethod] = useState('cash')
    const [paymentNotes, setPaymentNotes] = useState('')
    const [submittingPayment, setSubmittingPayment] = useState(false)

    const handleFilterChange = (newFilter) => {
        setCurrentFilter(newFilter)
        router.get(
            '/pos/clientes',
            { filter: newFilter, q: searchTerm },
            { preserveState: true, preserveScroll: true }
        )
    }

    const handleSearchSubmit = (e) => {
        e.preventDefault()
        router.get(
            '/pos/clientes',
            { filter: currentFilter, q: searchTerm },
            { preserveState: true, preserveScroll: true }
        )
    }

    const openCreateModal = () => {
        setFormCustomer({
            name: '',
            business_name: '',
            phone: '',
            email: '',
            address: '',
            credit_limit: '5000',
            credit_days_default: 15,
            notes: '',
        })
        setEditCustomer(null)
        setCreateModalOpen(true)
    }

    const openEditModal = (cust) => {
        setFormCustomer({
            name: cust.name || '',
            business_name: cust.business_name || '',
            phone: cust.phone || '',
            email: cust.email || '',
            address: cust.address || '',
            credit_limit: cust.credit_limit ? String(cust.credit_limit) : '5000',
            credit_days_default: cust.credit_days_default || 15,
            notes: cust.notes || '',
        })
        setEditCustomer(cust)
        setCreateModalOpen(true)
    }

    const handleSaveCustomer = async (e) => {
        e.preventDefault()
        if (editCustomer) {
            router.put(`/pos/clientes/${editCustomer.id}`, formCustomer, {
                onSuccess: () => setCreateModalOpen(false),
            })
        } else {
            router.post('/pos/clientes', formCustomer, {
                onSuccess: () => setCreateModalOpen(false),
            })
        }
    }

    const openPaymentModal = (customer) => {
        setPaymentCustomer(customer)
        setPaymentAmount(customer.total_debt > 0 ? String(customer.total_debt) : '')
        setPaymentMethod('cash')
        setPaymentNotes('')
    }

    const handleSavePayment = async (e) => {
        e.preventDefault()
        if (!paymentCustomer || !paymentAmount || Number(paymentAmount) <= 0) return

        setSubmittingPayment(true)
        router.post(
            `/pos/clientes/${paymentCustomer.id}/pagos`,
            {
                amount: Number(paymentAmount),
                payment_method: paymentMethod,
                notes: paymentNotes,
            },
            {
                onSuccess: () => {
                    setPaymentCustomer(null)
                    setSubmittingPayment(false)
                },
                onError: () => {
                    setSubmittingPayment(false)
                },
            }
        )
    }

    const getCleanPhone = (phone) => {
        if (!phone) return ''
        return phone.replace(/\D/g, '')
    }

    const formatWhatsAppUrl = (sale) => {
        const phone = getCleanPhone(sale.customer_phone || sale.customer?.phone)
        if (!phone) return '#'
        const fullPhone = phone.length === 10 ? `52${phone}` : phone
        const dueStr = sale.credit_due_date ? new Date(sale.credit_due_date).toLocaleDateString('es-MX') : 'pronto'
        const text = encodeURIComponent(
            `Hola ${sale.customer_name}, le saludamos de Salón & Barber Supply. Le recordamos amablemente sobre su nota a crédito #${sale.sale_number} por un saldo de $${Number(sale.balance_due).toFixed(2)}, con vencimiento el ${dueStr}. Quedamos atentos para registrar su abono o resolver cualquier duda. ¡Gracias!`
        )
        return `https://wa.me/${fullPhone}?text=${text}`
    }

    return (
        <AppShell>
            <Head title="Clientes y Cartera de Crédito" />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-6">
                {/* HEADER & TOP ACTIONS */}
                <div className="flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-white p-5 shadow-sm dark:bg-neutral-900 border border-slate-200 dark:border-neutral-800">
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="rounded-xl bg-indigo-50 p-2 text-2xl text-indigo-600 dark:bg-neutral-800 dark:text-indigo-400">
                                👥
                            </span>
                            <div>
                                <h1 className="text-xl font-bold tracking-tight text-slate-900 dark:text-white">
                                    Clientes & Cartera de Crédito
                                </h1>
                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                    Control de estilistas, barberías, créditos a 7, 15 y 30 días, y registro de abonos.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-3">
                        <Link
                            href="/pos"
                            className="rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                        >
                            🏪 Ir al Punto de Venta
                        </Link>
                        <button
                            type="button"
                            onClick={openCreateModal}
                            className="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white shadow-md hover:bg-indigo-700 transition"
                        >
                            + Nuevo Cliente / Estilista
                        </button>
                    </div>
                </div>

                {/* SUMMARY STATS TILES */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    {/* TOTAL CUSTOMERS */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                            Total Registrados
                        </span>
                        <div className="mt-2 flex items-baseline justify-between">
                            <span className="text-2xl font-extrabold text-slate-900 dark:text-white">
                                {summary.total_customers || 0}
                            </span>
                            <span className="text-xs text-slate-500">Clientes</span>
                        </div>
                    </div>

                    {/* CON CRÉDITO ACTIVO */}
                    <div
                        onClick={() => handleFilterChange('with_credit')}
                        className="cursor-pointer rounded-2xl border border-blue-200 bg-blue-50/50 p-4 shadow-sm transition hover:shadow-md dark:border-blue-900/50 dark:bg-blue-950/20"
                    >
                        <span className="text-[11px] font-bold uppercase tracking-wider text-blue-600 dark:text-blue-400">
                            🟢 Con Crédito Vigente
                        </span>
                        <div className="mt-2 flex items-baseline justify-between">
                            <span className="text-2xl font-extrabold text-blue-700 dark:text-blue-300">
                                {summary.active_with_credit || 0}
                            </span>
                            <span className="text-xs font-medium text-blue-600">Al corriente</span>
                        </div>
                    </div>

                    {/* CRÉDITOS VENCIDOS */}
                    <div
                        onClick={() => handleFilterChange('overdue')}
                        className="cursor-pointer rounded-2xl border border-red-200 bg-red-50/60 p-4 shadow-sm transition hover:shadow-md dark:border-red-900/50 dark:bg-red-950/20"
                    >
                        <span className="text-[11px] font-bold uppercase tracking-wider text-red-600 dark:text-red-400">
                            🔴 Créditos Vencidos
                        </span>
                        <div className="mt-2 flex items-baseline justify-between">
                            <span className="text-2xl font-extrabold text-red-700 dark:text-red-300">
                                {summary.with_overdue_credit || 0}
                            </span>
                            <span className="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-bold text-red-800 dark:bg-red-900 dark:text-red-200">
                                {summary.overdue_sales_count || 0} notas
                            </span>
                        </div>
                    </div>

                    {/* TOTAL CARTERA POR COBRAR */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                            Total Cartera a Crédito
                        </span>
                        <div className="mt-2">
                            <span className="font-mono text-xl font-extrabold text-indigo-600 dark:text-indigo-400">
                                ${Number(summary.total_debt || 0).toLocaleString('es-MX', { minimumFractionDigits: 2 })}
                            </span>
                            {Number(summary.total_overdue_debt || 0) > 0 && (
                                <p className="mt-0.5 text-[10px] font-semibold text-red-500">
                                    Vencido: ${Number(summary.total_overdue_debt || 0).toLocaleString('es-MX', { minimumFractionDigits: 2 })}
                                </p>
                            )}
                        </div>
                    </div>
                </div>

                {/* URGENT ALERTS / REMINDER BANNER */}
                {urgentSales.length > 0 && (
                    <div className="rounded-2xl border border-amber-300 bg-amber-50/80 p-4 shadow-sm dark:border-amber-900/60 dark:bg-amber-950/25">
                        <div className="flex items-center justify-between border-b border-amber-200 pb-2 dark:border-amber-900/50">
                            <div className="flex items-center gap-2">
                                <span className="text-lg">🔔</span>
                                <h3 className="text-xs font-bold uppercase tracking-wide text-amber-900 dark:text-amber-200">
                                    Alertas de Vencimiento de Crédito ({urgentSales.length} notas urgentes)
                                </h3>
                            </div>
                            <span className="text-[11px] text-amber-800 dark:text-amber-300">
                                Próximos 3 días o vencidas
                            </span>
                        </div>

                        <div className="mt-3 grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                            {urgentSales.map((sale) => {
                                const isOverdue = new Date(sale.credit_due_date) < new Date(new Date().setHours(0, 0, 0, 0))
                                return (
                                    <div
                                        key={sale.id}
                                        className="flex flex-col justify-between rounded-xl border border-amber-200 bg-white p-3 text-xs shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                                    >
                                        <div>
                                            <div className="flex items-center justify-between">
                                                <span className="font-bold text-slate-800 dark:text-slate-200">
                                                    {sale.customer_name}
                                                </span>
                                                <span
                                                    className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${
                                                        isOverdue
                                                            ? 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300'
                                                            : 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'
                                                    }`}
                                                >
                                                    {isOverdue ? 'VENCIDA' : 'POR VENCER'}
                                                </span>
                                            </div>
                                            {sale.customer?.business_name && (
                                                <p className="text-[11px] text-slate-500">{sale.customer.business_name}</p>
                                            )}
                                            <div className="mt-1 flex items-center justify-between text-slate-600 dark:text-slate-400">
                                                <span>Folio: <strong className="font-mono">{sale.sale_number}</strong></span>
                                                <span>Saldo: <strong className="font-mono text-red-600">${Number(sale.balance_due).toFixed(2)}</strong></span>
                                            </div>
                                            <p className="text-[10px] text-slate-400 mt-0.5">
                                                Vence: {sale.credit_due_date ? new Date(sale.credit_due_date).toLocaleDateString('es-MX') : '—'}
                                            </p>
                                        </div>

                                        <div className="mt-2.5 flex items-center justify-between border-t pt-2 gap-1 dark:border-neutral-800">
                                            {sale.customer_phone ? (
                                                <a
                                                    href={formatWhatsAppUrl(sale)}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-2 py-1 text-[11px] font-bold text-white hover:bg-emerald-700"
                                                >
                                                    <span>📱</span> WhatsApp
                                                </a>
                                            ) : (
                                                <span className="text-[10px] text-slate-400">Sin teléfono</span>
                                            )}
                                            <div className="flex gap-1">
                                                <a
                                                    href={`/pos/sales/${sale.id}/voucher`}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="rounded-lg border border-slate-300 px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                                                >
                                                    📄 Pagaré
                                                </a>
                                                <button
                                                    type="button"
                                                    onClick={() => openPaymentModal(sale.customer || { id: sale.customer_id, name: sale.customer_name, total_debt: sale.balance_due })}
                                                    className="rounded-lg bg-indigo-600 px-2 py-1 text-[11px] font-bold text-white hover:bg-indigo-700"
                                                >
                                                    💵 Abonar
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                )
                            })}
                        </div>
                    </div>
                )}

                {/* FILTERS & SEARCH BAR */}
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-3 shadow-sm dark:bg-neutral-900 border border-slate-200 dark:border-neutral-800">
                    {/* TABS */}
                    <div className="flex flex-wrap gap-1 text-xs font-semibold">
                        <button
                            type="button"
                            onClick={() => handleFilterChange('all')}
                            className={`rounded-xl px-3 py-1.5 transition ${
                                currentFilter === 'all'
                                    ? 'bg-indigo-600 text-white shadow-sm'
                                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-neutral-800'
                            }`}
                        >
                            Todos ({summary.total_customers || 0})
                        </button>
                        <button
                            type="button"
                            onClick={() => handleFilterChange('with_credit')}
                            className={`rounded-xl px-3 py-1.5 transition ${
                                currentFilter === 'with_credit'
                                    ? 'bg-blue-600 text-white shadow-sm'
                                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-neutral-800'
                            }`}
                        >
                            🟢 Activos con crédito ({summary.active_with_credit || 0})
                        </button>
                        <button
                            type="button"
                            onClick={() => handleFilterChange('overdue')}
                            className={`rounded-xl px-3 py-1.5 transition ${
                                currentFilter === 'overdue'
                                    ? 'bg-red-600 text-white shadow-sm'
                                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-neutral-800'
                            }`}
                        >
                            🔴 Crédito vencido ({summary.with_overdue_credit || 0})
                        </button>
                        <button
                            type="button"
                            onClick={() => handleFilterChange('no_debt')}
                            className={`rounded-xl px-3 py-1.5 transition ${
                                currentFilter === 'no_debt'
                                    ? 'bg-emerald-600 text-white shadow-sm'
                                    : 'text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-neutral-800'
                            }`}
                        >
                            ✓ Al día / Sin deuda ({summary.without_debt || 0})
                        </button>
                    </div>

                    {/* SEARCH INPUT */}
                    <form onSubmit={handleSearchSubmit} className="flex items-center gap-2">
                        <div className="flex items-center gap-1.5 rounded-xl border border-slate-300 bg-slate-50 px-3 py-1.5 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                            <span className="text-slate-400">🔍</span>
                            <input
                                type="text"
                                placeholder="Buscar por nombre, salón o teléfono..."
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                className="w-56 bg-transparent outline-none dark:text-white"
                            />
                        </div>
                        <button
                            type="submit"
                            className="rounded-xl bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-black dark:bg-neutral-700"
                        >
                            Buscar
                        </button>
                    </form>
                </div>

                {/* CUSTOMERS LIST / TABLE */}
                <div className="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden dark:border-neutral-800 dark:bg-neutral-900">
                    {customers.data.length === 0 ? (
                        <div className="py-16 text-center text-sm text-slate-400">
                            No se encontraron clientes con los filtros aplicados.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead className="border-b bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:border-neutral-800 dark:bg-neutral-950 dark:text-slate-400">
                                    <tr>
                                        <th className="p-3.5">Cliente / Estilista</th>
                                        <th className="p-3.5">Salón / Barbería</th>
                                        <th className="p-3.5">Contacto</th>
                                        <th className="p-3.5 text-right">Límite Crédito</th>
                                        <th className="p-3.5 text-right">Saldo Deudor</th>
                                        <th className="p-3.5 text-center">Estado Crédito</th>
                                        <th className="p-3.5 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                    {customers.data.map((c) => (
                                        <tr key={c.id} className="hover:bg-slate-50/60 dark:hover:bg-neutral-800/40 transition">
                                            <td className="p-3.5">
                                                <Link
                                                    href={`/pos/clientes/${c.id}`}
                                                    className="font-bold text-slate-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400"
                                                >
                                                    {c.name}
                                                </Link>
                                                <p className="text-[10px] text-slate-400">
                                                    Plazo preferido: {c.credit_days_default} días
                                                </p>
                                            </td>
                                            <td className="p-3.5 text-slate-600 dark:text-slate-300">
                                                {c.business_name || '—'}
                                            </td>
                                            <td className="p-3.5">
                                                {c.phone ? (
                                                    <a
                                                        href={`tel:${c.phone}`}
                                                        className="font-mono text-slate-700 hover:underline dark:text-slate-300"
                                                    >
                                                        {c.phone}
                                                    </a>
                                                ) : (
                                                    <span className="text-slate-400">—</span>
                                                )}
                                                {c.email && (
                                                    <p className="text-[10px] text-slate-400 truncate max-w-[150px]">{c.email}</p>
                                                )}
                                            </td>
                                            <td className="p-3.5 text-right font-mono font-medium text-slate-700 dark:text-slate-300">
                                                ${Number(c.credit_limit).toFixed(2)}
                                            </td>
                                            <td className="p-3.5 text-right">
                                                <span
                                                    className={`font-mono text-sm font-bold ${
                                                        c.total_debt > 0
                                                            ? c.has_overdue
                                                                ? 'text-red-600'
                                                                : 'text-indigo-600'
                                                            : 'text-slate-400'
                                                    }`}
                                                >
                                                    ${Number(c.total_debt).toFixed(2)}
                                                </span>
                                                {c.pending_sales_count > 0 && (
                                                    <span className="block text-[10px] text-slate-400">
                                                        ({c.pending_sales_count} notas pendientes)
                                                    </span>
                                                )}
                                            </td>
                                            <td className="p-3.5 text-center">
                                                {c.total_debt <= 0 ? (
                                                    <span className="rounded-full bg-emerald-100 px-2.5 py-1 text-[10px] font-bold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                                                        ✓ Sin deuda
                                                    </span>
                                                ) : c.has_overdue ? (
                                                    <span className="rounded-full bg-red-100 px-2.5 py-1 text-[10px] font-bold text-red-700 dark:bg-red-950 dark:text-red-300 animate-pulse">
                                                        ⚠️ Crédito Vencido
                                                    </span>
                                                ) : (
                                                    <span className="rounded-full bg-blue-100 px-2.5 py-1 text-[10px] font-bold text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                                                        🟢 Al corriente
                                                    </span>
                                                )}
                                            </td>
                                            <td className="p-3.5 text-right">
                                                <div className="flex items-center justify-end gap-1.5">
                                                    {c.total_debt > 0 && (
                                                        <button
                                                            type="button"
                                                            onClick={() => openPaymentModal(c)}
                                                            className="rounded-lg bg-emerald-600 px-2.5 py-1 font-bold text-white shadow-sm hover:bg-emerald-700"
                                                        >
                                                            💵 Abonar
                                                        </button>
                                                    )}
                                                    <Link
                                                        href={`/pos/clientes/${c.id}`}
                                                        className="rounded-lg border border-slate-300 px-2.5 py-1 font-semibold text-slate-700 hover:bg-slate-100 dark:border-neutral-700 dark:text-slate-300"
                                                    >
                                                        Detalles
                                                    </Link>
                                                    <button
                                                        type="button"
                                                        onClick={() => openEditModal(c)}
                                                        className="rounded-lg border border-slate-200 px-2 py-1 text-slate-500 hover:bg-slate-100 dark:border-neutral-700"
                                                        title="Editar cliente"
                                                    >
                                                        ✏️
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>

            {/* MODAL: CREATE / EDIT CUSTOMER */}
            {createModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                    <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl dark:bg-neutral-900 max-h-[90vh] overflow-y-auto">
                        <div className="flex items-center justify-between border-b pb-3 dark:border-neutral-800">
                            <h3 className="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                <span>👥</span> {editCustomer ? 'Editar Estilista / Cliente' : 'Nuevo Estilista / Cliente'}
                            </h3>
                            <button
                                type="button"
                                onClick={() => setCreateModalOpen(false)}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                ✕
                            </button>
                        </div>

                        <form onSubmit={handleSaveCustomer} className="mt-4 space-y-3.5 text-xs">
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="block font-semibold text-slate-700 dark:text-slate-300">
                                        Nombre Completo *:
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        placeholder="ej. Mariana Rodríguez"
                                        value={formCustomer.name}
                                        onChange={(e) => setFormCustomer({ ...formCustomer, name: e.target.value })}
                                        className="mt-1 w-full rounded-xl border border-slate-300 p-2 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="block font-semibold text-slate-700 dark:text-slate-300">
                                        Salón / Barbería:
                                    </label>
                                    <input
                                        type="text"
                                        placeholder="ej. Estudio Glamour"
                                        value={formCustomer.business_name}
                                        onChange={(e) => setFormCustomer({ ...formCustomer, business_name: e.target.value })}
                                        className="mt-1 w-full rounded-xl border border-slate-300 p-2 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="block font-semibold text-slate-700 dark:text-slate-300">
                                        Teléfono / WhatsApp:
                                    </label>
                                    <input
                                        type="text"
                                        placeholder="ej. 6621234567"
                                        value={formCustomer.phone}
                                        onChange={(e) => setFormCustomer({ ...formCustomer, phone: e.target.value })}
                                        className="mt-1 w-full rounded-xl border border-slate-300 p-2 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="block font-semibold text-slate-700 dark:text-slate-300">
                                        Correo Electrónico:
                                    </label>
                                    <input
                                        type="email"
                                        placeholder="ej. mariana@correo.com"
                                        value={formCustomer.email}
                                        onChange={(e) => setFormCustomer({ ...formCustomer, email: e.target.value })}
                                        className="mt-1 w-full rounded-xl border border-slate-300 p-2 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                </div>
                            </div>

                            <div>
                                <label className="block font-semibold text-slate-700 dark:text-slate-300">
                                    Dirección física / Salón:
                                </label>
                                <input
                                    type="text"
                                    placeholder="ej. Av. Rosales #123, Col. Centro"
                                    value={formCustomer.address}
                                    onChange={(e) => setFormCustomer({ ...formCustomer, address: e.target.value })}
                                    className="mt-1 w-full rounded-xl border border-slate-300 p-2 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-3 rounded-xl bg-slate-50 p-3 dark:bg-neutral-950 border border-slate-200 dark:border-neutral-800">
                                <div>
                                    <label className="block font-semibold text-slate-700 dark:text-slate-300">
                                        Límite de crédito autorizado ($):
                                    </label>
                                    <input
                                        type="number"
                                        step="100"
                                        min="0"
                                        value={formCustomer.credit_limit}
                                        onChange={(e) => setFormCustomer({ ...formCustomer, credit_limit: e.target.value })}
                                        className="mt-1 w-full rounded-xl border border-slate-300 p-2 font-mono font-bold dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="block font-semibold text-slate-700 dark:text-slate-300">
                                        Plazo de crédito preferido:
                                    </label>
                                    <select
                                        value={formCustomer.credit_days_default}
                                        onChange={(e) => setFormCustomer({ ...formCustomer, credit_days_default: Number(e.target.value) })}
                                        className="mt-1 w-full rounded-xl border border-slate-300 p-2 font-semibold dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                                    >
                                        <option value={7}>7 Días</option>
                                        <option value={15}>15 Días</option>
                                        <option value={30}>30 Días</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label className="block font-semibold text-slate-700 dark:text-slate-300">
                                    Notas u observaciones:
                                </label>
                                <textarea
                                    rows={2}
                                    placeholder="ej. Recomienda productos de queratina, paga puntual los viernes..."
                                    value={formCustomer.notes}
                                    onChange={(e) => setFormCustomer({ ...formCustomer, notes: e.target.value })}
                                    className="mt-1 w-full rounded-xl border border-slate-300 p-2 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div className="flex justify-end gap-2 pt-2 border-t dark:border-neutral-800">
                                <button
                                    type="button"
                                    onClick={() => setCreateModalOpen(false)}
                                    className="rounded-xl border px-3 py-2 font-semibold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    className="rounded-xl bg-indigo-600 px-4 py-2 font-bold text-white shadow hover:bg-indigo-700"
                                >
                                    {editCustomer ? 'Actualizar Cliente' : 'Guardar Cliente'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* MODAL: REGISTRAR ABONO / PAGO */}
            {paymentCustomer && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl dark:bg-neutral-900">
                        <div className="flex items-center justify-between border-b pb-3 dark:border-neutral-800">
                            <div>
                                <h3 className="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                    <span>💵</span> Registrar Abono / Pago
                                </h3>
                                <p className="text-xs text-slate-500">
                                    Cliente: <strong>{paymentCustomer.name}</strong>
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setPaymentCustomer(null)}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                ✕
                            </button>
                        </div>

                        <div className="mt-4 rounded-xl border border-indigo-100 bg-indigo-50/60 p-3 text-xs dark:border-indigo-950 dark:bg-indigo-950/20">
                            <div className="flex justify-between text-indigo-900 dark:text-indigo-300">
                                <span>Saldo Total Pendiente:</span>
                                <span className="font-mono text-sm font-extrabold text-red-600">
                                    ${Number(paymentCustomer.total_debt || 0).toFixed(2)}
                                </span>
                            </div>
                        </div>

                        <form onSubmit={handleSavePayment} className="mt-4 space-y-3.5 text-xs">
                            <div>
                                <label className="block font-bold text-slate-700 dark:text-slate-300">
                                    Importe del Abono ($) *:
                                </label>
                                <div className="mt-1 flex items-center rounded-xl border border-slate-300 bg-white px-3 py-2 font-mono text-base font-bold dark:border-neutral-700 dark:bg-neutral-950 dark:text-white">
                                    <span className="text-slate-400 mr-1">$</span>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        max={paymentCustomer.total_debt > 0 ? paymentCustomer.total_debt : undefined}
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
                                    Forma de pago:
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
                                    Notas o referencia de pago (opcional):
                                </label>
                                <textarea
                                    rows={2}
                                    placeholder="ej. Transferencia BBVA folio 93829, abono parcial..."
                                    value={paymentNotes}
                                    onChange={(e) => setPaymentNotes(e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-slate-300 p-2 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div className="flex justify-end gap-2 pt-2 border-t dark:border-neutral-800">
                                <button
                                    type="button"
                                    onClick={() => setPaymentCustomer(null)}
                                    className="rounded-xl border px-3 py-2 font-semibold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    disabled={submittingPayment || !paymentAmount}
                                    className="rounded-xl bg-emerald-600 px-4 py-2 font-bold text-white shadow hover:bg-emerald-700 disabled:opacity-40"
                                >
                                    {submittingPayment ? 'Registrando...' : 'Confirmar Abono'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AppShell>
    )
}
