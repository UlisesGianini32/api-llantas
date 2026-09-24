import AppShell from '@/Components/layout/AppShell'
import { Head, Link } from '@inertiajs/react'

function money(value) {
    if (value === null || value === undefined || value === '') return '—'
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: 2 }).format(Number(value))
}

export default function InventoryProductShow({ product, physicalStock = 0, stockByLocation = [], movements = [] }) {
    return (
        <AppShell title="Producto de almacén">
            <Head title={product.name} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center"><div><Link href="/almacen/productos" className="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-300">← Volver al catálogo</Link><h1 className="mt-3 text-3xl font-bold text-slate-900 dark:text-white">{product.name}</h1></div><Link href={`/almacen/productos/${product.id}/editar`} className="rounded-xl bg-indigo-600 px-4 py-2.5 text-center font-semibold text-white">Editar</Link></div>
                <div className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-6 sm:grid-cols-2 dark:border-neutral-800 dark:bg-neutral-900">
                    {[['SKU', product.sku], ['Código de barras', product.barcode || '—'], ['Ubicación principal', product.primary_location?.code || 'Sin ubicación'], ['Stock físico', Number(physicalStock || 0)], ['Costo', money(product.cost)], ['Precio Mercado Libre', money(product.price_mercado_libre)], ['Precio Amazon', money(product.price_amazon)], ['Precio estilista / Shopify', money(product.price_stylist)], ['Precio público', money(product.price_public)], ['Estado', product.is_active ? 'Activo' : 'Inactivo']].map(([label, value]) => <div key={label}><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</dt><dd className="mt-1 text-slate-900 dark:text-white">{value}</dd></div>)}
                    <div className="sm:col-span-2"><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Descripción</dt><dd className="mt-1 whitespace-pre-wrap text-slate-700 dark:text-slate-300">{product.description || '—'}</dd></div>
                </div>
                <section className="rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="border-b border-slate-200 px-5 py-4 font-bold dark:border-neutral-800">Stock por ubicación</div>
                    <div className="divide-y divide-slate-100 dark:divide-neutral-800">
                        {stockByLocation.length === 0 ? <p className="px-5 py-5 text-sm text-slate-500">Sin movimientos registrados.</p> : stockByLocation.map((row) => <div key={row.inventory_location_id} className="flex justify-between px-5 py-3 text-sm"><span>{row.location?.code || 'Sin ubicación'}</span><strong>{Number(row.quantity || 0)}</strong></div>)}
                    </div>
                </section>
                <section className="rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="border-b border-slate-200 px-5 py-4 font-bold dark:border-neutral-800">Últimos movimientos</div>
                    <div className="divide-y divide-slate-100 dark:divide-neutral-800">
                        {movements.length === 0 ? <p className="px-5 py-5 text-sm text-slate-500">Sin movimientos registrados.</p> : movements.map((movement) => <div key={movement.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm"><span>{movement.occurred_at} · {movement.type} · {movement.location?.code}</span><strong className={Number(movement.quantity) >= 0 ? 'text-emerald-700' : 'text-rose-700'}>{Number(movement.quantity) >= 0 ? '+' : ''}{movement.quantity}</strong></div>)}
                    </div>
                </section>
            </div>
        </AppShell>
    )
}
