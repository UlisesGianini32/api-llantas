import AppShell from '@/Components/layout/AppShell'
import { formatDateTime, reservationSourceLabel, reservationStatusLabel } from '@/lib/inventoryPresentation'
import { Head, Link, router } from '@inertiajs/react'

export default function InventoryReservationShow({ reservation }) {
    const active = reservation.status === 'ACTIVE'
    const action = (name) => router.post('/almacen/reservas/' + reservation.id + '/' + name, {}, { preserveScroll: true })

    const creator = reservation.creator_name || reservation.created_by?.name || '—'

    return (
        <AppShell title="Detalle de reserva">
            <Head title="Detalle de reserva" />
            <div className="mx-auto max-w-2xl space-y-6">
                <Link href="/almacen/reservas" className="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-300">
                    ← Volver a reservas
                </Link>
                <div className="rounded-2xl border border-slate-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900">
                    <h1 className="text-2xl font-bold dark:text-white">{reservation.product?.name}</h1>
                    <p className="mt-1 font-mono text-sm text-slate-500">{reservation.product?.sku}</p>

                    <dl className="mt-5 grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt className="text-xs uppercase text-slate-500">Ubicación</dt>
                            <dd className="mt-1 dark:text-white">{reservation.location?.code || 'Global'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs uppercase text-slate-500">Cantidad</dt>
                            <dd className="mt-1 font-bold dark:text-white">{reservation.quantity}</dd>
                        </div>
                        <div>
                            <dt className="text-xs uppercase text-slate-500">Estado</dt>
                            <dd className="mt-1 dark:text-white">{reservationStatusLabel(reservation.status)}</dd>
                        </div>
                        <div>
                            <dt className="text-xs uppercase text-slate-500">Fuente</dt>
                            <dd className="mt-1 dark:text-white">{reservationSourceLabel(reservation)}</dd>
                        </div>
                        <div>
                            <dt className="text-xs uppercase text-slate-500">Referencia</dt>
                            <dd className="mt-1 dark:text-white">{reservation.reference || '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-xs uppercase text-slate-500">Expiración</dt>
                            <dd className="mt-1 dark:text-white">{formatDateTime(reservation.expires_at)}</dd>
                        </div>
                        <div>
                            <dt className="text-xs uppercase text-slate-500">Creada por</dt>
                            <dd className="mt-1 dark:text-white">
                                <span className={`inline-flex items-center gap-1.5 font-medium ${
                                    creator.includes('Mercado Libre')
                                        ? 'text-amber-800 dark:text-amber-300'
                                        : creator.includes('Amazon')
                                        ? 'text-orange-800 dark:text-orange-300'
                                        : creator.includes('Shopify')
                                        ? 'text-emerald-800 dark:text-emerald-300'
                                        : 'text-slate-700 dark:text-slate-300'
                                }`}>
                                    {creator.includes('Mercado Libre') && <span className="h-2 w-2 rounded-full bg-amber-500"></span>}
                                    {creator.includes('Amazon') && <span className="h-2 w-2 rounded-full bg-orange-500"></span>}
                                    {creator.includes('Shopify') && <span className="h-2 w-2 rounded-full bg-emerald-500"></span>}
                                    {creator}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs uppercase text-slate-500">Creada</dt>
                            <dd className="mt-1 dark:text-white">{formatDateTime(reservation.created_at)}</dd>
                        </div>
                    </dl>

                    {active && (
                        <div className="mt-6 flex flex-wrap gap-3">
                            <button type="button" onClick={() => action('liberar')} className="rounded-xl bg-amber-600 px-4 py-2.5 font-semibold text-white hover:bg-amber-700">
                                Liberar
                            </button>
                            <button type="button" onClick={() => action('cancelar')} className="rounded-xl bg-rose-600 px-4 py-2.5 font-semibold text-white hover:bg-rose-700">
                                Cancelar
                            </button>
                            <button type="button" onClick={() => action('cumplir')} className="rounded-xl bg-emerald-600 px-4 py-2.5 font-semibold text-white hover:bg-emerald-700">
                                Cumplir
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </AppShell>
    )
}
