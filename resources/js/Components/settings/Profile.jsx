import { Head, useForm, usePage } from '@inertiajs/react'
import SettingsLayout from '@/Components/settings/SettingsLayout'
import SettingsCard from '@/Components/settings/SettingsCard'

export default function Profile() {
    const { auth } = usePage().props

    const { data, setData, patch, processing, errors, recentlySuccessful } = useForm({
        name: auth?.user?.name ?? '',
        email: auth?.user?.email ?? '',
    })

    const submit = (e) => {
        e.preventDefault()
        patch('/settings/profile')
    }

    return (
        <>
            <Head title="Perfil" />

            <SettingsLayout
                title="Ajustes"
                description="Gestiona tu perfil y la configuración de tu cuenta."
                current="profile"
            >
                <SettingsCard
                    title="Perfil"
                    description="Actualiza tu nombre y correo electrónico."
                >
                    <form onSubmit={submit} className="space-y-6">
                        <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Nombre
                                </label>
                                <input
                                    type="text"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    className="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white dark:focus:border-indigo-400 dark:focus:ring-indigo-500/20"
                                />
                                {errors.name && (
                                    <p className="mt-2 text-sm text-red-600">{errors.name}</p>
                                )}
                            </div>

                            <div>
                                <label className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                                    Correo electrónico
                                </label>
                                <input
                                    type="email"
                                    value={data.email}
                                    onChange={(e) => setData('email', e.target.value)}
                                    className="w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white dark:focus:border-indigo-400 dark:focus:ring-indigo-500/20"
                                />
                                {errors.email && (
                                    <p className="mt-2 text-sm text-red-600">{errors.email}</p>
                                )}
                            </div>
                        </div>

                        <div className="flex items-center gap-3">
                            <button
                                type="submit"
                                disabled={processing}
                                className="inline-flex items-center rounded-xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {processing ? 'Guardando...' : 'Guardar cambios'}
                            </button>

                            {recentlySuccessful && (
                                <span className="text-sm font-medium text-emerald-600">
                                    Guardado correctamente
                                </span>
                            )}
                        </div>
                    </form>
                </SettingsCard>

                <SettingsCard
                    title="Cuenta de Mercado Libre"
                    description="Vincula tu cuenta para gestionar publicaciones, stock y precios."
                >
                    <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p className="text-sm text-slate-600 dark:text-slate-300">
                                Haz clic en el botón para autorizar tu cuenta de Mercado Libre y permitir que la aplicación administre tus publicaciones.
                            </p>
                        </div>

                        <a
                            href="/meli/auth/redirect"
                            className="inline-flex items-center justify-center rounded-xl bg-sky-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-sky-700"
                        >
                            Vincular Mercado Libre
                        </a>
                    </div>
                </SettingsCard>

                <SettingsCard
                    title="Zona peligrosa"
                    description="Elimina tu cuenta y todos sus recursos asociados."
                >
                    <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p className="text-sm text-slate-600 dark:text-slate-300">
                                Esta acción no se puede deshacer.
                            </p>
                        </div>

                        <button
                            type="button"
                            className="inline-flex items-center justify-center rounded-xl bg-red-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-red-700"
                        >
                            Eliminar cuenta
                        </button>
                    </div>
                </SettingsCard>
            </SettingsLayout>
        </>
    )
}