import { Head } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import SettingsLayout from '@/Components/settings/SettingsLayout'
import SettingsCard from '@/Components/settings/SettingsCard'

export default function Appearance() {
    const [theme, setTheme] = useState('system')

    useEffect(() => {
        const saved = localStorage.getItem('theme') || 'system'
        setTheme(saved)
        applyTheme(saved)
    }, [])

    const applyTheme = (selectedTheme) => {
        const root = document.documentElement

        root.classList.remove('dark')

        if (selectedTheme === 'dark') {
            root.classList.add('dark')
        } else if (selectedTheme === 'system') {
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches
            if (prefersDark) {
                root.classList.add('dark')
            }
        }

        localStorage.setItem('theme', selectedTheme)
        setTheme(selectedTheme)
    }

    const OptionCard = ({ value, title, description }) => {
        const active = theme === value

        return (
            <button
                type="button"
                onClick={() => applyTheme(value)}
                className={`w-full rounded-2xl border p-5 text-left transition ${
                    active
                        ? 'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-200 dark:border-indigo-400 dark:bg-indigo-500/10 dark:ring-indigo-500/20'
                        : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50 dark:border-neutral-800 dark:bg-neutral-900 dark:hover:border-neutral-700 dark:hover:bg-neutral-800'
                }`}
            >
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h4 className="text-base font-semibold text-slate-900 dark:text-white">
                            {title}
                        </h4>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {description}
                        </p>
                    </div>

                    {active && (
                        <span className="inline-flex rounded-full bg-indigo-600 px-3 py-1 text-xs font-semibold text-white">
                            Activo
                        </span>
                    )}
                </div>
            </button>
        )
    }

    return (
        <>
            <Head title="Apariencia" />

            <SettingsLayout
                title="Ajustes"
                description="Gestiona tu perfil y la configuración de tu cuenta."
                current="appearance"
            >
                <SettingsCard
                    title="Apariencia"
                    description="Selecciona cómo quieres ver el panel."
                >
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <OptionCard
                            value="light"
                            title="Modo claro"
                            description="Usa una interfaz clara y limpia."
                        />

                        <OptionCard
                            value="dark"
                            title="Modo oscuro"
                            description="Ideal para ambientes con poca luz."
                        />

                        <OptionCard
                            value="system"
                            title="Usar sistema"
                            description="Sigue automáticamente la configuración de tu equipo."
                        />
                    </div>

                    <div className="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-neutral-800 dark:bg-neutral-950">
                        <p className="text-sm font-medium text-slate-700 dark:text-slate-300">
                            Tema actual:
                            <span className="ml-2 font-semibold text-slate-900 dark:text-white">
                                {theme === 'light'
                                    ? 'Claro'
                                    : theme === 'dark'
                                      ? 'Oscuro'
                                      : 'Sistema'}
                            </span>
                        </p>
                    </div>
                </SettingsCard>
            </SettingsLayout>
        </>
    )
}