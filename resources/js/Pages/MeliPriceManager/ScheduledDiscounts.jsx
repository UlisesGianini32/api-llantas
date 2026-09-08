import AppShell from '@/Components/layout/AppShell'
import { Head, router, useForm } from '@inertiajs/react'
import { useEffect, useMemo, useState } from 'react'

const fieldClass = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white'
const secondaryButton = 'rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50 dark:border-neutral-700 dark:text-slate-200 dark:hover:bg-neutral-800'
const primaryButton = 'rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-50'

function ErrorText({ message }) {
    return message ? <p className="mt-1 text-xs font-medium text-rose-600 dark:text-rose-300">{message}</p> : null
}

function Badge({ children, tone = 'slate' }) {
    const tones = {
        green: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200',
        amber: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
        rose: 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-200',
        slate: 'bg-slate-100 text-slate-700 dark:bg-neutral-800 dark:text-slate-300',
        indigo: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/15 dark:text-indigo-200',
    }
    return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${tones[tone]}`}>{children}</span>
}

const statusLabels = {
    scheduled: ['Programada', 'indigo'],
    in_window: ['En ventana', 'green'],
    outside_hours: ['Fuera de horario', 'amber'],
    finished: ['Finalizada', 'slate'],
    disabled: ['Deshabilitada', 'slate'],
    requires_configuration: ['Requiere configuración', 'rose'],
}

function timePart(value) {
    return String(value ?? '').slice(0, 5)
}

function dateForUi(value) {
    const part = String(value ?? '').slice(0, 10)
    const match = part.match(/^(\d{4})-(\d{2})-(\d{2})$/)
    return match ? `${match[3]}/${match[2]}/${match[1]}` : part
}

function normalizeClock(value) {
    const digits = value.replace(/\D/g, '').slice(0, 4)
    return digits.length > 2 ? `${digits.slice(0, 2)}:${digits.slice(2)}` : digits
}

function money(value) {
    return Number.isFinite(Number(value))
        ? Number(value).toLocaleString('es-MX', { style: 'currency', currency: 'MXN' })
        : 'Sin precio'
}

function target(price, percentage) {
    const base = Number(price)
    const discount = Number(percentage)
    return base > 0 && discount > 0 && discount < 100 ? Math.round(base * (1 - discount / 100) * 100) / 100 : null
}

function timezoneLabel(timezone) {
    return timezone === 'America/Mexico_City'
        ? 'Ciudad de México (America/Mexico_City)'
        : timezone
}

function emptyForm(defaultTimezone) {
    return {
        meli_account_id: '', brand_group_id: '', starts_on: '', ends_on: '', starts_at: '20:00', ends_at: '06:00',
        timezone: defaultTimezone, active: false, discount_percentage: '10', items: [],
    }
}

export default function ScheduledDiscounts({
    accounts = [], selectedAccountId = null, rules = [], brandOptions = [],
    defaultTimezone = 'America/Mexico_City', automationEnabled = false, schedulerEnabled = false,
}) {
    const [editor, setEditor] = useState(null)
    const [rows, setRows] = useState([])
    const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0, all_ids: [] })
    const [loading, setLoading] = useState(false)
    const [search, setSearch] = useState('')
    const [sort, setSort] = useState('title')
    const [page, setPage] = useState(1)
    const [selected, setSelected] = useState({})
    const [bulkPercentage, setBulkPercentage] = useState('10')
    const form = useForm(emptyForm(defaultTimezone))
    const selectedAccount = accounts.find((account) => Number(account.id) === Number(selectedAccountId))
    const selectedCount = Object.keys(selected).length

    useEffect(() => {
        if (!editor || !form.data.meli_account_id || !form.data.brand_group_id) {
            setRows([])
            return undefined
        }
        const controller = new AbortController()
        const timer = window.setTimeout(async () => {
            setLoading(true)
            const params = new URLSearchParams({
                catalog: '1',
                meli_account_id: form.data.meli_account_id,
                brand_group_id: form.data.brand_group_id,
                search,
                sort,
                page: String(page),
            })
            try {
                const response = await fetch(`/meli-price-manager/scheduled-discounts?${params}`, {
                    signal: controller.signal,
                    headers: { Accept: 'application/json' },
                })
                if (response.ok) {
                    const payload = await response.json()
                    setRows(payload.data)
                    setMeta(payload.meta)
                }
            } finally {
                if (!controller.signal.aborted) setLoading(false)
            }
        }, 250)
        return () => {
            window.clearTimeout(timer)
            controller.abort()
        }
    }, [editor, form.data.meli_account_id, form.data.brand_group_id, search, sort, page])

    const selectedItems = useMemo(() => Object.entries(selected).map(([id, percentage]) => ({
        price_manager_item_id: Number(id), discount_percentage: percentage,
    })), [selected])

    const openCreate = () => {
        form.clearErrors()
        form.setData({ ...emptyForm(defaultTimezone), meli_account_id: String(selectedAccountId ?? '') })
        setSelected({})
        setSearch('')
        setSort('title')
        setPage(1)
        setEditor({ mode: 'create' })
    }

    const openEdit = (promotion) => {
        form.clearErrors()
        const selections = Object.fromEntries((promotion.scheduled_items ?? []).map((item) => [
            item.price_manager_item_id, String(item.discount_percentage),
        ]))
        form.setData({
            meli_account_id: String(promotion.meli_account_id),
            brand_group_id: String(promotion.brand_group_id),
            starts_on: dateForUi(promotion.starts_on),
            ends_on: dateForUi(promotion.ends_on),
            starts_at: timePart(promotion.starts_at),
            ends_at: timePart(promotion.ends_at),
            timezone: defaultTimezone,
            active: Boolean(promotion.active),
            discount_percentage: String(promotion.discount_percentage ?? '10'),
            items: [],
        })
        setSelected(selections)
        setBulkPercentage(String(promotion.discount_percentage ?? '10'))
        setSearch('')
        setSort('title')
        setPage(1)
        setEditor({ mode: 'edit', id: promotion.id, brandName: promotion.brand_group?.name })
    }

    const submit = (event) => {
        event.preventDefault()
        form.transform((data) => ({ ...data, items: selectedItems }))
        const options = { preserveScroll: true, onSuccess: () => setEditor(null) }
        editor?.mode === 'edit'
            ? form.put(`/meli-price-manager/scheduled-discounts/${editor.id}`, options)
            : form.post('/meli-price-manager/scheduled-discounts', options)
    }

    const toggleSelection = (row) => setSelected((current) => {
        const next = { ...current }
        if (next[row.id] !== undefined) delete next[row.id]
        else next[row.id] = bulkPercentage
        return next
    })

    const applyBulk = () => setSelected((current) => Object.fromEntries(Object.keys(current).map((id) => [id, bulkPercentage])))
    const selectAll = () => setSelected((current) => ({
        ...current,
        ...Object.fromEntries((meta.all_ids ?? []).map((id) => [id, current[id] ?? bulkPercentage])),
    }))

    const togglePromotion = (promotion) => {
        const active = !promotion.active
        if (!window.confirm(active ? '¿Habilitar esta promoción?' : '¿Deshabilitarla? El scheduler restaurará los precios confirmados.')) return
        router.patch(`/meli-price-manager/scheduled-discounts/${promotion.id}/status`, { active }, { preserveScroll: true })
    }

    const activeBrands = editor?.mode === 'edit' && editor.brandName && !brandOptions.some((brand) => Number(brand.id) === Number(form.data.brand_group_id))
        ? [...brandOptions, { id: form.data.brand_group_id, name: editor.brandName }]
        : brandOptions

    return (
        <AppShell title="Meli Price Manager">
            <Head title="Promociones programadas · Meli Price Manager" />
            <div className="space-y-6">
                <header className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
                    <div><p className="text-xs font-bold uppercase tracking-[0.2em] text-indigo-600">Meli Price Manager</p><h1 className="mt-1 text-3xl font-bold">Promociones programadas</h1><p className="mt-2 text-sm text-slate-500">PRICE_DISCOUNT de Beauty por periodo, horario y publicación.</p></div>
                    <button type="button" onClick={openCreate} disabled={!selectedAccountId || !brandOptions.length} className={primaryButton}>Nueva promoción</button>
                </header>

                <section className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-wrap items-center gap-3 text-sm"><b>Automatización</b><Badge tone={automationEnabled ? 'green' : 'slate'}>{automationEnabled ? 'Activa' : 'Desactivada'}</Badge><b>Scheduler</b><Badge tone={schedulerEnabled ? 'green' : 'slate'}>{schedulerEnabled ? 'Cada minuto' : 'Desactivado'}</Badge></div>
                    {!automationEnabled && <p className="mt-2 text-sm text-slate-500">Puedes configurar y validar promociones, pero no se escribirán precios en Mercado Libre.</p>}
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <label className="grid gap-2 md:grid-cols-[1fr_24rem] md:items-center"><span><b>Cuenta de Mercado Libre</b><small className="block text-slate-500">Las marcas y publicaciones se aíslan por cuenta.</small></span><select value={selectedAccountId ?? ''} onChange={(event) => router.get('/meli-price-manager/scheduled-discounts', { account: event.target.value }, { preserveState: false })} className={fieldClass}>{accounts.map((account) => <option key={account.id} value={account.id}>{account.nickname || `Cuenta #${account.id}`}</option>)}</select></label>
                </section>

                {editor && <section className="rounded-2xl border border-indigo-200 bg-indigo-50/50 p-5 dark:border-indigo-500/20 dark:bg-indigo-500/5">
                    <div className="mb-5 flex justify-between gap-3"><div><h2 className="text-lg font-bold">{editor.mode === 'edit' ? 'Editar promoción' : 'Nueva promoción'}</h2><p className="text-sm text-slate-500">Zona fija: {timezoneLabel(defaultTimezone)}. Fechas DD/MM/AAAA y horas HH:mm (24 h).</p></div><button type="button" onClick={() => setEditor(null)} className={secondaryButton}>Cancelar</button></div>
                    <form onSubmit={submit} className="space-y-5">
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <label><span className="mb-1 block text-sm font-semibold">Cuenta</span><select value={form.data.meli_account_id} onChange={(e) => { form.setData('meli_account_id', e.target.value); setSelected({}); setPage(1) }} className={fieldClass} disabled={editor.mode === 'edit'}>{accounts.map((account) => <option key={account.id} value={account.id}>{account.nickname || `Cuenta #${account.id}`}</option>)}</select><ErrorText message={form.errors.meli_account_id} /></label>
                            <label><span className="mb-1 block text-sm font-semibold">Marca Beauty</span><select value={form.data.brand_group_id} onChange={(e) => { form.setData('brand_group_id', e.target.value); setSelected({}); setPage(1) }} className={fieldClass} disabled={editor.mode === 'edit'}><option value="">Selecciona</option>{activeBrands.map((brand) => <option key={brand.id} value={brand.id}>{brand.name}</option>)}</select><ErrorText message={form.errors.brand_group_id} /></label>
                            <label><span className="mb-1 block text-sm font-semibold">Fecha inicial</span><input value={form.data.starts_on} onChange={(e) => form.setData('starts_on', e.target.value)} placeholder="08/09/2028" inputMode="numeric" className={fieldClass} /><ErrorText message={form.errors.starts_on} /></label>
                            <label><span className="mb-1 block text-sm font-semibold">Fecha final</span><input value={form.data.ends_on} onChange={(e) => form.setData('ends_on', e.target.value)} placeholder="12/09/2028" inputMode="numeric" className={fieldClass} /><ErrorText message={form.errors.ends_on} /></label>
                            <label><span className="mb-1 block text-sm font-semibold">Hora inicial</span><input value={form.data.starts_at} onChange={(e) => form.setData('starts_at', normalizeClock(e.target.value))} placeholder="20:00" inputMode="numeric" maxLength={5} className={fieldClass} /><ErrorText message={form.errors.starts_at} /></label>
                            <label><span className="mb-1 block text-sm font-semibold">Hora final</span><input value={form.data.ends_at} onChange={(e) => form.setData('ends_at', normalizeClock(e.target.value))} placeholder="06:00" inputMode="numeric" maxLength={5} className={fieldClass} /><ErrorText message={form.errors.ends_at} /></label>
                            <div><span className="mb-1 block text-sm font-semibold">Zona horaria</span><div className={`${fieldClass} bg-slate-50 dark:bg-neutral-900`}>{timezoneLabel(defaultTimezone)}</div></div>
                            <label className="flex items-center gap-2 self-end rounded-xl border border-slate-200 px-3 py-2 dark:border-neutral-700"><input type="checkbox" checked={Boolean(form.data.active)} onChange={(e) => form.setData('active', e.target.checked)} /><span className="text-sm font-semibold">Promoción habilitada</span></label>
                        </div>

                        <div className="rounded-2xl border border-slate-200 bg-white dark:border-neutral-700 dark:bg-neutral-900">
                            <div className="grid gap-3 border-b border-slate-200 p-4 md:grid-cols-[1fr_13rem_12rem] dark:border-neutral-700">
                                <input value={search} onChange={(e) => { setSearch(e.target.value); setPage(1) }} placeholder="Buscar título, MLM o SKU" className={fieldClass} />
                                <select value={sort} onChange={(e) => { setSort(e.target.value); setPage(1) }} className={fieldClass}><option value="title">Título</option><option value="price_asc">Precio ascendente</option><option value="price_desc">Precio descendente</option><option value="kits_first">Kits primero</option><option value="kits_last">Kits al final</option></select>
                                <div className="flex gap-2"><button type="button" onClick={selectAll} className={secondaryButton}>Seleccionar todo</button><button type="button" onClick={() => setSelected({})} className={secondaryButton}>Deseleccionar todo</button></div>
                            </div>
                            <div className="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4 dark:border-neutral-700"><label><span className="mb-1 block text-xs font-semibold">Descuento masivo (%)</span><input type="number" min="0.01" max="99.99" step="0.01" value={bulkPercentage} onChange={(e) => setBulkPercentage(e.target.value)} className={`${fieldClass} w-40`} /></label><button type="button" onClick={applyBulk} disabled={!selectedCount} className={secondaryButton}>Aplicar a seleccionadas</button><Badge tone="indigo">{selectedCount} seleccionadas</Badge></div>
                            <ErrorText message={form.errors.items} />
                            <div className="overflow-x-auto"><table className="min-w-[920px] w-full text-left text-sm"><thead className="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-neutral-950"><tr><th className="px-3 py-2">Sel.</th><th className="px-3 py-2">Kit</th><th className="px-3 py-2">Publicación</th><th className="px-3 py-2">SKU/modelo</th><th className="px-3 py-2">Standard actual</th><th className="px-3 py-2">Descuento</th><th className="px-3 py-2">Vista previa</th><th className="px-3 py-2">Estado</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                {loading && <tr><td colSpan="8" className="p-8 text-center text-slate-500">Cargando publicaciones…</td></tr>}
                                {!loading && rows.map((row) => { const percentage = selected[row.id]; const preview = target(row.current_price, percentage); return <tr key={row.id}><td className="px-3 py-3"><input type="checkbox" checked={percentage !== undefined} onChange={() => toggleSelection(row)} /></td><td className="px-3 py-3">{row.is_kit ? <Badge tone="amber">Kit</Badge> : '—'}</td><td className="px-3 py-3"><b>{row.title}</b><small className="block text-slate-500">{row.meli_item_id}</small></td><td className="px-3 py-3">{row.sku || '—'}</td><td className="px-3 py-3 font-semibold">{money(row.current_price)}</td><td className="px-3 py-3"><input type="number" min="0.01" max="99.99" step="0.01" disabled={percentage === undefined} value={percentage ?? ''} onChange={(e) => setSelected((current) => ({ ...current, [row.id]: e.target.value }))} className={`${fieldClass} w-24`} />{form.errors[`items.${selectedItems.findIndex((item) => item.price_manager_item_id === row.id)}.discount_percentage`] && <ErrorText message="Porcentaje inválido" />}</td><td className="px-3 py-3 font-semibold text-emerald-700">{preview === null ? '—' : money(preview)}</td><td className="px-3 py-3">{row.state ? <Badge tone={row.state === 'active' ? 'green' : 'slate'}>{row.state}</Badge> : 'Sin estado'}</td></tr> })}
                                {!loading && !rows.length && <tr><td colSpan="8" className="p-8 text-center text-slate-500">No hay publicaciones Beauty elegibles.</td></tr>}
                            </tbody></table></div>
                            <div className="flex items-center justify-between border-t border-slate-200 p-3 text-sm dark:border-neutral-700"><span>{meta.total} publicaciones · página {meta.current_page} de {meta.last_page}</span><div className="flex gap-2"><button type="button" onClick={() => setPage((value) => Math.max(1, value - 1))} disabled={page <= 1} className={secondaryButton}>Anterior</button><button type="button" onClick={() => setPage((value) => Math.min(meta.last_page, value + 1))} disabled={page >= meta.last_page} className={secondaryButton}>Siguiente</button></div></div>
                        </div>
                        <div className="flex justify-end"><button disabled={form.processing || !selectedCount} className={primaryButton}>{form.processing ? 'Guardando…' : 'Guardar promoción'}</button></div>
                    </form>
                </section>}

                <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="border-b border-slate-200 p-4 dark:border-neutral-800"><h2 className="font-bold">Promociones configuradas</h2></div>
                    {!rules.length && <div className="p-10 text-center text-sm text-slate-500">No hay promociones programadas para esta cuenta.</div>}
                    {!!rules.length && <div className="overflow-x-auto"><table className="min-w-[980px] w-full text-left text-sm"><thead className="bg-slate-50 text-xs uppercase text-slate-500 dark:bg-neutral-950"><tr><th className="px-4 py-3">Marca</th><th className="px-4 py-3">Periodo</th><th className="px-4 py-3">Horario</th><th className="px-4 py-3">Publicaciones</th><th className="px-4 py-3">Estado</th><th className="px-4 py-3">Precios</th><th className="px-4 py-3">Acciones</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-neutral-800">{rules.map((promotion) => { const status = statusLabels[promotion.schedule_status] ?? statusLabels.requires_configuration; return <tr key={promotion.id}><td className="px-4 py-4"><b>{promotion.brand_group?.name || 'Marca no disponible'}</b><small className="block text-slate-500">{selectedAccount?.nickname}</small></td><td className="px-4 py-4">{promotion.starts_on ? `${dateForUi(promotion.starts_on)} – ${dateForUi(promotion.ends_on)}` : 'Sin fechas (legado)'}</td><td className="px-4 py-4">{timePart(promotion.starts_at)} – {timePart(promotion.ends_at)}<small className="block text-slate-500">{timezoneLabel(promotion.timezone)}</small></td><td className="px-4 py-4 font-bold">{promotion.selected_items_count ?? 0}</td><td className="px-4 py-4"><Badge tone={status[1]}>{status[0]}</Badge></td><td className="px-4 py-4"><span>{promotion.state_summary?.active || 0} activas</span><small className="block text-slate-500">{promotion.state_summary?.restore_pending || 0} por restaurar · {promotion.state_summary?.failed || 0} fallidas</small></td><td className="px-4 py-4"><div className="flex gap-2"><button type="button" onClick={() => openEdit(promotion)} className={secondaryButton}>Editar</button><button type="button" onClick={() => togglePromotion(promotion)} className={secondaryButton}>{promotion.active ? 'Deshabilitar' : 'Habilitar'}</button></div></td></tr> })}</tbody></table></div>}
                </section>
            </div>
        </AppShell>
    )
}
