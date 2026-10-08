import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'
import PageSection from '@/Components/ui/PageSection'
import Pagination from '@/Components/ui/Pagination'

function MetricCard({ title, value, subtitle, badge, badgeColor = 'bg-slate-100 text-slate-700 dark:bg-neutral-800 dark:text-neutral-300', icon, colorClass, highlight = false }) {
    return (
        <div className={`group relative overflow-hidden rounded-2xl border p-5 shadow-2xs transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md ${
            highlight
                ? 'border-indigo-200 bg-gradient-to-br from-indigo-50/40 via-white to-white dark:border-indigo-900/60 dark:from-indigo-950/20 dark:via-neutral-900 dark:to-neutral-900'
                : 'border-slate-200/80 bg-white dark:border-neutral-800/80 dark:bg-neutral-900'
        }`}>
            <div className="flex items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    {icon && (
                        <div className={`flex h-8 w-8 items-center justify-center rounded-xl ${colorClass || 'bg-slate-100 text-slate-600 dark:bg-neutral-800 dark:text-slate-300'}`}>
                            {icon}
                        </div>
                    )}
                    <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-neutral-400">
                        {title}
                    </p>
                </div>
                {badge && (
                    <span className={`rounded-md px-2 py-0.5 text-[10px] font-bold ${badgeColor}`}>
                        {badge}
                    </span>
                )}
            </div>

            <p className="mt-3 text-2xl sm:text-3xl font-black tracking-tight text-slate-900 dark:text-white">
                {value}
            </p>
            <p className="mt-1 text-xs text-slate-500 dark:text-neutral-400">
                {subtitle}
            </p>
        </div>
    )
}

function stockBadgeClass(stock) {
    if (stock <= 0) return 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-950/40 dark:text-rose-400 dark:ring-rose-900'
    if (stock <= 2) return 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-400 dark:ring-amber-900'
    return 'bg-yellow-50 text-yellow-700 ring-yellow-200 dark:bg-yellow-950/40 dark:text-yellow-400 dark:ring-yellow-900'
}

