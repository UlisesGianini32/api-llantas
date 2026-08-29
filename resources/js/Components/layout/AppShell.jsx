import FlashToasts from '@/Components/ui/FlashToasts'
import { Head, Link, usePage } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'

function NavItem({ href, children, active = false, danger = false, onNavigate }) {
    const base = 'block rounded-xl px-3 py-2 text-sm font-medium transition'

    const state = danger
        ? active
            ? 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'
            : 'text-slate-700 hover:bg-rose-50 hover:text-rose-700 dark:text-slate-300 dark:hover:bg-rose-500/10 dark:hover:text-rose-300'
        : active
          ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300'
          : 'text-slate-700 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-neutral-800'

    return (
        <Link href={href} className={`${base} ${state}`} onClick={onNavigate}>
            {children}
        </Link>
    )
}

function SidebarUserMenu({ user, onNavigate }) {
    const [open, setOpen] = useState(false)
    const menuRef = useRef(null)

    useEffect(() => {
        function handleClickOutside(event) {
            if (menuRef.current && !menuRef.current.contains(event.target)) {
                setOpen(false)
            }
        }

        document.addEventListener('mousedown', handleClickOutside)

        return () => {
            document.removeEventListener('mousedown', handleClickOutside)
        }
    }, [])

    return (
        <div className="relative" ref={menuRef}>
            <button
                type="button"
                onClick={() => setOpen((prev) => !prev)}
                className="w-full rounded-2xl border border-slate-200 bg-gradient-to-br from-white to-slate-50 p-4 text-left shadow-sm transition hover:border-slate-300 hover:shadow-md dark:border-neutral-800 dark:from-neutral-900 dark:to-neutral-950 dark:hover:border-neutral-700"
            >
                <div className="flex items-center gap-3">
                    <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-indigo-100 text-sm font-bold text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">
                        {user?.name?.charAt(0)?.toUpperCase() ?? 'U'}
                    </div>

                    <div className="min-w-0 flex-1">
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            Usuario
                        </p>
                        <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">
                            {user?.name ?? 'Sin sesión'}
                        </p>
                        <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                            {user?.email ?? ''}
                        </p>
                    </div>

                    <svg
                        className={`h-5 w-5 shrink-0 text-slate-400 transition-transform ${
                            open ? 'rotate-180' : ''
                        }`}
                        viewBox="0 0 20 20"
                        fill="currentColor"
                    >
                        <path
                            fillRule="evenodd"
                            d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
                            clipRule="evenodd"
                        />
                    </svg>
                </div>
            </button>

            {open && (
                <div className="absolute bottom-full left-0 z-50 mb-2 w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl dark:border-neutral-800 dark:bg-neutral-900">
                    <Link
                        href="/settings/profile"
                        className="block px-4 py-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-neutral-800"
                        onClick={onNavigate}
                    >
                        Configuración
                    </Link>

                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        className="block w-full px-4 py-3 text-left text-sm font-medium text-rose-600 transition hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10"
                        onClick={onNavigate}
                    >
                        Cerrar sesión
                    </Link>
                </div>
            )}
        </div>
    )
}

function SidebarBrand() {
    return (
        <div className="mb-6 rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-neutral-800 dark:bg-neutral-950">
            <div className="flex items-center gap-3">
                <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-white shadow-sm dark:bg-neutral-800">
                    <img src="/logo-llantas.png" alt="Llantas" className="h-8 w-8 object-contain" />
                </div>
                <div>
                    <p className="text-sm font-bold">Llantas</p>
                    <p className="text-xs text-slate-500 dark:text-slate-400">Panel administrativo</p>
                </div>
            </div>
        </div>
    )
}

