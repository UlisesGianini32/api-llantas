import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router, useForm } from '@inertiajs/react'

const fieldClass = 'mt-1 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white'

function OriginalField({ label, value }) {
    return <div><dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</dt><dd className="mt-1 whitespace-pre-wrap text-sm text-slate-800 dark:text-slate-200">{value ?? '—'}</dd></div>
}

export default function EnriquecimientoDetalle({ review, part }) {
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
            </div>
        </AppShell>
    )
}
