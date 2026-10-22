import { useState } from 'react'
import { Head, Link } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

export default function SupplierShow({
    supplier = {},
    products = [],
}) {
    const urlParams = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : null
    const initialTab = urlParams?.get('tab') || 'brands'
    const [activeTab, setActiveTab] = useState(initialTab) // 'brands' | 'products' | 'supplier_codes' | 'orders'

    const [rowStates, setRowStates] = useState({})
    const [toastMessage, setToastMessage] = useState(null)
    const [codeSearch, setCodeSearch] = useState('')
    const [codeStatusFilter, setCodeStatusFilter] = useState('all')

    const brands = supplier.brands || []
    const purchaseOrders = supplier.purchase_orders || []

    const showToast = (msg) => {
        setToastMessage(msg)
        setTimeout(() => setToastMessage(null), 3500)
    }

    const handleInputChange = (productId, field, value) => {
        setRowStates((prev) => ({
            ...prev,
            [productId]: {
                ...prev[productId],
                [field]: value,
                isDirty: true,
                isSaved: false,
            },
        }))
    }

    const handleSaveRow = async (product) => {
        const row = rowStates[product.id] || {
            supplier_sku: product.supplier_sku || '',
            supplier_cost: product.supplier_cost ?? product.cost ?? '',
        }
        const supplierSku = (row.supplier_sku || '').trim()

        if (!supplierSku) {
            alert('Por favor ingresa un código de catálogo válido.')
            return
        }

        setRowStates((prev) => ({
            ...prev,
            [product.id]: { ...prev[product.id], isSaving: true },
        }))

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            const response = await fetch('/compras/proveedores/codigos/guardar', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken || '',
                },
                body: JSON.stringify({
                    inventory_product_id: product.id,
                    supplier_id: supplier.id,
                    supplier_name: supplier.name,
                    supplier_sku: supplierSku,
                    supplier_cost: row.supplier_cost !== '' ? parseFloat(row.supplier_cost) : null,
                }),
            })

            const data = await response.json()

            if (response.ok && data.ok) {
                setRowStates((prev) => ({
                    ...prev,
                    [product.id]: {
                        ...prev[product.id],
                        isSaving: false,
                        isSaved: true,
                        isDirty: false,
                    },
                }))
                showToast(`✓ Código '${supplierSku}' guardado para ${product.name}`)
            } else {
                throw new Error(data.message || data.error || 'Error al guardar código')
            }
        } catch (err) {
            alert(err.message || 'Error al guardar el código de proveedor')
            setRowStates((prev) => ({
                ...prev,
                [product.id]: { ...prev[product.id], isSaving: false },
            }))
        }
    }

    const handleDeleteCode = async (product) => {
        if (!confirm(`¿Eliminar el código de proveedor para '${product.name}'?`)) return

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            const response = await fetch('/compras/proveedores/codigos/eliminar', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken || '',
                },
                body: JSON.stringify({
                    inventory_product_id: product.id,
                    supplier_name: supplier.name,
                }),
            })

            const data = await response.json()

            if (response.ok && data.ok) {
                setRowStates((prev) => ({
                    ...prev,
                    [product.id]: {
                        ...prev[product.id],
                        supplier_sku: '',
                        isDirty: false,
                        isSaved: false,
                    },
                }))
                showToast(`✓ Código de proveedor eliminado`)
            } else {
                throw new Error(data.message || 'Error al eliminar')
            }
        } catch (err) {
            alert(err.message || 'Error al eliminar el código')
        }
    }

    return (
        <AppShell>
            <Head title={`${supplier.name} | Proveedor`} />

            {/* FLOATING TOAST */}
            {toastMessage && (
                <div className="fixed top-5 right-5 z-50 flex items-center gap-2 rounded-2xl bg-slate-900 px-4 py-3 text-xs font-bold text-white shadow-2xl transition-all animate-bounce">
                    <span>✨</span> {toastMessage}
                </div>
            )}

            <div className="space-y-6">
                {/* BREADCRUMB & HEADER */}
                <div>
                    <nav className="mb-2 flex items-center gap-1.5 text-xs text-slate-400">
                        <Link href="/compras/proveedores" className="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Proveedores
                        </Link>
                        <span>/</span>
                        <span className="font-semibold text-slate-700 dark:text-slate-200">
                            {supplier.name}
                        </span>
                    </nav>

                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-center gap-3">
                            <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-indigo-50 text-2xl shadow-xs dark:bg-indigo-950/50">
                                🏢
                            </div>
                            <div>
                                <div className="flex items-center gap-2">
                                    <h1 className="text-2xl font-black tracking-tight text-slate-900 dark:text-white">
                                        {supplier.name}
                                    </h1>
                                    <span
                                        className={`rounded-full px-2.5 py-0.5 text-[10px] font-extrabold ${
                                            supplier.is_active
                                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                                                : 'bg-slate-200 text-slate-600 dark:bg-neutral-800 dark:text-slate-400'
                                        }`}
                                    >
                                        {supplier.is_active ? 'Activo' : 'Inactivo'}
                                    </span>
                                </div>
                                {supplier.rfc && (
                                    <p className="font-mono text-xs text-slate-400 mt-0.5">
                                        RFC: {supplier.rfc}
                                    </p>
                                )}
                            </div>
                        </div>

                        <div className="flex items-center gap-2">
                            <Link
                                href={`/compras/proveedores/codigos?supplier_id=${supplier.id}`}
                                className="inline-flex items-center gap-1.5 rounded-xl border border-purple-300 bg-purple-50 px-3.5 py-2 text-xs font-bold text-purple-700 shadow-xs transition hover:bg-purple-100 dark:border-purple-800 dark:bg-purple-950/60 dark:text-purple-300"
                            >
                                <span>🏷️</span> Gestionar Códigos de Catálogo
                            </Link>
                            <Link
                                href="/compras/proveedores"
                                className="rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-xs transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                            >
                                ← Volver a Proveedores
                            </Link>
                            <Link
                                href={`/compras/ordenes/crear?supplier=${encodeURIComponent(supplier.name)}`}
                                className="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white shadow-xs transition hover:bg-indigo-500 active:scale-[0.98]"
                            >
                                <span>🛒</span> Nueva Orden de Compra
                            </Link>
                        </div>
                    </div>
                </div>

                {/* OVERVIEW CARDS */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    {/* Contacto */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 block mb-2">
                            👤 Contacto y Enlaces
                        </span>
                        <div className="space-y-1.5 text-xs">
                            <div className="flex justify-between">
                                <span className="text-slate-400">Atención:</span>
                                <span className="font-semibold text-slate-800 dark:text-slate-200">
                                    {supplier.contact_name || 'Sin especificar'}
                                </span>
                            </div>
                            {supplier.phone && (
                                <div className="flex justify-between">
                                    <span className="text-slate-400">Teléfono / WhatsApp:</span>
                                    <a
                                        href={`https://wa.me/${supplier.phone.replace(/[^0-9]/g, '')}`}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="font-bold text-emerald-600 hover:underline dark:text-emerald-400 flex items-center gap-1"
                                    >
                                        <span>💬</span> {supplier.phone}
                                    </a>
                                </div>
                            )}
                            {supplier.email && (
                                <div className="flex justify-between">
                                    <span className="text-slate-400">Correo:</span>
                                    <a
                                        href={`mailto:${supplier.email}`}
                                        className="text-indigo-600 hover:underline dark:text-indigo-400"
                                    >
                                        {supplier.email}
                                    </a>
                                </div>
                            )}
                            {supplier.address && (
                                <div className="pt-1 text-[11px] text-slate-500 dark:text-slate-400">
                                    📍 {supplier.address}
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Condiciones Comerciales */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 block mb-2">
                            💳 Términos Comerciales
                        </span>
                        <div className="space-y-1.5 text-xs">
                            <div className="flex justify-between">
                                <span className="text-slate-400">Tiempo de Entrega:</span>
                                <span className="font-mono font-bold text-slate-800 dark:text-slate-200">
                                    ⏱️ {supplier.lead_time_days} días promedio
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-slate-400">Condiciones de Pago:</span>
                                <span className="font-mono font-bold text-indigo-600 dark:text-indigo-400">
                                    {supplier.credit_days > 0 ? `Crédito ${supplier.credit_days} días` : 'Contado'}
                                </span>
                            </div>
                            {supplier.credit_limit > 0 && (
                                <div className="flex justify-between">
                                    <span className="text-slate-400">Límite de Crédito:</span>
                                    <span className="font-mono font-semibold text-slate-800 dark:text-slate-200">
                                        ${supplier.credit_limit.toLocaleString('es-MX', { minimumFractionDigits: 2 })}
                                    </span>
                                </div>
                            )}
                            <div className="flex justify-between">
                                <span className="text-slate-400">Método de Pago:</span>
                                <span className="text-slate-700 dark:text-slate-300">
                                    {supplier.payment_method_preferred || 'Transferencia'}
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Resumen de Catálogo */}
                    <div className="rounded-2xl border border-purple-200 bg-purple-50/40 p-4 shadow-xs dark:border-purple-950 dark:bg-purple-950/20">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-purple-800 dark:text-purple-400 block mb-2">
                            🏷️ Marcas y Productos
                        </span>
                        <div className="flex items-center justify-around py-2">
                            <div className="text-center">
                                <span className="text-2xl font-black text-purple-700 dark:text-purple-400">
                                    {brands.length}
                                </span>
                                <span className="block text-[10px] text-purple-600/70 font-semibold uppercase">
                                    Marcas asignadas
                                </span>
                            </div>
                            <div className="h-8 w-px bg-purple-200 dark:bg-purple-900" />
                            <div className="text-center">
                                <span className="text-2xl font-black text-indigo-700 dark:text-indigo-400">
                                    {products.length}
                                </span>
                                <span className="block text-[10px] text-indigo-600/70 font-semibold uppercase">
                                    Productos en catálogo
                                </span>
                            </div>
                        </div>
                        {supplier.notes && (
                            <p className="mt-2 text-[11px] text-slate-500 border-t border-purple-200/50 pt-2 dark:border-purple-900/50">
                                📝 {supplier.notes}
                            </p>
                        )}
                    </div>
                </div>

                {/* TABS NAVIGATION */}
                <div className="flex border-b border-slate-200 dark:border-neutral-800">
                    <button
                        type="button"
                        onClick={() => setActiveTab('brands')}
                        className={`flex items-center gap-2 border-b-2 px-4 py-2.5 text-xs font-bold transition ${
                            activeTab === 'brands'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white'
                        }`}
                    >
                        <span>🏷️</span> Marcas Asignadas ({brands.length})
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('products')}
                        className={`flex items-center gap-2 border-b-2 px-4 py-2.5 text-xs font-bold transition ${
                            activeTab === 'products'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white'
                        }`}
                    >
                        <span>📦</span> Productos del Catálogo ({products.length})
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('supplier_codes')}
                        className={`flex items-center gap-2 border-b-2 px-4 py-2.5 text-xs font-bold transition ${
                            activeTab === 'supplier_codes'
                                ? 'border-purple-600 text-purple-600 dark:border-purple-400 dark:text-purple-400'
                                : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white'
                        }`}
                    >
                        <span>🏷️</span> Códigos de Catálogo ({products.filter((p) => p.supplier_sku).length})
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('orders')}
                        className={`flex items-center gap-2 border-b-2 px-4 py-2.5 text-xs font-bold transition ${
                            activeTab === 'orders'
                                ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-slate-400 dark:hover:text-white'
                        }`}
                    >
                        <span>🛒</span> Órdenes de Compra ({purchaseOrders.length})
                    </button>
                </div>

                {/* TAB 1: BRANDS */}
                {activeTab === 'brands' && (
                    <div className="space-y-4">
                        {brands.length === 0 ? (
                            <div className="rounded-2xl border border-slate-200 bg-white p-8 text-center dark:border-neutral-800 dark:bg-neutral-900">
                                <span className="text-3xl">🏷️</span>
                                <h3 className="mt-2 text-sm font-bold text-slate-800 dark:text-slate-200">
                                    No hay marcas asignadas a este proveedor
                                </h3>
                                <p className="mt-1 text-xs text-slate-400">
                                    Edita el proveedor para asociar las marcas comerciales que te provee.
                                </p>
                            </div>
                        ) : (
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3">
                                {brands.map((b) => (
                                    <div
                                        key={b.id || b.brand}
                                        className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs transition hover:border-purple-300 dark:border-neutral-800 dark:bg-neutral-900 dark:hover:border-purple-700"
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className="text-base font-black tracking-tight text-slate-900 dark:text-white">
                                                {b.brand}
                                            </span>
                                            {b.is_primary ? (
                                                <span className="rounded-md bg-purple-100 px-2 py-0.5 text-[10px] font-extrabold text-purple-800 dark:bg-purple-950/80 dark:text-purple-300">
                                                    ★ Proveedor Principal
                                                </span>
                                            ) : (
                                                <span className="rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600 dark:bg-neutral-800 dark:text-slate-400">
                                                    Secundario
                                                </span>
                                            )}
                                        </div>

                                        <div className="mt-3 flex items-baseline justify-between border-t border-slate-100 pt-2.5 dark:border-neutral-800">
                                            <span className="text-xs text-slate-400">
                                                Productos en catálogo:
                                            </span>
                                            <span className="font-mono text-sm font-bold text-indigo-600 dark:text-indigo-400">
                                                {b.catalog_products_count} productos
                                            </span>
                                        </div>

                                        <div className="mt-3 flex items-center justify-end gap-1.5">
                                            <Link
                                                href={`/reabastecimiento/pronostico?brand=${encodeURIComponent(b.brand)}`}
                                                className="rounded-lg bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-200 dark:bg-neutral-800 dark:text-slate-300"
                                            >
                                                📊 Ver Pronóstico
                                            </Link>
                                            <Link
                                                href={`/compras/ordenes/crear?supplier=${encodeURIComponent(supplier.name)}&brand=${encodeURIComponent(b.brand)}`}
                                                className="rounded-lg bg-indigo-50 px-2.5 py-1 text-[11px] font-bold text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950/70 dark:text-indigo-300"
                                            >
                                                🛒 Pedir Marca
                                            </Link>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {/* TAB 2: PRODUCTS */}
                {activeTab === 'products' && (
                    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                        {products.length === 0 ? (
                            <div className="p-8 text-center text-xs text-slate-400">
                                No se encontraron productos registrados en el catálogo bajo las marcas de este proveedor.
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead>
                                        <tr className="border-b border-slate-200 bg-slate-50/80 font-bold uppercase tracking-wider text-slate-500 dark:border-neutral-800 dark:bg-neutral-800/60 dark:text-slate-400">
                                            <th className="p-3.5">SKU / Códigos</th>
                                            <th className="p-3.5">Producto</th>
                                            <th className="p-3.5">Marca</th>
                                            <th className="p-3.5 text-right">Costo ($)</th>
                                            <th className="p-3.5 text-right">Precio Público ($)</th>
                                            <th className="p-3.5 text-right">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-neutral-800/60">
                                        {products.map((p) => (
                                            <tr key={p.id} className="hover:bg-slate-50/70 dark:hover:bg-neutral-800/30">
                                                <td className="p-3.5">
                                                    <span className="font-mono font-bold text-indigo-600 dark:text-indigo-400 block">
                                                        {p.sku}
                                                    </span>
                                                    {p.barcode && (
                                                        <span className="font-mono text-[10px] text-slate-400 block">
                                                            {p.barcode}
                                                        </span>
                                                    )}
                                                    {p.barcode_secondary && (
                                                        <span className="font-mono text-[10px] text-slate-400 block">
                                                            Cód 2: {p.barcode_secondary}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="p-3.5 font-semibold text-slate-800 dark:text-slate-200">
                                                    {p.name}
                                                </td>
                                                <td className="p-3.5">
                                                    <span className="rounded-md bg-purple-100 px-2 py-0.5 font-bold text-[10px] text-purple-800 dark:bg-purple-950/70 dark:text-purple-300">
                                                        {p.brand}
                                                    </span>
                                                </td>
                                                <td className="p-3.5 text-right font-mono font-bold text-slate-700 dark:text-slate-300">
                                                    ${Number(p.cost || 0).toLocaleString('es-MX', { minimumFractionDigits: 2 })}
                                                </td>
                                                <td className="p-3.5 text-right font-mono font-bold text-emerald-600 dark:text-emerald-400">
                                                    ${Number(p.price_public || 0).toLocaleString('es-MX', { minimumFractionDigits: 2 })}
                                                </td>
                                                <td className="p-3.5 text-right">
                                                    <Link
                                                        href={`/almacen/productos/${p.id}`}
                                                        className="rounded-lg border border-slate-200 p-1.5 text-slate-600 hover:text-indigo-600 dark:border-neutral-700 dark:text-slate-400"
                                                        title="Ver producto en almacén"
                                                    >
                                                        👁️
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}

                {/* TAB 2.5: SUPPLIER CODES */}
                {activeTab === 'supplier_codes' && (
                    <div className="space-y-4">
                        {/* Search & toolbar inside codes tab */}
                        <div className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-xs sm:flex-row sm:items-center sm:justify-between dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="relative w-full max-w-md">
                                <span className="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                                    🔍
                                </span>
                                <input
                                    type="text"
                                    placeholder="Filtrar por nombre, SKU o código de proveedor..."
                                    value={codeSearch}
                                    onChange={(e) => setCodeSearch(e.target.value)}
                                    className="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pr-3 pl-9 text-xs text-slate-900 outline-none transition focus:border-purple-500 focus:bg-white focus:ring-1 focus:ring-purple-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                />
                            </div>

                            <div className="flex items-center gap-2">
                                <select
                                    value={codeStatusFilter}
                                    onChange={(e) => setCodeStatusFilter(e.target.value)}
                                    className="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                                >
                                    <option value="all">Todos ({products.length})</option>
                                    <option value="with_code">Con código ({products.filter((p) => p.supplier_sku).length})</option>
                                    <option value="without_code">Sin código ({products.filter((p) => !p.supplier_sku).length})</option>
                                </select>

                                <Link
                                    href={`/compras/proveedores/codigos?supplier_id=${supplier.id}`}
                                    className="inline-flex items-center gap-1.5 rounded-xl bg-purple-600 px-3.5 py-1.5 text-xs font-bold text-white shadow-xs hover:bg-purple-500"
                                >
                                    <span>➕</span> Vincular otro producto
                                </Link>
                            </div>
                        </div>

                        {/* Codes Table */}
                        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                            {products.length === 0 ? (
                                <div className="p-8 text-center text-xs text-slate-400">
                                    No hay productos asociados a este proveedor en el catálogo.
                                </div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-left text-xs">
                                        <thead>
                                            <tr className="border-b border-slate-200 bg-slate-50/80 font-bold uppercase tracking-wider text-slate-500 dark:border-neutral-800 dark:bg-neutral-800/60 dark:text-slate-400">
                                                <th className="p-3.5">SKU SBS (Interno)</th>
                                                <th className="p-3.5">Producto</th>
                                                <th className="p-3.5">Marca</th>
                                                <th className="p-3.5 min-w-[200px]">Código de Proveedor (Catálogo)</th>
                                                <th className="p-3.5 min-w-[120px] text-right">Costo ($)</th>
                                                <th className="p-3.5 text-center">Estado</th>
                                                <th className="p-3.5 text-right">Acción</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100 dark:divide-neutral-800/60">
                                            {products
                                                .filter((p) => {
                                                    const s = codeSearch.toLowerCase()
                                                    const matchesSearch =
                                                        !s ||
                                                        p.name.toLowerCase().includes(s) ||
                                                        p.sku.toLowerCase().includes(s) ||
                                                        (p.barcode && p.barcode.includes(s)) ||
                                                        (p.supplier_sku && p.supplier_sku.toLowerCase().includes(s))

                                                    const row = rowStates[p.id]
                                                    const currentSku = row?.supplier_sku !== undefined ? row.supplier_sku : (p.supplier_sku || '')
                                                    const matchesStatus =
                                                        codeStatusFilter === 'all' ||
                                                        (codeStatusFilter === 'with_code' && currentSku) ||
                                                        (codeStatusFilter === 'without_code' && !currentSku)

                                                    return matchesSearch && matchesStatus
                                                })
                                                .map((p) => {
                                                    const state = rowStates[p.id] || {
                                                        supplier_sku: p.supplier_sku || '',
                                                        supplier_cost: p.supplier_cost ?? p.cost ?? '',
                                                        isSaving: false,
                                                        isSaved: false,
                                                        isDirty: false,
                                                    }
                                                    const hasSavedCode = Boolean(p.supplier_sku)

                                                    return (
                                                        <tr key={p.id} className="hover:bg-slate-50/70 dark:hover:bg-neutral-800/30">
                                                            <td className="p-3.5 align-top">
                                                                <span className="font-mono font-bold text-indigo-600 dark:text-indigo-400 block">
                                                                    {p.sku}
                                                                </span>
                                                                {p.barcode && (
                                                                    <span className="font-mono text-[10px] text-slate-400 block">
                                                                        UPC: {p.barcode}
                                                                    </span>
                                                                )}
                                                            </td>
                                                            <td className="p-3.5 align-top font-semibold text-slate-800 dark:text-slate-200 max-w-[280px]">
                                                                {p.name}
                                                            </td>
                                                            <td className="p-3.5 align-top">
                                                                <span className="rounded-md bg-purple-100 px-2 py-0.5 font-bold text-[10px] text-purple-800 dark:bg-purple-950/70 dark:text-purple-300">
                                                                    {p.brand}
                                                                </span>
                                                            </td>
                                                            <td className="p-3.5 align-top">
                                                                <input
                                                                    type="text"
                                                                    placeholder="ej. 60515, LOR-101..."
                                                                    value={state.supplier_sku}
                                                                    onChange={(e) => handleInputChange(p.id, 'supplier_sku', e.target.value.toUpperCase())}
                                                                    onKeyDown={(e) => {
                                                                        if (e.key === 'Enter') {
                                                                            e.preventDefault()
                                                                            handleSaveRow(p)
                                                                        }
                                                                    }}
                                                                    className="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-mono font-bold uppercase outline-none focus:border-purple-500 focus:bg-white focus:ring-1 focus:ring-purple-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                                                />
                                                            </td>
                                                            <td className="p-3.5 align-top text-right">
                                                                <input
                                                                    type="number"
                                                                    step="0.01"
                                                                    min="0"
                                                                    placeholder="0.00"
                                                                    value={state.supplier_cost}
                                                                    onChange={(e) => handleInputChange(p.id, 'supplier_cost', e.target.value)}
                                                                    onKeyDown={(e) => {
                                                                        if (e.key === 'Enter') {
                                                                            e.preventDefault()
                                                                            handleSaveRow(p)
                                                                        }
                                                                    }}
                                                                    className="w-24 rounded-xl border border-slate-200 bg-slate-50 px-2 py-1.5 text-right text-xs font-mono font-bold outline-none focus:border-purple-500 focus:bg-white focus:ring-1 focus:ring-purple-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                                                />
                                                            </td>
                                                            <td className="p-3.5 align-top text-center">
                                                                {state.isSaving ? (
                                                                    <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-bold text-indigo-700 animate-pulse">
                                                                        Guardando...
                                                                    </span>
                                                                ) : state.isSaved ? (
                                                                    <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-800">
                                                                        ✓ Guardado
                                                                    </span>
                                                                ) : state.isDirty ? (
                                                                    <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-800">
                                                                        ● Modificado
                                                                    </span>
                                                                ) : hasSavedCode ? (
                                                                    <span className="rounded-full bg-purple-100 px-2 py-0.5 text-[10px] font-bold text-purple-800">
                                                                        Asignado
                                                                    </span>
                                                                ) : (
                                                                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] text-slate-500">
                                                                        Sin código
                                                                    </span>
                                                                )}
                                                            </td>
                                                            <td className="p-3.5 align-top text-right">
                                                                <div className="flex items-center justify-end gap-1.5">
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => handleSaveRow(p)}
                                                                        disabled={state.isSaving || (!state.isDirty && hasSavedCode)}
                                                                        className={`rounded-xl px-3 py-1.5 text-xs font-bold transition ${
                                                                            state.isDirty
                                                                                ? 'bg-purple-600 text-white hover:bg-purple-500'
                                                                                : hasSavedCode
                                                                                ? 'border border-slate-200 bg-white text-slate-400'
                                                                                : 'bg-indigo-600 text-white hover:bg-indigo-500'
                                                                        }`}
                                                                    >
                                                                        {state.isSaving ? '...' : 'Guardar'}
                                                                    </button>
                                                                    {hasSavedCode && (
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => handleDeleteCode(p)}
                                                                            className="rounded-xl border border-rose-200 p-1.5 text-rose-500 hover:bg-rose-50"
                                                                            title="Eliminar código"
                                                                        >
                                                                            ✕
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
                    </div>
                )}

                {/* TAB 3: PURCHASE ORDERS */}
                {activeTab === 'orders' && (
                    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                        {purchaseOrders.length === 0 ? (
                            <div className="p-8 text-center">
                                <span className="text-3xl">🛒</span>
                                <h3 className="mt-2 text-sm font-bold text-slate-800 dark:text-slate-200">
                                    No hay órdenes de compra registradas con este proveedor
                                </h3>
                                <p className="mt-1 text-xs text-slate-400">
                                    Crea la primera orden de compra para abastecer inventario de este proveedor.
                                </p>
                                <div className="mt-4">
                                    <Link
                                        href={`/compras/ordenes/crear?supplier=${encodeURIComponent(supplier.name)}`}
                                        className="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white shadow-xs hover:bg-indigo-500"
                                    >
                                        <span>🛒</span> Crear Primera Orden de Compra
                                    </Link>
                                </div>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-xs">
                                    <thead>
                                        <tr className="border-b border-slate-200 bg-slate-50/80 font-bold uppercase tracking-wider text-slate-500 dark:border-neutral-800 dark:bg-neutral-800/60 dark:text-slate-400">
                                            <th className="p-3.5">No. Orden</th>
                                            <th className="p-3.5">Estado</th>
                                            <th className="p-3.5">Marca / Cotización</th>
                                            <th className="p-3.5 text-right">Total ($)</th>
                                            <th className="p-3.5">Fecha</th>
                                            <th className="p-3.5 text-right">Acción</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-neutral-800/60">
                                        {purchaseOrders.map((po) => (
                                            <tr key={po.id} className="hover:bg-slate-50/70 dark:hover:bg-neutral-800/30">
                                                <td className="p-3.5 font-mono font-bold text-indigo-600 dark:text-indigo-400">
                                                    <Link href={`/compras/ordenes/${po.id}`} className="hover:underline">
                                                        {po.order_number}
                                                    </Link>
                                                </td>
                                                <td className="p-3.5">
                                                    <span className="rounded-full bg-slate-100 px-2 py-0.5 font-bold text-[10px] uppercase text-slate-700 dark:bg-neutral-800 dark:text-slate-300">
                                                        {po.status}
                                                    </span>
                                                </td>
                                                <td className="p-3.5 text-slate-700 dark:text-slate-300">
                                                    {po.brand || 'Consolidado'}
                                                    {po.supplier_quote_reference && (
                                                        <span className="block text-[10px] text-slate-400 font-mono">
                                                            Ref: {po.supplier_quote_reference}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="p-3.5 text-right font-mono font-bold text-slate-900 dark:text-white">
                                                    ${Number(po.total_cost || 0).toLocaleString('es-MX', { minimumFractionDigits: 2 })}
                                                </td>
                                                <td className="p-3.5 text-slate-500">
                                                    {po.created_at ? new Date(po.created_at).toLocaleDateString() : '—'}
                                                </td>
                                                <td className="p-3.5 text-right">
                                                    <Link
                                                        href={`/compras/ordenes/${po.id}`}
                                                        className="rounded-lg border border-slate-200 p-1.5 text-slate-600 hover:text-indigo-600 dark:border-neutral-700 dark:text-slate-400"
                                                    >
                                                        👁️
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </AppShell>
    )
}
