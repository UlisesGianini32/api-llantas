import AppShell from '@/Components/layout/AppShell'
import { Link, router } from '@inertiajs/react'
import { useEffect, useMemo, useState } from 'react'

function date(value) {
    if (!value) return '—'

    const parsedDate = new Date(value)

    if (Number.isNaN(parsedDate.getTime())) {
        return value
    }

    return new Intl.DateTimeFormat('es-MX', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(parsedDate)
}

function isRecent(value) {
    if (!value) return false

    const parsedDate = new Date(value)

    if (Number.isNaN(parsedDate.getTime())) {
        return false
    }

    const twentyFourHours = 24 * 60 * 60 * 1000

    return Date.now() - parsedDate.getTime() <= twentyFourHours
}

function ActionButton({
    children,
    danger = false,
    secondary = false,
    ...props
}) {
    let classes =
        'bg-indigo-600 text-white hover:bg-indigo-700 disabled:hover:bg-indigo-600'

    if (danger) {
        classes =
            'bg-rose-600 text-white hover:bg-rose-700 disabled:hover:bg-rose-600'
    }

    if (secondary) {
        classes =
            'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200 dark:hover:bg-neutral-800'
    }

    return (
        <button
            type="button"
            className={`rounded-xl px-3 py-2 text-xs font-bold transition disabled:cursor-not-allowed disabled:opacity-50 ${classes}`}
            {...props}
        >
            {children}
        </button>
    )
}

function SeverityBadge({ severity }) {
    const styles = {
        critical:
            'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-300',
        high:
            'bg-orange-100 text-orange-800 dark:bg-orange-500/15 dark:text-orange-300',
        medium:
            'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
        low:
            'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
    }

    const labels = {
        critical: 'Crítico',
        high: 'Alto',
        medium: 'Medio',
        low: 'Bajo',
    }

    return (
        <span
            className={`rounded-full px-2.5 py-1 text-xs font-bold ${
                styles[severity] ?? styles.medium
            }`}
        >
            Riesgo {labels[severity] ?? 'Medio'}
        </span>
    )
}

function BooleanBadge({ value, positiveText, negativeText }) {
    return value ? (
        <span className="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-bold text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300">
            {positiveText}
        </span>
    ) : (
        <span className="rounded-full bg-rose-100 px-2.5 py-1 text-xs font-bold text-rose-800 dark:bg-rose-500/15 dark:text-rose-300">
            {negativeText}
        </span>
    )
}

function DiagnosisItem({ label, children }) {
    return (
        <div className="rounded-2xl bg-slate-50 p-4 dark:bg-neutral-950">
            <p className="text-xs font-bold uppercase tracking-wide text-slate-500">
                {label}
            </p>

            <div className="mt-2 text-sm leading-6 text-slate-700 dark:text-slate-200">
                {children}
            </div>
        </div>
    )
}

function JobDetailsModal({ job, onClose }) {
    useEffect(() => {
        if (!job) return undefined

        const closeWithEscape = (event) => {
            if (event.key === 'Escape') {
                onClose()
            }
        }

        document.addEventListener('keydown', closeWithEscape)
        document.body.style.overflow = 'hidden'

        return () => {
            document.removeEventListener('keydown', closeWithEscape)
            document.body.style.overflow = ''
        }
    }, [job, onClose])

    if (!job) return null

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4 backdrop-blur-sm"
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) {
                    onClose()
                }
            }}
        >
            <div className="flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl dark:bg-neutral-900">
                <div className="flex items-start justify-between gap-4 border-b border-slate-200 p-5 dark:border-neutral-800">
                    <div className="min-w-0">
                        <p className="text-xs font-bold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">
                            Detalles técnicos
                        </p>

                        <h2 className="mt-2 truncate text-xl font-black">
                            {job.name}
                        </h2>

                        <p className="mt-1 text-xs text-slate-500">
                            {job.connection} · {job.queue} · {date(job.failed_at)}
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-bold hover:bg-slate-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                    >
                        Cerrar
                    </button>
                </div>

                <div className="overflow-y-auto p-5">
                    <div className="grid gap-4 md:grid-cols-2">
                        <DiagnosisItem label="Tipo de error">
                            {job.diagnosis.type}
                        </DiagnosisItem>

                        <DiagnosisItem label="Regla detectada">
                            {job.diagnosis.matched_rule}
                        </DiagnosisItem>
                    </div>

                    <div className="mt-5">
                        <p className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">
                            Excepción completa
                        </p>

                        <pre className="max-h-[55vh] overflow-auto whitespace-pre-wrap break-words rounded-2xl bg-slate-950 p-4 text-xs leading-6 text-slate-200">
                            {job.exception}
                        </pre>
                    </div>
                </div>
            </div>
        </div>
    )
}

