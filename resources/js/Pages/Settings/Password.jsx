import { Head, useForm } from '@inertiajs/react'
import SettingsLayout from '@/Components/settings/SettingsLayout'
import SettingsCard from '@/Components/settings/SettingsCard'

export default function Password() {
    const {
        data,
        setData,
        patch,
        processing,
        errors,
        reset,
        recentlySuccessful,
    } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    })

    const submit = (e) => {
        e.preventDefault()

        patch('/settings/password', {
            preserveScroll: true,
            onSuccess: () => {
                reset('current_password', 'password', 'password_confirmation')
            },
        })
    }

    return (
        <>
            <Head title="Contraseña" />

            <SettingsLayout
                title="Ajustes"
                description="Gestiona tu perfil y la configuración de tu cuenta."
                current="password"
            >
                <SettingsCard
                    title="Actualizar contraseña"
                    description="Usa una contraseña larga y segura para proteger tu cuenta."
                >
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid grid-cols-1 gap-6">
                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Contraseña actual
                                </label>
                                <input
                                    type="password"
                                    value={data.current_password}
                                    onChange={(e) => setData('current_password', e.target.value)}
                                    className="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white dark:focus:border-indigo-400 dark:focus:ring-indigo-500/20"
                                />
                                {errors.current_password && (
                                    <p className="mt-2 text-sm text-red-600">
                                        {errors.current_password}
                                    </p>
                                )}
                            </div>

                            <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                                <div>
                                    <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                        Nueva contraseña
                                    </label>
                                    <input
                                        type="password"
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        className="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white dark:focus:border-indigo-400 dark:focus:ring-indigo-500/20"
                                    />
                                    {errors.password && (
                                        <p className="mt-2 text-sm text-red-600">{errors.password}</p>
                                    )}
                                </div>

                                <div>
                                    <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                        Confirmar nueva contraseña
                                    </label>
                                    <input
                                        type="password"
                                        value={data.password_confirmation}
                                        onChange={(e) =>
                                            setData('password_confirmation', e.target.value)
                                        }
                                        className="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white dark:focus:border-indigo-400 dark:focus:ring-indigo-500/20"
                                    />
                                </div>
                            </div>
                        </div>

                        <div className="flex items-center gap-3">
                            <button
                                type="submit"
                                disabled={processing}
                                className="inline-flex items-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {processing ? 'Actualizando...' : 'Actualizar contraseña'}
                            </button>

                            {recentlySuccessful && (
                                <span className="text-sm font-medium text-emerald-600">
                                    Contraseña actualizada
                                </span>
                            )}
                        </div>
                    </form>
                </SettingsCard>
            </SettingsLayout>
        </>
    )
}