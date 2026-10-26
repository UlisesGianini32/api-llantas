import { Head, Link, router } from '@inertiajs/react'
import { useState, useMemo } from 'react'
import AppShell from '@/Components/layout/AppShell'
import Pagination from '@/Components/ui/Pagination'

function formatMoney(amount) {
    const val = Number(amount || 0)
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    }).format(val)
}

function formatPercent(val) {
    const n = Number(val || 0)
    return `${n.toFixed(1)}%`
}

function StatCard({ title, value, subtitle, badge, badgeColor = 'bg-slate-100 text-slate-700 dark:bg-neutral-800 dark:text-neutral-300', accentBorder = 'border-slate-200/80 dark:border-neutral-800/80', isHighlight = false, highlightBg = '' }) {
    return (
        <div className={`relative overflow-hidden rounded-2xl border p-5 shadow-xs transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md ${accentBorder} ${isHighlight ? highlightBg : 'bg-white dark:bg-neutral-900'}`}>
            <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-neutral-400">{title}</span>
                {badge && (
                    <span className={`rounded-md px-2 py-0.5 text-[11px] font-bold ${badgeColor}`}>
                        {badge}
                    </span>
                )}
            </div>
            <p className="mt-3 text-2xl font-black tracking-tight text-slate-900 dark:text-white md:text-3xl">{value}</p>
            {subtitle && <p className="mt-1 text-xs text-slate-500 dark:text-neutral-400">{subtitle}</p>}
        </div>
    )
}

