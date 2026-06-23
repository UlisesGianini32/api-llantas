import { Head, Link, useForm, usePage } from '@inertiajs/react'
import SettingsLayout from '@/Components/settings/SettingsLayout'
import SettingsCard from '@/Components/settings/SettingsCard'

export default function Profile() {
    const { auth } = usePage().props
    const user = auth?.user

    const {
        data,
        setData,
        patch,
        processing,
        errors,
        recentlySuccessful,
    } = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
    })

    const submit = (e) => {
        e.preventDefault()
        patch('/settings/profile')
    }

    const meliAccounts = user?.meli_accounts ?? []

    const hasMeliLinked =
        meliAccounts.length > 0 ||
        user?.meli_linked === true ||
        (user?.meli_id != null && String(user.meli_id).trim() !== '')

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
                    description="Puedes vincular varias tiendas al mismo perfil (misma app de MeLi). La marcada como principal es la que usan sincronización y jobs por defecto."
                >
                    <div className="space-y-4">
                        <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-neutral-800 dark:bg-neutral-950">
                            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <p className="text-sm font-semibold text-slate-900 dark:text-white">
                                        Estado de la conexión
                                    </p>

                                    <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">
                                        {hasMeliLinked
                                            ? 'Una o más cuentas de Mercado Libre están vinculadas.'
                                            : 'No tienes una cuenta de Mercado Libre vinculada.'}
                                    </p>
                                </div>

                                <span
                                    className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${
                                        hasMeliLinked
                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                                            : 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'
                                    }`}
                                >
                                    {hasMeliLinked ? 'Vinculada' : 'Sin vincular'}
                                </span>
                            </div>
                        </div>

                        {meliAccounts.length > 0 && (
                            <ul className="space-y-3">
                                {meliAccounts.map((acc) => (
                                    <li
                                        key={acc.id}
                                        className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900 md:flex-row md:items-center md:justify-between"
                                    >
                                        <div>
                                            <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                                ID Mercado Libre (user_id)
                                            </p>
                                            <p className="mt-1 font-mono text-sm font-semibold text-slate-900 dark:text-white">
                                                {acc.meli_user_id}
                                                {acc.is_default && (
                                                    <span className="ml-2 inline-flex rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-800 dark:bg-indigo-500/20 dark:text-indigo-200">
                                                        Principal
                                                    </span>
                                                )}
                                            </p>
                                            {acc.nickname && (
                                                <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">
                                                    {acc.nickname}
                                                </p>
                                            )}
                                        </div>
                                        <div className="flex flex-wrap gap-2">
                                            <a
                                                href={`/auth/meli?account=${acc.id}`}
                                                className="inline-flex items-center justify-center rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-sky-700"
                                            >
                                                Reautorizar
                                            </a>
                                            <Link
                                                href={`/auth/meli/unlink/${acc.id}`}
                                                method="delete"
                                                as="button"
                                                className="inline-flex items-center justify-center rounded-xl border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-600 transition hover:bg-red-50 dark:border-red-800 dark:bg-neutral-950 dark:text-red-400 dark:hover:bg-red-500/10"
                                            >
                                                Desvincular
                                            </Link>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        )}

                        <div className="flex flex-col gap-3 md:flex-row md:flex-wrap">
                            {!hasMeliLinked ? (
                                <a
                                    href="/auth/meli"
                                    className="inline-flex items-center justify-center rounded-xl bg-sky-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-sky-700"
                                >
                                    Vincular Mercado Libre
                                </a>
                            ) : (
                                <>
                                    <a
                                        href="/auth/meli?additional=1"
                                        className="inline-flex items-center justify-center rounded-xl bg-sky-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-sky-700"
                                    >
                                        Vincular otra cuenta
                                    </a>
                                    <p className="text-sm text-slate-500 dark:text-slate-400 md:self-center">
                                        Inicia sesión en Mercado Libre con el usuario de la otra tienda cuando el
                                        navegador te lo pida.
                                    </p>
                                </>
                            )}
                        </div>
                    </div>
                </SettingsCard>

                <SettingsCard
                    title="Zona peligrosa"
                    description="Acciones sensibles relacionadas con la cuenta."
                >
                    <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p className="text-sm text-slate-600 dark:text-slate-300">
                                Aquí puedes agregar después la eliminación de cuenta si decides implementarla.
                            </p>
                        </div>

                        <button
                            type="button"
                            disabled
                            className="inline-flex cursor-not-allowed items-center justify-center rounded-xl bg-red-600 px-5 py-3 text-sm font-semibold text-white opacity-60"
                        >
                            Eliminar cuenta
                        </button>
                    </div>
                </SettingsCard>
            </SettingsLayout>
        </>
    )
}