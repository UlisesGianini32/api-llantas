import AppShell from '@/Components/layout/AppShell'
import { formatDateTime, reservationStatusLabel } from '@/lib/inventoryPresentation'
import { Head, Link } from '@inertiajs/react'

export default function InventoryKitReservationShow({ reservation }) {
    const creator = reservation.creator_name || reservation.created_by?.name || '—'

    return (
        <AppShell title="Reserva de kit">
            <Head title={`Reserva #${reservation.id}`} />
            <div className="mx-auto max-w-3xl space-y-6">
                <Link href={`/almacen/kits/${reservation.kit_product_id}`} className="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-300">
                    ← Volver al kit
                </Link>
                <h1 className="text-3xl font-bold dark:text-white">Reserva #{reservation.id}</h1>
                <div className="rounded-2xl border bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                    <p className="text-lg"><strong>{reservation.kit?.name}</strong> · {reservation.quantity} unidades</p>
                    <p className="mt-2 text-sm text-slate-500">Estado: {reservationStatusLabel(reservation.status)}</p>
                    <p className="mt-1 text-sm text-slate-500">Referencia: {reservation.reference || '—'}</p>
                    <p className="mt-1 text-sm text-slate-500">
                        Creado por: <span className="font-medium text-slate-800 dark:text-slate-200">{creator}</span>
                    </p>
                    <p className="mt-1 text-sm text-slate-500">
                        Creada: {formatDateTime(reservation.created_at)} · Expira: {formatDateTime(reservation.expires_at)}
                    </p>
                </div>
                <section className="rounded-2xl border bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <h2 className="border-b border-slate-200 p-5 font-bold dark:border-neutral-800 dark:text-white">Reservas componentes</h2>
                    {reservation.component_reservations?.map((child) => (
                        <div key={child.id} className="grid grid-cols-4 border-b border-slate-100 p-4 text-sm dark:border-neutral-800">
                            <span>{child.product?.name}</span>
                            <span>{child.quantity}</span>
                            <span>{child.location?.code}</span>
                            <strong>{reservationStatusLabel(child.status)}</strong>
                        </div>
                    ))}
                </section>
            </div>
        </AppShell>
    )
}
