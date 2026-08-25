import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router } from '@inertiajs/react'
import { useState } from 'react'

const statuses = {
    not_generated: 'Sin generar',
    draft: 'Borrador',
    incomplete: 'Incompleto',
    pending_review: 'Pendiente de revisión',
    approved: 'Aprobado internamente',
    rejected: 'Rechazado',
    stale: 'Obsoleto',
}

const errorCodes = [
    'missing_approved_enrichment', 'missing_approved_category', 'stale_category_mapping',
    'missing_price_mxn', 'missing_exchange_rate', 'invalid_stock', 'missing_images',
    'missing_required_attribute', 'missing_compatibility', 'invalid_title',
    'invalid_description', 'unsupported_currency', 'stale_source_data',
]

export default function BorradoresMeli({ parts, filters = {}, statusTotals = {}, drafts = {} }) {
    const [form, setForm] = useState({ q: filters.q ?? '', status: filters.status ?? '', error: filters.error ?? '' })
    const submit = (event) => {
        event.preventDefault()
        router.get('/autopartes/mercado-libre/borradores', form, { preserveState: true })
    }

    return (
        <AppShell title="Borradores ML de Autopartes">
            <Head title="Borradores ML de Autopartes" />
            <div className="space-y-6">
                <div className="flex flex-col justify-between gap-3 lg:flex-row lg:items-center">
                    <div><h1 className="text-2xl font-bold text-slate-900 dark:text-white">Borradores Mercado Libre</h1><p className="text-sm text-slate-500">Preparación y validación local de publicaciones futuras.</p></div>
                    <div className="flex flex-wrap gap-2"><Link href="/autopartes/medios" className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-neutral-700">Medios</Link><Link href="/autopartes/precios" className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-neutral-700">Precios</Link><Link href="/autopartes/mercado-libre/categorias" className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-neutral-700">Categorías ML</Link></div>
                </div>

                <div className="rounded-2xl border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                    <p className="font-bold">Borrador interno: todavía no publicado en Mercado Libre</p>
                    <p className="mt-1 text-sm">Integración {drafts.enabled ? 'habilitada' : 'deshabilitada'} · reglas {drafts.rules_version ?? '—'} · lote máximo {drafts.max_batch ?? 10}.</p>
                </div>

                <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">{Object.entries(statuses).filter(([key]) => key !== 'not_generated').map(([key, label]) => <div key={key} className="rounded-xl border border-slate-200 bg-white p-3 dark:border-neutral-800 dark:bg-neutral-900"><p className="text-xs text-slate-500">{label}</p><p className="text-xl font-bold">{statusTotals[key] ?? 0}</p></div>)}</div>

                <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="grid gap-3 md:grid-cols-4">
                        <input value={form.q} onChange={(event) => setForm({ ...form, q: event.target.value })} placeholder="Item, MFG, título o proveedor" className="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950" />
                        <select value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })} className="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950"><option value="">Todos los estados</option>{Object.entries(statuses).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>
                        <select value={form.error} onChange={(event) => setForm({ ...form, error: event.target.value })} className="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950"><option value="">Todos los errores</option>{errorCodes.map((code) => <option key={code} value={code}>{code}</option>)}</select>
                        <button className="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-slate-900">Filtrar</button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-neutral-800"><thead className="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-neutral-950"><tr><th className="px-4 py-3">Autoparte</th><th className="px-4 py-3">Proveedor</th><th className="px-4 py-3">Título</th><th className="px-4 py-3">Estado</th><th className="px-4 py-3">Errores</th><th className="px-4 py-3">Versión</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-neutral-800">{parts.data.map((part) => {
                        const draft = part.latest_meli_draft
                        return <tr key={part.id}><td className="px-4 py-3"><Link href={`/autopartes/mercado-libre/borradores/autopartes/${part.id}`} className="font-semibold text-sky-700 dark:text-sky-300">{part.item_number ?? `#${part.id}`}</Link><p className="text-xs text-slate-500">{part.manufacturer_part_number ?? 'Sin MFG Part #'}</p></td><td className="px-4 py-3">{part.vendor ?? '—'}</td><td className="max-w-sm truncate px-4 py-3">{draft?.title ?? '—'}</td><td className="px-4 py-3 font-semibold">{statuses[draft?.status] ?? statuses.not_generated}</td><td className="px-4 py-3">{draft?.blocking_errors?.length ?? 0}</td><td className="px-4 py-3">{draft?.version ?? '—'}</td></tr>
                    })}</tbody></table></div>
                    {parts.links?.length > 0 && <div className="flex flex-wrap gap-2 border-t border-slate-200 p-4 dark:border-neutral-800">{parts.links.map((link, index) => <Link key={index} href={link.url ?? '#'} className={`rounded-lg px-3 py-2 text-sm ${link.active ? 'bg-sky-600 text-white' : 'border border-slate-200'} ${!link.url ? 'pointer-events-none opacity-40' : ''}`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}
                </div>
            </div>
        </AppShell>
    )
}