export default function ProfitabilityIndex({ metrics = {}, timeline = [], orders = { data: [] }, filters = {}, error = null }) {
    // Estado local para filtros reactivos
    const [period, setPeriod] = useState(filters.period || 'este_mes')
    const [from, setFrom] = useState(filters.from || '')
    const [to, setTo] = useState(filters.to || '')
    const [channel, setChannel] = useState(filters.channel || 'all')
    const [status, setStatus] = useState(filters.status || 'all')
    const [search, setSearch] = useState(filters.search || '')
    const [fallbackFeePct, setFallbackFeePct] = useState(filters.fallback_fee_pct ?? 15)

    // Simulador interactivo en cliente (permite mover slider en tiempo real sin recargar)
    const [commissionPct, setCommissionPct] = useState(Number(filters.commission_pct ?? 10))
    const [commissionBase, setCommissionBase] = useState(filters.commission_base || 'profit')

    // Cálculo dinámico de comisiones en el cliente
    const currentBaseAmount = commissionBase === 'sales'
        ? Number(metrics.total_sales_net || 0)
        : Math.max(0, Number(metrics.total_net_profit || 0))

    const simulatedUserCommission = useMemo(() => {
        return Math.round(currentBaseAmount * (commissionPct / 100) * 100) / 100
    }, [currentBaseAmount, commissionPct])

    const simulatedCompanyProfit = useMemo(() => {
        return Math.round((Number(metrics.total_net_profit || 0) - simulatedUserCommission) * 100) / 100
    }, [metrics.total_net_profit, simulatedUserCommission])

    const simulatedCompanySharePct = useMemo(() => {
        const netProfit = Number(metrics.total_net_profit || 0)
        if (netProfit <= 0) return 100
        return Math.max(0, Math.min(100, Math.round((simulatedCompanyProfit / netProfit) * 1000) / 10))
    }, [metrics.total_net_profit, simulatedCompanyProfit])

    // Desglose porcentual de la venta neta
    const costBreakdown = useMemo(() => {
        const netSale = Number(metrics.total_sales_net || 0)
        if (netSale <= 0) return { costPct: 0, feesPct: 0, profitPct: 0 }
        const costPct = (Number(metrics.total_product_cost || 0) / netSale) * 100
        const feesPct = ((Number(metrics.total_meli_fees || 0) + Number(metrics.total_shipping_fees || 0)) / netSale) * 100
        const profitPct = (Number(metrics.total_net_profit || 0) / netSale) * 100
        return {
            costPct: Math.max(0, costPct),
            feesPct: Math.max(0, feesPct),
            profitPct: Math.max(0, profitPct),
        }
    }, [metrics])

    const applyFilters = (overrides = {}) => {
        const newFilters = {
            period,
            from,
            to,
            channel,
            status,
            search,
            commission_pct: commissionPct,
            commission_base: commissionBase,
            fallback_fee_pct: fallbackFeePct,
            ...overrides,
        }

        router.get('/rentabilidad-comisiones', newFilters, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    const handlePeriodChange = (newPeriod) => {
        setPeriod(newPeriod)
        applyFilters({ period: newPeriod })
    }

    const handleChannelTab = (tabChannel) => {
        setChannel(tabChannel)
        applyFilters({ channel: tabChannel })
    }

    const handleSearchSubmit = (e) => {
        e.preventDefault()
        applyFilters()
    }

    const exportUrl = useMemo(() => {
        const params = new URLSearchParams({
            period,
            from,
            to,
            channel,
            status,
            search,
            commission_pct: commissionPct,
            commission_base: commissionBase,
            fallback_fee_pct: fallbackFeePct,
        })
        return `/rentabilidad-comisiones/export?${params.toString()}`
    }, [period, from, to, channel, status, search, commissionPct, commissionBase, fallbackFeePct])

    return (
        <AppShell>
            <Head title="Rentabilidad y Propuesta de Comisiones" />

            <div className="space-y-6 pb-12">
                {error && (
                    <div className="rounded-2xl border border-amber-300 bg-amber-50 p-4 shadow-xs dark:border-amber-800 dark:bg-amber-950/40">
                        <div className="flex items-center gap-2 text-amber-800 dark:text-amber-200">
                            <svg className="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                            <span className="font-bold text-sm">Aviso de diagnóstico del servidor:</span>
                        </div>
                        <p className="mt-1 text-xs text-amber-700 dark:text-amber-300 font-mono">{error}</p>
                    </div>
                )}

                {/* ENCABEZADO EJECUTIVO */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between print:block">
                    <div>
                        <div className="flex items-center gap-2">
                            <h1 className="text-2xl font-black tracking-tight text-slate-900 dark:text-white sm:text-3xl">
                                Rentabilidad y Propuesta de Comisiones
                            </h1>
                            <span className="rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                                SYSCOM & Llantas
                            </span>
                        </div>
                        <p className="mt-1 text-sm text-slate-600 dark:text-neutral-400">
                            Cálculo transparente de margen real de tus ventas de <strong>SYSCOM</strong> y <strong>Llantas</strong>, deduciendo costo de mercancía, tarifas de Mercado Libre, envíos y cancelaciones/devoluciones. El catálogo general de belleza queda automáticamente excluido.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2.5 print:hidden">
                        <a
                            href={exportUrl}
                            className="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200 dark:hover:bg-neutral-700"
                        >
                            <svg className="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            Exportar a Excel / CSV
                        </a>

                        <button
                            type="button"
                            onClick={() => window.print()}
                            className="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2 text-xs font-semibold text-white shadow-2xs hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100"
                        >
                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                            </svg>
                            Imprimir / Guardar PDF
                        </button>
                    </div>
                </div>

                {/* FILTROS Y CONTROLES TEMPORALES */}
                <div className="rounded-2xl border border-slate-200/90 bg-white p-4 shadow-2xs dark:border-neutral-800 dark:bg-neutral-900 print:hidden">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                        {/* Selector de periodos predefinidos */}
                        <div className="flex flex-wrap items-center gap-1.5">
                            <span className="mr-1 text-xs font-semibold text-slate-500 dark:text-neutral-400">Periodo:</span>
                            {[
                                { key: 'hoy', label: 'Hoy' },
                                { key: 'esta_semana', label: 'Esta Semana' },
                                { key: 'este_mes', label: 'Este Mes' },
                                { key: 'mes_pasado', label: 'Mes Pasado' },
                                { key: 'ultimos_30', label: 'Últimos 30 días' },
                                { key: 'anio_actual', label: 'Año en Curso' },
                                { key: 'custom', label: 'Personalizado' },
                            ].map((btn) => (
                                <button
                                    key={btn.key}
                                    type="button"
                                    onClick={() => handlePeriodChange(btn.key)}
                                    className={`rounded-lg px-3 py-1.5 text-xs font-medium transition ${
                                        period === btn.key
                                            ? 'bg-indigo-600 text-white shadow-2xs'
                                            : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700'
                                    }`}
                                >
                                    {btn.label}
                                </button>
                            ))}
                        </div>

                        {/* Estado de entrega y buscador */}
                        <div className="flex flex-wrap items-center gap-3">
                            {/* Filtro estado */}
                            <select
                                value={status}
                                onChange={(e) => {
                                    setStatus(e.target.value)
                                    applyFilters({ status: e.target.value })
                                }}
                                className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-medium text-slate-700 focus:border-indigo-500 focus:outline-hidden dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                            >
                                <option value="all">Todas las órdenes</option>
                                <option value="ok">Solo Efectivas (Entregadas)</option>
                                <option value="cancelled_returned">Solo Canceladas / Devoluciones</option>
                            </select>

                            {/* Búsqueda rápida */}
                            <form onSubmit={handleSearchSubmit} className="relative flex items-center">
                                <input
                                    type="text"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Buscar orden, SKU, folio..."
                                    className="w-48 rounded-xl border border-slate-200 bg-slate-50 py-1.5 pr-8 pl-3 text-xs text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:bg-white focus:outline-hidden dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 sm:w-60"
                                />
                                {search && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setSearch('')
                                            applyFilters({ search: '' })
                                        }}
                                        className="absolute right-2 text-slate-400 hover:text-slate-600 dark:hover:text-neutral-200"
                                    >
                                        ✕
                                    </button>
                                )}
                            </form>
                        </div>
                    </div>

                    {/* Rango personalizado de fechas */}
                    {period === 'custom' && (
                        <div className="mt-3 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-3 dark:border-neutral-800">
                            <div className="flex items-center gap-2">
                                <label className="text-xs text-slate-500 dark:text-neutral-400">Desde:</label>
                                <input
                                    type="date"
                                    value={from}
                                    onChange={(e) => setFrom(e.target.value)}
                                    className="rounded-lg border border-slate-200 px-2.5 py-1 text-xs dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                />
                            </div>
                            <div className="flex items-center gap-2">
                                <label className="text-xs text-slate-500 dark:text-neutral-400">Hasta:</label>
                                <input
                                    type="date"
                                    value={to}
                                    onChange={(e) => setTo(e.target.value)}
                                    className="rounded-lg border border-slate-200 px-2.5 py-1 text-xs dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200"
                                />
                            </div>
                            <button
                                type="button"
                                onClick={() => applyFilters()}
                                className="rounded-lg bg-indigo-600 px-3 py-1 text-xs font-semibold text-white hover:bg-indigo-700"
                            >
                                Aplicar Fechas
                            </button>
                        </div>
                    )}
                </div>

                {/* PESTAÑAS DE CANAL (SYSCOM Y LLANTAS / SOLO SYSCOM / SOLO LLANTAS) */}
                <div className="flex flex-wrap items-center justify-between border-b border-slate-200 dark:border-neutral-800 print:hidden">
                    <div className="flex items-center">
                        {[
                            { key: 'all', label: 'SYSCOM y Llantas (Ambos)', count: metrics.channel_counts?.all ?? metrics.total_orders_count },
                            { key: 'syscom', label: 'Solo SYSCOM', count: metrics.channel_counts?.syscom ?? metrics.syscom?.total_orders ?? 0 },
                            { key: 'llantas', label: 'Solo Llantas', count: metrics.channel_counts?.llantas ?? metrics.llantas?.total_orders ?? 0 },
                        ].map((tab) => (
                            <button
                                key={tab.key}
                                type="button"
                                onClick={() => handleChannelTab(tab.key)}
                                className={`flex items-center gap-2 border-b-2 px-5 py-3 text-sm font-semibold transition ${
                                    channel === tab.key
                                        ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                                        : 'border-transparent text-slate-500 hover:border-slate-300 hover:text-slate-800 dark:text-neutral-400 dark:hover:text-neutral-200'
                                }`}
                            >
                                <span>{tab.label}</span>
                                <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600 dark:bg-neutral-800 dark:text-neutral-400">
                                    {tab.count}
                                </span>
                            </button>
                        ))}
                    </div>

                    {(metrics.channel_counts?.otro ?? 0) > 0 && (
                        <div className="hidden pb-2 text-xs text-slate-400 md:block dark:text-neutral-500">
                            <span className="inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-2.5 py-1 text-[11px] font-medium text-slate-500 dark:bg-neutral-800 dark:text-neutral-400">
                                <span className="h-1.5 w-1.5 rounded-full bg-slate-400" />
                                {metrics.channel_counts.otro} pedidos de belleza excluidos
                            </span>
                        </div>
                    )}
                </div>

                {/* TARJETAS DE KPIS FINANCIEROS AUDITADOS */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    {/* 1. Venta Bruta */}
                    <StatCard
                        title="Venta Bruta ML"
                        value={formatMoney(metrics.total_sales_gross)}
                        subtitle={`${metrics.total_orders_count} órdenes procesadas`}
                        badge="Facturado"
                    />

                    {/* 2. Devoluciones & Canceladas */}
                    <StatCard
                        title="Devoluciones"
                        value={`-${formatMoney(metrics.total_returns_amount)}`}
                        subtitle={`${metrics.cancelled_returned_count} pedidos cancelados/devueltos`}
                        badge="Deducido"
                        badgeColor="bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300"
                        accentBorder="border-rose-200/80 dark:border-rose-900/60"
                    />

                    {/* 3. Venta Neta Efectiva */}
                    <StatCard
                        title="Venta Neta Real"
                        value={formatMoney(metrics.total_sales_net)}
                        subtitle={`${metrics.delivered_orders_count} órdenes entregadas`}
                        badge="Ingreso Base"
                        badgeColor="bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300"
                    />

                    {/* 4. Costo de Mercancía */}
                    <StatCard
                        title="Costo de Compra"
                        value={formatMoney(metrics.total_product_cost)}
                        subtitle={`${formatPercent(costBreakdown.costPct)} de la venta neta`}
                        badge="Syscom / Llantas"
                        badgeColor="bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300"
                    />

                    {/* 5. Comisiones y Envíos ML */}
                    <StatCard
                        title="Comisión + Envíos ML"
                        value={formatMoney(Number(metrics.total_meli_fees || 0) + Number(metrics.total_shipping_fees || 0))}
                        subtitle={`Fee: ${formatMoney(metrics.total_meli_fees)} | Envío: ${formatMoney(metrics.total_shipping_fees)}`}
                        badge="Mercado Libre"
                        badgeColor="bg-yellow-100 text-yellow-800 dark:bg-yellow-950 dark:text-yellow-300"
                    />

                    {/* 6. Utilidad Real Neta (Destacada) */}
                    <StatCard
                        title="Ganancia Real Neta"
                        value={formatMoney(metrics.total_net_profit)}
                        subtitle={`Margen neto libre: ${formatPercent(metrics.profit_margin_pct)}`}
                        badge="100% Libre"
                        badgeColor="bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300"
                        isHighlight={true}
                        highlightBg="bg-linear-to-br from-emerald-500/10 via-emerald-500/5 to-white dark:from-emerald-950/40 dark:via-emerald-900/10 dark:bg-neutral-900 border-emerald-500/50"
                    />
                </div>

                {/* BARRA DE DISTRIBUCIÓN DE CADA PESO VENDIDO */}
                <div className="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 className="text-sm font-bold text-slate-800 dark:text-white">
                                Desglose de cada $1.00 de Venta Neta ({formatMoney(metrics.total_sales_net)})
                            </h3>
                            <p className="text-xs text-slate-500 dark:text-neutral-400">
                                Transparencia total: hacia dónde se va el dinero antes de calcular ganancias y comisiones.
                            </p>
                        </div>
                        <div className="flex items-center gap-4 text-xs font-medium">
                            <span className="flex items-center gap-1.5">
                                <span className="h-3 w-3 rounded-xs bg-amber-500" />
                                <span>Costo Mercancía ({formatPercent(costBreakdown.costPct)})</span>
                            </span>
                            <span className="flex items-center gap-1.5">
                                <span className="h-3 w-3 rounded-xs bg-yellow-400" />
                                <span>Tarifas ML & Envíos ({formatPercent(costBreakdown.feesPct)})</span>
                            </span>
                            <span className="flex items-center gap-1.5 font-bold text-emerald-700 dark:text-emerald-400">
                                <span className="h-3 w-3 rounded-xs bg-emerald-500" />
                                <span>Ganancia Libre ({formatPercent(costBreakdown.profitPct)})</span>
                            </span>
                        </div>
                    </div>

                    <div className="mt-3 flex h-4 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-neutral-800">
                        <div
                            className="bg-amber-500 transition-all duration-500"
                            style={{ width: `${Math.min(100, costBreakdown.costPct)}%` }}
                            title={`Costo: ${formatPercent(costBreakdown.costPct)}`}
                        />
                        <div
                            className="bg-yellow-400 transition-all duration-500"
                            style={{ width: `${Math.min(100 - costBreakdown.costPct, costBreakdown.feesPct)}%` }}
                            title={`Tarifas ML: ${formatPercent(costBreakdown.feesPct)}`}
                        />
                        <div
                            className="bg-emerald-500 transition-all duration-500"
                            style={{ width: `${Math.max(0, costBreakdown.profitPct)}%` }}
                            title={`Ganancia Neta: ${formatPercent(costBreakdown.profitPct)}`}
                        />
                    </div>
                </div>

                {/* SECCIÓN DEL SIMULADOR INTERACTIVO DE COMISIONES (PROPUESTA AL JEFE) */}
                <div className="rounded-3xl border-2 border-indigo-500/30 bg-gradient-to-b from-indigo-500/5 via-white to-white p-6 shadow-sm dark:from-indigo-950/20 dark:via-neutral-900 dark:to-neutral-900">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <div>
                            <div className="flex items-center gap-2">
                                <span className="rounded-lg bg-indigo-600 px-2.5 py-1 text-xs font-bold text-white uppercase tracking-wider">
                                    Herramienta de Negociación
                                </span>
                                <h2 className="text-xl font-black text-slate-900 dark:text-white">
                                    Simulador Interactivo de Propuesta de Comisión
                                </h2>
                            </div>
                            <p className="mt-1 text-xs text-slate-600 dark:text-neutral-400">
                                Ajusta el porcentaje para demostrar a tu jefe cómo ambos ganan: la empresa cubre todos sus gastos y asegura un retorno libre, mientras tú recibes una comisión justa y motivadora.
                            </p>
                        </div>

                        {/* Switch de Base de Cálculo: Sobre Ganancia vs Sobre Ventas */}
                        <div className="flex items-center rounded-xl bg-slate-100 p-1 dark:bg-neutral-800">
                            <button
                                type="button"
                                onClick={() => setCommissionBase('profit')}
                                className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                    commissionBase === 'profit'
                                        ? 'bg-indigo-600 text-white shadow-2xs'
                                        : 'text-slate-600 hover:text-slate-900 dark:text-neutral-400 dark:hover:text-white'
                                }`}
                            >
                                Sobre Ganancia Neta Real (Recomendado)
                            </button>
                            <button
                                type="button"
                                onClick={() => setCommissionBase('sales')}
                                className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                    commissionBase === 'sales'
                                        ? 'bg-indigo-600 text-white shadow-2xs'
                                        : 'text-slate-600 hover:text-slate-900 dark:text-neutral-400 dark:hover:text-white'
                                }`}
                            >
                                Sobre Ventas Netas
                            </button>
                        </div>
                    </div>

                    {/* Selector de Porcentaje: Slider + Botones Rápidos */}
                    <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-12">
                        <div className="space-y-4 lg:col-span-6">
                            <div className="flex items-center justify-between">
                                <label className="text-sm font-bold text-slate-800 dark:text-neutral-200">
                                    Porcentaje de Comisión: <span className="text-lg text-indigo-600 dark:text-indigo-400">{commissionPct}%</span>
                                </label>
                                <span className="text-xs text-slate-500 dark:text-neutral-400">
                                    Base calculada: <strong className="text-slate-800 dark:text-white">{formatMoney(currentBaseAmount)}</strong>
                                </span>
                            </div>

                            {/* Range Slider */}
                            <input
                                type="range"
                                min="1"
                                max="30"
                                step="0.5"
                                value={commissionPct}
                                onChange={(e) => setCommissionPct(parseFloat(e.target.value))}
                                className="h-2.5 w-full cursor-pointer appearance-none rounded-lg bg-indigo-200 accent-indigo-600 dark:bg-neutral-700"
                            />

                            {/* Botones de selección rápida */}
                            <div className="flex flex-wrap items-center gap-2">
                                {[3, 5, 8, 10, 12, 15, 20].map((rate) => (
                                    <button
                                        key={rate}
                                        type="button"
                                        onClick={() => setCommissionPct(rate)}
                                        className={`rounded-lg px-3 py-1 text-xs font-semibold transition ${
                                            commissionPct === rate
                                                ? 'bg-indigo-600 text-white shadow-xs'
                                                : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300'
                                        }`}
                                    >
                                        {rate}%
                                    </button>
                                ))}
                            </div>

                            <p className="text-xs leading-relaxed text-slate-500 dark:text-neutral-400">
                                {commissionBase === 'profit' ? (
                                    <>
                                        💡 <strong>Por qué sobre Ganancia Neta:</strong> Es la opción más segura y convincente para el jefe, porque solo cobras si el negocio realmente ganó dinero tras pagar el producto a Syscom/proveedores, las comisiones de ML y restar devoluciones.
                                    </>
                                ) : (
                                    <>
                                        💡 <strong>Por qué sobre Venta Neta:</strong> Es el esquema tradicional de retail. En ventas por e-commerce, un porcentaje habitual sobre ventas suele situarse entre <strong>3% y 7%</strong> para mantener la rentabilidad del negocio.
                                    </>
                                )}
                            </p>
                        </div>

                        {/* Resultado Visual de la Repartición: Jefe vs Usuario */}
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:col-span-6">
                            {/* Tu Comisión */}
                            <div className="rounded-2xl border border-indigo-200 bg-indigo-50/70 p-4 dark:border-indigo-900/60 dark:bg-indigo-950/30">
                                <span className="text-xs font-bold uppercase tracking-wider text-indigo-700 dark:text-indigo-400">
                                    Tu Comisión Estimada ({commissionPct}%)
                                </span>
                                <p className="mt-2 text-2xl font-black text-indigo-950 dark:text-indigo-200 sm:text-3xl">
                                    {formatMoney(simulatedUserCommission)}
                                </p>
                                <p className="mt-1 text-xs text-indigo-700/80 dark:text-indigo-400/80">
                                    Calculado sobre {commissionBase === 'profit' ? 'ganancia libre' : 'venta neta'}
                                </p>
                            </div>

                            {/* Ganancia Libre para el Jefe / Empresa */}
                            <div className="rounded-2xl border border-emerald-200 bg-emerald-50/70 p-4 dark:border-emerald-900/60 dark:bg-emerald-950/30">
                                <span className="text-xs font-bold uppercase tracking-wider text-emerald-800 dark:text-emerald-400">
                                    Remanente Libre para el Jefe ({formatPercent(simulatedCompanySharePct)})
                                </span>
                                <p className="mt-2 text-2xl font-black text-emerald-950 dark:text-emerald-200 sm:text-3xl">
                                    {formatMoney(simulatedCompanyProfit)}
                                </p>
                                <p className="mt-1 text-xs text-emerald-700/80 dark:text-emerald-400/80">
                                    Utilidad 100% limpia que le queda a la empresa
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* MATRIZ DE ESCENARIOS COMPARATIVOS */}
                    <div className="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="border-b border-slate-200 bg-slate-50 px-4 py-3 dark:border-neutral-800 dark:bg-neutral-800/50">
                            <h4 className="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-neutral-300">
                                Tabla Comparativa de Escenarios de Negociación
                            </h4>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead>
                                    <tr className="border-b border-slate-200 bg-slate-50/70 text-slate-600 dark:border-neutral-800 dark:bg-neutral-800/30 dark:text-neutral-400">
                                        <th className="py-2.5 px-4 font-semibold">Tasa %</th>
                                        <th className="py-2.5 px-4 font-semibold">Comisión sobre Ganancia</th>
                                        <th className="py-2.5 px-4 font-semibold">Queda al Jefe (vía Ganancia)</th>
                                        <th className="py-2.5 px-4 font-semibold">Comisión sobre Ventas</th>
                                        <th className="py-2.5 px-4 font-semibold">Queda al Jefe (vía Ventas)</th>
                                        <th className="py-2.5 px-4 font-semibold text-right">Acción</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                    {(metrics.scenarios || []).map((sc) => {
                                        const isSelected = commissionPct === sc.rate_pct
                                        return (
                                            <tr
                                                key={sc.rate_pct}
                                                className={`transition hover:bg-slate-50/80 dark:hover:bg-neutral-800/40 ${
                                                    isSelected ? 'bg-indigo-50/80 font-semibold dark:bg-indigo-950/40' : ''
                                                }`}
                                            >
                                                <td className="py-2.5 px-4">
                                                    <span className={`rounded-md px-2 py-0.5 font-bold ${
                                                        isSelected ? 'bg-indigo-600 text-white' : 'bg-slate-100 text-slate-800 dark:bg-neutral-800 dark:text-neutral-200'
                                                    }`}>
                                                        {sc.rate_pct}%
                                                    </span>
                                                </td>
                                                <td className="py-2.5 px-4 font-bold text-slate-900 dark:text-white">
                                                    {formatMoney(sc.on_profit.user_commission)}
                                                </td>
                                                <td className="py-2.5 px-4 font-medium text-emerald-700 dark:text-emerald-400">
                                                    {formatMoney(sc.on_profit.company_net)}
                                                </td>
                                                <td className="py-2.5 px-4 font-bold text-slate-900 dark:text-white">
                                                    {formatMoney(sc.on_sales.user_commission)}
                                                </td>
                                                <td className="py-2.5 px-4 font-medium text-emerald-700 dark:text-emerald-400">
                                                    {formatMoney(sc.on_sales.company_net)}
                                                </td>
                                                <td className="py-2.5 px-4 text-right">
                                                    <button
                                                        type="button"
                                                        onClick={() => setCommissionPct(sc.rate_pct)}
                                                        className={`rounded-lg px-2.5 py-1 text-[11px] font-semibold transition ${
                                                            isSelected
                                                                ? 'bg-indigo-600 text-white'
                                                                : 'bg-slate-100 text-slate-700 hover:bg-slate-200 dark:bg-neutral-800 dark:text-neutral-300 dark:hover:bg-neutral-700'
                                                        }`}
                                                    >
                                                        {isSelected ? 'Seleccionado' : 'Simular'}
                                                    </button>
                                                </td>
                                            </tr>
                                        )
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {/* TABLA AUDITABLE DETALLADA DE PEDIDOS */}
                <div className="rounded-2xl border border-slate-200/90 bg-white p-5 shadow-2xs dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 className="text-base font-bold text-slate-900 dark:text-white">
                                Auditoría Detallada de Órdenes ({orders.total} órdenes encontradas)
                            </h3>
                            <p className="text-xs text-slate-500 dark:text-neutral-400">
                                Cada fila muestra el desglose exacto de venta bruta, comisiones de Mercado Libre, costo de adquisición y utilidad neta resultante.
                            </p>
                        </div>

                        <div className="text-xs text-slate-500 dark:text-neutral-400">
                            Página {orders.current_page} de {orders.last_page}
                        </div>
                    </div>

                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full text-left text-xs">
                            <thead>
                                <tr className="border-b border-slate-200 bg-slate-50 font-semibold text-slate-600 dark:border-neutral-800 dark:bg-neutral-800/40 dark:text-neutral-300">
                                    <th className="py-3 px-3">Orden / Fecha</th>
                                    <th className="py-3 px-3">Canal</th>
                                    <th className="py-3 px-3">Producto / SKU</th>
                                    <th className="py-3 px-3">Estado</th>
                                    <th className="py-3 px-3 text-right">Venta Bruta</th>
                                    <th className="py-3 px-3 text-right">Costo Compra</th>
                                    <th className="py-3 px-3 text-right">Tarifas ML + Envío</th>
                                    <th className="py-3 px-3 text-right font-bold text-slate-900 dark:text-white">Ganancia Neta</th>
                                    <th className="py-3 px-3 text-right">Margen</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                {(!orders.data || orders.data.length === 0) && (
                                    <tr>
                                        <td colSpan={9} className="py-12 text-center text-slate-500 dark:text-neutral-400">
                                            <div className="mx-auto flex max-w-sm flex-col items-center">
                                                <div className="rounded-full bg-slate-100 p-3 dark:bg-neutral-800">
                                                    <svg className="h-6 w-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.5} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                    </svg>
                                                </div>
                                                <p className="mt-3 text-sm font-semibold text-slate-800 dark:text-neutral-200">
                                                    Sin órdenes de SYSCOM ni Llantas en este periodo
                                                </p>
                                                <p className="mt-1 text-xs text-slate-500 dark:text-neutral-400">
                                                    Prueba seleccionando otro rango de fechas o eliminando filtros. El catálogo general de belleza ha sido excluido automáticamente.
                                                </p>
                                            </div>
                                        </td>
                                    </tr>
                                )}
                                {(orders.data || []).map((order) => {
                                    const isCancelled = order.is_cancelled_or_returned
                                    return (
                                        <tr
                                            key={order.id}
                                            className={`transition hover:bg-slate-50/80 dark:hover:bg-neutral-800/40 ${
                                                isCancelled ? 'bg-rose-50/30 opacity-80 dark:bg-rose-950/10' : ''
                                            }`}
                                        >
                                            {/* Orden / Fecha */}
                                            <td className="py-3 px-3">
                                                <p className="font-bold text-slate-900 dark:text-white">#{order.order_id}</p>
                                                <p className="text-[11px] text-slate-500 dark:text-neutral-400">{order.created_at}</p>
                                            </td>

                                            {/* Canal */}
                                            <td className="py-3 px-3">
                                                <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-bold ${
                                                    order.channel === 'syscom'
                                                        ? 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
                                                        : order.channel === 'llantas'
                                                        ? 'bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-300'
                                                        : 'bg-slate-100 text-slate-700 dark:bg-neutral-800 dark:text-neutral-300'
                                                }`}>
                                                    {order.channel_label}
                                                </span>
                                                {order.syscom_order_folio && (
                                                    <p className="mt-0.5 text-[10px] font-mono text-slate-500">
                                                        Folio: {order.syscom_order_folio}
                                                    </p>
                                                )}
                                            </td>

                                            {/* Producto / SKU */}
                                            <td className="py-3 px-3 max-w-xs">
                                                <p className="truncate font-medium text-slate-800 dark:text-neutral-200" title={order.title}>
                                                    {order.title}
                                                </p>
                                                <p className="text-[11px] text-slate-500 dark:text-neutral-400 font-mono">
                                                    SKU: {order.sku} · Cant: {order.quantity}
                                                </p>
                                            </td>

                                            {/* Estado */}
                                            <td className="py-3 px-3">
                                                {isCancelled ? (
                                                    <div>
                                                        <span className="inline-flex rounded-md bg-rose-100 px-2 py-0.5 text-[11px] font-bold text-rose-700 dark:bg-rose-950 dark:text-rose-300">
                                                            Cancelada / Devuelta
                                                        </span>
                                                        {order.cancellation_reason && (
                                                            <p className="mt-0.5 text-[10px] text-rose-600 dark:text-rose-400">
                                                                {order.cancellation_reason}
                                                            </p>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <span className="inline-flex rounded-md bg-emerald-100 px-2 py-0.5 text-[11px] font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                                                        {order.shipping_status !== '—' ? order.shipping_status : 'Entregada'}
                                                    </span>
                                                )}
                                            </td>

                                            {/* Venta Bruta */}
                                            <td className={`py-3 px-3 text-right font-medium ${isCancelled ? 'line-through text-slate-400' : 'text-slate-800 dark:text-neutral-200'}`}>
                                                {formatMoney(order.gross_sale)}
                                            </td>

                                            {/* Costo Compra */}
                                            <td className="py-3 px-3 text-right text-slate-600 dark:text-neutral-400">
                                                {isCancelled ? '—' : formatMoney(order.product_cost)}
                                            </td>

                                            {/* Tarifas ML + Envío */}
                                            <td className="py-3 px-3 text-right text-slate-600 dark:text-neutral-400">
                                                {isCancelled ? '—' : (
                                                    <div>
                                                        <p>{formatMoney(order.meli_fee + order.shipping_fee)}</p>
                                                        {order.shipping_fee > 0 && (
                                                            <p className="text-[10px] text-slate-400">Envío: {formatMoney(order.shipping_fee)}</p>
                                                        )}
                                                    </div>
                                                )}
                                            </td>

                                            {/* Ganancia Neta */}
                                            <td className="py-3 px-3 text-right font-black">
                                                {isCancelled ? (
                                                    <span className="text-slate-400">$0.00</span>
                                                ) : order.net_profit >= 0 ? (
                                                    <span className="text-emerald-600 dark:text-emerald-400">
                                                        +{formatMoney(order.net_profit)}
                                                    </span>
                                                ) : (
                                                    <span className="text-rose-600 dark:text-rose-400">
                                                        {formatMoney(order.net_profit)}
                                                    </span>
                                                )}
                                            </td>

                                            {/* Margen */}
                                            <td className="py-3 px-3 text-right font-medium text-slate-600 dark:text-neutral-400">
                                                {isCancelled ? '—' : formatPercent(order.profit_margin_pct)}
                                            </td>
                                        </tr>
                                    )
                                })}
                            </tbody>
                        </table>
                    </div>

                    {/* Paginación */}
                    <div className="mt-5 flex items-center justify-between border-t border-slate-100 pt-4 dark:border-neutral-800 print:hidden">
                        <Pagination links={orders.links} />
                    </div>
                </div>
            </div>
        </AppShell>
    )
}
