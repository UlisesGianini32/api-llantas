import AppShell from '@/Components/layout/AppShell'
import { accountName, claimStage, claimStatus, claimType, playerRole } from '@/lib/meliClaimsPresentation'
import { Head, Link, router, useForm } from '@inertiajs/react'
import { useState } from 'react'

const badge = 'rounded-full px-2.5 py-1 text-xs font-bold'
const field = 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950'
const date = value => value ? new Date(value).toLocaleString('es-MX') : '—'
const money = value => value === null || value === undefined ? '—' : new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(value)

export default function Index({ accounts, selectedAccountId, claims, stats, filters, options, lastSyncedAt }) {
    const [data, setData] = useState(filters)
    const sync = useForm({ account_id: selectedAccountId })
    const apply = event => { event.preventDefault(); router.get('/meli-claims', { ...data, account: selectedAccountId }, { preserveState: true, replace: true }) }

    return <AppShell title="Reclamos">
        <Head title="Reclamos · Mercado Libre" />
        <div className="mx-auto max-w-7xl space-y-5 p-4 sm:p-6">
            <header className="flex flex-wrap items-end justify-between gap-4">
                <div><p className="text-xs font-bold uppercase tracking-widest text-indigo-600">Mercado Libre</p><h1 className="text-2xl font-black">Reclamos</h1><p className="text-sm text-slate-500">Información sincronizada de Mercado Libre · Última sincronización: {date(lastSyncedAt)}</p></div>
                <button disabled={!selectedAccountId || sync.processing} onClick={() => sync.post('/meli-claims/sync', { preserveScroll: true })} className="rounded-lg bg-indigo-600 px-4 py-2 font-bold text-white disabled:opacity-50">Sincronizar reclamos</button>
            </header>
            <div className="grid gap-3 sm:grid-cols-5">{[['Abiertos', stats.open], ['Requieren mi acción', stats.action], ['Por vencer', stats.due], ['En mediación', stats.mediation], ['Cerrados', stats.closed]].map(([label, value]) => <div key={label} className="rounded-xl border bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900"><p className="text-xs text-slate-500">{label}</p><p className="text-2xl font-black">{value}</p></div>)}</div>
            <form onSubmit={apply} className="grid gap-3 rounded-xl border bg-white p-4 md:grid-cols-4 dark:border-neutral-800 dark:bg-neutral-900">
                <select className={field} value={selectedAccountId || ''} onChange={e => router.get('/meli-claims', { ...data, account: e.target.value })}>{accounts.map(account => <option key={account.id} value={account.id}>{accountName(account)}</option>)}</select>
                {[['status', 'Estado', options.statuses, claimStatus], ['stage', 'Etapa', options.stages, claimStage], ['type', 'Tipo', options.types, claimType]].map(([key, label, values, format]) => <select key={key} className={field} value={data[key]} onChange={e => setData({ ...data, [key]: e.target.value })}><option value="">{label}: todos</option>{values.map(value => <option key={value} value={value}>{format(value)}</option>)}</select>)}
                <select className={field} value={data.reputation} onChange={e => setData({ ...data, reputation: e.target.value })}><option value="all">Reputación: todos</option><option value="yes">Afecta</option><option value="no">No afecta</option><option value="unknown">Sin información</option></select>
                <input className={`${field} md:col-span-2`} value={data.search} onChange={e => setData({ ...data, search: e.target.value })} placeholder="Claim, pedido, pack, MLM, SKU o título" />
                <input className={field} value={data.action_responsible} onChange={e => setData({ ...data, action_responsible: e.target.value })} placeholder="Responsable de acción" />
                <button className="rounded-lg bg-slate-900 px-4 py-2 font-bold text-white dark:bg-white dark:text-black">Aplicar filtros</button>
            </form>
            <section className="space-y-3">
                {!claims.data.length && <div className="rounded-xl border p-10 text-center text-slate-500">No hay reclamos para estos filtros.</div>}
                {claims.data.map(claim => {
                    const product = claim.products[0]
                    return <article key={claim.id} className="rounded-xl border bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900"><div className="flex flex-wrap justify-between gap-4"><div className="flex min-w-0 gap-4">{product?.thumbnail && <img src={product.thumbnail} alt="" className="h-20 w-20 rounded-lg object-cover" />}<div><div className="flex flex-wrap gap-2"><span className={`${badge} ${claim.urgency === 'critical' ? 'bg-red-100 text-red-800' : claim.urgency === 'attention' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-700'}`}>{claim.urgency === 'critical' ? 'CRÍTICO' : claim.urgency === 'attention' ? 'ATENCIÓN' : 'EN ESPERA'}</span><span className={`${badge} bg-indigo-100 text-indigo-800`}>{claimStatus(claim.status)}</span>{claim.affects_reputation === true && <span className={`${badge} bg-red-100 text-red-800`}>Afecta reputación</span>}</div><h2 className="mt-2 font-bold">{product?.title || `Reclamo ${claim.claim_id}`}{claim.products.length > 1 && <span className="ml-2 text-sm font-normal text-slate-500">+{claim.products.length - 1} productos</span>}</h2><p className="text-sm text-slate-500">Claim {claim.claim_id} · Pedido {claim.order_id || '—'} · {product?.mlm || 'MLM no disponible'} · SKU {product?.sku || '—'} · {money(claim.order_amount)}</p><p className="mt-2 text-sm">{claim.reason || claim.detail_title || 'Motivo sin resolver'} · {claimStage(claim.stage)} · Acción: {playerRole(claim.action_responsible)}</p><p className="text-xs text-slate-500">Abierto: {date(claim.date_created)} · Vence: {date(claim.due_date)}</p></div></div><Link href={`/meli-claims/${claim.id}`} className="self-center rounded-lg border px-4 py-2 font-bold">Ver detalle</Link></div></article>
                })}
            </section>
            {claims.links?.length > 0 && <nav className="flex flex-wrap gap-2">{claims.links.map((link, index) => <Link key={index} href={link.url || '#'} className={`rounded border px-3 py-2 ${link.active ? 'bg-indigo-600 text-white' : ''} ${!link.url ? 'pointer-events-none opacity-40' : ''}`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</nav>}
        </div>
    </AppShell>
}
