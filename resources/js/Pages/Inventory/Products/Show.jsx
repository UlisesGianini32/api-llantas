import { useState } from 'react'
import AppShell from '@/Components/layout/AppShell'
import { formatDateTime, movementTypeLabel } from '@/lib/inventoryPresentation'
import { Head, Link, router } from '@inertiajs/react'

function money(value) {
    if (value === null || value === undefined || value === '') return '—'
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: 2 }).format(Number(value))
}

export default function InventoryProductShow({
    product,
    physicalStock = 0,
    reservedStock = 0,
    availableStock = 0,
    stockByLocation = [],
    movements = [],
    reservations = [],
    kitComponents = [],
    channelLinks = [],
}) {
    const isKit = product.product_type === 'KIT'

    // Modal state for linking publication
    const [showModal, setShowModal] = useState(false)
    const [searchQuery, setSearchQuery] = useState('')
    const [searching, setSearching] = useState(false)
    const [searchError, setSearchError] = useState('')
    const [searchResult, setSearchResult] = useState(null)
    const [selectedItem, setSelectedItem] = useState(null)
    const [isFullLogistic, setIsFullLogistic] = useState(false)
    const [forceReassign, setForceReassign] = useState(false)
    const [connecting, setConnecting] = useState(false)

    const openLinkModal = () => {
        setSearchQuery('')
        setSearchError('')
        setSearchResult(null)
        setSelectedItem(null)
        setIsFullLogistic(false)
        setForceReassign(false)
        setShowModal(true)
    }

    const handleSearch = async (e) => {
        if (e) e.preventDefault()
        if (!searchQuery.trim()) return

        setSearching(true)
        setSearchError('')
        setSearchResult(null)
        setSelectedItem(null)

        try {
            const res = await fetch(`/almacen/canales/buscar-publicacion-meli?query=${encodeURIComponent(searchQuery.trim())}&current_product_id=${product.id}`)
            const data = await res.json()

            if (!res.ok || !data.success) {
                setSearchError(data.message || 'No se encontró la publicación con ese enlace o código.')
                return
            }

            if (data.type === 'single') {
                setSearchResult(data)
                setSelectedItem(data.item)
                setIsFullLogistic(Boolean(data.item.is_full))
                setForceReassign(false)
            } else if (data.type === 'list') {
                setSearchResult(data)
                if (data.items.length === 0) {
                    setSearchError('No se encontraron publicaciones con ese término de búsqueda.')
                }
            }
        } catch (err) {
            setSearchError('Error de red al consultar la publicación.')
        } finally {
            setSearching(false)
        }
    }

    const selectFromList = async (it) => {
        setSearchQuery(it.mlm)
        setSearching(true)
        setSearchError('')
        try {
            const res = await fetch(`/almacen/canales/buscar-publicacion-meli?query=${encodeURIComponent(it.mlm)}&current_product_id=${product.id}`)
            const data = await res.json()
            if (data.success && data.type === 'single') {
                setSearchResult(data)
                setSelectedItem(data.item)
                setIsFullLogistic(Boolean(data.item.is_full))
                setForceReassign(false)
            }
        } catch (err) {
            setSearchError('Error al cargar detalle de la publicación.')
        } finally {
            setSearching(false)
        }
    }

    const handleConnect = () => {
        if (!selectedItem) return
        setConnecting(true)

        router.post(`/almacen/productos/${product.id}/vincular-publicacion`, {
            mlm: selectedItem.mlm,
            is_full: isFullLogistic,
            force_reassign: forceReassign,
            title: selectedItem.title,
            thumbnail: selectedItem.thumbnail,
            permalink: selectedItem.permalink,
            remote_price: selectedItem.price,
            account_key: selectedItem.account_key,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setShowModal(false)
                setSearchQuery('')
                setSearchResult(null)
                setSelectedItem(null)
            },
            onError: (errs) => {
                setSearchError(errs.error || errs.mlm || 'No fue posible vincular la publicación.')
            },
            onFinish: () => setConnecting(false),
        })
    }

    const handleUnlink = (link) => {
        const mlmLabel = link.external_listing_id || 'esta publicación'
        if (!confirm(`¿Estás seguro de desvincular ${mlmLabel} de este producto?`)) {
            return
        }
        router.delete(`/almacen/productos/${product.id}/desvincular-publicacion/${link.id}`, {
            preserveScroll: true,
        })
    }

    const handleToggleSync = (link) => {
        router.patch(`/almacen/productos/${product.id}/publicaciones/${link.id}`, {
            stock_sync_enabled: !link.stock_sync_enabled,
        }, { preserveScroll: true })
    }

    const handleToggleIsFull = (link) => {
        const nextIsFull = !link.is_full
        router.patch(`/almacen/productos/${product.id}/publicaciones/${link.id}`, {
            is_full: nextIsFull,
            stock_sync_enabled: nextIsFull ? false : link.stock_sync_enabled,
        }, { preserveScroll: true })
    }

    return (
        <AppShell title="Producto de almacén">
            <Head title={product.name} />
            <div className="mx-auto max-w-4xl space-y-6 pb-12">
                {/* Header */}
                <div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                    <div>
                        <Link href="/almacen/productos" className="text-sm font-semibold text-indigo-600 hover:underline dark:text-indigo-300">
                            ← Volver al catálogo
                        </Link>
                        <h1 className="mt-2 text-3xl font-extrabold text-slate-900 dark:text-white">{product.name}</h1>
                        <p className="text-xs font-mono text-slate-500">SKU: {product.sku} · {product.brand || 'Sin marca'}</p>
                    </div>
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={openLinkModal}
                            className="inline-flex items-center gap-1.5 rounded-xl bg-amber-500 px-4 py-2.5 text-center font-bold text-white shadow hover:bg-amber-600 transition"
                        >
                            <span className="text-lg leading-none">+</span> Conectar MeLi
                        </button>
                        <Link href={`/almacen/productos/${product.id}/editar`} className="rounded-xl bg-indigo-600 px-4 py-2.5 text-center font-semibold text-white shadow hover:bg-indigo-700 transition">
                            Editar
                        </Link>
                    </div>
                </div>

                {/* Ficha técnica y stock */}
                <div className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-6 sm:grid-cols-2 dark:border-neutral-800 dark:bg-neutral-900">
                    {[
                        ['SKU', product.sku],
                        ['Tipo', product.product_type || 'SIMPLE'],
                        ['Código de barras', product.barcode || '—'],
                        ['Segundo código de barras', product.barcode_secondary || '—'],
                        ['Ubicación principal (Picking)', isKit ? '📦 Derivada de componentes' : (product.primary_location?.code || 'Sin ubicación')],
                        ['Ubicación secundaria (Reserva)', isKit ? '📦 Derivada de componentes' : (product.secondary_location?.code || 'Sin reserva')],
                        ['Notas de reserva', isKit ? 'Ver componentes abajo' : (product.reserve_notes || '—')],
                        [isKit ? 'Stock derivado / físico posible' : 'Stock físico', Number(physicalStock || 0)],
                        ['Reservado', Number(reservedStock || 0)],
                        ['Disponible', Number(availableStock || 0)],
                        ['Costo', money(product.cost)],
                        ['Precio Mercado Libre', money(product.price_mercado_libre)],
                        ['Precio Amazon', money(product.price_amazon)],
                        ['Precio estilista / Shopify', money(product.price_stylist)],
                        ['Precio público', money(product.price_public)],
                        ['Estado', product.is_active ? 'Activo' : 'Inactivo']
                    ].map(([label, value]) => (
                        <div key={label}>
                            <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</dt>
                            <dd className="mt-1 text-slate-900 font-medium dark:text-white">{value}</dd>
                        </div>
                    ))}
                    <div className="sm:col-span-2">
                        <dt className="text-xs font-semibold uppercase tracking-wide text-slate-500">Descripción</dt>
                        <dd className="mt-1 whitespace-pre-wrap text-slate-700 dark:text-slate-300">{product.description || '—'}</dd>
                    </div>
                </div>

                {/* Componentes de Kit si aplica */}
                {isKit && (
                    <section className="rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4 font-bold dark:border-neutral-800">
                            <span>Componentes del kit y ubicaciones</span>
                            <Link href={`/almacen/kits/${product.id}/editar-componentes`} className="text-sm font-semibold text-indigo-600">
                                Configurar
                            </Link>
                        </div>
                        <div className="divide-y divide-slate-100 dark:divide-neutral-800">
                            {kitComponents.length === 0 ? (
                                <p className="px-5 py-5 text-sm text-amber-700">Kit sin componentes configurados.</p>
                            ) : (
                                kitComponents.map((row) => {
                                    const comp = row.component || {}
                                    const pickCode = row.primary_location_code || comp.primary_location?.code || comp.primaryLocation?.code
                                    const pickAisle = row.primary_location_aisle || comp.primary_location?.amazon_aisle || comp.primaryLocation?.amazon_aisle
                                    const resCode = row.secondary_location_code || comp.secondary_location?.code || comp.secondaryLocation?.code
                                    const resAisle = row.secondary_location_aisle || comp.secondary_location?.amazon_aisle || comp.secondaryLocation?.amazon_aisle
                                    const notes = row.reserve_notes || comp.reserve_notes
                                    return (
                                        <div key={row.id || comp.id} className="p-4 space-y-1.5 text-sm">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <div>
                                                    <span className="font-semibold text-slate-900 dark:text-white">{comp.name}</span>{' '}
                                                    <span className="font-mono text-xs text-slate-500">({comp.sku})</span>
                                                </div>
                                                <div className="flex items-center gap-3 text-xs">
                                                    <span>Requiere: <strong>{row.quantity} pza</strong></span>
                                                    <span>Físico: <strong>{row.physical_stock}</strong></span>
                                                    <strong className="text-emerald-700 dark:text-emerald-400">Disponible: {row.available_stock}</strong>
                                                </div>
                                            </div>
                                            <div className="flex flex-wrap items-center gap-2 text-xs">
                                                <span className={`inline-flex items-center rounded-md px-2 py-0.5 font-bold ${pickCode ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-slate-100 text-slate-500'}`}>
                                                    📍 Picking: {pickCode || 'Sin ubicación'}{pickAisle ? ` (P. ${pickAisle})` : ''}
                                                </span>
                                                {resCode && (
                                                    <span className="inline-flex items-center rounded-md bg-amber-100 px-2 py-0.5 font-bold text-amber-800 dark:bg-amber-950/60 dark:text-amber-300">
                                                        📦 Reserva: {resCode}{resAisle ? ` (P. ${resAisle})` : ''}
                                                    </span>
                                                )}
                                                {notes && <span className="text-slate-500 italic">({notes})</span>}
                                            </div>
                                        </div>
                                    )
                                })
                            )}
                        </div>
                    </section>
                )}

                {/* SECCIÓN PRINCIPAL: PUBLICACIONES Y CANALES CONECTADOS */}
                <section className="rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900 overflow-hidden">
                    <div className="flex flex-col sm:flex-row sm:items-center justify-between border-b border-slate-200 px-6 py-4 gap-2 dark:border-neutral-800">
                        <div>
                            <div className="flex items-center gap-2">
                                <h2 className="text-lg font-bold text-slate-900 dark:text-white">Publicaciones Vinculadas</h2>
                                <span className="rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-bold text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                    {channelLinks.length}
                                </span>
                            </div>
                            <p className="text-xs text-slate-500 mt-0.5">
                                Publicaciones en Mercado Libre conectadas a este producto (FULL, Colecta, o publicaciones repetidas).
                            </p>
                        </div>
                        <div className="flex items-center gap-3">
                            <button
                                type="button"
                                onClick={openLinkModal}
                                className="inline-flex items-center gap-1.5 rounded-xl bg-amber-500 px-3.5 py-2 text-xs font-bold text-white shadow-sm hover:bg-amber-600 transition"
                            >
                                <span className="text-base leading-none">+</span> Conectar Publicación
                            </button>
                            <Link href="/almacen/canales" className="text-xs font-semibold text-indigo-600 hover:underline">
                                Ver todos →
                            </Link>
                        </div>
                    </div>

                    <div className="divide-y divide-slate-100 dark:divide-neutral-800">
                        {channelLinks.length === 0 ? (
                            <div className="px-6 py-10 text-center">
                                <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-950/40">
                                    🔗
                                </div>
                                <h3 className="mt-3 text-sm font-bold text-slate-900 dark:text-white">Sin publicaciones vinculadas</h3>
                                <p className="mt-1 text-xs text-slate-500 max-w-sm mx-auto">
                                    Conecta este producto a sus publicaciones de Mercado Libre (Envío Propio o FULL) para sincronizar stock y armar cajas de 30 kg automáticamente.
                                </p>
                                <button
                                    type="button"
                                    onClick={openLinkModal}
                                    className="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white shadow hover:bg-indigo-700 transition"
                                >
                                    + Conectar mi primera publicación
                                </button>
                            </div>
                        ) : (
                            channelLinks.map((link) => {
                                const isMeli = link.channel === 'mercado_libre'
                                const isFull = link.is_full

                                return (
                                    <div key={link.id} className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-5 hover:bg-slate-50/50 dark:hover:bg-neutral-800/40 transition">
                                        {/* Thumbnail & Title Info */}
                                        <div className="flex items-start gap-4">
                                            {link.thumbnail ? (
                                                <img
                                                    src={link.thumbnail}
                                                    alt={link.title || 'Publicación'}
                                                    className="h-16 w-16 flex-shrink-0 rounded-xl object-contain border border-slate-200 bg-white p-1 dark:border-neutral-700"
                                                />
                                            ) : (
                                                <div className="flex h-16 w-16 flex-shrink-0 items-center justify-center rounded-xl bg-slate-100 text-2xl font-bold text-slate-400 dark:bg-neutral-800">
                                                    📦
                                                </div>
                                            )}

                                            <div className="space-y-1">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="inline-flex items-center rounded-md bg-yellow-100 px-2 py-0.5 text-xs font-bold text-yellow-800 dark:bg-yellow-950/60 dark:text-yellow-300">
                                                        {link.channel === 'mercado_libre' ? 'Mercado Libre' : link.channel}
                                                    </span>

                                                    {link.external_listing_id && (
                                                        <span className="font-mono text-xs font-semibold text-slate-700 bg-slate-100 px-2 py-0.5 rounded dark:bg-neutral-800 dark:text-slate-300">
                                                            {link.external_listing_id}
                                                        </span>
                                                    )}

                                                    {/* Badge FULL vs Envío Propio */}
                                                    {isFull ? (
                                                        <span className="inline-flex items-center gap-1 rounded-md bg-emerald-100 px-2.5 py-0.5 text-xs font-bold text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300">
                                                            <span>⚡</span> Bodega FULL
                                                        </span>
                                                    ) : (
                                                        <span className="inline-flex items-center gap-1 rounded-md bg-blue-100 px-2.5 py-0.5 text-xs font-bold text-blue-800 dark:bg-blue-950/60 dark:text-blue-300">
                                                            <span>🚚</span> Envío Propio / Colecta
                                                        </span>
                                                    )}

                                                    {link.remote_price && (
                                                        <span className="text-xs font-bold text-slate-900 dark:text-white">
                                                            {money(link.remote_price)}
                                                        </span>
                                                    )}
                                                </div>

                                                <h4 className="text-sm font-semibold text-slate-900 dark:text-white">
                                                    {link.permalink ? (
                                                        <a
                                                            href={link.permalink}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="hover:text-indigo-600 hover:underline inline-flex items-center gap-1"
                                                        >
                                                            <span>{link.title || `Publicación ${link.external_listing_id}`}</span>
                                                            <span className="text-xs text-slate-400">↗</span>
                                                        </a>
                                                    ) : (
                                                        <span>{link.title || `Publicación ${link.external_listing_id}`}</span>
                                                    )}
                                                </h4>

                                                {/* FULL Stock Info or Sync Info */}
                                                <div className="flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                                    {isFull ? (
                                                        <span className="font-semibold text-emerald-700 dark:text-emerald-400">
                                                            Existencias en bodega FULL: <strong>{link.full_stock_available || 0} pzas</strong>
                                                        </span>
                                                    ) : (
                                                        <span>
                                                            Sincroniza stock almacén local:{' '}
                                                            <strong className={link.stock_sync_enabled ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-400'}>
                                                                {link.stock_sync_enabled ? 'Activado' : 'Desactivado'}
                                                            </strong>
                                                        </span>
                                                    )}

                                                    {link.account_key && <span>Cuenta: {link.account_key}</span>}
                                                </div>
                                            </div>
                                        </div>

                                        {/* Actions */}
                                        <div className="flex flex-row sm:flex-col items-end gap-2 flex-shrink-0">
                                            {isMeli && (
                                                <div className="flex items-center gap-1.5">
                                                    <button
                                                        type="button"
                                                        onClick={() => handleToggleIsFull(link)}
                                                        title="Cambiar entre Bodega FULL y Envío Propio"
                                                        className="rounded-lg border border-slate-200 px-2 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800"
                                                    >
                                                        {isFull ? 'Cambiar a Propio' : 'Cambiar a FULL'}
                                                    </button>

                                                    {!isFull && (
                                                        <button
                                                            type="button"
                                                            onClick={() => handleToggleSync(link)}
                                                            className={`rounded-lg px-2 py-1 text-xs font-semibold ${
                                                                link.stock_sync_enabled
                                                                    ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950/40'
                                                                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-neutral-800'
                                                            }`}
                                                        >
                                                            Sync: {link.stock_sync_enabled ? 'ON' : 'OFF'}
                                                        </button>
                                                    )}
                                                </div>
                                            )}

                                            <button
                                                type="button"
                                                onClick={() => handleUnlink(link)}
                                                className="text-xs font-semibold text-rose-600 hover:text-rose-800 hover:underline p-1"
                                            >
                                                Desvincular
                                            </button>
                                        </div>
                                    </div>
                                )
                            })
                        )}
                    </div>
                </section>

                {/* Stock por ubicación */}
                <section className="rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="border-b border-slate-200 px-5 py-4 font-bold dark:border-neutral-800">Stock por ubicación</div>
                    <div className="divide-y divide-slate-100 dark:divide-neutral-800">
                        {stockByLocation.length === 0 ? (
                            <p className="px-5 py-5 text-sm text-slate-500">Sin movimientos ni reservas por ubicación.</p>
                        ) : (
                            stockByLocation.map((row) => (
                                <div key={row.inventory_location_id} className="grid grid-cols-4 gap-2 px-5 py-3 text-sm">
                                    <span>{row.location?.code || 'Sin ubicación'}</span>
                                    <span>Físico: {row.physical_stock}</span>
                                    <span>Reservado: {row.reserved_stock}</span>
                                    <strong>Disponible: {row.available_stock}</strong>
                                </div>
                            ))
                        )}
                    </div>
                </section>

                {/* Reservas activas recientes */}
                <section className="rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="border-b border-slate-200 px-5 py-4 font-bold dark:border-neutral-800">Reservas activas recientes</div>
                    <div className="divide-y divide-slate-100 dark:divide-neutral-800">
                        {reservations.length === 0 ? (
                            <p className="px-5 py-5 text-sm text-slate-500">Sin reservas activas.</p>
                        ) : (
                            reservations.map((reservation) => (
                                <div key={reservation.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
                                    <span>
                                        {reservation.location?.code || 'Global'} · {reservation.reference || 'Sin referencia'}{' '}
                                        <span className="text-slate-500">· {formatDateTime(reservation.created_at)}</span>
                                    </span>
                                    <strong>{reservation.quantity}</strong>
                                </div>
                            ))
                        )}
                    </div>
                </section>

                {/* Últimos movimientos */}
                <section className="rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="border-b border-slate-200 px-5 py-4 font-bold dark:border-neutral-800">Últimos movimientos</div>
                    <div className="divide-y divide-slate-100 dark:divide-neutral-800">
                        {movements.length === 0 ? (
                            <p className="px-5 py-5 text-sm text-slate-500">Sin movimientos registrados.</p>
                        ) : (
                            movements.map((movement) => (
                                <div key={movement.id} className="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm">
                                    <span>
                                        {formatDateTime(movement.occurred_at)} · {movementTypeLabel(movement.type)} · {movement.location?.code}
                                    </span>
                                    <strong className={Number(movement.quantity) >= 0 ? 'text-emerald-700' : 'text-rose-700'}>
                                        {Number(movement.quantity) >= 0 ? '+' : ''}{movement.quantity}
                                    </strong>
                                </div>
                            ))
                        )}
                    </div>
                </section>
            </div>

            {/* MODAL: CONECTAR PUBLICACIÓN DE MERCADO LIBRE */}
            {showModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4 backdrop-blur-sm">
                    <div className="relative w-full max-w-xl rounded-2xl bg-white shadow-2xl dark:bg-neutral-900 border border-slate-200 dark:border-neutral-800 overflow-hidden">
                        {/* Modal Header */}
                        <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4 dark:border-neutral-800">
                            <div>
                                <h3 className="text-lg font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                    <span>🔗</span> Conectar Publicación de Mercado Libre
                                </h3>
                                <p className="text-xs text-slate-500 mt-0.5">
                                    Para asociar a: <strong className="text-slate-800 dark:text-slate-200">{product.name}</strong> ({product.sku})
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setShowModal(false)}
                                className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-neutral-800"
                            >
                                ✕
                            </button>
                        </div>

                        {/* Modal Body */}
                        <div className="p-6 space-y-5 max-h-[75vh] overflow-y-auto">
                            {/* Search input form */}
                            <form onSubmit={handleSearch} className="space-y-2">
                                <label className="block text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300">
                                    Enlace o código MLM de Mercado Libre:
                                </label>
                                <div className="flex gap-2">
                                    <input
                                        type="text"
                                        value={searchQuery}
                                        onChange={(e) => setSearchQuery(e.target.value)}
                                        placeholder="Ej: https://articulo.mercadolibre.com.mx/MLM-... o MLM123456789"
                                        className="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-900 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                        autoFocus
                                    />
                                    <button
                                        type="submit"
                                        disabled={searching || !searchQuery.trim()}
                                        className="flex-shrink-0 rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white shadow hover:bg-indigo-700 disabled:opacity-50 transition"
                                    >
                                        {searching ? 'Buscando...' : 'Buscar'}
                                    </button>
                                </div>
                                <p className="text-[11px] text-slate-400">
                                    💡 Puedes pegar el link completo de la publicación de Mercado Libre, el código MLM o el SKU.
                                </p>
                            </form>

                            {/* Error notification */}
                            {searchError && (
                                <div className="rounded-xl border border-rose-200 bg-rose-50 p-3.5 text-xs text-rose-700 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-300">
                                    ⚠️ {searchError}
                                </div>
                            )}

                            {/* Multiple matches list */}
                            {searchResult && searchResult.type === 'list' && searchResult.items.length > 0 && (
                                <div className="space-y-2">
                                    <p className="text-xs font-bold text-slate-700 dark:text-slate-300">
                                        Selecciona la publicación coincidente:
                                    </p>
                                    <div className="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:border-neutral-800 max-h-48 overflow-y-auto">
                                        {searchResult.items.map((it) => (
                                            <div
                                                key={it.mlm}
                                                onClick={() => selectFromList(it)}
                                                className="flex items-center justify-between gap-3 p-3 hover:bg-slate-50 cursor-pointer dark:hover:bg-neutral-800/60 transition"
                                            >
                                                <div className="flex items-center gap-3">
                                                    {it.thumbnail ? (
                                                        <img src={it.thumbnail} alt="" className="h-10 w-10 rounded-lg object-contain border bg-white" />
                                                    ) : (
                                                        <div className="h-10 w-10 rounded-lg bg-slate-100 flex items-center justify-center text-xs">📦</div>
                                                    )}
                                                    <div>
                                                        <div className="text-xs font-bold text-slate-900 dark:text-white line-clamp-1">{it.title}</div>
                                                        <div className="text-[11px] font-mono text-slate-500">{it.mlm} · SKU: {it.seller_sku || '—'}</div>
                                                    </div>
                                                </div>
                                                <button
                                                    type="button"
                                                    className="rounded-lg bg-indigo-50 px-2.5 py-1 text-xs font-bold text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950 dark:text-indigo-300"
                                                >
                                                    Elegir
                                                </button>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Single preview & confirmation card */}
                            {selectedItem && (
                                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-4 dark:border-neutral-800 dark:bg-neutral-800/50">
                                    <div className="flex items-start gap-4">
                                        {selectedItem.thumbnail ? (
                                            <img
                                                src={selectedItem.thumbnail}
                                                alt={selectedItem.title}
                                                className="h-20 w-20 rounded-xl object-contain border border-slate-200 bg-white p-1"
                                            />
                                        ) : (
                                            <div className="flex h-20 w-20 items-center justify-center rounded-xl bg-slate-200 text-3xl">📦</div>
                                        )}
                                        <div className="space-y-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-mono text-xs font-bold text-slate-800 bg-white px-2 py-0.5 rounded border border-slate-200 dark:bg-neutral-900 dark:border-neutral-700 dark:text-slate-200">
                                                    {selectedItem.mlm}
                                                </span>
                                                {selectedItem.price && (
                                                    <span className="text-xs font-bold text-emerald-700 dark:text-emerald-400">
                                                        {money(selectedItem.price)}
                                                    </span>
                                                )}
                                                {selectedItem.status && (
                                                    <span className="text-[10px] uppercase font-bold text-slate-500">
                                                        {selectedItem.status}
                                                    </span>
                                                )}
                                            </div>
                                            <h4 className="text-sm font-bold text-slate-900 dark:text-white line-clamp-2">
                                                {selectedItem.title}
                                            </h4>
                                            {selectedItem.permalink && (
                                                <a
                                                    href={selectedItem.permalink}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-xs font-semibold text-indigo-600 hover:underline inline-flex items-center gap-0.5"
                                                >
                                                    <span>Ver publicación en Mercado Libre</span>
                                                    <span>↗</span>
                                                </a>
                                            )}
                                        </div>
                                    </div>

                                    {/* Warning if already linked to another product */}
                                    {selectedItem.other_product && (
                                        <div className="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300">
                                            <div className="font-bold flex items-center gap-1.5">
                                                <span>⚠️</span> Esta publicación ya está asignada a:
                                            </div>
                                            <div className="mt-1 font-semibold">
                                                {selectedItem.other_product.name} (SKU: {selectedItem.other_product.sku})
                                            </div>
                                            <label className="mt-2.5 flex items-center gap-2 cursor-pointer font-bold text-amber-900 dark:text-amber-200">
                                                <input
                                                    type="checkbox"
                                                    checked={forceReassign}
                                                    onChange={(e) => setForceReassign(e.target.checked)}
                                                    className="h-4 w-4 rounded border-amber-300 text-indigo-600 focus:ring-indigo-500"
                                                />
                                                <span>Reasignar y transferir a {product.name}</span>
                                            </label>
                                        </div>
                                    )}

                                    {/* Logistics type selection (FULL vs Envío Propio) */}
                                    <div className="space-y-2 pt-2 border-t border-slate-200 dark:border-neutral-700">
                                        <label className="block text-xs font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wider">
                                            ¿Qué tipo de publicación es?
                                        </label>

                                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                            <button
                                                type="button"
                                                onClick={() => setIsFullLogistic(true)}
                                                className={`flex flex-col text-left p-3 rounded-xl border transition ${
                                                    isFullLogistic
                                                        ? 'border-emerald-500 bg-emerald-50/80 ring-2 ring-emerald-500 dark:bg-emerald-950/30'
                                                        : 'border-slate-200 bg-white hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800'
                                                }`}
                                            >
                                                <div className="flex items-center justify-between">
                                                    <span className="font-bold text-xs text-emerald-800 dark:text-emerald-300 flex items-center gap-1">
                                                        <span>⚡</span> Bodega FULL
                                                    </span>
                                                    {isFullLogistic && <span className="text-emerald-600 font-bold text-xs">✓</span>}
                                                </div>
                                                <span className="text-[11px] text-slate-500 mt-1">
                                                    El stock vive en Mercado Libre. Se usa para conteo y cajas de 30 kg. No descuenta almacén local.
                                                </span>
                                            </button>

                                            <button
                                                type="button"
                                                onClick={() => setIsFullLogistic(false)}
                                                className={`flex flex-col text-left p-3 rounded-xl border transition ${
                                                    !isFullLogistic
                                                        ? 'border-indigo-500 bg-indigo-50/80 ring-2 ring-indigo-500 dark:bg-indigo-950/30'
                                                        : 'border-slate-200 bg-white hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800'
                                                }`}
                                            >
                                                <div className="flex items-center justify-between">
                                                    <span className="font-bold text-xs text-indigo-800 dark:text-indigo-300 flex items-center gap-1">
                                                        <span>🚚</span> Envío Propio / Colecta
                                                    </span>
                                                    {!isFullLogistic && <span className="text-indigo-600 font-bold text-xs">✓</span>}
                                                </div>
                                                <span className="text-[11px] text-slate-500 mt-1">
                                                    Nosotros enviamos el producto. Sincroniza y descuenta del stock de almacén físico local.
                                                </span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>

                        {/* Modal Footer */}
                        <div className="flex items-center justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4 dark:border-neutral-800 dark:bg-neutral-900">
                            <button
                                type="button"
                                onClick={() => setShowModal(false)}
                                className="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-300"
                            >
                                Cancelar
                            </button>

                            <button
                                type="button"
                                disabled={!selectedItem || connecting || (selectedItem.other_product && !forceReassign)}
                                onClick={handleConnect}
                                className="rounded-xl bg-amber-500 px-5 py-2.5 text-xs font-bold text-white shadow hover:bg-amber-600 disabled:opacity-50 transition"
                            >
                                {connecting ? 'Conectando...' : 'Confirmar y Vincular'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AppShell>
    )
}
