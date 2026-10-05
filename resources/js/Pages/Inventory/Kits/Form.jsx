import AppShell from '@/Components/layout/AppShell'
import { Head, Link, useForm } from '@inertiajs/react'

export default function InventoryKitForm({ kit, products = [] }) {
    const { data, setData, put, processing, errors } = useForm({
        components: (kit.kit_components || []).map((row) => ({
            component_product_id: row.component_product_id,
            quantity: row.quantity,
        })),
    })

    const submit = (event) => {
        event.preventDefault()
        put(`/almacen/kits/${kit.id}/componentes`)
    }

    const updateRow = (index, changes) => {
        setData(
            'components',
            data.components.map((item, i) => (i === index ? { ...item, ...changes } : item))
        )
    }

    const add = () => {
        setData('components', [
            ...data.components,
            { component_product_id: products[0]?.id || '', quantity: 1 },
        ])
    }

    const productsMap = new Map(products.map((p) => [String(p.id), p]))

    return (
        <AppShell title="Componentes del kit">
            <Head title={`Componentes · ${kit.name}`} />
            <div className="mx-auto max-w-3xl space-y-6">
                <div>
                    <Link href={`/almacen/kits/${kit.id}`} className="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-400">
                        ← Volver al kit
                    </Link>
                    <h1 className="mt-2 text-3xl font-bold text-slate-900 dark:text-white">Componentes de {kit.name}</h1>
                    <p className="mt-1 text-sm text-slate-500">
                        Selecciona los productos y las cantidades que componen este paquete. Las ubicaciones de almacén se vinculan automáticamente desde cada producto.
                    </p>
                </div>

                <form onSubmit={submit} className="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="space-y-4">
                        {data.components.length === 0 ? (
                            <p className="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500 dark:border-neutral-700">
                                No hay componentes agregados todavía. Presiona "Agregar componente" para comenzar.
                            </p>
                        ) : (
                            data.components.map((row, index) => {
                                const prod = productsMap.get(String(row.component_product_id))

                                return (
                                    <div
                                        key={index}
                                        className="space-y-3 rounded-2xl border border-slate-200 bg-slate-50/70 p-4 transition dark:border-neutral-800 dark:bg-neutral-950/60"
                                    >
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                                            <div className="flex-1">
                                                <label className="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">
                                                    Producto componente #{index + 1}
                                                </label>
                                                <select
                                                    value={row.component_product_id}
                                                    onChange={(event) => updateRow(index, { component_product_id: event.target.value })}
                                                    className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                                                >
                                                    {products.map((product) => (
                                                        <option key={product.id} value={product.id}>
                                                            {product.name} · SKU: {product.sku}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>

                                            <div className="w-full sm:w-28">
                                                <label className="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">
                                                    Cantidad
                                                </label>
                                                <input
                                                    type="number"
                                                    min="1"
                                                    value={row.quantity}
                                                    onChange={(event) => updateRow(index, { quantity: event.target.value })}
                                                    className="w-full rounded-xl border border-slate-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                                                />
                                            </div>

                                            <div className="sm:pt-5">
                                                <button
                                                    type="button"
                                                    onClick={() => setData('components', data.components.filter((_, i) => i !== index))}
                                                    className="rounded-xl border border-rose-300 px-3 py-2 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 dark:border-rose-900 dark:text-rose-400 dark:hover:bg-rose-950/40"
                                                >
                                                    Quitar
                                                </button>
                                            </div>
                                        </div>

                                        {/* Ubicaciones del componente seleccionado */}
                                        <div className="flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs dark:border-neutral-800 dark:bg-neutral-900">
                                            <span className="font-semibold text-slate-500">Ubicación de almacén:</span>
                                            <span className="inline-flex items-center rounded-md bg-emerald-100 px-2 py-0.5 font-bold text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                                📍 Picking: {prod?.primary_location?.code || 'Sin ubicación asignada'}
                                            </span>
                                            {prod?.secondary_location?.code ? (
                                                <span className="inline-flex items-center rounded-md bg-amber-100 px-2 py-0.5 font-bold text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">
                                                    📦 Reserva: {prod.secondary_location.code}
                                                </span>
                                            ) : null}
                                            {prod?.reserve_notes ? (
                                                <span className="text-slate-500 italic">
                                                    ({prod.reserve_notes})
                                                </span>
                                            ) : null}
                                        </div>
                                    </div>
                                )
                            })
                        )}
                    </div>

                    {errors.components && <p className="text-sm font-semibold text-rose-600">{errors.components}</p>}

                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-t border-slate-200 pt-4 dark:border-neutral-800">
                        <button
                            type="button"
                            onClick={add}
                            className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-200 dark:hover:bg-neutral-800"
                        >
                            + Agregar componente
                        </button>
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
                        >
                            {processing ? 'Guardando…' : 'Guardar componentes'}
                        </button>
                    </div>
                </form>
            </div>
        </AppShell>
    )
}
