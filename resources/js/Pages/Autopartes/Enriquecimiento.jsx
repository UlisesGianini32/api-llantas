import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router } from '@inertiajs/react'
import { useState } from 'react'

const statusStyles = {
    pending: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
    in_review: 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-200',
    approved: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200',
    rejected: 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-200',
}

const statusLabels = {
    pending: 'Pendiente',
    in_review: 'En revisión',
    approved: 'Aprobada',
    rejected: 'Rechazada',
}

function Indicator({ label, ready }) {
    return (
        <span className={`rounded-full px-2 py-1 text-xs font-semibold ${ready ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'}`}>
            {label}: {ready ? 'sí' : 'no'}
        </span>
    )
}

export default function Enriquecimiento({ reviews, filters = {}, statusTotals = {}, issueCodes = [], categories = [], vendors = [], ai = {} }) {
    const [form, setForm] = useState({
        q: filters.q ?? '',
        status: filters.status ?? '',
        issue_code: filters.issue_code ?? '',
        category: filters.category ?? '',
        vendor: filters.vendor ?? '',
    })
    const [auditing, setAuditing] = useState(false)
    const [queueing, setQueueing] = useState(false)

    const submit = (event) => {
        event.preventDefault()
        router.get('/autopartes/enriquecimiento', form, { preserveState: true })
    }

    const runAudit = () => {
        setAuditing(true)
        router.post('/autopartes/enriquecimiento/auditar', { limit: 250 }, {
            preserveScroll: true,
            onFinish: () => setAuditing(false),
        })
    }

    const aiDisabledReason = !ai.enabled
        ? 'La integración de IA está deshabilitada.'
        : (!ai.configured ? 'La credencial de OpenAI no está configurada.' : (ai.daily_remaining < 1 ? 'Se alcanzó el límite diario.' : null))

    const queueBatch = () => {
        const limit = Math.min(ai.max_batch ?? 10, ai.daily_remaining ?? 0)
        if (limit < 1 || !window.confirm(`¿Encolar hasta ${limit} propuestas con IA? Cada resultado requerirá aprobación humana.`)) return

        setQueueing(true)
        router.post('/autopartes/enriquecimiento/ia/lote', {
            limit,
            issue: form.issue_code || null,
        }, {
            preserveScroll: true,
            onFinish: () => setQueueing(false),
        })
    }

    return (
        <AppShell title="Enriquecimiento de autopartes">
            <Head title="Enriquecimiento de autopartes" />
            <div className="space-y-6">
                <div className="flex flex-col justify-between gap-3 lg:flex-row lg:items-center">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Bandeja de enriquecimiento</h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">Audita datos existentes y prepara propuestas para revisión manual.</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" onClick={runAudit} disabled={auditing} className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-60">
                            {auditing ? 'Auditando…' : 'Auditar 250 productos'}
                        </button>
                        <button type="button" onClick={queueBatch} disabled={queueing || Boolean(aiDisabledReason)} title={aiDisabledReason ?? ''} className="rounded-xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500 disabled:cursor-not-allowed disabled:opacity-50">
                            {queueing ? 'Encolando…' : `Encolar IA (${Math.min(ai.max_batch ?? 10, ai.daily_remaining ?? 0)})`}
                        </button>
                        <Link href="/autopartes" className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200">Catálogo</Link>
                    </div>
                </div>

                <div className="rounded-2xl border border-violet-200 bg-violet-50 p-4 text-sm text-violet-900 dark:border-violet-500/20 dark:bg-violet-500/5 dark:text-violet-200">
                    <p className="font-semibold">IA: {ai.model ?? 'sin modelo'} · prompt {ai.prompt_version ?? '—'} · {ai.daily_remaining ?? 0} disponibles hoy</p>
                    <p className="mt-1">{aiDisabledReason ?? 'Los jobs se procesan en la cola autopartes-ai y nunca aprueban una propuesta automáticamente.'}</p>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {Object.keys(statusLabels).map((status) => (
                        <button key={status} type="button" onClick={() => router.get('/autopartes/enriquecimiento', { ...form, status })} className="rounded-2xl border border-slate-200 bg-white p-4 text-left dark:border-neutral-800 dark:bg-neutral-900">
                            <span className={`inline-flex rounded-full px-2 py-1 text-xs font-semibold ${statusStyles[status]}`}>{statusLabels[status]}</span>
                            <p className="mt-3 text-3xl font-bold text-slate-900 dark:text-white">{statusTotals[status] ?? 0}</p>
                        </button>
                    ))}
                </div>

                <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                        <input value={form.q} onChange={(event) => setForm({ ...form, q: event.target.value })} placeholder="Item, MFG, proveedor o descripción" className="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white xl:col-span-2" />
                        <select value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })} className="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white">
                            <option value="">Todos los estados</option>
                            {Object.keys(statusLabels).map((status) => <option key={status} value={status}>{statusLabels[status]}</option>)}
                        </select>
                        <select value={form.issue_code} onChange={(event) => setForm({ ...form, issue_code: event.target.value })} className="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white">
                            <option value="">Todos los problemas</option>
                            {issueCodes.map((code) => <option key={code} value={code}>{code}</option>)}
                        </select>
                        <select value={form.category} onChange={(event) => setForm({ ...form, category: event.target.value })} className="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white">
                            <option value="">Todas las categorías</option>
                            {categories.map((category) => <option key={category} value={category}>{category}</option>)}
                        </select>
                        <select value={form.vendor} onChange={(event) => setForm({ ...form, vendor: event.target.value })} className="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white">
                            <option value="">Todos los proveedores</option>
                            {vendors.map((vendor) => <option key={vendor} value={vendor}>{vendor}</option>)}
                        </select>
                    </div>
                    <div className="mt-4 flex gap-2">
                        <button type="submit" className="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-slate-900">Filtrar</button>
                        <button type="button" onClick={() => router.get('/autopartes/enriquecimiento')} className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 dark:border-neutral-700 dark:text-slate-200">Limpiar</button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 dark:divide-neutral-800">
                            <thead className="bg-slate-50 dark:bg-neutral-950">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Producto</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Proveedor / categoría</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Problemas</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Cobertura</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Estado</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">IA</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-200 dark:divide-neutral-800">
                                {reviews.data.length === 0 ? (
                                    <tr><td colSpan={6} className="px-4 py-8 text-center text-sm text-slate-500">No hay revisiones con estos filtros.</td></tr>
                                ) : reviews.data.map((review) => {
                                    const part = review.automotive_part
                                    const hasYears = part.min_model_year !== null || part.average_model_year !== null || part.max_model_year !== null
                                    const hasDimensions = part.length_inches !== null && part.width_inches !== null && part.height_inches !== null
                                    return (
                                        <tr key={review.id} className="align-top hover:bg-slate-50 dark:hover:bg-neutral-950/50">
                                            <td className="px-4 py-3 text-sm">
                                                <Link href={`/autopartes/enriquecimiento/${review.id}`} className="font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">{part.item_number ?? 'Sin Item #'}</Link>
                                                <p className="mt-1 text-slate-600 dark:text-slate-300">{part.manufacturer_part_number ?? 'Sin MFG Part #'}</p>
                                                <p className="mt-1 max-w-xs truncate text-xs text-slate-500">{part.description_original ?? 'Sin descripción'}</p>
                                            </td>
                                            <td className="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">
                                                <p>{part.vendor ?? '—'}</p><p className="mt-1 text-xs text-slate-500">{part.category ?? '—'}</p>
                                            </td>
                                            <td className="max-w-sm px-4 py-3"><div className="flex flex-wrap gap-1">{(review.issue_codes ?? []).map((code) => <span key={code} className="rounded-full bg-amber-50 px-2 py-1 text-xs font-medium text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">{code}</span>)}</div></td>
                                            <td className="px-4 py-3"><div className="flex max-w-xs flex-wrap gap-1"><Indicator label="Compat." ready={Boolean(part.applicable_models_text)} /><Indicator label="Medidas" ready={hasDimensions} /><Indicator label="Peso" ready={part.weight_pounds !== null} /><Indicator label="Años" ready={hasYears} /></div></td>
                                            <td className="px-4 py-3"><span className={`rounded-full px-2 py-1 text-xs font-semibold ${statusStyles[review.status]}`}>{statusLabels[review.status]}</span></td>
                                            <td className="px-4 py-3 text-xs text-slate-600 dark:text-slate-300">
                                                {review.latest_ai_run ? <><p className="font-semibold">{review.latest_ai_run.status}</p><p>{review.latest_ai_run.model} · {review.latest_ai_run.prompt_version}</p><p>{review.latest_ai_run.total_tokens ?? '—'} tokens</p></> : 'Sin ejecuciones'}
                                            </td>
                                        </tr>
                                    )
                                })}
                            </tbody>
                        </table>
                    </div>
                    {reviews.links?.length > 0 && <div className="flex flex-wrap gap-2 border-t border-slate-200 p-4 dark:border-neutral-800">{reviews.links.map((link, index) => <Link key={index} href={link.url ?? '#'} preserveScroll className={`rounded-lg px-3 py-2 text-sm ${link.active ? 'bg-indigo-600 text-white' : 'border border-slate-200 text-slate-600 dark:border-neutral-700 dark:text-slate-300'} ${!link.url ? 'pointer-events-none opacity-40' : ''}`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}
                </div>
            </div>
        </AppShell>
    )
}
