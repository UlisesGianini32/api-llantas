import AppShell from '@/Components/layout/AppShell'
import { Head, router } from '@inertiajs/react'
import { useState } from 'react'

const statusLabels = {
    pending: 'Pendiente',
    same: 'Misma llanta',
    different: 'Diferente',
    ignored: 'Ignorada',
}

function money(value) {
    if (value === null || value === undefined || value === '') return '—'

    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    }).format(Number(value))
}

function StatCard({ title, value, tone }) {
    const tones = {
        indigo: 'from-indigo-500 to-violet-600',
        amber: 'from-amber-500 to-orange-600',
        emerald: 'from-emerald-500 to-teal-600',
        rose: 'from-rose-500 to-pink-600',
    }

    return (
        <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">{title}</p>
            <p className="mt-3 text-3xl font-bold text-slate-900 dark:text-white">{value}</p>
            <div className={`mt-4 h-1.5 rounded-full bg-gradient-to-r ${tones[tone]}`} />
        </div>
    )
}

function Technical({ technical = {} }) {
    const fields = [
        ['Carga', technical.load_index],
        ['Velocidad', technical.speed_index],
        ['Capas', technical.ply_rating],
        ['Construcción', technical.construction],
        ['Categoría', technical.category],
        ['XL', technical.is_xl ? 'Sí' : 'No'],
        ['LT', technical.is_lt ? 'Sí' : 'No'],
        ['RWL', technical.is_rwl ? 'Sí' : 'No'],
    ]

    return (
        <div className="mt-4 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
            {fields.map(([label, value]) => (
                <div key={label} className="rounded-xl bg-slate-50 px-3 py-2 dark:bg-neutral-950">
                    <span className="block font-semibold uppercase tracking-wide text-slate-400">{label}</span>
                    <span className="mt-1 block font-medium text-slate-700 dark:text-slate-200">{value ?? '—'}</span>
                </div>
            ))}
        </div>
    )
}

function TireCard({ title, tire }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-neutral-700 dark:bg-neutral-950">
            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">{title}</p>
            {tire?.deleted ? (
                <p className="mt-4 text-sm text-rose-600">La llanta ya no existe.</p>
            ) : (
                <>
                    <div className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                        <div><span className="font-semibold">SKU:</span> <span className="font-mono">{tire.sku || '—'}</span></div>
                        <div><span className="font-semibold">MLM:</span> {tire.MLM || '—'}</div>
                        <div><span className="font-semibold">Marca:</span> {tire.marca || '—'}</div>
                        <div><span className="font-semibold">Medida:</span> {tire.medida || '—'}</div>
                        <div><span className="font-semibold">Stock:</span> {tire.stock ?? '—'}</div>
                        <div><span className="font-semibold">Costo:</span> {money(tire.costo)}</div>
                        <div><span className="font-semibold">Precio ML:</span> {money(tire.precio_ML)}</div>
                    </div>
                    <div className="mt-3 text-sm">
                        <p><span className="font-semibold">Título:</span> {tire.title_familyname || '—'}</p>
                        <p className="mt-1"><span className="font-semibold">Descripción:</span> {tire.descripcion || '—'}</p>
                    </div>
                    <Technical technical={tire.technical} />
                </>
            )}
        </div>
    )
}

function Pagination({ links }) {
    if (!links || links.length <= 3) return null

    return (
        <div className="mt-6 flex flex-wrap gap-2">
            {links.map((link, index) => (
                <button
                    key={link.url || `${link.label}-${index}`}
                    type="button"
                    disabled={!link.url}
                    onClick={() => link.url && router.visit(link.url, { preserveScroll: true, preserveState: true })}
                    className={`rounded-xl border px-4 py-2 text-sm ${link.active ? 'border-indigo-500 bg-indigo-600 text-white' : 'border-slate-200 bg-white text-slate-700 dark:border-neutral-800 dark:bg-neutral-900 dark:text-slate-300'} ${!link.url ? 'cursor-default opacity-50' : ''}`}
                    dangerouslySetInnerHTML={{ __html: link.label }}
                />
            ))}
        </div>
    )
}