export default function DashboardIndex({ ecommerce = {}, catalog = {}, filters = {}, stockBajo = { data: [] } }) {
    const search = filters.search || ''
    const sort = filters.sort || 'stock'
    const dir = filters.dir || 'asc'

    const submitSearch = (e) => {
        e.preventDefault()
        const form = new FormData(e.currentTarget)
        const newSearch = form.get('search') || ''

        router.get('/dashboard', {
            search: newSearch,
            sort,
            dir,
        }, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    const sortLink = (column) => {
        const nextDir = sort === column && dir === 'asc' ? 'desc' : 'asc'
        return `/dashboard?search=${encodeURIComponent(search)}&sort=${column}&dir=${nextDir}`
    }

    const SortTh = ({ column, label, align = 'left', sticky = false }) => {
        const active = sort === column
        const alignClass = align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : 'text-left'
        const linkJustify = align === 'right' ? 'justify-end' : align === 'center' ? 'justify-center' : 'justify-start'
        const stickyClass = sticky
            ? 'sticky left-0 z-20 bg-slate-50 shadow-[2px_0_8px_-4px_rgba(0,0,0,0.08)] dark:bg-neutral-800/90 dark:shadow-[2px_0_8px_-4px_rgba(0,0,0,0.4)]'
            : ''

        return (
            <th className={`px-4 py-3.5 ${alignClass} ${stickyClass}`}>
                <Link
                    href={sortLink(column)}
                    className={`inline-flex w-full items-center gap-1.5 font-bold text-slate-700 transition hover:text-indigo-600 dark:text-slate-200 dark:hover:text-indigo-400 ${linkJustify}`}
                >
                    <span>{label}</span>
                    <span className="text-[10px] text-slate-400 dark:text-slate-500" aria-hidden>
                        {active ? (dir === 'asc' ? '▲' : '▼') : '↕'}
                    </span>
                </Link>
            </th>
        )
    }

    const formatMoney = (val) => {
        return `$${Number(val || 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} MXN`
    }

    const stockRows = stockBajo.data || []
    const stockTotal = Number(stockBajo.total ?? 0)

    return (
        <AppShell title="Dashboard">
            <Head title="Panel de Control E-commerce - SBS" />

            <div className="space-y-8">
                {/* Header Principal con Identidad SBS */}
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between border-b border-slate-200/80 pb-6 dark:border-neutral-800/80">
                    <div>
                        <div className="flex items-center gap-2">
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-slate-900 px-2.5 py-0.5 text-[11px] font-bold tracking-wider text-white dark:bg-white dark:text-slate-900">
                                <span>SBS</span>
                                <span className="opacity-60">|</span>
                                <span className="text-pink-300 dark:text-pink-600">T.O. THE BEAUTY SHOP</span>
                            </span>
                            <span className="text-xs font-semibold text-slate-400 dark:text-neutral-500">
                                Panel E-commerce & Retail
                            </span>
                        </div>
                        <h1 className="mt-2 text-2xl sm:text-3xl font-black tracking-tight text-slate-900 dark:text-white">
                            Panel de Control General
                        </h1>
                        <p className="mt-1 text-sm text-slate-500 dark:text-neutral-400">
                            Monitoreo en tiempo real de ventas omnicanal, despacho de pedidos y salud del inventario SBS.
                        </p>
                    </div>

                    {/* Botones de Acción Operativa */}
                    <div className="flex flex-wrap items-center gap-2.5">
                        <Link
                            href="/pos"
                            className="inline-flex items-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100"
                        >
                            <svg className="h-4 w-4 text-emerald-400 dark:text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                            Punto de Venta POS
                        </Link>
                        <Link
                            href="/almacen/productos"
                            className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 shadow-2xs transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200 dark:hover:bg-neutral-700"
                        >
                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                            Catálogo Maestro
                        </Link>
                        <Link
                            href="/compras/ordenes/crear"
                            className="inline-flex items-center gap-2 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-xs font-bold text-indigo-700 transition hover:bg-indigo-100 dark:border-indigo-900/60 dark:bg-indigo-950/40 dark:text-indigo-300 dark:hover:bg-indigo-900/50"
                        >
                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                            </svg>
                            Nueva Orden de Compra
                        </Link>
                    </div>
                </div>

                {/* FILA 1: KPIs Principales de Ventas y Despacho */}
                <section className="space-y-3">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {/* Ventas Totales Hoy */}
                        <MetricCard
                            title="Ventas Totales Hoy"
                            value={formatMoney(ecommerce.totalSalesToday)}
                            subtitle={`${Number(ecommerce.totalOrdersToday || 0)} órdenes combinadas (ML + Tienda)`}
                            badge="Hoy"
                            badgeColor="bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300"
                            highlight
                            colorClass="bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400"
                            icon={
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            }
                        />

                        {/* Pedidos por Despachar */}
                        <MetricCard
                            title="Pedidos x Despachar"
                            value={Number(ecommerce.meli?.pendingDispatch || 0).toLocaleString()}
                            subtitle="Órdenes Mercado Libre listas para preparación"
                            badge={Number(ecommerce.meli?.pendingDispatch || 0) > 0 ? 'Pendiente' : 'Al día ✓'}
                            badgeColor={
                                Number(ecommerce.meli?.pendingDispatch || 0) > 0
                                    ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300'
                                    : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                            }
                            colorClass="bg-amber-50 text-amber-600 dark:bg-amber-950/50 dark:text-amber-400"
                            icon={
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                                </svg>
                            }
                        />

                        {/* Ventas en Mostrador POS */}
                        <MetricCard
                            title="Mostrador POS Hoy"
                            value={formatMoney(ecommerce.pos?.totalToday)}
                            subtitle={`${Number(ecommerce.pos?.ordersToday || 0)} tickets cobrados en tienda`}
                            badge="Tienda Física"
                            badgeColor="bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300"
                            colorClass="bg-blue-50 text-blue-600 dark:bg-blue-950/50 dark:text-blue-400"
                            icon={
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                                </svg>
                            }
                        />

                        {/* Atención al Cliente / Reputación ML */}
                        <MetricCard
                            title="Atención al Cliente ML"
                            value={`${Number(ecommerce.support?.unansweredQuestions || 0)} preg.`}
                            subtitle={`${Number(ecommerce.support?.openClaims || 0)} reclamos abiertos actualmente`}
                            badge={
                                Number(ecommerce.support?.unansweredQuestions || 0) === 0 && Number(ecommerce.support?.openClaims || 0) === 0
                                    ? 'Excelente ✓'
                                    : 'Requiere Atención'
                            }
                            badgeColor={
                                Number(ecommerce.support?.unansweredQuestions || 0) === 0 && Number(ecommerce.support?.openClaims || 0) === 0
                                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                                    : 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300'
                            }
                            colorClass="bg-purple-50 text-purple-600 dark:bg-purple-950/50 dark:text-purple-400"
                            icon={
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                </svg>
                            }
                        />
                    </div>
                </section>

                {/* FILA 2: Operación por Canal (Mercado Libre vs POS Mostrador) */}
                <section className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {/* Tarjeta Canal Online: Mercado Libre */}
                    <div className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-6 shadow-2xs dark:border-neutral-800/80 dark:bg-neutral-900">
                        <div className="flex items-center justify-between border-b border-slate-100 pb-4 dark:border-neutral-800">
                            <div className="flex items-center gap-3">
                                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400">
                                    <svg className="h-6 w-6" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/>
                                    </svg>
                                </div>
                                <div>
                                    <h3 className="font-bold text-slate-900 dark:text-white">Canal Online: Mercado Libre</h3>
                                    <p className="text-xs text-slate-500 dark:text-neutral-400">E-commerce y sincronización de órdenes</p>
                                </div>
                            </div>
                            <span className="rounded-lg bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-700 dark:bg-amber-950/40 dark:text-amber-400">
                                Mercado Libre
                            </span>
                        </div>

                        <div className="mt-5 grid grid-cols-2 gap-4">
                            <div className="rounded-xl bg-slate-50 p-4 dark:bg-neutral-800/50">
                                <p className="text-xs text-slate-500 dark:text-neutral-400">Ventas Hoy</p>
                                <p className="mt-1 text-xl font-black text-slate-900 dark:text-white">
                                    {formatMoney(ecommerce.meli?.totalToday)}
                                </p>
                                <p className="mt-0.5 text-[11px] text-slate-400">
                                    {Number(ecommerce.meli?.ordersToday || 0)} pedidos hoy
                                </p>
                            </div>
                            <div className="rounded-xl bg-slate-50 p-4 dark:bg-neutral-800/50">
                                <p className="text-xs text-slate-500 dark:text-neutral-400">Envíos en Tránsito</p>
                                <p className="mt-1 text-xl font-black text-indigo-600 dark:text-indigo-400">
                                    {Number(ecommerce.meli?.inTransit || 0)} paquetes
                                </p>
                                <p className="mt-0.5 text-[11px] text-slate-400">
                                    {Number(ecommerce.meli?.deliveredToday || 0)} entregados hoy
                                </p>
                            </div>
                        </div>

                        <div className="mt-5 flex flex-wrap items-center gap-2 pt-4 border-t border-slate-100 dark:border-neutral-800">
                            <Link
                                href="/ams/pedidos"
                                className="inline-flex items-center gap-1.5 rounded-lg bg-indigo-50 px-3 py-1.5 text-xs font-bold text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950/50 dark:text-indigo-300"
                            >
                                Despacho AMS
                            </Link>
                            <Link
                                href="/mercado-libre/etiquetas"
                                className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800"
                            >
                                Imprimir Etiquetas
                            </Link>
                            <Link
                                href="/meli/preguntas"
                                className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800"
                            >
                                Responder Preguntas ({Number(ecommerce.support?.unansweredQuestions || 0)})
                            </Link>
                        </div>
                    </div>

                    {/* Tarjeta Canal Físico: Punto de Venta POS */}
                    <div className="overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-6 shadow-2xs dark:border-neutral-800/80 dark:bg-neutral-900">
                        <div className="flex items-center justify-between border-b border-slate-100 pb-4 dark:border-neutral-800">
                            <div className="flex items-center gap-3">
                                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-500/10 text-blue-600 dark:bg-blue-500/20 dark:text-blue-400">
                                    <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                                    </svg>
                                </div>
                                <div>
                                    <h3 className="font-bold text-slate-900 dark:text-white">Canal Físico: Punto de Venta SBS</h3>
                                    <p className="text-xs text-slate-500 dark:text-neutral-400">Mostrador y ventas a estilistas</p>
                                </div>
                            </div>
                            <span className="rounded-lg bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700 dark:bg-blue-500/10 dark:text-blue-300">
                                Retail SBS
                            </span>
                        </div>

                        <div className="mt-5 grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                            <div className="rounded-xl bg-slate-50 p-3 text-center dark:bg-neutral-800/50">
                                <p className="text-[11px] font-semibold text-slate-500 dark:text-neutral-400">Efectivo</p>
                                <p className="mt-1 text-sm font-bold text-slate-900 dark:text-white">
                                    ${Number(ecommerce.pos?.cash || 0).toLocaleString()}
                                </p>
                            </div>
                            <div className="rounded-xl bg-slate-50 p-3 text-center dark:bg-neutral-800/50">
                                <p className="text-[11px] font-semibold text-slate-500 dark:text-neutral-400">Tarjeta</p>
                                <p className="mt-1 text-sm font-bold text-slate-900 dark:text-white">
                                    ${Number(ecommerce.pos?.card || 0).toLocaleString()}
                                </p>
                            </div>
                            <div className="rounded-xl bg-slate-50 p-3 text-center dark:bg-neutral-800/50">
                                <p className="text-[11px] font-semibold text-slate-500 dark:text-neutral-400">Transferencia</p>
                                <p className="mt-1 text-sm font-bold text-slate-900 dark:text-white">
                                    ${Number(ecommerce.pos?.transfer || 0).toLocaleString()}
                                </p>
                            </div>
                            <div className="rounded-xl bg-slate-50 p-3 text-center dark:bg-neutral-800/50">
                                <p className="text-[11px] font-semibold text-slate-500 dark:text-neutral-400">Crédito</p>
                                <p className="mt-1 text-sm font-bold text-amber-600 dark:text-amber-400">
                                    ${Number(ecommerce.pos?.credit || 0).toLocaleString()}
                                </p>
                            </div>
                        </div>

                        <div className="mt-5 flex flex-wrap items-center gap-2 pt-4 border-t border-slate-100 dark:border-neutral-800">
                            <Link
                                href="/pos"
                                className="inline-flex items-center gap-1.5 rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-bold text-blue-700 hover:bg-blue-100 dark:bg-blue-950/50 dark:text-blue-300"
                            >
                                Nueva Venta en Caja
                            </Link>
                            <Link
                                href="/pos/clientes"
                                className="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800"
                            >
                                Clientes & Créditos Estilistas
                            </Link>
                        </div>
                    </div>
                </section>

                {/* FILA 3: Inventario y Salud del Catálogo SBS */}
                <section className="space-y-4">
                    <PageSection
                        eyebrow="Inventario SBS"
                        title="Catálogo General y Existencias"
                        description="Valores reales calculados sobre el inventario físico disponible en almacén."
                    />

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <div className="relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-5 shadow-2xs dark:border-neutral-800/80 dark:bg-neutral-900">
                            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-neutral-400">
                                Productos en Catálogo
                            </p>
                            <p className="mt-2 text-3xl font-black tracking-tight text-slate-900 dark:text-white">
                                {Number(catalog.totalProducts || 0).toLocaleString()}
                            </p>
                            <p className="mt-1 text-xs text-slate-500 dark:text-neutral-400">
                                Referencias individuales registradas
                            </p>
                            <div className="mt-3 flex items-center justify-between text-[11px] text-slate-500">
                                <span>Con existencia</span>
                                <span className="font-bold text-emerald-600">
                                    {Number(catalog.healthyStock || 0)} SKUs
                                </span>
                            </div>
                        </div>

                        <div className="relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-5 shadow-2xs dark:border-neutral-800/80 dark:bg-neutral-900">
                            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-neutral-400">
                                Kits & Combos Compuestos
                            </p>
                            <p className="mt-2 text-3xl font-black tracking-tight text-violet-600 dark:text-violet-400">
                                {Number(catalog.totalCombos || 0).toLocaleString()}
                            </p>
                            <p className="mt-1 text-xs text-slate-500 dark:text-neutral-400">
                                Paquetes y tratamientos armados
                            </p>
                            <div className="mt-3 flex items-center justify-between text-[11px] text-slate-500">
                                <span>Valor teórico</span>
                                <span className="font-bold text-slate-700 dark:text-slate-300">
                                    ${Number(catalog.combosTheoreticalValue || 0).toLocaleString(undefined, { maximumFractionDigits: 0 })}
                                </span>
                            </div>
                        </div>

                        <div className="relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-5 shadow-2xs dark:border-neutral-800/80 dark:bg-neutral-900">
                            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-neutral-400">
                                Unidades Físicas en Almacén
                            </p>
                            <p className="mt-2 text-3xl font-black tracking-tight text-emerald-600 dark:text-emerald-400">
                                {Number(catalog.totalPieces || 0).toLocaleString()}
                            </p>
                            <p className="mt-1 text-xs text-slate-500 dark:text-neutral-400">
                                Piezas totales disponibles
                            </p>
                            <div className="mt-3 flex items-center justify-between text-[11px] text-slate-500">
                                <span>Agotados (Stock 0)</span>
                                <span className="font-bold text-rose-600">
                                    {Number(catalog.outOfStock || 0)} SKUs
                                </span>
                            </div>
                        </div>

                        <div className="relative overflow-hidden rounded-2xl border border-slate-200/80 bg-gradient-to-br from-white via-white to-indigo-50/30 p-5 shadow-2xs dark:border-neutral-800/80 dark:from-neutral-900 dark:via-neutral-900 dark:to-indigo-950/20">
                            <div className="flex items-center justify-between">
                                <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-neutral-400">
                                    Valor Inventario (Costo)
                                </p>
                                <span className="text-xs font-bold text-indigo-600 dark:text-indigo-400">MXN</span>
                            </div>
                            <p className="mt-2 text-2xl sm:text-3xl font-black tracking-tight text-slate-900 dark:text-white">
                                ${Number(catalog.inventoryValueCost || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                            </p>
                            <p className="mt-1 text-xs text-slate-500 dark:text-neutral-400">
                                Valorización al costo de piezas físicas
                            </p>
                        </div>
                    </div>
                </section>

                {/* FILA 4: Alertas de Reabastecimiento / Stock Crítico */}
                <section className="space-y-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 className="text-xl font-black tracking-tight text-slate-900 dark:text-white">
                                Alertas de Reabastecimiento
                            </h2>
                            <p className="text-xs text-slate-500 dark:text-neutral-400">
                                Productos con stock crítico (≤ 4 unidades). Genera órdenes de compra a proveedores para evitar quiebres de inventario.
                            </p>
                        </div>

                        <Link
                            href="/compras/ordenes/crear"
                            className="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-3.5 py-2 text-xs font-bold text-white shadow-sm hover:bg-indigo-700 transition"
                        >
                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                            </svg>
                            Crear Orden de Compra
                        </Link>
                    </div>

                    {/* Buscador de Stock Crítico */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs dark:border-neutral-800 dark:bg-neutral-900">
                        <form onSubmit={submitSearch} className="flex flex-col gap-2 sm:flex-row sm:items-center">
                            <div className="relative flex-1">
                                <input
                                    name="search"
                                    defaultValue={search}
                                    placeholder="Buscar por SKU, marca o descripción..."
                                    className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm text-slate-800 placeholder-slate-400 outline-none transition focus:border-indigo-500 focus:bg-white dark:border-neutral-700 dark:bg-neutral-950 dark:text-slate-100"
                                />
                            </div>
                            <div className="flex items-center gap-2">
                                <button
                                    type="submit"
                                    className="rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-bold text-white transition hover:bg-indigo-700"
                                >
                                    Buscar
                                </button>
                                {search && (
                                    <Link
                                        href="/dashboard"
                                        className="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                                    >
                                        Limpiar
                                    </Link>
                                )}
                            </div>
                        </form>
                    </div>

                    {/* Tabla de Productos con Stock Crítico */}
                    <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xs dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="flex items-center justify-between border-b border-slate-200 px-5 py-3.5 dark:border-neutral-800">
                            <div className="flex items-center gap-2">
                                <span className="inline-flex h-2.5 w-2.5 rounded-full bg-rose-500" />
                                <h3 className="font-bold text-slate-900 dark:text-white text-sm">
                                    Productos en Stock Crítico
                                </h3>
                                <span className="rounded-md bg-rose-50 px-2 py-0.5 text-xs font-bold text-rose-700 dark:bg-rose-950/40 dark:text-rose-400">
                                    {stockTotal.toLocaleString()} encontrados
                                </span>
                            </div>

                            {stockBajo.from != null && stockBajo.to != null && (
                                <span className="text-xs text-slate-500 dark:text-neutral-400">
                                    Mostrando {stockBajo.from}–{stockBajo.to} de {stockTotal}
                                </span>
                            )}
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead className="border-b border-slate-200 bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-600 dark:border-neutral-800 dark:bg-neutral-800/80 dark:text-slate-400">
                                    <tr>
                                        <SortTh column="sku" label="SKU" sticky />
                                        <SortTh column="marca" label="Marca" />
                                        <SortTh column="descripcion" label="Descripción del Producto" />
                                        <SortTh column="costo" label="Costo" align="right" />
                                        <SortTh column="precio_ML" label="Precio Venta" align="right" />
                                        <SortTh column="stock" label="Stock" align="center" />
                                        <th className="px-4 py-3.5 text-right">Acción</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                    {stockRows.length === 0 ? (
                                        <tr>
                                            <td colSpan="7" className="px-5 py-12 text-center text-sm text-slate-500 dark:text-neutral-400">
                                                {search
                                                    ? 'No se encontraron productos críticos con esa búsqueda.'
                                                    : '¡Excelente! No hay productos con stock menor o igual a 4 unidades.'}
                                            </td>
                                        </tr>
                                    ) : (
                                        stockRows.map((item) => (
                                            <tr key={item.id} className="transition hover:bg-slate-50 dark:hover:bg-neutral-800/50">
                                                <td className="sticky left-0 z-10 bg-white px-4 py-3.5 font-mono text-xs font-bold text-indigo-600 dark:bg-neutral-900 dark:text-indigo-400">
                                                    <Link href={`/llantas/${item.id}/editar`} className="hover:underline">
                                                        {item.sku}
                                                    </Link>
                                                </td>
                                                <td className="px-4 py-3.5 text-xs font-semibold text-slate-800 dark:text-slate-200 uppercase">
                                                    {item.marca || 'SBS'}
                                                </td>
                                                <td className="px-4 py-3.5 text-xs text-slate-600 dark:text-slate-300">
                                                    <div className="max-w-md truncate" title={item.descripcion || item.title_familyname || ''}>
                                                        {item.descripcion || item.title_familyname || '—'}
                                                    </div>
                                                </td>
                                                <td className="px-4 py-3.5 text-right font-mono text-xs text-slate-700 dark:text-slate-300">
                                                    ${Number(item.costo || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                                </td>
                                                <td className="px-4 py-3.5 text-right font-mono text-xs font-bold text-emerald-600 dark:text-emerald-400">
                                                    ${Number(item.precio_ML || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                                </td>
                                                <td className="px-4 py-3.5 text-center">
                                                    <span className={`inline-flex min-w-[36px] items-center justify-center rounded-full px-2.5 py-0.5 text-xs font-bold ring-1 ${stockBadgeClass(Number(item.stock || 0))}`}>
                                                        {item.stock} pz
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3.5 text-right">
                                                    <Link
                                                        href={`/compras/ordenes/crear?sku=${encodeURIComponent(item.sku)}`}
                                                        className="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-300 dark:hover:bg-neutral-700"
                                                    >
                                                        + Pedir OC
                                                    </Link>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>

                        <div className="border-t border-slate-200 px-5 py-3.5 dark:border-neutral-800">
                            <Pagination links={stockBajo.links || []} />
                        </div>
                    </div>
                </section>
            </div>
        </AppShell>
    )
}