function SidebarNav({ currentPath, onNavigate, pendingQuestions = 0 }) {
    return (
        <nav className="space-y-6">
            <div>
                <p className="mb-2 px-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                    General
                </p>
                <div className="space-y-1">
                    <NavItem href="/dashboard" active={currentPath === '/dashboard'} onNavigate={onNavigate}>
                        Dashboard
                    </NavItem>
                </div>
            </div>

            <div>
                <p className="mb-2 px-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                    Inventario
                </p>
                <div className="space-y-1">
                    <NavItem
                        href="/producto"
                        active={currentPath.startsWith('/producto')}
                        onNavigate={onNavigate}
                    >
                        Productos ML
                    </NavItem>
                    <NavItem href="/ml/compare" active={currentPath.startsWith('/ml/compare')} onNavigate={onNavigate}>
                        Comparar ML
                    </NavItem>
                    <NavItem
                        href="/llantas"
                        active={currentPath === '/llantas' || currentPath.startsWith('/llantas/')}
                        onNavigate={onNavigate}
                    >
                        Llantas
                    </NavItem>
                    <NavItem
                        href="/productos"
                        active={currentPath === '/productos' || currentPath.startsWith('/productos/')}
                        onNavigate={onNavigate}
                    >
                        Productos compuestos
                    </NavItem>
                    <NavItem
                        href="/price-rules"
                        active={currentPath.startsWith('/price-rules')}
                        onNavigate={onNavigate}
                    >
                        Fórmulas de ventas
                    </NavItem>
                    <NavItem
                        href="/syscom-ml"
                        active={currentPath.startsWith('/syscom-ml')}
                        onNavigate={onNavigate}
                    >
                        SYSCOM → ML
                    </NavItem>
                    <NavItem
                        href="/importar-excel"
                        active={currentPath.startsWith('/importar-excel')}
                        onNavigate={onNavigate}
                    >
                        Importar Excel
                    </NavItem>
                    <NavItem
                        href="/llantas/agotadas"
                        active={currentPath.startsWith('/llantas/agotadas')}
                        danger
                        onNavigate={onNavigate}
                    >
                        Llantas agotadas
                    </NavItem>
                    <NavItem
                        href="/llantas/no-actualizadas"
                        active={currentPath.startsWith('/llantas/no-actualizadas')}
                        onNavigate={onNavigate}
                    >
                        Llantas no actualizadas
                    </NavItem>
                </div>
            </div>

            <div>
                <p className="mb-2 px-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                    Mercado Libre
                </p>
                <div className="space-y-1">
                    <NavItem
                        href="/meli/preguntas"
                        active={currentPath.startsWith('/meli/preguntas')}
                        onNavigate={onNavigate}
                    >
                        <span className="flex items-center justify-between gap-2">
                            <span>Preguntas de productos</span>
                            {pendingQuestions > 0 && (
                                <span className="min-w-6 rounded-full bg-amber-100 px-2 py-0.5 text-center text-xs font-bold text-amber-800 dark:bg-amber-500/20 dark:text-amber-200">
                                    {pendingQuestions > 99 ? '99+' : pendingQuestions}
                                </span>
                            )}
                        </span>
                    </NavItem>

                    <NavItem
                        href="/meli/mensajeria"
                        active={currentPath.startsWith('/meli/mensajeria')}
                        onNavigate={onNavigate}
                    >
                        Mensajería posventa
                    </NavItem>

                    <NavItem
                        href="/meli-claims"
                        active={currentPath.startsWith('/meli-claims')}
                        onNavigate={onNavigate}
                    >
                        Reclamos
                    </NavItem>

                    <NavItem
                        href="/meli/publicaciones"
                        active={currentPath.startsWith('/meli/publicaciones')}
                        onNavigate={onNavigate}
                    >
                        Publicaciones Mercado Libre
                    </NavItem>

                    <NavItem
                        href="/meli-price-manager"
                        active={currentPath === '/meli-price-manager'}
                        onNavigate={onNavigate}
                    >
                        Meli Price Manager
                    </NavItem>

                    <NavItem
                        href="/meli-price-manager/brands"
                        active={currentPath.startsWith('/meli-price-manager/brands')}
                        onNavigate={onNavigate}
                    >
                        Marcas y alias
                    </NavItem>

                    <NavItem
                        href="/meli-price-manager/uncategorized"
                        active={currentPath.startsWith('/meli-price-manager/uncategorized')}
                        onNavigate={onNavigate}
                    >
                        Pendientes de clasificación
                    </NavItem>

                    <NavItem
                        href="/meli/full"
                        active={currentPath.startsWith('/meli/full')}
                        onNavigate={onNavigate}
                    >
                        Inventario FULL
                    </NavItem>
                </div>
            </div>

            <div>
                <p className="mb-2 px-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                    Operaciones
                </p>
                <div className="space-y-1">
                    <NavItem href="/ams/pedidos" active={currentPath.startsWith('/ams/pedidos')} onNavigate={onNavigate}>
                        AMS Pedidos
                    </NavItem>
                    <NavItem
    href="/ams/pedidos-procesar"
    active={currentPath.startsWith('/ams/pedidos-procesar')}
    onNavigate={onNavigate}
>
    AMS Procesar
</NavItem>

<NavItem
    href="/ams/pedidos-secundaria"
    active={currentPath.startsWith('/ams/pedidos-secundaria')}
    onNavigate={onNavigate}
>
    AMS Secundaria
</NavItem>

<NavItem
    href="/ams/pedidos-manana"
    active={currentPath.startsWith('/ams/pedidos-manana')}
    onNavigate={onNavigate}
>
    AMS Mañana
</NavItem>
                </div>
            </div>

            <div>
                <p className="mb-2 px-2 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500 dark:text-slate-400">
                    Sistema
                </p>
                <div className="space-y-1">
                    <NavItem href="/sistema/estado" active={currentPath.startsWith('/sistema/estado')} onNavigate={onNavigate}>
                        Estado del sistema
                    </NavItem>

                    <NavItem
                        href="/sistema/colas"
                        active={currentPath.startsWith('/sistema/colas')}
                        onNavigate={onNavigate}
                    >
                        Colas
                    </NavItem>
                    <NavItem
                        href="/sistema/logs"
                        active={currentPath.startsWith('/sistema/logs')}
                        onNavigate={onNavigate}
                    >
                        Logs
                    </NavItem>
                    <NavItem
                        href="/sistema/acciones"
                        active={currentPath.startsWith('/sistema/acciones')}
                        onNavigate={onNavigate}
                    >
                        Acciones rápidas
                    </NavItem>

                </div>
            </div>

        </nav>
    )
}

