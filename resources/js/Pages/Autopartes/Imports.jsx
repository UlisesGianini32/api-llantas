import { Link, useForm } from '@inertiajs/react'
import Layout, { Pagination, baseUrl, button, card, field, statusLabel } from './Layout'

export default function Imports({ branch, runs }) {
    const form = useForm({ file: null })
    return <Layout branch={branch} title="Importaciones">
        <Link className="text-indigo-600" href={baseUrl(branch)}>← Catálogo</Link>
        <form className={card} onSubmit={e => { e.preventDefault(); form.post(`${baseUrl(branch)}/importaciones`, { forceFormData: true }) }}>
            <h2 className="font-semibold">Preparar archivo</h2><p className="my-2 text-sm text-slate-500">XLS o XLSX · máximo 20 MB. Se conservará el original antes de confirmar el alta del catálogo.</p>
            <label className="block text-sm">Archivo Excel<input required className={`${field} my-3 block w-full`} type="file" accept=".xls,.xlsx" onChange={e => form.setData('file', e.target.files[0])} /></label>
            <button className={button} disabled={form.processing || !form.data.file}>{form.processing ? 'Leyendo y conservando…' : 'Crear vista previa'}</button>{form.progress && <progress className="ml-4" value={form.progress.percentage} max="100" />}
        </form>
        <div className={card}><h2 className="mb-3 font-semibold">Historial de ejecuciones</h2><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr>{['Ejecución / archivo', 'Estado', 'Filas procesadas', 'Nuevas / reutilizadas'].map(h => <th className="border-b p-3" key={h}>{h}</th>)}</tr></thead><tbody>{runs.data.map(run => <tr key={run.id} className="border-b border-slate-100 dark:border-neutral-800"><td className="p-3"><Link className="text-indigo-600" href={`${baseUrl(branch)}/importaciones/${run.id}`}>#{run.id} · {run.uploaded_name}</Link></td><td className="p-3">{statusLabel(run.status)}</td><td className="p-3">{run.processed_rows} / {run.total_rows}</td><td className="p-3">{run.counters?.created || 0} / {run.counters?.reused || 0}</td></tr>)}</tbody></table></div><Pagination data={runs} /></div>
    </Layout>
}
