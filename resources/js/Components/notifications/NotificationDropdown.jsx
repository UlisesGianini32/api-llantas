import React, { useState, useRef, useEffect } from 'react'
import { Link, usePage, router } from '@inertiajs/react'

function IconBell({ className = 'h-5 w-5' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.8}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
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

function getSeverityBadge(severity) {
    if (severity === 'danger') {
        return 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-500/10 dark:text-rose-400 dark:border-rose-500/20'
    }
    if (severity === 'warning') {
        return 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-500/10 dark:text-amber-400 dark:border-amber-500/20'
    }
    return 'bg-sky-50 text-sky-700 border-sky-200 dark:bg-sky-500/10 dark:text-sky-400 dark:border-sky-500/20'
}

function getTypeIcon(type, severity) {
    if (type === 'publication') {
        return '🚫'
    }
    if (type === 'claim') {
        return '⚠️'
    }
    if (type === 'message') {
        return '💬'
    }
    if (type === 'question') {
        return '❓'
    }
    if (type === 'stock') {
        return '📦'
    }
    if (type === 'full') {
        return '⚡'
    }
    return '🚨'
}

export default function NotificationDropdown() {
    const { notifications_summary } = usePage().props
    const [open, setOpen] = useState(false)
    const [activeTab, setActiveTab] = useState('all') // 'all', 'critical', 'customer', 'inventory'
    const [markingAll, setMarkingAll] = useState(false)
    const dropdownRef = useRef(null)

    const totalUnread = notifications_summary?.total_unread ?? 0
    const hasCritical = notifications_summary?.has_critical ?? false
    const items = notifications_summary?.latest ?? []

    // Close when clicking outside or pressing Escape
    useEffect(() => {
        function handleClickOutside(e) {
            if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
                setOpen(false)
            }
        }
        function handleKeyDown(e) {
            if (e.key === 'Escape') {
                setOpen(false)
            }
        }

        if (open) {
            document.addEventListener('mousedown', handleClickOutside)
            document.addEventListener('keydown', handleKeyDown)
        }
        return () => {
            document.removeEventListener('mousedown', handleClickOutside)
            document.removeEventListener('keydown', handleKeyDown)
        }
    }, [open])

    const handleMarkAllAsRead = (e) => {
        e.preventDefault()
        e.stopPropagation()
        setMarkingAll(true)
        router.post('/notificaciones/leer-todas', {}, {
            preserveScroll: true,
            onFinish: () => setMarkingAll(false),
        })
    }

    const handleMarkAsRead = (e, key) => {
        e.preventDefault()
        e.stopPropagation()
        router.post(`/notificaciones/${encodeURIComponent(key)}/leer`, {}, {
            preserveScroll: true,
        })
    }

    // Filter items according to activeTab
    const filteredItems = items.filter((item) => {
        if (activeTab === 'critical') {
            return item.category === 'critical' || item.severity === 'danger'
        }
        if (activeTab === 'customer') {
            return item.category === 'customer' || item.type === 'question' || item.type === 'message'
        }
        if (activeTab === 'inventory') {
            return item.category === 'inventory' || item.type === 'stock' || item.type === 'full'
        }
        return true
    })

    return (
        <div className="relative" ref={dropdownRef}>
            {/* Bell Trigger Button */}
            <button
                type="button"
                onClick={() => setOpen((prev) => !prev)}
                className={`relative flex h-9 w-9 items-center justify-center rounded-xl border transition shadow-2xs ${
                    open
                        ? 'border-indigo-400 bg-indigo-50/80 text-indigo-700 dark:border-indigo-500/40 dark:bg-indigo-500/20 dark:text-indigo-300'
                        : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 hover:text-slate-900 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:hover:text-white'
                }`}
                title={totalUnread > 0 ? `${totalUnread} notificaciones pendientes` : 'Notificaciones'}
                aria-label="Notificaciones"
                aria-expanded={open}
            >
                <IconBell className="h-4 w-4" />

                {/* Badge Indicator */}
                {totalUnread > 0 && (
                    <span
                        className={`absolute -top-1 -right-1 flex h-4 min-w-[1rem] items-center justify-center rounded-full px-1 text-[10px] font-black text-white shadow-xs ${
                            hasCritical
                                ? 'bg-rose-600 ring-2 ring-rose-300 dark:ring-rose-950 animate-pulse'
                                : 'bg-indigo-600 ring-2 ring-white dark:ring-neutral-900'
                        }`}
                    >
                        {totalUnread > 99 ? '99+' : totalUnread}
                    </span>
                )}
            </button>

            {/* Dropdown Floating Popover */}
            {open && (
                <div className="absolute right-0 mt-2 z-50 w-[min(26rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white shadow-2xl ring-1 ring-black/5 dark:border-neutral-800 dark:bg-neutral-900 dark:ring-white/5 animate-in fade-in-0 zoom-in-95 duration-150">
                    {/* Header */}
                    <div className="flex items-center justify-between border-b border-slate-100 px-4 py-3 dark:border-neutral-800">
                        <div className="flex items-center gap-2">
                            <span className="text-sm font-bold text-slate-900 dark:text-white">
                                Notificaciones
                            </span>
                            {totalUnread > 0 ? (
                                <span className={`rounded-full px-2 py-0.5 text-[10px] font-black ${
                                    hasCritical
                                        ? 'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300'
                                        : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300'
                                }`}>
                                    {totalUnread} pendientes
                                </span>
                            ) : (
                                <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300">
                                    Al día
                                </span>
                            )}
                        </div>

                        {totalUnread > 0 && (
                            <button
                                type="button"
                                onClick={handleMarkAllAsRead}
                                disabled={markingAll}
                                className="flex items-center gap-1 text-[11px] font-semibold text-slate-500 hover:text-indigo-600 transition disabled:opacity-50 dark:text-neutral-400 dark:hover:text-indigo-400"
                                title="Marcar todas como leídas"
                            >
                                <IconCheckAll className="h-3.5 w-3.5" />
                                <span>{markingAll ? 'Marcando...' : 'Leídas'}</span>
                            </button>
                        )}
                    </div>

                    {/* Quick Category Tabs */}
                    <div className="flex items-center gap-1 border-b border-slate-100 bg-slate-50/60 px-3 py-1.5 dark:border-neutral-800 dark:bg-neutral-950/40 text-[11px] font-semibold overflow-x-auto">
                        <button
                            type="button"
                            onClick={() => setActiveTab('all')}
                            className={`rounded-lg px-2.5 py-1 transition shrink-0 ${
                                activeTab === 'all'
                                    ? 'bg-white text-indigo-700 font-bold shadow-2xs dark:bg-neutral-800 dark:text-indigo-300'
                                    : 'text-slate-500 hover:text-slate-800 dark:text-neutral-400 dark:hover:text-neutral-200'
                            }`}
                        >
                            Todas ({items.length})
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveTab('critical')}
                            className={`rounded-lg px-2.5 py-1 transition shrink-0 flex items-center gap-1 ${
                                activeTab === 'critical'
                                    ? 'bg-white text-rose-700 font-bold shadow-2xs dark:bg-neutral-800 dark:text-rose-300'
                                    : 'text-slate-500 hover:text-slate-800 dark:text-neutral-400 dark:hover:text-neutral-200'
                            }`}
                        >
                            <span>🔴 Críticas</span>
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveTab('customer')}
                            className={`rounded-lg px-2.5 py-1 transition shrink-0 flex items-center gap-1 ${
                                activeTab === 'customer'
                                    ? 'bg-white text-indigo-700 font-bold shadow-2xs dark:bg-neutral-800 dark:text-indigo-300'
                                    : 'text-slate-500 hover:text-slate-800 dark:text-neutral-400 dark:hover:text-neutral-200'
                            }`}
                        >
                            <span>💬 Clientes</span>
                        </button>
                        <button
                            type="button"
                            onClick={() => setActiveTab('inventory')}
                            className={`rounded-lg px-2.5 py-1 transition shrink-0 flex items-center gap-1 ${
                                activeTab === 'inventory'
                                    ? 'bg-white text-amber-700 font-bold shadow-2xs dark:bg-neutral-800 dark:text-amber-300'
                                    : 'text-slate-500 hover:text-slate-800 dark:text-neutral-400 dark:hover:text-neutral-200'
                            }`}
                        >
                            <span>📦 Stock</span>
                        </button>
                    </div>

                    {/* Scrollable Notification Items List */}
                    <div className="max-h-[22rem] divide-y divide-slate-100 overflow-y-auto dark:divide-neutral-800 custom-scrollbar">
                        {filteredItems.length === 0 ? (
                            <div className="p-8 text-center">
                                <div className="mx-auto mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50 text-lg dark:bg-emerald-500/10">
                                    🎉
                                </div>
                                <p className="text-xs font-bold text-slate-800 dark:text-neutral-200">
                                    ¡Todo al día!
                                </p>
                                <p className="mt-0.5 text-[11px] text-slate-500 dark:text-neutral-400">
                                    No hay alertas pendientes en esta sección.
                                </p>
                            </div>
                        ) : (
                            filteredItems.map((item) => {
                                const isUnread = !item.is_read

                                return (
                                    <div
                                        key={item.id}
                                        className={`group relative flex items-start gap-3 p-3.5 transition hover:bg-slate-50 dark:hover:bg-neutral-800/60 ${
                                            isUnread
                                                ? 'bg-indigo-50/20 dark:bg-indigo-500/5'
                                                : 'opacity-85'
                                        }`}
                                    >
                                        {/* Icon or Type Emoji */}
                                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-sm dark:bg-neutral-800">
                                            {getTypeIcon(item.type, item.severity)}
                                        </div>

                                        {/* Content */}
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center justify-between gap-1 mb-0.5">
                                                <div className="flex items-center gap-1.5 truncate">
                                                    <span className={`inline-block truncate rounded-md border px-1.5 py-0.2 text-[9px] font-bold uppercase ${getSeverityBadge(item.severity)}`}>
                                                        {item.badge_text}
                                                    </span>
                                                    <span className="truncate text-xs font-bold text-slate-900 dark:text-white">
                                                        {item.title}
                                                    </span>
                                                </div>
                                                <span className="shrink-0 text-[10px] text-slate-400 dark:text-neutral-500">
                                                    {item.time}
                                                </span>
                                            </div>

                                            {item.subtitle && (
                                                <p className="truncate text-[11px] font-semibold text-slate-600 dark:text-neutral-300">
                                                    {item.subtitle}
                                                </p>
                                            )}

                                            <p className="line-clamp-2 mt-0.5 text-[11px] text-slate-500 dark:text-neutral-400 leading-snug">
                                                {item.message}
                                            </p>

                                            {/* Action buttons */}
                                            <div className="mt-2 flex items-center justify-between gap-2">
                                                <Link
                                                    href={item.action_url}
                                                    onClick={() => setOpen(false)}
                                                    className={`inline-flex items-center gap-1 rounded-lg px-2.5 py-1 text-[11px] font-bold shadow-2xs transition active:scale-95 ${
                                                        item.severity === 'danger'
                                                            ? 'bg-rose-600 text-white hover:bg-rose-700'
                                                            : 'bg-indigo-600 text-white hover:bg-indigo-700'
                                                    }`}
                                                >
                                                    <span>{item.action_label || 'Resolver'}</span>
                                                    <span>→</span>
                                                </Link>

                                                {isUnread && (
                                                    <button
                                                        type="button"
                                                        onClick={(e) => handleMarkAsRead(e, item.id)}
                                                        className="text-[10px] font-medium text-slate-400 hover:text-slate-700 dark:text-neutral-500 dark:hover:text-neutral-300"
                                                        title="Marcar como leída"
                                                    >
                                                        Marcar leída
                                                    </button>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                )
                            })
                        )}
                    </div>

                    {/* Footer */}
                    <div className="border-t border-slate-100 bg-slate-50/70 p-2 text-center dark:border-neutral-800 dark:bg-neutral-950/60 rounded-b-2xl">
                        <Link
                            href="/notificaciones"
                            onClick={() => setOpen(false)}
                            className="inline-flex items-center gap-1 text-xs font-bold text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300 transition"
                        >
                            <span>Ver Centro de Notificaciones Completo</span>
                            <span>→</span>
                        </Link>
                    </div>
                </div>
            )}
        </div>
    )
}
