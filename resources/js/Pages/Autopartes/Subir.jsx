import AppShell from '@/Components/layout/AppShell'
import { Head, Link, useForm } from '@inertiajs/react'

export default function Subir() {
    const { data, setData, post, processing, errors } = useForm({
        file: null,
    })

    const submit = (event) => {
        event.preventDefault()
        post('/autopartes/upload', {
            forceFormData: true,
        })
    }

    return (
        <AppShell title="Subir importación">
            <Head title="Subir importación de autopartes" />
            <div className="mx-auto max-w-2xl space-y-6">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold text-slate-900 dark:text-white">Importación de autopartes</h1>
                        <p className="text-sm text-slate-500 dark:text-slate-400">Sube un archivo .xls o .xlsx para registrar la importación.</p>
                    </div>
                    <Link href="/autopartes" className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200 dark:hover:bg-neutral-800">
                        Volver
                    </Link>
                </div>

                <form onSubmit={submit} className="rounded-2xl border border-slate-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900">
                    <div>
                        <label htmlFor="file" className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-200">Archivo Excel</label>
                        <input
                            id="file"
                            type="file"
                            accept=".xls,.xlsx"
                            onChange={(event) => setData('file', event.target.files?.[0] ?? null)}
                            className="block w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700 dark:border-neutral-700 dark:bg-neutral-950 dark:text-slate-200"
                        />
                        {errors.file && <p className="mt-2 text-sm text-rose-600">{errors.file}</p>}
                    </div>

                    <div className="mt-6 flex justify-end">
                        <button type="submit" disabled={processing || !data.file} className="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:cursor-not-allowed disabled:opacity-50">
                            {processing ? 'Enviando...' : 'Importar archivo'}
                        </button>
                    </div>
                </form>
            </div>
        </AppShell>
    )
}
