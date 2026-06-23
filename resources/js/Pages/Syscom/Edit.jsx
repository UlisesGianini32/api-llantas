import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router, useForm, usePage } from '@inertiajs/react'

function inputCls() {
    return 'w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white'
}

function mxn(n) {
    if (typeof n !== 'number' || Number.isNaN(n)) return '—'
    return n.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' })
}

export default function Edit({ product, filters, meliLinked = false }) {
    const { errors = {}, flash = {} } = usePage().props

    const form = useForm({
        marca: product.marca ?? '',
        modelo: product.modelo ?? '',
        titulo: product.titulo ?? '',
        descripcion: product.descripcion ?? '',
        precio_lista: product.precio_lista ?? '',
        precio_especial: product.precio_especial ?? '',
        precio_descuento: product.precio_descuento ?? '',
        stock_hermosillo: product.stock_hermosillo ?? '',
        precio_meli: product.precio_meli ?? '',
        mlm: product.mlm ?? '',
        price_scope: product.price_scope ?? 'llanta',
        page: filters.page ?? 1,
        q: filters.q ?? '',
        cola: filters.cola ?? '',
        sync_to_meli: false,
    })

    const save = (e) => {
        e.preventDefault()
        form.put(`/syscom-ml/${product.id}`, { preserveScroll: true })
    }

    const backParams = new URLSearchParams()
    if (form.data.page > 1) backParams.set('page', String(form.data.page))
    if (form.data.q) backParams.set('q', form.data.q)
    if (form.data.cola) backParams.set('cola', form.data.cola)
    const backUrl = backParams.toString() ? `/syscom-ml?${backParams}` : '/syscom-ml'

    const mode = (product.price_mode || 'auto').toLowerCase()

    const setManual = () => {
        router.post(
            `/syscom-ml/${product.id}/price/manual`,
            { precio_meli: form.data.precio_meli },
            { preserveScroll: true }
        )
    }

    const setAuto = () => {
        router.post(`/syscom-ml/${product.id}/price/auto`, {}, { preserveScroll: true })
    }

    const recalcPrice = () => {
        router.post(`/syscom-ml/${product.id}/price/recalc`, {}, { preserveScroll: true })
    }

    const syncPriceToMl = () => {
        if (!confirm('¿Enviar precio y stock actuales a Mercado Libre?')) {
            return
        }
        router.post(`/syscom-ml/${product.id}/sync-price`, {}, { preserveScroll: true })
    }

    return (
        <>
            <Head title={`Editar SYSCOM - ${product.sku}`} />

            <AppShell title="Editar producto SYSCOM">
                <div className="mx-auto max-w-3xl space-y-6">
                    <h1 className="text-2xl font-bold text-zinc-900 dark:text-white">
                        Editar SYSCOM – {product.sku}
                    </h1>
                    <p className="text-sm text-zinc-500 dark:text-gray-400">
                        ID SYSCOM {product.syscom_producto_id}
                        {product.queue_status ? ` · cola: ${product.queue_status}` : ''}
                    </p>

                    {(flash.error || flash.err) && (
                        <div className="rounded-lg border border-red-300 bg-red-50 p-4 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
                            {flash.error || flash.err}
                        </div>
                    )}
                    {(flash.success || flash.ok) && (
                        <div className="rounded-lg border border-emerald-300 bg-emerald-50 p-4 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">
                            {flash.success || flash.ok}
                        </div>
                    )}

                    {Object.keys(errors).length > 0 && (
                        <div className="rounded-lg border border-red-300 bg-red-50 p-4 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
                            <div className="mb-2 font-semibold">Hay errores:</div>
                            <ul className="list-disc space-y-1 pl-5 text-sm">
                                {Object.entries(errors).map(([key, value]) => (
                                    <li key={key}>{value}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <div className="rounded-xl border border-zinc-200 bg-white p-6 text-zinc-900 dark:border-neutral-800 dark:bg-neutral-900 dark:text-white">
                        <form onSubmit={save} className="space-y-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label className="mb-1 block text-sm text-zinc-600 dark:text-gray-400">Marca</label>
                                    <input
                                        type="text"
                                        value={form.data.marca}
                                        onChange={(e) => form.setData('marca', e.target.value)}
                                        className={inputCls()}
                                    />
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm text-zinc-600 dark:text-gray-400">Modelo</label>
                                    <input
                                        type="text"
                                        value={form.data.modelo}
                                        onChange={(e) => form.setData('modelo', e.target.value)}
                                        className={inputCls()}
                                    />
                                </div>
                            </div>

                            <div>
                                <label className="mb-1 block text-sm text-zinc-600 dark:text-gray-400">Título</label>
                                <input
                                    type="text"
                                    value={form.data.titulo}
                                    onChange={(e) => form.setData('titulo', e.target.value)}
                                    className={inputCls()}
                                />
                            </div>

                            <div>
                                <label className="mb-1 block text-sm text-zinc-600 dark:text-gray-400">Descripción</label>
                                <textarea
                                    rows="5"
                                    value={form.data.descripcion}
                                    onChange={(e) => form.setData('descripcion', e.target.value)}
                                    className={inputCls()}
                                />
                            </div>

                            <div className="rounded-lg border border-dashed border-zinc-200 p-4 dark:border-neutral-700">
                                <p className="mb-3 text-sm font-semibold">Costos SYSCOM (USD)</p>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div>
                                        <label className="mb-1 block text-xs text-zinc-500">Lista</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            value={form.data.precio_lista}
                                            onChange={(e) => form.setData('precio_lista', e.target.value)}
                                            className={inputCls()}
                                        />
                                    </div>
                                    <div>
                                        <label className="mb-1 block text-xs text-zinc-500">Especial</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            value={form.data.precio_especial}
                                            onChange={(e) => form.setData('precio_especial', e.target.value)}
                                            className={inputCls()}
                                        />
                                    </div>
                                    <div>
                                        <label className="mb-1 block text-xs text-zinc-500">Descuento / costo</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            value={form.data.precio_descuento}
                                            onChange={(e) => form.setData('precio_descuento', e.target.value)}
                                            className={inputCls()}
                                        />
                                    </div>
                                </div>
                                <p className="mt-2 text-xs text-zinc-500">
                                    Costo MXN (fórmula): <strong>{mxn(product.costo_mxn)}</strong> · Precio fórmula
                                    actual: <strong>{mxn(product.precio_formula_mxn)}</strong>
                                </p>
                            </div>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label className="mb-1 block text-sm text-zinc-600 dark:text-gray-400">
                                        Alcance precio (fórmula)
                                    </label>
                                    <select
                                        value={form.data.price_scope}
                                        onChange={(e) => form.setData('price_scope', e.target.value)}
                                        className={inputCls()}
                                    >
                                        <option value="llanta">1 unidad</option>
                                        <option value="par">Par (×2)</option>
                                        <option value="juego4">Juego 4 (×4)</option>
                                    </select>
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm text-zinc-600 dark:text-gray-400">
                                        Stock Hermosillo
                                    </label>
                                    <input
                                        type="number"
                                        value={form.data.stock_hermosillo}
                                        onChange={(e) => form.setData('stock_hermosillo', e.target.value)}
                                        required
                                        className={inputCls()}
                                    />
                                </div>
                            </div>

                            <div>
                                <label className="mb-1 block text-sm text-zinc-600 dark:text-gray-400">
                                    Precio Mercado Libre (MXN)
                                </label>
                                <input
                                    type="number"
                                    step="0.01"
                                    value={form.data.precio_meli}
                                    onChange={(e) => form.setData('precio_meli', e.target.value)}
                                    required
                                    className={inputCls()}
                                />
                                <p className="mt-1 text-xs text-zinc-500">
                                    MANUAL solo fija el precio en este panel. Mercado Libre no cambia hasta que guardes con
                                    «enviar a ML» marcado o uses «Sincronizar precio a ML». La fórmula sigue mostrándose arriba
                                    como referencia.
                                </p>
                            </div>

                            <div>
                                <label className="mb-1 block text-sm text-zinc-600 dark:text-gray-400">MLM</label>
                                <input
                                    type="text"
                                    value={form.data.mlm}
                                    onChange={(e) => form.setData('mlm', e.target.value)}
                                    className={`${inputCls()} font-mono`}
                                    placeholder="MLM…"
                                />
                            </div>

                            {product.publish_error && (
                                <div className="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200">
                                    Último error al publicar: {product.publish_error}
                                </div>
                            )}

                            {meliLinked && form.data.mlm && (
                                <label className="flex cursor-pointer items-center gap-2 text-sm text-zinc-600 dark:text-gray-400">
                                    <input
                                        type="checkbox"
                                        checked={form.data.sync_to_meli}
                                        onChange={(e) => form.setData('sync_to_meli', e.target.checked)}
                                        className="rounded border-zinc-300"
                                    />
                                    Al guardar, enviar también precio y stock a Mercado Libre
                                </label>
                            )}

                            <div className="flex flex-wrap gap-3 pt-2">
                                <button
                                    type="submit"
                                    disabled={form.processing}
                                    className="rounded-md bg-indigo-600 px-4 py-2 font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
                                >
                                    {form.processing ? 'Guardando…' : 'Guardar'}
                                </button>
                                {meliLinked && form.data.mlm && product.can_sync_price_ml && (
                                    <button
                                        type="button"
                                        onClick={syncPriceToMl}
                                        className="rounded-md border border-sky-300 bg-sky-50 px-4 py-2 font-semibold text-sky-950 hover:bg-sky-100 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100"
                                    >
                                        Sincronizar precio a ML
                                    </button>
                                )}
                                {meliLinked && form.data.mlm && !product.can_sync_price_ml && (
                                    <p className="self-center text-xs text-amber-800 dark:text-amber-200">
                                        ML está {product.meli_status_raw || 'inactiva'}: no se puede sync. Republicá desde
                                        la lista.
                                    </p>
                                )}
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
                                        Control de precio (como en Llantas)
                                    </p>
                                    <p className="text-xs text-zinc-500 dark:text-gray-400">
                                        MANUAL = no recalcula con fórmula en el panel · AUTO = usa fórmulas SYSCOM. «Bloquear»
                                        usa el número del campo «Precio Mercado Libre», no el de la fórmula.
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
                                    className="w-full rounded-md border border-zinc-300 bg-white px-4 py-3 text-left hover:bg-zinc-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                                >
                                    <div className="text-sm font-semibold">Bloquear (MANUAL)</div>
                                    <div className="text-xs text-zinc-500">Fija el precio actual.</div>
                                </button>
                                <button
                                    type="button"
                                    onClick={setAuto}
                                    className="w-full rounded-md border border-zinc-300 bg-white px-4 py-3 text-left hover:bg-zinc-50 dark:border-neutral-700 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                                >
                                    <div className="text-sm font-semibold">Permitir fórmula (AUTO)</div>
                                    <div className="text-xs text-zinc-500">Vuelve a calcular con reglas.</div>
                                </button>
                                <button
                                    type="button"
                                    onClick={recalcPrice}
                                    className="w-full rounded-md border border-emerald-300 bg-emerald-50 px-4 py-3 text-left hover:bg-emerald-100 dark:border-emerald-900/50 dark:bg-emerald-950 dark:hover:bg-emerald-900/40"
                                >
                                    <div className="text-sm font-semibold text-emerald-800 dark:text-emerald-200">
                                        Recalcular (AUTO)
                                    </div>
                                    <div className="text-xs text-emerald-700/80">Aplica fórmula activa.</div>
                                </button>
                            </div>
                        </div>

                        <div className="mt-6 border-t border-zinc-200 pt-5 dark:border-neutral-800">
                            <p className="text-sm font-semibold text-zinc-900 dark:text-white">Publicaciones ML (mismo SKU)</p>
                            {product.meli_publications?.length ? (
                                <div className="mt-4 space-y-3">
                                    {product.meli_publications.map((pub) => (
                                        <div
                                            key={pub.id}
                                            className="rounded-lg border border-zinc-200 p-4 dark:border-neutral-800"
                                        >
                                            <div className="font-semibold text-indigo-600 dark:text-indigo-400">
                                                {pub.mlm || '—'}
                                            </div>
                                            <div className="text-sm text-zinc-500">Estado: {pub.status || '—'}</div>
                                            {pub.sub_status && (
                                                <div className="text-xs text-zinc-400">{pub.sub_status}</div>
                                            )}
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
                                <div className="mt-4 rounded-lg border border-zinc-200 p-4 text-sm text-zinc-500 dark:border-neutral-800">
                                    Sin publicaciones registradas para este SKU.
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </AppShell>
        </>
    )
}
