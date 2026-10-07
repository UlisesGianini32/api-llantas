import FlashToasts from '@/Components/ui/FlashToasts'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { useEffect, useId, useRef, useState } from 'react'

/* =========================================================================
   ICONS (Clean, lightweight SVGs inspired by Lucide / Linear)
   ========================================================================= */
function IconDashboard({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
        </svg>
    )
}

function IconPOS({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 21z" />
        </svg>
    )
}

function IconUsers({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
        </svg>
    )
}

function IconBox({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
        </svg>
    )
}

function IconLocation({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
        </svg>
    )
}

function IconArrows({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
        </svg>
    )
}

function IconLock({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
        </svg>
    )
}

function IconPuzzle({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M14.25 6.087c0-.355.186-.676.401-.959.221-.29.349-.634.349-1.003 0-1.036-1.007-1.875-2.25-1.875s-2.25.84-2.25 1.875c0 .369.128.713.349 1.003.215.283.401.604.401.959v0a.75.75 0 01-.75.75H6.75A2.25 2.25 0 004.5 9v3.75a.75.75 0 01-.75.75h0c-.355 0-.676-.186-.959-.401a2.25 2.25 0 00-1.003-.349C.752 12.75 0 13.757 0 15s.752 2.25 1.788 2.25c.369 0 .713-.128 1.003-.349.283-.215.604-.401.959-.401h0a.75.75 0 01.75.75V21a2.25 2.25 0 002.25 2.25h3.75a.75.75 0 01.75.75v0c0 .355-.186.676-.401.959-.221.29-.349.634-.349 1.003 0 1.036 1.007 1.875 2.25 1.875s2.25-.84 2.25-1.875c0-.369-.128-.713-.349-1.003-.215-.283-.401-.604-.401-.959v0a.75.75 0 01.75-.75h3.75A2.25 2.25 0 0021 21v-3.75a.75.75 0 01.75-.75h0c.355 0 .676.186.959.401.29.221.634.349 1.003.349 1.036 0 1.875-.752 1.875-1.788s-.84-2.25-1.875-2.25c-.369 0-.713.128-1.003.349-.283.215-.604.401-.959.401h0a.75.75 0 01-.75-.75V9a2.25 2.25 0 00-2.25-2.25h-3.75a.75.75 0 01-.75-.75v0z" />
        </svg>
    )
}

function IconChart({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
        </svg>
    )
}

function IconShoppingBag({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25c-.67 0-1.19-.578-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007zM8.625 10.5a.375.375 0 11-.75 0 .375.375 0 01.75 0zm7.5 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
        </svg>
    )
}

function IconTag({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
            <path strokeLinecap="round" strokeLinejoin="round" d="M6 6h.008v.008H6V6z" />
        </svg>
    )
}

function IconMeli({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 21v-7.5a.75.75 0 01.75-.75h3a.75.75 0 01.75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 003.75-.615A2.993 2.993 0 009 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 003.75.614m-16.5 0a3.004 3.004 0 01-.621-4.72L4.318 3.44A1.5 1.5 0 015.378 3h13.243a1.5 1.5 0 011.06.44l1.19 1.189a3 3 0 01-.621 4.72m-13.5 0c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 002.25 1.016c.896 0 1.7-.393 2.25-1.016" />
        </svg>
    )
}

function IconMessage({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a.75.75 0 01-.84-.969 4.488 4.488 0 00.73-1.614C3.864 16.71 3 14.475 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
        </svg>
    )
}

function IconAlert({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
        </svg>
    )
}

function IconTruck({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.25m0-11.25V4.875c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v9.375c0 .621.504 1.125 1.125 1.125h.375" />
        </svg>
    )
}

function IconGear({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.869a6.544 6.544 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
            <path strokeLinecap="round" strokeLinejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
    )
}

function IconSearch({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
        </svg>
    )
}

function IconMoon({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
        </svg>
    )
}

function IconSun({ className = 'h-4 w-4' }) {
    return (
        <svg className={className} fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.75}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
        </svg>
    )
}

/* =========================================================================
   NAVIGATION ITEM
   ========================================================================= */
function NavItem({
    href,
    icon: Icon,
    children,
    active = false,
    danger = false,
    badge = null,
    onNavigate,
}) {
    return (
        <Link
            href={href}
            onClick={onNavigate}
            className={`group relative flex items-center justify-between rounded-xl px-3 py-2 text-[13px] font-medium transition-all duration-150 ${
                active
                    ? 'bg-indigo-50/90 text-indigo-700 shadow-xs ring-1 ring-indigo-500/20 dark:bg-indigo-500/15 dark:text-indigo-300 dark:ring-indigo-400/20'
                    : danger
                      ? 'text-rose-600 hover:bg-rose-50 hover:text-rose-700 dark:text-rose-400 dark:hover:bg-rose-500/10'
                      : 'text-slate-600 hover:bg-slate-100/80 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-neutral-800/80 dark:hover:text-white'
            }`}
        >
            <div className="flex min-w-0 items-center gap-2.5">
                {Icon && (
                    <Icon
                        className={`h-4 w-4 shrink-0 transition-colors ${
                            active
                                ? 'text-indigo-600 dark:text-indigo-400'
                                : 'text-slate-400 group-hover:text-slate-700 dark:text-neutral-500 dark:group-hover:text-neutral-300'
                        }`}
                    />
                )}
                <span className="truncate">{children}</span>
            </div>

            {badge !== null && badge !== undefined && (
                <span className="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-800 dark:bg-amber-500/20 dark:text-amber-200">
                    {badge}
                </span>
            )}

            {/* Active Pill Indicator */}
            {active && (
                <span className="absolute -left-1 top-1/2 h-5 w-1 -translate-y-1/2 rounded-r-full bg-indigo-600 dark:bg-indigo-400" />
            )}
        </Link>
    )
}

