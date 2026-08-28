import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router, useForm } from '@inertiajs/react'

const fieldClass = 'mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white'

function OriginalField({ label, value }) {
    return <div><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</dt><dd className="mt-1 whitespace-pre-wrap text-sm text-slate-800 dark:text-slate-200">{value ?? '—'}</dd></div>
}

function ProposalCard({ title, proposal }) {
    return (
        <article className="rounded-xl border border-slate-200 p-4 dark:border-neutral-700">
            <h3 className="text-sm font-semibold text-slate-900 dark:text-white">{title}</h3>
            {proposal ? <dl className="mt-3 space-y-2 text-sm"><OriginalField label="Título" value={proposal.title_es ?? proposal.proposed_title} /><OriginalField label="Descripción" value={proposal.description_es ?? proposal.proposed_description} /><OriginalField label="Marca" value={proposal.brand_normalized ?? proposal.proposed_brand} /><OriginalField label="Categoría" value={proposal.category_suggestion ?? proposal.proposed_category} /></dl> : <p className="mt-2 text-sm text-slate-500">Sin propuesta conservada.</p>}
        </article>
    )
}

export default function EnriquecimientoDetalle({ review, part, ai = {}, proposalComparison = {} }) {
    const { data, setData, put, post, processing, errors } = useForm({
        proposed_title: review.proposed_title ?? '',
        proposed_description: review.proposed_description ?? '',
        proposed_brand: review.proposed_brand ?? '',
        proposed_category: review.proposed_category ?? '',
        proposed_compatibility: review.proposed_compatibility ? JSON.stringify(review.proposed_compatibility, null, 2) : '',
        proposed_attributes: review.proposed_attributes ? JSON.stringify(review.proposed_attributes, null, 2) : '',
        confidence_score: review.confidence_score ?? '',
        reviewer_notes: review.reviewer_notes ?? '',
    })

    const save = (event) => {
        event.preventDefault()
        put(`/autopartes/enriquecimiento/${review.id}`, { preserveScroll: true })
    }

    const latestAiRun = review.ai_runs?.[0] ?? null
    const aiPath = review.enrichment_source === 'openai'
        ? `/autopartes/enriquecimiento/${review.id}/ia/regenerar`
        : `/autopartes/enriquecimiento/${review.id}/ia/generar`
    const queueAi = () => {
        if (!window.confirm('¿Encolar una propuesta con IA? El resultado seguirá pendiente de aprobación humana.')) return
        router.post(aiPath, {}, { preserveScroll: true })
    }

    return (
        <AppShell title="Revisión de autoparte">
            <Head title={`Revisión ${part.item_number ?? part.id}`} />
            <div className="space-y-6">
                <div className="flex flex-col justify-between gap-3 md:flex-row md:items-center">
                    <div>
                        <div className="flex flex-wrap items-center gap-2"><h1 className="text-2xl font-bold text-slate-900 dark:text-white">{part.item_number ?? 'Autoparte'}</h1><span className="rounded-full bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700 dark:bg-neutral-800 dark:text-slate-200">{review.status}</span></div>
                        <p className="text-sm text-slate-500">{part.manufacturer_part_number ?? 'Sin MFG Part #'}</p>
                    </div>
                    <div className="flex flex-wrap gap-2"><Link href={`/autopartes/${part.id}`} className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 dark:border-neutral-700 dark:text-slate-200">Ver original</Link><Link href="/autopartes/enriquecimiento" className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 dark:border-neutral-700 dark:text-slate-200">Volver</Link></div>
                </div>

                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/20 dark:bg-amber-500/5">
                    <h2 className="text-sm font-bold text-amber-900 dark:text-amber-200">Problemas detectados</h2>
                    <div className="mt-3 flex flex-wrap gap-2">{(review.issue_codes ?? []).map((code) => <span key={code} className="rounded-full bg-white px-2 py-1 text-xs font-medium text-amber-800 shadow-sm dark:bg-neutral-900 dark:text-amber-200">{code}</span>)}</div>
                </div>

                <section className="rounded-2xl border border-violet-200 bg-violet-50 p-5 dark:border-violet-500/20 dark:bg-violet-500/5">
                    <div className="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                        <div>
                            <h2 className="text-lg font-semibold text-violet-950 dark:text-violet-100">Enriquecimiento asistido con IA</h2>
                            <p className="mt-1 text-sm text-violet-800 dark:text-violet-200">Modelo {ai.model ?? '—'} · prompt {ai.prompt_version ?? '—'} · {ai.daily_remaining ?? 0} disponibles hoy.</p>
                            <p className="mt-1 text-xs text-violet-700 dark:text-violet-300">La IA solo prepara un borrador; una persona debe revisarlo y aprobarlo.</p>
                            {ai.disabled_reason && <p className="mt-2 text-sm font-semibold text-rose-700 dark:text-rose-300">{ai.disabled_reason}</p>}
                            {errors.ai && <p className="mt-2 text-sm font-semibold text-rose-700 dark:text-rose-300">{errors.ai}</p>}
                        </div>
                        <button type="button" onClick={queueAi} disabled={!ai.can_generate} title={ai.disabled_reason ?? ''} className="rounded-xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500 disabled:cursor-not-allowed disabled:opacity-50">
                            {review.enrichment_source === 'openai' ? 'Regenerar con IA' : 'Generar con IA'}
                        </button>
                    </div>
                    {latestAiRun && <div className="mt-4 grid gap-3 rounded-xl bg-white/70 p-4 text-sm dark:bg-neutral-900/70 sm:grid-cols-2 lg:grid-cols-4">
                        <OriginalField label="Estado" value={latestAiRun.status} />
                        <OriginalField label="Modelo / prompt" value={`${latestAiRun.model} / ${latestAiRun.prompt_version}`} />
                        <OriginalField label="Tokens entrada / salida / total" value={`${latestAiRun.input_tokens ?? '—'} / ${latestAiRun.output_tokens ?? '—'} / ${latestAiRun.total_tokens ?? '—'}`} />
                        <OriginalField label="Fecha" value={latestAiRun.completed_at ?? latestAiRun.created_at} />
                        <OriginalField label="Confianza" value={latestAiRun.output_payload?.confidence} />
                        <OriginalField label="Warnings" value={latestAiRun.output_payload?.warnings?.join('\n')} />
                        <OriginalField label="Información faltante" value={latestAiRun.output_payload?.missing_facts?.join('\n')} />
                        <OriginalField label="Error" value={latestAiRun.error_message} />
                    </div>}
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                    <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Comparación de propuestas</h2>
                    <p className="mt-1 text-xs text-slate-500">Los datos originales aparecen abajo; estos borradores nunca modifican directamente el catálogo.</p>
                    <div className="mt-4 grid gap-4 lg:grid-cols-3">
                        <ProposalCard title="Reglas" proposal={proposalComparison.rules} />
                        <ProposalCard title="OpenAI" proposal={proposalComparison.ai} />
                        <ProposalCard title="Manual" proposal={proposalComparison.manual} />
                    </div>
                </section>

                <div className="grid gap-6 xl:grid-cols-2">
                    <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                        <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Datos originales</h2>
                        <dl className="mt-4 grid gap-4 sm:grid-cols-2">
                            <OriginalField label="Item #" value={part.item_number} /><OriginalField label="MFG Part #" value={part.manufacturer_part_number} /><OriginalField label="Proveedor" value={part.vendor} /><OriginalField label="Categoría" value={part.category} /><OriginalField label="Descripción" value={part.description_original} /><OriginalField label="Compatibilidad" value={part.applicable_models_text} /><OriginalField label="Modelo prevalente" value={part.prevalent_model} /><OriginalField label="Años" value={[part.min_model_year, part.average_model_year, part.max_model_year].filter((value) => value !== null).join(' / ') || null} /><OriginalField label="Medidas (in)" value={[part.length_inches, part.width_inches, part.height_inches].filter((value) => value !== null).join(' × ') || null} /><OriginalField label="Peso (lb)" value={part.weight_pounds} /><OriginalField label="Precio USD" value={part.retail_price_original} /></dl>
                    </section>

                    <form onSubmit={save} className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                        <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Propuesta editable</h2>
                        <p className="mt-1 text-xs text-slate-500">Fuente actual: {review.enrichment_source}. El borrador de reglas no usa IA.</p>
                        <div className="mt-4 space-y-4">
                            <label className="block text-sm font-medium">Título<input value={data.proposed_title} onChange={(event) => setData('proposed_title', event.target.value)} className={fieldClass} />{errors.proposed_title && <span className="text-xs text-rose-600">{errors.proposed_title}</span>}</label>
                            <label className="block text-sm font-medium">Descripción<textarea rows={5} value={data.proposed_description} onChange={(event) => setData('proposed_description', event.target.value)} className={fieldClass} /></label>
                            <div className="grid gap-4 sm:grid-cols-2"><label className="block text-sm font-medium">Marca<input value={data.proposed_brand} onChange={(event) => setData('proposed_brand', event.target.value)} className={fieldClass} /></label><label className="block text-sm font-medium">Categoría<input value={data.proposed_category} onChange={(event) => setData('proposed_category', event.target.value)} className={fieldClass} /></label></div>
                            <label className="block text-sm font-medium">Compatibilidad propuesta (JSON)<textarea rows={5} value={data.proposed_compatibility} onChange={(event) => setData('proposed_compatibility', event.target.value)} className={fieldClass} />{errors.proposed_compatibility && <span className="text-xs text-rose-600">{errors.proposed_compatibility}</span>}</label>
                            <label className="block text-sm font-medium">Atributos propuestos (JSON)<textarea rows={5} value={data.proposed_attributes} onChange={(event) => setData('proposed_attributes', event.target.value)} className={fieldClass} />{errors.proposed_attributes && <span className="text-xs text-rose-600">{errors.proposed_attributes}</span>}</label>
                            <label className="block text-sm font-medium">Confianza (0–1)<input type="number" min="0" max="1" step="0.0001" value={data.confidence_score} onChange={(event) => setData('confidence_score', event.target.value)} className={fieldClass} /></label>
                            <label className="block text-sm font-medium">Notas del revisor<textarea rows={4} value={data.reviewer_notes} onChange={(event) => setData('reviewer_notes', event.target.value)} className={fieldClass} />{errors.reviewer_notes && <span className="text-xs text-rose-600">{errors.reviewer_notes}</span>}</label>
                        </div>
                        <div className="mt-5 flex flex-wrap gap-2">
                            <button type="submit" disabled={processing} className="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60 dark:bg-white dark:text-slate-900">Guardar propuesta</button>
                            <button type="button" disabled={processing} onClick={() => post(`/autopartes/enriquecimiento/${review.id}/aprobar`, { preserveScroll: true })} className="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">Aprobar</button>
                            <button type="button" disabled={processing} onClick={() => post(`/autopartes/enriquecimiento/${review.id}/rechazar`, { preserveScroll: true })} className="rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">Rechazar</button>
                            <button type="button" onClick={() => router.post(`/autopartes/enriquecimiento/${review.id}/pendiente`, {}, { preserveScroll: true })} className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 dark:border-neutral-700 dark:text-slate-200">Regresar a pendiente</button>
                        </div>
                    </form>
                </div>

                <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                    <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Historial de ejecuciones de IA</h2>
                    <div className="mt-4 overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-neutral-800">
                            <thead><tr className="text-left text-xs uppercase text-slate-500"><th className="py-2 pr-4">Run</th><th className="py-2 pr-4">Estado</th><th className="py-2 pr-4">Modelo</th><th className="py-2 pr-4">Prompt</th><th className="py-2 pr-4">Tokens</th><th className="py-2">Fecha</th></tr></thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">{(review.ai_runs ?? []).map((run) => <tr key={run.id}><td className="py-2 pr-4">#{run.id}</td><td className="py-2 pr-4 font-semibold">{run.status}</td><td className="py-2 pr-4">{run.model}</td><td className="py-2 pr-4">{run.prompt_version}</td><td className="py-2 pr-4">{run.total_tokens ?? '—'}</td><td className="py-2">{run.completed_at ?? run.created_at}</td></tr>)}</tbody>
                        </table>
                        {(review.ai_runs ?? []).length === 0 && <p className="py-4 text-sm text-slate-500">Todavía no hay ejecuciones.</p>}
                    </div>
                </section>
            </div>
        </AppShell>
    )
}
