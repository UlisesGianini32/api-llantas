import { Link, router, useForm } from '@inertiajs/react'
import { useState } from 'react'
import { AUTOPARTES_CAPABILITIES, hasCapability } from '@/lib/autopartesCapabilities'
import Layout, { IdentityBadge, Pagination, baseUrl, button, card, field, secondaryButton } from './Layout'

export default function Inventory({
    branch,
    kpis,
    products,
    filters,
    categories = [],
    vendors = [],
    locations = [],
    capabilities = [],
}) {
    const [values, setValues] = useState({
        search: filters.search || '',
        category: filters.category || '',
        vendor: filters.vendor || '',
        automotive_location_id: filters.automotive_location_id || '',
        stock_filter: filters.stock_filter || 'all',
        identity_status: filters.identity_status || 'all',
    })
    const [verifyingPart, setVerifyingPart] = useState(null)

    const verifyForm = useForm({
        verified_barcode: '',
        reason: '',
    })

    const canResolveIdentity = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.IDENTITY_RESOLVE)
    const canDraftReceipt = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.RECEIPT_DRAFT)
    const canViewMovements = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.MOVEMENTS_VIEW)
    const canViewCatalog = hasCapability(capabilities, AUTOPARTES_CAPABILITIES.CATALOG_VIEW)

    const change = key => e => setValues({ ...values, [key]: e.target.value })

    const openVerify = product => {
        setVerifyingPart(product)
        verifyForm.setData({
            verified_barcode: product.verified_barcode || '',
            reason: '',
        })
    }

    const submitVerify = e => {
        e.preventDefault()
        if (!verifyingPart) return
        verifyForm.post(`${baseUrl(branch)}/identidades/${verifyingPart.automotive_part_id}/verificar`, {
            preserveScroll: true,
            onSuccess: () => setVerifyingPart(null),
        })
    }

    return (
        <Layout branch={branch} title={`Inventario ${branch.name}`}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-sm text-slate-600 dark:text-neutral-400">
                    Control de existencias físicas reales en ubicaciones de la sucursal {branch.name} ({branch.code}).
                </p>
                <div className="flex flex-wrap gap-2">
                    {canDraftReceipt && (
                        <Link className={button} href={`${baseUrl(branch)}/entradas`}>
                            + Entrada de autopartes (Escáner)
                        </Link>
                    )}
                    {canViewMovements && (
                        <Link className={secondaryButton} href={`${baseUrl(branch)}/movimientos`}>
                            Historial de movimientos
                        </Link>
                    )}
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
                <div className={card}>
                    <p className="text-xs font-medium uppercase text-slate-500">Total catálogo</p>
                    <p className="mt-1 text-2xl font-bold">{kpis.total_catalog}</p>
                </div>
                <div className={card}>
                    <p className="text-xs font-medium uppercase text-emerald-700">Con stock en {branch.code}</p>
                    <p className="mt-1 text-2xl font-bold text-emerald-700">{kpis.with_stock}</p>
                </div>
                <div className={card}>
                    <p className="text-xs font-medium uppercase text-slate-500">Sin stock físico</p>
                    <p className="mt-1 text-2xl font-bold">{kpis.without_stock}</p>
                </div>
                <div className={card}>
                    <p className="text-xs font-medium uppercase text-indigo-700">Unidades totales {branch.code}</p>
                    <p className="mt-1 text-2xl font-bold text-indigo-700">{kpis.total_units}</p>
                </div>
                <div className={card}>
                    <p className="text-xs font-medium uppercase text-amber-700">Identidades ambiguas</p>
                    <p className="mt-1 text-2xl font-bold text-amber-700">{kpis.ambiguous_count}</p>
                </div>
                <div className={card}>
                    <p className="text-xs font-medium uppercase text-emerald-700">Etiquetas verificadas</p>
                    <p className="mt-1 text-2xl font-bold text-emerald-700">{kpis.verified_count}</p>
                </div>
            </div>

            <form
                className={`${card} flex flex-wrap items-end gap-3`}
                onSubmit={e => {
                    e.preventDefault()
                    router.get(`${baseUrl(branch)}/inventario`, values, { preserveState: true })
                }}
            >
                <label className="flex min-w-64 flex-1 flex-col gap-1 text-sm">
                    Buscar (SKU, Item #, MFG Part #, etiqueta o descripción)
                    <input
                        className={field}
                        value={values.search}
                        onChange={change('search')}
                        placeholder="Ej. 15670445, 029-0131, AP-..., alternador"
                    />
                </label>
                <label className="flex flex-col gap-1 text-sm">
                    Existencias
                    <select className={field} value={values.stock_filter} onChange={change('stock_filter')}>
                        <option value="all">Todos</option>
                        <option value="with_stock">Con stock en {branch.code} (&gt; 0)</option>
                        <option value="without_stock">Sin stock en {branch.code} (0)</option>
                    </select>
                </label>
                <label className="flex flex-col gap-1 text-sm">
                    Ubicación {branch.code}
                    <select className={field} value={values.automotive_location_id} onChange={change('automotive_location_id')}>
                        <option value="">Todas las ubicaciones de {branch.code}</option>
                        {locations.map(loc => (
                            <option key={loc.id} value={loc.id}>
                                {loc.code} · {loc.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex flex-col gap-1 text-sm">
                    Categoría
                    <select className={`${field} max-w-48`} value={values.category} onChange={change('category')}>
                        <option value="">Todas</option>
                        {categories.map(cat => (
                            <option key={cat} value={cat}>
                                {cat}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex flex-col gap-1 text-sm">
                    Proveedor
                    <select className={`${field} max-w-48`} value={values.vendor} onChange={change('vendor')}>
                        <option value="">Todos</option>
                        {vendors.map(vendor => (
                            <option key={vendor} value={vendor}>
                                {vendor}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex flex-col gap-1 text-sm">
                    Identidad
                    <select className={field} value={values.identity_status} onChange={change('identity_status')}>
                        <option value="all">Todas</option>
                        <option value="verified">Etiqueta verificada</option>
                        <option value="provisional">Provisional</option>
                        <option value="ambiguous">Ambigua (requiere revisión)</option>
                    </select>
                </label>
                <button className={button} type="submit">
                    Filtrar
                </button>
            </form>

            {canResolveIdentity && verifyingPart && (
                <form className={`${card} border-2 border-indigo-400 space-y-4`} onSubmit={submitVerify}>
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <h2 className="text-base font-semibold">
                                Verificar identidad física · {verifyingPart.code} (Fila Excel #{verifyingPart.source_row_number})
                            </h2>
                            <p className="mt-1 text-sm text-slate-600">
                                Item #: <strong>{verifyingPart.item_number || '—'}</strong> · MFG Part #:{' '}
                                <strong>{verifyingPart.manufacturer_part_number || '—'}</strong> · {verifyingPart.description}
                            </p>
                        </div>
                        <button type="button" className={secondaryButton} onClick={() => setVerifyingPart(null)}>
                            Cerrar
                        </button>
                    </div>
                    <div className="grid gap-4 md:grid-cols-2">
                        <label className="flex flex-col gap-1 text-sm">
                            Código de barras físico verificado (opcional, único)
                            <input
                                className={field}
                                value={verifyForm.data.verified_barcode}
                                onChange={e => verifyForm.setData('verified_barcode', e.target.value)}
                                placeholder="Escanee o escriba el código impreso en la pieza/caja"
                            />
                        </label>
                        <label className="flex flex-col gap-1 text-sm">
                            Evidencia / motivo de verificación (obligatorio)
                            <input
                                required
                                minLength={5}
                                className={field}
                                value={verifyForm.data.reason}
                                onChange={e => verifyForm.setData('reason', e.target.value)}
                                placeholder="Ej. Verificado físicamente contra número grabado en caja y fila del Excel"
                            />
                        </label>
                    </div>
                    <div className="flex justify-end gap-2">
                        <button type="button" className={secondaryButton} onClick={() => setVerifyingPart(null)}>
                            Cancelar
                        </button>
                        <button type="submit" className={button} disabled={verifyForm.processing}>
                            Guardar verificación de identidad
                        </button>
                    </div>
                </form>
            )}

            <div className={card}>
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b border-slate-200 text-xs uppercase text-slate-500">
                            <tr>
                                <th className="p-3">SKU / Códigos</th>
                                <th className="p-3">Descripción / Categoría / Proveedor</th>
                                <th className="p-3">Identidad</th>
                                <th className="p-3">Qty del Excel (Documental)</th>
                                <th className="p-3">Stock {branch.name} (Físico real)</th>
                                <th className="p-3">Ubicaciones {branch.code}</th>
                                <th className="p-3">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            {products.data.map(product => (
                                <tr key={product.id} className="border-b border-slate-100 align-top dark:border-neutral-800">
                                    <td className="p-3">
                                        <p className="font-mono text-xs font-semibold text-indigo-700">{product.code}</p>
                                        <p className="mt-1 text-xs">
                                            Item #: <strong className="font-mono">{product.item_number || '—'}</strong>
                                        </p>
                                        <p className="text-xs">
                                            MFG Part #: <strong className="font-mono">{product.manufacturer_part_number || '—'}</strong>
                                        </p>
                                        {product.verified_barcode && (
                                            <p className="mt-1 text-xs text-emerald-700">
                                                Barcode: <strong className="font-mono">{product.verified_barcode}</strong>
                                            </p>
                                        )}
                                        {product.source_row_number && (
                                            <p className="mt-1 text-xs text-slate-400">Fila Excel #{product.source_row_number}</p>
                                        )}
                                    </td>
                                    <td className="max-w-md p-3">
                                        <p className="whitespace-pre-wrap font-medium">{product.description}</p>
                                        <p className="mt-1 text-xs text-slate-500">
                                            {product.category || 'Sin categoría'}
                                            {product.subcategory ? ` / ${product.subcategory}` : ''} · {product.vendor_name || 'Sin proveedor'}
                                        </p>
                                    </td>
                                    <td className="p-3">
                                        <IdentityBadge status={product.identity_status} />
                                        {product.verification_evidence && (
                                            <p className="mt-1 max-w-xs text-xs text-slate-500">{product.verification_evidence}</p>
                                        )}
                                        {canResolveIdentity && (
                                            <button
                                                type="button"
                                                onClick={() => openVerify(product)}
                                                className="mt-2 block text-xs font-medium text-indigo-600 hover:underline"
                                            >
                                                {product.identity_status === 'ambiguous' ? 'Resolver ambigüedad' : 'Verificar etiqueta'}
                                            </button>
                                        )}
                                    </td>
                                    <td className="p-3">
                                        <span className="rounded bg-slate-100 px-2 py-1 font-mono text-xs text-slate-600 dark:bg-neutral-800 dark:text-neutral-300">
                                            {product.excel_qty} (Excel)
                                        </span>
                                    </td>
                                    <td className="p-3">
                                        <span
                                            className={`inline-block rounded-lg px-3 py-1 text-sm font-bold ${
                                                product.branch_stock > 0
                                                    ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200'
                                                    : 'bg-slate-100 text-slate-600 dark:bg-neutral-800 dark:text-neutral-400'
                                            }`}
                                        >
                                            {product.branch_stock} uds
                                        </span>
                                    </td>
                                    <td className="p-3 text-xs">
                                        {product.locations_breakdown.length === 0 ? (
                                            <span className="text-slate-400">Sin existencias</span>
                                        ) : (
                                            <ul className="space-y-1">
                                                {product.locations_breakdown.map(loc => (
                                                    <li key={loc.automotive_location_id}>
                                                        <strong className="font-mono">{loc.code}</strong>: {loc.quantity} uds
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </td>
                                    <td className="p-3 text-xs space-y-1">
                                        {canViewCatalog && product.source_row_id && (
                                            <Link
                                                className="block font-medium text-indigo-600 hover:underline"
                                                href={`${baseUrl(branch)}/registros/${product.source_row_id}`}
                                            >
                                                Ver 21 columnas
                                            </Link>
                                        )}
                                        {canViewMovements && (
                                            <Link
                                                className="block font-medium text-slate-700 hover:underline dark:text-neutral-300"
                                                href={`${baseUrl(branch)}/movimientos?automotive_part_id=${product.automotive_part_id}`}
                                            >
                                                Ver movimientos
                                            </Link>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {products.data.length === 0 && (
                        <p className="p-8 text-center text-slate-500">
                            No se encontraron autopartes con los criterios seleccionados.
                        </p>
                    )}
                </div>
                <Pagination data={products} />
            </div>
        </Layout>
    )
}
