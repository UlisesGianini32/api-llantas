import AppShell from '@/Components/layout/AppShell'
import { Head, router, useForm } from '@inertiajs/react'
import { useMemo, useState } from 'react'

const fieldClass =
    'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white dark:focus:ring-indigo-500/20'
const secondaryButton =
    'rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50 dark:border-neutral-700 dark:text-slate-200 dark:hover:bg-neutral-800'
const primaryButton =
    'rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-50'

function slugify(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-|-$/g, '')
}

function normalizedPreview(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toUpperCase()
        .trim()
        .replace(/[^A-Z0-9]+/g, ' ')
        .replace(/\s+/g, ' ')
}

function ErrorText({ message }) {
    return message ? <p className="mt-1 text-xs font-medium text-rose-600 dark:text-rose-300">{message}</p> : null
}

function SummaryMetric({ label, value, tone = 'slate' }) {
    const tones = {
        slate: 'bg-slate-50 text-slate-900 dark:bg-neutral-950 dark:text-white',
        green: 'bg-emerald-50 text-emerald-900 dark:bg-emerald-500/10 dark:text-emerald-200',
        amber: 'bg-amber-50 text-amber-900 dark:bg-amber-500/10 dark:text-amber-200',
        rose: 'bg-rose-50 text-rose-900 dark:bg-rose-500/10 dark:text-rose-200',
    }

    return (
        <div className={`rounded-xl p-3 ${tones[tone]}`}>
            <p className="text-xs font-semibold uppercase tracking-wide opacity-70">{label}</p>
            <p className="mt-1 text-2xl font-bold">{value ?? 0}</p>
        </div>
    )
}

