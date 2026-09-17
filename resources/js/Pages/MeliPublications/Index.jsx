import AppShell from '@/Components/layout/AppShell'
import { router } from '@inertiajs/react'
import { useEffect, useMemo, useState } from 'react'

const FILTERS = [
    ['all', 'Todas'],
    ['active', 'Activas'],
    ['out_of_stock', 'Agotadas'],
    ['paused', 'Pausadas'],
]

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
        const validation = body?.errors ? Object.values(body.errors).flat().join('\n') : ''
        throw new Error(validation || body?.message || `Error HTTP ${response.status}`)
    }

    return body
}

function badge(status) {
    const classes = {
        active: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        paused: 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
        under_review: 'bg-orange-100 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300',
        blocked: 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        inactive: 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        suspended: 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        closed: 'bg-slate-200 text-slate-700 dark:bg-neutral-700 dark:text-slate-200',
    }

    return classes[status] ?? 'bg-slate-100 text-slate-700 dark:bg-neutral-800 dark:text-slate-300'
}

function Pager({ links }) {
    return (
        <div className="flex flex-wrap justify-center gap-2">
            {links.map((link, index) => (
                <button
                    key={`${link.label}-${index}`}
                    type="button"
                    disabled={!link.url}
                    onClick={() => link.url && router.visit(link.url, { preserveScroll: true })}
                    className={`rounded-xl border px-3 py-2 text-sm ${
                        link.active
                            ? 'border-indigo-600 bg-indigo-600 text-white'
                            : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200'
                    } disabled:cursor-not-allowed disabled:opacity-40`}
                    dangerouslySetInnerHTML={{ __html: link.label }}
                />
            ))}
        </div>
    )
}

