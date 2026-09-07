import AppShell from '@/Components/layout/AppShell'
import { Head, router, useForm } from '@inertiajs/react'
import { useMemo, useState } from 'react'

const fieldClass =
    'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white dark:focus:ring-indigo-500/20'
const secondaryButton =
    'rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-50 dark:border-neutral-700 dark:text-slate-200 dark:hover:bg-neutral-800'
const primaryButton =
    'rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-50'

function ErrorText({ message }) {
    return message ? <p className="mt-1 text-xs font-medium text-rose-600 dark:text-rose-300">{message}</p> : null
}

function Badge({ children, tone = 'slate' }) {
    const tones = {
        green: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200',
        amber: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-200',
        slate: 'bg-slate-100 text-slate-700 dark:bg-neutral-800 dark:text-slate-300',
        indigo: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/15 dark:text-indigo-200',
    }

    return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold ${tones[tone]}`}>{children}</span>
}

function timePart(value) {
    return String(value ?? '').slice(0, 5)
}

function formatTime(value) {
    const [hours, minutes] = timePart(value).split(':').map(Number)
    if (!Number.isFinite(hours) || !Number.isFinite(minutes)) return value || '—'

    return new Intl.DateTimeFormat('es-MX', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    }).format(new Date(2026, 0, 1, hours, minutes))
}

function scheduleDescription(startsAt, endsAt) {
    const start = timePart(startsAt)
    const end = timePart(endsAt)
    if (!start || !end || start === end) return 'Indica dos horas diferentes.'

    const crossesMidnight = start > end
    return crossesMidnight
        ? `Desde las ${formatTime(start)} hasta las ${formatTime(end)} del día siguiente.`
        : `Desde las ${formatTime(start)} hasta las ${formatTime(end)}.`
}

function percentagePreview(price, percentage) {
    const numericPrice = Number(price)
    const numericPercentage = Number(percentage)
    if (!Number.isFinite(numericPrice) || numericPrice <= 0 || !Number.isFinite(numericPercentage)) return null

    return Math.round(numericPrice * (1 - numericPercentage / 100) * 100) / 100
}

function emptyForm(defaultTimezone) {
    return {
        meli_account_id: '',
        brand_group_id: '',
        discount_percentage: '10',
        starts_at: '20:00',
        ends_at: '06:00',
        timezone: defaultTimezone,
        active: true,
    }
}

export default function ScheduledDiscounts({
    accounts = [],
    selectedAccountId = null,
    rules = [],
    brandOptions = [],
    defaultTimezone = 'America/Hermosillo',
}) {
    const [editor, setEditor] = useState(null)
    const [examplePrice, setExamplePrice] = useState('2000')
    const form = useForm(emptyForm(defaultTimezone))

    const selectedAccount = accounts.find((account) => Number(account.id) === Number(selectedAccountId))
    const preview = useMemo(
        () => percentagePreview(examplePrice, form.data.discount_percentage),
        [examplePrice, form.data.discount_percentage],
    )

    const changeAccount = (accountId) => {
        router.get('/meli-price-manager/scheduled-discounts', { account: accountId }, { preserveState: true, preserveScroll: true })
    }

    const openCreate = () => {
        form.clearErrors()
        form.setData({ ...emptyForm(defaultTimezone), meli_account_id: String(selectedAccountId ?? '') })
        setEditor({ mode: 'create' })
    }

    const openEdit = (rule) => {
        form.clearErrors()
        form.setData({
            meli_account_id: String(rule.meli_account_id),
            brand_group_id: String(rule.brand_group_id),
            discount_percentage: String(rule.discount_percentage),
            starts_at: timePart(rule.starts_at),
            ends_at: timePart(rule.ends_at),
            timezone: rule.timezone,
            active: Boolean(rule.active),
        })
        setEditor({ mode: 'edit', id: rule.id, brandName: rule.brand_group?.name })
    }

    const closeEditor = () => {
        if (form.processing) return
        setEditor(null)
        form.clearErrors()
    }

    const submit = (event) => {
        event.preventDefault()
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setEditor(null)
                form.reset()
            },
        }

        if (editor?.mode === 'edit') {
            form.put(`/meli-price-manager/scheduled-discounts/${editor.id}`, options)
        } else {
            form.post('/meli-price-manager/scheduled-discounts', options)
        }
    }

    const toggleRule = (rule) => {
        const nextActive = !rule.active
        const message = nextActive
            ? '¿Habilitar esta regla? La configuración quedará lista, pero todavía no se modificarán precios en Mercado Libre.'
            : '¿Deshabilitar esta regla? No se restaurará ningún precio desde esta pantalla.'
        if (!window.confirm(message)) return

        router.patch(
            `/meli-price-manager/scheduled-discounts/${rule.id}/status`,
            { active: nextActive },
            { preserveScroll: true },
        )
    }

    const activeBrands = editor?.mode === 'edit' && editor.brandName
        ? brandOptions.some((brand) => Number(brand.id) === Number(form.data.brand_group_id))
            ? brandOptions
            : [...brandOptions, { id: form.data.brand_group_id, name: editor.brandName, eligible_items_count: null }]
        : brandOptions

    return (
        <AppShell title="Meli Price Manager">
            <Head title="Descuentos programados · Meli Price Manager" />
            <div className="space-y-6">
                <header className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
                    <div>
                        <p className="text-xs font-bold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Meli Price Manager</p>
                        <h1 className="mt-1 text-3xl font-bold text-slate-950 dark:text-white">Descuentos programados</h1>
                        <p className="mt-2 max-w-3xl text-sm text-slate-500 dark:text-slate-400">
                            Configura ventanas de descuento por marca para publicaciones Beauty elegibles.
                        </p>
                    </div>
                    <button type="button" onClick={openCreate} disabled={!selectedAccountId || brandOptions.length === 0} className={primaryButton}>
                        Nueva regla
                    </button>
                </header>

                <section className="rounded-2xl border border-sky-200 bg-sky-50 p-4 dark:border-sky-500/20 dark:bg-sky-500/5">
                    <p className="text-sm font-semibold text-sky-900 dark:text-sky-100">
                        Las reglas pueden configurarse desde esta pantalla. La ejecución automática de precios todavía no está habilitada.
                    </p>
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,24rem)] lg:items-end">
                        <div>
                            <h2 className="font-semibold text-slate-900 dark:text-white">Cuenta de Mercado Libre</h2>
                            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                Las marcas y reglas corresponden únicamente a la cuenta seleccionada.
                            </p>
                        </div>
                        <select value={selectedAccountId ?? ''} onChange={(event) => changeAccount(event.target.value)} disabled={!accounts.length} className={fieldClass}>
                            {!accounts.length && <option value="">Sin cuentas vinculadas</option>}
                            {accounts.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.nickname || `Cuenta #${account.id}`} · {account.meli_user_id}{account.is_default ? ' · predeterminada' : ''}
                                </option>
                            ))}
                        </select>
                    </div>
                </section>

                {editor && (
                    <section className="rounded-2xl border border-indigo-200 bg-indigo-50/60 p-5 dark:border-indigo-500/20 dark:bg-indigo-500/5">
                        <div className="mb-4 flex items-start justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-bold text-slate-950 dark:text-white">{editor.mode === 'edit' ? 'Editar regla' : 'Nueva regla'}</h2>
                                <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                    {editor.mode === 'edit' ? 'La cuenta y la marca identifican la regla y no se cambian durante la edición.' : 'Solo aparecen marcas Beauty con publicaciones elegibles en esta cuenta.'}
                                </p>
                            </div>
                            <button type="button" onClick={closeEditor} className={secondaryButton}>Cancelar</button>
                        </div>
                        <form onSubmit={submit} className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <label>
                                <span className="mb-1 block text-sm font-semibold">Cuenta</span>
                                <select value={form.data.meli_account_id} onChange={(event) => form.setData('meli_account_id', event.target.value)} disabled={editor.mode === 'edit'} className={fieldClass}>
                                    {accounts.map((account) => <option key={account.id} value={account.id}>{account.nickname || `Cuenta #${account.id}`}</option>)}
                                </select>
                                <ErrorText message={form.errors.meli_account_id} />
                            </label>
                            <label>
                                <span className="mb-1 block text-sm font-semibold">Marca Beauty</span>
                                <select value={form.data.brand_group_id} onChange={(event) => form.setData('brand_group_id', event.target.value)} disabled={editor.mode === 'edit'} className={fieldClass}>
                                    <option value="">Selecciona una marca</option>
                                    {activeBrands.map((brand) => <option key={brand.id} value={brand.id}>{brand.name}{brand.eligible_items_count !== null ? ` · ${brand.eligible_items_count} publicaciones` : ''}</option>)}
                                </select>
                                <ErrorText message={form.errors.brand_group_id} />
                            </label>
                            <label>
                                <span className="mb-1 block text-sm font-semibold">Descuento (%)</span>
                                <div className="relative"><input type="number" min="0.01" max="99.99" step="0.01" value={form.data.discount_percentage} onChange={(event) => form.setData('discount_percentage', event.target.value)} className={`${fieldClass} pr-9`} required /><span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm font-bold text-slate-400">%</span></div>
                                <ErrorText message={form.errors.discount_percentage} />
                            </label>
                            <label className="flex items-center gap-2 self-end rounded-xl border border-slate-200 px-3 py-2 dark:border-neutral-700">
                                <input type="checkbox" checked={Boolean(form.data.active)} onChange={(event) => form.setData('active', event.target.checked)} />
                                <span className="text-sm font-semibold">Regla habilitada</span>
                            </label>
                            <label>
                                <span className="mb-1 block text-sm font-semibold">Hora de inicio</span>
                                <input type="time" value={form.data.starts_at} onChange={(event) => form.setData('starts_at', event.target.value)} className={fieldClass} required />
                                <ErrorText message={form.errors.starts_at} />
                            </label>
                            <label>
                                <span className="mb-1 block text-sm font-semibold">Hora de finalización</span>
                                <input type="time" value={form.data.ends_at} onChange={(event) => form.setData('ends_at', event.target.value)} className={fieldClass} required />
                                <ErrorText message={form.errors.ends_at} />
                            </label>
                            <label>
                                <span className="mb-1 block text-sm font-semibold">Zona horaria</span>
                                <input value={form.data.timezone} onChange={(event) => form.setData('timezone', event.target.value)} className={fieldClass} required />
                                <ErrorText message={form.errors.timezone} />
                            </label>
                            <div className="rounded-xl border border-indigo-200 bg-white/70 p-3 text-sm dark:border-indigo-500/20 dark:bg-neutral-950/50">
                                <p className="font-semibold text-indigo-900 dark:text-indigo-200">Ventana horaria</p>
                                <p className="mt-1 text-slate-600 dark:text-slate-300">{scheduleDescription(form.data.starts_at, form.data.ends_at)}</p>
                            </div>
                            <div className="md:col-span-2 xl:col-span-4 rounded-xl border border-slate-200 bg-white/70 p-4 dark:border-neutral-700 dark:bg-neutral-950/50">
                                <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                                    <div><p className="font-semibold">Previsualización ilustrativa</p><p className="mt-1 text-xs text-slate-500">No representa el precio real de una publicación y no se guarda.</p></div>
                                    <label className="w-full md:w-56"><span className="mb-1 block text-xs font-semibold">Ejemplo de precio</span><input type="number" min="0.01" step="0.01" value={examplePrice} onChange={(event) => setExamplePrice(event.target.value)} className={fieldClass} /></label>
                                </div>
                                {preview !== null && <div className="mt-3 flex flex-wrap items-center gap-3 text-sm"><span>Precio ejemplo: <b>${Number(examplePrice).toLocaleString('es-MX', { minimumFractionDigits: 2 })}</b></span><span className="text-indigo-500">−</span><Badge tone="amber">{form.data.discount_percentage || 0}% de descuento</Badge><span className="text-indigo-500">→</span><span>Precio promocional ilustrativo: <b className="text-emerald-700 dark:text-emerald-300">${preview.toLocaleString('es-MX', { minimumFractionDigits: 2 })}</b></span></div>}
                            </div>
                            <div className="md:col-span-2 xl:col-span-4 flex justify-end"><button disabled={form.processing || !form.data.brand_group_id} className={primaryButton}>{form.processing ? 'Guardando...' : 'Guardar regla'}</button></div>
                        </form>
                    </section>
                )}

                <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="border-b border-slate-200 px-4 py-4 dark:border-neutral-800">
                        <h2 className="font-bold text-slate-950 dark:text-white">Reglas configuradas</h2>
                        <p className="mt-1 text-sm text-slate-500">Los conteos usan la elegibilidad Beauty del backend. No indican precios aplicados.</p>
                    </div>
                    {!accounts.length && <div className="p-10 text-center text-sm text-slate-500">No hay cuentas de Mercado Libre vinculadas.</div>}
                    {accounts.length > 0 && !brandOptions.length && <div className="p-10 text-center"><h3 className="font-bold">No hay marcas Beauty elegibles para esta cuenta.</h3><p className="mt-1 text-sm text-slate-500">La marca debe estar activa y tener publicaciones Beauty categorizadas.</p></div>}
                    {accounts.length > 0 && brandOptions.length > 0 && !rules.length && <div className="p-10 text-center"><h3 className="font-bold">No hay descuentos programados.</h3><p className="mt-1 text-sm text-slate-500">Configura una marca Beauty para crear la primera regla.</p><button type="button" onClick={openCreate} className={`${primaryButton} mt-4`}>Crear primera regla</button></div>}
                    {rules.length > 0 && <div className="overflow-x-auto"><table className="min-w-[920px] w-full text-left text-sm"><thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-neutral-950"><tr><th className="px-4 py-3">Marca</th><th className="px-4 py-3">Descuento</th><th className="px-4 py-3">Horario</th><th className="px-4 py-3">Estado de regla</th><th className="px-4 py-3">Ventana horaria</th><th className="px-4 py-3">Productos Beauty</th><th className="px-4 py-3">Acciones</th></tr></thead><tbody className="divide-y divide-slate-100 dark:divide-neutral-800">{rules.map((rule) => <tr key={rule.id}><td className="px-4 py-4"><p className="font-bold">{rule.brand_group?.name || 'Marca no disponible'}</p><p className="mt-1 text-xs text-slate-500">{selectedAccount?.nickname || `Cuenta #${rule.meli_account_id}`}</p></td><td className="px-4 py-4 font-bold text-indigo-700 dark:text-indigo-300">{Number(rule.discount_percentage).toLocaleString('es-MX')}%</td><td className="px-4 py-4"><p className="font-semibold">{timePart(rule.starts_at)} - {timePart(rule.ends_at)}</p><p className="mt-1 text-xs text-slate-500">{rule.timezone}</p></td><td className="px-4 py-4"><Badge tone={rule.active ? 'green' : 'slate'}>{rule.active ? 'Habilitada' : 'Deshabilitada'}</Badge></td><td className="px-4 py-4"><Badge tone={rule.active && rule.window_active ? 'amber' : 'slate'}>{rule.active && rule.window_active ? 'En horario' : 'Fuera de horario'}</Badge></td><td className="px-4 py-4"><p className="font-bold">{rule.eligible_items_count}</p><p className="text-xs text-slate-500">publicaciones elegibles</p></td><td className="px-4 py-4"><div className="flex flex-wrap gap-2"><button type="button" onClick={() => openEdit(rule)} className={secondaryButton}>Editar</button><button type="button" onClick={() => toggleRule(rule)} className={secondaryButton}>{rule.active ? 'Deshabilitar' : 'Habilitar'}</button></div></td></tr>)}</tbody></table></div>}
                </section>
            </div>
        </AppShell>
    )
}
