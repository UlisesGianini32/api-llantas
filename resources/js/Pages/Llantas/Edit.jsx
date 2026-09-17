import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router, useForm, usePage } from '@inertiajs/react'

function inputCls() {
    return 'w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white'
}

export default function Edit({ llanta, filters }) {
    const { errors = {} } = usePage().props

    const form = useForm({
        marca: llanta.marca ?? '',
        medida: llanta.medida ?? '',
        descripcion: llanta.descripcion ?? '',
        title_familyname: llanta.title_familyname ?? '',
        costo: llanta.costo ?? '',
        precio_ML: llanta.precio_ML ?? '',
        stock: llanta.stock ?? '',
        MLM: llanta.MLM ?? '',

        page: filters.page ?? 1,
        search: filters.search ?? '',
        sort: filters.sort ?? 'sku',
        dir: filters.dir ?? 'asc',
        per_page: filters.per_page ?? 25,
        ml_status: filters.ml_status ?? '',
    })

    const save = (e) => {
        e.preventDefault()

        form.put(`/llantas/${llanta.id}`, {
            preserveScroll: true,
        })
    }

    const backUrl =
        `/llantas?page=${encodeURIComponent(form.data.page)}`
        + `&search=${encodeURIComponent(form.data.search)}`
        + `&sort=${encodeURIComponent(form.data.sort)}`
        + `&dir=${encodeURIComponent(form.data.dir)}`
        + `&per_page=${encodeURIComponent(form.data.per_page)}`
        + `&ml_status=${encodeURIComponent(form.data.ml_status)}`

    const mode = (llanta.price_mode || 'auto').toLowerCase()

    const setManual = () => {
        router.post(`/llantas/${llanta.id}/price/manual`, {}, {
            preserveScroll: true,
        })
    }

    const setAuto = () => {
        router.post(`/llantas/${llanta.id}/price/auto`, {}, {
            preserveScroll: true,
        })
    }

    const recalcPrice = () => {
        router.post(`/llantas/${llanta.id}/price/recalc`, {}, {
            preserveScroll: true,
        })
    }

    return (
        <>
            <Head title={`Editar llanta - ${llanta.sku}`} />

            <AppShell title="Editar llanta">
                <div className="mx-auto max-w-3xl space-y-6">
                    <h1 className="text-2xl font-bold text-zinc-900 dark:text-white">
                        Editar llanta – {llanta.sku}
                    </h1>

                    {Object.keys(errors).length > 0 && (
                        <div className="rounded-lg border border-red-300 bg-red-50 p-4 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
                            <div className="mb-2 font-semibold">Hay errores:</div>
                            <ul className="list-disc pl-5 space-y-1 text-sm">
                                {Object.entries(errors).map(([key, value]) => (
                                    <li key={key}>{value}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <div className="rounded-xl border border-zinc-200 bg-white p-6 text-zinc-900 dark:border-neutral-800 dark:bg-neutral-900 dark:text-white">
                        <form onSubmit={save} className="space-y-4">
                            <div>
                                <label className="block text-sm mb-1 text-zinc-600 dark:text-gray-400">
                                    Marca
                                </label>
                                <input
                                    type="text"
                                    value={form.data.marca}
                                    onChange={(e) => form.setData('marca', e.target.value)}
                                    required
                                    className={inputCls()}
                                />
                            </div>

                            <div>
                                <label className="block text-sm mb-1 text-zinc-600 dark:text-gray-400">
                                    Medida
                                </label>
                                <input
                                    type="text"
                                    value={form.data.medida}
                                    onChange={(e) => form.setData('medida', e.target.value)}
                                    required
                                    className={inputCls()}
                                />
                            </div>

                            <div>
                                <label className="block text-sm mb-1 text-zinc-600 dark:text-gray-400">
                                    Descripción
                                </label>
                                <textarea
                                    rows="3"
                                    value={form.data.descripcion}
                                    onChange={(e) => form.setData('descripcion', e.target.value)}
                                    className={inputCls()}
                                />
                            </div>

                            <div>
                                <label className="block text-sm mb-1 text-zinc-600 dark:text-gray-400">
                                    Título MercadoLibre
                                </label>
                                <input
                                    type="text"
                                    value={form.data.title_familyname}
                                    onChange={(e) => form.setData('title_familyname', e.target.value)}
                                    required
                                    className={inputCls()}
                                />
                            </div>

                            <div>
                                <label className="block text-sm mb-1 text-zinc-600 dark:text-gray-400">
                                    Costo
                                </label>
                                <input
                                    type="number"
                                    step="0.01"
                                    value={form.data.costo}
                                    onChange={(e) => form.setData('costo', e.target.value)}
                                    required
                                    className={inputCls()}
                                />
                            </div>

                            <div>
                                <label className="block text-sm mb-1 text-zinc-600 dark:text-gray-400">
                                    Precio MercadoLibre
                                </label>
                                <input
                                    type="number"
                                    step="0.01"
                                    value={form.data.precio_ML}
                                    onChange={(e) => form.setData('precio_ML', e.target.value)}
                                    required
                                    className={inputCls()}
                                />
                                <p className="mt-1 text-xs text-zinc-500 dark:text-gray-500">
                                    Si lo editas, se marcará MANUAL y el import ya no lo pisa.
                                </p>
                            </div>

                            <div>
                                <label className="block text-sm mb-1 text-zinc-600 dark:text-gray-400">
                                    Stock
                                </label>
                                <input
                                    type="number"
                                    value={form.data.stock}
                                    onChange={(e) => form.setData('stock', e.target.value)}
                                    required
                                    className={inputCls()}
                                />
                            </div>

                            <div>
                                <label className="block text-sm mb-1 text-zinc-600 dark:text-gray-400">
                                    MLM
                                </label>
                                <input
                                    type="text"
                                    value={form.data.MLM}
                                    onChange={(e) => form.setData('MLM', e.target.value)}
                                    className={inputCls()}
                                />
                            </div>

                            <div className="flex gap-3 pt-2">
                                <button
                                    type="submit"
                                    disabled={form.processing}
                                    className="rounded-md bg-indigo-600 px-4 py-2 font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
                                >
                                    {form.processing ? 'Guardando...' : 'Guardar'}
                                </button>

                                <Link
                                    href={backUrl}
                                    className="rounded-md border border-zinc-300 bg-white px-4 py-2 text-zinc-900 hover:bg-zinc-100 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white dark:hover:bg-neutral-800"
                                >
                                    Cancelar
                                </Link>
                            </div>
                        </form>

                        <div className="mt-6 border-t border-zinc-200 pt-5 dark:border-neutral-800">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p className="text-sm font-semibold text-zinc-900 dark:text-white">
                                        Control de precio (para que el import no lo cambie)
                                    </p>
                                    <p className="text-xs text-zinc-500 dark:text-gray-400">
                                        MANUAL = el import no pisa el precio · AUTO = el import sí puede recalcular.
                                    </p>
                                </div>

                                <span
                                    className={`inline-flex items-center rounded-md border px-3 py-1 text-xs font-semibold ${
                                        mode === 'manual'
                                            ? 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950 dark:text-amber-200'
                                            : 'border-sky-300 bg-sky-50 text-sky-800 dark:border-sky-900/50 dark:bg-sky-950 dark:text-sky-200'
                                    }`}
                                >
                                    Estado: {mode.toUpperCase()}
                                </span>
                            </div>

                            <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <button
                                    type="button"
                                    onClick={setManual}
                                    className="w-full rounded-md border border-zinc-300 bg-white px-4 py-3 text-left hover:bg-zinc-50 transition dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                                >
                                    <div className="text-sm font-semibold text-zinc-900 dark:text-white">
                                        Bloquear (MANUAL)
                                    </div>
                                    <div className="text-xs text-zinc-500 dark:text-gray-400">
                                        El import ya NO cambiará el precio.
                                    </div>
                                </button>

                                <button
                                    type="button"
                                    onClick={setAuto}
                                    className="w-full rounded-md border border-zinc-300 bg-white px-4 py-3 text-left hover:bg-zinc-50 transition dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                                >
                                    <div className="text-sm font-semibold text-zinc-900 dark:text-white">
                                        Permitir import (AUTO)
                                    </div>
                                    <div className="text-xs text-zinc-500 dark:text-gray-400">
                                        El import podrá recalcular el precio.
                                    </div>
                                </button>

                                <button
                                    type="button"
                                    onClick={recalcPrice}
                                    className="w-full rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-left hover:bg-emerald-100 transition dark:border-emerald-900/50 dark:bg-emerald-950 dark:hover:bg-emerald-900/40"
                                >
                                    <div className="text-sm font-semibold text-emerald-800 dark:text-emerald-200">
                                        Recalcular (AUTO)
                                    </div>
                                    <div className="text-xs text-emerald-700/80 dark:text-emerald-200/80">
                                        Aplica fórmula activa y pone AUTO.
                                    </div>
                                </button>
                            </div>
                        </div>

                        <div className="mt-6 border-t border-zinc-200 pt-5 dark:border-neutral-800">
                            <p className="text-sm font-semibold text-zinc-900 dark:text-white">
                                Publicaciones ML
                            </p>

                            {llanta.meli_publications?.length ? (
                                <div className="mt-4 space-y-3">
                                    {llanta.meli_publications.map((pub) => (
                                        <div
                                            key={pub.id}
                                            className="rounded-lg border border-zinc-200 p-4 dark:border-neutral-800"
                                        >
                                            <div className="font-semibold text-indigo-600 dark:text-indigo-400">
                                                {pub.mlm || '—'}
                                            </div>
                                            <div className="text-sm text-zinc-500 dark:text-gray-400">
                                                Estado: {pub.status || '—'}
                                            </div>
                                            {pub.permalink && (
                                                <a
                                                    href={pub.permalink}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="mt-2 inline-block text-sm text-indigo-600 hover:underline dark:text-indigo-400"
                                                >
                                                    Abrir publicación
                                                </a>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <div className="mt-4 rounded-lg border border-zinc-200 p-4 text-sm text-zinc-500 dark:border-neutral-800 dark:text-gray-400">
                                    Esta llanta todavía no tiene publicaciones registradas.
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </AppShell>
        </>
    )
}