/* =========================================================================
   SEARCH / COMMAND PALETTE MODAL (⌘K / Ctrl+K)
   ========================================================================= */
const ALL_SEARCH_ITEMS = [
    { title: 'Dashboard', desc: 'Resumen general del inventario y ventas', href: '/dashboard', category: 'General', icon: IconDashboard },
    { title: 'Punto de Venta (POS)', desc: 'Cobro rápido, tickets y venta mostrador', href: '/pos', category: 'General', icon: IconPOS },
    { title: 'Clientes y Créditos', desc: 'Directorio de estilistas, plazos 7/15/30 y pagarés', href: '/pos/clientes', category: 'General', icon: IconUsers },
    { title: 'Almacén (Catálogo maestro)', desc: 'Productos, SKU, códigos de barra y stock', href: '/almacen/productos', category: 'Inventario', icon: IconBox },
    { title: 'Ubicaciones de Almacén', desc: 'Pasillos, estantes E10-1, D10-1 y bins', href: '/almacen/ubicaciones', category: 'Inventario', icon: IconLocation },
    { title: 'Movimientos de Inventario', desc: 'Entradas, salidas y ajustes de stock', href: '/almacen/movimientos', category: 'Inventario', icon: IconArrows },
    { title: 'Reservas de Stock', desc: 'Apartados por pedidos pendientes', href: '/almacen/reservas', category: 'Inventario', icon: IconLock },
    { title: 'Kits y Ensambles', desc: 'Productos agrupados con stock derivado', href: '/almacen/kits', category: 'Inventario', icon: IconPuzzle },
    { title: 'Pronóstico de Compras', desc: 'Sugerencias de reabastecimiento y días de stock', href: '/reabastecimiento/pronostico', category: 'Inventario', icon: IconChart },
    { title: 'Órdenes de Compra', desc: 'Gestión de pedidos a proveedores', href: '/compras/ordenes', category: 'Inventario', icon: IconShoppingBag },
    { title: 'Vincular Publicaciones', desc: 'Vincular catálogo con Mercado Libre, Amazon y Shopify', href: '/almacen/canales/importar', category: 'Inventario', icon: IconTag },
    { title: 'Stock Mercado Libre', desc: 'Monitoreo de existencias publicadas en MeLi', href: '/almacen/canales/mercado-libre/stock', category: 'Inventario', icon: IconMeli },
    { title: 'Comparar ML', desc: 'Comparativa de precios y competidores en ML', href: '/ml/compare', category: 'Inventario', icon: IconMeli },
    { title: 'Llantas Compuestas', desc: 'Combos de 2 y 4 llantas', href: '/productos', category: 'Inventario', icon: IconPuzzle },
    { title: 'Fórmulas de Precios', desc: 'Reglas y márgenes para cálculo de precios', href: '/price-rules', category: 'Inventario', icon: IconChart },
    { title: 'Importar Excel', desc: 'Carga masiva de inventario y listas', href: '/importar-excel', category: 'Inventario', icon: IconBox },
    { title: 'Etiquetas Mercado Libre', desc: 'Impresión térmica ZPL / PDF de envíos', href: '/mercado-libre/etiquetas', category: 'Mercado Libre', icon: IconTag },
    { title: 'Preguntas de Productos', desc: 'Respuestas rápidas a clientes en ML', href: '/meli/preguntas', category: 'Mercado Libre', icon: IconMessage },
    { title: 'Mensajería Posventa', desc: 'Chats con compradores después de su compra', href: '/meli/mensajeria', category: 'Mercado Libre', icon: IconMessage },
    { title: 'Reclamos ML', desc: 'Gestión y resolución de disputas', href: '/meli-claims', category: 'Mercado Libre', icon: IconAlert },
    { title: 'Publicaciones ML', desc: 'Listado completo de publicaciones activas', href: '/meli/publicaciones', category: 'Mercado Libre', icon: IconMeli },
    { title: 'Meli Price Manager', desc: 'Gestión de precios dinámicos por marca', href: '/meli-price-manager', category: 'Mercado Libre', icon: IconTag },
    { title: 'Inventario FULL', desc: 'Monitoreo de existencias en bodegas de MeLi', href: '/meli/full', category: 'Mercado Libre', icon: IconBox },
    { title: 'Envíos FULL (Cajas 30)', desc: 'Armado de paquetes para despacho a bodega MeLi', href: '/meli/full/envios', category: 'Mercado Libre', icon: IconTruck },
    { title: 'AMS Pedidos', desc: 'Operación central de pedidos y despachos', href: '/ams/pedidos', category: 'Operaciones', icon: IconTruck },
    { title: 'AMS Procesar', desc: 'Escaneo y empaquetado de pedidos activos', href: '/ams/pedidos-procesar', category: 'Operaciones', icon: IconTruck },
    { title: 'AMS Incidencias', desc: 'Reporte de problemas en productos o envíos', href: '/ams/incidencias', category: 'Operaciones', icon: IconAlert },
    { title: 'Estado del Sistema', desc: 'Salud de Laravel, APIs y servidor', href: '/sistema/estado', category: 'Sistema', icon: IconGear },
    { title: 'Colas y Tareas', desc: 'Supervisión de jobs en segundo plano', href: '/sistema/colas', category: 'Sistema', icon: IconGear },
    { title: 'Logs del Sistema', desc: 'Registro de errores y auditoría', href: '/sistema/logs', category: 'Sistema', icon: IconGear },
    { title: 'Acciones Rápidas', desc: 'Limpieza de caché, sincronizaciones forzadas', href: '/sistema/acciones', category: 'Sistema', icon: IconGear },
]

