import { Link, useForm } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

export default function ExcelImportar() {
    const { data, setData, post, processing, errors, reset } = useForm({
        archivo: null,
    })

    const onSubmit = (e) => {
        e.preventDefault()
        post('/importar-excel', {
            forceFormData: true,
            onSuccess: () => reset('archivo'),
        })
    }

    return (
        <AppShell title="Importar Excel">
            <div className="mx-auto max-w-xl space-y-6">
                <h1 className="text-2xl font-bold text-zinc-900 dark:text-white">
                    Importar inventario desde Excel
                </h1>

                <form
                    onSubmit={onSubmit}
                    className="space-y-4 rounded-xl border border-zinc-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900"
                >
                    <div>
                        <label className="mb-1 block text-sm text-zinc-600 dark:text-gray-400">
                            Archivo Excel (.xlsx / .xls)
                        </label>

                        <input
                            type="file"
                            name="archivo"
                            required
                            accept=".xlsx,.xls"
                            onChange={(e) => setData('archivo', e.target.files?.[0] || null)}
                            className="block w-full text-sm text-zinc-700 file:cursor-pointer file:rounded-md file:border file:border-zinc-300 file:bg-zinc-100 file:px-4 file:py-2 file:text-zinc-900 hover:file:bg-zinc-200 dark:text-gray-300 dark:file:border-neutral-700 dark:file:bg-neutral-800 dark:file:text-white dark:hover:file:bg-neutral-700"
                        />

                        {errors.archivo && (
                            <p className="mt-2 text-sm text-red-600 dark:text-red-300">{errors.archivo}</p>
                        )}
                    </div>

                    <div className="flex justify-end gap-2">
                        <Link
                            href="/dashboard"
                            className="rounded-md border border-zinc-300 bg-white px-4 py-2 text-zinc-900 hover:bg-zinc-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-gray-300 dark:hover:bg-neutral-700"
                        >
                            Cancelar
                        </Link>

                        <button
                            type="submit"
                            disabled={processing || !data.archivo}
                            className="rounded-md bg-indigo-600 px-5 py-2 font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {processing ? 'Importando...' : 'Importar'}
                        </button>
                    </div>
                </form>

                <div className="text-sm text-zinc-600 dark:text-gray-500">
                    <p>- El archivo debe contener las columnas esperadas.</p>
                    <p>- Las llantas nuevas se crearan automaticamente.</p>
                    <p>- Los productos compuestos se generan solos.</p>
                </div>
            </div>
        </AppShell>
    )
}
