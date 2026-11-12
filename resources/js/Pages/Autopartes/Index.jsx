import { Link, router } from '@inertiajs/react'
import { useState } from 'react'
import Layout, { Pagination, baseUrl, button, card, field } from './Layout'

export default function Index({ branch, rows, filters, categories, vendors, files, selectedFile, canImport }) {
    const [values, setValues] = useState({ search: filters.search || '', category: filters.category || '', vendor: filters.vendor || '', file: selectedFile || '' })
    const change = key => e => setValues({ ...values, [key]: e.target.value })
    return <Layout branch={branch} title="Catálogo">
        <div className="flex items-center justify-between"><p className="text-sm text-slate-500">Identidades internas provisionales · {rows.total} registros de origen</p>{canImport && <Link className={button} href={`${baseUrl(branch)}/importaciones`}>Importaciones</Link>}</div>
        <form className={`${card} flex flex-wrap items-end gap-3`} onSubmit={e => { e.preventDefault(); router.get(baseUrl(branch), values, { preserveState: true }) }}>
            <label className="flex min-w-64 flex-1 flex-col gap-1 text-sm">Buscar<input className={field} value={values.search} onChange={change('search')} placeholder="Artículo, parte, descripción, categoría o proveedor" /></label>
            <label className="flex flex-col gap-1 text-sm">Categoría<select className={`${field} max-w-56`} value={values.category} onChange={change('category')}><option value="">Todas</option>{categories.map(v => <option key={v}>{v}</option>)}</select></label>
            <label className="flex flex-col gap-1 text-sm">Proveedor<select className={`${field} max-w-56`} value={values.vendor} onChange={change('vendor')}><option value="">Todos</option>{vendors.map(v => <option key={v}>{v}</option>)}</select></label>
            <label className="flex flex-col gap-1 text-sm">Archivo<select className={`${field} max-w-64`} value={values.file} onChange={change('file')}><option value="">Última importación</option>{files.map(v => <option key={v.id} value={v.id}>{v.name}</option>)}</select></label>
            <button className={button}>Filtrar</button>
        </form>
        <div className={card}><div className="overflow-x-auto"><table className="w-full text-left text-sm">
            <thead className="border-b border-slate-200 text-xs uppercase text-slate-500"><tr>{['Artículo / parte', 'Descripción / proveedor', 'Retail origen', 'Ext. Retail origen', 'Qty Excel', `Stock físico ${branch.code}`, 'Revisión'].map(h => <th className="p-3" key={h}>{h}</th>)}</tr></thead>
            <tbody>{rows.data.map(row => <tr className="border-b border-slate-100 align-top dark:border-neutral-800" key={row.id}>
                <td className="p-3"><Link className="font-semibold text-indigo-600" href={`${baseUrl(branch)}/registros/${row.id}`}>{row.item || 'Sin artículo'}</Link><p className="font-mono">{row.part || 'Sin parte'}</p><p className="mt-1 text-xs text-slate-500">Fila {row.row_number}</p></td>
                <td className="max-w-sm p-3"><p className="whitespace-pre-wrap">{row.description}</p><p className="mt-1 text-xs text-slate-500">{row.vendor} · {row.category}</p></td>
                <td className="whitespace-pre-wrap p-3">{row.retail}</td><td className="whitespace-pre-wrap p-3">{row.extended_retail}</td><td className="p-3">{row.qty}</td><td className="p-3">{row.physical_stock ?? 'Sin permiso'}</td>
                <td className="p-3"><span className={`rounded px-2 py-1 text-xs ${row.ambiguous ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-700'}`}>{row.ambiguous ? 'Identificador repetido' : 'Provisional'}</span></td>
            </tr>)}</tbody>
        </table>{rows.data.length === 0 && <p className="p-8 text-center text-slate-500">No hay registros importados que coincidan con estos filtros.</p>}</div><Pagination data={rows} /></div>
    </Layout>
}
