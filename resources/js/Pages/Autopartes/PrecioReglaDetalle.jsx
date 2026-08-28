import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router, useForm } from '@inertiajs/react'
import axios from 'axios'
import { useState } from 'react'

export default function PrecioReglaDetalle({ rule, affectedDraftsCount = 0 }) {
    const action = name => router.post(`/autopartes/precios/reglas/${rule.id}/${name}`)
    const edit = useForm({ name: rule.name, scope_type: rule.scope_type, scope_value: rule.scope_value ?? '', source_currency: rule.source_currency, target_currency: rule.target_currency, usd_mxn_rate: rule.usd_mxn_rate, markup_percent: rule.markup_percent, meli_fee_percent: rule.meli_fee_percent, fixed_cost_mxn: rule.fixed_cost_mxn, rounding_mode: rule.rounding_mode, rounding_increment: rule.rounding_increment, minimum_price_mxn: rule.minimum_price_mxn ?? '', maximum_price_mxn: rule.maximum_price_mxn ?? '', effective_from: rule.effective_from, effective_until: rule.effective_until ?? '', notes: rule.notes ?? '', metadata: rule.metadata ?? {} })
    const [partId, setPartId] = useState('')
    const [preview, setPreview] = useState(null)
    const previewPrice = async e => { e.preventDefault(); const response = await axios.post(`/autopartes/precios/reglas/${rule.id}/previsualizar`, { automotive_part_id: partId }); setPreview(response.data) }
    return <AppShell title="Regla de precio"><Head title="Regla de precio" /><div className="space-y-5">
        <div><Link href="/autopartes/precios" className="text-sm text-sky-700">← Reglas</Link><h1 className="text-2xl font-bold">{rule.name} · v{rule.version}</h1><p>{rule.scope_type}: {rule.scope_value ?? 'global'} · {rule.status}</p></div>
        <div className="space-y-1 rounded-xl border border-amber-300 bg-amber-50 p-4 font-semibold text-amber-950"><p>Este precio es un cálculo interno y no modifica Mercado Libre.</p><p>Activar una regla no publica ni cambia precios externos.</p></div>
        <section className="rounded-xl border p-4"><h2 className="font-bold">Fórmula</h2><pre className="mt-2 overflow-auto text-sm">source_price_mxn = precio USD × {rule.usd_mxn_rate}{'\n'}subtotal = source_price_mxn × (1 + {rule.markup_percent}/100) + {rule.fixed_cost_mxn}{'\n'}antes_redondeo = subtotal / (1 - {rule.meli_fee_percent}/100){'\n'}redondeo = {rule.rounding_mode} a {rule.rounding_increment}; después mínimo/máximo</pre></section>
        <p className="rounded-xl border p-3"><strong>{affectedDraftsCount}</strong> borradores vigentes quedarían obsoletos para este scope.</p>
        {rule.status === 'draft' && <form onSubmit={e => { e.preventDefault(); edit.put(`/autopartes/precios/reglas/${rule.id}`) }} className="grid gap-2 rounded-xl border p-4 md:grid-cols-3">{Object.entries(edit.data).filter(([key]) => !['metadata','source_currency','target_currency'].includes(key)).map(([key, value]) => <input key={key} value={value ?? ''} onChange={e => edit.setData(key, e.target.value)} className="rounded border p-2" placeholder={key}/>)}<button className="rounded bg-slate-900 p-2 text-white">Guardar borrador</button></form>}
        <form onSubmit={previewPrice} className="rounded-xl border p-4"><div className="flex gap-2"><input value={partId} onChange={e => setPartId(e.target.value)} className="rounded border p-2" placeholder="ID de autoparte"/><button className="rounded border px-3">Previsualizar cálculo</button></div>{preview && <pre className="mt-3 overflow-auto text-xs">{JSON.stringify(preview, null, 2)}</pre>}</form>
        <div className="flex gap-2">{rule.status === 'draft' && <button onClick={() => action('activar')} className="rounded border px-3 py-2">Aprobar y activar</button>}{rule.status === 'active' && <button onClick={() => action('desactivar')} className="rounded border px-3 py-2">Desactivar</button>}{['active','inactive'].includes(rule.status) && <button onClick={() => action('reemplazar')} className="rounded border px-3 py-2">Crear nueva versión</button>}</div>
        <details className="rounded-xl border p-4"><summary className="font-bold">Historial y cálculos</summary><pre className="overflow-auto text-xs">{JSON.stringify({ events: rule.events, calculations: rule.calculations }, null, 2)}</pre></details>
    </div></AppShell>
}
