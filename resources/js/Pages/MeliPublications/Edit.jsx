import AppShell from '@/Components/layout/AppShell'
import { useEffect, useMemo, useState } from 'react'

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
}

async function api(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            ...(options.headers ?? {}),
        },
    })

    const body = await response.json().catch(() => ({}))

    if (!response.ok) {
        const validation = body?.errors
            ? Object.values(body.errors).flat().join('\n')
            : ''

        throw new Error(
            validation
            || body?.message
            || `Error HTTP ${response.status}`
        )
    }

    return body
}

function statusBadge(status) {
    const classes = {
        active: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        paused: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    }

    return classes[status]
        ?? 'bg-slate-100 text-slate-700 dark:bg-neutral-800 dark:text-slate-300'
}

function Notice({ type, children }) {
    if (!children) return null

    const styles = type === 'error'
        ? 'border-rose-300 bg-rose-50 text-rose-800 dark:border-rose-800 dark:bg-rose-500/10 dark:text-rose-200'
        : 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200'

    return (
        <div className={`rounded-2xl border px-4 py-3 text-sm font-semibold ${styles}`}>
            {children}
        </div>
    )
}

export default function Edit({ publication: initialPublication, backUrl }) {
    const [publication, setPublication] = useState(initialPublication)
    const [price, setPrice] = useState(initialPublication.price ?? '')
    const [stock, setStock] = useState(initialPublication.stock ?? 0)
    const [variationStocks, setVariationStocks] = useState({})
    const [busyAction, setBusyAction] = useState(null)
    const [notice, setNotice] = useState(null)

    useEffect(() => {
        setPrice(publication.price ?? '')
        setStock(publication.stock ?? 0)
        setVariationStocks(
            Object.fromEntries(
                (publication.variations ?? []).map((variation) => [
                    String(variation.id),
                    variation.stock,
                ])
            )
        )
    }, [publication])

    const editableVariationCount = useMemo(
        () => (publication.variations ?? [])
            .filter((variation) => variation.can_update_stock)
            .length,
        [publication.variations]
    )

    const execute = async (action, callback) => {
        setBusyAction(action)
        setNotice(null)

        try {
            const result = await callback()

            if (result?.publication) {
                setPublication(result.publication)
            }

            setNotice({
                type: 'success',
                message: result?.message || 'Cambios guardados correctamente.',
            })
        } catch (error) {
            setNotice({
                type: 'error',
                message: error instanceof Error ? error.message : String(error),
            })
        } finally {
            setBusyAction(null)
        }
    }

    const saveSimple = () => execute('simple', () =>
        api(`/meli/publicaciones/${publication.id}`, {
            method: 'PUT',
            body: JSON.stringify({
                price: price === '' ? null : Number(price),
                stock: publication.can_update_stock && stock !== ''
                    ? Number(stock)
                    : null,
            }),
        })
    )

    const savePrice = () => execute('price', () =>
        api(`/meli/publicaciones/${publication.id}`, {
            method: 'PUT',
            body: JSON.stringify({
                price: price === '' ? null : Number(price),
            }),
        })
    )

    const saveVariation = (variation) => execute(
        `variation-${variation.id}`,
        () => api(`/meli/publicaciones/${publication.id}`, {
            method: 'PUT',
            body: JSON.stringify({
                stock: Number(
                    variationStocks[String(variation.id)]
                    ?? variation.stock
                    ?? 0
                ),
                variation_id: String(variation.id),
            }),
        })
    )

    const refresh = () => execute('refresh', () =>
        api(`/meli/publicaciones/${publication.id}/refresh`, {
            method: 'POST',
            body: '{}',
        })
    )

    const busy = busyAction !== null

    return (
        <AppShell title={`Conteo · ${publication.mlm}`}>
            <div className="mx-auto max-w-5xl space-y-5 p-4 sm:p-6">
                <header className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div className="flex min-w-0 gap-4">
                            <div className="flex h-28 w-28 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-neutral-700 dark:bg-neutral-800">
                                {publication.thumbnail ? (
                                    <img
                                        src={publication.thumbnail}
                                        alt=""
                                        className="h-full w-full object-contain"
                                    />
                                ) : (
                                    <span className="text-xs text-slate-400">
                                        Sin imagen
                                    </span>
                                )}
                            </div>

                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className={`inline-flex rounded-full px-3 py-1 text-xs font-bold ${statusBadge(publication.status)}`}>
                                        {publication.status_label}
                                    </span>

                                    {publication.shared && (
                                        <span className={`inline-flex rounded-full px-3 py-1 text-xs font-bold ${
                                            publication.is_default_account
                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                                                : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300'
                                        }`}>
                                            {publication.is_default_account
                                                ? 'Cuenta 1 · stock maestro'
                                                : 'Cuenta 2 · stock espejo'}
                                        </span>
                                    )}
                                </div>

                                <h1 className="mt-3 text-xl font-black text-slate-900 dark:text-white sm:text-2xl">
                                    {publication.title}
                                </h1>

                                <div className="mt-3 grid gap-1 text-sm text-slate-500 dark:text-slate-400">
                                    <p><strong>SKU:</strong> {publication.sku || '—'}</p>
                                    <p><strong>MLM:</strong> {publication.mlm}</p>
                                    <p><strong>Cuenta:</strong> {publication.account_name}</p>
                                    <p><strong>Ventas:</strong> {publication.sold_quantity}</p>
                                </div>
                            </div>
                        </div>

                        <div className="flex flex-wrap gap-2">
                            <a
                                href={backUrl}
                                className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-200 dark:hover:bg-neutral-800"
                            >
                                Volver al listado
                            </a>

                            {publication.permalink && (
                                <a
                                    href={publication.permalink}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="rounded-xl border border-indigo-300 px-4 py-2 text-sm font-bold text-indigo-700 hover:bg-indigo-50 dark:border-indigo-800 dark:text-indigo-300 dark:hover:bg-indigo-500/10"
                                >
                                    Ver en Mercado Libre
                                </a>
                            )}

                            <button
                                type="button"
                                disabled={busy}
                                onClick={refresh}
                                className="rounded-xl bg-slate-800 px-4 py-2 text-sm font-bold text-white hover:bg-slate-900 disabled:opacity-50 dark:bg-slate-200 dark:text-slate-900"
                            >
                                {busyAction === 'refresh'
                                    ? 'Sincronizando…'
                                    : 'Sincronizar'}
                            </button>
                        </div>
                    </div>
                </header>

                <Notice type={notice?.type}>
                    {notice?.message}
                </Notice>

                <section className="rounded-3xl border border-violet-200 bg-violet-50/60 p-5 dark:border-violet-900 dark:bg-violet-500/5">
                    <p className="text-xs font-black uppercase tracking-[0.18em] text-violet-700 dark:text-violet-300">
                        Pestaña para conteo físico
                    </p>
                    <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">
                        Puedes dejar esta pestaña abierta, abrir otras publicaciones
                        y después recorrer la bodega para contarlas todas.
                    </p>
                </section>

                <section className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="grid gap-5 md:grid-cols-[1fr_auto] md:items-end">
                        <div>
                            <label className="block text-sm font-bold text-slate-700 dark:text-slate-200">
                                Precio
                            </label>
                            <input
                                type="number"
                                min="1"
                                step="0.01"
                                value={price}
                                disabled={!publication.can_update || busy}
                                onChange={(event) => setPrice(event.target.value)}
                                className="mt-2 w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-lg font-bold dark:border-neutral-700 dark:bg-neutral-950"
                            />
                        </div>

                        {publication.has_variations && (
                            <button
                                type="button"
                                disabled={!publication.can_update || busy}
                                onClick={savePrice}
                                className="rounded-2xl bg-indigo-600 px-6 py-3 font-bold text-white hover:bg-indigo-700 disabled:opacity-50"
                            >
                                {busyAction === 'price'
                                    ? 'Guardando…'
                                    : 'Guardar precio'}
                            </button>
                        )}
                    </div>
                </section>

                {!publication.has_variations ? (
                    <section className="rounded-3xl border border-emerald-200 bg-white p-5 shadow-sm dark:border-emerald-900 dark:bg-neutral-900">
                        <div className="grid gap-5 md:grid-cols-2">
                            <div>
                                <label className="block text-sm font-bold text-slate-700 dark:text-slate-200">
                                    Stock contado
                                </label>
                                <input
                                    type="number"
                                    min="0"
                                    value={stock}
                                    disabled={!publication.can_update_stock || busy}
                                    onChange={(event) => setStock(event.target.value)}
                                    className="mt-2 w-full rounded-2xl border border-slate-300 bg-white px-4 py-4 text-3xl font-black dark:border-neutral-700 dark:bg-neutral-950"
                                    autoFocus
                                />

                                {publication.shared && publication.is_default_account && (
                                    <p className="mt-2 text-sm font-semibold text-emerald-600 dark:text-emerald-300">
                                        Al guardar se actualizarán {publication.connected_members} publicaciones conectadas.
                                    </p>
                                )}

                                {publication.shared && !publication.is_default_account && (
                                    <p className="mt-2 text-sm font-semibold text-indigo-600 dark:text-indigo-300">
                                        La cuenta 1 controla automáticamente este stock.
                                    </p>
                                )}
                            </div>

                            <div className="flex items-end">
                                <button
                                    type="button"
                                    disabled={!publication.can_update || busy}
                                    onClick={saveSimple}
                                    className="w-full rounded-2xl bg-emerald-600 px-6 py-4 text-lg font-black text-white hover:bg-emerald-700 disabled:opacity-50"
                                >
                                    {busyAction === 'simple'
                                        ? 'Guardando cambios…'
                                        : 'Guardar stock y precio'}
                                </button>
                            </div>
                        </div>
                    </section>
                ) : (
                    <section className="space-y-4">
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <h2 className="text-xl font-black text-slate-900 dark:text-white">
                                    Variantes
                                </h2>
                                <p className="text-sm text-slate-500 dark:text-slate-400">
                                    {publication.variations_count} variantes · {editableVariationCount} editables
                                </p>
                            </div>
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            {(publication.variations ?? []).map((variation) => {
                                const action = `variation-${variation.id}`

                                return (
                                    <article
                                        key={variation.id}
                                        className="rounded-3xl border border-violet-200 bg-white p-5 shadow-sm dark:border-violet-900 dark:bg-neutral-900"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <h3 className="font-black text-slate-900 dark:text-white">
                                                    {variation.label}
                                                </h3>
                                                <p className="mt-1 text-xs text-slate-500">
                                                    SKU: {variation.sku || '—'}
                                                </p>
                                            </div>

                                            {variation.shared && (
                                                <span className={`rounded-full px-2 py-1 text-[10px] font-black ${
                                                    variation.shared_role === 'master'
                                                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                                                        : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300'
                                                }`}>
                                                    {variation.shared_role === 'master'
                                                        ? 'Maestra'
                                                        : 'Espejo'}
                                                </span>
                                            )}
                                        </div>

                                        <label className="mt-5 block text-sm font-bold text-slate-700 dark:text-slate-200">
                                            Stock contado
                                        </label>
                                        <input
                                            type="number"
                                            min="0"
                                            value={
                                                variationStocks[String(variation.id)]
                                                ?? variation.stock
                                            }
                                            disabled={!variation.can_update_stock || busy}
                                            onChange={(event) => setVariationStocks((current) => ({
                                                ...current,
                                                [String(variation.id)]: event.target.value,
                                            }))}
                                            className="mt-2 w-full rounded-2xl border border-slate-300 bg-white px-4 py-4 text-2xl font-black dark:border-neutral-700 dark:bg-neutral-950"
                                        />

                                        <button
                                            type="button"
                                            disabled={!variation.can_update_stock || busy}
                                            onClick={() => saveVariation(variation)}
                                            className="mt-3 w-full rounded-2xl bg-violet-600 px-5 py-3 font-black text-white hover:bg-violet-700 disabled:opacity-50"
                                        >
                                            {busyAction === action
                                                ? 'Guardando…'
                                                : 'Guardar stock de esta variante'}
                                        </button>

                                        {variation.shared && variation.shared_role === 'master' && (
                                            <p className="mt-2 text-xs font-semibold text-emerald-600 dark:text-emerald-300">
                                                Actualizará {variation.connected_members} publicaciones conectadas.
                                            </p>
                                        )}

                                        {variation.shared && variation.shared_role === 'mirror' && (
                                            <p className="mt-2 text-xs font-semibold text-indigo-600 dark:text-indigo-300">
                                                La cuenta 1 controla esta variante.
                                            </p>
                                        )}

                                        {variation.last_error && (
                                            <p className="mt-2 text-xs font-semibold text-rose-600 dark:text-rose-300">
                                                {variation.last_error}
                                            </p>
                                        )}
                                    </article>
                                )
                            })}
                        </div>
                    </section>
                )}
            </div>
        </AppShell>
    )
}