export default function Brands({ accounts = [], selectedAccountId = null, brands = [], matchTypes = {}, preview = null }) {
    const [brandEditor, setBrandEditor] = useState(null)
    const [slugTouched, setSlugTouched] = useState(false)
    const [aliasEditor, setAliasEditor] = useState(null)

    const brandForm = useForm({
        name: '',
        slug: '',
        description: '',
        active: true,
        sort_order: 0,
    })
    const aliasForm = useForm({
        alias: '',
        match_type: 'exact',
        priority: 0,
        active: true,
    })

    const selectedAccount = accounts.find((account) => Number(account.id) === Number(selectedAccountId))
    const aliasNormalized = useMemo(() => normalizedPreview(aliasForm.data.alias), [aliasForm.data.alias])
    const shortAlias = aliasNormalized.length > 0 && aliasNormalized.replace(/\s/g, '').length <= 3

    const openNewBrand = () => {
        setBrandEditor({ mode: 'create' })
        setSlugTouched(false)
        brandForm.clearErrors()
        brandForm.setData({ name: '', slug: '', description: '', active: true, sort_order: 0 })
    }

    const openEditBrand = (brand) => {
        setBrandEditor({ mode: 'edit', id: brand.id })
        setSlugTouched(true)
        brandForm.clearErrors()
        brandForm.setData({
            name: brand.name,
            slug: brand.slug,
            description: brand.description ?? '',
            active: Boolean(brand.active),
            sort_order: brand.sort_order ?? 0,
        })
    }

    const submitBrand = (event) => {
        event.preventDefault()
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setBrandEditor(null)
                brandForm.reset()
            },
        }

        if (brandEditor?.mode === 'edit') {
            brandForm.put(`/meli-price-manager/brands/${brandEditor.id}`, options)
        } else {
            brandForm.post('/meli-price-manager/brands', options)
        }
    }

    const openNewAlias = (brand) => {
        setAliasEditor({ mode: 'create', brandId: brand.id, brandName: brand.name })
        aliasForm.clearErrors()
        aliasForm.setData({ alias: '', match_type: 'exact', priority: 0, active: true })
    }

    const openEditAlias = (brand, alias) => {
        setAliasEditor({ mode: 'edit', id: alias.id, brandId: brand.id, brandName: brand.name })
        aliasForm.clearErrors()
        aliasForm.setData({
            alias: alias.alias,
            match_type: alias.match_type,
            priority: alias.priority,
            active: Boolean(alias.active),
        })
    }

    const submitAlias = (event) => {
        event.preventDefault()
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setAliasEditor(null)
                aliasForm.reset()
            },
        }

        if (aliasEditor?.mode === 'edit') {
            aliasForm.put(`/meli-price-manager/aliases/${aliasEditor.id}`, options)
        } else {
            aliasForm.post(`/meli-price-manager/brands/${aliasEditor.brandId}/aliases`, options)
        }
    }

    const changeAccount = (accountId) => {
        router.get('/meli-price-manager/brands', { account: accountId }, { preserveState: true, preserveScroll: true })
    }

    const toggleBrand = (brand) => {
        const nextActive = !brand.active
        if (!nextActive && brand.categorized_items_count > 0) {
            const confirmed = window.confirm(
                `Esta marca tiene ${brand.categorized_items_count} publicaciones categorizadas. Se conservarán, pero la marca dejará de participar en clasificación automática. ¿Continuar?`,
            )
            if (!confirmed) return
        }

        router.patch(`/meli-price-manager/brands/${brand.id}/status`, { active: nextActive }, { preserveScroll: true })
    }

    const toggleAlias = (alias) => {
        router.patch(`/meli-price-manager/aliases/${alias.id}/status`, { active: !alias.active }, { preserveScroll: true })
    }

    const deleteAlias = (alias) => {
        if (!window.confirm(`Eliminar el alias "${alias.alias}"? Se recomienda desactivarlo si deseas conservarlo.`)) return
        router.delete(`/meli-price-manager/aliases/${alias.id}`, {
            data: { confirm: true },
            preserveScroll: true,
        })
    }

    const previewReclassification = (brand = null) => {
        if (!selectedAccountId) return
        const url = brand
            ? `/meli-price-manager/brands/${brand.id}/reclassification/preview`
            : '/meli-price-manager/brands/reclassification/preview'
        router.post(url, { meli_account_id: selectedAccountId, reclassify_all: true }, { preserveScroll: true })
    }

    const applyReclassification = () => {
        if (!preview || !selectedAccountId) return
        if (!window.confirm('Aplicar esta reclasificación? Las asignaciones manuales y los items ignorados se conservarán.')) return
        router.post(
            '/meli-price-manager/brands/reclassification/apply',
            { meli_account_id: selectedAccountId, reclassify_all: Boolean(preview.reclassify_all), confirm: true },
            { preserveScroll: true },
        )
    }

    return (
        <AppShell title="Meli Price Manager">
            <Head title="Marcas y alias · Meli Price Manager" />
            <div className="space-y-6">
                <header className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
                    <div>
                        <p className="text-xs font-bold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">
                            Meli Price Manager
                        </p>
                        <h1 className="mt-1 text-3xl font-bold text-slate-950 dark:text-white">Marcas y alias</h1>
                        <p className="mt-2 max-w-3xl text-sm text-slate-500 dark:text-slate-400">
                            Configura reglas determinísticas sin modificar la marca original de Mercado Libre. Guardar una regla no reclasifica automáticamente.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" onClick={openNewBrand} className={primaryButton}>Nueva marca</button>
                        <button type="button" onClick={() => previewReclassification()} disabled={!selectedAccountId} className={secondaryButton}>
                            Vista previa de reclasificación
                        </button>
                    </div>
                </header>

                <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,24rem)] lg:items-end">
                        <div>
                            <h2 className="font-semibold text-slate-900 dark:text-white">Cuenta de Mercado Libre</h2>
                            <p className="mt-1 text-sm text-slate-500">Los conteos y la reclasificación corresponden exclusivamente a esta cuenta.</p>
                        </div>
                        <select value={selectedAccountId ?? ''} onChange={(event) => changeAccount(event.target.value)} disabled={accounts.length === 0} className={fieldClass}>
                            {accounts.length === 0 && <option value="">Sin cuentas vinculadas</option>}
                            {accounts.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.nickname || `Cuenta #${account.id}`} · {account.meli_user_id}{account.is_default ? ' · predeterminada' : ''}
                                </option>
                            ))}
                        </select>
                    </div>
                </section>

                {brandEditor && (
                    <section className="rounded-2xl border border-indigo-200 bg-indigo-50/60 p-5 dark:border-indigo-500/20 dark:bg-indigo-500/5">
                        <div className="mb-4 flex items-center justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-bold">{brandEditor.mode === 'edit' ? 'Editar marca' : 'Nueva marca'}</h2>
                                <p className="text-sm text-slate-500">El slug existente solo cambia cuando lo editas explícitamente.</p>
                            </div>
                            <button type="button" onClick={() => setBrandEditor(null)} className={secondaryButton}>Cancelar</button>
                        </div>
                        <form onSubmit={submitBrand} className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                            <label className="xl:col-span-2">
                                <span className="mb-1 block text-sm font-semibold">Nombre</span>
                                <input
                                    value={brandForm.data.name}
                                    onChange={(event) => {
                                        const name = event.target.value
                                        brandForm.setData({
                                            ...brandForm.data,
                                            name,
                                            slug: brandEditor.mode === 'create' && !slugTouched ? slugify(name) : brandForm.data.slug,
                                        })
                                    }}
                                    className={fieldClass}
                                    required
                                    maxLength={255}
                                />
                                <ErrorText message={brandForm.errors.name} />
                            </label>
                            <label>
                                <span className="mb-1 block text-sm font-semibold">Slug</span>
                                <input value={brandForm.data.slug} onChange={(event) => { setSlugTouched(true); brandForm.setData('slug', event.target.value) }} className={fieldClass} required maxLength={255} />
                                <ErrorText message={brandForm.errors.slug} />
                            </label>
                            <label>
                                <span className="mb-1 block text-sm font-semibold">Orden</span>
                                <input type="number" value={brandForm.data.sort_order} onChange={(event) => brandForm.setData('sort_order', event.target.value)} className={fieldClass} required />
                                <ErrorText message={brandForm.errors.sort_order} />
                            </label>
                            <label className="flex items-center gap-2 self-end rounded-xl border border-slate-200 px-3 py-2 dark:border-neutral-700">
                                <input type="checkbox" checked={brandForm.data.active} onChange={(event) => brandForm.setData('active', event.target.checked)} />
                                <span className="text-sm font-semibold">Marca activa</span>
                            </label>
                            <label className="md:col-span-2 xl:col-span-4">
                                <span className="mb-1 block text-sm font-semibold">Descripción</span>
                                <textarea value={brandForm.data.description} onChange={(event) => brandForm.setData('description', event.target.value)} className={fieldClass} rows={2} maxLength={4000} />
                                <ErrorText message={brandForm.errors.description} />
                            </label>
                            <button disabled={brandForm.processing} className={`${primaryButton} self-end`}>
                                {brandForm.processing ? 'Guardando...' : 'Guardar marca'}
                            </button>
                        </form>
                    </section>
                )}

                {preview && (
                    <section className="rounded-2xl border border-amber-300 bg-amber-50 p-5 dark:border-amber-500/30 dark:bg-amber-500/5">
                        <div className="flex flex-col justify-between gap-3 md:flex-row md:items-center">
                            <div>
                                <h2 className="text-lg font-bold text-amber-950 dark:text-amber-100">Vista previa lista</h2>
                                <p className="text-sm text-amber-800 dark:text-amber-200">
                                    {preview.brand_name ? `Originada desde ${preview.brand_name}. ` : ''}
                                    Cuenta {selectedAccount?.nickname || `#${selectedAccountId}`} · {preview.generated_at}
                                </p>
                            </div>
                            <button type="button" onClick={applyReclassification} className={primaryButton}>Aplicar reclasificación</button>
                        </div>
                        <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                            <SummaryMetric label="Procesadas" value={preview.summary.processed} />
                            <SummaryMetric label="Se categorizarían" value={preview.summary.categorized} tone="green" />
                            <SummaryMetric label="Sugeridas" value={preview.summary.suggested} tone="amber" />
                            <SummaryMetric label="Sin categoría" value={preview.summary.uncategorized} tone="rose" />
                            <SummaryMetric label="Ignoradas" value={preview.summary.ignored} />
                            <SummaryMetric label="Manuales" value={preview.summary.skipped_manual} />
                        </div>
                        <p className="mt-3 text-xs font-semibold text-amber-900 dark:text-amber-200">
                            Esta vista previa no escribió en base de datos. Se modificarían {preview.summary.changed ?? 0} registros al aplicar.
                        </p>
                    </section>
                )}

                <section className="space-y-4">
                    {brands.length === 0 && (
                        <div className="rounded-2xl border border-dashed border-slate-300 bg-white p-10 text-center dark:border-neutral-700 dark:bg-neutral-900">
                            <h2 className="font-bold">Todavía no hay marcas internas</h2>
                            <p className="mt-1 text-sm text-slate-500">Crea la primera marca; no se agregarán datos automáticamente.</p>
                        </div>
                    )}

                    {brands.map((brand) => (
                        <article key={brand.id} className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="grid gap-4 border-b border-slate-200 p-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-start dark:border-neutral-800">
                                <div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="text-xl font-bold">{brand.name}</h2>
                                        <span className={`rounded-full px-2.5 py-1 text-xs font-bold ${brand.active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200' : 'bg-slate-100 text-slate-600 dark:bg-neutral-800 dark:text-slate-300'}`}>
                                            {brand.active ? 'Activa' : 'Inactiva'}
                                        </span>
                                        <span className="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-200">Orden {brand.sort_order}</span>
                                    </div>
                                    <p className="mt-1 text-xs font-mono text-slate-400">{brand.slug}</p>
                                    <p className="mt-2 max-w-3xl text-sm text-slate-500">{brand.description || 'Sin descripción.'}</p>
                                    <div className="mt-4 flex flex-wrap gap-4 text-sm">
                                        <span><b>{brand.aliases_count}</b> aliases</span>
                                        <span><b>{brand.categorized_items_count}</b> publicaciones categorizadas</span>
                                        <span><b>{brand.suggested_items_count}</b> sugeridas</span>
                                    </div>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <button type="button" onClick={() => openEditBrand(brand)} className={secondaryButton}>Editar</button>
                                    <button type="button" onClick={() => openNewAlias(brand)} className={secondaryButton}>Agregar alias</button>
                                    <button type="button" onClick={() => previewReclassification(brand)} disabled={!selectedAccountId} className={secondaryButton}>Reclasificar</button>
                                    <button type="button" onClick={() => toggleBrand(brand)} className={secondaryButton}>{brand.active ? 'Desactivar' : 'Activar'}</button>
                                </div>
                            </div>

                            {aliasEditor?.brandId === brand.id && (
                                <form onSubmit={submitAlias} className="border-b border-indigo-100 bg-indigo-50/40 p-5 dark:border-indigo-500/20 dark:bg-indigo-500/5">
                                    <div className="mb-3 flex items-center justify-between gap-3">
                                        <div>
                                            <h3 className="font-bold">{aliasEditor.mode === 'edit' ? 'Editar alias' : `Nuevo alias para ${brand.name}`}</h3>
                                            <p className="text-xs text-slate-500">El valor normalizado se calcula exclusivamente en backend.</p>
                                        </div>
                                        <button type="button" onClick={() => setAliasEditor(null)} className={secondaryButton}>Cancelar</button>
                                    </div>
                                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                                        <label className="xl:col-span-2">
                                            <span className="mb-1 block text-sm font-semibold">Alias</span>
                                            <input value={aliasForm.data.alias} onChange={(event) => aliasForm.setData('alias', event.target.value)} className={fieldClass} required maxLength={255} />
                                            <ErrorText message={aliasForm.errors.alias || aliasForm.errors.normalized_alias} />
                                            {aliasNormalized && <p className="mt-1 text-xs text-slate-500">Vista previa normalizada: <b>{aliasNormalized}</b></p>}
                                        </label>
                                        <label>
                                            <span className="mb-1 block text-sm font-semibold">Tipo</span>
                                            <select value={aliasForm.data.match_type} onChange={(event) => aliasForm.setData('match_type', event.target.value)} className={fieldClass}>
                                                {Object.entries(matchTypes).map(([value, option]) => <option key={value} value={value}>{option.label}</option>)}
                                            </select>
                                            <ErrorText message={aliasForm.errors.match_type} />
                                        </label>
                                        <label>
                                            <span className="mb-1 block text-sm font-semibold">Prioridad</span>
                                            <input type="number" min="0" max="1000" value={aliasForm.data.priority} onChange={(event) => aliasForm.setData('priority', event.target.value)} className={fieldClass} required />
                                            <ErrorText message={aliasForm.errors.priority} />
                                        </label>
                                        <label className="flex items-center gap-2 self-end rounded-xl border border-slate-200 px-3 py-2 dark:border-neutral-700">
                                            <input type="checkbox" checked={aliasForm.data.active} onChange={(event) => aliasForm.setData('active', event.target.checked)} />
                                            <span className="text-sm font-semibold">Alias activo</span>
                                        </label>
                                    </div>
                                    <div className="mt-3 rounded-xl bg-white p-3 text-xs text-slate-600 dark:bg-neutral-950 dark:text-slate-300">
                                        <b>{matchTypes[aliasForm.data.match_type]?.label}:</b> {matchTypes[aliasForm.data.match_type]?.help}
                                        <p className="mt-1">Una prioridad mayor gana entre coincidencias de la misma etapa.</p>
                                    </div>
                                    {shortAlias && <div className="mt-3 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm font-semibold text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">Los aliases cortos requieren coincidencia por palabra completa para evitar falsos positivos.</div>}
                                    <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                                        <p className="text-xs text-slate-500">Un alias equivalente en otra marca se permite, pero puede producir una sugerencia ambigua.</p>
                                        <button disabled={aliasForm.processing} className={primaryButton}>{aliasForm.processing ? 'Guardando...' : 'Guardar alias'}</button>
                                    </div>
                                </form>
                            )}

                            <div className="overflow-x-auto">
                                <table className="min-w-full text-sm">
                                    <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-neutral-950">
                                        <tr><th className="px-5 py-3">Alias</th><th className="px-5 py-3">Normalizado</th><th className="px-5 py-3">Tipo</th><th className="px-5 py-3">Prioridad</th><th className="px-5 py-3">Estado</th><th className="px-5 py-3 text-right">Acciones</th></tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                        {brand.aliases.length === 0 && <tr><td colSpan="6" className="px-5 py-6 text-center text-slate-500">Esta marca todavía no tiene aliases.</td></tr>}
                                        {brand.aliases.map((alias) => (
                                            <tr key={alias.id}>
                                                <td className="px-5 py-3 font-semibold">{alias.alias}</td>
                                                <td className="px-5 py-3 font-mono text-xs text-slate-500">{alias.normalized_alias}</td>
                                                <td className="px-5 py-3" title={matchTypes[alias.match_type]?.help}>{matchTypes[alias.match_type]?.label || alias.match_type}</td>
                                                <td className="px-5 py-3">{alias.priority}</td>
                                                <td className="px-5 py-3"><span className={alias.active ? 'font-semibold text-emerald-700 dark:text-emerald-300' : 'text-slate-400'}>{alias.active ? 'Activo' : 'Inactivo'}</span></td>
                                                <td className="px-5 py-3"><div className="flex justify-end gap-2"><button type="button" onClick={() => openEditAlias(brand, alias)} className={secondaryButton}>Editar</button><button type="button" onClick={() => toggleAlias(alias)} className={secondaryButton}>{alias.active ? 'Desactivar' : 'Activar'}</button><button type="button" onClick={() => deleteAlias(alias)} className="rounded-xl border border-rose-200 px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 dark:border-rose-500/30 dark:text-rose-300">Eliminar</button></div></td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </article>
                    ))}
                </section>
            </div>
        </AppShell>
    )
}