function FailedJobCard({
    job,
    busy,
    onRetry,
    onDelete,
    onDetails,
}) {
    const diagnosis = job.diagnosis
    const canRetry = diagnosis.retry_safe

    return (
        <article className="p-5">
            <div className="flex flex-col justify-between gap-5 xl:flex-row">
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h3 className="break-all text-lg font-black">
                            {job.name}
                        </h3>

                        <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700 dark:bg-neutral-800 dark:text-slate-200">
                            {job.queue}
                        </span>

                        <SeverityBadge severity={diagnosis.severity} />

                        {isRecent(job.failed_at) && (
                            <span className="rounded-full bg-indigo-100 px-2.5 py-1 text-xs font-bold text-indigo-800 dark:bg-indigo-500/15 dark:text-indigo-300">
                                Error reciente
                            </span>
                        )}

                        {diagnosis.integration && (
                            <span className="rounded-full bg-sky-100 px-2.5 py-1 text-xs font-bold text-sky-800 dark:bg-sky-500/15 dark:text-sky-300">
                                {diagnosis.integration}
                            </span>
                        )}
                    </div>

                    <p className="mt-1 text-xs text-slate-500">
                        {job.connection} · {date(job.failed_at)}
                    </p>

                    <div className="mt-4 rounded-2xl border border-slate-200 p-4 dark:border-neutral-800">
                        <p className="text-base font-black">
                            {diagnosis.title}
                        </p>

                        <p className="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">
                            {diagnosis.cause}
                        </p>
                    </div>

                    <div className="mt-4 grid gap-4 lg:grid-cols-2">
                        <DiagnosisItem label="Recomendación">
                            {diagnosis.recommendation}
                        </DiagnosisItem>

                        <DiagnosisItem label="Reintento">
                            <div className="flex flex-wrap gap-2">
                                <BooleanBadge
                                    value={diagnosis.retry_recommended}
                                    positiveText="Recomendado"
                                    negativeText="No recomendado"
                                />

                                <BooleanBadge
                                    value={diagnosis.retry_safe}
                                    positiveText="Permitido"
                                    negativeText="Bloqueado"
                                />
                            </div>
                        </DiagnosisItem>
                    </div>

                    <div className="mt-4 rounded-2xl bg-slate-950 p-4">
                        <p className="text-xs font-bold uppercase tracking-wide text-slate-400">
                            Resumen técnico
                        </p>

                        <p className="mt-2 break-words text-xs leading-6 text-slate-200">
                            {job.exception_preview}
                        </p>
                    </div>
                </div>

                <div className="flex shrink-0 flex-wrap content-start gap-2 xl:max-w-48 xl:flex-col">
                    <ActionButton
                        disabled={busy !== null || !canRetry}
                        onClick={() => onRetry(job)}
                    >
                        {canRetry
                            ? 'Reintentar'
                            : 'Reintento bloqueado'}
                    </ActionButton>

                    <ActionButton
                        secondary
                        disabled={busy !== null}
                        onClick={() => onDetails(job)}
                    >
                        Ver detalles
                    </ActionButton>

                    <ActionButton
                        danger
                        disabled={busy !== null}
                        onClick={() => onDelete(job)}
                    >
                        Eliminar
                    </ActionButton>
                </div>
            </div>
        </article>
    )
}

