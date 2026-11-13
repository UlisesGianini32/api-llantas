import { Head, Link, usePage } from '@inertiajs/react'
import { useCallback, useEffect, useId, useRef, useState } from 'react'
import {
    getAutopartesNavigationSections,
    isAutopartesRouteActive,
} from '@/lib/autopartesCapabilities'

function HamburgerIcon({ className = 'h-6 w-6' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
        </svg>
    )
}

function CloseIcon({ className = 'h-5 w-5' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
    )
}

function LockIcon({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
        </svg>
    )
}

function NavSectionIcon({ itemKey, className = 'h-4 w-4 shrink-0' }) {
    switch (itemKey) {
        case 'catalog':
            return (
                <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 00-1.883 2.542l.857 6a2.25 2.25 0 002.227 1.932H19.05a2.25 2.25 0 002.227-1.932l.857-6a2.25 2.25 0 00-1.883-2.542m-16.5 0V6A2.25 2.25 0 016 3.75h3.879a1.5 1.5 0 011.06.44l2.122 2.12a1.5 1.5 0 001.06.44H18A2.25 2.25 0 0120.25 9v.776" />
                </svg>
            )
        case 'inventory':
            return (
                <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                </svg>
            )
        case 'receipts':
            return (
                <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 013.75 9.375v-4.5zM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 01-1.125-1.125v-4.5zM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0113.5 9.375v-4.5z" />
                    <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 14.625h2.25m3.375 0h1.125m-6.75 3.375h3.375m0 0h3.375m-3.375 0v2.25" />
                </svg>
            )
        case 'pos':
            return (
                <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 21z" />
                </svg>
            )
        case 'movements':
            return (
                <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
                </svg>
            )
        default:
            return (
                <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75} aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h6m-6 4h6m2 5H7a2.25 2.25 0 01-2.25-2.25V5.25A2.25 2.25 0 017 3h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19.75A2.25 2.25 0 0117 21z" />
                </svg>
            )
    }
}

