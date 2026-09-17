import AppShell from '@/Components/layout/AppShell'
import { Link } from '@inertiajs/react'
import { useMemo, useState } from 'react'

const levelStyles = {
    ERROR: 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
    CRITICAL: 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
    WARNING: 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    INFO: 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
    DEBUG: 'bg-slate-100 text-slate-700 dark:bg-neutral-800 dark:text-slate-300',
}

function bytes(value) {
    const units = ['B', 'KB', 'MB', 'GB', 'TB']
    let current = Number(value || 0)
    let index = 0

    while (current >= 1024 && index < units.length - 1) {
        current /= 1024
        index += 1
    }

    return `${current.toFixed(index >= 3 ? 1 : 0)} ${units[index]}`
}

export default function Logs({ log }) {
    const [level, setLevel] = useState('ALL')
    const [search, setSearch] = useState('')

    const entries = useMemo(() => {
        return (log.entries ?? []).filter((entry) => {
            const matchesLevel = level === 'ALL' || entry.level === level
            const haystack = `${entry.message} ${entry.environment} ${entry.level}`.toLowerCase()
            return matchesLevel && haystack.includes(search.toLowerCase())
        })
    }, [log.entries, level, search])

    return (
        <AppShell title="Logs del sistema">
            <div className="mx-auto max-w-7xl space-y-6">
                <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">
                                Sistema
                            </p>
                            <h1 className="mt-2 text-3xl font-black">Logs recientes</h1>
                            <p className="mt-2 text-sm text-slate-500">
                                Se muestran hasta 100 registros tomados del último 1 MB del archivo.
                            </p>
                        </div>
                        <div className="flex gap-2">
                            <Link
                                href="/sistema/estado"
                                className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold dark:border-neutral-700"
                            >
                                Estado general
                            </Link>
                            <Link
                                href="/sistema/logs"
                                preserveScroll
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-bold text-white"
                            >
                                Actualizar
                            </Link>
                        </div>
                    </div>

                    <div className="mt-6 grid gap-4 md:grid-cols-[180px_1fr_auto]">
                        <select
                            value={level}
                            onChange={(event) => setLevel(event.target.value)}
                            className="rounded-xl border border-slate-200 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950"
                        >
                            <option value="ALL">Todos los niveles</option>
                            <option value="ERROR">Error</option>
                            <option value="CRITICAL">Critical</option>
                            <option value="WARNING">Warning</option>
                            <option value="INFO">Info</option>
                            <option value="DEBUG">Debug</option>
                        </select>
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Buscar en los mensajes"
                            className="rounded-xl border border-slate-200 bg-white px-3 py-2 dark:border-neutral-700 dark:bg-neutral-950"
                        />
                        <div className="rounded-xl bg-slate-50 px-4 py-2 text-sm dark:bg-neutral-950">
                            Tamaño: <strong>{bytes(log.size)}</strong>
                        </div>
                    </div>
                </section>

                <section className="space-y-3">
                    {!log.exists && (
                        <div className="rounded-3xl border border-amber-200 bg-amber-50 p-5 text-amber-800">
                            No existe storage/logs/laravel.log.
                        </div>
                    )}

                    {log.exists && entries.length === 0 && (
                        <div className="rounded-3xl border border-slate-200 bg-white p-6 text-sm text-slate-500 dark:border-neutral-800 dark:bg-neutral-900">
                            No se encontraron registros con estos filtros.
                        </div>
                    )}

                    {entries.map((entry, index) => (
                        <article
                            key={`${entry.date}-${index}`}
                            className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="flex items-center gap-2">
                                    <span
                                        className={`rounded-full px-2.5 py-1 text-xs font-bold ${
                                            levelStyles[entry.level] ?? levelStyles.DEBUG
                                        }`}
                                    >
                                        {entry.level}
                                    </span>
                                    <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                        {entry.environment}
                                    </span>
                                </div>
                                <time className="text-xs text-slate-500">{entry.date}</time>
                            </div>
                            <pre className="mt-4 max-h-96 overflow-auto whitespace-pre-wrap rounded-2xl bg-slate-950 p-4 text-xs leading-6 text-slate-200">
                                {entry.message}
                            </pre>
                        </article>
                    ))}
                </section>
            </div>
        </AppShell>
    )
}
