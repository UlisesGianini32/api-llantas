import { Link } from '@inertiajs/react'
import AutopartesLayout from '@/Components/Autopartes/AutopartesLayout'
import { branchBaseUrl } from '@/lib/autopartesCapabilities'

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

export default AutopartesLayout
