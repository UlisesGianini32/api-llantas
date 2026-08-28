import AppShell from '@/Components/layout/AppShell'
import { Head, Link } from '@inertiajs/react'

export default function ImportDetail({ import: importRecord, rows = [] }) {
    return (
        <AppShell title={`Importación ${importRecord.id}`}>
            <Head title={`Importación ${importRecord.id}`} />
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Importación #{importRecord.id}</h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">{importRecord.original_filename}</p>
                    </div>
                    <Link href="/autopartes/importaciones" className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200 dark:hover:bg-neutral-800">
                        Volver
                    </Link>
                </div>

                <div className="grid gap-4 md:grid-cols-4">
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900"><p className="text-xs uppercase text-slate-500">Estado</p><p className="mt-2 font-semibold text-slate-900 dark:text-white">{importRecord.status}</p></div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900"><p className="text-xs uppercase text-slate-500">Filas</p><p className="mt-2 font-semibold text-slate-900 dark:text-white">{importRecord.total_rows ?? 0}</p></div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900"><p className="text-xs uppercase text-slate-500">Importadas</p><p className="mt-2 font-semibold text-slate-900 dark:text-white">{importRecord.imported_rows ?? 0}</p></div>
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900"><p className="text-xs uppercase text-slate-500">Duplicadas</p><p className="mt-2 font-semibold text-slate-900 dark:text-white">{importRecord.duplicate_rows ?? 0}</p></div>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                    <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Filas originales</h2>
                    <div className="mt-4 overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 dark:divide-neutral-800">
                            <thead className="bg-slate-50 dark:bg-neutral-950">
                                <tr>
                                    <th className="px-3 py-2 text-left text-xs uppercase text-slate-500">#</th>
                                    <th className="px-3 py-2 text-left text-xs uppercase text-slate-500">Item #</th>
                                    <th className="px-3 py-2 text-left text-xs uppercase text-slate-500">Proveedor</th>
                                    <th className="px-3 py-2 text-left text-xs uppercase text-slate-500">Cantidad</th>
                                    <th className="px-3 py-2 text-left text-xs uppercase text-slate-500">Estado</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-200 dark:divide-neutral-800">
                                {rows.length === 0 ? (
                                    <tr>
                                        <td colSpan={5} className="px-3 py-3 text-sm text-slate-500 dark:text-slate-400">Sin filas registradas.</td>
                                    </tr>
                                ) : rows.map((row) => (
                                    <tr key={row.id}>
                                        <td className="px-3 py-2 text-sm text-slate-700 dark:text-slate-200">{row.row_number}</td>
                                        <td className="px-3 py-2 text-sm text-slate-700 dark:text-slate-200">{row.item_number_raw ?? '—'}</td>
                                        <td className="px-3 py-2 text-sm text-slate-700 dark:text-slate-200">{row.vendor_raw ?? '—'}</td>
                                        <td className="px-3 py-2 text-sm text-slate-700 dark:text-slate-200">{row.quantity_raw ?? '—'}</td>
                                        <td className="px-3 py-2 text-sm text-slate-700 dark:text-slate-200">{row.validation_errors?.length ? 'con errores' : 'ok'}</td>
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