function SidebarFooter({ user, onNavigate }) {
    return (
        <>
            <div className="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-4 dark:border-neutral-800 dark:bg-neutral-950">
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    Estado del panel
                </p>
                <p className="mt-2 text-sm font-semibold">Sistema de llantas</p>
                <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    Administra inventario, compuestos, AMS y Mercado Libre.
                </p>
            </div>
            <div className="mt-auto pt-4">
                <SidebarUserMenu user={user} onNavigate={onNavigate} />
            </div>
        </>
    )
}

export default function AppShell({ title = 'Dashboard', children }) {
    const { auth, meli_questions_pending = 0 } = usePage().props
    const [mobileNavOpen, setMobileNavOpen] = useState(false)
    const currentPath =
        typeof window !== 'undefined' ? window.location.pathname : ''

    const closeMobile = () => setMobileNavOpen(false)

    useEffect(() => {
        document.body.style.overflow = mobileNavOpen ? 'hidden' : ''
        return () => {
            document.body.style.overflow = ''
        }
    }, [mobileNavOpen])

    return (
        <>
            <Head title={title} />
            <FlashToasts />

            <div className="min-h-screen bg-slate-50 text-slate-900 dark:bg-neutral-950 dark:text-slate-100">
                <div className="flex min-h-screen">
                    {/* Móvil: overlay + drawer */}
                    {mobileNavOpen && (
                        <div className="fixed inset-0 z-50 lg:hidden" aria-modal="true" role="dialog">
                            <button
                                type="button"
                                className="absolute inset-0 bg-black/50"
                                aria-label="Cerrar menú"
                                onClick={closeMobile}
                            />
                            <div className="absolute left-0 top-0 flex h-full w-[min(18rem,88vw)] flex-col border-r border-slate-200 bg-white p-4 shadow-xl dark:border-neutral-800 dark:bg-neutral-900">
                                <div className="mb-4 flex items-center justify-between">
                                    <span className="text-sm font-bold text-slate-900 dark:text-white">Menú</span>
                                    <button
                                        type="button"
                                        onClick={closeMobile}
                                        className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-neutral-800"
                                        aria-label="Cerrar"
                                    >
                                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                strokeWidth={2}
                                                d="M6 18L18 6M6 6l12 12"
                                            />
                                        </svg>
                                    </button>
                                </div>
                                <SidebarBrand />
                                <div className="min-h-0 flex-1 overflow-y-auto">
                                    <SidebarNav
                                        currentPath={currentPath}
                                        onNavigate={closeMobile}
                                        pendingQuestions={meli_questions_pending}
                                    />
                                </div>
                                <SidebarFooter user={auth?.user} onNavigate={closeMobile} />
                            </div>
                        </div>
                    )}

                    <aside className="hidden w-72 shrink-0 border-r border-slate-200 bg-white lg:block dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="flex h-full flex-col p-4">
                            <SidebarBrand />
                            <SidebarNav currentPath={currentPath} pendingQuestions={meli_questions_pending} />
                            <SidebarFooter user={auth?.user} />
                        </div>
                    </aside>

                    <main className="min-w-0 flex-1">
                        <div className="border-b border-slate-200 bg-white px-4 py-3 sm:px-6 sm:py-4 dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="flex items-center justify-between gap-3">
                                <div className="flex min-w-0 flex-1 items-center gap-3">
                                    <button
                                        type="button"
                                        className="shrink-0 rounded-xl border border-slate-200 p-2 text-slate-700 hover:bg-slate-50 lg:hidden dark:border-neutral-700 dark:text-slate-200 dark:hover:bg-neutral-800"
                                        onClick={() => setMobileNavOpen(true)}
                                        aria-label="Abrir menú"
                                    >
                                        <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                strokeWidth={2}
                                                d="M4 6h16M4 12h16M4 18h16"
                                            />
                                        </svg>
                                    </button>
                                    <div className="min-w-0">
                                        <h1 className="truncate text-base font-bold sm:text-lg">{title}</h1>
                                        <p className="hidden text-sm text-slate-500 dark:text-slate-400 sm:block">
                                            Panel administrativo
                                        </p>
                                    </div>
                                </div>

                                <div className="hidden shrink-0 text-sm text-slate-500 dark:text-slate-400 sm:block">
                                    {new Date().toLocaleString()}
                                </div>
                            </div>
                        </div>

                        <div className="overflow-x-auto p-4 sm:p-6">
                            {children}
                        </div>
                    </main>
                </div>
            </div>
        </>
    )
}
