import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router } from '@inertiajs/react'
import { useState } from 'react'

const statusLabels = {
    unmapped: 'Sin mapeo',
    category_pending: 'Categoría pendiente',
    incomplete: 'Incompleta',
    ready_for_review: 'Lista para revisión',
    ready: 'Lista',
}

export default function CategoriasMeli({ parts, filters = {}, internalCategories = [], meli = {} }) {
    const [form, setForm] = useState({
        q: filters.q ?? '',
        status: filters.status ?? '',
        internal_category: filters.internal_category ?? '',
    })
    const [queueing, setQueueing] = useState(false)
    const disabledReason = !meli.enabled ? 'La integración de metadatos está deshabilitada.' : (meli.daily_remaining < 1 ? 'Se alcanzó el límite diario.' : null)

    const submit = (event) => {
        event.preventDefault()
        router.get('/autopartes/mercado-libre/categorias', form, { preserveState: true })
    }

    const queueBatch = () => {
        const limit = Math.min(meli.max_batch ?? 10, 10)
        if (!window.confirm(`¿Encolar hasta ${limit} autopartes para buscar categorías? No se publicará ningún artículo.`)) return
        setQueueing(true)
        router.post('/autopartes/mercado-libre/categorias/lote', {
            limit,
            internal_category: form.internal_category || null,
        }, { preserveScroll: true, onFinish: () => setQueueing(false) })
    }

    return (
        <AppShell title="Categorías ML de Autopartes">
            <Head title="Categorías ML de Autopartes" />
            <div className="space-y-6">
                <div className="flex flex-col justify-between gap-3 lg:flex-row lg:items-center">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Categorías Mercado Libre</h1>
                        <p className="text-sm text-slate-500">Mapeo controlado para MLM. Esta bandeja no publica artículos.</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" disabled={queueing || Boolean(disabledReason)} title={disabledReason ?? ''} onClick={queueBatch} className="rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{queueing ? 'Encolando…' : 'Buscar lote pequeño'}</button>
                        <Link href="/autopartes/enriquecimiento" className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-neutral-700">Enriquecimiento</Link>
                    </div>
                </div>

                <div className="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900 dark:border-sky-500/20 dark:bg-sky-500/5 dark:text-sky-200">
                    <p className="font-semibold">Sitio {meli.site_id ?? 'MLM'} · reglas {meli.rules_version ?? '—'} · {meli.daily_remaining ?? 0} solicitudes disponibles hoy</p>
                    <p className="mt-1">{disabledReason ?? 'Los resultados son candidatos pendientes hasta que una persona los apruebe.'}</p>
                </div>

                <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="grid gap-3 md:grid-cols-4">
                        <input value={form.q} onChange={(event) => setForm({ ...form, q: event.target.value })} placeholder="Item, MFG o descripción" className="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950" />
                        <select value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })} className="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950"><option value="">Todos los estados</option>{Object.entries(statusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>
                        <select value={form.internal_category} onChange={(event) => setForm({ ...form, internal_category: event.target.value })} className="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950"><option value="">Todas las categorías internas</option>{internalCategories.map((category) => <option key={category} value={category}>{category}</option>)}</select>
                        <button className="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-slate-900">Filtrar</button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="overflow-x-auto"><table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-neutral-800">
                        <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-neutral-950"><tr><th className="px-4 py-3">Producto</th><th className="px-4 py-3">Categoría interna</th><th className="px-4 py-3">Categoría aprobada</th><th className="px-4 py-3">Candidatos</th><th className="px-4 py-3">Readiness</th></tr></thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">{parts.data.map((part) => {
                            const readiness = part.meli_readiness
                            return <tr key={part.id}><td className="px-4 py-3"><Link href={`/autopartes/mercado-libre/categorias/${part.id}`} className="font-semibold text-sky-700 dark:text-sky-300">{part.item_number ?? `#${part.id}`}</Link><p className="text-xs text-slate-500">{part.manufacturer_part_number ?? 'Sin MFG Part #'}</p></td><td className="px-4 py-3">{part.category ?? '—'} / {part.subcategory ?? '—'}</td><td className="px-4 py-3">{readiness?.approved_category_candidate?.category_name ?? '—'}<p className="text-xs text-slate-500">{readiness?.approved_category_candidate?.category_id}</p></td><td className="px-4 py-3">{part.meli_category_candidates?.filter((candidate) => candidate.status === 'pending').length ?? 0}</td><td className="px-4 py-3 font-semibold">{statusLabels[readiness?.status] ?? statusLabels.unmapped}</td></tr>
                        })}</tbody>
                    </table></div>
                    {parts.links?.length > 0 && <div className="flex flex-wrap gap-2 border-t border-slate-200 p-4 dark:border-neutral-800">{parts.links.map((link, index) => <Link key={index} href={link.url ?? '#'} className={`rounded-lg px-3 py-2 text-sm ${link.active ? 'bg-sky-600 text-white' : 'border border-slate-200'} ${!link.url ? 'pointer-events-none opacity-40' : ''}`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}
                </div>
            </div>
        </AppShell>
    )
}
