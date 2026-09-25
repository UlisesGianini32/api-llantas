import AppShell from '@/Components/layout/AppShell'
import { Head, Link, useForm } from '@inertiajs/react'

const fields = [
    ['cost', 'Costo'],
    ['price_mercado_libre', 'Precio Mercado Libre'],
    ['price_amazon', 'Precio Amazon'],
    ['price_stylist', 'Precio estilista / Shopify'],
    ['price_public', 'Precio público'],
]

export default function InventoryProductForm({ mode, product, locations = [] }) {
    const editing = mode === 'edit'
    const { data, setData, post, put, processing, errors } = useForm({
        sku: product?.sku || '',
        product_type: product?.product_type || 'SIMPLE',
        barcode: product?.barcode || '',
        name: product?.name || '',
        description: product?.description || '',
        cost: product?.cost ?? '',
        price_mercado_libre: product?.price_mercado_libre ?? '',
        price_amazon: product?.price_amazon ?? '',
        price_stylist: product?.price_stylist ?? '',
        price_public: product?.price_public ?? '',
        is_active: product?.is_active ?? true,
        primary_location_id: product?.primary_location_id ?? '',
    })

    const submit = (event) => {
        event.preventDefault()
        const url = editing ? `/almacen/productos/${product.id}` : '/almacen/productos'
        editing ? put(url) : post(url)
    }

    const fieldError = (field) => errors[field] && <p className="mt-1 text-sm text-rose-600">{errors[field]}</p>

    return (
        <AppShell title={editing ? 'Editar producto de almacén' : 'Nuevo producto de almacén'}>
            <Head title={editing ? 'Editar producto' : 'Nuevo producto'} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div>
                    <Link href="/almacen/productos" className="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-300">← Volver al catálogo</Link>
                    <h1 className="mt-3 text-3xl font-bold text-slate-900 dark:text-white">{editing ? 'Editar producto' : 'Nuevo producto'}</h1>
                </div>
                <form onSubmit={submit} className="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="grid gap-5 sm:grid-cols-2">
                        <label className="sm:col-span-2"><span className="mb-1 block text-sm font-semibold">Nombre *</span><input value={data.name} onChange={(e) => setData('name', e.target.value)} className="w-full rounded-xl border border-slate-300 px-4 py-2.5 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />{fieldError('name')}</label>
                        <label><span className="mb-1 block text-sm font-semibold">SKU *</span><input value={data.sku} onChange={(e) => setData('sku', e.target.value)} className="w-full rounded-xl border border-slate-300 px-4 py-2.5 font-mono dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />{fieldError('sku')}</label>
                        <label><span className="mb-1 block text-sm font-semibold">Código de barras</span><input value={data.barcode} onChange={(e) => setData('barcode', e.target.value)} inputMode="numeric" className="w-full rounded-xl border border-slate-300 px-4 py-2.5 font-mono dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />{fieldError('barcode')}</label>
                        <label><span className="mb-1 block text-sm font-semibold">Tipo</span><select value={data.product_type} onChange={(e) => setData('product_type', e.target.value)} className="w-full rounded-xl border border-slate-300 px-4 py-2.5 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"><option value="SIMPLE">Simple</option><option value="KIT">Kit</option></select>{fieldError('product_type')}</label>
                        <label className="sm:col-span-2"><span className="mb-1 block text-sm font-semibold">Descripción</span><textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows="4" className="w-full rounded-xl border border-slate-300 px-4 py-2.5 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />{fieldError('description')}</label>
                        <label className="sm:col-span-2"><span className="mb-1 block text-sm font-semibold">Ubicación principal</span><select value={data.primary_location_id} onChange={(e) => setData('primary_location_id', e.target.value || '')} className="w-full rounded-xl border border-slate-300 px-4 py-2.5 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"><option value="">Sin ubicación</option>{locations.map((location) => <option key={location.id} value={location.id}>{location.code}{location.name ? ` — ${location.name}` : ''}{!location.is_active ? ' (inactiva)' : ''}</option>)}</select>{fieldError('primary_location_id')}</label>
                        {fields.map(([key, label]) => <label key={key}><span className="mb-1 block text-sm font-semibold">{label}</span><input type="number" min="0" step="0.01" value={data[key]} onChange={(e) => setData(key, e.target.value)} className="w-full rounded-xl border border-slate-300 px-4 py-2.5 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />{fieldError(key)}</label>)}
                        <label className="flex items-center gap-3 sm:col-span-2"><input type="checkbox" checked={Boolean(data.is_active)} onChange={(e) => setData('is_active', e.target.checked)} /> <span className="text-sm font-semibold">Producto activo</span></label>
                    </div>
                    <div className="flex justify-end gap-3"><Link href="/almacen/productos" className="rounded-xl border border-slate-300 px-5 py-2.5 font-semibold dark:border-neutral-700 dark:text-slate-300">Cancelar</Link><button type="submit" disabled={processing} className="rounded-xl bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">{processing ? 'Guardando…' : 'Guardar producto'}</button></div>
                </form>
            </div>
        </AppShell>
    )
}
