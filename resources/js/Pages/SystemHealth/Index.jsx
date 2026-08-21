import AppShell from '@/Components/layout/AppShell'
import { router } from '@inertiajs/react'
import { useMemo, useState } from 'react'

const styles = {
    ok: { label: 'Correcto', dot: 'bg-emerald-500', badge: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300', border: 'border-emerald-200 dark:border-emerald-900/60' },
    warning: { label: 'Advertencia', dot: 'bg-amber-500', badge: 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300', border: 'border-amber-200 dark:border-amber-900/60' },
    error: { label: 'Error', dot: 'bg-rose-500', badge: 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300', border: 'border-rose-200 dark:border-rose-900/60' },
    info: { label: 'Información', dot: 'bg-sky-500', badge: 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300', border: 'border-sky-200 dark:border-sky-900/60' },
}

function bytes(value) {
    if (value === null || value === undefined) return '—'
    const units = ['B', 'KB', 'MB', 'GB', 'TB']
    let amount = Number(value)
    let index = 0
    while (amount >= 1024 && index < units.length - 1) { amount /= 1024; index += 1 }
    return `${amount.toFixed(index >= 3 ? 1 : 0)} ${units[index]}`
}

function date(value) {
    if (!value) return '—'
    try { return new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) } catch { return value }
}

const labels = {
    driver: 'Controlador', latency_ms: 'Latencia', store: 'Almacén', connection: 'Conexión', pending: 'Pendientes', failed: 'Fallidos',
    last_run_at: 'Última ejecución', minutes_ago: 'Minutos transcurridos', total_bytes: 'Capacidad total', used_bytes: 'Espacio utilizado',
    free_bytes: 'Espacio disponible', used_percent: 'Porcentaje utilizado', environment: 'Entorno', debug: 'Modo debug', laravel_version: 'Laravel',
    php_version: 'PHP', orders_last_24h: 'Pedidos actualizados (24 h)', publications_synced_last_24h: 'Publicaciones sincronizadas (24 h)',
    messages_last_24h: 'Conversaciones actualizadas (24 h)', products: 'Productos SYSCOM', queue_records: 'Registros SYSCOM', branch: 'Sucursal',
    orders_from_meli_enabled: 'Pedidos ML → SYSCOM', tires: 'Llantas', out_of_stock: 'Sin existencia', last_import_at: 'Última importación',
    file_size_bytes: 'Tamaño del log', errors_in_tail: 'Errores recientes', warnings_in_tail: 'Advertencias recientes', error: 'Detalle',
}

function format(key, value) {
    if (value === null || value === undefined || value === '') return '—'
    if (typeof value === 'boolean') return value ? 'Sí' : 'No'
    if (['total_bytes', 'used_bytes', 'free_bytes', 'file_size_bytes'].includes(key)) return bytes(value)
    if (key.endsWith('_at')) return date(value)
    if (key === 'latency_ms') return `${value} ms`
    if (key === 'used_percent') return `${value}%`
    return typeof value === 'object' ? null : String(value)
}

function Accounts({ accounts = [] }) {
    if (!accounts.length) return null
    return <div className="mt-4 space-y-2">{accounts.map((account) => (
        <div key={account.id} className="rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm dark:border-neutral-700 dark:bg-neutral-950">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="font-semibold text-slate-900 dark:text-white">{account.nickname || `Cuenta ${account.meli_user_id}`}{account.is_default ? ' · Principal' : ''}</p>
                    <p className="text-xs text-slate-500 dark:text-slate-400">ID ML: {account.meli_user_id}</p>
                </div>
                <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${account.has_access_token && account.has_refresh_token ? styles.ok.badge : styles.error.badge}`}>
                    {account.has_access_token && account.has_refresh_token ? 'Credenciales completas' : 'Credenciales incompletas'}
                </span>
            </div>
            <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Vencimiento: {date(account.expires_at)}</p>
        </div>
    ))}</div>
}

function Card({ check }) {
    const style = styles[check.status] ?? styles.info
    const details = Object.entries(check.details ?? {}).filter(([key, value]) => key !== 'accounts' && typeof value !== 'object')
    return <article className={`rounded-3xl border bg-white p-5 shadow-sm dark:bg-neutral-900 ${style.border}`}>
        <div className="flex items-start justify-between gap-4">
            <div className="min-w-0">
                <div className="flex items-center gap-2"><span className={`h-2.5 w-2.5 rounded-full ${style.dot}`} /><h2 className="font-bold">{check.name}</h2></div>
                <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">{check.message}</p>
            </div>
            <span className={`shrink-0 rounded-full px-3 py-1 text-xs font-semibold ${style.badge}`}>{style.label}</span>
        </div>
        {details.length > 0 && <dl className="mt-4 divide-y divide-slate-100 rounded-2xl bg-slate-50 px-4 dark:divide-neutral-800 dark:bg-neutral-950">
            {details.map(([key, value]) => <div key={key} className="flex items-center justify-between gap-4 py-2.5 text-sm">
                <dt className="text-slate-500 dark:text-slate-400">{labels[key] ?? key.replaceAll('_', ' ')}</dt>
                <dd className="max-w-[60%] break-words text-right font-medium">{format(key, value)}</dd>
            </div>)}
        </dl>}
        <Accounts accounts={check.details?.accounts} />
    </article>
}

export default function SystemHealthIndex({ generatedAt, summary, checks }) {
    const [refreshing, setRefreshing] = useState(false)
    const overall = useMemo(() => summary.error > 0 ? ['Requiere atención', 'error'] : summary.warning > 0 ? ['Con advertencias', 'warning'] : ['Todo correcto', 'ok'], [summary])
    const refresh = () => {
        setRefreshing(true)
        router.reload({ only: ['generatedAt', 'summary', 'checks'], preserveScroll: true, onFinish: () => setRefreshing(false) })
    }

    return <AppShell title="Estado del sistema"><div className="mx-auto max-w-7xl space-y-6">
        <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
            <div className="p-6 sm:p-8">
                <div className="flex flex-col justify-between gap-5 lg:flex-row lg:items-center">
                    <div><p className="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Diagnóstico</p><h1 className="mt-2 text-2xl font-black sm:text-3xl">Salud general del sistema</h1><p className="mt-2 text-sm text-slate-600 dark:text-slate-300">Laravel, servidor, colas, inventario e integraciones.</p></div>
                    <button type="button" onClick={refresh} disabled={refreshing} className="rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-bold text-white hover:bg-indigo-700 disabled:opacity-60">{refreshing ? 'Actualizando…' : 'Actualizar diagnóstico'}</button>
                </div>
                <div className="mt-6 flex flex-wrap items-center gap-3"><span className={`rounded-full px-3 py-1.5 text-sm font-bold ${styles[overall[1]].badge}`}>{overall[0]}</span><span className="text-sm text-slate-500">Actualizado: {date(generatedAt)}</span></div>
            </div>
            <div className="grid grid-cols-2 border-t border-slate-200 sm:grid-cols-4 dark:border-neutral-800">
                {[['Correctos', summary.ok, 'text-emerald-600'], ['Advertencias', summary.warning, 'text-amber-600'], ['Errores', summary.error, 'text-rose-600'], ['Informativos', summary.info, 'text-sky-600']].map(([label, value, color]) => <div key={label} className="border-r border-slate-200 p-4 text-center last:border-r-0 dark:border-neutral-800"><p className={`text-2xl font-black ${color}`}>{value}</p><p className="mt-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p></div>)}
            </div>
        </section>
        <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{checks.map((check) => <Card key={check.key} check={check} />)}</section>
    </div></AppShell>
}
