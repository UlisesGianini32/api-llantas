import { Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'
import Pagination from '@/Components/ui/Pagination'

function money(n) {
    return Number(n || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })
}

function HeaderCounters({ mlmLlantasCount, mlmCompuestosCount }) {
    return (
        <>
            <div className="inline-flex items-center gap-2 rounded-full border border-neutral-700 bg-neutral-900/60 px-3 py-2 text-neutral-100">
                <span className="text-xs uppercase tracking-wide text-neutral-300">MLM Llantas</span>
                <span className="inline-flex h-6 min-w-[2.25rem] items-center justify-center rounded-full bg-indigo-500/20 px-2 text-xs font-bold text-indigo-200">
                    {Number(mlmLlantasCount || 0).toLocaleString()}
                </span>
            </div>

            <div className="inline-flex items-center gap-2 rounded-full border border-neutral-700 bg-neutral-900/60 px-3 py-2 text-neutral-100">
                <span className="text-xs uppercase tracking-wide text-neutral-300">MLM Compuestos</span>
                <span className="inline-flex h-6 min-w-[2.25rem] items-center justify-center rounded-full bg-fuchsia-500/20 px-2 text-xs font-bold text-fuchsia-200">
                    {Number(mlmCompuestosCount || 0).toLocaleString()}
                </span>
            </div>
        </>
    )
}