export default function AutopartesLayout({ branch, title, children }) {
    const page = usePage()
    const {
        errors = {},
        auth,
        flash = {},
        capabilities = [],
        autopartes_nav: autopartesNav = null,
    } = page.props

    const currentUrl = page.url || (typeof window !== 'undefined' ? window.location.pathname : '')
    const [isDrawerOpen, setIsDrawerOpen] = useState(false)
    const menuButtonRef = useRef(null)
    const closeButtonRef = useRef(null)
    const drawerId = useId()

    const effectiveCapabilities = Array.isArray(capabilities) && capabilities.length > 0
        ? capabilities
        : (autopartesNav?.capabilities || [])

    const branchCode = branch?.code || autopartesNav?.branch?.code || 'HMO'
    const branchDisplayName = branch?.name && branch.name.toUpperCase() !== 'HERMOSILLO'
        ? branch.name
        : 'Hermosillo'
    const moduleTitle = `Autopartes ${branchDisplayName}`

    const { primary, admin } = getAutopartesNavigationSections(
        branch || autopartesNav?.branch || { code: branchCode, name: branchDisplayName },
        effectiveCapabilities,
        autopartesNav,
    )

    const closeDrawer = useCallback((restoreFocus = false) => {
        setIsDrawerOpen(false)
        if (restoreFocus) {
            setTimeout(() => menuButtonRef.current?.focus(), 0)
        }
    }, [])

    // Close drawer automatically upon Inertia navigation without altering active inputs while open
    useEffect(() => {
        setIsDrawerOpen(false)
    }, [currentUrl])

    // Escape key & body scroll lock strictly scoped to when drawer itself is open
    useEffect(() => {
        if (!isDrawerOpen) {
            return undefined
        }

        const prevOverflow = typeof document !== 'undefined' ? document.body.style.overflow : ''
        if (typeof document !== 'undefined') {
            document.body.style.overflow = 'hidden'
        }

        const focusTimer = setTimeout(() => {
            closeButtonRef.current?.focus()
        }, 0)

        const handleKeyDown = e => {
            if (e.key === 'Escape') {
                e.preventDefault()
                e.stopPropagation()
                closeDrawer(true)
            }
        }

        document.addEventListener('keydown', handleKeyDown)
        return () => {
            clearTimeout(focusTimer)
            document.removeEventListener('keydown', handleKeyDown)
            if (typeof document !== 'undefined') {
                document.body.style.overflow = prevOverflow
            }
        }
    }, [isDrawerOpen, closeDrawer])

    const renderNavItem = item => {
        if (!item.enabled || !item.href) {
            return (
                <li key={item.key}>
                    <div
                        role="listitem"
                        aria-disabled="true"
                        className="cursor-not-allowed rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5 text-slate-500 dark:border-neutral-800 dark:bg-neutral-950/60 dark:text-neutral-400"
                    >
                        <div className="flex items-center justify-between gap-2">
                            <span className="flex items-center gap-2.5 text-sm font-medium">
                                <LockIcon className="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                                <span>{item.label}</span>
                            </span>
                            {item.badge && (
                                <span className="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-950/70 dark:text-amber-300">
                                    {item.badge}
                                </span>
                            )}
                        </div>
                        {item.disabledReason && (
                            <p className="mt-1 pl-6 text-xs leading-snug text-slate-500 dark:text-neutral-400">
                                {item.disabledReason}
                            </p>
                        )}
                    </div>
                </li>
            )
        }

        const active = isAutopartesRouteActive(currentUrl, item)

        return (
            <li key={item.key}>
                <Link
                    href={item.href}
                    onClick={() => setIsDrawerOpen(false)}
                    aria-current={active ? 'page' : undefined}
                    className={`flex flex-col rounded-xl px-3 py-2.5 text-sm transition-colors focus:outline-hidden focus:ring-2 focus:ring-indigo-500 ${
                        active
                            ? 'bg-indigo-600 font-semibold text-white shadow-xs dark:bg-indigo-500'
                            : 'font-medium text-slate-700 hover:bg-indigo-50 hover:text-indigo-700 dark:text-neutral-200 dark:hover:bg-neutral-800 dark:hover:text-white'
                    }`}
                >
                    <div className="flex items-center justify-between gap-2">
                        <span className="flex items-center gap-2.5 min-w-0">
                            <NavSectionIcon itemKey={item.key} />
                            <span className="truncate">{item.label}</span>
                        </span>
                        {item.badge ? (
                            <span
                                className={`shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                    active
                                        ? 'bg-white/20 text-white'
                                        : 'bg-amber-100 text-amber-800 dark:bg-amber-950/70 dark:text-amber-300'
                                }`}
                            >
                                {item.badge}
                            </span>
                        ) : active ? (
                            <span className="shrink-0 text-[11px] font-bold uppercase tracking-wider text-indigo-100">
                                Activa
                            </span>
                        ) : null}
                    </div>
                    {item.note && (
                        <span
                            className={`mt-1 pl-6 text-xs leading-snug ${
                                active ? 'text-indigo-100' : 'text-slate-500 dark:text-neutral-400'
                            }`}
                        >
                            {item.note}
                        </span>
                    )}
                </Link>
            </li>
        )
    }

    return (
        <main className="min-h-screen overflow-x-clip bg-slate-50 text-slate-900 dark:bg-neutral-950 dark:text-neutral-100">
            <Head title={`${title} · ${moduleTitle}`} />

            <header className="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur-xs dark:border-neutral-800 dark:bg-neutral-900/95">
                <div className="mx-auto flex max-w-screen-2xl items-center justify-between gap-3 px-3 py-3 sm:px-6 sm:py-4">
                    <div className="flex min-w-0 items-center gap-3">
                        <button
                            ref={menuButtonRef}
                            type="button"
                            onClick={() => setIsDrawerOpen(prev => !prev)}
                            aria-label={isDrawerOpen ? 'Cerrar menú de navegación' : 'Abrir menú de navegación'}
                            aria-expanded={isDrawerOpen}
                            aria-controls={drawerId}
                            className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 shadow-2xs hover:bg-slate-100 hover:text-indigo-700 focus:outline-hidden focus:ring-2 focus:ring-indigo-500 active:scale-95 transition dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                        >
                            <HamburgerIcon className="h-6 w-6" />
                        </button>

                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-1.5 sm:gap-2">
                                <span className="text-xs font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">
                                    {moduleTitle}
                                </span>
                                <span className="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700 ring-1 ring-inset ring-indigo-200 dark:bg-indigo-950/60 dark:text-indigo-300 dark:ring-indigo-800">
                                    Sucursal {branchCode}
                                </span>
                            </div>
                            <h1 className="truncate text-base font-semibold text-slate-900 sm:text-xl dark:text-white">
                                {title}
                            </h1>
                        </div>
                    </div>

                    <div className="flex shrink-0 items-center gap-2 sm:gap-3 text-right text-xs sm:text-sm">
                        <div className="min-w-0">
                            <p className="max-w-[120px] truncate font-medium text-slate-800 sm:max-w-xs dark:text-neutral-200">
                                {auth?.user?.name || 'Usuario'}
                            </p>
                            <Link
                                href="/logout"
                                method="post"
                                as="button"
                                className="font-medium text-slate-500 hover:text-red-600 dark:text-neutral-400 dark:hover:text-red-400"
                            >
                                Cerrar sesión
                            </Link>
                        </div>
                    </div>
                </div>
            </header>

            {isDrawerOpen && (
                <>
                    <div
                        className="fixed inset-0 z-40 bg-slate-900/55 backdrop-blur-[1px]"
                        onClick={() => closeDrawer(false)}
                        aria-hidden="true"
                    />
                    <aside
                        id={drawerId}
                        role="dialog"
                        aria-modal="true"
                        aria-label={`Navegación ${moduleTitle}`}
                        className="fixed inset-y-0 left-0 z-50 flex w-[min(20.5rem,88vw)] flex-col border-r border-slate-200 bg-white shadow-2xl dark:border-neutral-800 dark:bg-neutral-900"
                    >
                        <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-4 py-4 dark:border-neutral-800">
                            <div className="min-w-0">
                                <p className="text-sm font-bold text-slate-900 dark:text-white">
                                    {moduleTitle}
                                </p>
                                <p className="mt-0.5 inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700 dark:bg-indigo-950/70 dark:text-indigo-300">
                                    Sucursal {branchCode}
                                </p>
                            </div>
                            <button
                                ref={closeButtonRef}
                                type="button"
                                onClick={() => closeDrawer(true)}
                                aria-label="Cerrar menú"
                                className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-slate-600 hover:bg-slate-100 hover:text-slate-900 focus:outline-hidden focus:ring-2 focus:ring-indigo-500 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                            >
                                <CloseIcon />
                            </button>
                        </div>

                        <nav
                            aria-label="Secciones de Autopartes"
                            className="flex-1 overflow-y-auto px-3 py-4 space-y-5"
                        >
                            {primary.length > 0 && (
                                <div>
                                    <p className="mb-2 px-2 text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-neutral-500">
                                        Operación · Sucursal {branchCode}
                                    </p>
                                    <ul className="space-y-1.5">
                                        {primary.map(renderNavItem)}
                                    </ul>
                                </div>
                            )}

                            {admin.length > 0 && (
                                <div>
                                    <p className="mb-2 px-2 text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-neutral-500">
                                        Administración y comercial
                                    </p>
                                    <ul className="space-y-1.5">
                                        {admin.map(renderNavItem)}
                                    </ul>
                                </div>
                            )}

                            {primary.length === 0 && admin.length === 0 && (
                                <p className="px-3 text-xs text-slate-500 dark:text-neutral-400">
                                    Sin secciones habilitadas para tu usuario en esta sucursal.
                                </p>
                            )}
                        </nav>

                        <div className="border-t border-slate-200 bg-slate-50/70 px-4 py-3 text-sm dark:border-neutral-800 dark:bg-neutral-950/60">
                            <p className="truncate font-semibold text-slate-800 dark:text-neutral-200">
                                {auth?.user?.name || 'Usuario'}
                            </p>
                            {auth?.user?.email && (
                                <p className="truncate text-xs text-slate-500 dark:text-neutral-400">
                                    {auth.user.email}
                                </p>
                            )}
                            <Link
                                href="/logout"
                                method="post"
                                as="button"
                                onClick={() => setIsDrawerOpen(false)}
                                className="mt-2.5 inline-flex w-full items-center justify-center rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-red-50 hover:text-red-700 hover:border-red-200 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-red-950/40 dark:hover:text-red-300"
                            >
                                Cerrar sesión
                            </Link>
                        </div>
                    </aside>
                </>
            )}

            <section className="mx-auto min-w-0 max-w-screen-2xl space-y-5 px-3 py-4 sm:px-6 sm:py-6">
                <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                    <strong>Aislamiento documental y comercial ({branchCode}):</strong> Las 21 columnas del Excel (<code>Qty</code>, <code>Retail</code>, <code>Ext. Retail</code>) permanecen inmutables como referencia documental. Las ventas en el POS de {branchDisplayName} requieren turno abierto, vendedor autorizado, precio en MXN aprobado y existencias físicas disponibles en la sucursal, sin afectar Mercado Libre ni otros almacenes.
                </div>
                {flash?.success && <div role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{flash.success}</div>}
                {flash?.warning && <div role="alert" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">{flash.warning}</div>}
                {flash?.error && <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">{flash.error}</div>}
                {Object.keys(errors).length > 0 && (
                    <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                        {Object.entries(errors).map(([key, value]) => <p key={key}>{value}</p>)}
                    </div>
                )}
                {children}
            </section>
        </main>
    )
}
