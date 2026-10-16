import React, { useState, useMemo } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

function IconSearch({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
        </svg>
    )
}

function IconCheckAll({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
        </svg>
    )
}

function IconRefresh({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
        </svg>
    )
}

function getTypeIcon(type) {
    switch (type) {
        case 'publication':
            return '🚫'
        case 'claim':
            return '⚠️'
        case 'message':
            return '💬'
        case 'question':
            return '❓'
        case 'stock':
            return '📦'
        case 'full':
            return '⚡'
        case 'issue':
            return '🚨'
        default:
            return '🔔'
    }
}

function getSeverityBadge(severity) {
    if (severity === 'danger') {
        return 'bg-rose-100 text-rose-800 border-rose-300 dark:bg-rose-500/20 dark:text-rose-300 dark:border-rose-500/30'
    }
    if (severity === 'warning') {
        return 'bg-amber-100 text-amber-800 border-amber-300 dark:bg-amber-500/20 dark:text-amber-300 dark:border-amber-500/30'
    }
    return 'bg-sky-100 text-sky-800 border-sky-300 dark:bg-sky-500/20 dark:text-sky-300 dark:border-sky-500/30'
}

export default function NotificationsIndex({ notifications = [], summary = {}, filters = {} }) {
    const [searchTerm, setSearchTerm] = useState(filters.search || '')
    const [categoryFilter, setCategoryFilter] = useState(filters.category || 'all')
    const [typeFilter, setTypeFilter] = useState(filters.type || 'all')
    const [statusFilter, setStatusFilter] = useState(filters.status || 'all') // 'all', 'unread', 'read'
    const [refreshing, setRefreshing] = useState(false)
    const [processingKey, setProcessingKey] = useState(null)

    const counts = summary?.counts || {}
    const totalUnread = summary?.total_unread || 0

    // Filter notifications locally for instantaneous responsiveness
    const filteredNotifications = useMemo(() => {
        return notifications.filter((item) => {
            if (categoryFilter !== 'all' && item.category !== categoryFilter) {
                return false
            }

            if (typeFilter !== 'all' && item.type !== typeFilter) {
                return false
            }

            if (statusFilter === 'unread' && item.is_read) {
                return false
            }

            if (statusFilter === 'read' && !item.is_read) {
                return false
            }

            if (searchTerm.trim() !== '') {
                const q = searchTerm.toLowerCase()
                const text = `${item.title} ${item.subtitle || ''} ${item.message} ${item.badge_text || ''}`.toLowerCase()
                if (!text.includes(q)) {
                    return false
                }
            }

            return true
        })
    }, [notifications, categoryFilter, typeFilter, statusFilter, searchTerm])

    const handleMarkAsRead = (key) => {
        setProcessingKey(key)
        router.post(`/notificaciones/${encodeURIComponent(key)}/leer`, {}, {
            preserveScroll: true,
            onFinish: () => setProcessingKey(null),
        })
    }

    const handleDismiss = (key) => {
        setProcessingKey(key)
        router.post(`/notificaciones/${encodeURIComponent(key)}/descartar`, {}, {
            preserveScroll: true,
            onFinish: () => setProcessingKey(null),
        })
    }

    const handleMarkAllAsRead = () => {
        setRefreshing(true)
        router.post('/notificaciones/leer-todas', {}, {
            preserveScroll: true,
            onFinish: () => setRefreshing(false),
        })
    }

    const handleRefresh = () => {
        setRefreshing(true)
        router.reload({
            onFinish: () => setRefreshing(false),
        })
    }

    return (
        <AppShell title="Centro de Notificaciones & Alertas">
            <Head title="Centro de Notificaciones - SBS" />

            <div className="space-y-6">
                {/* PAGE HEADER */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <span className="flex h-10 w-10 items-center justify-center rounded-2xl bg-indigo-600 text-xl text-white shadow-md">
                                🔔
                            </span>
                            <div>
                                <h1 className="text-xl font-black tracking-tight text-slate-900 sm:text-2xl dark:text-white">
                                    Centro de Notificaciones & Alertas
                                </h1>
                                <p className="text-xs text-slate-500 dark:text-neutral-400">
                                    Monitoreo en tiempo real de publicaciones bloqueadas, reclamos, mensajería posventa, preguntas y stock.
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* Actions */}
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={handleRefresh}
                            disabled={refreshing}
                            className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700 shadow-2xs transition hover:bg-slate-50 disabled:opacity-50 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800"
                        >
                            <IconRefresh className={`h-4 w-4 ${refreshing ? 'animate-spin' : ''}`} />
                            <span>Actualizar</span>
                        </button>

                        {totalUnread > 0 && (
                            <button
                                type="button"
                                onClick={handleMarkAllAsRead}
                                disabled={refreshing}
                                className="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-3.5 py-2 text-xs font-bold text-white shadow-xs transition hover:bg-indigo-700 active:scale-95 disabled:opacity-50"
                            >
                                <IconCheckAll className="h-4 w-4" />
                                <span>Marcar todas como leídas</span>
                            </button>
                        )}
                    </div>
                </div>

                {/* METRIC STAT CARDS */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {/* Publicaciones Bloqueadas */}
                    <button
                        type="button"
                        onClick={() => {
                            setTypeFilter(typeFilter === 'publication' ? 'all' : 'publication')
                            setCategoryFilter('all')
                        }}
                        className={`text-left rounded-2xl border p-4 transition shadow-2xs hover:shadow-xs active:scale-98 ${
                            typeFilter === 'publication'
                                ? 'border-rose-500 bg-rose-50/70 ring-2 ring-rose-500/20 dark:bg-rose-500/10 dark:border-rose-500/40'
                                : 'border-slate-200 bg-white hover:border-slate-300 dark:border-neutral-800 dark:bg-neutral-900'
                        }`}
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-xl">🚫</span>
                            <span className={`rounded-full px-2 py-0.5 text-[11px] font-black ${
                                (counts.publication || 0) > 0 ? 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-300' : 'bg-slate-100 text-slate-600 dark:bg-neutral-800 dark:text-neutral-400'
                            }`}>
                                {counts.publication || 0}
                            </span>
                        </div>
                        <p className="mt-2 text-xs font-bold text-slate-900 dark:text-white">
                            Bloqueadas / Pausa
                        </p>
                        <p className="text-[10px] text-slate-500 dark:text-neutral-400">
                            Publicaciones ML
                        </p>
                    </button>

                    {/* Reclamos */}
                    <button
                        type="button"
                        onClick={() => {
                            setTypeFilter(typeFilter === 'claim' ? 'all' : 'claim')
                            setCategoryFilter('all')
                        }}
                        className={`text-left rounded-2xl border p-4 transition shadow-2xs hover:shadow-xs active:scale-98 ${
                            typeFilter === 'claim'
                                ? 'border-amber-500 bg-amber-50/70 ring-2 ring-amber-500/20 dark:bg-amber-500/10 dark:border-amber-500/40'
                                : 'border-slate-200 bg-white hover:border-slate-300 dark:border-neutral-800 dark:bg-neutral-900'
                        }`}
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-xl">⚠️</span>
                            <span className={`rounded-full px-2 py-0.5 text-[11px] font-black ${
                                (counts.claim || 0) > 0 ? 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300' : 'bg-slate-100 text-slate-600 dark:bg-neutral-800 dark:text-neutral-400'
                            }`}>
                                {counts.claim || 0}
                            </span>
                        </div>
                        <p className="mt-2 text-xs font-bold text-slate-900 dark:text-white">
                            Reclamos Abiertos
                        </p>
                        <p className="text-[10px] text-slate-500 dark:text-neutral-400">
                            Riesgo de reputación
                        </p>
                    </button>

                    {/* Mensajería Posventa */}
                    <button
                        type="button"
                        onClick={() => {
                            setTypeFilter(typeFilter === 'message' ? 'all' : 'message')
                            setCategoryFilter('all')
                        }}
                        className={`text-left rounded-2xl border p-4 transition shadow-2xs hover:shadow-xs active:scale-98 ${
                            typeFilter === 'message'
                                ? 'border-indigo-500 bg-indigo-50/70 ring-2 ring-indigo-500/20 dark:bg-indigo-500/10 dark:border-indigo-500/40'
                                : 'border-slate-200 bg-white hover:border-slate-300 dark:border-neutral-800 dark:bg-neutral-900'
                        }`}
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-xl">💬</span>
                            <span className={`rounded-full px-2 py-0.5 text-[11px] font-black ${
                                (counts.message || 0) > 0 ? 'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/20 dark:text-indigo-300' : 'bg-slate-100 text-slate-600 dark:bg-neutral-800 dark:text-neutral-400'
                            }`}>
                                {counts.message || 0}
                            </span>
                        </div>
                        <p className="mt-2 text-xs font-bold text-slate-900 dark:text-white">
                            Mensajes Posventa
                        </p>
                        <p className="text-[10px] text-slate-500 dark:text-neutral-400">
                            Atención al comprador
                        </p>
                    </button>

                    {/* Preguntas Pre-venta */}
                    <button
                        type="button"
                        onClick={() => {
                            setTypeFilter(typeFilter === 'question' ? 'all' : 'question')
                            setCategoryFilter('all')
                        }}
                        className={`text-left rounded-2xl border p-4 transition shadow-2xs hover:shadow-xs active:scale-98 ${
                            typeFilter === 'question'
                                ? 'border-sky-500 bg-sky-50/70 ring-2 ring-sky-500/20 dark:bg-sky-500/10 dark:border-sky-500/40'
                                : 'border-slate-200 bg-white hover:border-slate-300 dark:border-neutral-800 dark:bg-neutral-900'
                        }`}
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-xl">❓</span>
                            <span className={`rounded-full px-2 py-0.5 text-[11px] font-black ${
                                (counts.question || 0) > 0 ? 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-300' : 'bg-slate-100 text-slate-600 dark:bg-neutral-800 dark:text-neutral-400'
                            }`}>
                                {counts.question || 0}
                            </span>
                        </div>
                        <p className="mt-2 text-xs font-bold text-slate-900 dark:text-white">
                            Preguntas Clientes
                        </p>
                        <p className="text-[10px] text-slate-500 dark:text-neutral-400">
                            Pre-venta sin responder
                        </p>
                    </button>

                    {/* Stock Crítico & FULL */}
                    <button
                        type="button"
                        onClick={() => {
                            setTypeFilter(typeFilter === 'stock' ? 'all' : 'stock')
                            setCategoryFilter('all')
                        }}
                        className={`text-left rounded-2xl border p-4 transition shadow-2xs hover:shadow-xs active:scale-98 ${
                            typeFilter === 'stock'
                                ? 'border-amber-500 bg-amber-50/70 ring-2 ring-amber-500/20 dark:bg-amber-500/10 dark:border-amber-500/40'
                                : 'border-slate-200 bg-white hover:border-slate-300 dark:border-neutral-800 dark:bg-neutral-900'
                        }`}
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-xl">📦</span>
                            <span className={`rounded-full px-2 py-0.5 text-[11px] font-black ${
                                (counts.stock || 0) > 0 ? 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300' : 'bg-slate-100 text-slate-600 dark:bg-neutral-800 dark:text-neutral-400'
                            }`}>
                                {counts.stock || 0}
                            </span>
                        </div>
                        <p className="mt-2 text-xs font-bold text-slate-900 dark:text-white">
                            Stock Crítico & FULL
                        </p>
                        <p className="text-[10px] text-slate-500 dark:text-neutral-400">
                            Agotados en bodega
                        </p>
                    </button>

                    {/* Incidencias en Empaque */}
                    <button
                        type="button"
                        onClick={() => {
                            setTypeFilter(typeFilter === 'issue' ? 'all' : 'issue')
                            setCategoryFilter('all')
                        }}
                        className={`text-left rounded-2xl border p-4 transition shadow-2xs hover:shadow-xs active:scale-98 ${
                            typeFilter === 'issue'
                                ? 'border-rose-500 bg-rose-50/70 ring-2 ring-rose-500/20 dark:bg-rose-500/10 dark:border-rose-500/40'
                                : 'border-slate-200 bg-white hover:border-slate-300 dark:border-neutral-800 dark:bg-neutral-900'
                        }`}
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-xl">🚨</span>
                            <span className={`rounded-full px-2 py-0.5 text-[11px] font-black ${
                                (counts.issue || 0) > 0 ? 'bg-rose-100 text-rose-800 dark:bg-rose-500/20 dark:text-rose-300' : 'bg-slate-100 text-slate-600 dark:bg-neutral-800 dark:text-neutral-400'
                            }`}>
                                {counts.issue || 0}
                            </span>
                        </div>
                        <p className="mt-2 text-xs font-bold text-slate-900 dark:text-white">
                            Incidencias Empaque
                        </p>
                        <p className="text-[10px] text-slate-500 dark:text-neutral-400">
                            Mesa de empaque AMS
                        </p>
                    </button>
                </div>

                {/* FILTERS & SEARCH BAR */}
                <div className="flex flex-col gap-3 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-2xs dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        {/* Search Input */}
                        <div className="relative flex-1 max-w-md">
                            <IconSearch className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" />
                            <input
                                type="text"
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                placeholder="Buscar por SKU, MLM, comprador, reclamo o motivo..."
                                className="w-full rounded-xl border border-slate-200 bg-slate-50/70 pl-9 pr-4 py-2 text-xs text-slate-900 transition placeholder:text-slate-400 focus:border-indigo-500 focus:bg-white focus:outline-hidden dark:border-neutral-800 dark:bg-neutral-950/60 dark:text-white dark:focus:bg-neutral-900"
                            />
                            {searchTerm && (
                                <button
                                    type="button"
                                    onClick={() => setSearchTerm('')}
                                    className="absolute right-2.5 top-1/2 -translate-y-1/2 text-xs font-bold text-slate-400 hover:text-slate-700 dark:hover:text-white"
                                >
                                    ✕
                                </button>
                            )}
                        </div>

                        {/* Status Toggle (Todas / Solo no leídas) */}
                        <div className="flex items-center gap-1 rounded-xl bg-slate-100 p-1 dark:bg-neutral-800 text-xs font-semibold">
                            <button
                                type="button"
                                onClick={() => setStatusFilter('all')}
                                className={`rounded-lg px-3 py-1.5 transition ${
                                    statusFilter === 'all'
                                        ? 'bg-white text-indigo-700 shadow-2xs dark:bg-neutral-700 dark:text-white'
                                        : 'text-slate-600 hover:text-slate-900 dark:text-neutral-400 dark:hover:text-white'
                                }`}
                            >
                                Todas
                            </button>
                            <button
                                type="button"
                                onClick={() => setStatusFilter('unread')}
                                className={`rounded-lg px-3 py-1.5 transition flex items-center gap-1.5 ${
                                    statusFilter === 'unread'
                                        ? 'bg-white text-indigo-700 shadow-2xs dark:bg-neutral-700 dark:text-white'
                                        : 'text-slate-600 hover:text-slate-900 dark:text-neutral-400 dark:hover:text-white'
                                }`}
                            >
                                <span>No Leídas</span>
                                {totalUnread > 0 && (
                                    <span className="rounded-full bg-indigo-600 px-1.5 py-0.2 text-[9px] font-bold text-white">
                                        {totalUnread}
                                    </span>
                                )}
                            </button>
                            <button
                                type="button"
                                onClick={() => setStatusFilter('read')}
                                className={`rounded-lg px-3 py-1.5 transition ${
                                    statusFilter === 'read'
                                        ? 'bg-white text-indigo-700 shadow-2xs dark:bg-neutral-700 dark:text-white'
                                        : 'text-slate-600 hover:text-slate-900 dark:text-neutral-400 dark:hover:text-white'
                                }`}
                            >
                                Leídas
                            </button>
                        </div>
                    </div>

                    {/* Category Tabs */}
                    <div className="flex items-center gap-1.5 overflow-x-auto pt-2 border-t border-slate-100 dark:border-neutral-800 text-xs">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-neutral-500 mr-1 shrink-0">
                            Categoría:
                        </span>
                        <button
                            type="button"
                            onClick={() => { setCategoryFilter('all'); setTypeFilter('all') }}
                            className={`rounded-lg px-2.5 py-1 font-semibold transition shrink-0 ${
                                categoryFilter === 'all' && typeFilter === 'all'
                                    ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-neutral-800 dark:text-neutral-300'
                            }`}
                        >
                            Todas las categorías
                        </button>
                        <button
                            type="button"
                            onClick={() => { setCategoryFilter('critical'); setTypeFilter('all') }}
                            className={`rounded-lg px-2.5 py-1 font-semibold transition shrink-0 flex items-center gap-1 ${
                                categoryFilter === 'critical'
                                    ? 'bg-rose-600 text-white'
                                    : 'bg-rose-50 text-rose-700 hover:bg-rose-100 dark:bg-rose-500/10 dark:text-rose-300'
                            }`}
                        >
                            <span>🔴 Críticas (Bloqueadas & Reclamos)</span>
                        </button>
                        <button
                            type="button"
                            onClick={() => { setCategoryFilter('customer'); setTypeFilter('all') }}
                            className={`rounded-lg px-2.5 py-1 font-semibold transition shrink-0 flex items-center gap-1 ${
                                categoryFilter === 'customer'
                                    ? 'bg-indigo-600 text-white'
                                    : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-500/10 dark:text-indigo-300'
                            }`}
                        >
                            <span>💬 Atención a Clientes</span>
                        </button>
                        <button
                            type="button"
                            onClick={() => { setCategoryFilter('inventory'); setTypeFilter('all') }}
                            className={`rounded-lg px-2.5 py-1 font-semibold transition shrink-0 flex items-center gap-1 ${
                                categoryFilter === 'inventory'
                                    ? 'bg-amber-600 text-white'
                                    : 'bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-500/10 dark:text-amber-300'
                            }`}
                        >
                            <span>📦 Inventario & Stock</span>
                        </button>
                        <button
                            type="button"
                            onClick={() => { setCategoryFilter('operation'); setTypeFilter('all') }}
                            className={`rounded-lg px-2.5 py-1 font-semibold transition shrink-0 flex items-center gap-1 ${
                                categoryFilter === 'operation'
                                    ? 'bg-purple-600 text-white'
                                    : 'bg-purple-50 text-purple-700 hover:bg-purple-100 dark:bg-purple-500/10 dark:text-purple-300'
                            }`}
                        >
                            <span>🚚 Operación AMS</span>
                        </button>
                    </div>
                </div>

                {/* NOTIFICATIONS LIST */}
                <div className="space-y-3">
                    {filteredNotifications.length === 0 ? (
                        <div className="rounded-2xl border border-dashed border-slate-200 bg-white p-12 text-center shadow-2xs dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-50 text-3xl dark:bg-emerald-500/10">
                                🎉
                            </div>
                            <h3 className="text-base font-bold text-slate-900 dark:text-white">
                                ¡No hay alertas pendientes!
                            </h3>
                            <p className="mt-1 text-xs text-slate-500 dark:text-neutral-400 max-w-md mx-auto">
                                Todo está al día en publicaciones, preguntas, mensajes posventa, reclamos y stock de inventario.
                            </p>
                            {(searchTerm || categoryFilter !== 'all' || typeFilter !== 'all' || statusFilter !== 'all') && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setSearchTerm('')
                                        setCategoryFilter('all')
                                        setTypeFilter('all')
                                        setStatusFilter('all')
                                    }}
                                    className="mt-4 inline-flex items-center gap-1 rounded-xl bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-200 dark:bg-neutral-800 dark:text-neutral-200"
                                >
                                    Limpiar todos los filtros
                                </button>
                            )}
                        </div>
                    ) : (
                        filteredNotifications.map((item) => {
                            const isUnread = !item.is_read
                            const isProcessing = processingKey === item.id

                            return (
                                <div
                                    key={item.id}
                                    className={`relative flex flex-col gap-3 rounded-2xl border p-4 shadow-2xs transition sm:flex-row sm:items-center sm:justify-between ${
                                        item.severity === 'danger'
                                            ? isUnread
                                                ? 'border-rose-300 bg-rose-50/40 ring-1 ring-rose-500/20 dark:border-rose-500/40 dark:bg-rose-500/5'
                                                : 'border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900'
                                            : isUnread
                                              ? 'border-indigo-200 bg-indigo-50/30 ring-1 ring-indigo-500/20 dark:border-indigo-500/30 dark:bg-indigo-500/5'
                                              : 'border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900'
                                    } ${isProcessing ? 'opacity-50 pointer-events-none' : ''}`}
                                >
                                    {/* Left: Icon & Description */}
                                    <div className="flex items-start gap-3.5 min-w-0 flex-1">
                                        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-white shadow-xs ring-1 ring-slate-200 text-xl dark:bg-neutral-800 dark:ring-neutral-700">
                                            {getTypeIcon(item.type)}
                                        </div>

                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2 mb-1">
                                                <span className={`rounded-md border px-2 py-0.5 text-[10px] font-black uppercase tracking-wide ${getSeverityBadge(item.severity)}`}>
                                                    {item.badge_text}
                                                </span>
                                                <h4 className="text-sm font-bold text-slate-900 dark:text-white">
                                                    {item.title}
                                                </h4>
                                                {isUnread && (
                                                    <span className="flex h-2 w-2 rounded-full bg-indigo-600 ring-4 ring-indigo-600/20" title="No leída" />
                                                )}
                                                <span className="text-[11px] text-slate-400 dark:text-neutral-500 ml-auto">
                                                    {item.time}
                                                </span>
                                            </div>

                                            {item.subtitle && (
                                                <p className="text-xs font-semibold text-slate-700 dark:text-neutral-300">
                                                    {item.subtitle}
                                                </p>
                                            )}

                                            <p className="mt-1 text-xs text-slate-600 dark:text-neutral-400 leading-relaxed">
                                                {item.message}
                                            </p>
                                        </div>
                                    </div>

                                    {/* Right: Actions */}
                                    <div className="flex items-center gap-2 sm:shrink-0 pt-2 sm:pt-0 border-t border-slate-100 sm:border-0 dark:border-neutral-800">
                                        {/* Action CTA Button */}
                                        <Link
                                            href={item.action_url}
                                            className={`inline-flex items-center gap-1.5 rounded-xl px-3.5 py-2 text-xs font-bold text-white shadow-xs transition active:scale-95 ${
                                                item.severity === 'danger'
                                                    ? 'bg-rose-600 hover:bg-rose-700'
                                                    : 'bg-indigo-600 hover:bg-indigo-700'
                                            }`}
                                        >
                                            <span>{item.action_label || 'Resolver'}</span>
                                            <span>→</span>
                                        </Link>

                                        {/* Mark as Read Button */}
                                        {isUnread && (
                                            <button
                                                type="button"
                                                onClick={() => handleMarkAsRead(item.id)}
                                                className="rounded-xl border border-slate-200 bg-white px-2.5 py-2 text-xs font-semibold text-slate-600 shadow-2xs hover:bg-slate-50 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                                title="Marcar como leída"
                                            >
                                                ✓ Leída
                                            </button>
                                        )}

                                        {/* Dismiss Button */}
                                        <button
                                            type="button"
                                            onClick={() => handleDismiss(item.id)}
                                            className="rounded-xl border border-slate-200 bg-white px-2.5 py-2 text-xs font-semibold text-slate-400 shadow-2xs hover:bg-slate-50 hover:text-rose-600 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-400 dark:hover:text-rose-400"
                                            title="Ocultar de la lista"
                                        >
                                            ✕
                                        </button>
                                    </div>
                                </div>
                            )
                        })
                    )}
                </div>
            </div>
        </AppShell>
    )
}
