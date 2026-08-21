import AppShell from '@/Components/layout/AppShell'
import { Head, router, useForm } from '@inertiajs/react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

function csrfHeader() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
}

function mlItemPublicUrl(itemId, siteId) {
    const id = String(itemId || '').trim()
    if (!id) return null
    const site = String(siteId || 'MLM').toUpperCase()
    const hostBySite = {
        MLM: 'https://articulo.mercadolibre.com.mx',
        MLA: 'https://articulo.mercadolibre.com.ar',
        MLB: 'https://produto.mercadolivre.com.br',
        MLC: 'https://articulo.mercadolibre.cl',
        MCO: 'https://articulo.mercadolibre.com.co',
        MLU: 'https://articulo.mercadolibre.com.uy',
    }
    const base = hostBySite[site] || hostBySite.MLM
    return `${base}/${id}`
}

function formatWhen(iso) {
    if (!iso) return ''
    try {
        const d = new Date(iso)
        if (Number.isNaN(d.getTime())) return String(iso)
        return d.toLocaleString()
    } catch {
        return String(iso)
    }
}

function formatMoney(amount, currencyId) {
    if (amount == null || Number.isNaN(Number(amount))) return '—'
    const cur = String(currencyId || 'MXN').trim() || 'MXN'
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency: cur }).format(Number(amount))
    } catch {
        return `${cur} ${amount}`
    }
}

