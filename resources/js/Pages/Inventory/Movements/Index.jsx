import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'

export default function InventoryMovementsIndex({ movements, filters, types }) {
    const { flash = {} } = usePage().props
    const [search, setSearch] = useState(filters?.search || '')
    const [type, setType] = useState(filters?.type || '')

    const submitSearch = (event) => {
        event.preventDefault()
        router.get('/almacen/movimientos', { search, type }, { preserveState: true, replace: true })
    }

    return (
        <AppShell title="Movimientos">
            <Head title="Movimientos de inventario" />
            <div className="space-y-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <div><p className="text-sm font-semibold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-300">Almacén</p><h1 className="mt-2 text-3xl font-bold text-slate-900 dark:text-white">Movimientos</h1><p className="mt-2 text-sm text-slate-500 dark:text-slate-400">El stock físico se calcula desde este historial auditable.</p></div>
                    <Link href="/almacen/movimientos/crear" className="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 font-semibold text-white hover:bg-indigo-700">Registrar movimiento</Link>
                </div>
                {flash.success && <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800">{flash.success}</div>}
                <form onSubmit={submitSearch} className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:flex-row dark:border-neutral-800 dark:bg-neutral-900">
                    <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar producto, SKU, barcode, ubicación o referencia" className="min-w-0 flex-1 rounded-xl border border-slate-300 px-4 py-2.5 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                    <select value={type} onChange={(event) => setType(event.target.value)} className="rounded-xl border border-slate-300 px-4 py-2.5 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"><option value="">Todos los tipos</option>{types.map((item) => <option key={item} value={item}>{item}</option>)}</select>
                    <button type="submit" className="rounded-xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white dark:bg-white dark:text-slate-900">Buscar</button>
                </form>
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900"><div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-neutral-800"><thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-neutral-950 dark:text-slate-400"><tr><th className="px-5 py-4">Fecha</th><th className="px-5 py-4">Producto / SKU</th><th className="px-5 py-4">Ubicación</th><th className="px-5 py-4">Tipo</th><th className="px-5 py-4">Cantidad</th><th className="px-5 py-4">Referencia</th><th className="px-5 py-4">Usuario</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-neutral-800">{movements.data.length === 0 ? <tr><td colSpan="7" className="px-5 py-12 text-center text-slate-500">No hay movimientos para mostrar.</td></tr> : movements.data.map((movement) => <tr key={movement.id}><td className="whitespace-nowrap px-5 py-4">{movement.occurred_at}</td><td className="px-5 py-4"><Link href={`/almacen/productos/${movement.product?.id}`} className="font-semibold text-indigo-600 hover:underline dark:text-indigo-300">{movement.product?.name}</Link><div className="font-mono text-xs text-slate-500">{movement.product?.sku}</div></td><td className="px-5 py-4">{movement.location?.code}</td><td className="px-5 py-4">{movement.type}</td><td className={`px-5 py-4 font-bold ${Number(movement.quantity) >= 0 ? 'text-emerald-700' : 'text-rose-700'}`}>{Number(movement.quantity) >= 0 ? '+' : ''}{movement.quantity}</td><td className="px-5 py-4">{movement.reference || '—'}</td><td className="px-5 py-4">{movement.created_by?.name || '—'}</td></tr>)}</tbody></table></div>{movements.links?.length > 3 && <div className="flex flex-wrap gap-2 border-t border-slate-200 p-4 dark:border-neutral-800">{movements.links.map((link, index) => link.url ? <Link key={index} href={link.url} preserveState className={`rounded-lg border px-3 py-1.5 text-sm ${link.active ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 dark:border-neutral-700 dark:text-slate-300'}`} dangerouslySetInnerHTML={{ __html: link.label }} /> : <span key={index} className="rounded-lg border border-slate-100 px-3 py-1.5 text-sm text-slate-400 dark:border-neutral-800" dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}</div>
            </div>
        </AppShell>
    )
}
