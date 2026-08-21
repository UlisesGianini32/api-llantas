import AppShell from '@/Components/layout/AppShell'
import { Link, router } from '@inertiajs/react'
import { useState } from 'react'

const actions = [
    {
        key: 'cache-clear',
        title: 'Limpiar caché',
        description: 'Elimina la caché general de Laravel.',
        confirm: false,
    },
    {
        key: 'config-clear',
        title: 'Limpiar configuración',
        description: 'Elimina la caché de config para volver a leer los archivos.',
        confirm: false,
    },
    {
        key: 'route-clear',
        title: 'Limpiar rutas',
        description: 'Elimina la caché de rutas.',
        confirm: false,
    },
    {
        key: 'view-clear',
        title: 'Limpiar vistas',
        description: 'Elimina vistas Blade compiladas.',
        confirm: false,
    },
    {
        key: 'queue-restart',
        title: 'Reiniciar workers',
        description: 'Solicita un reinicio ordenado de los workers de cola.',
        confirm: true,
    },
    {
        key: 'schedule-run',
        title: 'Ejecutar scheduler',
        description: 'Ejecuta una vuelta manual de las tareas programadas.',
        confirm: true,
    },
]

export default function Actions() {
    const [busy, setBusy] = useState(null)

    const run = (action) => {
        if (action.confirm && !confirm(`¿Ejecutar "${action.title}"?`)) return

        setBusy(action.key)
        router.post(`/sistema/acciones/${action.key}`, {}, {
            preserveScroll: true,
            onFinish: () => setBusy(null),
        })
    }

    return (
        <AppShell title="Acciones del sistema">
            <div className="mx-auto max-w-6xl space-y-6">
                <section className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">
                                Centro de control
                            </p>
                            <h1 className="mt-2 text-3xl font-black">Acciones rápidas</h1>
                            <p className="mt-2 text-sm text-slate-500">
                                Utilidades seguras para mantenimiento básico sin entrar por SSH.
                            </p>
                        </div>
                        <Link
                            href="/sistema/estado"
                            className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold dark:border-neutral-700"
                        >
                            Estado general
                        </Link>
                    </div>
                </section>

                <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {actions.map((action) => (
                        <article
                            key={action.key}
                            className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900"
                        >
                            <h2 className="font-black">{action.title}</h2>
                            <p className="mt-2 min-h-10 text-sm text-slate-500">{action.description}</p>
                            <button
                                type="button"
                                disabled={busy !== null}
                                onClick={() => run(action)}
                                className="mt-5 w-full rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-indigo-700 disabled:opacity-50"
                            >
                                {busy === action.key ? 'Ejecutando…' : 'Ejecutar'}
                            </button>
                        </article>
                    ))}
                </section>

                <section className="rounded-3xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-500/10 dark:text-amber-200">
                    Estas acciones no ejecutan migraciones, no eliminan inventario y no modifican publicaciones.
                </section>
            </div>
        </AppShell>
    )
}
