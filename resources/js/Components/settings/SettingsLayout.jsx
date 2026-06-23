import { Link } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

function SettingsNavLink({ href, active, children }) {
    return (
        <Link
            href={href}
            className={`block rounded-xl px-4 py-3 text-sm font-medium transition ${
                active
                    ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300'
                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-neutral-800 dark:hover:text-white'
            }`}
        >
            {children}
        </Link>
    )
}

export default function SettingsLayout({
    title = 'Ajustes',
    description = 'Gestiona tu cuenta y configuración.',
    current = 'profile',
    children,
}) {
    return (
        <AppShell title={title}>
            <div className="mx-auto max-w-7xl">
                <div className="mb-6">
                    <h2 className="text-2xl font-bold text-slate-900 dark:text-white">
                        {title}
                    </h2>
                    <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        {description}
                    </p>
                </div>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                    <aside className="lg:col-span-3">
                        <div className="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="mb-2 px-3 pt-2">
                                <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                                    Configuración
                                </p>
                            </div>

                            <div className="space-y-1">
                                <SettingsNavLink
                                    href="/settings/profile"
                                    active={current === 'profile'}
                                >
                                    Perfil
                                </SettingsNavLink>

                                <SettingsNavLink
                                    href="/settings/password"
                                    active={current === 'password'}
                                >
                                    Contraseña
                                </SettingsNavLink>

                                <SettingsNavLink
                                    href="/settings/two-factor"
                                    active={current === 'two-factor'}
                                >
                                    Autenticación de dos factores
                                </SettingsNavLink>

                                <SettingsNavLink
                                    href="/settings/appearance"
                                    active={current === 'appearance'}
                                >
                                    Apariencia
                                </SettingsNavLink>
                            </div>
                        </div>
                    </aside>

                    <section className="lg:col-span-9">
                        <div className="space-y-6">{children}</div>
                    </section>
                </div>
            </div>
        </AppShell>
    )
}