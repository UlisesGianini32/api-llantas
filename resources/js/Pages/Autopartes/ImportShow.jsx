import { Link, router, useForm } from '@inertiajs/react'
import { useEffect } from 'react'
import Layout, { Pagination, baseUrl, button, card, statusLabel } from './Layout'

export default function ImportShow({ branch, run, issues, samples = [] }) {
    const form = useForm({ confirmed: false })
    const url = `${baseUrl(branch)}/importaciones/${run.id}`
    useEffect(() => {
        if (!['queued', 'processing'].includes(run.status)) return
        const timer = setInterval(() => router.reload({ only: ['run', 'issues'] }), 5000)
        return () => clearInterval(timer)
    }, [run.status])
    return <Layout branch={branch} title={`Importación #${run.id}`}>
        <Link className="text-indigo-600" href={`${baseUrl(branch)}/importaciones`}>← Historial</Link>
        <div className={card}><div className="flex flex-wrap items-center justify-between gap-4"><h2 className="font-semibold">{run.uploaded_name}</h2><span className="rounded bg-indigo-50 px-3 py-1 text-sm text-indigo-800">{statusLabel(run.status)}</span></div><p className="mt-2 break-all font-mono text-xs">SHA-256: {run.sha256}</p><a className="mt-3 inline-block text-sm text-indigo-600" href={`${url}/original`}>Descargar original conservado</a>
            <dl className="mt-5 grid grid-cols-2 gap-4 md:grid-cols-4">{[['Filas detectadas', run.total_rows], ['Columnas', run.summary.columns], ['Filas procesadas', run.processed_rows], ['Nuevos productos', run.counters?.created || 0], ['Productos reutilizados', run.counters?.reused || 0], ['Item # repetidos', run.summary.duplicate_item_values], ['Filas con Item # repetido', run.summary.duplicate_item_rows], ['Identidades por revisar', run.summary.pending_identity_review]].map(([label, value]) => <div key={label}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 text-xl font-semibold">{value ?? '—'}</dd></div>)}</dl>
            <progress className="mt-5 w-full" value={run.processed_rows} max={run.total_rows || 1} />
            {run.summary.equivalent_verified && <p className="mt-3 text-sm text-emerald-700">Equivalencia verificada celda por celda con el archivo de referencia; se reutilizarán asociaciones.</p>}
            {run.summary.missing_reference_rows > 0 && <p className="mt-3 text-sm text-amber-700">{run.summary.missing_reference_rows} filas anteriores sin correspondencia. No se eliminarán productos ni stock.</p>}
            {run.summary.classifications && <p className="mt-3 text-sm">Nuevas: {run.summary.classifications.new} · Modificadas: {run.summary.classifications.modified} · Ambiguas: {run.summary.classifications.ambiguous} · Sin cambios: {run.summary.classifications.unchanged}</p>}
            {run.error && <p role="alert" className="mt-4 text-red-700">{run.error}</p>}
            {run.status === 'preview' && <form className="mt-5 space-y-3" onSubmit={e => { e.preventDefault(); form.post(`${url}/confirmar`) }}><label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.confirmed} onChange={e => form.setData('confirmed', e.target.checked)} />Confirmo el alta del catálogo sin entradas de stock ni precios de venta.</label><button className={button} disabled={!form.data.confirmed || form.processing}>Confirmar importación</button></form>}
            {['failed', 'queued', 'processing'].includes(run.status) && <button className={`${button} mt-4`} onClick={() => router.post(`${url}/reintentar`)}>Reanudar si está interrumpida</button>}
            {run.status === 'completed' && <Link className={`${button} mt-4`} href={baseUrl(branch)}>Ver catálogo</Link>}
        </div>
        <div className={card}><h2 className="font-semibold">Muestra del archivo conservado</h2><div className="mt-3 overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr>{['Fila', 'Artículo', 'Parte', 'Descripción', 'Proveedor'].map(h => <th className="p-2" key={h}>{h}</th>)}</tr></thead><tbody>{samples.map(row => <tr key={row.id}>{[row.row_number, row.item_number, row.manufacturer_part_number, row.description, row.vendor_name].map((v, i) => <td className="whitespace-pre-wrap border-t p-2" key={i}>{v}</td>)}</tr>)}</tbody></table></div></div>
        <div className={card}><h2 className="font-semibold">Advertencias e incidencias</h2>{issues.data.length === 0 && <p className="mt-2 text-sm text-slate-500">Sin incidencias registradas.</p>}{issues.data.map(issue => <div key={issue.id} className="mt-3 border-b border-slate-100 pb-3 text-sm"><span className="font-semibold">{issue.code}</span><p>{issue.message}</p>{issue.source_row_id && <p className="text-xs text-slate-500">Fila Excel {issue.row_number} · Item # {issue.item_number} · registro #{issue.source_row_id}</p>}</div>)}<Pagination data={issues} /></div>
    </Layout>
}
