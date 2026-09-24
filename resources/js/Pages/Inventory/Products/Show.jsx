import AppShell from '@/Components/layout/AppShell'
import { Head, Link } from '@inertiajs/react'

function money(value) {
    if (value === null || value === undefined || value === '') return '—'
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: 2 }).format(Number(value))
}

export default function InventoryProductShow({ product }) {
    return (
        <AppShell title="Producto de almacén">
            <Head title={product.name} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center"><div><Link href="/almacen/productos" className="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-300">← Volver al catálogo</Link><h1 className="mt-3 text-3xl font-bold text-slate-900 dark:text-white">{product.name}</h1></div><Link href={`/almacen/productos/${product.id}/editar`} className="rounded-xl bg-indigo-600 px-4 py-2.5 text-center font-semibold text-white">Editar</Link></div>
                <div className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-6 sm:grid-cols-2 dark:border-neutral-800 dark:bg-neutral-900">
                    {[['SKU', product.sku], ['Código de barras', product.barcode || '—'], ['Ubicación principal', product.primary_location?.code || 'Sin ubicación'], ['Costo', money(product.cost)], ['Precio Mercado Libre', money(product.price_mercado_libre)], ['Precio Amazon', money(product.price_amazon)], ['Precio estilista / Shopify', money(product.price_stylist)], ['Precio público', money(product.price_public)], ['Estado', product.is_active ? 'Activo' : 'Inactivo']].map(([label, value]) => <div key={label}><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</dt><dd className="mt-1 text-slate-900 dark:text-white">{value}</dd></div>)}
                    <div className="sm:col-span-2"><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Descripción</dt><dd className="mt-1 whitespace-pre-wrap text-slate-700 dark:text-slate-300">{product.description || '—'}</dd></div>
                </div>
            </div>
        </AppShell>
    )
}