function PublicationRow({ publication, onChanged, onDeleted }) {
    const [price, setPrice] = useState(publication.price ?? '')
    const [stock, setStock] = useState(publication.stock ?? 0)
    const [variationStocks, setVariationStocks] = useState({})
    const [expanded, setExpanded] = useState(false)
    const [busy, setBusy] = useState(false)

    useEffect(() => {
        setPrice(publication.price ?? '')
        setStock(publication.stock ?? 0)
        setVariationStocks(
            Object.fromEntries((publication.variations ?? []).map((variation) => [variation.id, variation.stock])),
        )
    }, [publication.id, publication.price, publication.stock, publication.variations])

    const execute = async (callback) => {
        setBusy(true)
        try {
            const result = await callback()
            if (result?.message) window.alert(result.message)
            if (result?.publication) onChanged(result.publication)
        } catch (error) {
            window.alert(error.message)
        } finally {
            setBusy(false)
        }
    }

    const save = () =>
        execute(() =>
            api(`/meli/publicaciones/${publication.id}`, {
                method: 'PUT',
                body: JSON.stringify({
                    price: price === '' ? null : Number(price),
                    stock: publication.can_update_stock && stock !== '' ? Number(stock) : null,
                }),
            }),
        )

    const saveVariation = (variation) =>
        execute(() =>
            api(`/meli/publicaciones/${publication.id}`, {
                method: 'PUT',
                body: JSON.stringify({
                    stock: Number(variationStocks[variation.id] ?? 0),
                    variation_id: String(variation.id),
                }),
            }),
        )

    const refresh = () =>
        execute(() =>
            api(`/meli/publicaciones/${publication.id}/refresh`, {
                method: 'POST',
                body: '{}',
            }),
        )

    const changeStatus = (status) =>
        execute(() =>
            api(`/meli/publicaciones/${publication.id}/status`, {
                method: 'PATCH',
                body: JSON.stringify({ status }),
            }),
        )

    const destroy = async () => {
        if (!publication.can_delete) {
            window.alert('La eliminación permanente está deshabilitada para la cuenta principal.')
            return
        }

        if (!window.confirm(`¿Eliminar permanentemente ${publication.mlm} de la cuenta secundaria?`)) return

        setBusy(true)
        try {
            const result = await api(`/meli/publicaciones/${publication.id}`, {
                method: 'DELETE',
                body: '{}',
            })
            window.alert(result.message)
            onDeleted(publication.id)
        } catch (error) {
            window.alert(error.message)
        } finally {
            setBusy(false)
        }
    }

    const sharedVariantCount = (publication.variations ?? []).filter((variation) => variation.shared).length

    return (
        <>
            <tr className="border-b border-slate-200 align-top dark:border-neutral-800">
                <td className="p-4">
                    <div className="flex min-w-[320px] gap-4">
                        <a
                            href={`/meli/publicaciones/${publication.id}/editar`}
                            target="_blank"
                            rel="noreferrer"
                            title="Abrir editor de stock y precio en otra pestaña"
                            className="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl border border-slate-200 bg-white transition hover:border-indigo-400 hover:ring-2 hover:ring-indigo-100 dark:border-neutral-700 dark:bg-neutral-800 dark:hover:border-indigo-500 dark:hover:ring-indigo-500/10"
                        >
                            {publication.thumbnail ? (
                                <img src={publication.thumbnail} alt="" className="h-full w-full object-contain" />
                            ) : (
                                <span className="text-xs text-slate-400">Sin imagen</span>
                            )}
                        </a>
                        <div className="min-w-0">
                            <a
                                href={`/meli/publicaciones/${publication.id}/editar`}
                                target="_blank"
                                rel="noreferrer"
                                title="Abrir editor de stock y precio en otra pestaña"
                                className="block rounded-lg font-semibold text-slate-900 transition hover:text-indigo-600 hover:underline dark:text-white dark:hover:text-indigo-300"
                            >
                                {publication.title}
                            </a>
                            <div className="mt-1 space-y-0.5 text-xs text-slate-500 dark:text-slate-400">
                                <p>SKU: {publication.sku || '—'}</p>
                                <p>MLM: {publication.mlm}</p>
                                <p>{publication.account_name}</p>
                            </div>
                            {(publication.shared || sharedVariantCount > 0) && (
                                <div className="mt-2 flex flex-wrap gap-1.5">
                                    <span className={`rounded-full px-2 py-1 text-[11px] font-bold ${publication.is_default_account ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300'}`}>
                                        {publication.is_default_account ? 'Cuenta 1 · stock maestro' : 'Cuenta 2 · stock espejo'}
                                    </span>
                                    {publication.connected_members > 0 && (
                                        <span className="rounded-full bg-amber-100 px-2 py-1 text-[11px] font-bold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                                            {publication.connected_members} publicaciones conectadas
                                        </span>
                                    )}
                                    {sharedVariantCount > 0 && (
                                        <span className="rounded-full bg-violet-100 px-2 py-1 text-[11px] font-bold text-violet-700 dark:bg-violet-500/10 dark:text-violet-300">
                                            {sharedVariantCount} variantes conectadas
                                        </span>
                                    )}
                                </div>
                            )}
                            <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                                <a
                                    href={`/meli/publicaciones/${publication.id}/editar`}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex items-center gap-1 text-xs font-bold text-violet-600 hover:underline dark:text-violet-300"
                                >
                                    Abrir editor
                                    <span aria-hidden="true">↗</span>
                                </a>
                                {publication.permalink && (
                                    <a href={publication.permalink} target="_blank" rel="noreferrer" className="inline-block text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-300">
                                        Ver en Mercado Libre
                                    </a>
                                )}
                            </div>
                        </div>
                    </div>
                </td>

                <td className="p-4">
                    <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${badge(publication.status)}`}>
                        {publication.status_label}
                    </span>
                    {publication.sub_status && <p className="mt-2 max-w-[180px] text-xs text-slate-500 dark:text-slate-400">{publication.sub_status}</p>}
                    {publication.block_reason && <p className="mt-2 max-w-[220px] text-xs font-medium text-rose-600 dark:text-rose-300">{publication.block_reason}</p>}
                </td>

                <td className="p-4">
                    <div className="w-40 space-y-2">
                        <label className="block text-xs font-semibold text-slate-500">Stock</label>
                        {publication.has_variations ? (
                            <>
                                <button
                                    type="button"
                                    onClick={() => setExpanded((value) => !value)}
                                    className="w-full rounded-xl border border-violet-300 px-3 py-2 text-xs font-bold text-violet-700 hover:bg-violet-50 dark:border-violet-800 dark:text-violet-300 dark:hover:bg-violet-500/10"
                                >
                                    {expanded ? 'Ocultar variantes' : `Ver ${publication.variations_count} variantes`}
                                </button>
                                <span className="block text-[11px] text-amber-600 dark:text-amber-300">El stock se controla por cada variante.</span>
                            </>
                        ) : (
                            <>
                                <input type="number" min="0" value={stock} disabled={!publication.can_update_stock || busy} onChange={(event) => setStock(event.target.value)} className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                                {publication.stock <= 0 && <span className="text-xs font-bold text-rose-600 dark:text-rose-300">AGOTADA</span>}
                                {publication.shared && !publication.is_default_account && (
                                    <span className="block text-[11px] font-medium text-indigo-600 dark:text-indigo-300">Controlado automáticamente por la cuenta 1.</span>
                                )}
                                {publication.shared && publication.is_default_account && (
                                    <span className="block text-[11px] font-medium text-emerald-600 dark:text-emerald-300">Al guardar se actualizarán las dos cuentas.</span>
                                )}
                            </>
                        )}
                        {publication.logistic_type === 'fulfillment' && <span className="block text-[11px] text-sky-600 dark:text-sky-300">Stock FULL: lo administra Mercado Libre.</span>}
                    </div>
                </td>

                <td className="p-4">
                    <div className="w-36 space-y-2">
                        <label className="block text-xs font-semibold text-slate-500">Precio</label>
                        <input type="number" min="1" step="0.01" value={price} disabled={!publication.can_update || busy} onChange={(event) => setPrice(event.target.value)} className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                        <button type="button" disabled={!publication.can_update || busy} onClick={save} className="w-full rounded-xl bg-indigo-600 px-3 py-2 text-xs font-bold text-white hover:bg-indigo-700 disabled:opacity-40">Guardar</button>
                    </div>
                </td>

                <td className="p-4 text-sm">
                    <p className="font-bold text-slate-900 dark:text-white">{publication.sold_quantity}</p>
                    <p className="text-xs text-slate-500">ventas</p>
                    {publication.visits !== null && <p className="mt-2 text-xs">{publication.visits} visitas</p>}
                    {publication.conversion !== null && <p className="text-xs">{publication.conversion}% conversión</p>}
                </td>

                <td className="p-4">
                    {publication.health !== null ? (
                        <div className="w-28">
                            <div className="mb-1 flex justify-between text-xs"><span>Calidad</span><strong>{publication.health}%</strong></div>
                            <div className="h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-neutral-700"><div className="h-full rounded-full bg-emerald-500" style={{ width: `${Math.max(0, Math.min(100, publication.health))}%` }} /></div>
                        </div>
                    ) : (
                        <span className="text-xs text-slate-400">Sin dato</span>
                    )}
                </td>

                <td className="p-4">
                    <div className="flex min-w-[150px] flex-col gap-2">
                        <button type="button" disabled={busy} onClick={refresh} className="rounded-xl border border-slate-300 px-3 py-2 text-xs font-semibold hover:bg-slate-50 dark:border-neutral-700 dark:hover:bg-neutral-800">Sincronizar</button>
                        {publication.status === 'active' ? (
                            <button type="button" disabled={busy} onClick={() => changeStatus('paused')} className="rounded-xl border border-amber-300 px-3 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-300">Pausar</button>
                        ) : publication.status === 'paused' ? (
                            <button type="button" disabled={busy} onClick={() => changeStatus('active')} className="rounded-xl border border-emerald-300 px-3 py-2 text-xs font-semibold text-emerald-700 hover:bg-emerald-50 dark:border-emerald-700 dark:text-emerald-300">Reactivar</button>
                        ) : null}
                        {publication.can_delete && <button type="button" disabled={busy} onClick={destroy} className="rounded-xl border border-rose-300 px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50 dark:border-rose-800 dark:text-rose-300">Eliminar</button>}
                    </div>
                    <p className="mt-2 text-[11px] text-slate-400">{publication.last_sync_at || 'Sin sincronizar'}</p>
                </td>
            </tr>

            {publication.has_variations && expanded && (
                <tr className="border-b border-slate-200 bg-violet-50/50 dark:border-neutral-800 dark:bg-violet-500/5">
                    <td colSpan={7} className="p-4">
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {(publication.variations ?? []).map((variation) => (
                                <article key={variation.id} className="rounded-2xl border border-violet-200 bg-white p-4 dark:border-violet-900 dark:bg-neutral-950">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="text-sm font-bold text-slate-900 dark:text-white">{variation.label}</p>
                                            <p className="mt-1 text-xs text-slate-500">Variation ID: {variation.id}</p>
                                            <p className="text-xs text-slate-500">SKU: {variation.sku || '—'}</p>
                                        </div>
                                        {variation.shared && (
                                            <span className={`rounded-full px-2 py-1 text-[10px] font-bold ${variation.shared_role === 'master' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300'}`}>
                                                {variation.shared_role === 'master' ? 'Maestra' : 'Espejo'}
                                            </span>
                                        )}
                                    </div>

                                    <div className="mt-4 grid grid-cols-[1fr_auto] gap-2">
                                        <input
                                            type="number"
                                            min="0"
                                            value={variationStocks[variation.id] ?? variation.stock}
                                            disabled={!variation.can_update_stock || busy}
                                            onChange={(event) => setVariationStocks((current) => ({ ...current, [variation.id]: event.target.value }))}
                                            className="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                                        />
                                        <button
                                            type="button"
                                            disabled={!variation.can_update_stock || busy}
                                            onClick={() => saveVariation(variation)}
                                            className="rounded-xl bg-violet-600 px-3 py-2 text-xs font-bold text-white hover:bg-violet-700 disabled:opacity-40"
                                        >
                                            Guardar stock
                                        </button>
                                    </div>

                                    {variation.shared && variation.shared_role === 'master' && (
                                        <p className="mt-2 text-[11px] font-medium text-emerald-600 dark:text-emerald-300">Actualizará {variation.connected_members} miembros conectados.</p>
                                    )}
                                    {variation.shared && variation.shared_role === 'mirror' && (
                                        <p className="mt-2 text-[11px] font-medium text-indigo-600 dark:text-indigo-300">La cuenta 1 controla esta variante.</p>
                                    )}
                                    {variation.last_error && <p className="mt-2 text-[11px] text-rose-600 dark:text-rose-300">{variation.last_error}</p>}
                                </article>
                            ))}
                        </div>
                    </td>
                </tr>
            )}
        </>
    )
}

