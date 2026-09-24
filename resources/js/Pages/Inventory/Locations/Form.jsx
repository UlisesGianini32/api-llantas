import AppShell from '@/Components/layout/AppShell'
import { Head, Link, useForm } from '@inertiajs/react'

export default function InventoryLocationForm({ mode, location }) {
    const editing = mode === 'edit'
    const { data, setData, post, put, processing, errors } = useForm({
        code: location?.code || '',
        name: location?.name || '',
        description: location?.description || '',
        sort_order: location?.sort_order ?? '',
        is_active: location?.is_active ?? true,
    })
    const submit = (event) => {
        event.preventDefault()
        const url = editing ? `/almacen/ubicaciones/${location.id}` : '/almacen/ubicaciones'
        editing ? put(url) : post(url)
    }
    const fieldError = (field) => errors[field] && <p className="mt-1 text-sm text-rose-600">{errors[field]}</p>

    return <AppShell title={editing ? 'Editar ubicación' : 'Nueva ubicación'}><Head title={editing ? 'Editar ubicación' : 'Nueva ubicación'} /><div className="mx-auto max-w-2xl space-y-6"><div><Link href="/almacen/ubicaciones" className="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-300">← Volver a ubicaciones</Link><h1 className="mt-3 text-3xl font-bold text-slate-900 dark:text-white">{editing ? 'Editar ubicación' : 'Nueva ubicación'}</h1></div><form onSubmit={submit} className="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"><label><span className="mb-1 block text-sm font-semibold">Código *</span><input value={data.code} onChange={(e) => setData('code', e.target.value)} placeholder="A1-1" className="w-full rounded-xl border border-slate-300 px-4 py-2.5 font-mono uppercase dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />{fieldError('code')}</label><label><span className="mb-1 block text-sm font-semibold">Nombre</span><input value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="Pasillo A, Estante 1, Nivel 1" className="w-full rounded-xl border border-slate-300 px-4 py-2.5 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />{fieldError('name')}</label><label><span className="mb-1 block text-sm font-semibold">Descripción</span><textarea value={data.description} onChange={(e) => setData('description', e.target.value)} rows="4" className="w-full rounded-xl border border-slate-300 px-4 py-2.5 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />{fieldError('description')}</label><label><span className="mb-1 block text-sm font-semibold">Orden</span><input type="number" min="0" value={data.sort_order} onChange={(e) => setData('sort_order', e.target.value)} className="w-full rounded-xl border border-slate-300 px-4 py-2.5 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />{fieldError('sort_order')}</label><label className="flex items-center gap-3"><input type="checkbox" checked={Boolean(data.is_active)} onChange={(e) => setData('is_active', e.target.checked)} /> <span className="text-sm font-semibold">Ubicación activa</span></label><div className="flex justify-end gap-3"><Link href="/almacen/ubicaciones" className="rounded-xl border border-slate-300 px-5 py-2.5 font-semibold dark:border-neutral-700 dark:text-slate-300">Cancelar</Link><button type="submit" disabled={processing} className="rounded-xl bg-indigo-600 px-5 py-2.5 font-semibold text-white hover:bg-indigo-700 disabled:opacity-50">{processing ? 'Guardando…' : 'Guardar ubicación'}</button></div></form></div></AppShell>
}
