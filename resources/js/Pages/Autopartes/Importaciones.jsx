import AppShell from '@/Components/layout/AppShell'
import { Head, Link } from '@inertiajs/react'

export default function Importaciones({ imports }) {
    return (
        <AppShell title="Importaciones de autopartes">
            <Head title="Importaciones de autopartes" />
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Importaciones de autopartes</h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">Historial de cargues del archivo AUTO PARTES JULIO 2026.</p>
                    </div>
                    <Link href="/autopartes" className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200 dark:hover:bg-neutral-800">
                        Volver al catálogo
                    </Link>
                </div>

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 dark:divide-neutral-800">
                            <thead className="bg-slate-50 dark:bg-neutral-950">
                                <tr>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Archivo</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Estado</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Filas</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Importadas</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Actualizadas</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Duplicadas</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Incompletas</th>
                                    <th className="px-4 py-3 text-left text-xs font-semibold uppercase text-slate-500">Fecha</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-200 dark:divide-neutral-800">
                                {imports.data.map((item) => (
                                    <tr key={item.id} className="hover:bg-slate-50 dark:hover:bg-neutral-950/60">
                                        <td className="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">
                                            <Link href={`/autopartes/importaciones/${item.id}`} className="font-semibold text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
                                                {item.original_filename}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-sm">
                                            <span className={`inline-flex rounded-full px-2 py-1 text-xs font-semibold ${item.status === 'completed' ? 'bg-emerald-100 text-emerald-700' : item.status === 'processing' ? 'bg-amber-100 text-amber-700' : item.status === 'failed' ? 'bg-rose-100 text-rose-700' : 'bg-slate-100 text-slate-700'}`}>
                                                {item.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{item.total_rows ?? 0}</td>
                                        <td className="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{item.imported_rows ?? 0}</td>
                                        <td className="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{item.updated_rows ?? 0}</td>
                                        <td className="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{item.duplicate_rows ?? 0}</td>
                                        <td className="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{item.invalid_rows ?? 0}</td>
                                        <td className="px-4 py-3 text-sm text-slate-700 dark:text-slate-200">{item.created_at ? new Date(item.created_at).toLocaleString('es-MX') : '—'}</td>
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