export default function Index({ accounts, selectedAccountId, publications, stats, filters, syncState, sharedStats }) {
    const [rows, setRows] = useState(publications.data)
    const [search, setSearch] = useState(filters.search ?? '')

    useEffect(() => setRows(publications.data), [publications.data])

    const syncRunning = ['queued', 'running'].includes(syncState?.status)

    useEffect(() => {
        if (!syncRunning) return undefined

        const timer = window.setInterval(() => {
            router.reload({
                only: ['syncState', 'stats', 'publications'],
                preserveScroll: true,
                preserveState: true,
            })
        }, 4000)

        return () => window.clearInterval(timer)
    }, [syncRunning])

    const selectedAccount = useMemo(() => accounts.find((account) => Number(account.id) === Number(selectedAccountId)), [accounts, selectedAccountId])

    const navigate = (changes = {}) => {
        router.get('/meli/publicaciones', { ...filters, account_id: selectedAccountId, search, ...changes }, { preserveState: true, preserveScroll: true, replace: true })
    }

    return (
        <AppShell title="Publicaciones Mercado Libre">
            <div className="space-y-6">
                <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div>
                            <p className="text-xs font-bold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-300">Centro de publicaciones</p>
                            <h1 className="mt-2 text-3xl font-black text-slate-900 dark:text-white">Publicaciones Mercado Libre</h1>
                            <p className="mt-2 text-sm text-slate-500 dark:text-slate-400">Todas las publicaciones de la cuenta seleccionada, excepto las bloqueadas y las que están en revisión.</p>
                        </div>
                        <div className="w-full space-y-3 lg:w-[34rem]">
                            <div>
                                <label className="mb-2 block text-xs font-bold uppercase tracking-wide text-slate-500">Cuenta</label>
                                <select value={selectedAccountId} onChange={(event) => navigate({ account_id: Number(event.target.value), page: 1 })} className="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 dark:border-neutral-700 dark:bg-neutral-950">
                                    {accounts.map((account) => <option key={account.id} value={account.id}>{account.nickname} {account.is_default ? '(Principal)' : '(Secundaria)'}</option>)}
                                </select>
                            </div>
                            <div className="grid gap-2 sm:grid-cols-2">
                                <button
                                    type="button"
                                    disabled={syncRunning || !selectedAccountId}
                                    onClick={() => navigate({ sync_all: 1, page: 1 })}
                                    className="rounded-2xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {syncRunning ? 'Sincronizando todas…' : 'Sincronizar todas'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => router.reload({ preserveScroll: true })}
                                    className="rounded-2xl border border-slate-300 px-4 py-3 text-sm font-bold hover:bg-slate-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                >
                                    Actualizar vista
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                {syncState && (
                    <section className={`rounded-2xl border p-4 text-sm ${syncState.status === 'failed' ? 'border-rose-300 bg-rose-50 text-rose-800 dark:border-rose-800 dark:bg-rose-500/10 dark:text-rose-200' : syncState.status === 'finished' ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200' : 'border-sky-300 bg-sky-50 text-sky-800 dark:border-sky-800 dark:bg-sky-500/10 dark:text-sky-200'}`}>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <strong>{syncState.message || 'Sincronización de publicaciones'}</strong>
                            {syncRunning && <span className="rounded-full bg-white/70 px-3 py-1 text-xs font-bold dark:bg-black/20">En proceso</span>}
                        </div>
                        {(syncState.discovered !== undefined || syncState.processed !== undefined) && (
                            <p className="mt-2 text-xs opacity-80">
                                Encontradas: {syncState.discovered ?? 0} · Procesadas: {syncState.processed ?? 0} · Guardadas: {syncState.saved ?? 0} · Errores: {syncState.errors ?? 0}
                            </p>
                        )}
                    </section>
                )}

                {sharedStats?.groups > 0 && (
                    <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <div className="rounded-2xl border border-emerald-300 bg-emerald-50 p-4 dark:border-emerald-900 dark:bg-emerald-500/10">
                            <p className="text-xs font-bold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Grupos compartidos</p>
                            <p className="mt-2 text-2xl font-black">{sharedStats.groups}</p>
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                            <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Miembros conectados</p>
                            <p className="mt-2 text-2xl font-black">{sharedStats.members}</p>
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                            <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Cuenta 1</p>
                            <p className="mt-2 text-2xl font-black">{sharedStats.master_members}</p>
                        </div>
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                            <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Cuenta 2</p>
                            <p className="mt-2 text-2xl font-black">{sharedStats.mirror_members}</p>
                        </div>
                        <div className={`rounded-2xl border p-4 ${sharedStats.errors > 0 ? 'border-rose-300 bg-rose-50 dark:border-rose-900 dark:bg-rose-500/10' : 'border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900'}`}>
                            <p className="text-xs font-bold uppercase tracking-wide text-slate-500">Errores de envío</p>
                            <p className="mt-2 text-2xl font-black">{sharedStats.errors}</p>
                        </div>
                    </section>
                )}

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {FILTERS.map(([key, label]) => (
                        <button key={key} type="button" onClick={() => navigate({ filter: key, page: 1 })} className={`rounded-2xl border p-4 text-left shadow-sm transition ${filters.filter === key ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-500/10' : 'border-slate-200 bg-white hover:border-slate-300 dark:border-neutral-800 dark:bg-neutral-900'}`}>
                            <p className="text-xs font-bold uppercase tracking-wide text-slate-500">{label}</p>
                            <p className="mt-2 text-2xl font-black">{stats[key] ?? 0}</p>
                        </button>
                    ))}
                </section>

                <section className="rounded-3xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <form className="grid gap-3 lg:grid-cols-[1fr_220px_160px_auto]" onSubmit={(event) => { event.preventDefault(); navigate({ search, page: 1 }) }}>
                        <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar por título, SKU o MLM" className="rounded-2xl border border-slate-300 bg-white px-4 py-3 dark:border-neutral-700 dark:bg-neutral-950" />
                        <select value={filters.sort} onChange={(event) => navigate({ sort: event.target.value, page: 1 })} className="rounded-2xl border border-slate-300 bg-white px-4 py-3 dark:border-neutral-700 dark:bg-neutral-950">
                            <option value="updated">Actualización</option><option value="sales">Ventas</option><option value="stock">Stock</option><option value="price">Precio</option><option value="quality">Calidad</option><option value="title">Título</option>
                        </select>
                        <select value={filters.direction} onChange={(event) => navigate({ direction: event.target.value, page: 1 })} className="rounded-2xl border border-slate-300 bg-white px-4 py-3 dark:border-neutral-700 dark:bg-neutral-950"><option value="desc">Mayor a menor</option><option value="asc">Menor a mayor</option></select>
                        <button type="submit" className="rounded-2xl bg-indigo-600 px-5 py-3 font-bold text-white hover:bg-indigo-700">Buscar</button>
                    </form>
                    <p className="mt-3 text-xs text-slate-500">Cuenta seleccionada: <strong>{selectedAccount?.nickname ?? 'Sin cuenta'}</strong></p>
                </section>

                <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-left">
                            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-neutral-950"><tr><th className="p-4">Publicación</th><th className="p-4">Estado</th><th className="p-4">Stock</th><th className="p-4">Precio</th><th className="p-4">Ventas</th><th className="p-4">Calidad</th><th className="p-4">Acciones</th></tr></thead>
                            <tbody>{rows.map((publication) => <PublicationRow key={publication.id} publication={publication} onChanged={(updated) => setRows((current) => current.map((row) => row.id === updated.id ? updated : row))} onDeleted={(id) => setRows((current) => current.filter((row) => row.id !== id))} />)}</tbody>
                        </table>
                    </div>
                    {rows.length === 0 && <div className="p-12 text-center text-sm text-slate-500">No se encontraron publicaciones con estos filtros.</div>}
                    <div className="border-t border-slate-200 p-4 dark:border-neutral-800"><Pager links={publications.links} /></div>
                </section>
            </div>
        </AppShell>
    )
}
