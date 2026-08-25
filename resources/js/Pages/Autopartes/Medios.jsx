import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router } from '@inertiajs/react'
import { useState } from 'react'

export default function Medios({ parts, filters = {}, settings = {} }) {
    const [q, setQ] = useState(filters.q ?? '')
    return <AppShell title="Medios de Autopartes"><Head title="Medios de Autopartes" /><div className="space-y-5">
        <div className="flex flex-wrap items-center justify-between gap-3"><div><h1 className="text-2xl font-bold">Medios respaldados</h1><p className="text-sm text-slate-500">Administración privada y aprobada por personas.</p></div><div className="flex gap-2"><Link href="/autopartes/precios" className="rounded-lg border px-3 py-2">Precios</Link><Link href="/autopartes/mercado-libre/borradores" className="rounded-lg border px-3 py-2">Borradores</Link></div></div>
        <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 font-semibold text-amber-950">La imagen debe ser propia o estar autorizada.</div>
        <form onSubmit={e => { e.preventDefault(); router.get('/autopartes/medios', { q }, { preserveState: true }) }} className="flex gap-2"><input value={q} onChange={e => setQ(e.target.value)} className="flex-1 rounded-lg border px-3 py-2" placeholder="Item, MFG Part # o descripción"/><button className="rounded-lg bg-slate-900 px-4 py-2 text-white">Buscar</button></form>
        <div className="overflow-x-auto rounded-xl border"><table className="min-w-full text-sm"><thead><tr className="border-b text-left"><th className="p-3">Autoparte</th><th>Imágenes</th><th>Aprobadas</th><th>Borradores obsoletos</th></tr></thead><tbody>{parts.data.map(part => <tr key={part.id} className="border-b"><td className="p-3"><Link className="font-semibold text-sky-700" href={`/autopartes/medios/${part.id}`}>{part.item_number ?? `#${part.id}`}</Link><div className="text-xs text-slate-500">{part.manufacturer_part_number}</div></td><td>{part.media_count}</td><td>{part.approved_media_count}</td><td>{part.stale_drafts_count}</td></tr>)}</tbody></table></div>
        <p className="text-xs text-slate-500">Integración {settings.enabled ? 'habilitada' : 'deshabilitada'} · máximo {settings.max_images} imágenes activas por autoparte.</p>
    </div></AppShell>
}
