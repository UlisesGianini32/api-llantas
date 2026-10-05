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
        brand: product?.brand || '',
        supplier: product?.supplier || '',
        description: product?.description || '',
        cost: product?.cost ?? '',
        price_mercado_libre: product?.price_mercado_libre ?? '',
        price_amazon: product?.price_amazon ?? '',
        price_stylist: product?.price_stylist ?? '',
        price_public: product?.price_public ?? '',
        is_active: product?.is_active ?? true,
        primary_location_id: product?.primary_location_id ?? '',
        secondary_location_id: product?.secondary_location_id ?? '',
        reserve_notes: product?.reserve_notes || '',
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
                        <label><span className="mb-1 block text-sm font-semibold">Marca</span><input value={data.brand} onChange={(e) => setData('brand', e.target.value)} placeholder="Ej. BUNEE, JOICO, MICHELIN..." className="w-full rounded-xl border border-slate-300 px-4 py-2.5 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />{fieldError('brand')}</label>
                        <label><span className="mb-1 block text-sm font-semibold">Proveedor / Fabricante</span><input value={data.supplier} onChange={(e) => setData('supplier', e.target.value)} placeholder="Distribuidor o fabricante" className="w-full rounded-xl border border-slate-300 px-4 py-2.5 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />{fieldError('supplier')}</label>
                        <label><span className="mb-1 block text-sm font-semibold">SKU *</span><input value={data.sku} onChange={(e) => setData('sku', e.target.value)} className="w-full rounded-xl border border-slate-300 px-4 py-2.5 font-mono dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />{fieldError('sku')}</label>
                        <label><span className="mb-1 block text-sm font-semibold">Código de barras</span><input value={data.barcode} onChange={(e) => setData('barcode', e.target.value)} inputMode="numeric" className="w-full rounded-xl border border-slate-300 px-4 py-2.5 font-mono dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />{fieldError('barcode')}</label>
                        <label><span className="mb-1 block text-sm font-semibold">Tipo</span><select value={data.product_type} onChange={(e) => setData('product_type', e.target.value)} className="w-full rounded-xl border border-slate-300 px-4 py-2.5 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"><option value="SIMPLE">Simple</option><option value="KIT">Kit</option></select>{fieldError('product_type')}</label>
                        <label className="sm:col-span-2"><span className="mb-1 block text-sm font-semibold">Descripción</span><textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows="4" className="w-full rounded-xl border border-slate-300 px-4 py-2.5 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />{fieldError('description')}</label>
                        {data.product_type === 'KIT' ? (
                            <div className="sm:col-span-2 rounded-2xl border border-indigo-200 bg-indigo-50/70 p-4 dark:border-indigo-900/60 dark:bg-indigo-950/30">
                                <div className="flex items-start gap-3">
                                    <span className="text-2xl">📦</span>
                                    <div className="flex-1">
                                        <h4 className="text-sm font-bold text-indigo-950 dark:text-indigo-200">
                                            Producto tipo Kit (Sin ubicación fija propia)
                                        </h4>
                                        <p className="mt-1 text-xs text-indigo-800 dark:text-indigo-300">
                                            Los kits no se almacenan como un producto físico en una sola gaveta; las ubicaciones de picking y reserva se toman automáticamente de los componentes que lo integran.
                                        </p>
                                        {editing && product?.kit_components && product.kit_components.length > 0 ? (
                                            <div className="mt-3 border-t border-indigo-200/60 pt-3 dark:border-indigo-800/40">
                                                <div className="mb-2 flex items-center justify-between">
                                                    <span className="text-xs font-bold uppercase tracking-wider text-indigo-900 dark:text-indigo-200">
                                                        Ubicaciones de componentes configurados:
                                                    </span>
                                                    <Link href={`/almacen/kits/${product.id}/editar-componentes`} className="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                                                        Configurar componentes →
                                                    </Link>
                                                </div>
                                                <div className="grid gap-2 sm:grid-cols-2">
                                                    {product.kit_components.map((kc) => (
                                                        <div key={kc.id || kc.component_product_id} className="rounded-xl border border-indigo-200/80 bg-white p-3 text-xs shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                                                            <div className="font-semibold text-slate-900 dark:text-white">
                                                                {kc.component?.name || `Componente #${kc.component_product_id}`} <span className="font-mono text-slate-500">({kc.component?.sku})</span>
                                                            </div>
                                                            <div className="mt-1 text-slate-600 dark:text-slate-400">
                                                                Cantidad requerida: <strong>{kc.quantity} pza{kc.quantity > 1 ? 's' : ''}</strong>
                                                            </div>
                                                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                                                <span className="inline-flex items-center rounded-md bg-emerald-100 px-2 py-0.5 text-xs font-bold text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                                                    📍 Picking: {kc.component?.primary_location?.code || 'Sin ubicación'}
                                                                </span>
                                                                {kc.component?.secondary_location?.code ? (
                                                                    <span className="inline-flex items-center rounded-md bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">
                                                                        📦 Res: {kc.component.secondary_location.code}
                                                                    </span>
                                                                ) : null}
                                                            </div>
                                                            {kc.component?.reserve_notes ? (
                                                                <div className="mt-1 text-[11px] text-slate-500 italic">
                                                                    Nota: {kc.component.reserve_notes}
                                                                </div>
                                                            ) : null}
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        ) : (
                                            <p className="mt-2 text-xs italic text-indigo-700 dark:text-indigo-400">
                                                Al guardar el kit, podrás configurar sus componentes y el sistema tomará automáticamente sus ubicaciones de almacén.
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <>
                                <label><span className="mb-1 block text-sm font-semibold">📍 Ubicación principal (Picking)</span><select value={data.primary_location_id} onChange={(e) => setData('primary_location_id', e.target.value || '')} className="w-full rounded-xl border border-slate-300 px-4 py-2.5 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"><option value="">Sin ubicación de picking</option>{locations.map((location) => <option key={location.id} value={location.id}>{location.code}{location.name ? ` — ${location.name}` : ''}{!location.is_active ? ' (inactiva)' : ''}</option>)}</select>{fieldError('primary_location_id')}</label>
                                <label><span className="mb-1 block text-sm font-semibold">📦 Ubicación secundaria / Reserva (Pulmón)</span><select value={data.secondary_location_id} onChange={(e) => setData('secondary_location_id', e.target.value || '')} className="w-full rounded-xl border border-slate-300 px-4 py-2.5 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"><option value="">Sin reserva (cabe todo en picking)</option>{locations.map((location) => <option key={location.id} value={location.id}>{location.code}{location.name ? ` — ${location.name}` : ''}{!location.is_active ? ' (inactiva)' : ''}</option>)}</select>{fieldError('secondary_location_id')}</label>
                                <label className="sm:col-span-2"><span className="mb-1 block text-sm font-semibold">Notas / Detalle de reserva (sobreflujo)</span><input value={data.reserve_notes} onChange={(e) => setData('reserve_notes', e.target.value)} placeholder="Ej. Tarima superior 2, caja 3, excedente sobre anaquel..." className="w-full rounded-xl border border-slate-300 px-4 py-2.5 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />{fieldError('reserve_notes')}</label>
                            </>
                        )}
                        {fields.map(([key, label]) => <label key={key}><span className="mb-1 block text-sm font-semibold">{label}</span><input type="number" min="0" step="0.01" value={data[key]} onChange={(e) => setData(key, e.target.value)} className="w-full rounded-xl border border-slate-300 px-4 py-2.5 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />{fieldError(key)}</label>)}
                        <label className="flex items-center gap-3 sm:col-span-2"><input type="checkbox" checked={Boolean(data.is_active)} onChange={(e) => setData('is_active', e.target.checked)} /> <span className="text-sm font-semibold">Producto activo</span></label>
                    </div>
                    <div className="flex justify-end gap-3"><Link href="/almacen/productos" className="rounded-xl border border-slate-300 px-5 py-2.5 font-semibold dark:border-neutral-700 dark:text-slate-300">Cancelar</Link><button type="submit" disabled={processing} className="rounded-xl bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">{processing ? 'Guardando…' : 'Guardar producto'}</button></div>
                </form>
            </div>
        </AppShell>
    )
}
