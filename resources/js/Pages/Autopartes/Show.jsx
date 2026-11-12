import { Link } from '@inertiajs/react'
import Layout, { baseUrl, card } from './Layout'

export default function Show({ branch, record, headers, related, decisions, importUrl }) {
    return <Layout branch={branch} title="Ficha completa">
        <Link className="text-indigo-600" href={baseUrl(branch)}>← Catálogo</Link>
        <div className={card}><h2 className="font-semibold">{record.code || 'Sin código asignado'}</h2><p className="mt-2 break-all text-sm">{record.file_name} · hoja {record.sheet_index + 1}: {record.sheet} · fila {record.row_number}</p><p className="mt-1 break-all font-mono text-xs">SHA-256: {record.sha256}</p><p className="mt-3">Existencia física {branch.name}: <strong>{record.physical_stock ?? 'Sin permiso de consulta'}</strong></p>{importUrl && <Link className="mt-3 inline-block text-indigo-600" href={importUrl}>Consultar importación y original</Link>}</div>
        {related.length > 0 && <div className={`${card} border-amber-300`}><h2 className="font-semibold">Identificador compartido: requiere revisión</h2><p className="mt-1 text-sm">Estas coincidencias no se seleccionan automáticamente ni constituyen barcodes verificados.</p><ul className="mt-3 space-y-2">{related.map(row => <li key={row.id}><Link className="text-indigo-600" href={`${baseUrl(branch)}/registros/${row.id}`}>Fila {row.row_number}: {row.item_number} · {row.manufacturer_part_number} · {row.description}</Link></li>)}</ul></div>}
        <div className={card}><h2 className="mb-4 font-semibold">Las 21 columnas originales</h2><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr>{['Columna', 'Valor original completo', 'Tipo / presencia', 'Formato / presentación'].map(h => <th className="border-b p-3" key={h}>{h}</th>)}</tr></thead><tbody>{record.cells.map((cell, i) => <tr key={cell.column} className="border-b border-slate-100 align-top dark:border-neutral-800">
            <th className="p-3 font-medium">{cell.column} · {headers[i]}</th><td className="min-w-64 max-w-3xl p-3"><pre className="whitespace-pre-wrap break-words font-mono text-sm">{cell.value === null ? '⟨nulo⟩' : cell.value === '' ? '⟨cadena vacía⟩' : String(cell.value)}</pre></td><td className="p-3">{cell.type}<br />{cell.present ? 'Celda presente' : 'Celda ausente'}</td><td className="max-w-sm p-3"><code>{cell.format}</code><pre className="whitespace-pre-wrap break-words">{cell.display}</pre>{cell.type === 'f' && <p>Valor en caché: {String(cell.cached)}. Fórmula sin ejecutar.</p>}</td>
        </tr>)}</tbody></table></div></div>
        <div className={card}><h2 className="font-semibold">Historial de asociación</h2>{decisions.map(d => <p className="mt-2 text-sm" key={d.id}>{d.created_at} · {d.decision} · {d.reason}</p>)}</div>
    </Layout>
}
