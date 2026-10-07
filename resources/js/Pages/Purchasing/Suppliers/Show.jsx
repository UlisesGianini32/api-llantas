import { useState } from 'react'
import { Head, Link } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

export default function SupplierShow({
    supplier = {},
    products = [],
}) {
    const [activeTab, setActiveTab] = useState('brands') // 'brands' | 'products' | 'orders'

    const brands = supplier.brands || []
    const purchaseOrders = supplier.purchase_orders || []

    return (
        <AppShell>
            <Head title={`${supplier.name} | Proveedor`} />

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