export default function Comparador({ comparisons, stats = {}, filters = {} }) {
    const [busyId, setBusyId] = useState(null)
    const [actionError, setActionError] = useState('')
    const [form, setForm] = useState({
        search: filters.search || '',
        marca: filters.marca || '',
        medida: filters.medida || '',
        min_score: filters.min_score || '',
        status: filters.status || 'pending',
        different_sku: Boolean(filters.different_sku),
        sort: filters.sort || 'score',
        per_page: filters.per_page || 20,
    })

    const update = (key, value) => setForm((current) => ({ ...current, [key]: value }))
    const apply = (event) => {
        event?.preventDefault()
        router.get('/llantas/comparador', form, { preserveState: true, preserveScroll: true, replace: true })
    }

    const decide = (id, status) => {
        if (busyId !== null) return

        setActionError('')
        router.post(`/llantas/comparador/${id}/decision`, { status }, {
            preserveScroll: true,
            onStart: () => setBusyId(id),
            onError: () => setActionError('No se pudo guardar la decisión. Intenta nuevamente.'),
            onFinish: () => setBusyId(null),
        })
    }

    return (
        <AppShell title="Comparador de llantas">
            <Head title="Comparador de llantas" />
            <div className="space-y-6">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-600">Inventario</p>
                    <h1 className="mt-2 text-3xl font-bold text-slate-900 dark:text-white">Comparador de llantas</h1>
                    <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Detecta posibles registros duplicados y confirma manualmente si representan el mismo producto.</p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard title="Pendientes" value={stats.pending || 0} tone="indigo" />
                    <StatCard title="Alta coincidencia" value={stats.high || 0} tone="amber" />
                    <StatCard title="Confirmadas iguales" value={stats.same || 0} tone="emerald" />
                    <StatCard title="Confirmadas diferentes" value={stats.different || 0} tone="rose" />
                </div>

                <form onSubmit={apply} className="grid gap-3 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900 md:grid-cols-4">
                    <input value={form.search} onChange={(event) => update('search', event.target.value)} placeholder="Buscar SKU, marca o descripción" className="rounded-xl border-slate-200 dark:border-neutral-700 dark:bg-neutral-950 md:col-span-2" />
                    <input value={form.marca} onChange={(event) => update('marca', event.target.value)} placeholder="Marca" className="rounded-xl border-slate-200 dark:border-neutral-700 dark:bg-neutral-950" />
                    <input value={form.medida} onChange={(event) => update('medida', event.target.value)} placeholder="Medida" className="rounded-xl border-slate-200 dark:border-neutral-700 dark:bg-neutral-950" />
                    <select value={form.status} onChange={(event) => update('status', event.target.value)} className="rounded-xl border-slate-200 dark:border-neutral-700 dark:bg-neutral-950">
                        <option value="">Todos los estados</option>
                        {Object.entries(statusLabels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                    <input type="number" min="0" max="100" step="1" value={form.min_score} onChange={(event) => update('min_score', event.target.value)} placeholder="Score mínimo" className="rounded-xl border-slate-200 dark:border-neutral-700 dark:bg-neutral-950" />
                    <select value={form.sort} onChange={(event) => update('sort', event.target.value)} className="rounded-xl border-slate-200 dark:border-neutral-700 dark:bg-neutral-950">
                        <option value="score">Ordenar por score</option>
                        <option value="recent">Más recientes</option>
                    </select>
                    <label className="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
                        <input type="checkbox" checked={form.different_sku} onChange={(event) => update('different_sku', event.target.checked)} />
                        Solo SKU diferente
                    </label>
                    <button type="submit" className="rounded-xl bg-indigo-600 px-4 py-2 font-semibold text-white hover:bg-indigo-700">Aplicar filtros</button>
                </form>

                <div className="space-y-5">
                    {actionError && <div role="alert" className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-300">{actionError}</div>}
                    {(comparisons?.data || []).map((item) => (
                        <article key={item.id} className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="flex items-center gap-3">
                                    <span className="rounded-full bg-indigo-100 px-3 py-1 text-sm font-bold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">Score {Number(item.score).toFixed(2)}</span>
                                    <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 dark:bg-neutral-800 dark:text-slate-300">{statusLabels[item.status] || item.status}</span>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {item.status !== 'same' && <button type="button" disabled={busyId !== null} onClick={() => decide(item.id, 'same')} className="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-semibold text-white disabled:cursor-wait disabled:opacity-50">Misma llanta</button>}
                                    {item.status !== 'different' && <button type="button" disabled={busyId !== null} onClick={() => decide(item.id, 'different')} className="rounded-xl bg-rose-600 px-3 py-2 text-xs font-semibold text-white disabled:cursor-wait disabled:opacity-50">No son la misma</button>}
                                    {item.status !== 'ignored' && <button type="button" disabled={busyId !== null} onClick={() => decide(item.id, 'ignored')} className="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 disabled:cursor-wait disabled:opacity-50 dark:border-neutral-700 dark:text-slate-300">Ignorar</button>}
                                    {item.status !== 'pending' && <button type="button" disabled={busyId !== null} onClick={() => decide(item.id, 'pending')} className="rounded-xl border border-indigo-300 px-3 py-2 text-xs font-semibold text-indigo-700 disabled:cursor-wait disabled:opacity-50 dark:border-indigo-700 dark:text-indigo-300">Ver nuevamente</button>}
                                </div>
                            </div>
                            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                                <TireCard title="Llanta A" tire={item.a} />
                                <TireCard title="Llanta B" tire={item.b} />
                            </div>
                            <div className="mt-4 grid gap-4 text-sm lg:grid-cols-2">
                                <div><p className="font-semibold text-emerald-700">Razones</p><ul className="mt-1 list-disc pl-5 text-slate-600 dark:text-slate-300">{(item.reasons || []).map((reason) => <li key={reason}>{reason}</li>)}</ul></div>
                                <div><p className="font-semibold text-rose-700">Diferencias</p><ul className="mt-1 list-disc pl-5 text-slate-600 dark:text-slate-300">{(item.differences || []).map((difference) => <li key={difference}>{difference}</li>)}</ul></div>
                            </div>
                        </article>
                    ))}
                    {(comparisons?.data || []).length === 0 && <div className="rounded-3xl border border-dashed border-slate-300 p-10 text-center text-slate-500 dark:border-neutral-700">No hay comparaciones con estos filtros.</div>}
                </div>

                <Pagination links={comparisons?.links} />
            </div>
        </AppShell>
    )
}