function SectionTable({ title, count, headers, rows, emptyCols, rowClassName = '' }) {
    return (
        <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
            <div className="bg-zinc-100 px-4 py-3 font-semibold text-zinc-700 dark:bg-neutral-800 dark:text-gray-300">
                {title} ({count})
            </div>
            <div className="overflow-x-auto">
                <table className="w-full text-sm text-zinc-700 dark:text-gray-300">
                    <thead className="bg-zinc-50 text-zinc-600 dark:bg-neutral-900 dark:text-gray-400">
                        <tr>
                            {headers.map((h) => (
                                <th key={h.key} className={h.className || 'px-4 py-2'}>
                                    {h.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 ? (
                            <tr>
                                <td colSpan={emptyCols} className="px-4 py-4 text-center text-zinc-500 dark:text-gray-400">
                                    Sin resultados en esta pagina.
                                </td>
                            </tr>
                        ) : (
                            rows.map((row) => (
                                <tr key={row.key} className={`border-t border-zinc-200 dark:border-neutral-800 ${rowClassName}`}>
                                    {row.cells.map((cell, idx) => (
                                        <td key={`${row.key}-${idx}`} className={cell.className || 'px-4 py-2'}>
                                            {cell.value}
                                        </td>
                                    ))}
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </div>
    )
}

export default function MlCompare({
    products,
    missingInSystem = [],
    skuMismatch = [],
    dupByMlm = [],
    running = false,
    lastRun = null,
    lastRes = null,
    filters = {},
    mlmLlantasCount = 0,
    mlmCompuestosCount = 0,
}) {
    const searchValue = filters.search || ''
    const perPageValue = Number(filters.per_page || 25)

    const onSearch = (e) => {
        e.preventDefault()
        const fd = new FormData(e.currentTarget)
        const search = fd.get('search') || ''
        router.get('/ml/compare', {
            search,
            per_page: perPageValue,
        }, { preserveState: true, preserveScroll: true })
    }

    const onPerPage = (e) => {
        const perPage = Number(e.target.value || 25)
        router.get('/ml/compare', {
            search: searchValue,
            per_page: perPage,
        }, { preserveState: true, preserveScroll: true })
    }

    return (
        <AppShell title="Comparador ML vs Sistema">
            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-zinc-900 dark:text-white">Comparador ML vs Sistema</h1>
                        <div className="mt-2 space-y-1 text-xs text-zinc-600 dark:text-gray-400">
                            <div>
                                Estado:{' '}
                                {running ? (
                                    <span className="font-semibold text-amber-600 dark:text-amber-300">Corriendo...</span>
                                ) : (
                                    <span className="font-semibold text-emerald-600 dark:text-emerald-300">Listo</span>
                                )}
                            </div>

                            {lastRun && (
                                <div>
                                    Ultimo inicio: <span className="font-mono">{lastRun}</span>
                                </div>
                            )}

                            {lastRes && (
                                <div>
                                    Ultimo resultado:{' '}
                                    {lastRes.ok === true ? (
                                        <span className="text-emerald-600 dark:text-emerald-300">
                                            OK - Nuevos: {lastRes.inserted ?? 0} | Actualizados: {lastRes.updated ?? 0}
                                        </span>
                                    ) : (
                                        <span className="text-red-600 dark:text-red-300">
                                            ERROR - {lastRes.error || 'Desconocido'}
                                        </span>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>

                    <Link
                        href="/ml/compare/run"
                        method="post"
                        as="button"
                        disabled={running}
                        className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {running ? 'Sincronizando...' : 'Actualizar compare'}
                    </Link>
                </div>

                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <form onSubmit={onSearch} className="w-full sm:max-w-xl">
                        <input
                            type="text"
                            name="search"
                            defaultValue={searchValue}
                            placeholder="Buscar por MLM, SKU o nombre..."
                            className="w-full rounded-md border border-zinc-300 bg-white px-4 py-2 text-zinc-900 placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                        />
                    </form>

                    <div className="flex flex-wrap items-center gap-3">
                        <HeaderCounters mlmLlantasCount={mlmLlantasCount} mlmCompuestosCount={mlmCompuestosCount} />
                        <div className="flex items-center gap-2">
                            <span className="text-sm text-zinc-600 dark:text-gray-300">Por pagina</span>
                            <select
                                name="per_page"
                                value={perPageValue}
                                onChange={onPerPage}
                                className="rounded-md border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                            >
                                {[10, 25, 50, 100, 200].map((n) => (
                                    <option key={n} value={n}>
                                        {n}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                </div>

                <div className="flex flex-wrap gap-2 text-xs">
                    <span className="rounded bg-red-100 px-2 py-1 text-red-800 dark:bg-red-900 dark:text-red-200">Falta en sistema</span>
                    <span className="rounded bg-orange-100 px-2 py-1 text-orange-800 dark:bg-orange-900 dark:text-orange-200">SKU no coincide</span>
                    <span className="rounded bg-purple-100 px-2 py-1 text-purple-800 dark:bg-purple-900 dark:text-purple-200">Duplicado / otro SKU</span>
                </div>

                <SectionTable
                    title="A) Publicados en ML pero faltan / no cuadran en sistema"
                    count={missingInSystem.length}
                    rowClassName="bg-red-50/60 dark:bg-red-950/20"
                    emptyCols={5}
                    headers={[
                        { key: 'mlm', label: 'MLM', className: 'px-4 py-2 text-left' },
                        { key: 'sku', label: 'SKU (ML)' },
                        { key: 'name', label: 'Nombre' },
                        { key: 'status', label: 'Estatus' },
                        { key: 'reason', label: 'Motivo' },
                    ]}
                    rows={missingInSystem.map((row, idx) => ({
                        key: `${row.product?.ml || 'no-ml'}-${idx}`,
                        cells: [
                            { value: row.product?.ml || '-', className: 'px-4 py-2 font-mono text-blue-600 dark:text-blue-400' },
                            { value: row.product?.sku || '-' , className: 'px-4 py-2 font-mono' },
                            { value: row.product?.name || '-', className: 'px-4 py-2' },
                            { value: row.product?.status_ml || '-', className: 'px-4 py-2' },
                            { value: row.reason || '-', className: 'px-4 py-2 text-red-700 dark:text-red-300' },
                        ],
                    }))}
                />

                <SectionTable
                    title="B) SKU no coincide"
                    count={skuMismatch.length}
                    rowClassName="bg-orange-50/60 dark:bg-orange-950/20"
                    emptyCols={5}
                    headers={[
                        { key: 'mlm', label: 'MLM', className: 'px-4 py-2 text-left' },
                        { key: 'sku_ml', label: 'SKU (ML)' },
                        { key: 'sku_sys', label: 'SKU (Sistema)' },
                        { key: 'name', label: 'Nombre' },
                        { key: 'status', label: 'Estatus' },
                    ]}
                    rows={skuMismatch.map((row, idx) => ({
                        key: `${row.product?.ml || 'no-ml'}-${idx}`,
                        cells: [
                            { value: row.product?.ml || '-', className: 'px-4 py-2 font-mono text-blue-600 dark:text-blue-400' },
                            { value: row.ml_sku !== '' ? row.ml_sku : '- (vacio)', className: 'px-4 py-2 font-mono' },
                            { value: row.sys_sku || '-', className: 'px-4 py-2 font-mono font-semibold text-orange-800 dark:text-orange-200' },
                            { value: row.product?.name || '-', className: 'px-4 py-2' },
                            { value: row.product?.status_ml || '-', className: 'px-4 py-2' },
                        ],
                    }))}
                />

                <SectionTable
                    title="C) Mismo MLM con varios SKUs"
                    count={dupByMlm.length}
                    rowClassName="bg-purple-50/60 dark:bg-purple-950/20"
                    emptyCols={4}
                    headers={[
                        { key: 'mlm', label: 'MLM', className: 'px-4 py-2 text-left' },
                        { key: 'skus', label: 'SKUs detectados (pubs)' },
                        { key: 'name', label: 'Nombre' },
                        { key: 'status', label: 'Estatus' },
                    ]}
                    rows={dupByMlm.map((row, idx) => ({
                        key: `${row.product?.ml || 'no-ml'}-${idx}`,
                        cells: [
                            { value: row.product?.ml || '-', className: 'px-4 py-2 font-mono text-blue-600 dark:text-blue-400' },
                            { value: (row.pub_skus || []).join(', '), className: 'px-4 py-2 font-mono' },
                            { value: row.product?.name || '-', className: 'px-4 py-2' },
                            { value: row.product?.status_ml || '-', className: 'px-4 py-2' },
                        ],
                    }))}
                />

                <div className="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="bg-zinc-100 px-4 py-3 font-semibold text-zinc-700 dark:bg-neutral-800 dark:text-gray-300">
                        Lista general de publicados (products) - pagina actual
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-sm text-zinc-700 dark:text-gray-300">
                            <thead className="bg-zinc-50 text-zinc-600 dark:bg-neutral-900 dark:text-gray-400">
                                <tr>
                                    <th className="px-4 py-2 text-left">Nombre</th>
                                    <th className="px-4 py-2">MLM</th>
                                    <th className="px-4 py-2">SKU</th>
                                    <th className="px-4 py-2 text-right">Precio</th>
                                    <th className="px-4 py-2 text-center">Stock</th>
                                    <th className="px-4 py-2">Estatus</th>
                                    <th className="px-4 py-2">Categoria</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(products?.data || []).map((p, idx) => (
                                    <tr key={`${p.ml || 'no-ml'}-${idx}`} className="border-t border-zinc-200 dark:border-neutral-800">
                                        <td className="px-4 py-2">{p.name}</td>
                                        <td className="px-4 py-2 font-mono text-blue-600 dark:text-blue-400">{p.ml}</td>
                                        <td className="px-4 py-2 font-mono">{p.sku || '-'}</td>
                                        <td className="px-4 py-2 text-right">${money(p.price)}</td>
                                        <td className="px-4 py-2 text-center">{Number(p.stock || 0)}</td>
                                        <td className="px-4 py-2">{p.status_ml || '-'}</td>
                                        <td className="px-4 py-2">{p.category_name || '-'}</td>
                                    </tr>
                                ))}
                                {(products?.data || []).length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="px-4 py-4 text-center text-zinc-500 dark:text-gray-400">
                                            Sin resultados en esta pagina.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="p-3">
                        <Pagination links={products?.links || []} />
                    </div>
                </div>
            </div>
        </AppShell>
    )
}
