import AppShell from '@/Components/layout/AppShell'
import { Head, Link } from '@inertiajs/react'

export default function Detalle({ part, stockMovements = [] }) {
    return (
        <AppShell title={part.item_number ?? 'Detalle de autoparte'}>
            <Head title={part.item_number ?? 'Detalle de autoparte'} />
            <div className="space-y-6">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">{part.item_number ?? 'Autoparte'}</h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">{part.manufacturer_part_number ?? '—'}</p>
                    </div>
                    <div className="flex gap-2">
                        {part.enrichment_review && <Link href={`/autopartes/enriquecimiento/${part.enrichment_review.id}`} className="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm font-semibold text-indigo-700 dark:border-indigo-500/30 dark:bg-indigo-500/10 dark:text-indigo-300">Ver revisión</Link>}
                        <Link href="/autopartes" className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200 dark:hover:bg-neutral-800">Volver</Link>
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                        <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Datos principales</h2>
                        <dl className="mt-4 space-y-3 text-sm text-slate-700 dark:text-slate-200">
                            <div className="flex justify-between gap-4"><dt>Proveedor</dt><dd>{part.vendor ?? '—'}</dd></div>
                            <div className="flex justify-between gap-4"><dt>Categoría</dt><dd>{[part.category, part.subcategory].filter(Boolean).join(' / ') || '—'}</dd></div>
                            <div className="flex justify-between gap-4"><dt>Stock</dt><dd>{part.quantity ?? 0}</dd></div>
                            <div className="flex justify-between gap-4"><dt>Precio original</dt><dd>{part.retail_price_original ? `$${Number(part.retail_price_original).toFixed(2)}` : '—'}</dd></div>
                            <div className="flex justify-between gap-4"><dt>Estado</dt><dd>{part.data_status ?? '—'}</dd></div>
                            <div className="flex justify-between gap-4"><dt>Lifecycle</dt><dd>{part.lifecycle ?? '—'}</dd></div>
                        </dl>
                    </div>

                    <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                        <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Medidas y peso</h2>
                        <dl className="mt-4 space-y-3 text-sm text-slate-700 dark:text-slate-200">
                            <div className="flex justify-between gap-4"><dt>Longitud (in)</dt><dd>{part.length_inches ?? '—'}</dd></div>
                            <div className="flex justify-between gap-4"><dt>Ancho (in)</dt><dd>{part.width_inches ?? '—'}</dd></div>
                            <div className="flex justify-between gap-4"><dt>Altura (in)</dt><dd>{part.height_inches ?? '—'}</dd></div>
                            <div className="flex justify-between gap-4"><dt>Longitud (cm)</dt><dd>{part.length_cm ?? '—'}</dd></div>
                            <div className="flex justify-between gap-4"><dt>Peso (lb)</dt><dd>{part.weight_pounds ?? '—'}</dd></div>
                            <div className="flex justify-between gap-4"><dt>Peso (kg)</dt><dd>{part.weight_kg ?? '—'}</dd></div>
                        </dl>
                    </div>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                    <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Descripcion original</h2>
                    <p className="mt-3 text-sm text-slate-700 dark:text-slate-200">{part.description_original ?? '—'}</p>
                </div>

                <div className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                    <h2 className="text-lg font-semibold text-slate-900 dark:text-white">Historial de stock</h2>
                    <div className="mt-4 overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 dark:divide-neutral-800">
                            <thead className="bg-slate-50 dark:bg-neutral-950">
                                <tr>
                                    <th className="px-3 py-2 text-left text-xs uppercase text-slate-500">Fecha</th>
                                    <th className="px-3 py-2 text-left text-xs uppercase text-slate-500">Previo</th>
                                    <th className="px-3 py-2 text-left text-xs uppercase text-slate-500">Nuevo</th>
                                    <th className="px-3 py-2 text-left text-xs uppercase text-slate-500">Diferencia</th>
                                    <th className="px-3 py-2 text-left text-xs uppercase text-slate-500">Motivo</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-200 dark:divide-neutral-800">
                                {stockMovements.length === 0 ? (
                                    <tr>
                                        <td colSpan={5} className="px-3 py-3 text-sm text-slate-500 dark:text-slate-400">Sin movimientos registrados.</td>
                                    </tr>
                                ) : stockMovements.map((movement) => (
                                    <tr key={movement.id}>
                                        <td className="px-3 py-2 text-sm text-slate-700 dark:text-slate-200">{new Date(movement.created_at).toLocaleString('es-MX')}</td>
                                        <td className="px-3 py-2 text-sm text-slate-700 dark:text-slate-200">{movement.previous_quantity}</td>
                                        <td className="px-3 py-2 text-sm text-slate-700 dark:text-slate-200">{movement.new_quantity}</td>
                                        <td className="px-3 py-2 text-sm text-slate-700 dark:text-slate-200">{movement.difference}</td>
                                        <td className="px-3 py-2 text-sm text-slate-700 dark:text-slate-200">{movement.reason}</td>
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
