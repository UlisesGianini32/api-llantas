import AppShell from '@/Components/layout/AppShell'
import { formatDateTime, reservationStatusLabel } from '@/lib/inventoryPresentation'
import { Head, Link, router, useForm } from '@inertiajs/react'

export default function InventoryKitShow({ kit, components = [], physicalStock = 0, availableStock = 0, reservations = [] }) {
    const { data, setData, post, processing, errors } = useForm({ quantity: 1, reference: '', expires_at: '' })
    const reserve = (event) => {
        event.preventDefault()
        post(`/almacen/kits/${kit.id}/reservas`)
    }
    const action = (id, name) => router.post(`/almacen/kits/reservas/${id}/${name}`, {}, { preserveScroll: true })

    return (
        <AppShell title="Detalle de kit">
            <Head title={kit.name} />
            <div className="mx-auto max-w-4xl space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <Link href="/almacen/kits" className="text-sm font-semibold text-indigo-600 hover:underline">
                            ← Volver a Kits
                        </Link>
                        <h1 className="mt-2 text-3xl font-bold">{kit.name}</h1>
                        <p className="font-mono text-sm text-slate-500">{kit.sku}</p>
                    </div>
                    <Link
                        href={`/almacen/kits/${kit.id}/editar-componentes`}
                        className="rounded-xl bg-indigo-600 px-4 py-2 font-semibold text-white shadow hover:bg-indigo-700 transition"
                    >
                        Configurar componentes
                    </Link>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-xl border p-4 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-sm text-slate-500">Físico posible</span>
                        <strong className="block text-3xl font-bold mt-1">{physicalStock}</strong>
                        <span className="text-xs text-slate-500">Stock derivado de componentes</span>
                    </div>
                    <div className="rounded-xl border p-4 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-sm text-slate-500">Disponible</span>
                        <strong className="block text-3xl font-bold mt-1 text-emerald-600">{availableStock}</strong>
                    </div>
                    <div className="rounded-xl border p-4 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-sm text-slate-500">Componentes requeridos</span>
                        <strong className="block text-3xl font-bold mt-1 text-indigo-600">{components.length}</strong>
                    </div>
                </div>

                <section className="rounded-2xl border bg-white dark:border-neutral-800 dark:bg-neutral-900 overflow-hidden shadow-sm">
                    <div className="flex items-center justify-between border-b p-5 bg-slate-50/50 dark:bg-neutral-950/50">
                        <div>
                            <h2 className="font-bold text-lg">Componentes y Ubicaciones de Almacén</h2>
                            <p className="text-xs text-slate-500 mt-0.5">
                                Ubicaciones físicas tomadas automáticamente del almacén para cada pieza de este kit.
                            </p>
                        </div>
                        <Link
                            href={`/almacen/kits/${kit.id}/editar-componentes`}
                            className="text-xs font-semibold text-indigo-600 hover:underline"
                        >
                            Editar componentes →
                        </Link>
                    </div>

                    {components.length === 0 ? (
                        <p className="p-6 text-amber-700">⚠️ Kit sin componentes configurados. Haz clic en "Configurar componentes" para agregar.</p>
                    ) : (
                        <div className="divide-y divide-slate-100 dark:divide-neutral-800">
                            {components.map((row) => {
                                const comp = row.component || {}
                                const pickingCode = row.primary_location_code || comp.primary_location?.code || comp.primaryLocation?.code
                                const pickingAisle = row.primary_location_aisle || comp.primary_location?.amazon_aisle || comp.primaryLocation?.amazon_aisle
                                const reserveCode = row.secondary_location_code || comp.secondary_location?.code || comp.secondaryLocation?.code
                                const reserveAisle = row.secondary_location_aisle || comp.secondary_location?.amazon_aisle || comp.secondaryLocation?.amazon_aisle
                                const notes = row.reserve_notes || comp.reserve_notes

                                return (
                                    <div key={row.id || comp.id} className="p-4 sm:p-5 text-sm space-y-3 hover:bg-slate-50/50 dark:hover:bg-neutral-950/30 transition">
                                        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                                            <div>
                                                <span className="font-bold text-slate-900 dark:text-white text-base">{comp.name}</span>
                                                <span className="ml-2 font-mono text-xs text-slate-500 bg-slate-100 dark:bg-neutral-800 px-2 py-0.5 rounded">
                                                    {comp.sku}
                                                </span>
                                            </div>
                                            <div className="flex items-center gap-4 text-xs font-medium">
                                                <span className="bg-slate-100 dark:bg-neutral-800 px-2.5 py-1 rounded-md">
                                                    Requiere: <strong className="text-indigo-600 font-bold">x{row.quantity}</strong>
                                                </span>
                                                <span>Físico: <strong>{row.physical_stock}</strong></span>
                                                <strong className="text-emerald-700 dark:text-emerald-400">
                                                    Disponible: {row.available_stock}
                                                </strong>
                                            </div>
                                        </div>

                                        <div className="flex flex-wrap items-center gap-2.5 pt-1">
                                            <div className={`inline-flex items-center gap-1.5 rounded-lg border px-3 py-1 font-semibold text-xs ${
                                                pickingCode
                                                    ? 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-800/80 dark:bg-emerald-950/60 dark:text-emerald-300'
                                                    : 'border-slate-200 bg-slate-50 text-slate-500 dark:border-neutral-800 dark:bg-neutral-950 dark:text-slate-400'
                                            }`}>
                                                <span>📍 Picking:</span>
                                                <strong className="font-bold">{pickingCode || 'Sin ubicación asignada'}</strong>
                                                {pickingAisle ? (
                                                    <span className="ml-1 text-[11px] font-normal text-emerald-700 dark:text-emerald-400 bg-emerald-200/60 dark:bg-emerald-900/60 px-1.5 py-0.2 rounded">
                                                        Pasillo {pickingAisle}
                                                    </span>
                                                ) : null}
                                            </div>

                                            {reserveCode ? (
                                                <div className="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-3 py-1 font-semibold text-xs text-amber-900 dark:border-amber-800/80 dark:bg-amber-950/60 dark:text-amber-300">
                                                    <span>📦 Reserva:</span>
                                                    <strong className="font-bold">{reserveCode}</strong>
                                                    {reserveAisle ? (
                                                        <span className="ml-1 text-[11px] font-normal text-amber-700 dark:text-amber-400 bg-amber-200/60 dark:bg-amber-900/60 px-1.5 py-0.2 rounded">
                                                            Pasillo {reserveAisle}
                                                        </span>
                                                    ) : null}
                                                </div>
                                            ) : null}

                                            {notes ? (
                                                <span className="text-slate-500 italic text-xs">
                                                    📝 {notes}
                                                </span>
                                            ) : null}
                                        </div>
                                    </div>
                                )
                            })}
                        </div>
                    )}
                </section>

                <section className="rounded-2xl border bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900 shadow-sm">
                    <h2 className="font-bold text-lg">Reservar kit</h2>
                    <form onSubmit={reserve} className="mt-4 grid gap-3 sm:grid-cols-4">
                        <input
                            type="number"
                            min="1"
                            value={data.quantity}
                            onChange={(e) => setData('quantity', e.target.value)}
                            className="rounded-xl border px-3 py-2 text-sm"
                            placeholder="Cantidad"
                        />
                        <input
                            placeholder="Referencia"
                            value={data.reference}
                            onChange={(e) => setData('reference', e.target.value)}
                            className="rounded-xl border px-3 py-2 text-sm"
                        />
                        <input
                            type="datetime-local"
                            value={data.expires_at}
                            onChange={(e) => setData('expires_at', e.target.value)}
                            className="rounded-xl border px-3 py-2 text-sm"
                        />
                        <button
                            disabled={processing}
                            className="rounded-xl bg-indigo-600 px-4 py-2 font-semibold text-white shadow hover:bg-indigo-700 transition"
                        >
                            Reservar
                        </button>
                    </form>
                    {errors.quantity && <p className="mt-2 text-sm text-rose-600">{errors.quantity}</p>}
                </section>

                <section className="rounded-2xl border bg-white dark:border-neutral-800 dark:bg-neutral-900 shadow-sm overflow-hidden">
                    <h2 className="border-b p-5 font-bold text-lg bg-slate-50/50 dark:bg-neutral-950/50">Historial de reservas</h2>
                    {reservations.length === 0 ? (
                        <p className="p-6 text-sm text-slate-500">Sin reservas activas para este kit.</p>
                    ) : (
                        <div className="divide-y divide-slate-100 dark:divide-neutral-800">
                            {reservations.map((reservation) => (
                                <div key={reservation.id} className="flex flex-wrap items-center justify-between gap-3 p-4 text-sm">
                                    <div>
                                        <Link href={`/almacen/kits/reservas/${reservation.id}`} className="text-indigo-600 font-semibold hover:underline">
                                            #{reservation.id} · {reservation.reference || 'Sin referencia'}
                                        </Link>
                                        <div className="mt-1 text-xs text-slate-500">
                                            {formatDateTime(reservation.created_at)} · Expira: {formatDateTime(reservation.expires_at)}
                                        </div>
                                    </div>
                                    <span className="font-medium">
                                        {reservationStatusLabel(reservation.status)} · {reservation.quantity} pzs
                                    </span>
                                    <div className="flex gap-2">
                                        {reservation.status === 'ACTIVE' && (
                                            <>
                                                <button onClick={() => action(reservation.id, 'liberar')} className="text-amber-700 hover:underline text-xs font-semibold">
                                                    Liberar
                                                </button>
                                                <button onClick={() => action(reservation.id, 'cumplir')} className="text-emerald-700 hover:underline text-xs font-semibold">
                                                    Cumplir
                                                </button>
                                            </>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </AppShell>
    )
}
