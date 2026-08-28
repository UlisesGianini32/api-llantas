import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router } from '@inertiajs/react'
import { useState } from 'react'

const statusBadge = {
    imported: 'bg-sky-100 text-sky-700',
    incomplete: 'bg-amber-100 text-amber-700',
    ready_for_enrichment: 'bg-indigo-100 text-indigo-700',
    enriched: 'bg-emerald-100 text-emerald-700',
    ready_for_review: 'bg-violet-100 text-violet-700',
    approved: 'bg-emerald-100 text-emerald-700',
}

export default function Index({ parts, filters = {}, summary = {} }) {
    const [form, setForm] = useState({
        item_number: filters.item_number ?? '',
        manufacturer_part_number: filters.manufacturer_part_number ?? '',
        vendor: filters.vendor ?? '',
        category: filters.category ?? '',
        subcategory: filters.subcategory ?? '',
        status: filters.status ?? '',
        stock: filters.stock ?? '',
    })

    const submit = (event) => {
        event.preventDefault()
        router.get('/autopartes', form)
    }

    return (
        <AppShell title="Autopartes">
            <Head title="Autopartes" />
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Autopartes</h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">
                            Catálogo canónico y historial de importaciones del archivo de autopartes.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Link href="/autopartes/enriquecimiento" className="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700 hover:bg-indigo-100 dark:border-indigo-500/30 dark:bg-indigo-500/10 dark:text-indigo-300">
                            Enriquecimiento
                        </Link>
                        <Link href="/autopartes/upload" className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">
                            Subir XLS/XLSX
                        </Link>
                        <Link href="/autopartes/importaciones" className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200 dark:hover:bg-neutral-800">
                            Importaciones
                        </Link>
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-3">
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                        <p className="text-xs uppercase tracking-wide text-slate-500">Total</p>
                        <p className="mt-2 text-3xl font-bold text-slate-900 dark:text-white">{summary.count ?? 0}</p>
                    </div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                        <p className="text-xs uppercase tracking-wide text-slate-500">Incompletos</p>
                        <p className="mt-2 text-3xl font-bold text-amber-600">{summary.incomplete ?? 0}</p>
                    </div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                        <p className="text-xs uppercase tracking-wide text-slate-500">Duplicados</p>
                        <p className="mt-2 text-3xl font-bold text-rose-600">{summary.duplicate ?? 0}</p>
                    </div>
                </div>

                <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                        <input value={form.item_number} onChange={(event) => setForm({ ...form, item_number: event.target.value })} placeholder="Item #" className="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        <input value={form.manufacturer_part_number} onChange={(event) => setForm({ ...form, manufacturer_part_number: event.target.value })} placeholder="MFG Part #" className="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        <input value={form.vendor} onChange={(event) => setForm({ ...form, vendor: event.target.value })} placeholder="Proveedor" className="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        <input value={form.category} onChange={(event) => setForm({ ...form, category: event.target.value })} placeholder="Categoría" className="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        <input value={form.subcategory} onChange={(event) => setForm({ ...form, subcategory: event.target.value })} placeholder="Subcategoría" className="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white" />
                        <select value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })} className="rounded-xl border border-slate-200 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white">
                            <option value="">Estado</option>
                            <option value="imported">imported</option>
                            <option value="incomplete">incomplete</option>
                            <option value="ready_for_enrichment">ready_for_enrichment</option>
                            <option value="enriched">enriched</option>
                            <option value="ready_for_review">ready_for_review</option>
                            <option value="approved">approved</option>
                        </select>
                    </div>
                    <div className="mt-4 flex gap-2">
                        <button type="submit" className="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white">Filtrar</button>
                        <button type="button" onClick={() => router.get('/autopartes')} className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200 dark:hover:bg-neutral-800">Limpiar</button>
                    </div>
                </form>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 dark:divide-neutral-800">
                            <thead className="bg-slate-50 dark:bg-neutral-950">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Item #</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">MFG Part #</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Proveedor</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Categoría</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Stock</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Precio</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Estado</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Última importación</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-200 dark:divide-neutral-800">
                                {parts.data.map((part) => (
                                    <tr key={part.id} className="hover:bg-slate-50 dark:hover:bg-neutral-950/60">
                                        <td className="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">
                                            <Link href={`/autopartes/${part.id}`} className="font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                                {part.item_number ?? '—'}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{part.manufacturer_part_number ?? '—'}</td>
                                        <td className="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{part.vendor ?? '—'}</td>
                                        <td className="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{[part.category, part.subcategory].filter(Boolean).join(' / ') || '—'}</td>
                                        <td className="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{part.quantity ?? 0}</td>
                                        <td className="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{part.retail_price_original ? `$${Number(part.retail_price_original).toFixed(2)}` : '—'}</td>
                                        <td className="px-4 py-3 text-sm">
                                            <span className={`inline-flex rounded-full px-2 py-1 text-xs font-semibold ${statusBadge[part.data_status] ?? 'bg-slate-100 text-slate-700'}`}>
                                                {part.data_status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{part.last_imported_at ? new Date(part.last_imported_at).toLocaleString('es-MX') : '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AppShell>
    )
}
