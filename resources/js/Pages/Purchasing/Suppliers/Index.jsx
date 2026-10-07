import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

export default function SuppliersIndex({
    suppliers = [],
    availableBrands = [],
    summary = {},
    filters = {},
}) {
    const [search, setSearch] = useState(filters.search || '')
    const [selectedBrand, setSelectedBrand] = useState(filters.brand || '')
    const [selectedStatus, setSelectedStatus] = useState(filters.status || 'active')

    // Modal state for create / edit
    const [modalOpen, setModalOpen] = useState(false)
    const [editingSupplier, setEditingSupplier] = useState(null)
    const [formData, setFormData] = useState({
        name: '',
        rfc: '',
        contact_name: '',
        email: '',
        phone: '',
        address: '',
        lead_time_days: 7,
        credit_days: 0,
        credit_limit: 0,
        payment_method_preferred: 'Transferencia',
        website: '',
        notes: '',
        is_active: true,
        brands: [],
        update_catalog_products: false,
    })
    const [newBrandInput, setNewBrandInput] = useState('')
    const [saving, setSaving] = useState(false)
    const [formErrors, setFormErrors] = useState({})

    const handleFilterChange = (newFilters) => {
        const query = {
            search: search,
            brand: selectedBrand,
            status: selectedStatus,
            ...newFilters,
        }

        Object.keys(query).forEach((key) => {
            if (!query[key]) delete query[key]
        })

        router.get('/compras/proveedores', query, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    const handleSearchSubmit = (e) => {
        e.preventDefault()
        handleFilterChange({ search })
    }

    const openCreateModal = () => {
        setEditingSupplier(null)
        setFormData({
            name: '',
            rfc: '',
            contact_name: '',
            email: '',
            phone: '',
            address: '',
            lead_time_days: 7,
            credit_days: 0,
            credit_limit: 0,
            payment_method_preferred: 'Transferencia',
            website: '',
            notes: '',
            is_active: true,
            brands: [],
            update_catalog_products: false,
        })
        setNewBrandInput('')
        setFormErrors({})
        setModalOpen(true)
    }

    const openEditModal = (sup) => {
        setEditingSupplier(sup)
        setFormData({
            name: sup.name,
            rfc: sup.rfc || '',
            contact_name: sup.contact_name || '',
            email: sup.email || '',
            phone: sup.phone || '',
            address: sup.address || '',
            lead_time_days: sup.lead_time_days ?? 7,
            credit_days: sup.credit_days ?? 0,
            credit_limit: sup.credit_limit ?? 0,
            payment_method_preferred: sup.payment_method_preferred || 'Transferencia',
            website: sup.website || '',
            notes: sup.notes || '',
            is_active: sup.is_active ?? true,
            brands: sup.brands ? sup.brands.map((b) => ({
                brand: b.brand,
                is_primary: b.is_primary ?? true,
                lead_time_override: b.lead_time_override || null,
            })) : [],
            update_catalog_products: false,
        })
        setNewBrandInput('')
        setFormErrors({})
        setModalOpen(true)
    }

    const handleAddBrand = (brandToAdd) => {
        const clean = (brandToAdd || newBrandInput).trim().toUpperCase()
        if (!clean) return

        if (!formData.brands.some((b) => b.brand === clean)) {
            setFormData({
                ...formData,
                brands: [...formData.brands, { brand: clean, is_primary: true, lead_time_override: null }],
            })
        }
        setNewBrandInput('')
    }

    const handleRemoveBrand = (brandToRemove) => {
        setFormData({
            ...formData,
            brands: formData.brands.filter((b) => b.brand !== brandToRemove),
        })
    }

    const handleTogglePrimaryBrand = (brandName) => {
        setFormData({
            ...formData,
            brands: formData.brands.map((b) =>
                b.brand === brandName ? { ...b, is_primary: !b.is_primary } : b
            ),
        })
    }

    const handleSubmit = (e) => {
        e.preventDefault()
        setSaving(true)
        setFormErrors({})

        const url = editingSupplier
            ? `/compras/proveedores/${editingSupplier.id}`
            : '/compras/proveedores'

        const method = editingSupplier ? 'put' : 'post'

        router[method](url, formData, {
            preserveScroll: true,
            onSuccess: () => {
                setModalOpen(false)
                setSaving(false)
            },
            onError: (errors) => {
                setFormErrors(errors)
                setSaving(false)
            },
        })
    }

    const handleToggleStatus = (sup) => {
        router.patch(`/compras/proveedores/${sup.id}/estado`, {}, {
            preserveScroll: true,
        })
    }

    return (
        <AppShell>
            <Head title="Proveedores y Marcas | Compras" />

            <div className="space-y-6">
                {/* HEADER */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2.5">
                            <h1 className="text-2xl font-black tracking-tight text-slate-900 dark:text-white">
                                Directorio de Proveedores
                            </h1>
                            <span className="rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-bold text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-400">
                                {summary.total_suppliers || 0} Registrados
                            </span>
                        </div>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            Administra a tus proveedores, días de entrega, condiciones comerciales y las marcas que te proveen.
                        </p>
                    </div>

                    <div className="flex items-center gap-2">
                        <Link
                            href="/compras/ordenes/crear"
                            className="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-xs transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200 dark:hover:bg-neutral-700"
                        >
                            <span>🛒</span> Nueva Orden de Compra
                        </Link>
                        <button
                            type="button"
                            onClick={openCreateModal}
                            className="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white shadow-xs transition hover:bg-indigo-500 active:scale-[0.98]"
                        >
                            <span>➕</span> Registrar Proveedor
                        </button>
                    </div>
                </div>

                {/* KPIS SUMMARY CARDS */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            Total Proveedores
                        </span>
                        <div className="mt-1 flex items-baseline gap-1.5">
                            <span className="text-2xl font-black text-slate-900 dark:text-white">
                                {summary.total_suppliers || 0}
                            </span>
                            <span className="text-xs text-slate-400">empresas</span>
                        </div>
                    </div>

                    <div className="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-xs dark:border-emerald-950 dark:bg-emerald-950/20">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                            Activos Operando
                        </span>
                        <div className="mt-1 flex items-baseline gap-1.5">
                            <span className="text-2xl font-black text-emerald-700 dark:text-emerald-400">
                                {summary.active_suppliers || 0}
                            </span>
                            <span className="text-xs text-emerald-600/70">habilitados</span>
                        </div>
                    </div>

                    <div className="rounded-2xl border border-indigo-200 bg-indigo-50/50 p-4 shadow-xs dark:border-indigo-950 dark:bg-indigo-950/20">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-indigo-700 dark:text-indigo-400">
                            Con Plazo de Crédito
                        </span>
                        <div className="mt-1 flex items-baseline gap-1.5">
                            <span className="text-2xl font-black text-indigo-700 dark:text-indigo-400">
                                {summary.credit_suppliers || 0}
                            </span>
                            <span className="text-xs text-indigo-600/70">proveedores</span>
                        </div>
                    </div>

                    <div className="rounded-2xl border border-purple-200 bg-purple-50/50 p-4 shadow-xs dark:border-purple-950 dark:bg-purple-950/20">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-purple-700 dark:text-purple-400">
                            Marcas Mapeadas
                        </span>
                        <div className="mt-1 flex items-baseline gap-1.5">
                            <span className="text-2xl font-black text-purple-700 dark:text-purple-400">
                                {summary.total_mapped_brands || 0}
                            </span>
                            <span className="text-xs text-purple-600/70">marcas</span>
                        </div>
                    </div>
                </div>

                {/* FILTERS & SEARCH TOOLBAR */}
                <div className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-xs sm:flex-row sm:items-center sm:justify-between dark:border-neutral-800 dark:bg-neutral-900">
                    <form onSubmit={handleSearchSubmit} className="flex flex-1 items-center gap-2">
                        <div className="relative w-full max-w-md">
                            <span className="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                                🔍
                            </span>
                            <input
                                type="text"
                                placeholder="Buscar por proveedor, contacto, teléfono o marca..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pr-3 pl-9 text-xs text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white dark:focus:bg-neutral-900"
                            />
                        </div>
                        <button
                            type="submit"
                            className="rounded-xl bg-slate-800 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-slate-700 dark:bg-neutral-700 dark:hover:bg-neutral-600"
                        >
                            Buscar
                        </button>
                    </form>

                    <div className="flex flex-wrap items-center gap-2">
                        {/* Brand filter */}
                        <div className="flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                            <span className="text-slate-400">🏷️</span>
                            <select
                                value={selectedBrand}
                                onChange={(e) => {
                                    setSelectedBrand(e.target.value)
                                    handleFilterChange({ brand: e.target.value })
                                }}
                                className="bg-transparent font-medium text-slate-700 outline-none dark:text-slate-200"
                            >
                                <option value="">Todas las marcas</option>
                                {availableBrands.map((b) => (
                                    <option key={b} value={b}>
                                        {b}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {/* Status filter */}
                        <div className="flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                            <span className="text-slate-400">⚡</span>
                            <select
                                value={selectedStatus}
                                onChange={(e) => {
                                    setSelectedStatus(e.target.value)
                                    handleFilterChange({ status: e.target.value })
                                }}
                                className="bg-transparent font-medium text-slate-700 outline-none dark:text-slate-200"
                            >
                                <option value="active">Solo activos</option>
                                <option value="inactive">Solo inactivos</option>
                                <option value="all">Todos los estados</option>
                            </select>
                        </div>

                        {(search || selectedBrand || selectedStatus !== 'active') && (
                            <button
                                type="button"
                                onClick={() => {
                                    setSearch('')
                                    setSelectedBrand('')
                                    setSelectedStatus('active')
                                    router.get('/compras/proveedores')
                                }}
                                className="text-xs font-bold text-red-500 hover:underline"
                            >
                                Limpiar
                            </button>
                        )}
                    </div>
                </div>

                {/* SUPPLIERS TABLE */}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                    {suppliers.length === 0 ? (
                        <div className="p-12 text-center">
                            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-50 text-2xl dark:bg-indigo-950/40">
                                🏢
                            </div>
                            <h3 className="mt-3 text-base font-bold text-slate-900 dark:text-white">
                                No se encontraron proveedores
                            </h3>
                            <p className="mx-auto mt-1 max-w-md text-xs text-slate-500 dark:text-slate-400">
                                {search || selectedBrand
                                    ? 'No hay proveedores que coincidan con los filtros aplicados. Intenta con otros términos o limpia los filtros.'
                                    : 'Aún no tienes proveedores registrados en el sistema. Registra al primero para asignar sus marcas.'}
                            </p>
                            <div className="mt-5">
                                <button
                                    type="button"
                                    onClick={openCreateModal}
                                    className="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white shadow-xs hover:bg-indigo-500"
                                >
                                    <span>➕</span> Registrar Primer Proveedor
                                </button>
                            </div>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead>
                                    <tr className="border-b border-slate-200 bg-slate-50/80 font-bold uppercase tracking-wider text-slate-500 dark:border-neutral-800 dark:bg-neutral-800/60 dark:text-slate-400">
                                        <th className="p-3.5">Proveedor</th>
                                        <th className="p-3.5">Contacto y Comunicación</th>
                                        <th className="p-3.5">Condiciones Comerciales</th>
                                        <th className="p-3.5">Marcas que Provee</th>
                                        <th className="p-3.5 text-center">Catálogo</th>
                                        <th className="p-3.5 text-center">Estado</th>
                                        <th className="p-3.5 text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-neutral-800/60">
                                    {suppliers.map((sup) => (
                                        <tr
                                            key={sup.id}
                                            className="transition hover:bg-slate-50/70 dark:hover:bg-neutral-800/30"
                                        >
                                            {/* Proveedor */}
                                            <td className="p-3.5 align-top">
                                                <Link
                                                    href={`/compras/proveedores/${sup.id}`}
                                                    className="font-bold text-slate-900 transition hover:text-indigo-600 dark:text-white dark:hover:text-indigo-400"
                                                >
                                                    {sup.name}
                                                </Link>
                                                {sup.rfc && (
                                                    <span className="mt-0.5 block font-mono text-[11px] text-slate-400">
                                                        RFC: {sup.rfc}
                                                    </span>
                                                )}
                                                {sup.address && (
                                                    <span className="mt-0.5 line-clamp-1 text-[11px] text-slate-400" title={sup.address}>
                                                        📍 {sup.address}
                                                    </span>
                                                )}
                                            </td>

                                            {/* Contacto */}
                                            <td className="p-3.5 align-top">
                                                {sup.contact_name ? (
                                                    <span className="font-semibold text-slate-800 dark:text-slate-200 block">
                                                        {sup.contact_name}
                                                    </span>
                                                ) : (
                                                    <span className="text-slate-400 italic block">Sin contacto</span>
                                                )}
                                                <div className="mt-1 flex flex-col gap-0.5 text-[11px]">
                                                    {sup.phone && (
                                                        <a
                                                            href={`https://wa.me/${sup.phone.replace(/[^0-9]/g, '')}`}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="flex items-center gap-1 text-emerald-600 hover:underline dark:text-emerald-400"
                                                            title="Abrir WhatsApp"
                                                        >
                                                            <span>💬</span> {sup.phone}
                                                        </a>
                                                    )}
                                                    {sup.email && (
                                                        <a
                                                            href={`mailto:${sup.email}`}
                                                            className="flex items-center gap-1 text-slate-500 hover:text-indigo-600 dark:text-slate-400 dark:hover:text-indigo-400"
                                                        >
                                                            <span>✉️</span> {sup.email}
                                                        </a>
                                                    )}
                                                </div>
                                            </td>

                                            {/* Condiciones Comerciales */}
                                            <td className="p-3.5 align-top">
                                                <div className="flex flex-col gap-1">
                                                    <div className="flex items-center gap-1.5">
                                                        <span className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] font-bold text-slate-700 dark:bg-neutral-800 dark:text-slate-300">
                                                            ⏱️ {sup.lead_time_days} días entrega
                                                        </span>
                                                    </div>
                                                    <div>
                                                        {sup.credit_days > 0 ? (
                                                            <span className="rounded bg-indigo-100 px-1.5 py-0.5 font-mono text-[10px] font-bold text-indigo-700 dark:bg-indigo-950/80 dark:text-indigo-300">
                                                                💳 Crédito {sup.credit_days} días
                                                            </span>
                                                        ) : (
                                                            <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-neutral-800 dark:text-slate-400">
                                                                💵 Contado
                                                            </span>
                                                        )}
                                                    </div>
                                                    {sup.credit_limit > 0 && (
                                                        <span className="text-[10px] text-slate-400 font-mono">
                                                            Límite: ${sup.credit_limit.toLocaleString('es-MX')}
                                                        </span>
                                                    )}
                                                </div>
                                            </td>

                                            {/* Marcas que provee */}
                                            <td className="p-3.5 align-top max-w-[280px]">
                                                {sup.brands && sup.brands.length > 0 ? (
                                                    <div className="flex flex-wrap gap-1.5">
                                                        {sup.brands.map((b) => (
                                                            <span
                                                                key={b.id || b.brand}
                                                                className={`inline-flex items-center gap-1 rounded-lg px-2 py-0.5 text-[11px] font-bold ${
                                                                    b.is_primary
                                                                        ? 'bg-purple-100 text-purple-800 border border-purple-200 dark:bg-purple-950/70 dark:text-purple-300 dark:border-purple-800'
                                                                        : 'bg-slate-100 text-slate-700 border border-slate-200 dark:bg-neutral-800 dark:text-slate-300 dark:border-neutral-700'
                                                                }`}
                                                                title={`${b.brand} (${b.catalog_products_count} productos en almacén) ${b.is_primary ? '- Proveedor principal' : ''}`}
                                                            >
                                                                <span>{b.brand}</span>
                                                                {b.is_primary && (
                                                                    <span className="text-[9px] text-purple-600 dark:text-purple-400 font-extrabold" title="Proveedor Principal">★</span>
                                                                )}
                                                                {b.catalog_products_count > 0 && (
                                                                    <span className="ml-0.5 rounded-full bg-white/70 px-1 text-[9px] text-slate-600 dark:bg-neutral-900/60 dark:text-slate-300 font-mono">
                                                                        {b.catalog_products_count}
                                                                    </span>
                                                                )}
                                                            </span>
                                                        ))}
                                                    </div>
                                                ) : (
                                                    <span className="text-slate-400 italic">
                                                        Sin marcas asignadas
                                                    </span>
                                                )}
                                            </td>

                                            {/* Catálogo Total */}
                                            <td className="p-3.5 align-top text-center">
                                                <span className="font-mono text-sm font-bold text-slate-900 dark:text-white">
                                                    {sup.catalog_products_count}
                                                </span>
                                                <span className="block text-[10px] text-slate-400">
                                                    productos
                                                </span>
                                            </td>

                                            {/* Estado */}
                                            <td className="p-3.5 align-top text-center">
                                                <button
                                                    type="button"
                                                    onClick={() => handleToggleStatus(sup)}
                                                    className={`rounded-full px-2.5 py-0.5 text-[10px] font-extrabold transition ${
                                                        sup.is_active
                                                            ? 'bg-emerald-100 text-emerald-800 hover:bg-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300'
                                                            : 'bg-slate-200 text-slate-600 hover:bg-slate-300 dark:bg-neutral-800 dark:text-slate-400'
                                                    }`}
                                                    title="Haz clic para activar o desactivar"
                                                >
                                                    {sup.is_active ? 'Activo' : 'Inactivo'}
                                                </button>
                                            </td>

                                            {/* Acciones */}
                                            <td className="p-3.5 align-top text-right">
                                                <div className="flex items-center justify-end gap-1.5">
                                                    <Link
                                                        href={`/compras/proveedores/${sup.id}`}
                                                        className="rounded-lg border border-slate-200 p-1.5 text-slate-600 transition hover:bg-slate-100 hover:text-indigo-600 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800"
                                                        title="Ver detalles, marcas y catálogo"
                                                    >
                                                        👁️
                                                    </Link>
                                                    <button
                                                        type="button"
                                                        onClick={() => openEditModal(sup)}
                                                        className="rounded-lg border border-slate-200 p-1.5 text-slate-600 transition hover:bg-slate-100 hover:text-indigo-600 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800"
                                                        title="Editar datos y marcas"
                                                    >
                                                        ✏️
                                                    </button>
                                                    <Link
                                                        href={`/compras/ordenes/crear?supplier=${encodeURIComponent(sup.name)}`}
                                                        className="rounded-lg bg-indigo-50 p-1.5 text-indigo-700 transition hover:bg-indigo-100 dark:bg-indigo-950/60 dark:text-indigo-300"
                                                        title="Crear orden de compra a este proveedor"
                                                    >
                                                        🛒
                                                    </Link>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                {/* MODAL REGISTRAR / EDITAR PROVEEDOR */}
                {modalOpen && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs">
                        <div className="relative w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-neutral-800 dark:bg-neutral-900">
                            {/* Modal Header */}
                            <div className="flex items-center justify-between border-b border-slate-100 pb-4 dark:border-neutral-800">
                                <div>
                                    <h2 className="text-lg font-black text-slate-900 dark:text-white">
                                        {editingSupplier ? 'Editar Proveedor' : 'Registrar Nuevo Proveedor'}
                                    </h2>
                                    <p className="text-xs text-slate-500 dark:text-slate-400">
                                        Ingresa los datos del proveedor y las marcas que te distribuye.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setModalOpen(false)}
                                    className="rounded-full p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-neutral-800"
                                >
                                    ✕
                                </button>
                            </div>

                            <form onSubmit={handleSubmit} className="mt-4 space-y-4">
                                {/* Datos de Identificación */}
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div className="sm:col-span-2">
                                        <label className="block text-xs font-bold text-slate-700 dark:text-slate-300">
                                            Nombre Comercial / Razón Social <span className="text-red-500">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            required
                                            placeholder="ej. Distribuidora Barber Pro S.A. de C.V."
                                            value={formData.name}
                                            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                                            className="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-xs text-slate-900 outline-none focus:border-indigo-500 focus:bg-white focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                        />
                                        {formErrors.name && (
                                            <p className="mt-1 text-xs text-red-500">{formErrors.name}</p>
                                        )}
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold text-slate-700 dark:text-slate-300">
                                            RFC / Identificador Fiscal
                                        </label>
                                        <input
                                            type="text"
                                            placeholder="ej. DBP200101XYZ"
                                            value={formData.rfc}
                                            onChange={(e) => setFormData({ ...formData, rfc: e.target.value.toUpperCase() })}
                                            className="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-xs font-mono text-slate-900 outline-none focus:border-indigo-500 focus:bg-white focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                        />
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold text-slate-700 dark:text-slate-300">
                                            Nombre del Contacto / Agente
                                        </label>
                                        <input
                                            type="text"
                                            placeholder="ej. Carlos Mendoza (Ventas)"
                                            value={formData.contact_name}
                                            onChange={(e) => setFormData({ ...formData, contact_name: e.target.value })}
                                            className="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-xs text-slate-900 outline-none focus:border-indigo-500 focus:bg-white focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                        />
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold text-slate-700 dark:text-slate-300">
                                            Teléfono / WhatsApp
                                        </label>
                                        <input
                                            type="text"
                                            placeholder="ej. 662 123 4567"
                                            value={formData.phone}
                                            onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                                            className="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-xs text-slate-900 outline-none focus:border-indigo-500 focus:bg-white focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                        />
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold text-slate-700 dark:text-slate-300">
                                            Correo Electrónico
                                        </label>
                                        <input
                                            type="email"
                                            placeholder="ej. pedidos@barberpro.com"
                                            value={formData.email}
                                            onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                                            className="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-xs text-slate-900 outline-none focus:border-indigo-500 focus:bg-white focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                        />
                                    </div>
                                </div>

                                {/* SECCIÓN DE MARCAS ASIGNADAS */}
                                <div className="rounded-2xl border border-purple-200 bg-purple-50/40 p-4 dark:border-purple-900/60 dark:bg-purple-950/20">
                                    <div className="flex items-center justify-between">
                                        <div className="flex items-center gap-1.5">
                                            <span className="text-sm">🏷️</span>
                                            <h3 className="text-xs font-black uppercase tracking-wider text-purple-900 dark:text-purple-300">
                                                Marcas que Provee este Proveedor
                                            </h3>
                                        </div>
                                        <span className="rounded-full bg-purple-200 px-2 py-0.5 text-[10px] font-bold text-purple-800 dark:bg-purple-900 dark:text-purple-300">
                                            {formData.brands.length} seleccionadas
                                        </span>
                                    </div>
                                    <p className="mt-1 text-[11px] text-purple-700/80 dark:text-purple-400">
                                        Asigna qué marcas te surte. Puedes elegir marcas existentes en tu catálogo o escribir una nueva.
                                    </p>

                                    {/* Input para agregar marca */}
                                    <div className="mt-3 flex gap-2">
                                        <input
                                            type="text"
                                            placeholder="Escribe o busca una marca (ej. WAHL, BABYLISS, REVLON)..."
                                            value={newBrandInput}
                                            onChange={(e) => setNewBrandInput(e.target.value)}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter') {
                                                    e.preventDefault()
                                                    handleAddBrand()
                                                }
                                            }}
                                            className="w-full rounded-xl border border-purple-300 bg-white px-3 py-1.5 text-xs text-slate-900 uppercase outline-none focus:border-purple-600 focus:ring-1 focus:ring-purple-600 dark:border-purple-800 dark:bg-neutral-800 dark:text-white"
                                            list="available-brands-list"
                                        />
                                        <datalist id="available-brands-list">
                                            {availableBrands.map((b) => (
                                                <option key={b} value={b} />
                                            ))}
                                        </datalist>
                                        <button
                                            type="button"
                                            onClick={() => handleAddBrand()}
                                            className="shrink-0 rounded-xl bg-purple-700 px-3.5 py-1.5 text-xs font-bold text-white shadow-xs transition hover:bg-purple-600 active:scale-95"
                                        >
                                            + Agregar
                                        </button>
                                    </div>

                                    {/* Sugerencias rápidas de marcas del catálogo */}
                                    {availableBrands.length > 0 && (
                                        <div className="mt-2.5">
                                            <span className="text-[10px] font-semibold text-slate-500 dark:text-slate-400 block mb-1">
                                                Marcas registradas en catálogo:
                                            </span>
                                            <div className="flex flex-wrap gap-1 max-h-20 overflow-y-auto pr-1">
                                                {availableBrands.map((b) => {
                                                    const isAdded = formData.brands.some((item) => item.brand === b)
                                                    return (
                                                        <button
                                                            key={b}
                                                            type="button"
                                                            onClick={() => !isAdded && handleAddBrand(b)}
                                                            disabled={isAdded}
                                                            className={`rounded-md px-2 py-0.5 text-[10px] font-bold transition ${
                                                                isAdded
                                                                    ? 'bg-purple-200 text-purple-700 opacity-60 dark:bg-purple-900/60 dark:text-purple-400'
                                                                    : 'bg-white text-slate-700 border border-purple-200 hover:bg-purple-100 hover:text-purple-900 dark:bg-neutral-800 dark:text-slate-300 dark:border-neutral-700'
                                                            }`}
                                                        >
                                                            {isAdded ? `✓ ${b}` : `+ ${b}`}
                                                        </button>
                                                    )
                                                })}
                                            </div>
                                        </div>
                                    )}

                                    {/* Lista de marcas añadidas */}
                                    {formData.brands.length > 0 ? (
                                        <div className="mt-3.5 space-y-1.5">
                                            <span className="text-[10px] font-bold uppercase tracking-wider text-purple-900 dark:text-purple-300 block">
                                                Marcas asignadas a este proveedor:
                                            </span>
                                            <div className="flex flex-wrap gap-2">
                                                {formData.brands.map((b) => (
                                                    <div
                                                        key={b.brand}
                                                        className="flex items-center gap-1.5 rounded-xl border border-purple-300 bg-white px-2.5 py-1 text-xs shadow-2xs dark:border-purple-800 dark:bg-neutral-800"
                                                    >
                                                        <span className="font-bold text-slate-900 dark:text-white">
                                                            {b.brand}
                                                        </span>
                                                        <button
                                                            type="button"
                                                            onClick={() => handleTogglePrimaryBrand(b.brand)}
                                                            className={`rounded px-1 text-[9px] font-extrabold ${
                                                                b.is_primary
                                                                    ? 'bg-purple-100 text-purple-800 dark:bg-purple-950 dark:text-purple-300'
                                                                    : 'bg-slate-100 text-slate-500 hover:bg-purple-50 dark:bg-neutral-700 dark:text-slate-400'
                                                            }`}
                                                            title="Haz clic para marcar si es proveedor principal de esta marca"
                                                        >
                                                            {b.is_primary ? '★ Principal' : 'Secundario'}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => handleRemoveBrand(b.brand)}
                                                            className="text-slate-400 hover:text-red-500 font-bold ml-1"
                                                            title="Quitar marca"
                                                        >
                                                            ✕
                                                        </button>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    ) : (
                                        <p className="mt-2 text-center text-[11px] text-purple-600/70 italic dark:text-purple-400">
                                            Aún no has agregado ninguna marca a este proveedor.
                                        </p>
                                    )}

                                    {/* Checkbox para actualizar catálogo */}
                                    <div className="mt-3 pt-2.5 border-t border-purple-200/60 dark:border-purple-900/60">
                                        <label className="flex items-center gap-2 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                checked={formData.update_catalog_products}
                                                onChange={(e) => setFormData({ ...formData, update_catalog_products: e.target.checked })}
                                                className="h-3.5 w-3.5 rounded text-indigo-600 focus:ring-indigo-500"
                                            />
                                            <span className="text-xs text-slate-700 dark:text-slate-300">
                                                Asignar este proveedor a todos los productos existentes de estas marcas en el catálogo
                                            </span>
                                        </label>
                                    </div>
                                </div>

                                {/* Condiciones Comerciales */}
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                    <div>
                                        <label className="block text-xs font-bold text-slate-700 dark:text-slate-300">
                                            Días de Entrega (Lead Time)
                                        </label>
                                        <div className="relative mt-1">
                                            <input
                                                type="number"
                                                min="0"
                                                value={formData.lead_time_days}
                                                onChange={(e) => setFormData({ ...formData, lead_time_days: parseInt(e.target.value) || 0 })}
                                                className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-xs font-mono text-slate-900 outline-none focus:border-indigo-500 focus:bg-white focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                            />
                                            <span className="absolute inset-y-0 right-0 flex items-center pr-3 text-xs text-slate-400">
                                                días
                                            </span>
                                        </div>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold text-slate-700 dark:text-slate-300">
                                            Plazo de Crédito
                                        </label>
                                        <select
                                            value={formData.credit_days}
                                            onChange={(e) => setFormData({ ...formData, credit_days: parseInt(e.target.value) || 0 })}
                                            className="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-xs text-slate-900 outline-none focus:border-indigo-500 focus:bg-white focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                        >
                                            <option value={0}>0 días (Contado)</option>
                                            <option value={7}>7 días de crédito</option>
                                            <option value={15}>15 días de crédito</option>
                                            <option value={30}>30 días de crédito</option>
                                            <option value={45}>45 días de crédito</option>
                                            <option value={60}>60 días de crédito</option>
                                            <option value={90}>90 días de crédito</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold text-slate-700 dark:text-slate-300">
                                            Límite de Crédito ($)
                                        </label>
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            value={formData.credit_limit}
                                            onChange={(e) => setFormData({ ...formData, credit_limit: parseFloat(e.target.value) || 0 })}
                                            className="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2 text-xs font-mono text-slate-900 outline-none focus:border-indigo-500 focus:bg-white focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                        />
                                    </div>
                                </div>

                                {/* Dirección y Notas */}
                                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div>
                                        <label className="block text-xs font-bold text-slate-700 dark:text-slate-300">
                                            Dirección / Ciudad / Bodega
                                        </label>
                                        <textarea
                                            rows={2}
                                            placeholder="ej. Calle 12 de Octubre #123, Col. Centro, Hermosillo, Sonora"
                                            value={formData.address}
                                            onChange={(e) => setFormData({ ...formData, address: e.target.value })}
                                            className="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-1.5 text-xs text-slate-900 outline-none focus:border-indigo-500 focus:bg-white focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                        />
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold text-slate-700 dark:text-slate-300">
                                            Notas / Cuentas Bancarias
                                        </label>
                                        <textarea
                                            rows={2}
                                            placeholder="ej. CLABE BBVA: 0123456789... Atiende pedidos antes de las 2 PM"
                                            value={formData.notes}
                                            onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                                            className="mt-1 w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-1.5 text-xs text-slate-900 outline-none focus:border-indigo-500 focus:bg-white focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                        />
                                    </div>
                                </div>

                                {/* Modal Actions */}
                                <div className="mt-6 flex items-center justify-end gap-2 border-t border-slate-100 pt-4 dark:border-neutral-800">
                                    <button
                                        type="button"
                                        onClick={() => setModalOpen(false)}
                                        className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-300"
                                    >
                                        Cancelar
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={saving}
                                        className="rounded-xl bg-indigo-600 px-5 py-2 text-xs font-bold text-white shadow-xs transition hover:bg-indigo-500 disabled:opacity-50"
                                    >
                                        {saving ? 'Guardando...' : editingSupplier ? 'Actualizar Proveedor' : 'Guardar Proveedor'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}
            </div>
        </AppShell>
    )
}