export default function Queues({
    stats,
    pendingJobs = [],
    failedJobs = [],
}) {
    const [busy, setBusy] = useState(null)
    const [selectedJob, setSelectedJob] = useState(null)
    const [filter, setFilter] = useState('all')

    const filteredJobs = useMemo(() => {
        if (filter === 'retryable') {
            return failedJobs.filter(
                (job) => job.diagnosis.retry_safe
            )
        }

        if (filter === 'blocked') {
            return failedJobs.filter(
                (job) => !job.diagnosis.retry_safe
            )
        }

        if (filter === 'recent') {
            return failedJobs.filter(
                (job) => isRecent(job.failed_at)
            )
        }

        return failedJobs
    }, [failedJobs, filter])

    const post = (url, key) => {
        setBusy(key)

        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setBusy(null),
            },
        )
    }

    const destroy = (job) => {
        const confirmed = window.confirm(
            `¿Seguro que deseas eliminar el trabajo fallido "${job.name}"?\n\nEsta acción elimina el registro del error, pero no corrige la causa que lo provocó.`,
        )

        if (!confirmed) return

        setBusy(`delete-${job.uuid}`)

        router.delete(`/sistema/colas/${job.uuid}`, {
            preserveScroll: true,
            onFinish: () => setBusy(null),
        })
    }

    const retry = (job) => {
        if (!job.diagnosis.retry_safe) {
            window.alert(
                'Este trabajo está bloqueado porque primero debe corregirse la causa del error.',
            )

            return
        }

        const confirmed = window.confirm(
            `¿Reintentar el trabajo "${job.name}"?\n\nDiagnóstico: ${job.diagnosis.title}`,
        )

        if (!confirmed) return

        post(
            `/sistema/colas/${job.uuid}/retry`,
            `retry-${job.uuid}`,
        )
    }

    const retryAll = () => {
        const confirmed = window.confirm(
            '¿Reintentar todos los trabajos permitidos?\n\nEl servidor volverá a procesarlos y la operación puede consumir recursos. Los trabajos peligrosos serán bloqueados automáticamente.',
        )

        if (!confirmed) return

        post('/sistema/colas/retry-all', 'retry-all')
    }

    const flush = () => {
        const firstConfirmation = window.confirm(
            '¿Deseas vaciar definitivamente todos los trabajos fallidos?',
        )

        if (!firstConfirmation) return

        const secondConfirmation = window.confirm(
            'Esta acción eliminará todo el historial visible de trabajos fallidos. ¿Confirmas que deseas continuar?',
        )

        if (!secondConfirmation) return

        post('/sistema/colas/flush', 'flush')
    }

    const filters = [
        {
            key: 'all',
            label: `Todos (${failedJobs.length})`,
        },
        {
            key: 'retryable',
            label: `Permitidos (${
                failedJobs.filter(
                    (job) => job.diagnosis.retry_safe
                ).length
            })`,
        },
        {
            key: 'blocked',
            label: `Bloqueados (${
                failedJobs.filter(
                    (job) => !job.diagnosis.retry_safe
                ).length
            })`,
        },
        {
            key: 'recent',
            label: `Recientes (${
                failedJobs.filter(
                    (job) => isRecent(job.failed_at)
                ).length
            })`,
        },
    ]

    return (
        <AppShell title="Colas del sistema">
            <div className="mx-auto max-w-7xl space-y-6">
                <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">
                                Sistema
                            </p>

                            <h1 className="mt-2 text-3xl font-black">
                                Colas y trabajos fallidos
                            </h1>

                            <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-500">
                                El sistema analiza cada excepción, explica
                                su causa probable y bloquea los reintentos
                                que podrían volver a fallar.
                            </p>
                        </div>

                        <div className="flex flex-wrap gap-2">
                            <Link
                                href="/sistema/estado"
                                className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold transition hover:bg-slate-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                            >
                                Estado general
                            </Link>

                            <ActionButton
                                disabled={
                                    busy !== null ||
                                    stats.failed === 0
                                }
                                onClick={retryAll}
                            >
                                Reintentar permitidos
                            </ActionButton>

                            <ActionButton
                                danger
                                disabled={
                                    busy !== null ||
                                    stats.failed === 0
                                }
                                onClick={flush}
                            >
                                Vaciar fallidos
                            </ActionButton>
                        </div>
                    </div>

                    <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        {[
                            ['Conexión', stats.connection],
                            ['Pendientes', stats.pending],
                            ['Fallidos', stats.failed],
                            [
                                'Bloqueados visibles',
                                stats.blocked_visible,
                            ],
                        ].map(([label, value]) => (
                            <div
                                key={label}
                                className="rounded-2xl bg-slate-50 p-4 dark:bg-neutral-950"
                            >
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    {label}
                                </p>

                                <p className="mt-2 break-words text-2xl font-black">
                                    {value}
                                </p>
                            </div>
                        ))}
                    </div>
                </section>

                <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="border-b border-slate-200 p-5 dark:border-neutral-800">
                        <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                            <div>
                                <h2 className="text-lg font-black">
                                    Trabajos fallidos
                                </h2>

                                <p className="mt-1 text-xs text-slate-500">
                                    Mostrando los últimos 100 registros.
                                </p>
                            </div>

                            <div className="flex flex-wrap gap-2">
                                {filters.map((item) => (
                                    <button
                                        key={item.key}
                                        type="button"
                                        onClick={() =>
                                            setFilter(item.key)
                                        }
                                        className={`rounded-xl px-3 py-2 text-xs font-bold transition ${
                                            filter === item.key
                                                ? 'bg-indigo-600 text-white'
                                                : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200 dark:hover:bg-neutral-800'
                                        }`}
                                    >
                                        {item.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>

                    {failedJobs.length === 0 ? (
                        <p className="p-6 text-sm text-slate-500">
                            No hay trabajos fallidos.
                        </p>
                    ) : filteredJobs.length === 0 ? (
                        <p className="p-6 text-sm text-slate-500">
                            No hay trabajos que coincidan con este filtro.
                        </p>
                    ) : (
                        <div className="divide-y divide-slate-200 dark:divide-neutral-800">
                            {filteredJobs.map((job) => (
                                <FailedJobCard
                                    key={job.uuid}
                                    job={job}
                                    busy={busy}
                                    onRetry={retry}
                                    onDelete={destroy}
                                    onDetails={setSelectedJob}
                                />
                            ))}
                        </div>
                    )}
                </section>

                <section className="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="border-b border-slate-200 p-5 dark:border-neutral-800">
                        <h2 className="text-lg font-black">
                            Pendientes recientes
                        </h2>

                        <p className="mt-1 text-xs text-slate-500">
                            Se muestran hasta 100 trabajos pendientes.
                        </p>
                    </div>

                    {pendingJobs.length === 0 ? (
                        <p className="p-6 text-sm text-slate-500">
                            No hay trabajos pendientes.
                        </p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500 dark:bg-neutral-950">
                                    <tr>
                                        <th className="px-5 py-3">
                                            ID
                                        </th>

                                        <th className="px-5 py-3">
                                            Cola
                                        </th>

                                        <th className="px-5 py-3">
                                            Intentos
                                        </th>

                                        <th className="px-5 py-3">
                                            Creado
                                        </th>

                                        <th className="px-5 py-3">
                                            Disponible
                                        </th>

                                        <th className="px-5 py-3">
                                            Reservado
                                        </th>
                                    </tr>
                                </thead>

                                <tbody className="divide-y divide-slate-200 dark:divide-neutral-800">
                                    {pendingJobs.map((job) => (
                                        <tr key={job.id}>
                                            <td className="px-5 py-3 font-semibold">
                                                {job.id}
                                            </td>

                                            <td className="px-5 py-3">
                                                {job.queue}
                                            </td>

                                            <td className="px-5 py-3">
                                                {job.attempts}
                                            </td>

                                            <td className="px-5 py-3">
                                                {date(job.created_at)}
                                            </td>

                                            <td className="px-5 py-3">
                                                {date(
                                                    job.available_at,
                                                )}
                                            </td>

                                            <td className="px-5 py-3">
                                                {date(
                                                    job.reserved_at,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </div>

            <JobDetailsModal
                job={selectedJob}
                onClose={() => setSelectedJob(null)}
            />
        </AppShell>
    )
}