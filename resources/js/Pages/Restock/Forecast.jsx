import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

export default function RestockForecast({
    filters = {},
    summary = {},
    items = [],
    brands = [],
    brandConfigs = {},
    presets = {},
}) {
    const [selectedBrand, setSelectedBrand] = useState(filters.brand || '')
    const [selectedStatus, setSelectedStatus] = useState(filters.status || '')
    const [selectedPreset, setSelectedPreset] = useState(filters.cadence_preset || '')
    const [search, setSearch] = useState(filters.search || '')

    // Modal state for brand configuration
    const [configModalOpen, setConfigModalOpen] = useState(false)
    const [editingBrand, setEditingBrand] = useState('')
    const [configPreset, setConfigPreset] = useState('BIWEEKLY_15_20')
    const [configSupplier, setConfigSupplier] = useState('')
    const [configLeadTime, setConfigLeadTime] = useState(7)
    const [configCoverage, setConfigCoverage] = useState(15)
    const [configSafetyStock, setConfigSafetyStock] = useState(5)
    const [configNotes, setConfigNotes] = useState('')
    const [savingConfig, setSavingConfig] = useState(false)

    const applyFilter = (newFilters) => {
        const query = {
            brand: selectedBrand,
            status: selectedStatus,
            cadence_preset: selectedPreset,
            search: search,
            ...newFilters,
        }

        Object.keys(query).forEach((key) => {
            if (!query[key]) delete query[key]
        })

        router.get('/reabastecimiento/pronostico', query, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    const handleSearchSubmit = (e) => {
        e.preventDefault()
        applyFilter({ search })
    }

    const openConfigModal = (brandName) => {
        setEditingBrand(brandName)
        const existing = brandConfigs[brandName]

        if (existing) {
            setConfigPreset(existing.cadence_preset || 'BIWEEKLY_15_20')
            setConfigSupplier(existing.supplier || '')
            setConfigLeadTime(existing.lead_time_days ?? 7)
            setConfigCoverage(existing.target_coverage_days ?? 15)
            setConfigSafetyStock(existing.safety_stock_days ?? 5)
            setConfigNotes(existing.notes || '')
        } else {
            setConfigPreset('BIWEEKLY_15_20')
            setConfigSupplier('')
            setConfigLeadTime(7)
            setConfigCoverage(15)
            setConfigSafetyStock(5)
            setConfigNotes('')
        }

        setConfigModalOpen(true)
    }

    const handlePresetChange = (presetKey) => {
        setConfigPreset(presetKey)
        const defaults = presets[presetKey]
        if (defaults) {
            setConfigLeadTime(defaults.default_lead_time)
            setConfigCoverage(defaults.default_coverage)
            setConfigSafetyStock(defaults.default_safety_stock)
        }
    }

    const handleSaveConfiguration = async (e) => {
        e.preventDefault()
        setSavingConfig(true)
        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            const res = await fetch('/reabastecimiento/configuraciones', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: JSON.stringify({
                    brand: editingBrand,
                    supplier: configSupplier,
                    cadence_preset: configPreset,
                    lead_time_days: Number(configLeadTime),
                    target_coverage_days: Number(configCoverage),
                    safety_stock_days: Number(configSafetyStock),
                    notes: configNotes,
                }),
            })

            const data = await res.json()
            if (!res.ok || !data.ok) {
                window.alert('Error al guardar: ' + (data.error || 'Verifica los campos.'))
                return
            }

            setConfigModalOpen(false)
            router.reload()
        } catch (err) {
            window.alert('Error de red: ' + err.message)
        } finally {
            setSavingConfig(false)
        }
    }

    // Export link generator
    const getExportUrl = () => {
        const params = new URLSearchParams()
        if (selectedBrand) params.set('brand', selectedBrand)
        if (selectedStatus) params.set('status', selectedStatus)
        if (selectedPreset) params.set('cadence_preset', selectedPreset)
        if (search) params.set('search', search)
        return `/reabastecimiento/exportar?${params.toString()}`
    }

    const getStatusBadge = (status) => {
        switch (status) {
            case 'CRITICAL':
                return (
                    <span className="inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-bold text-red-700 dark:bg-red-950/60 dark:text-red-300">
                        <span className="h-1.5 w-1.5 rounded-full bg-red-600 animate-pulse" />
                        Crítico / Quiebre
                    </span>
                )
            case 'WARNING':
                return (
                    <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-bold text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">
                        <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                        Punto de Reorden
                    </span>
                )
            case 'OPTIMAL':
                return (
                    <span className="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-bold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                        <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                        Óptimo
                    </span>
                )
            case 'OVERSTOCK':
                return (
                    <span className="inline-flex items-center gap-1 rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-bold text-blue-700 dark:bg-blue-950/60 dark:text-blue-300">
                        <span className="h-1.5 w-1.5 rounded-full bg-blue-500" />
                        Sobrestock
                    </span>
                )
            default:
                return null
        }
    }

    return (
        <AppShell>
            <Head title="Pronóstico de Reabastecimiento - Compras" />

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-6">
                {/* HEADER */}
                <div className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex items-center gap-3">
                        <div className="rounded-xl bg-indigo-600 p-3 text-white shadow-md">
                            <span className="text-2xl">📈</span>
                        </div>
                        <div>
                            <h1 className="text-xl font-bold tracking-tight text-slate-900 dark:text-white">
                                Pronóstico de Reabastecimiento & Compra Óptima
                            </h1>
                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                Demanda multicanal consolidada (MeLi + Shopify + Amazon + Mostrador POS) con cadencias de 7 a 120 días y estacionalidad.
                            </p>
                        </div>
                    </div>

                    <Link
                        href={`/compras/ordenes/crear${selectedBrand ? `?brand=${encodeURIComponent(selectedBrand)}` : ''}`}
                        className="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-bold text-white shadow hover:bg-indigo-700 transition"
                    >
                        <span>📦</span> Generar Orden de Compra {selectedBrand ? `(${selectedBrand})` : ''}
                    </Link>
                    <a
                        href={getExportUrl()}
                        download
                        className="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-xs font-bold text-white shadow hover:bg-black transition dark:bg-neutral-800 dark:hover:bg-neutral-700"
                    >
                        <span>⬇️</span> Exportar CSV para Orden de Compra
                    </a>
                </div>

                {/* KPI METRIC CARDS */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {/* CRITICAL */}
                    <div
                        onClick={() => {
                            setSelectedStatus(selectedStatus === 'CRITICAL' ? '' : 'CRITICAL')
                            applyFilter({ status: selectedStatus === 'CRITICAL' ? '' : 'CRITICAL' })
                        }}
                        className={`cursor-pointer rounded-2xl border p-4 shadow-sm transition ${
                            selectedStatus === 'CRITICAL'
                                ? 'border-red-500 bg-red-50/70 ring-2 ring-red-400 dark:bg-red-950/30'
                                : 'border-slate-200 bg-white hover:border-red-300 dark:border-neutral-800 dark:bg-neutral-900'
                        }`}
                    >
                        <span className="text-[11px] font-bold uppercase tracking-wider text-red-600 dark:text-red-400">
                            Críticos / Quiebre
                        </span>
                        <div className="mt-1 flex items-baseline gap-1">
                            <span className="text-2xl font-black text-slate-900 dark:text-white">
                                {summary.critical_count || 0}
                            </span>
                            <span className="text-xs text-slate-400">SKUs</span>
                        </div>
                    </div>

                    {/* REORDER WARNING */}
                    <div
                        onClick={() => {
                            setSelectedStatus(selectedStatus === 'WARNING' ? '' : 'WARNING')
                            applyFilter({ status: selectedStatus === 'WARNING' ? '' : 'WARNING' })
                        }}
                        className={`cursor-pointer rounded-2xl border p-4 shadow-sm transition ${
                            selectedStatus === 'WARNING'
                                ? 'border-amber-500 bg-amber-50/70 ring-2 ring-amber-400 dark:bg-amber-950/30'
                                : 'border-slate-200 bg-white hover:border-amber-300 dark:border-neutral-800 dark:bg-neutral-900'
                        }`}
                    >
                        <span className="text-[11px] font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400">
                            Punto Reorden
                        </span>
                        <div className="mt-1 flex items-baseline gap-1">
                            <span className="text-2xl font-black text-slate-900 dark:text-white">
                                {summary.warning_count || 0}
                            </span>
                            <span className="text-xs text-slate-400">SKUs</span>
                        </div>
                    </div>

                    {/* OPTIMAL */}
                    <div
                        onClick={() => {
                            setSelectedStatus(selectedStatus === 'OPTIMAL' ? '' : 'OPTIMAL')
                            applyFilter({ status: selectedStatus === 'OPTIMAL' ? '' : 'OPTIMAL' })
                        }}
                        className={`cursor-pointer rounded-2xl border p-4 shadow-sm transition ${
                            selectedStatus === 'OPTIMAL'
                                ? 'border-emerald-500 bg-emerald-50/70 ring-2 ring-emerald-400 dark:bg-emerald-950/30'
                                : 'border-slate-200 bg-white hover:border-emerald-300 dark:border-neutral-800 dark:bg-neutral-900'
                        }`}
                    >
                        <span className="text-[11px] font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">
                            Saludable / Óptimo
                        </span>
                        <div className="mt-1 flex items-baseline gap-1">
                            <span className="text-2xl font-black text-slate-900 dark:text-white">
                                {summary.optimal_count || 0}
                            </span>
                            <span className="text-xs text-slate-400">SKUs</span>
                        </div>
                    </div>

                    {/* OVERSTOCK */}
                    <div
                        onClick={() => {
                            setSelectedStatus(selectedStatus === 'OVERSTOCK' ? '' : 'OVERSTOCK')
                            applyFilter({ status: selectedStatus === 'OVERSTOCK' ? '' : 'OVERSTOCK' })
                        }}
                        className={`cursor-pointer rounded-2xl border p-4 shadow-sm transition ${
                            selectedStatus === 'OVERSTOCK'
                                ? 'border-blue-500 bg-blue-50/70 ring-2 ring-blue-400 dark:bg-blue-950/30'
                                : 'border-slate-200 bg-white hover:border-blue-300 dark:border-neutral-800 dark:bg-neutral-900'
                        }`}
                    >
                        <span className="text-[11px] font-bold uppercase tracking-wider text-blue-600 dark:text-blue-400">
                            Sobrestock
                        </span>
                        <div className="mt-1 flex items-baseline gap-1">
                            <span className="text-2xl font-black text-slate-900 dark:text-white">
                                {summary.overstock_count || 0}
                            </span>
                            <span className="text-xs text-slate-400">SKUs</span>
                        </div>
                    </div>

                    {/* SUGGESTED UNITS */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">
                            Piezas a Comprar
                        </span>
                        <div className="mt-1 flex items-baseline gap-1">
                            <span className="text-2xl font-black text-indigo-600 dark:text-indigo-400">
                                {summary.total_suggested_units?.toLocaleString() || 0}
                            </span>
                            <span className="text-xs text-slate-400">uds</span>
                        </div>
                    </div>

                    {/* INVESTMENT */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                            Inversión Sugerida
                        </span>
                        <div className="mt-1 flex items-baseline gap-1">
                            <span className="font-mono text-xl font-black text-slate-900 dark:text-white">
                                ${summary.total_estimated_investment ? Number(summary.total_estimated_investment).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '0.00'}
                            </span>
                        </div>
                    </div>
                </div>

                {/* CADENCE PRESET TABS (INCLUDING 90-120 DAYS) */}
                <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 pb-3 dark:border-neutral-800">
                    <span className="text-xs font-bold text-slate-500 mr-2">Horizonte / Cadencia:</span>

                    <button
                        type="button"
                        onClick={() => {
                            setSelectedPreset('')
                            applyFilter({ cadence_preset: '' })
                        }}
                        className={`rounded-xl px-3.5 py-1.5 text-xs font-semibold transition ${
                            !selectedPreset
                                ? 'bg-indigo-600 text-white shadow'
                                : 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-neutral-800 dark:text-slate-300'
                        }`}
                    >
                        Todas las Cadencias
                    </button>

                    <button
                        type="button"
                        onClick={() => {
                            setSelectedPreset('WEEKLY_7_10')
                            applyFilter({ cadence_preset: 'WEEKLY_7_10' })
                        }}
                        className={`rounded-xl px-3.5 py-1.5 text-xs font-semibold transition ${
                            selectedPreset === 'WEEKLY_7_10'
                                ? 'bg-indigo-600 text-white shadow'
                                : 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-neutral-800 dark:text-slate-300'
                        }`}
                    >
                        Semanal (7 - 10 días)
                    </button>

                    <button
                        type="button"
                        onClick={() => {
                            setSelectedPreset('BIWEEKLY_15_20')
                            applyFilter({ cadence_preset: 'BIWEEKLY_15_20' })
                        }}
                        className={`rounded-xl px-3.5 py-1.5 text-xs font-semibold transition ${
                            selectedPreset === 'BIWEEKLY_15_20'
                                ? 'bg-indigo-600 text-white shadow'
                                : 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-neutral-800 dark:text-slate-300'
                        }`}
                    >
                        Quincenal (15 - 20 días)
                    </button>

                    <button
                        type="button"
                        onClick={() => {
                            setSelectedPreset('MONTHLY_30_45')
                            applyFilter({ cadence_preset: 'MONTHLY_30_45' })
                        }}
                        className={`rounded-xl px-3.5 py-1.5 text-xs font-semibold transition ${
                            selectedPreset === 'MONTHLY_30_45'
                                ? 'bg-indigo-600 text-white shadow'
                                : 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-neutral-800 dark:text-slate-300'
                        }`}
                    >
                        Mensual (30 - 45 días)
                    </button>

                    {/* HIGHLIGHTED 90-120 DAYS IMPORT CADENCE AS REQUESTED BY USER */}
                    <button
                        type="button"
                        onClick={() => {
                            setSelectedPreset('IMPORT_90_120')
                            applyFilter({ cadence_preset: 'IMPORT_90_120' })
                        }}
                        className={`rounded-xl px-3.5 py-1.5 text-xs font-extrabold transition flex items-center gap-1.5 ${
                            selectedPreset === 'IMPORT_90_120'
                                ? 'bg-purple-700 text-white shadow-md ring-2 ring-purple-400'
                                : 'bg-purple-100 text-purple-800 hover:bg-purple-200 dark:bg-purple-950/60 dark:text-purple-300'
                        }`}
                    >
                        <span>🚢</span> Largo Plazo / Importación (90 - 120 días)
                    </button>
                </div>

                {/* FILTERS TOOLBAR */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        {/* Brand Filter */}
                        <div className="flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                            <span className="text-slate-400">🏷️</span>
                            <select
                                value={selectedBrand}
                                onChange={(e) => {
                                    setSelectedBrand(e.target.value)
                                    applyFilter({ brand: e.target.value })
                                }}
                                className="bg-transparent font-medium text-slate-800 outline-none dark:text-slate-200"
                            >
                                <option value="">Todas las marcas</option>
                                {brands.map((b) => (
                                    <option key={b} value={b}>
                                        {b}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {/* Reset filters */}
                        {(selectedBrand || selectedStatus || selectedPreset || search) && (
                            <button
                                type="button"
                                onClick={() => {
                                    setSelectedBrand('')
                                    setSelectedStatus('')
                                    setSelectedPreset('')
                                    setSearch('')
                                    router.get('/reabastecimiento/pronostico')
                                }}
                                className="text-xs font-semibold text-red-500 hover:underline"
                            >
                                Limpiar filtros
                            </button>
                        )}
                    </div>

                    {/* Search Form */}
                    <form onSubmit={handleSearchSubmit} className="flex items-center gap-2">
                        <div className="flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs dark:border-neutral-700 dark:bg-neutral-800 w-64">
                            <span className="text-slate-400">🔍</span>
                            <input
                                type="text"
                                placeholder="Buscar por SKU o nombre..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="w-full bg-transparent outline-none dark:text-white"
                            />
                        </div>
                        <button
                            type="submit"
                            className="rounded-xl bg-indigo-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-indigo-700"
                        >
                            Filtrar
                        </button>
                    </form>
                </div>

                {/* FORECAST DATA TABLE */}
                <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <table className="w-full text-left text-xs">
                        <thead className="border-b border-slate-200 bg-slate-50/70 text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:border-neutral-800 dark:bg-neutral-950">
                            <tr>
                                <th className="p-3.5">SKU & Producto</th>
                                <th className="p-3.5">Marca / Proveedor</th>
                                <th className="p-3.5 text-center">Estado</th>
                                <th className="p-3.5 text-center">Stock Disp.</th>
                                <th className="p-3.5 text-center">Venta Diaria</th>
                                <th className="p-3.5 text-center">Cobertura</th>
                                <th className="p-3.5 text-center">Parámetros</th>
                                <th className="p-3.5 text-right">Compra Sugerida</th>
                                <th className="p-3.5 text-right">Inversión</th>
                                <th className="p-3.5 text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100 dark:divide-neutral-800 font-medium">
                            {items.length === 0 ? (
                                <tr>
                                    <td colSpan={10} className="py-12 text-center text-sm text-slate-400">
                                        No se encontraron productos con los filtros seleccionados.
                                    </td>
                                </tr>
                            ) : (
                                items.map((item) => (
                                    <tr
                                        key={item.product_id}
                                        className="transition hover:bg-slate-50/60 dark:hover:bg-neutral-950/40"
                                    >
                                        <td className="p-3.5 max-w-[220px]">
                                            <span className="font-mono font-bold text-indigo-600 dark:text-indigo-400 block">
                                                {item.sku}
                                            </span>
                                            <span className="line-clamp-2 text-slate-800 dark:text-slate-200 font-semibold">
                                                {item.name}
                                            </span>
                                        </td>

                                        <td className="p-3.5">
                                            <span className="font-semibold text-slate-800 dark:text-slate-200 block">
                                                {item.brand}
                                            </span>
                                            <span className="text-[11px] text-slate-400">
                                                {item.supplier}
                                            </span>
                                        </td>

                                        <td className="p-3.5 text-center">
                                            {getStatusBadge(item.status)}
                                        </td>

                                        <td className="p-3.5 text-center">
                                            <span className="font-mono text-sm font-bold text-slate-900 dark:text-white block">
                                                {item.available_stock}
                                            </span>
                                            <span className="text-[10px] text-slate-400">
                                                Físico: {item.physical_stock} | Res: {item.reserved_stock}
                                            </span>
                                        </td>

                                        <td className="p-3.5 text-center">
                                            <span className="font-mono font-bold text-slate-800 dark:text-slate-200 block">
                                                {item.expected_daily_demand}
                                            </span>
                                            <span className="text-[10px] text-slate-400">
                                                30d: {item.sales_30d} | Estac: {item.seasonal_factor}x
                                            </span>
                                        </td>

                                        <td className="p-3.5 text-center">
                                            <span
                                                className={`font-mono font-bold ${
                                                    item.days_of_stock_remaining <= item.lead_time_days
                                                        ? 'text-red-600 dark:text-red-400'
                                                        : item.days_of_stock_remaining <= 30
                                                        ? 'text-amber-600 dark:text-amber-400'
                                                        : 'text-slate-700 dark:text-slate-300'
                                                }`}
                                            >
                                                {item.days_of_stock_remaining > 365 ? '>1 año' : `${item.days_of_stock_remaining} días`}
                                            </span>
                                        </td>

                                        <td className="p-3.5 text-center text-[10px] text-slate-500">
                                            <span className="block font-semibold text-slate-700 dark:text-slate-300">
                                                {item.target_coverage_days}d cob. + {item.lead_time_days}d entrega
                                            </span>
                                            <span>Colchón: {item.safety_stock_units} uds</span>
                                        </td>

                                        <td className="p-3.5 text-right font-mono">
                                            {item.suggested_quantity > 0 ? (
                                                <span className="inline-block rounded-lg bg-indigo-50 px-2 py-1 text-sm font-extrabold text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300">
                                                    +{item.suggested_quantity} uds
                                                </span>
                                            ) : (
                                                <span className="text-slate-400 text-xs">0 (cubierto)</span>
                                            )}
                                        </td>

                                        <td className="p-3.5 text-right font-mono">
                                            {item.estimated_investment > 0 ? (
                                                <span className="font-bold text-slate-900 dark:text-white">
                                                    ${Number(item.estimated_investment).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                                </span>
                                            ) : (
                                                <span className="text-slate-400">$0.00</span>
                                            )}
                                        </td>

                                        <td className="p-3.5 text-center">
                                            {item.brand && item.brand !== 'Sin marca' && (
                                                <button
                                                    type="button"
                                                    onClick={() => openConfigModal(item.brand)}
                                                    className="rounded-lg border border-slate-200 px-2 py-1 text-[11px] font-semibold text-slate-600 hover:bg-slate-100 dark:border-neutral-700 dark:text-slate-300"
                                                    title="Configurar cadencia para esta marca"
                                                >
                                                    ⚙️ Cadencia
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* MODAL: BRAND RESTOCK CONFIGURATION */}
            {configModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                    <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl dark:bg-neutral-900 max-h-[90vh] overflow-y-auto">
                        <div className="flex items-center justify-between border-b pb-3 dark:border-neutral-800">
                            <div>
                                <h3 className="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                    <span>⚙️</span> Configuración de Reabastecimiento
                                </h3>
                                <p className="text-xs text-indigo-600 dark:text-indigo-400 font-bold">
                                    Marca: {editingBrand}
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setConfigModalOpen(false)}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                ✕
                            </button>
                        </div>

                        <form onSubmit={handleSaveConfiguration} className="mt-4 space-y-4 text-xs">
                            {/* PRESET PICKER */}
                            <div>
                                <label className="block text-xs font-bold text-slate-700 dark:text-slate-300">
                                    Cadencia / Horizonte de Compra:
                                </label>
                                <div className="mt-1.5 space-y-1.5">
                                    {Object.entries(presets).map(([key, p]) => (
                                        <div
                                            key={key}
                                            onClick={() => handlePresetChange(key)}
                                            className={`cursor-pointer rounded-xl border p-2.5 transition ${
                                                configPreset === key
                                                    ? 'border-indigo-600 bg-indigo-50/60 dark:border-indigo-500 dark:bg-indigo-950/40'
                                                    : 'border-slate-200 hover:bg-slate-50 dark:border-neutral-800 dark:hover:bg-neutral-950'
                                            }`}
                                        >
                                            <div className="flex items-center justify-between">
                                                <span className="font-bold text-slate-900 dark:text-white">
                                                    {p.label}
                                                </span>
                                                <input
                                                    type="radio"
                                                    checked={configPreset === key}
                                                    onChange={() => handlePresetChange(key)}
                                                />
                                            </div>
                                            <p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">
                                                {p.description}
                                            </p>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            {/* SUPPLIER */}
                            <div>
                                <label className="block font-semibold text-slate-700 dark:text-slate-300">
                                    Proveedor preferido:
                                </label>
                                <input
                                    type="text"
                                    value={configSupplier}
                                    onChange={(e) => setConfigSupplier(e.target.value)}
                                    placeholder="Nombre del proveedor o distribuidor"
                                    className="mt-1 w-full rounded-xl border border-slate-300 p-2 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            {/* PARAMETERS GRID */}
                            <div className="grid grid-cols-3 gap-2">
                                <div>
                                    <label className="block font-semibold text-slate-700 dark:text-slate-300">
                                        Entrega (Días):
                                    </label>
                                    <input
                                        type="number"
                                        min="1"
                                        required
                                        value={configLeadTime}
                                        onChange={(e) => setConfigLeadTime(e.target.value)}
                                        className="mt-1 w-full rounded-xl border border-slate-300 p-2 font-mono font-bold dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                    <span className="text-[10px] text-slate-400">Lead time</span>
                                </div>

                                <div>
                                    <label className="block font-semibold text-slate-700 dark:text-slate-300">
                                        Cobertura (Días):
                                    </label>
                                    <input
                                        type="number"
                                        min="1"
                                        required
                                        value={configCoverage}
                                        onChange={(e) => setConfigCoverage(e.target.value)}
                                        className="mt-1 w-full rounded-xl border border-slate-300 p-2 font-mono font-bold dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                    <span className="text-[10px] text-slate-400">7, 15, 30, 90, 120</span>
                                </div>

                                <div>
                                    <label className="block font-semibold text-slate-700 dark:text-slate-300">
                                        Colchón (Días):
                                    </label>
                                    <input
                                        type="number"
                                        min="0"
                                        required
                                        value={configSafetyStock}
                                        onChange={(e) => setConfigSafetyStock(e.target.value)}
                                        className="mt-1 w-full rounded-xl border border-slate-300 p-2 font-mono font-bold dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                    <span className="text-[10px] text-slate-400">Stock seguridad</span>
                                </div>
                            </div>

                            {/* NOTES */}
                            <div>
                                <label className="block font-semibold text-slate-700 dark:text-slate-300">
                                    Notas o condiciones comerciales:
                                </label>
                                <textarea
                                    rows={2}
                                    value={configNotes}
                                    onChange={(e) => setConfigNotes(e.target.value)}
                                    placeholder="ej. Mínimo de compra $50,000 para flete gratis..."
                                    className="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div className="flex justify-end gap-2 pt-2 border-t dark:border-neutral-800">
                                <button
                                    type="button"
                                    onClick={() => setConfigModalOpen(false)}
                                    className="rounded-xl border px-3 py-2 font-semibold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    disabled={savingConfig}
                                    className="rounded-xl bg-indigo-600 px-4 py-2 font-bold text-white shadow hover:bg-indigo-700 disabled:opacity-40"
                                >
                                    {savingConfig ? 'Guardando...' : 'Guardar Configuración'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AppShell>
    )
}
