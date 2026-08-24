import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router } from '@inertiajs/react'

function Issues({ title, issues = [], tone = 'rose' }) {
    const classes = tone === 'amber'
        ? 'rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs dark:border-amber-500/20 dark:bg-amber-500/5'
        : 'rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs dark:border-rose-500/20 dark:bg-rose-500/5'
    return <div><h3 className="text-sm font-semibold">{title} ({issues.length})</h3><div className="mt-2 space-y-2">{issues.map((issue, index) => <div key={`${issue.code}-${index}`} className={classes}><strong>{issue.code}</strong> · {issue.field}<p className="mt-1">{issue.message}</p>{Object.keys(issue.metadata ?? {}).length > 0 && <pre className="mt-1 whitespace-pre-wrap">{JSON.stringify(issue.metadata)}</pre>}</div>)}{issues.length === 0 && <p className="text-xs text-slate-500">Ninguno.</p>}</div></div>
}

export default function BorradorMeliDetalle({ part, preview, drafts = {} }) {
    const history = part.meli_drafts ?? []
    const latest = history[0]
    const generate = () => {
        if (!window.confirm('¿Generar el borrador interno? No se realizará ninguna publicación ni solicitud externa.')) return
        router.post(`/autopartes/mercado-libre/borradores/autopartes/${part.id}/generar`)
    }
    const reject = (draft) => {
        const review_notes = window.prompt('Motivo obligatorio del rechazo:')
        if (review_notes) router.post(`/autopartes/mercado-libre/borradores/${draft.id}/rechazar`, { review_notes })
    }

    return (
        <AppShell title="Borrador ML de autoparte">
            <Head title={`Borrador ${part.item_number ?? part.id}`} />
            <div className="space-y-6">
                <div className="flex flex-col justify-between gap-3 md:flex-row md:items-center"><div><h1 className="text-2xl font-bold text-slate-900 dark:text-white">{part.item_number ?? `Autoparte #${part.id}`}</h1><p className="text-sm text-slate-500">{part.manufacturer_part_number ?? 'Sin MFG Part #'} · {part.vendor ?? 'Sin proveedor'}</p></div><div className="flex gap-2"><button type="button" disabled={!drafts.enabled} title={!drafts.enabled ? 'La generación está deshabilitada.' : ''} onClick={generate} className="rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-40">{latest ? 'Regenerar' : 'Generar'} borrador</button><Link href="/autopartes/mercado-libre/borradores" className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-neutral-700">Volver</Link></div></div>

                <div className="rounded-2xl border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100"><p className="font-bold">Borrador interno: todavía no publicado en Mercado Libre</p><p className="mt-1 text-sm">Aprobar solo registra una decisión local. No existe acción de publicación en esta pantalla.</p></div>

                <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900"><div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="text-lg font-semibold">Vista previa determinística</h2><p className="text-xs text-slate-500">Fingerprint: {preview.fingerprint}</p></div><span className={`rounded-full px-3 py-1 text-xs font-bold ${preview.eligible ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-800'}`}>{preview.eligible ? 'Elegible para revisión' : 'Incompleto'}</span></div><div className="mt-4 grid gap-4 lg:grid-cols-2"><div className="space-y-2 text-sm"><p><strong>Categoría:</strong> {preview.payload.category_name ?? '—'} ({preview.payload.category_id ?? '—'})</p><p><strong>Título:</strong> {preview.payload.title ?? '—'}</p><p><strong>Descripción:</strong> {preview.payload.description ?? '—'}</p><p><strong>Precio:</strong> {preview.payload.price_mxn ?? '—'} {preview.payload.currency}</p><p><strong>Stock:</strong> {preview.payload.stock ?? '—'} · <strong>Condición:</strong> {preview.payload.condition ?? '—'}</p><p><strong>Imágenes:</strong> {preview.payload.prepared_images.length} · <strong>Atributos:</strong> {preview.payload.prepared_attributes.length}</p></div><div className="grid gap-4 sm:grid-cols-2"><Issues title="Errores bloqueantes" issues={preview.blocking_errors} /><Issues title="Advertencias" issues={preview.warnings} tone="amber" /></div></div><details className="mt-4"><summary className="cursor-pointer text-sm font-semibold text-slate-500">Snapshot técnico utilizado</summary><pre className="mt-2 max-h-96 overflow-auto whitespace-pre-wrap rounded-xl bg-slate-50 p-3 text-xs dark:bg-neutral-950">{JSON.stringify(preview.source_snapshot, null, 2)}</pre></details></section>

                <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900"><h2 className="text-lg font-semibold">Versiones e historial</h2><div className="mt-4 space-y-4">{history.map((draft) => <article key={draft.id} className="rounded-xl border border-slate-200 p-4 dark:border-neutral-700"><div className="flex flex-col justify-between gap-3 md:flex-row"><div><h3 className="font-semibold">Versión {draft.version} · {draft.status}</h3><p className="text-xs text-slate-500">Generado {draft.generated_at} · fingerprint {draft.fingerprint}</p><p className="mt-1 text-sm">{draft.title ?? 'Sin título'} · {draft.price_mxn ?? '—'} {draft.currency}</p>{draft.review_notes && <p className="mt-1 text-sm">Nota: {draft.review_notes}</p>}</div><div className="flex flex-wrap gap-2">{draft.status === 'pending_review' && <button type="button" onClick={() => router.post(`/autopartes/mercado-libre/borradores/${draft.id}/aprobar`)} className="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white">Aprobar internamente</button>}{['draft', 'incomplete', 'pending_review'].includes(draft.status) && <button type="button" onClick={() => reject(draft)} className="rounded-lg bg-rose-600 px-3 py-2 text-xs font-semibold text-white">Rechazar</button>}{['approved', 'rejected'].includes(draft.status) && <button type="button" onClick={() => router.post(`/autopartes/mercado-libre/borradores/${draft.id}/pendiente`)} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold">Volver a pendiente</button>}<button type="button" disabled={!drafts.enabled} onClick={() => router.post(`/autopartes/mercado-libre/borradores/${draft.id}/regenerar`, { force: true })} className="rounded-lg border border-slate-200 px-3 py-2 text-xs font-semibold disabled:opacity-40">Regenerar</button></div></div><details className="mt-3"><summary className="cursor-pointer text-xs font-semibold text-slate-500">Errores, advertencias y eventos</summary><pre className="mt-2 max-h-96 overflow-auto whitespace-pre-wrap rounded-xl bg-slate-50 p-3 text-xs dark:bg-neutral-950">{JSON.stringify({ blocking_errors: draft.blocking_errors, warnings: draft.warnings, events: draft.events }, null, 2)}</pre></details></article>)}{history.length === 0 && <p className="text-sm text-slate-500">Todavía no existen versiones persistidas.</p>}</div></section>
            </div>
        </AppShell>
    )
}