function CommandPalette({ open, onClose }) {
    const [query, setQuery] = useState('')
    const [selectedIndex, setSelectedIndex] = useState(0)
    const inputRef = useRef(null)

    useEffect(() => {
        if (open) {
            setQuery('')
            setSelectedIndex(0)
            setTimeout(() => inputRef.current?.focus(), 50)
        }
    }, [open])

    const filtered = query.trim()
        ? ALL_SEARCH_ITEMS.filter((item) =>
              item.title.toLowerCase().includes(query.toLowerCase()) ||
              item.desc.toLowerCase().includes(query.toLowerCase()) ||
              item.category.toLowerCase().includes(query.toLowerCase())
          )
        : ALL_SEARCH_ITEMS.slice(0, 8)

    useEffect(() => {
        setSelectedIndex(0)
    }, [query])

    const handleKeyDown = (e) => {
        if (e.key === 'ArrowDown') {
            e.preventDefault()
            setSelectedIndex((prev) => (prev + 1 < filtered.length ? prev + 1 : 0))
        } else if (e.key === 'ArrowUp') {
            e.preventDefault()
            setSelectedIndex((prev) => (prev - 1 >= 0 ? prev - 1 : filtered.length - 1))
        } else if (e.key === 'Enter' && filtered[selectedIndex]) {
            e.preventDefault()
            onClose()
            router.visit(filtered[selectedIndex].href)
        } else if (e.key === 'Escape') {
            onClose()
        }
    }

    if (!open) return null

    return (
        <div className="fixed inset-0 z-50 flex items-start justify-center p-4 pt-16 sm:pt-24" role="dialog" aria-modal="true">
            <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-sm transition-opacity" onClick={onClose} />

            <div className="relative w-full max-w-xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl transition-all dark:border-neutral-800 dark:bg-neutral-900">
                {/* Search Bar Input */}
                <div className="flex items-center gap-3 border-b border-slate-200 px-4 py-3.5 dark:border-neutral-800">
                    <IconSearch className="h-5 w-5 text-slate-400 dark:text-neutral-500" />
                    <input
                        ref={inputRef}
                        type="text"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        onKeyDown={handleKeyDown}
                        placeholder="Buscar módulo, producto, acción o ruta..."
                        className="w-full bg-transparent text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none dark:text-white dark:placeholder:text-neutral-500"
                    />
                    <kbd className="hidden rounded bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-500 sm:inline-block dark:bg-neutral-800 dark:text-neutral-400">
                        ESC
                    </kbd>
                </div>

                {/* Results list */}
                <div className="max-h-80 overflow-y-auto p-2">
                    {filtered.length === 0 ? (
                        <div className="px-4 py-8 text-center text-sm text-slate-500 dark:text-neutral-400">
                            No se encontraron resultados para &ldquo;<span className="font-semibold">{query}</span>&rdquo;
                        </div>
                    ) : (
                        <div className="space-y-1">
                            {filtered.map((item, index) => {
                                const isSelected = index === selectedIndex
                                const ItemIcon = item.icon || IconBox
                                return (
                                    <button
                                        key={item.href + index}
                                        type="button"
                                        onClick={() => {
                                            onClose()
                                            router.visit(item.href)
                                        }}
                                        onMouseEnter={() => setSelectedIndex(index)}
                                        className={`flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-left text-sm transition ${
                                            isSelected
                                                ? 'bg-indigo-50 text-indigo-900 dark:bg-indigo-500/15 dark:text-indigo-200'
                                                : 'text-slate-700 hover:bg-slate-50 dark:text-neutral-300 dark:hover:bg-neutral-800/60'
                                        }`}
                                    >
                                        <div className="flex items-center gap-3 min-w-0">
                                            <div
                                                className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ${
                                                    isSelected
                                                        ? 'bg-indigo-600 text-white dark:bg-indigo-500'
                                                        : 'bg-slate-100 text-slate-500 dark:bg-neutral-800 dark:text-neutral-400'
                                                }`}
                                            >
                                                <ItemIcon className="h-4 w-4" />
                                            </div>
                                            <div className="min-w-0">
                                                <p className="truncate font-semibold text-xs sm:text-sm">{item.title}</p>
                                                <p className="truncate text-[11px] text-slate-500 dark:text-neutral-400">{item.desc}</p>
                                            </div>
                                        </div>
                                        <span className="shrink-0 rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600 dark:bg-neutral-800 dark:text-neutral-400">
                                            {item.category}
                                        </span>
                                    </button>
                                )
                            })}
                        </div>
                    )}
                </div>

                {/* Footer hints */}
                <div className="flex items-center justify-between border-t border-slate-100 bg-slate-50/70 px-4 py-2 text-[11px] text-slate-500 dark:border-neutral-800 dark:bg-neutral-950/50 dark:text-neutral-400">
                    <span className="flex items-center gap-1.5">
                        <kbd className="rounded bg-white px-1.5 py-0.5 shadow-2xs border border-slate-200 dark:bg-neutral-800 dark:border-neutral-700">↑</kbd>
                        <kbd className="rounded bg-white px-1.5 py-0.5 shadow-2xs border border-slate-200 dark:bg-neutral-800 dark:border-neutral-700">↓</kbd>
                        para navegar
                    </span>
                    <span className="flex items-center gap-1.5">
                        <kbd className="rounded bg-white px-1.5 py-0.5 shadow-2xs border border-slate-200 dark:bg-neutral-800 dark:border-neutral-700">↵</kbd>
                        para abrir
                    </span>
                </div>
            </div>
        </div>
    )
}

/* =========================================================================
   USER PROFILE DRAWER / MENU
   ========================================================================= */
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
        return () => document.removeEventListener('mousedown', handleClickOutside)
    }, [])

    const toggleTheme = () => {
        const root = document.documentElement
        const isDark = root.classList.contains('dark')
        if (isDark) {
            root.classList.remove('dark')
            localStorage.setItem('theme', 'light')
        } else {
            root.classList.add('dark')
            localStorage.setItem('theme', 'dark')
        }
        setOpen(false)
    }

    return (
        <div className="relative" ref={menuRef}>
            <button
                type="button"
                onClick={() => setOpen((prev) => !prev)}
                className="group flex w-full items-center gap-3 rounded-2xl border border-slate-200/80 bg-white p-3 text-left shadow-2xs transition hover:border-slate-300 hover:shadow-xs dark:border-neutral-800 dark:bg-neutral-900 dark:hover:border-neutral-700"
            >
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 text-xs font-bold text-white shadow-xs">
                    {user?.name?.charAt(0)?.toUpperCase() ?? 'U'}
                </div>

                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-1.5">
                        <p className="truncate text-xs font-bold text-slate-900 dark:text-white">
                            {user?.name ?? 'Usuario'}
                        </p>
                        <span className="rounded bg-indigo-50 px-1.5 py-0.2 text-[9px] font-bold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300">
                            {user?.role === 'admin' ? 'ADMIN' : 'STAFF'}
                        </span>
                    </div>
                    <p className="truncate text-[11px] text-slate-500 dark:text-slate-400">
                        {user?.email ?? 'Sesión activa'}
                    </p>
                </div>

                <svg
                    className={`h-4 w-4 shrink-0 text-slate-400 transition-transform ${open ? 'rotate-180' : ''}`}
                    viewBox="0 0 20 20"
                    fill="currentColor"
                >
                    <path
                        fillRule="evenodd"
                        d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
                        clipRule="evenodd"
                    />
                </svg>
            </button>

            {open && (
                <div className="absolute bottom-full left-0 z-50 mb-2 w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="border-b border-slate-100 p-3 dark:border-neutral-800">
                        <p className="text-xs font-semibold text-slate-900 dark:text-white">
                            {user?.name}
                        </p>
                        <p className="truncate text-[11px] text-slate-500 dark:text-neutral-400">
                            {user?.email}
                        </p>
                    </div>

                    <Link
                        href="/settings/profile"
                        className="flex items-center gap-2 px-3 py-2.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-neutral-800"
                        onClick={() => {
                            setOpen(false)
                            onNavigate?.()
                        }}
                    >
                        <IconGear className="h-4 w-4 text-slate-400" />
                        Configuración de cuenta
                    </Link>

                    <button
                        type="button"
                        onClick={toggleTheme}
                        className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-xs font-medium text-slate-700 transition hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-neutral-800"
                    >
                        <IconMoon className="h-4 w-4 text-slate-400" />
                        Alternar modo oscuro / claro
                    </button>

                    <div className="border-t border-slate-100 dark:border-neutral-800">
                        <Link
                            href="/logout"
                            method="post"
                            as="button"
                            className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-xs font-semibold text-rose-600 transition hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10"
                            onClick={() => {
                                setOpen(false)
                                onNavigate?.()
                            }}
                        >
                            <svg className="h-4 w-4 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                            </svg>
                            Cerrar sesión
                        </Link>
                    </div>
                </div>
            )}
        </div>
    )
}

/* =========================================================================
   SIDEBAR BRAND / LOGO
   ========================================================================= */
function SidebarBrand() {
    return (
        <div className="mb-4">
            <Link
                href="/dashboard"
                className="group flex items-center justify-between rounded-2xl border border-slate-200/80 bg-gradient-to-b from-white to-slate-50/70 p-3 shadow-2xs transition hover:border-slate-300 hover:shadow-xs dark:border-neutral-800 dark:from-neutral-900 dark:to-neutral-950"
            >
                <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white shadow-xs ring-1 ring-slate-900/5 dark:bg-neutral-800 dark:ring-white/10">
                        <img src="/logo-llantas.png" alt="Llantas" className="h-7 w-7 object-contain" />
                    </div>
                    <div>
                        <div className="flex items-center gap-1.5">
                            <span className="text-sm font-black tracking-tight text-slate-900 dark:text-white">
                                Llantas ERP
                            </span>
                            <span className="rounded bg-indigo-600 px-1.5 py-0.2 text-[9px] font-bold text-white shadow-2xs">
                                PRO
                            </span>
                        </div>
                        <p className="text-[11px] font-medium text-slate-500 dark:text-neutral-400">
                            Inventario & POS
                        </p>
                    </div>
                </div>

                <span className="flex h-2 w-2 rounded-full bg-emerald-500 ring-4 ring-emerald-500/20" title="En línea" />
            </Link>

            {/* Quick POS Access Card Button */}
            <Link
                href="/pos"
                className="mt-3 flex items-center justify-between rounded-xl bg-gradient-to-r from-indigo-600 via-indigo-600 to-indigo-700 px-3.5 py-2.5 text-xs font-semibold text-white shadow-xs transition-all duration-150 hover:brightness-105 active:scale-[0.98]"
            >
                <span className="flex items-center gap-2">
                    <IconPOS className="h-4 w-4" />
                    <span>Punto de Venta</span>
                </span>
                <span className="rounded bg-white/20 px-1.5 py-0.5 text-[10px] font-bold tracking-wider uppercase">
                    ⚡ POS
                </span>
            </Link>
        </div>
    )
}

/* =========================================================================
   SIDEBAR NAVIGATION
   ========================================================================= */
function SidebarNav({ currentPath, role, onNavigate, pendingQuestions = 0 }) {
    const isAdmin = role === 'admin'

    return (
        <nav className="space-y-5">
            {/* GENERAL */}
            <div>
                <p className="mb-1.5 px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-neutral-500">
                    General
                </p>
                <div className="space-y-0.5">
                    <NavItem
                        href="/dashboard"
                        icon={IconDashboard}
                        active={currentPath === '/dashboard'}
                        onNavigate={onNavigate}
                    >
                        Dashboard
                    </NavItem>
                    <NavItem
                        href="/pos"
                        icon={IconPOS}
                        active={currentPath === '/pos'}
                        onNavigate={onNavigate}
                    >
                        Punto de Venta (POS)
                    </NavItem>
                    <NavItem
                        href="/pos/clientes"
                        icon={IconUsers}
                        active={currentPath.startsWith('/pos/clientes') || currentPath.startsWith('/clientes')}
                        onNavigate={onNavigate}
                    >
                        Clientes y Créditos
                    </NavItem>
                </div>
            </div>

            {/* INVENTARIO & ALMACÉN */}
            {isAdmin && (
                <div>
                    <p className="mb-1.5 px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-neutral-500">
                        Almacén & Catálogo
                    </p>
                    <div className="space-y-0.5">
                        <NavItem
                            href="/almacen/productos"
                            icon={IconBox}
                            active={currentPath.startsWith('/almacen/productos')}
                            onNavigate={onNavigate}
                        >
                            Catálogo maestro
                        </NavItem>
                        <NavItem
                            href="/almacen/ubicaciones"
                            icon={IconLocation}
                            active={currentPath.startsWith('/almacen/ubicaciones')}
                            onNavigate={onNavigate}
                        >
                            Ubicaciones
                        </NavItem>
                        <NavItem
                            href="/almacen/movimientos"
                            icon={IconArrows}
                            active={currentPath.startsWith('/almacen/movimientos')}
                            onNavigate={onNavigate}
                        >
                            Movimientos
                        </NavItem>
                        <NavItem
                            href="/almacen/reservas"
                            icon={IconLock}
                            active={currentPath.startsWith('/almacen/reservas')}
                            onNavigate={onNavigate}
                        >
                            Reservas
                        </NavItem>
                        <NavItem
                            href="/almacen/kits"
                            icon={IconPuzzle}
                            active={currentPath.startsWith('/almacen/kits')}
                            onNavigate={onNavigate}
                        >
                            Kits y Ensambles
                        </NavItem>
                        <NavItem
                            href="/reabastecimiento/pronostico"
                            icon={IconChart}
                            active={currentPath.startsWith('/reabastecimiento')}
                            onNavigate={onNavigate}
                        >
                            Pronóstico Compras
                        </NavItem>
                        <NavItem
                            href="/compras/ordenes"
                            icon={IconShoppingBag}
                            active={currentPath.startsWith('/compras/ordenes')}
                            onNavigate={onNavigate}
                        >
                            Órdenes de Compra
                        </NavItem>
                        <NavItem
                            href="/almacen/canales/importar"
                            icon={IconTag}
                            active={
                                currentPath.startsWith('/almacen/canales/importar') ||
                                currentPath.startsWith('/almacen/canales/mercado-libre/importar')
                            }
                            onNavigate={onNavigate}
                        >
                            Vincular publicaciones
                        </NavItem>
                        <NavItem
                            href="/almacen/canales/mercado-libre/stock"
                            icon={IconMeli}
                            active={currentPath.startsWith('/almacen/canales/mercado-libre/stock')}
                            onNavigate={onNavigate}
                        >
                            Stock ML
                        </NavItem>
                        <NavItem
                            href="/ml/compare"
                            icon={IconChart}
                            active={currentPath.startsWith('/ml/compare')}
                            onNavigate={onNavigate}
                        >
                            Comparar ML
                        </NavItem>
                        <NavItem
                            href="/llantas"
                            icon={IconBox}
                            active={
                                currentPath === '/llantas' ||
                                (currentPath.startsWith('/llantas/') && !currentPath.startsWith('/llantas/comparador'))
                            }
                            onNavigate={onNavigate}
                        >
                            Llantas individuales
                        </NavItem>
                        <NavItem
                            href="/llantas/comparador"
                            icon={IconChart}
                            active={currentPath.startsWith('/llantas/comparador')}
                            onNavigate={onNavigate}
                        >
                            Comparador llantas
                        </NavItem>
                        <NavItem
                            href="/productos"
                            icon={IconPuzzle}
                            active={currentPath === '/productos' || currentPath.startsWith('/productos/')}
                            onNavigate={onNavigate}
                        >
                            Llantas compuestas
                        </NavItem>
                        <NavItem
                            href="/price-rules"
                            icon={IconTag}
                            active={currentPath.startsWith('/price-rules')}
                            onNavigate={onNavigate}
                        >
                            Fórmulas de precios
                        </NavItem>
                        <NavItem
                            href="/syscom-ml"
                            icon={IconTag}
                            active={currentPath.startsWith('/syscom-ml')}
                            onNavigate={onNavigate}
                        >
                            SYSCOM → ML
                        </NavItem>
                        <NavItem
                            href="/importar-excel"
                            icon={IconBox}
                            active={currentPath.startsWith('/importar-excel')}
                            onNavigate={onNavigate}
                        >
                            Importar Excel
                        </NavItem>
                    </div>
                </div>
            )}

            {/* MERCADO LIBRE */}
            <div>
                <p className="mb-1.5 px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-neutral-500">
                    Mercado Libre
                </p>
                <div className="space-y-0.5">
                    <NavItem
                        href="/mercado-libre/etiquetas"
                        icon={IconTag}
                        active={currentPath.startsWith('/mercado-libre/etiquetas')}
                        onNavigate={onNavigate}
                    >
                        Etiquetas ML
                    </NavItem>
                    <NavItem
                        href="/meli/preguntas"
                        icon={IconMessage}
                        active={currentPath.startsWith('/meli/preguntas')}
                        badge={pendingQuestions > 0 ? (pendingQuestions > 99 ? '99+' : pendingQuestions) : null}
                        onNavigate={onNavigate}
                    >
                        Preguntas de clientes
                    </NavItem>
                    <NavItem
                        href="/meli/mensajeria"
                        icon={IconMessage}
                        active={currentPath.startsWith('/meli/mensajeria')}
                        onNavigate={onNavigate}
                    >
                        Mensajería posventa
                    </NavItem>
                    <NavItem
                        href="/meli-claims"
                        icon={IconAlert}
                        active={currentPath.startsWith('/meli-claims')}
                        onNavigate={onNavigate}
                    >
                        Reclamos
                    </NavItem>
                    <NavItem
                        href="/meli/publicaciones"
                        icon={IconMeli}
                        active={currentPath.startsWith('/meli/publicaciones')}
                        onNavigate={onNavigate}
                    >
                        Publicaciones ML
                    </NavItem>

                    {isAdmin && (
                        <>
                            <NavItem
                                href="/meli-price-manager"
                                icon={IconTag}
                                active={currentPath === '/meli-price-manager'}
                                onNavigate={onNavigate}
                            >
                                Meli Price Manager
                            </NavItem>
                            <NavItem
                                href="/meli-price-manager/brands"
                                icon={IconTag}
                                active={currentPath.startsWith('/meli-price-manager/brands')}
                                onNavigate={onNavigate}
                            >
                                Marcas y alias
                            </NavItem>
                            <NavItem
                                href="/meli-price-manager/scheduled-discounts"
                                icon={IconTag}
                                active={currentPath.startsWith('/meli-price-manager/scheduled-discounts')}
                                onNavigate={onNavigate}
                            >
                                Promociones programadas
                            </NavItem>
                            <NavItem
                                href="/meli-price-manager/uncategorized"
                                icon={IconAlert}
                                active={currentPath.startsWith('/meli-price-manager/uncategorized')}
                                onNavigate={onNavigate}
                            >
                                Por clasificar
                            </NavItem>
                        </>
                    )}

                    <NavItem
                        href="/meli/full"
                        icon={IconBox}
                        active={currentPath === '/meli/full'}
                        onNavigate={onNavigate}
                    >
                        Inventario FULL
                    </NavItem>
                    <NavItem
                        href="/meli/full/envios"
                        icon={IconTruck}
                        active={currentPath.startsWith('/meli/full/envios')}
                        onNavigate={onNavigate}
                    >
                        📦 Envíos FULL (Cajas 30)
                    </NavItem>
                </div>
            </div>

            {/* OPERACIONES AMS */}
            <div>
                <p className="mb-1.5 px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-neutral-500">
                    Operaciones AMS
                </p>
                <div className="space-y-0.5">
                    <NavItem
                        href="/ams/pedidos"
                        icon={IconTruck}
                        active={currentPath.startsWith('/ams/pedidos')}
                        onNavigate={onNavigate}
                    >
                        AMS Pedidos
                    </NavItem>
                    <NavItem
                        href="/ams/pedidos-procesar"
                        icon={IconTruck}
                        active={currentPath.startsWith('/ams/pedidos-procesar')}
                        onNavigate={onNavigate}
                    >
                        AMS Procesar
                    </NavItem>
                    <NavItem
                        href="/ams/pedidos-secundaria"
                        icon={IconTruck}
                        active={currentPath.startsWith('/ams/pedidos-secundaria')}
                        onNavigate={onNavigate}
                    >
                        AMS Secundaria
                    </NavItem>
                    <NavItem
                        href="/ams/pedidos-manana"
                        icon={IconTruck}
                        active={currentPath.startsWith('/ams/pedidos-manana')}
                        onNavigate={onNavigate}
                    >
                        AMS Mañana
                    </NavItem>
                    <NavItem
                        href="/ams/incidencias"
                        icon={IconAlert}
                        active={currentPath.startsWith('/ams/incidencias')}
                        onNavigate={onNavigate}
                    >
                        ⚠️ Incidencias
                    </NavItem>

                    {!isAdmin && (
                        <>
                            <NavItem
                                href="/almacen/canales"
                                icon={IconTag}
                                active={currentPath.startsWith('/almacen/canales')}
                                onNavigate={onNavigate}
                            >
                                Enlaces de canales
                            </NavItem>
                            <NavItem
                                href="/almacen/canales/importar"
                                icon={IconTag}
                                active={
                                    currentPath.startsWith('/almacen/canales/importar') ||
                                    currentPath.startsWith('/almacen/canales/mercado-libre/importar')
                                }
                                onNavigate={onNavigate}
                            >
                                Vincular publicaciones
                            </NavItem>
                            <NavItem
                                href="/almacen/canales/mercado-libre/stock"
                                icon={IconMeli}
                                active={currentPath.startsWith('/almacen/canales/mercado-libre/stock')}
                                onNavigate={onNavigate}
                            >
                                Stock ML
                            </NavItem>
                            <NavItem
                                href="/reabastecimiento/pronostico"
                                icon={IconChart}
                                active={currentPath.startsWith('/reabastecimiento')}
                                onNavigate={onNavigate}
                            >
                                Pronóstico compras
                            </NavItem>
                            <NavItem
                                href="/compras/ordenes"
                                icon={IconShoppingBag}
                                active={currentPath.startsWith('/compras/ordenes')}
                                onNavigate={onNavigate}
                            >
                                Órdenes de compra
                            </NavItem>
                        </>
                    )}
                </div>
            </div>

            {/* SISTEMA (ADMIN) */}
            {isAdmin && (
                <div>
                    <p className="mb-1.5 px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400 dark:text-neutral-500">
                        Sistema
                    </p>
                    <div className="space-y-0.5">
                        <NavItem
                            href="/sistema/estado"
                            icon={IconGear}
                            active={currentPath.startsWith('/sistema/estado')}
                            onNavigate={onNavigate}
                        >
                            Estado del sistema
                        </NavItem>
                        <NavItem
                            href="/sistema/colas"
                            icon={IconGear}
                            active={currentPath.startsWith('/sistema/colas')}
                            onNavigate={onNavigate}
                        >
                            Colas y Jobs
                        </NavItem>
                        <NavItem
                            href="/sistema/logs"
                            icon={IconGear}
                            active={currentPath.startsWith('/sistema/logs')}
                            onNavigate={onNavigate}
                        >
                            Logs
                        </NavItem>
                        <NavItem
                            href="/sistema/acciones"
                            icon={IconGear}
                            active={currentPath.startsWith('/sistema/acciones')}
                            onNavigate={onNavigate}
                        >
                            Acciones rápidas
                        </NavItem>
                    </div>
                </div>
            )}
        </nav>
    )
}

/* =========================================================================
   MAIN APP SHELL EXPORT
   ========================================================================= */
export default function AppShell({ title = 'Dashboard', children }) {
    const { auth, meli_questions_pending = 0 } = usePage().props
    const [mobileNavOpen, setMobileNavOpen] = useState(false)
    const [cmdOpen, setCmdOpen] = useState(false)
    const [isDark, setIsDark] = useState(false)

    const currentPath = typeof window !== 'undefined' ? window.location.pathname : ''
    const closeMobile = () => setMobileNavOpen(false)

    // Sync dark mode state from DOM on mount
    useEffect(() => {
        setIsDark(document.documentElement.classList.contains('dark'))
    }, [])

    // Keyboard shortcut for Cmd+K / Ctrl+K
    useEffect(() => {
        const handleKeyDown = (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault()
                setCmdOpen((prev) => !prev)
            }
        }
        window.addEventListener('keydown', handleKeyDown)
        return () => window.removeEventListener('keydown', handleKeyDown)
    }, [])

    // Prevent body scroll when mobile drawer is open
    useEffect(() => {
        document.body.style.overflow = mobileNavOpen ? 'hidden' : ''
        return () => {
            document.body.style.overflow = ''
        }
    }, [mobileNavOpen])

    const toggleTheme = () => {
        const root = document.documentElement
        const currentIsDark = root.classList.contains('dark')
        if (currentIsDark) {
            root.classList.remove('dark')
            localStorage.setItem('theme', 'light')
            setIsDark(false)
        } else {
            root.classList.add('dark')
            localStorage.setItem('theme', 'dark')
            setIsDark(true)
        }
    }

    return (
        <>
            <Head title={title} />
            <FlashToasts />
            <CommandPalette open={cmdOpen} onClose={() => setCmdOpen(false)} />

            <div className="min-h-screen bg-slate-50/70 font-sans text-slate-900 antialiased dark:bg-neutral-950 dark:text-neutral-100">
                <div className="flex min-h-screen">
                    {/* MOBILE DRAWER */}
                    {mobileNavOpen && (
                        <div className="fixed inset-0 z-50 lg:hidden" aria-modal="true" role="dialog">
                            <button
                                type="button"
                                className="absolute inset-0 bg-black/60 backdrop-blur-xs transition-opacity"
                                aria-label="Cerrar menú"
                                onClick={closeMobile}
                            />
                            <div className="absolute left-0 top-0 flex h-full w-[min(19rem,88vw)] flex-col border-r border-slate-200 bg-white p-4 shadow-2xl dark:border-neutral-800 dark:bg-neutral-900">
                                <div className="mb-2 flex items-center justify-between">
                                    <span className="text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-neutral-500">
                                        Navegación
                                    </span>
                                    <button
                                        type="button"
                                        onClick={closeMobile}
                                        className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 dark:hover:bg-neutral-800"
                                        aria-label="Cerrar"
                                    >
                                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>

                                <SidebarBrand />

                                <div className="custom-scrollbar min-h-0 flex-1 overflow-y-auto pr-1">
                                    <SidebarNav
                                        currentPath={currentPath}
                                        role={auth?.user?.role}
                                        onNavigate={closeMobile}
                                        pendingQuestions={meli_questions_pending}
                                    />
                                </div>

                                <div className="mt-4 pt-3 border-t border-slate-100 dark:border-neutral-800">
                                    <SidebarUserMenu user={auth?.user} onNavigate={closeMobile} />
                                </div>
                            </div>
                        </div>
                    )}

                    {/* DESKTOP SIDEBAR */}
                    <aside className="hidden w-72 shrink-0 border-r border-slate-200/80 bg-white lg:block dark:border-neutral-800/80 dark:bg-neutral-900">
                        <div className="flex h-screen sticky top-0 flex-col p-4">
                            <SidebarBrand />

                            <div className="custom-scrollbar min-h-0 flex-1 overflow-y-auto pr-1">
                                <SidebarNav
                                    currentPath={currentPath}
                                    role={auth?.user?.role}
                                    pendingQuestions={meli_questions_pending}
                                />
                            </div>

                            <div className="mt-4 pt-3 border-t border-slate-100 dark:border-neutral-800">
                                <SidebarUserMenu user={auth?.user} />
                            </div>
                        </div>
                    </aside>

                    {/* MAIN CONTENT AREA */}
                    <main className="min-w-0 flex-1">
                        {/* TOPBAR (Sticky Glassmorphism) */}
                        <header className="sticky top-0 z-30 flex h-16 items-center justify-between border-b border-slate-200/80 bg-white/85 px-4 backdrop-blur-md sm:px-6 dark:border-neutral-800/80 dark:bg-neutral-900/85">
                            {/* Left: Mobile trigger & breadcrumb / page title */}
                            <div className="flex items-center gap-3 min-w-0">
                                <button
                                    type="button"
                                    className="shrink-0 rounded-xl border border-slate-200 p-2 text-slate-700 hover:bg-slate-50 lg:hidden dark:border-neutral-700 dark:text-slate-200 dark:hover:bg-neutral-800"
                                    onClick={() => setMobileNavOpen(true)}
                                    aria-label="Abrir menú"
                                >
                                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 12h16M4 18h16" />
                                    </svg>
                                </button>

                                <div className="min-w-0">
                                    <div className="flex items-center gap-2">
                                        <span className="hidden text-xs font-semibold text-slate-400 sm:inline dark:text-neutral-500">
                                            Inicio
                                        </span>
                                        <span className="hidden text-xs text-slate-300 sm:inline dark:text-neutral-600">/</span>
                                        <h1 className="truncate text-base font-bold tracking-tight text-slate-900 dark:text-white sm:text-lg">
                                            {title}
                                        </h1>
                                    </div>
                                </div>
                            </div>

                            {/* Center / Right: Quick Command Search + Fast POS + Theme Toggle */}
                            <div className="flex items-center gap-2.5">
                                {/* Command Search Bar Trigger Button */}
                                <button
                                    type="button"
                                    onClick={() => setCmdOpen(true)}
                                    className="hidden items-center gap-2.5 rounded-xl border border-slate-200 bg-slate-50/80 px-3 py-1.5 text-xs text-slate-500 transition hover:border-slate-300 hover:bg-white md:flex dark:border-neutral-800 dark:bg-neutral-950/60 dark:text-neutral-400 dark:hover:border-neutral-700 dark:hover:bg-neutral-900"
                                >
                                    <IconSearch className="h-3.5 w-3.5 text-slate-400" />
                                    <span>Buscar módulos o acciones...</span>
                                    <kbd className="rounded bg-white px-1.5 py-0.5 text-[10px] font-semibold text-slate-600 shadow-2xs border border-slate-200 dark:bg-neutral-800 dark:border-neutral-700 dark:text-neutral-300">
                                        Ctrl K
                                    </kbd>
                                </button>

                                {/* POS Direct Shortcut */}
                                <Link
                                    href="/pos"
                                    className="hidden items-center gap-1.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-3 py-1.5 text-xs font-bold text-white shadow-xs transition hover:brightness-105 active:scale-95 sm:inline-flex"
                                >
                                    <span>⚡ Nueva Venta POS</span>
                                </Link>

                                {/* Theme Switcher (Dark/Light) */}
                                <button
                                    type="button"
                                    onClick={toggleTheme}
                                    className="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-2xs transition hover:bg-slate-50 hover:text-slate-900 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:hover:text-white"
                                    title={isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro'}
                                >
                                    {isDark ? <IconSun className="h-4 w-4 text-amber-400" /> : <IconMoon className="h-4 w-4 text-indigo-600" />}
                                </button>

                                {/* Mobile search button */}
                                <button
                                    type="button"
                                    onClick={() => setCmdOpen(true)}
                                    className="flex h-9 w-9 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-600 shadow-2xs transition hover:bg-slate-50 md:hidden dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-300"
                                    title="Buscar"
                                >
                                    <IconSearch className="h-4 w-4" />
                                </button>
                            </div>
                        </header>

                        {/* Page Body */}
                        <div className="p-4 sm:p-6 lg:p-8">
                            {children}
                        </div>
                    </main>
                </div>
            </div>
        </>
    )
}
