import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'

export default function InventoryLocationsIndex({ locations, filters }) {
    const { flash = {} } = usePage().props
    const [search, setSearch] = useState(filters?.search || '')

    const submitSearch = (event) => {
        event.preventDefault()
        router.get('/almacen/ubicaciones', { search }, { preserveState: true, replace: true })
    }

    const toggle = (id) => {
        router.patch(`/almacen/ubicaciones/${id}/estado`, {}, { preserveScroll: true })
    }

    return (
        <AppShell title="Ubicaciones">
            <Head title="Ubicaciones de almacén" />
            <div className="space-y-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <div><p className="text-sm font-semibold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-300">Almacén</p><h1 className="mt-2 text-3xl font-bold text-slate-900 dark:text-white">Ubicaciones</h1><p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Organiza pasillos, estantes y niveles del almacén.</p></div>
                    <Link href="/almacen/ubicaciones/crear" className="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 font-semibold text-white hover:bg-indigo-700">Nueva ubicación</Link>
                </div>
                {flash.success && <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">{flash.success}</div>}
                <form onSubmit={submitSearch} className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:flex-row dark:border-neutral-800 dark:bg-neutral-900"><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar por código o nombre" className="min-w-0 flex-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" /><button type="submit" className="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white dark:bg-white dark:text-slate-900">Buscar</button></form>
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900"><div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-neutral-800"><thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-neutral-950 dark:text-slate-400"><tr><th className="px-5 py-4">Código</th><th className="px-5 py-4">Nombre</th><th className="px-5 py-4">Estado</th><th className="px-5 py-4">Productos asignados</th><th className="px-5 py-4">Acciones</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-neutral-800">{locations.data.length === 0 ? <tr><td colSpan="5" className="px-5 py-12 text-center text-slate-500">No hay ubicaciones para mostrar.</td></tr> : locations.data.map((location) => <tr key={location.id} className="hover:bg-slate-50/70 dark:hover:bg-neutral-950/50"><td className="px-5 py-4 font-mono font-semibold text-slate-900 dark:text-white">{location.code}</td><td className="px-5 py-4 text-slate-700 dark:text-slate-300">{location.name || '—'}</td><td className="px-5 py-4"><span className={`rounded-full px-3 py-1 text-xs font-semibold ${location.is_active ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-slate-100 text-slate-600 dark:bg-neutral-800 dark:text-slate-300'}`}>{location.is_active ? 'Activa' : 'Inactiva'}</span></td><td className="px-5 py-4">{location.products_count}</td><td className="px-5 py-4"><div className="flex flex-wrap gap-2"><Link href={`/almacen/ubicaciones/${location.id}`} className="text-indigo-600 hover:underline dark:text-indigo-300">Ver</Link><Link href={`/almacen/ubicaciones/${location.id}/editar`} className="text-indigo-600 hover:underline dark:text-indigo-300">Editar</Link><button type="button" onClick={() => toggle(location.id)} className="text-amber-700 hover:underline dark:text-amber-300">{location.is_active ? 'Desactivar' : 'Activar'}</button></div></td></tr>)}</tbody></table></div>{locations.links?.length > 3 && <div className="flex flex-wrap gap-2 border-t border-slate-200 p-4 dark:border-neutral-800">{locations.links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveState className={`rounded-lg border px-3 py-1.5 text-sm ${link.active ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 dark:border-neutral-700 dark:text-slate-300'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="rounded-lg border border-slate-100 px-3 py-1.5 text-sm text-slate-400 dark:border-neutral-800" dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}</div>
            </div>
        </AppShell>
    )
}
