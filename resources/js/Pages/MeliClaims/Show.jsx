import AppShell from '@/Components/layout/AppShell'
import { accountName, actionName, availableAction, claimStage, claimStatus, claimType, expectedResolution, historyAction, listData, playerRole, resolutionName } from '@/lib/meliClaimsPresentation'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'

const date = value => value ? new Date(value).toLocaleString('es-MX') : '—'
const money = (value, currency = 'MXN') => value == null ? '—' : new Intl.NumberFormat('es-MX', { style: 'currency', currency: currency || 'MXN' }).format(value)

export default function Show({ claim }) {
    const [refreshing, setRefreshing] = useState(false)
    const [message, setMessage] = useState('')
    const [confirming, setConfirming] = useState(false)
    const [sending, setSending] = useState(false)
    const [attachments, setAttachments] = useState([])
    const [resolution, setResolution] = useState(null)
    const [resolutionConfirmed, setResolutionConfirmed] = useState(false)
    const [resolving, setResolving] = useState(false)
    const [offers, setOffers] = useState([])
    const [offersError, setOffersError] = useState('')
    const page = usePage()
    const flash = page.props.flash || {}
    const errors = page.props.errors || {}
    const refresh = () => router.post(`/meli-claims/${claim.id}/refresh`, {}, { preserveScroll: true, onStart: () => setRefreshing(true), onFinish: () => setRefreshing(false) })
    const recipientLabel = claim.message_recipient === 'mediator' ? 'Mediador de Mercado Libre' : claim.message_recipient === 'complainant' ? 'Comprador' : claim.message_recipient === 'respondent' ? 'Vendedor' : null
    const confirmMessage = () => { if (message.trim() && !sending) setConfirming(true) }
    const sendMessage = () => {
        if (sending) return
        router.post(`/meli-claims/${claim.id}/messages`, { message, attachments }, {
            preserveScroll: true,
            onStart: () => setSending(true),
            onSuccess: responsePage => { if (responsePage.props.flash?.ok) { setMessage(''); setAttachments([]) } setConfirming(false) },
            onError: () => setConfirming(false),
            onFinish: () => setSending(false),
        })
    }
    const openResolution = async action => {
        if (resolving) return
        setResolutionConfirmed(false)
        setOffersError('')
        if (action !== 'partial_refund') return setResolution({ action })
        setResolving(true)
        try {
            const response = await fetch(`/meli-claims/${claim.id}/resolutions/partial-refund/offers`, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            if (!response.ok) throw new Error('offers')
            const data = await response.json()
            setOffers(Array.isArray(data.offers) ? data.offers : [])
            setResolution({ action })
        } catch {
            setOffersError('No fue posible obtener las ofertas vigentes de Mercado Libre. Actualiza el reclamo e inténtalo nuevamente.')
        } finally {
            setResolving(false)
        }
    }
    const confirmResolution = () => {
        if (!resolutionConfirmed || resolving || !resolution) return
        const suffix = resolution.action === 'allow_return' ? 'allow-return' : resolution.action === 'partial_refund' ? 'partial-refund' : 'refund'
        const payload = { confirmed: true, ...(resolution.action === 'partial_refund' ? { percentage: resolution.offer.percentage } : {}) }
        router.post(`/meli-claims/${claim.id}/resolutions/${suffix}`, payload, { preserveScroll: true, onStart: () => setResolving(true), onSuccess: () => { setResolution(null); setResolutionConfirmed(false) }, onFinish: () => setResolving(false) })
    }
    return <AppShell title="Reclamos"><Head title={`Reclamo ${claim.claim_id}`} /><main className="mx-auto max-w-6xl space-y-5 p-4 sm:p-6">
        <div className="flex flex-wrap justify-between gap-3"><Link href="/meli-claims" className="font-bold text-indigo-600">← Volver a Reclamos</Link><button onClick={refresh} disabled={refreshing} className="rounded-lg bg-indigo-600 px-4 py-2 font-bold text-white disabled:opacity-50">{refreshing ? 'Actualizando…' : 'Actualizar reclamo'}</button></div>
        {(flash.ok || flash.err) && <div className={`rounded-xl border p-4 ${flash.err ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800'}`}>{flash.err || flash.ok}</div>}
        <header className="rounded-xl border bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900"><div className="flex flex-wrap gap-2"><Badge>{claimStatus(claim.status)}</Badge><Badge>{claimStage(claim.stage)}</Badge><Badge>{claimType(claim.type)}</Badge></div><h1 className="mt-3 text-2xl font-black">Reclamo {claim.claim_id}</h1><p className="text-sm text-slate-500">Seguimiento de reclamo · Última sincronización: {date(claim.last_synced_at)}</p>{claim.sync_error && <p className="mt-2 text-sm text-red-600">La última actualización no pudo completarse.</p>}</header>
        {['dispute', 'mediation'].includes(claim.stage) && <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 font-bold text-amber-900">Mercado Libre intervino en este reclamo.</div>}
        <section className="grid gap-4 md:grid-cols-2"><Card title="Resumen"><Rows rows={[["Cuenta", accountName(claim.account)], ['Estado', claimStatus(claim.status)], ['Etapa', claimStage(claim.stage)], ['Tipo', claimType(claim.type)], ['Creado', date(claim.date_created)], ['Última actualización', date(claim.last_updated)]]} /></Card><Card title="Motivo del reclamo"><Rows rows={[["Motivo", claim.reason], ['Código oficial', claim.reason_id], ['Título', claim.detail_title], ['Descripción', claim.detail_description], ['Problema', claim.problem]]} /></Card></section>
        <Card title="¿Quién debe actuar?"><p className="text-xl font-black">{claim.action_responsible ? playerRole(claim.action_responsible) : 'Sin acción pendiente'}</p><ActionList value={claim.available_actions} /></Card>
        <Card title="Fechas y límites"><Deadlines values={claim.deadlines} /></Card>
        <Card title="Pedido"><Order claim={claim} /></Card>
        <Card title="Producto"><Products values={claim.products} currency={claim.order?.currency_id} /></Card>
        <ResolutionPanel claim={claim} resolving={resolving} offersError={offersError} openResolution={openResolution} />
        <Card title="Comprador / contraparte">{claim.participants?.length ? <ul>{claim.participants.map((p, i) => <li key={i}>{playerRole(p.role)}{p.type ? ` · ${p.type}` : ''}</li>)}</ul> : <Empty>Mercado Libre no informó datos visibles de las partes.</Empty>}</Card>
        <Card title="Resolución solicitada / esperada"><Resolutions value={claim.expected_resolutions} /></Card>
        <Card title="Historial del reclamo"><Timeline values={claim.timeline || []} /></Card>
        <Card title="Conversación"><Messages value={claim.messages} claimId={claim.id} /></Card>
        <Card title="Responder reclamo">{recipientLabel ? <div className="space-y-3"><p><b>Destinatario:</b> {recipientLabel}</p><textarea value={message} onChange={event => setMessage(event.target.value)} maxLength={2000} rows={7} disabled={sending} placeholder="Escribe tu mensaje" className="w-full rounded-lg border p-3 dark:border-neutral-700 dark:bg-neutral-800" />{errors.message && <p className="text-sm font-semibold text-red-600">{errors.message}</p>}<div><label className="font-bold">Adjuntar archivos</label><p className="text-xs text-slate-500">JPG, PNG o PDF · máximo 5 MB por archivo · máximo local de 5 archivos</p><input type="file" multiple accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" disabled={sending || attachments.length >= 5} onChange={event => { const selected = [...event.target.files].slice(0, 5 - attachments.length); setAttachments(current => [...current, ...selected]); event.target.value = '' }} className="mt-2 block w-full text-sm" />{errors.attachments && <p className="text-sm font-semibold text-red-600">{errors.attachments}</p>}<FileList files={attachments} remove={index => setAttachments(current => current.filter((_, itemIndex) => itemIndex !== index))} disabled={sending} /></div><div className="flex flex-wrap items-center justify-between gap-3"><span className="text-xs text-slate-500">{message.length}/2000</span><div className="flex gap-2"><button type="button" onClick={() => { setMessage(''); setAttachments([]) }} disabled={sending || (message.length === 0 && attachments.length === 0)} className="rounded-lg border px-4 py-2 font-bold disabled:opacity-50">Limpiar</button><button type="button" onClick={confirmMessage} disabled={sending || !message.trim()} className="rounded-lg bg-indigo-600 px-4 py-2 font-bold text-white disabled:opacity-50">Enviar mensaje</button></div></div></div> : <Empty>Actualmente Mercado Libre no permite enviar un mensaje desde este reclamo.</Empty>}</Card>
        {listData(claim.changes).length > 0 && <Card title="Cambio / reemplazo relacionado"><Changes value={claim.changes} /></Card>}
        {confirming && <div role="dialog" aria-modal="true" className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"><div className="w-full max-w-xl rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900"><h2 className="text-xl font-black">Confirmar envío</h2><p className="mt-2">Vas a enviar este mensaje a Mercado Libre.</p><dl className="mt-4 space-y-3"><div><dt className="text-xs font-bold uppercase text-slate-500">Reclamo</dt><dd>{claim.claim_id}</dd></div><div><dt className="text-xs font-bold uppercase text-slate-500">Destinatario</dt><dd>{recipientLabel}</dd></div><div><dt className="text-xs font-bold uppercase text-slate-500">Mensaje</dt><dd className="max-h-64 overflow-auto whitespace-pre-wrap rounded-lg bg-slate-50 p-3 dark:bg-neutral-800">{message.trim()}</dd></div>{attachments.length > 0 && <div><dt className="text-xs font-bold uppercase text-slate-500">Adjuntos</dt><dd><FileList files={attachments} /></dd></div>}</dl><div className="mt-5 flex justify-end gap-2"><button type="button" onClick={() => setConfirming(false)} disabled={sending} className="rounded-lg border px-4 py-2 font-bold disabled:opacity-50">Cancelar</button><button type="button" onClick={sendMessage} disabled={sending} className="rounded-lg bg-indigo-600 px-4 py-2 font-bold text-white disabled:opacity-50">{sending ? 'Enviando…' : 'Confirmar y enviar'}</button></div></div></div>}
        {resolution && <ResolutionModal claim={claim} resolution={resolution} offers={offers} confirmed={resolutionConfirmed} setConfirmed={setResolutionConfirmed} selectOffer={offer => { setResolutionConfirmed(false); setResolution({ action: 'partial_refund', offer }) }} close={() => { if (!resolving) { setResolution(null); setResolutionConfirmed(false) } }} submit={confirmResolution} resolving={resolving} />}
    </main></AppShell>
}

const Card = ({ title, children }) => <section className="rounded-xl border bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900"><h2 className="mb-4 text-lg font-black">{title}</h2>{children}</section>
const Badge = ({ children }) => <span className="rounded-full bg-indigo-100 px-3 py-1 text-xs font-bold text-indigo-800">{children}</span>
const Empty = ({ children }) => <p className="text-slate-500">{children}</p>
const Rows = ({ rows }) => <dl className="grid gap-3 sm:grid-cols-2">{rows.map(([key, value]) => <div key={key}><dt className="text-xs font-bold uppercase text-slate-500">{key}</dt><dd className="whitespace-pre-wrap">{value ?? '—'}</dd></div>)}</dl>
const fileSize = bytes => bytes < 1024 * 1024 ? `${Math.round(bytes / 1024)} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`
function FileList({ files = [], remove, disabled }) { return files.length ? <ul className="mt-2 space-y-1">{files.map((file, index) => <li key={`${file.name}-${file.size}-${index}`} className="flex justify-between gap-3 rounded bg-slate-50 px-3 py-2 text-sm dark:bg-neutral-800"><span>{file.name} · {fileSize(file.size)}</span>{remove && <button type="button" disabled={disabled} onClick={() => remove(index)} className="font-bold text-red-600 disabled:opacity-50">Quitar</button>}</li>)}</ul> : null }
function Order({ claim }) { const o = claim.order; return o ? <Rows rows={[["ID de orden Mercado Libre", o.order_id], ['Identificador local', o.display_id || o.id], ['Fecha de compra', date(o.date_created)], ['Estado', o.status], ['Total', money(o.total_amount ?? claim.order_amount, o.currency_id)], ['Moneda', o.currency_id]]} /> : <Empty>No se encontró un pedido local relacionado.</Empty> }
function Products({ values = [], currency }) { return values.length ? <div className="grid gap-3 md:grid-cols-2">{values.map((p, i) => <article key={`${p.mlm}-${i}`} className="flex gap-4 rounded-lg border p-3">{p.thumbnail && <img src={p.thumbnail} alt="" className="h-20 w-20 rounded object-cover" />}<div><p className="font-bold">{p.title || 'Producto sin título'}</p><p className="text-sm text-slate-500">{p.mlm || 'MLM no disponible'} · SKU {p.sku || '—'}</p><p className="text-sm">Cantidad: {p.quantity} · Precio unitario: {money(p.unit_price, currency)}</p>{(p.variation_id || p.variation_text) && <p className="text-sm">Variación: {p.variation_text || '—'} {p.variation_id && `(${p.variation_id})`}</p>}</div></article>)}</div> : <Empty>No hay productos locales relacionados.</Empty> }
function Deadlines({ values = [] }) { return values.length ? <ul className="space-y-3">{values.map((d, i) => <li key={i} className="rounded-lg bg-slate-50 p-3 dark:bg-neutral-800"><b>{playerRole(d.role)}</b> · {d.action ? availableAction(d.action) : 'Próximo paso'}<p className="text-sm">Fecha límite: {date(d.due_date)}{d.mandatory === true ? ' · Obligatoria' : ''}</p></li>)}</ul> : <Empty>Mercado Libre no informó una fecha límite.</Empty> }
function ActionList({ value }) { const values = listData(value); return values.length ? <ul className="mt-3">{values.map((a, i) => <li key={i}>{availableAction(actionName(a))}</li>)}</ul> : <Empty>Sin acciones informadas.</Empty> }
function Resolutions({ value }) { const values = listData(value); return values.length ? <ul className="space-y-2">{values.map((r, i) => { const raw = resolutionName(r); return <li key={i}><b>{expectedResolution(raw)}</b> <code className="text-xs">{raw}</code>{r.status && ` · ${claimStatus(r.status)}`}</li> })}</ul> : <Empty>Mercado Libre no informa una resolución esperada actualmente.</Empty> }
function Timeline({ values }) { return values.length ? <ol className="space-y-3 border-l pl-4">{values.map((e, i) => { const raw = e.source === 'action' ? actionName(e) : e.status || e.stage || e.type; return <li key={i}><b>{e.source === 'action' ? historyAction(raw) : claimStatus(raw)}</b>{(e.player_role || e.change_by || e.role) && <p className="text-sm">Actor: {playerRole(e.player_role || e.change_by || e.role)}</p>}<p className="text-sm text-slate-500">{date(e.date || e.date_created || e.created_at)}</p>{(e.description || e.reason) && <p>{e.description || e.reason}</p>}</li> })}</ol> : <Empty>Sin historial disponible.</Empty> }
function Messages({ value, claimId }) { const values = [...listData(value)].sort((a, b) => new Date(b.message_date || b.date_created || 0) - new Date(a.message_date || a.date_created || 0)); return values.length ? <div className="space-y-3">{values.map((m, i) => <article key={i} className="rounded-lg border p-3"><p className="text-sm font-bold">{playerRole(m.sender_role)} → {playerRole(m.receiver_role)} · {date(m.message_date || m.date_created)}</p><p className="mt-2 whitespace-pre-wrap">{m.message || m.translated_message || 'Mensaje sin texto visible'}</p>{m.attachments?.length > 0 && <ul className="mt-2 text-sm text-slate-500">{m.attachments.map((a, j) => { const remote = a.filename || a.file_name; return <li key={j}>Adjunto: {a.original_filename || remote || 'archivo'}{a.type ? ` · ${a.type}` : ''}{a.size ? ` · ${fileSize(a.size)}` : ''}{remote && <> · <a className="font-bold text-indigo-600" href={`/meli-claims/${claimId}/attachments/${encodeURIComponent(remote)}/download`}>Ver / Descargar</a></>}</li> })}</ul>}</article>)}</div> : <Empty>Este reclamo no tiene mensajes disponibles.</Empty> }
function Changes({ value }) { return <div className="space-y-3">{listData(value).map((change, index) => <article key={index} className="rounded-lg border p-3"><Rows rows={[["Tipo", change.type], ['Estado', change.status], ['Detalle de estado', change.status_detail], ['Creado', date(change.date_created)], ['Actualizado', date(change.last_updated)], ['Entrega estimada desde', date(change.estimated_exchange_date?.from)], ['Entrega estimada hasta', date(change.estimated_exchange_date?.to)]]} /></article>)}</div> }

function ResolutionPanel({ claim, resolving, offersError, openResolution }) {
    const actions = claim.resolution_actions || []
    if (!actions.length) return null
    return <Card title="Resolver reclamo"><p className="mb-4 text-sm text-slate-600 dark:text-slate-300">Estas acciones afectan dinero o devoluciones y se validarán nuevamente con Mercado Libre antes de ejecutarse.</p><div className="flex flex-wrap gap-3">{actions.includes('allow_return') && <button type="button" disabled={resolving} onClick={() => openResolution('allow_return')} className="rounded-lg border border-amber-500 px-4 py-2 font-bold text-amber-800 disabled:opacity-50">Permitir devolución</button>}{actions.includes('refund') && <button type="button" disabled={resolving} onClick={() => openResolution('refund')} className="rounded-lg bg-red-700 px-4 py-2 font-bold text-white disabled:opacity-50">Reembolsar compra</button>}{actions.includes('partial_refund') && <button type="button" disabled={resolving} onClick={() => openResolution('partial_refund')} className="rounded-lg border border-red-600 px-4 py-2 font-bold text-red-700 disabled:opacity-50">Ofrecer reembolso parcial</button>}</div>{offersError && <p className="mt-3 text-sm font-semibold text-red-600">{offersError}</p>}</Card>
}

function ResolutionModal({ claim, resolution, offers, confirmed, setConfirmed, selectOffer, close, submit, resolving }) {
    const partial = resolution.action === 'partial_refund'
    const title = resolution.action === 'refund' ? 'REEMBOLSO TOTAL' : resolution.action === 'allow_return' ? 'PERMITIR DEVOLUCIÓN' : 'REEMBOLSO PARCIAL'
    const warning = resolution.action === 'refund' ? 'Esta acción devolverá el dinero al comprador y puede cerrar el reclamo. No se puede deshacer desde este panel.' : resolution.action === 'allow_return' ? 'Mercado Libre podrá generar el flujo o etiqueta para que el comprador devuelva el producto. El reembolso se procesará de acuerdo con el flujo de devolución de Mercado Libre.' : 'El comprador recibirá una oferta de reembolso parcial. Si la acepta, el reclamo podrá cerrarse.'
    const checkbox = resolution.action === 'refund' ? 'Confirmo que deseo realizar el reembolso total.' : resolution.action === 'allow_return' ? 'Confirmo que deseo permitir la devolución.' : 'Confirmo que deseo ofrecer este reembolso parcial.'
    const button = resolution.action === 'refund' ? 'Confirmar reembolso total' : resolution.action === 'allow_return' ? 'Confirmar devolución' : 'Confirmar oferta de reembolso'
    const currency = claim.order?.currency_id || 'MXN'
    const firstProduct = claim.products?.[0]
    const product = firstProduct?.title || firstProduct?.mlm || '—'
    const additionalProducts = Math.max(0, (claim.products?.length || 0) - 1)
    return <div role="dialog" aria-modal="true" className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"><div className="w-full max-w-xl rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900"><h2 className="text-xl font-black text-red-700">{title}</h2><dl className="mt-4 grid gap-3 sm:grid-cols-2"><div><dt className="text-xs font-bold uppercase text-slate-500">Reclamo</dt><dd>{claim.claim_id}</dd></div><div><dt className="text-xs font-bold uppercase text-slate-500">Pedido</dt><dd>{claim.order_id || '—'}</dd></div><div className="sm:col-span-2"><dt className="text-xs font-bold uppercase text-slate-500">Producto</dt><dd>{product}{additionalProducts > 0 ? ` y ${additionalProducts} producto(s) más` : ''}</dd></div>{resolution.action === 'refund' && <div><dt className="text-xs font-bold uppercase text-slate-500">Total del pedido</dt><dd>{money(claim.order?.total_amount ?? claim.order_amount, currency)} {currency}</dd></div>}{partial && resolution.offer && <><div><dt className="text-xs font-bold uppercase text-slate-500">Porcentaje</dt><dd>{resolution.offer.percentage}%</dd></div><div><dt className="text-xs font-bold uppercase text-slate-500">Importe</dt><dd>{money(resolution.offer.amount, resolution.offer.currency_id)} {resolution.offer.currency_id}</dd></div></>}</dl>{partial && !resolution.offer && <div className="mt-4"><p className="font-bold">Selecciona una oferta vigente:</p><div className="mt-2 grid gap-2">{offers.map(offer => <button key={offer.percentage} type="button" onClick={() => selectOffer(offer)} className="rounded-lg border p-3 text-left font-bold hover:border-indigo-500">{offer.percentage}% — {money(offer.amount, offer.currency_id)} {offer.currency_id}</button>)}</div>{!offers.length && <p className="mt-2 text-sm text-red-600">Mercado Libre no devolvió ofertas disponibles.</p>}</div>}{(!partial || resolution.offer) && <><p className="mt-5 rounded-lg bg-red-50 p-4 font-semibold text-red-900">{warning}</p><label className="mt-4 flex gap-3 font-bold"><input type="checkbox" checked={confirmed} disabled={resolving} onChange={event => setConfirmed(event.target.checked)} /> <span>{checkbox}</span></label></>}<div className="mt-6 flex justify-end gap-2"><button type="button" onClick={close} disabled={resolving} className="rounded-lg border px-4 py-2 font-bold disabled:opacity-50">Cancelar</button>{(!partial || resolution.offer) && <button type="button" onClick={submit} disabled={!confirmed || resolving} className="rounded-lg bg-red-700 px-4 py-2 font-bold text-white disabled:opacity-50">{resolving ? 'Procesando…' : button}</button>}</div></div></div>
}
