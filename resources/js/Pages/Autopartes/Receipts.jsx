import { Link, useForm } from '@inertiajs/react'
import { AUTOPARTES_CAPABILITIES, hasCapability } from '@/lib/autopartesCapabilities'
import Layout, { Pagination, baseUrl, button, card, field, statusLabel } from './Layout'

export default function Receipts({ branch, defaultLocationId, locations = [], receipts, capabilities = [] }) {
    const canDraft = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.RECEIPT_DRAFT)
    const form = useForm({
        automotive_location_id: defaultLocationId || (locations[0]?.id ?? ''),
        notes: '',
    })

    const submitNewDraft = e => {
        e.preventDefault()
        form.post(`${baseUrl(branch)}/entradas`)
    }

    return (
        <Layout branch={branch} title={`Entrada de autopartes — ${branch.name}`}>
            {canDraft && (
                <form className={card} onSubmit={submitNewDraft}>
                    <h2 className="text-base font-semibold">Iniciar nueva sesión de recepción por lote (Borrador)</h2>
                    <p className="mt-1 text-sm text-slate-600 dark:text-neutral-400">
                        Cree un borrador para escanear códigos de barras USB o capturar cajas recibidas. Las existencias reales solo aumentarán cuando confirme explícitamente la recepción.
                    </p>
                    <div className="mt-4 grid gap-4 md:grid-cols-3">
                        <label className="flex flex-col gap-1 text-sm">
                            Ubicación física de recepción ({branch.code})
                            <select
                                required
                                className={field}
                                value={form.data.automotive_location_id}
                                onChange={e => form.setData('automotive_location_id', e.target.value)}
                            >
                                {locations.map(loc => (
                                    <option key={loc.id} value={loc.id}>
                                        {loc.code} · {loc.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="flex flex-col gap-1 text-sm md:col-span-2">
                            Observaciones / Referencia de traslado (opcional)
                            <input
                                className={field}
                                value={form.data.notes}
                                onChange={e => form.setData('notes', e.target.value)}
                                placeholder="Ej. Tarima #1 enviada a Hermosillo, conteo físico de apertura"
                            />
                        </label>
                    </div>
                    <div className="mt-4">
                        <button type="submit" className={button} disabled={form.processing}>
                            {form.processing ? 'Creando borrador…' : 'Crear borrador e iniciar escaneo'}
                        </button>
                    </div>
                </form>
            )}

            <div className={card}>
                <h2 className="mb-3 text-base font-semibold">Sesiones de recepción registradas en {branch.name}</h2>
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                            <tr>
                                <th className="p-3">Folio</th>
                                <th className="p-3">Estado</th>
                                <th className="p-3">Ubicación</th>
                                <th className="p-3">Partidas</th>
                                <th className="p-3">Unidades totales</th>
                                <th className="p-3">Creado por / Fecha</th>
                                <th className="p-3">Confirmado por / Fecha</th>
                                <th className="p-3">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            {receipts.data.map(rec => (
                                <tr key={rec.id} className="border-b border-slate-100 dark:border-neutral-800">
                                    <td className="p-3 font-mono font-semibold text-indigo-700">
                                        <Link href={`${baseUrl(branch)}/entradas/${rec.id}`} className="hover:underline">
                                            {rec.folio}
                                        </Link>
                                    </td>
                                    <td className="p-3">
                                        <span
                                            className={`inline-block rounded px-2 py-0.5 text-xs font-semibold ${
                                                rec.status === 'confirmed'
                                                    ? 'bg-emerald-100 text-emerald-900'
                                                    : 'bg-amber-100 text-amber-900'
                                            }`}
                                        >
                                            {statusLabel(rec.status)}
                                        </span>
                                    </td>
                                    <td className="p-3 font-mono text-xs">
                                        {rec.location ? `${rec.location.code} · ${rec.location.name}` : '—'}
                                    </td>
                                    <td className="p-3">{rec.items_count}</td>
                                    <td className="p-3 font-semibold">{rec.total_units}</td>
                                    <td className="p-3 text-xs">
                                        {rec.created_by_name || '—'}
                                        <br />
                                        <span className="text-slate-400">{rec.created_at || ''}</span>
                                    </td>
                                    <td className="p-3 text-xs">
                                        {rec.confirmed_by_name || 'Pendiente'}
                                        <br />
                                        <span className="text-slate-400">{rec.confirmed_at || ''}</span>
                                    </td>
                                    <td className="p-3">
                                        <Link
                                            href={`${baseUrl(branch)}/entradas/${rec.id}`}
                                            className="font-medium text-indigo-600 hover:underline"
                                        >
                                            {rec.status === 'draft' && canDraft ? 'Continuar escaneo →' : 'Ver detalle →'}
                                        </Link>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {receipts.data.length === 0 && (
                        <p className="p-8 text-center text-slate-500">
                            Aún no hay sesiones de recepción registradas para {branch.name}.
                        </p>
                    )}
                </div>
                <Pagination data={receipts} />
            </div>
        </Layout>
    )
}
