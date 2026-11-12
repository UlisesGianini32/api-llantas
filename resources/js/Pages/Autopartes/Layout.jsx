import { Head, Link, usePage } from '@inertiajs/react'
import { branchBaseUrl, getAutopartesNavItems } from '@/lib/autopartesCapabilities'

export const field = 'rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 dark:border-neutral-600 dark:bg-neutral-900 dark:text-white'
export const button = 'inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50'
export const secondaryButton = 'inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200'
export const dangerButton = 'inline-flex items-center justify-center rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700 disabled:opacity-50'
export const card = 'rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-700 dark:bg-neutral-900'
export const baseUrl = branchBaseUrl
export const statusLabel = status => ({
    preview: 'Vista previa',
    queued: 'En cola',
    processing: 'Procesando',
    completed: 'Completada',
    failed: 'Interrumpida',
    draft: 'Borrador',
    confirmed: 'Confirmada',
    cancelled: 'Cancelada',
    pending: 'Pendiente de aprobación',
    approved: 'Aprobado',
    rejected: 'Rechazado',
}[status] || status)

export function IdentityBadge({ status }) {
    const map = {
        verified: { label: 'Etiqueta verificada', cls: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200' },
        ambiguous: { label: 'Ambigua · requiere verificación', cls: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200' },
        provisional: { label: 'Provisional catálogo', cls: 'bg-slate-100 text-slate-700 dark:bg-neutral-800 dark:text-neutral-300' },
    }
    const current = map[status] || map.provisional
    return <span className={`inline-block rounded px-2 py-0.5 text-xs font-medium ${current.cls}`}>{current.label}</span>
}

export function Pagination({ data }) {
    if (!data) return null
    return <div className="mt-4 flex items-center justify-between gap-3 text-sm">
        <span>{data.from || 0}–{data.to || 0} de {data.total || 0}</span>
        <div className="flex gap-3">
            {data.prev_page_url && <Link href={data.prev_page_url} preserveScroll className="font-medium text-indigo-600">← Anterior</Link>}
            {data.next_page_url && <Link href={data.next_page_url} preserveScroll className="font-medium text-indigo-600">Siguiente →</Link>}
        </div>
    </div>
}

export default function Layout({ branch, title, children }) {
    const { errors = {}, auth, flash = {}, capabilities = [] } = usePage().props
    const navItems = getAutopartesNavItems(branch, capabilities)

    return <main className="min-h-screen bg-slate-50 text-slate-900 dark:bg-neutral-950 dark:text-neutral-100">
        <Head title={`${title} · Autopartes ${branch.name}`} />
        <header className="border-b border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div className="mx-auto flex max-w-screen-2xl flex-wrap items-center justify-between gap-4 px-6 py-5">
                <div>
                    <p className="text-xs font-bold uppercase tracking-widest text-indigo-600">Autopartes · Sucursal {branch.name} ({branch.code})</p>
                    <h1 className="mt-1 text-2xl font-semibold">{title}</h1>
                </div>
                <div className="text-right text-sm">
                    <p className="font-medium">{auth?.user?.name}</p>
                    <Link href="/logout" method="post" as="button" className="text-slate-500 hover:text-slate-700">Cerrar sesión</Link>
                </div>
            </div>
            {navItems.length > 0 && (
                <nav className="mx-auto flex max-w-screen-2xl flex-wrap gap-2 border-t border-slate-100 px-6 py-2.5 text-sm dark:border-neutral-800">
                    {navItems.map(item => (
                        <Link
                            key={item.href}
                            href={item.href}
                            className="rounded-lg px-3 py-1.5 font-medium text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        >
                            {item.label}
                        </Link>
                    ))}
                </nav>
            )}
        </header>
        <section className="mx-auto max-w-screen-2xl space-y-5 px-6 py-6">
            <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                <strong>Aislamiento documental y comercial ({branch.code}):</strong> Las 21 columnas del Excel (<code>Qty</code>, <code>Retail</code>, <code>Ext. Retail</code>) permanecen inmutables como referencia documental. Las ventas en el POS de {branch.name} requieren turno abierto, vendedor autorizado, precio en MXN aprobado y existencias físicas disponibles en la sucursal, sin afectar Mercado Libre ni otros almacenes.
            </div>
            {flash?.success && <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{flash.success}</div>}
            {flash?.warning && <div role="alert" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">{flash.warning}</div>}
            {flash?.error && <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{flash.error}</div>}
            {Object.keys(errors).length > 0 && (
                <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                    {Object.entries(errors).map(([key, value]) => <p key={key}>{value}</p>)}
                </div>
            )}
            {children}
        </section>
    </main>
}
