import AppShell from '@/Components/layout/AppShell'
import Pagination from '@/Components/ui/Pagination'
import { Head, Link, router } from '@inertiajs/react'
import { useState } from 'react'

export default function InventoryKitsIndex({ kits, filters = {} }) {
    const [search, setSearch] = useState(filters?.search || '')
    const [perPage, setPerPage] = useState(filters?.per_page || '50')

    const submitSearch = (e) => {
        e.preventDefault()
        router.get(
            '/almacen/kits',
            { search, per_page: perPage },
            { preserveState: true, replace: true }
        )
    }

    const handlePerPageChange = (newPerPage) => {
        setPerPage(newPerPage)
        router.get(
            '/almacen/kits',
            { search, per_page: newPerPage },
            { preserveState: true, replace: true }
        )
    }

    const resetFilters = () => {
        setSearch('')
        router.get('/almacen/kits', { per_page: perPage }, { preserveState: true, replace: true })
    }

    return (
        <AppShell title="Kits de inventario">
            <Head title="Kits" />
            <div className="mx-auto max-w-7xl space-y-6">
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <div>
                        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-300">
                            Inventario / Paquetes
                        </p>
                        <h1 className="mt-1 text-3xl font-bold text-slate-900 dark:text-white">
                            Kits y Combos
                        </h1>
                        <p className="mt-1 text-sm text-slate-500">
                            El stock y las ubicaciones se derivan dinámicamente de sus componentes individuales.
                        </p>
                    </div>
                    <Link
                        href="/almacen/productos/crear?product_type=KIT"
                        className="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 font-semibold text-white transition hover:bg-indigo-700 shadow"
                    >
                        + Nuevo kit
                    </Link>
                </div>

                {/* Filtros de búsqueda y cantidad por página */}
                <form
                    onSubmit={submitSearch}
                    className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center dark:border-neutral-800 dark:bg-neutral-900 shadow-sm"
                >
                    <div className="relative min-w-0 flex-1">
                        <input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Buscar kit o componente por nombre o SKU..."
                            className="w-full rounded-xl border border-slate-300 px-4 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white pr-9"
                        />
                        {search && (
                            <button
                                type="button"
                                onClick={resetFilters}
                                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 text-sm font-bold"
                                title="Limpiar búsqueda"
                            >
                                ✕
                            </button>
                        )}
                    </div>

                    <div className="flex items-center gap-2">
                        <select
                            value={perPage}
                            onChange={(e) => handlePerPageChange(e.target.value)}
                            className="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                        >
                            <option value="25">25 por pág.</option>
                            <option value="50">50 por pág.</option>
                            <option value="100">100 por pág.</option>
                            <option value="all">Todos ({kits?.total || 0})</option>
                        </select>

                        <button
                            type="submit"
                            className="rounded-xl bg-slate-900 px-5 py-2 text-sm font-semibold text-white hover:bg-slate-700 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200 transition"
                        >
                            Buscar
                        </button>
                    </div>
                </form>

                {/* Resumen de cantidad */}
                <div className="flex items-center justify-between text-xs text-slate-500 px-1">
                    <span>
                        Mostrando <strong className="text-slate-900 dark:text-white">{kits?.from || 0}</strong> a{' '}
                        <strong className="text-slate-900 dark:text-white">{kits?.to || 0}</strong> de{' '}
                        <strong className="text-indigo-600 dark:text-indigo-400">{kits?.total || 0}</strong> kits registrados
                    </span>
                    {search && (
                        <span>
                            Filtrado por: <span className="font-semibold text-slate-800 dark:text-slate-200">"{search}"</span>
                        </span>
                    )}
                </div>

                {/* Tabla de Kits */}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900 shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-sm dark:divide-neutral-800">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500 dark:bg-neutral-950">
                                <tr>
                                    <th className="px-5 py-3.5">Kit</th>
                                    <th className="px-5 py-3.5">Componentes & Ubicaciones</th>
                                    <th className="px-5 py-3.5 text-center">Físico posible</th>
                                    <th className="px-5 py-3.5 text-center">Disponible</th>
                                    <th className="px-5 py-3.5 text-center">Estado</th>
                                    <th className="px-5 py-3.5 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                {kits?.data?.length === 0 ? (
                                    <tr>
                                        <td colSpan="6" className="p-12 text-center text-sm text-slate-500">
                                            {search ? (
                                                <div>
                                                    <p className="font-semibold text-slate-700 dark:text-slate-300">
                                                        No se encontraron kits que coincidan con "{search}".
                                                    </p>
                                                    <button
                                                        type="button"
                                                        onClick={resetFilters}
                                                        className="mt-2 text-indigo-600 hover:underline text-xs"
                                                    >
                                                        Ver todos los kits
                                                    </button>
                                                </div>
                                            ) : (
                                                'No hay kits configurados.'
                                            )}
                                        </td>
                                    </tr>
                                ) : (
                                    kits?.data?.map((kit) => {
                                        const compCount = kit.kit_components?.length || 0
                                        return (
                                            <tr
                                                key={kit.id}
                                                className="hover:bg-slate-50/70 dark:hover:bg-neutral-950/50 transition align-top"
                                            >
                                                <td className="px-5 py-4 min-w-[240px]">
                                                    <Link
                                                        href={`/almacen/kits/${kit.id}`}
                                                        className="font-bold text-slate-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-300"
                                                    >
                                                        {kit.name}
                                                    </Link>
                                                    <div className="mt-0.5 font-mono text-xs text-slate-500">
                                                        {kit.sku}
                                                    </div>
                                                </td>

                                                <td className="px-5 py-4 min-w-[280px]">
                                                    <div className="flex items-center gap-2 mb-1">
                                                        <span className="inline-flex items-center rounded-md bg-indigo-50 px-2 py-0.5 text-xs font-bold text-indigo-700 dark:bg-indigo-950/60 dark:text-indigo-300 border border-indigo-200/50">
                                                            {compCount} {compCount === 1 ? 'componente' : 'componentes'}
                                                        </span>
                                                        <Link
                                                            href={`/almacen/kits/${kit.id}/editar-componentes`}
                                                            className="text-xs text-indigo-600 hover:underline"
                                                        >
                                                            Configurar →
                                                        </Link>
                                                    </div>
                                                    {compCount > 0 ? (
                                                        <div className="space-y-1 text-xs text-slate-600 dark:text-slate-300">
                                                            {kit.kit_components.slice(0, 3).map((kc, idx) => {
                                                                const comp = kc.component || {}
                                                                const loc = comp.primary_location?.code
                                                                return (
                                                                    <div key={idx} className="flex items-center gap-1.5 truncate">
                                                                        <span className="font-semibold text-slate-800 dark:text-slate-200">
                                                                            x{kc.quantity}
                                                                        </span>
                                                                        <span className="truncate max-w-[160px]" title={comp.name}>
                                                                            {comp.name || comp.sku}
                                                                        </span>
                                                                        {loc ? (
                                                                            <span className="rounded bg-emerald-100 px-1 py-0.2 text-[10px] font-bold text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 shrink-0">
                                                                                📍 {loc}
                                                                            </span>
                                                                        ) : null}
                                                                    </div>
                                                                )
                                                            })}
                                                            {compCount > 3 && (
                                                                <div className="text-[11px] text-slate-400 italic">
                                                                    +{compCount - 3} componente(s) más...
                                                                </div>
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <span className="text-xs text-amber-600 italic">
                                                            Sin componentes asignados
                                                        </span>
                                                    )}
                                                </td>

                                                <td className="px-5 py-4 text-center font-bold text-base text-slate-800 dark:text-slate-200">
                                                    {kit.physical_stock}
                                                </td>

                                                <td className="px-5 py-4 text-center font-bold text-base text-emerald-600 dark:text-emerald-400">
                                                    {kit.available_stock}
                                                </td>

                                                <td className="px-5 py-4 text-center">
                                                    <span
                                                        className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ${
                                                            kit.is_active
                                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                                                                : 'bg-slate-100 text-slate-600 dark:bg-neutral-800 dark:text-slate-300'
                                                        }`}
                                                    >
                                                        {kit.is_active ? 'Activo' : 'Inactivo'}
                                                    </span>
                                                </td>

                                                <td className="px-5 py-4 text-right whitespace-nowrap">
                                                    <div className="flex items-center justify-end gap-3 text-xs font-semibold">
                                                        <Link
                                                            href={`/almacen/kits/${kit.id}`}
                                                            className="text-indigo-600 hover:underline dark:text-indigo-300"
                                                        >
                                                            Ver
                                                        </Link>
                                                        <Link
                                                            href={`/almacen/kits/${kit.id}/editar-componentes`}
                                                            className="rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1 text-slate-700 hover:bg-slate-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                                                        >
                                                            Editar
                                                        </Link>
                                                    </div>
                                                </td>
                                            </tr>
                                        )
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>

                    {/* Controles de paginación */}
                    {kits?.links && kits.links.length > 3 && (
                        <div className="border-t border-slate-200 p-4 dark:border-neutral-800">
                            <Pagination links={kits.links} />
                        </div>
                    )}
                </div>
            </div>
        </AppShell>
    )
}