export default function Index({
    flows = [],
    accounts = [],
    selectedAccountId = null,
    selectedAccountLinked = false,
    sellerMaxLength = 350,
    selectedFlowId = null,
}) {
    const [activeFlowId, setActiveFlowId] = useState(selectedFlowId)
    const [messages, setMessages] = useState([])
    const [loadState, setLoadState] = useState('idle')
    const [loadError, setLoadError] = useState('')
    const [saleDetails, setSaleDetails] = useState(null)
    const [saleLoadState, setSaleLoadState] = useState('idle')
    const [saleError, setSaleError] = useState('')
    const bottomRef = useRef(null)

    const replyForm = useForm({ text: '' })

    useEffect(() => {
        setActiveFlowId(selectedFlowId)
    }, [selectedFlowId])

    const activeFlow = useMemo(
        () => flows.find((f) => f.id === activeFlowId) || null,
        [flows, activeFlowId]
    )

    const loadMessages = useCallback(async () => {
        if (!activeFlowId || !selectedAccountLinked) {
            setMessages([])
            return
        }
        setLoadState('loading')
        setLoadError('')
        try {
            const res = await fetch(`/meli/mensajeria/flows/${activeFlowId}/messages?mark_as_read=false`, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfHeader(),
                },
                credentials: 'same-origin',
            })
            const json = await res.json().catch(() => ({}))
            if (!res.ok || !json.ok) {
                throw new Error(json.message || 'No se pudieron cargar los mensajes.')
            }
            setMessages(Array.isArray(json.messages) ? json.messages : [])
            setLoadState('ok')
        } catch (e) {
            setMessages([])
            setLoadError(e?.message || 'Error al cargar.')
            setLoadState('error')
        }
    }, [activeFlowId, selectedAccountLinked])

    useEffect(() => {
        loadMessages()
    }, [loadMessages])

    const loadSaleDetails = useCallback(async () => {
        if (!activeFlowId || !selectedAccountLinked) {
            setSaleDetails(null)
            return
        }
        setSaleLoadState('loading')
        setSaleError('')
        try {
            const res = await fetch(`/meli/mensajeria/flows/${activeFlowId}/venta`, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfHeader(),
                },
                credentials: 'same-origin',
            })
            const json = await res.json().catch(() => ({}))
            if (!res.ok || !json.ok) {
                throw new Error(json.message || 'No se pudo cargar el detalle de venta.')
            }
            setSaleDetails(json)
            setSaleLoadState('ok')
        } catch (e) {
            setSaleDetails(null)
            setSaleError(e?.message || 'Error al cargar venta.')
            setSaleLoadState('error')
        }
    }, [activeFlowId, selectedAccountLinked])

    useEffect(() => {
        loadSaleDetails()
    }, [loadSaleDetails])

    useEffect(() => {
        if (loadState === 'ok' && bottomRef.current) {
            bottomRef.current.scrollIntoView({ behavior: 'smooth' })
        }
    }, [messages, loadState])

    const selectFlow = (id) => {
        setActiveFlowId(id)
        router.get(
            '/meli/mensajeria',
            { account_id: selectedAccountId, flow: id },
            { replace: true, preserveState: true, preserveScroll: true }
        )
    }

    const selectAccount = (accountId) => {
        router.get(
            '/meli/mensajeria',
            { account_id: accountId },
            { replace: true, preserveState: false, preserveScroll: true }
        )
    }

    const submitReply = (e) => {
        e.preventDefault()
        if (!activeFlowId || !replyForm.data.text.trim()) return

        replyForm.post(`/meli/mensajeria/flows/${activeFlowId}/reply`, {
            preserveScroll: true,
            onSuccess: () => {
                replyForm.reset('text')
                loadMessages()
            },
        })
    }

    const remaining = sellerMaxLength - (replyForm.data.text?.length || 0)

    return (
        <>
            <Head title="Mensajería Mercado Libre" />

            <AppShell title="Mensajería posventa (MeLi)">
                <div className="w-full max-w-none space-y-4 text-slate-900 dark:text-slate-100">
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900 dark:shadow-none">
                        <h1 className="text-xl font-semibold">Mensajería posventa</h1>
                        <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">
                            Respondé conversaciones de posventa sin abrir Mercado Libre. Solo aparecen chats que el
                            sistema ya registró (por ejemplo cuando el comprador escribió y entró el webhook del
                            menú automático). Los mensajes se envían con la misma API que el bot posventa.
                        </p>

                        <div className="mt-4 max-w-md">
                            <label
                                htmlFor="meli-account"
                                className="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400"
                            >
                                Cuenta Mercado Libre
                            </label>
                            <select
                                id="meli-account"
                                value={selectedAccountId || ''}
                                onChange={(e) => selectAccount(Number(e.target.value))}
                                disabled={accounts.length === 0}
                                className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                            >
                                {accounts.length === 0 ? (
                                    <option value="">Sin cuentas vinculadas</option>
                                ) : (
                                    accounts.map((account) => (
                                        <option key={account.id} value={account.id}>
                                            {account.nickname}
                                            {account.is_default ? ' — Principal' : ''}
                                            {' · '}
                                            {account.meli_user_id}
                                        </option>
                                    ))
                                )}
                            </select>
                        </div>

                        {!selectedAccountLinked && (
                            <p className="mt-3 text-sm font-medium text-amber-800 dark:text-amber-200">
                                La cuenta seleccionada no tiene token disponible. Refrescá su token o volvé a vincularla.
                            </p>
                        )}
                    </div>

                    <div className="flex min-h-[28rem] flex-col gap-4 lg:grid lg:grid-cols-[minmax(0,16rem)_minmax(0,1fr)] lg:items-stretch">
                        <div className="rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900 dark:shadow-none lg:min-w-0">
                            <div className="border-b border-slate-200 px-3 py-2 text-sm font-semibold dark:border-neutral-800">
                                Conversaciones ({flows.length})
                            </div>
                            <div className="max-h-[70vh] overflow-y-auto p-2">
                                {flows.length === 0 ? (
                                    <p className="px-2 py-4 text-sm text-slate-600 dark:text-slate-400">
                                        Aún no hay conversaciones en el sistema. Cuando un comprador escriba por
                                        posventa y se procese el aviso de Mercado Libre, aparecerá aquí.
                                    </p>
                                ) : (
                                    <ul className="space-y-1">
                                        {flows.map((f) => {
                                            const active = f.id === activeFlowId
                                            return (
                                                <li key={f.id}>
                                                    <button
                                                        type="button"
                                                        onClick={() => selectFlow(f.id)}
                                                        className={`w-full rounded-xl px-3 py-2 text-left text-sm transition ${
                                                            active
                                                                ? 'bg-indigo-50 font-medium text-indigo-800 dark:bg-indigo-500/15 dark:text-indigo-200'
                                                                : 'text-slate-700 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-neutral-800'
                                                        }`}
                                                    >
                                                        <div className="truncate">Orden {f.order_id}</div>
                                                        {f.sku && (
                                                            <div className="truncate text-xs text-slate-500 dark:text-slate-400">
                                                                SKU {f.sku}
                                                            </div>
                                                        )}
                                                        {f.requires_human && (
                                                            <span className="mt-1 inline-block rounded-md bg-rose-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-rose-800 dark:bg-rose-500/20 dark:text-rose-200">
                                                                Asesor
                                                            </span>
                                                        )}
                                                    </button>
                                                </li>
                                            )
                                        })}
                                    </ul>
                                )}
                            </div>
                        </div>

                        <div className="flex min-h-[28rem] min-w-0 flex-col gap-4 xl:flex-row xl:items-stretch">
                            <div className="order-2 flex min-h-[28rem] min-w-0 flex-1 flex-col rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900 dark:shadow-none xl:order-1">
                            {!activeFlowId ? (
                                <div className="flex flex-1 items-center justify-center p-6 text-sm text-slate-500 dark:text-slate-400">
                                    Elegí una conversación de la lista.
                                </div>
                            ) : (
                                <>
                                    <div className="border-b border-slate-200 px-4 py-3 dark:border-neutral-800">
                                        <p className="text-sm font-semibold">Orden {activeFlow?.order_id}</p>
                                        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                            Pack: {activeFlow?.pack_id || activeFlow?.order_id}
                                            {activeFlow?.item_id &&
                                                mlItemPublicUrl(activeFlow.item_id, activeFlow.site_id) && (
                                                    <>
                                                        {' '}
                                                        · Ítem{' '}
                                                        <a
                                                            href={mlItemPublicUrl(
                                                                activeFlow.item_id,
                                                                activeFlow.site_id
                                                            )}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="text-indigo-600 underline dark:text-indigo-300"
                                                        >
                                                            {activeFlow.item_id}
                                                        </a>
                                                    </>
                                                )}
                                        </p>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                loadMessages()
                                                loadSaleDetails()
                                            }}
                                            disabled={loadState === 'loading'}
                                            className="mt-2 text-xs font-medium text-indigo-600 hover:underline disabled:opacity-50 dark:text-indigo-300"
                                        >
                                            {loadState === 'loading' ? 'Actualizando…' : 'Actualizar mensajes'}
                                        </button>
                                    </div>

                                    <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
                                        {loadError && (
                                            <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100">
                                                {loadError}
                                            </div>
                                        )}
                                        {loadState === 'loading' && !loadError && (
                                            <p className="text-sm text-slate-500 dark:text-slate-400">Cargando…</p>
                                        )}
                                        {messages.map((m) => {
                                            const mine = m.role === 'seller'
                                            return (
                                                <div
                                                    key={m.id || `${m.created}-${m.text}`}
                                                    className={`flex ${mine ? 'justify-end' : 'justify-start'}`}
                                                >
                                                    <div
                                                        className={`max-w-[85%] rounded-2xl px-3 py-2 text-sm shadow-sm ${
                                                            mine
                                                                ? 'bg-indigo-600 text-white'
                                                                : 'border border-slate-200 bg-slate-50 text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100'
                                                        }`}
                                                    >
                                                        <p className="whitespace-pre-wrap break-words">
                                                            {m.text || '(sin texto)'}
                                                        </p>
                                                        <p
                                                            className={`mt-1 text-[10px] ${
                                                                mine ? 'text-indigo-100' : 'text-slate-500 dark:text-slate-400'
                                                            }`}
                                                        >
                                                            {mine ? 'Vos' : 'Cliente'} · {formatWhen(m.created)}
                                                        </p>
                                                    </div>
                                                </div>
                                            )
                                        })}
                                        <div ref={bottomRef} />
                                    </div>

                                    <form
                                        onSubmit={submitReply}
                                        className="border-t border-slate-200 p-4 dark:border-neutral-800"
                                    >
                                        {replyForm.errors.text && (
                                            <p className="mb-2 text-sm text-red-600 dark:text-red-300">
                                                {replyForm.errors.text}
                                            </p>
                                        )}
                                        <label className="sr-only" htmlFor="meli-reply-text">
                                            Mensaje
                                        </label>
                                        <textarea
                                            id="meli-reply-text"
                                            value={replyForm.data.text}
                                            onChange={(e) => replyForm.setData('text', e.target.value)}
                                            rows={3}
                                            maxLength={sellerMaxLength}
                                            disabled={!selectedAccountLinked || replyForm.processing}
                                            placeholder="Escribí tu respuesta…"
                                            className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/30 disabled:opacity-60 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white dark:placeholder-slate-500"
                                        />
                                        <div className="mt-2 flex items-center justify-between gap-3">
                                            <span
                                                className={`text-xs ${
                                                    remaining < 0 ? 'text-red-600' : 'text-slate-500 dark:text-slate-400'
                                                }`}
                                            >
                                                {Math.max(0, remaining)} caracteres restantes (máx. {sellerMaxLength} en
                                                MeLi)
                                            </span>
                                            <button
                                                type="submit"
                                                disabled={
                                                    !selectedAccountLinked ||
                                                    replyForm.processing ||
                                                    !replyForm.data.text.trim()
                                                }
                                                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-50"
                                            >
                                                {replyForm.processing ? 'Enviando…' : 'Enviar'}
                                            </button>
                                        </div>
                                    </form>
                                </>
                            )}
                            </div>

                            {!activeFlowId ? null : (
                                <aside className="order-1 w-full shrink-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900 dark:shadow-none xl:order-2 xl:max-h-[min(70vh,calc(100vh-10rem))] xl:w-80 xl:max-w-sm xl:overflow-y-auto xl:self-stretch">
                                    <h2 className="text-sm font-semibold text-slate-900 dark:text-slate-100">
                                        Detalles de venta
                                    </h2>
                                    {saleLoadState === 'loading' && (
                                        <p className="mt-3 text-sm text-slate-500 dark:text-slate-400">Cargando…</p>
                                    )}
                                    {saleError && (
                                        <p className="mt-3 text-sm text-amber-800 dark:text-amber-200">{saleError}</p>
                                    )}
                                    {saleLoadState === 'ok' && saleDetails && (
                                        <dl className="mt-3 space-y-3 text-sm">
                                            <div>
                                                <dt className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                                    Cliente
                                                </dt>
                                                <dd className="mt-0.5 font-medium text-slate-900 dark:text-slate-100">
                                                    {saleDetails.buyer_name ||
                                                        `Comprador ${saleDetails.buyer_id || '—'}`}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                                    Orden MeLi
                                                </dt>
                                                <dd className="mt-0.5 text-slate-800 dark:text-slate-200">
                                                    {saleDetails.order_id}
                                                </dd>
                                            </div>
                                            {(saleDetails.lines || []).length === 0 ? (
                                                <p className="text-slate-600 dark:text-slate-400">
                                                    No hay líneas de producto en la orden.
                                                </p>
                                            ) : (
                                                (saleDetails.lines || []).map((line, idx) => {
                                                    const sku =
                                                        line.sku ||
                                                        (idx === 0 && activeFlow?.sku ? activeFlow.sku : null)
                                                    const pubUrl =
                                                        line.item_id &&
                                                        mlItemPublicUrl(line.item_id, activeFlow?.site_id)
                                                    const pubTitle = line.publication_title || ''
                                                    const prodTitle = line.product_title || ''
                                                    const sameTitle =
                                                        pubTitle &&
                                                        prodTitle &&
                                                        String(pubTitle).trim() === String(prodTitle).trim()
                                                    return (
                                                        <div
                                                            key={`${line.item_id || idx}-${idx}`}
                                                            className="border-t border-slate-100 pt-3 dark:border-neutral-800"
                                                        >
                                                            <dt className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                                                {sameTitle
                                                                    ? 'Publicación y producto'
                                                                    : 'Nombre de la publicación'}
                                                            </dt>
                                                            <dd className="mt-0.5 text-slate-900 dark:text-slate-100">
                                                                {pubUrl ? (
                                                                    <a
                                                                        href={pubUrl}
                                                                        target="_blank"
                                                                        rel="noreferrer"
                                                                        className="text-indigo-600 underline dark:text-indigo-300"
                                                                    >
                                                                        {line.publication_title || line.item_id}
                                                                    </a>
                                                                ) : (
                                                                    line.publication_title || '—'
                                                                )}
                                                            </dd>
                                                            {!sameTitle && (
                                                                <>
                                                                    <dt className="mt-2 text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                                                        Producto
                                                                    </dt>
                                                                    <dd className="mt-0.5 text-slate-800 dark:text-slate-200">
                                                                        {line.product_title || '—'}
                                                                    </dd>
                                                                </>
                                                            )}
                                                            <div className="mt-2 grid grid-cols-2 gap-2 text-xs">
                                                                <div>
                                                                    <span className="text-slate-500 dark:text-slate-400">
                                                                        SKU{' '}
                                                                    </span>
                                                                    <span className="font-medium text-slate-900 dark:text-slate-100">
                                                                        {sku || '—'}
                                                                    </span>
                                                                </div>
                                                                <div>
                                                                    <span className="text-slate-500 dark:text-slate-400">
                                                                        Precio unit.{' '}
                                                                    </span>
                                                                    <span className="font-medium text-slate-900 dark:text-slate-100">
                                                                        {formatMoney(
                                                                            line.unit_price,
                                                                            saleDetails.currency_id
                                                                        )}
                                                                    </span>
                                                                </div>
                                                                {line.quantity != null && line.quantity > 0 && (
                                                                    <div className="col-span-2">
                                                                        <span className="text-slate-500 dark:text-slate-400">
                                                                            Cantidad{' '}
                                                                        </span>
                                                                        <span className="font-medium text-slate-900 dark:text-slate-100">
                                                                            {line.quantity}
                                                                        </span>
                                                                    </div>
                                                                )}
                                                            </div>
                                                        </div>
                                                    )
                                                })
                                            )}
                                        </dl>
                                    )}
                                </aside>
                            )}
                        </div>
                    </div>
                </div>
            </AppShell>
        </>
    )
}
