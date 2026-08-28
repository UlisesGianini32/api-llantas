import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router } from '@inertiajs/react'
import { useState } from 'react'

export default function PublicacionesMeli({ publications, filters = {}, publisher = {}, accounts = [], approvedDrafts = [] }) {
    const [query, setQuery] = useState({ q: filters.q ?? '', status: filters.status ?? '' })
    const [preflight, setPreflight] = useState({ draft_id: '', meli_account_id: '' })
    const create = (event) => { event.preventDefault(); router.post('/autopartes/mercado-libre/publicaciones/preflight', preflight) }
    return <AppShell title="Publicaciones ML de Autopartes">
        <Head title="Publicaciones ML de Autopartes" />
        <div className="space-y-6">
            <div><h1 className="text-2xl font-bold">Publicador controlado de Autopartes</h1><p className="text-sm text-slate-500">Preflight, validación, aprobación final y publicación son pasos separados.</p></div>
            <div className="rounded-2xl border border-rose-300 bg-rose-50 p-4 text-rose-950 dark:bg-rose-500/10 dark:text-rose-100">
                <p className="font-bold">Esta acción puede crear una publicación real en Mercado Libre</p>
                <ul className="mt-2 list-disc space-y-1 pl-5 text-sm"><li>La validación remota no publica.</li><li>Después de crear el artículo no se repetirá automáticamente el POST.</li><li>Confirma cuenta, precio, stock y categoría.</li></ul>
                <p className="mt-2 text-xs">Publicador: {publisher.enabled ? 'activo' : 'deshabilitado'} · validación: {publisher.remote_validation_enabled ? 'activa' : 'deshabilitada'} · imágenes: {publisher.image_upload_enabled ? 'activas' : 'deshabilitadas'} · live: {publisher.live_enabled ? 'activo' : 'deshabilitado'}</p>
            </div>
            <form onSubmit={create} className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                <h2 className="font-semibold">Crear preflight local</h2><p className="mb-3 text-xs text-slate-500">No realiza HTTP. La cuenta se selecciona explícitamente.</p>
                <div className="grid gap-3 md:grid-cols-3"><select required value={preflight.draft_id} onChange={e => setPreflight({...preflight, draft_id:e.target.value})} className="rounded-xl border p-2 dark:bg-neutral-950"><option value="">Borrador aprobado</option>{approvedDrafts.map(d => <option key={d.id} value={d.id}>#{d.id} v{d.version} · {d.title}</option>)}</select><select required value={preflight.meli_account_id} onChange={e => setPreflight({...preflight, meli_account_id:e.target.value})} className="rounded-xl border p-2 dark:bg-neutral-950"><option value="">Cuenta destino</option>{accounts.map(a => <option key={a.id} value={a.id}>{a.nickname ?? a.meli_user_id} · {a.meli_user_id}</option>)}</select><button disabled={!publisher.enabled} className="rounded-xl bg-sky-600 px-4 py-2 font-semibold text-white disabled:opacity-40">Crear preflight</button></div>
            </form>
            <form onSubmit={e => {e.preventDefault(); router.get('/autopartes/mercado-libre/publicaciones', query, {preserveState:true})}} className="grid gap-3 rounded-2xl border border-slate-200 p-4 md:grid-cols-3 dark:border-neutral-800"><input value={query.q} onChange={e=>setQuery({...query,q:e.target.value})} placeholder="Título, item_id o fingerprint" className="rounded-xl border p-2 dark:bg-neutral-950"/><input value={query.status} onChange={e=>setQuery({...query,status:e.target.value})} placeholder="Estado exacto" className="rounded-xl border p-2 dark:bg-neutral-950"/><button className="rounded-xl bg-slate-900 px-4 py-2 text-white dark:bg-white dark:text-black">Filtrar</button></form>
            <div className="overflow-x-auto rounded-2xl border border-slate-200 dark:border-neutral-800"><table className="min-w-full text-sm"><thead><tr className="text-left"><th className="p-3">ID</th><th className="p-3">Borrador</th><th className="p-3">Cuenta</th><th className="p-3">Estado</th><th className="p-3">Item</th><th className="p-3">Validación</th></tr></thead><tbody>{publications.data.map(p=><tr key={p.id} className="border-t"><td className="p-3"><Link className="font-semibold text-sky-600" href={`/autopartes/mercado-libre/publicaciones/${p.id}`}>#{p.id}</Link></td><td className="p-3">#{p.draft?.id} · {p.draft?.title}</td><td className="p-3">{p.account?.nickname ?? p.seller_id}</td><td className="p-3 font-semibold">{p.status}</td><td className="p-3">{p.meli_item_id ?? '—'}</td><td className="p-3">{p.remote_validation_status}</td></tr>)}</tbody></table></div>
            {publications.links && <div className="flex flex-wrap gap-2">{publications.links.map((link,i)=><Link key={i} href={link.url ?? '#'} className={`rounded px-3 py-2 text-sm ${link.active?'bg-sky-600 text-white':'border'} ${!link.url?'pointer-events-none opacity-40':''}`} dangerouslySetInnerHTML={{__html:link.label}} />)}</div>}
        </div>
    </AppShell>
}
