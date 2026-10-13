import { Head, Link, router } from '@inertiajs/react'
import { useState, useEffect } from 'react'
import AppShell from '@/Components/layout/AppShell'
import Pagination from '@/Components/ui/Pagination'

function StatCard({ title, value, subtitle, colorClass, badge }) {
    return (
        <div className="group relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-5 shadow-2xs transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md dark:border-neutral-800/80 dark:bg-neutral-900">
            <div className="flex items-center justify-between gap-2">
                <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-neutral-400">{title}</p>
                {badge && (
                    <span className="rounded-md bg-slate-100 px-2 py-0.5 text-[10px] font-bold text-slate-600 dark:bg-neutral-800 dark:text-neutral-400">
                        {badge}
                    </span>
                )}
            </div>
            <p className="mt-3 text-3xl font-black tracking-tight text-slate-900 dark:text-white">{value}</p>
            <p className="mt-1.5 text-xs text-slate-500 dark:text-neutral-400">{subtitle}</p>
            <div className="mt-4 h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-neutral-800">
                <div className={`h-full rounded-full ${colorClass}`} style={{ width: '100%' }} />
            </div>
        </div>
    )
}

export default function SyscomOrders({ orders, filters, stats }) {
    const [ordersList, setOrdersList] = useState(orders?.data || [])
    const [copiedFolio, setCopiedFolio] = useState(null)
    const [notification, setNotification] = useState(null)
    const [processingOrderId, setProcessingOrderId] = useState(null)

    // Edit Folio Modal
    const [editModalOpen, setEditModalOpen] = useState(false)
    const [selectedOrder, setSelectedOrder] = useState(null)
    const [folioInput, setFolioInput] = useState('')
    const [savingFolio, setSavingFolio] = useState(false)

    // Raw JSON Modal
    const [rawModalOpen, setRawModalOpen] = useState(false)
    const [rawModalOrder, setRawModalOrder] = useState(null)
    const [copiedRaw, setCopiedRaw] = useState(false)

    useEffect(() => {
        setOrdersList(orders?.data || [])
    }, [orders])

    const showNotice = (msg, type = 'success') => {
        setNotification({ type, message: msg })
        setTimeout(() => setNotification(null), 5000)
    }

    const copyText = async (text) => {
        const t = String(text || '')
        if (!t) return
        try {
            await navigator.clipboard.writeText(t)
            setCopiedFolio(t)
            setTimeout(() => setCopiedFolio(null), 2000)
        } catch {
            window.prompt('Copiar:', t)
        }
    }

    const openEditFolio = (order) => {
        setSelectedOrder(order)
        setFolioInput(order.syscom_order_folio || '')
        setEditModalOpen(true)
    }

    const handleSaveFolio = async (e) => {
        if (e && e.preventDefault) e.preventDefault()
        if (!selectedOrder || !folioInput.trim()) return

        setSavingFolio(true)
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            const res = await fetch(`/syscom-ml/pedidos/${selectedOrder.id}/folio`, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ syscom_order_folio: folioInput.trim() }),
            })
            const data = await res.json()
            if (res.ok && data.success) {
                const newFolio = data.syscom_order_folio
                setOrdersList((prev) =>
                    prev.map((o) => (o.id === selectedOrder.id ? { ...o, syscom_order_folio: newFolio, syscom_order_error: '' } : o))
                )
                setEditModalOpen(false)
                showNotice(`✓ Folio guardado: ${newFolio}`)
                copyText(newFolio)
            } else {
                alert(data.error || 'Error al guardar el folio.')
            }
        } catch (err) {
            console.error('Error saving folio:', err)
            alert('Error de conexión al guardar el folio.')
        } finally {
            setSavingFolio(false)
        }
    }

    const handleExtractFolio = async (order) => {
        setProcessingOrderId(order.id)
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            const res = await fetch(`/syscom-ml/pedidos/${order.id}/extraer-folio`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
            })
            const data = await res.json()
            if (res.ok && data.success) {
                const newFolio = data.syscom_order_folio
                setOrdersList((prev) =>
                    prev.map((o) => (o.id === order.id ? { ...o, syscom_order_folio: newFolio, syscom_order_error: '' } : o))
                )
                showNotice(`✓ ¡Folio recuperado exitosamente!: ${newFolio}`)
                copyText(newFolio)
            } else {
                showNotice(data.error || 'No se encontró folio en la respuesta de SYSCOM.', 'error')
                openEditFolio(order)
            }
        } catch (err) {
            console.error('Error extracting folio:', err)
            showNotice('Error al intentar extraer el folio.', 'error')
        } finally {
            setProcessingOrderId(null)
        }
    }

    const handleRetrySync = async (order) => {
        if (!confirm(`¿Reintentar enviar la orden ${order.order_id} a SYSCOM para generar el pedido?`)) return
        setProcessingOrderId(order.id)
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            const res = await fetch(`/syscom-ml/pedidos/${order.id}/reintentar`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
            })
            const data = await res.json()
            if (res.ok && data.success) {
                const newFolio = data.syscom_order_folio
                setOrdersList((prev) =>
                    prev.map((o) => (o.id === order.id ? { ...o, syscom_order_folio: newFolio, syscom_order_error: '', syscom_order_raw: data.raw, has_raw: true } : o))
                )
                showNotice(`✓ Pedido generado en SYSCOM con folio: ${newFolio}`)
                copyText(newFolio)
            } else {
                showNotice(data.error || 'SYSCOM no generó folio al reintentar.', 'error')
            }
        } catch (err) {
            console.error('Error retrying sync:', err)
            showNotice('Error al reintentar generar el pedido.', 'error')
        } finally {
            setProcessingOrderId(null)
        }
    }

    const handleViewRaw = (order) => {
        setRawModalOrder(order)
        setRawModalOpen(true)
    }

    const copyRawJson = async () => {
        if (!rawModalOrder?.syscom_order_raw) return
        try {
            await navigator.clipboard.writeText(JSON.stringify(rawModalOrder.syscom_order_raw, null, 2))
            setCopiedRaw(true)
            setTimeout(() => setCopiedRaw(false), 2000)
        } catch {
            // ignore
        }
    }

    const applyFilter = (newFilters) => {
        router.get('/syscom-ml/pedidos', {
            ...filters,
            ...newFilters,
            page: 1,
        }, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    const handleSearch = (e) => {
        e.preventDefault()
        const form = new FormData(e.currentTarget)
        applyFilter({ search: form.get('search') || '' })
    }

    return (
        <AppShell title="Pedidos SYSCOM">
            <Head title="Pedidos y Folios SYSCOM" />

            <div className="space-y-8">
                {/* Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <Link href="/syscom-ml" className="text-xs font-semibold uppercase tracking-wider text-indigo-600 hover:underline dark:text-indigo-400">
                                SYSCOM → ML
                            </Link>
                            <span className="text-slate-300 dark:text-neutral-700">/</span>
                            <span className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-neutral-400">
                                Pedidos y Folios
                            </span>
                        </div>
                        <h1 className="mt-1 text-2xl font-black tracking-tight text-slate-900 dark:text-white sm:text-3xl">
                            Auditoría de Pedidos SYSCOM
                        </h1>
                        <p className="mt-1 text-sm text-slate-500 dark:text-neutral-400">
                            Registro de pedidos generados en SYSCOM a partir de ventas en Mercado Libre.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            href="/syscom-ml"
                            className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 shadow-2xs hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200 dark:hover:bg-neutral-700"
                        >
                            <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                            </svg>
                            Ver Catálogo SYSCOM
                        </Link>
                    </div>
                </div>

                {/* Métricas del Día */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        title="Creados en SYSCOM"
                        value={Number(stats.syncOkToday || 0).toLocaleString()}
                        subtitle="Órdenes ML convertidas exitosamente hoy"
                        badge="Hoy"
                        colorClass="bg-emerald-500"
                    />
                    <StatCard
                        title="Omitidos (SKIP)"
                        value={Number(stats.syncSkipToday || 0).toLocaleString()}
                        subtitle="Publicaciones que no son de SYSCOM"
                        badge="Hoy"
                        colorClass="bg-slate-400"
                    />
                    <StatCard
                        title="Errores SYSCOM"
                        value={Number(stats.syncErrToday || 0).toLocaleString()}
                        subtitle="Fallas reportadas por el API de SYSCOM"
                        badge="Hoy"
                        colorClass="bg-rose-500"
                    />
                    <StatCard
                        title="Total Histórico"
                        value={Number(stats.totalFolios || 0).toLocaleString()}
                        subtitle="Folios totales creados en SYSCOM"
                        badge="Acumulado"
                        colorClass="bg-blue-500"
                    />
                </div>

                {/* Filtros y Búsqueda */}
                <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-2xs dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        {/* Buscador */}
                        <form onSubmit={handleSearch} className="flex flex-1 items-center gap-2">
                            <div className="relative flex-1">
                                <input
                                    name="search"
                                    defaultValue={filters.search || ''}
                                    placeholder="Buscar por Folio SYSCOM, Orden ML o error..."
                                    className="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2 text-sm text-slate-800 placeholder-slate-400 outline-none transition focus:border-indigo-500 focus:bg-white dark:border-neutral-700 dark:bg-neutral-950 dark:text-slate-100 dark:placeholder-neutral-500"
                                />
                            </div>
                            <button
                                type="submit"
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white transition hover:bg-indigo-700"
                            >
                                Buscar
                            </button>
                        </form>

                        {/* Filtros de estado */}
                        <div className="flex flex-wrap items-center gap-1.5">
                            <button
                                type="button"
                                onClick={() => applyFilter({ status: 'all' })}
                                className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                    (filters.status || 'all') === 'all'
                                        ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                                        : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-neutral-800 dark:text-slate-300'
                                }`}
                            >
                                Todos
                            </button>
                            <button
                                type="button"
                                onClick={() => applyFilter({ status: 'ok' })}
                                className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                    filters.status === 'ok'
                                        ? 'bg-emerald-600 text-white'
                                        : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300'
                                }`}
                            >
                                Con Folio
                            </button>
                            <button
                                type="button"
                                onClick={() => applyFilter({ status: 'error' })}
                                className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                    filters.status === 'error'
                                        ? 'bg-rose-600 text-white'
                                        : 'bg-rose-50 text-rose-700 hover:bg-rose-100 dark:bg-rose-950/40 dark:text-rose-300'
                                }`}
                            >
                                Errores
                            </button>
                            <button
                                type="button"
                                onClick={() => applyFilter({ status: 'skip' })}
                                className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                    filters.status === 'skip'
                                        ? 'bg-slate-600 text-white'
                                        : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-neutral-800 dark:text-slate-400'
                                }`}
                            >
                                SKIP
                            </button>
                            <button
                                type="button"
                                onClick={() => applyFilter({ status: 'cancelled' })}
                                className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                    filters.status === 'cancelled'
                                        ? 'bg-amber-600 text-white'
                                        : 'bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-950/40 dark:text-amber-300'
                                }`}
                            >
                                Cancelados
                            </button>

                            {/* Filtro fecha */}
                            <span className="mx-1 text-slate-300 dark:text-neutral-700">|</span>
                            <button
                                type="button"
                                onClick={() => applyFilter({ date: filters.date === 'today' ? 'all' : 'today' })}
                                className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                    filters.date === 'today'
                                        ? 'bg-indigo-600 text-white'
                                        : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200'
                                }`}
                            >
                                {filters.date === 'today' ? '📅 Solo Hoy ✓' : '📅 Ver Solo Hoy'}
                            </button>
                        </div>
                    </div>
                </div>

                {/* Notificación Flotante / Toast */}
                {notification && (
                    <div
                        className={`flex items-center justify-between rounded-xl p-4 text-xs font-bold shadow-md transition-all ${
                            notification.type === 'error'
                                ? 'bg-rose-50 text-rose-800 border border-rose-200 dark:bg-rose-950 dark:text-rose-200'
                                : 'bg-emerald-50 text-emerald-800 border border-emerald-200 dark:bg-emerald-950 dark:text-emerald-200'
                        }`}
                    >
                        <span>{notification.message}</span>
                        <button type="button" onClick={() => setNotification(null)} className="ml-4 opacity-70 hover:opacity-100">
                            ✕
                        </button>
                    </div>
                )}

                {/* Tabla de Folios */}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xs dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-left text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-600 dark:border-neutral-800 dark:bg-neutral-800/80 dark:text-slate-400">
                                <tr>
                                    <th className="px-5 py-3.5">Orden ML</th>
                                    <th className="px-5 py-3.5">Referencia</th>
                                    <th className="px-5 py-3.5">Folio SYSCOM</th>
                                    <th className="px-5 py-3.5">Sincronización</th>
                                    <th className="px-5 py-3.5">Estado / Error</th>
                                    <th className="px-5 py-3.5 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                {ordersList.length === 0 ? (
                                    <tr>
                                        <td colSpan="6" className="px-5 py-12 text-center text-sm text-slate-500 dark:text-neutral-400">
                                            No se encontraron pedidos de SYSCOM con los filtros seleccionados.
                                        </td>
                                    </tr>
                                ) : (
                                    ordersList.map((row) => {
                                        const mlCancelled = Boolean(row.ml_cancelled)
                                        const syscomCancelled = Boolean(row.syscom_cancelled)
                                        const strike = mlCancelled ? 'line-through text-slate-400 dark:text-slate-500' : ''
                                        const isProcessing = processingOrderId === row.id

                                        return (
                                            <tr key={row.id || row.order_id} className="transition hover:bg-slate-50 dark:hover:bg-neutral-800/50">
                                                <td className={`whitespace-nowrap px-5 py-3.5 font-mono text-xs font-semibold ${strike}`}>
                                                    {row.order_id}
                                                </td>
                                                <td className={`whitespace-nowrap px-5 py-3.5 font-mono text-xs text-slate-500 dark:text-slate-400 ${strike}`}>
                                                    {row.referencia_ml}
                                                </td>
                                                <td className="px-5 py-3.5 font-mono text-xs font-bold">
                                                    {row.syscom_order_folio ? (
                                                        <div className="flex items-center gap-1.5 flex-wrap">
                                                            <span
                                                                className={`inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 ${
                                                                    syscomCancelled
                                                                        ? 'bg-slate-100 text-slate-400 line-through dark:bg-neutral-800'
                                                                        : mlCancelled
                                                                          ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300'
                                                                          : 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                                                                }`}
                                                            >
                                                                {row.syscom_order_folio}
                                                            </span>
                                                            <button
                                                                type="button"
                                                                onClick={() => openEditFolio(row)}
                                                                className="text-slate-400 hover:text-indigo-600 transition"
                                                                title="Editar este folio"
                                                            >
                                                                ✎
                                                            </button>
                                                        </div>
                                                    ) : (
                                                        <div>
                                                            <span className="inline-flex items-center gap-1 rounded-md bg-amber-100 border border-amber-300 px-2 py-0.5 text-xs font-bold text-amber-900 dark:bg-amber-950/60 dark:border-amber-700 dark:text-amber-300">
                                                                ⚠️ Sin folio
                                                            </span>
                                                            <button
                                                                type="button"
                                                                onClick={() => openEditFolio(row)}
                                                                className="mt-1 block text-[11px] font-bold text-indigo-600 hover:text-indigo-800 hover:underline dark:text-indigo-400"
                                                            >
                                                                ✎ Asignar Folio
                                                            </button>
                                                        </div>
                                                    )}

                                                    {mlCancelled && !syscomCancelled && (
                                                        <span className="mt-1 block text-[10px] font-normal text-amber-600 dark:text-amber-400">
                                                            ML cancelada · pendiente SYSCOM
                                                        </span>
                                                    )}
                                                    {syscomCancelled && (
                                                        <span className="mt-1 block text-[10px] font-normal text-slate-500">
                                                            Cancelado en SYSCOM {row.syscom_order_cancelled_at || ''}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="whitespace-nowrap px-5 py-3.5 text-xs text-slate-600 dark:text-slate-400">
                                                    {row.syscom_order_synced_at || '—'}
                                                </td>
                                                <td className="px-5 py-3.5 text-xs">
                                                    {row.syscom_order_error ? (
                                                        <span className={`inline-block max-w-xs truncate rounded px-2 py-0.5 text-[11px] ${
                                                            row.syscom_order_error.startsWith('SKIP')
                                                                ? 'bg-slate-100 text-slate-600 dark:bg-neutral-800 dark:text-slate-400'
                                                                : 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300'
                                                        }`} title={row.syscom_order_error}>
                                                            {row.syscom_order_error}
                                                        </span>
                                                    ) : row.syscom_order_folio ? (
                                                        <span className="text-emerald-600 dark:text-emerald-400 font-medium">
                                                            Sincronizado OK
                                                        </span>
                                                    ) : (
                                                        <span className="text-amber-600 dark:text-amber-400 font-medium">
                                                            ⚠️ Requiere Folio
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-5 py-3.5 text-right whitespace-nowrap">
                                                    <div className="flex items-center justify-end gap-1.5">
                                                        {row.syscom_order_folio && (
                                                            <button
                                                                type="button"
                                                                onClick={() => copyText(row.syscom_order_folio)}
                                                                className={`rounded-lg border px-2.5 py-1 text-xs font-semibold transition ${
                                                                    copiedFolio === row.syscom_order_folio
                                                                        ? 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300'
                                                                        : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200'
                                                                }`}
                                                            >
                                                                {copiedFolio === row.syscom_order_folio ? '✓ Copiado' : 'Copiar'}
                                                            </button>
                                                        )}

                                                        {!row.syscom_order_folio && (
                                                            <>
                                                                {row.has_raw && (
                                                                    <button
                                                                        type="button"
                                                                        disabled={isProcessing}
                                                                        onClick={() => handleExtractFolio(row)}
                                                                        className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-bold text-indigo-700 hover:bg-indigo-100 disabled:opacity-50 dark:border-indigo-900 dark:bg-indigo-950/50 dark:text-indigo-300"
                                                                        title="Re-analizar la respuesta de SYSCOM para buscar el folio"
                                                                    >
                                                                        {isProcessing ? '⏳ Buscando...' : '🔍 Extraer Folio'}
                                                                    </button>
                                                                )}
                                                                <button
                                                                    type="button"
                                                                    onClick={() => openEditFolio(row)}
                                                                    className="rounded-lg border border-emerald-300 bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-800 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300"
                                                                    title="Ingresar folio manualmente"
                                                                >
                                                                    ✎ Asignar
                                                                </button>
                                                                <button
                                                                    type="button"
                                                                    disabled={isProcessing}
                                                                    onClick={() => handleRetrySync(row)}
                                                                    className="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-300"
                                                                    title="Reintentar llamada para generar pedido en SYSCOM"
                                                                >
                                                                    🔄
                                                                </button>
                                                            </>
                                                        )}

                                                        {row.has_raw && (
                                                            <button
                                                                type="button"
                                                                onClick={() => handleViewRaw(row)}
                                                                className="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs text-slate-500 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-400"
                                                                title="Ver respuesta JSON cruda de SYSCOM"
                                                            >
                                                                👁️ JSON
                                                            </button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        )
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>

                    <div className="border-t border-slate-200 px-5 py-3.5 dark:border-neutral-800">
                        <Pagination links={orders.links || []} />
                    </div>
                </div>

                {/* MODAL: EDITAR / ASIGNAR FOLIO SYSCOM */}
                {editModalOpen && selectedOrder && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs">
                        <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="flex items-center justify-between border-b border-slate-100 pb-3 dark:border-neutral-800">
                                <h3 className="text-base font-black text-slate-900 dark:text-white">
                                    Asignar Folio de Pedido SYSCOM
                                </h3>
                                <button
                                    type="button"
                                    onClick={() => setEditModalOpen(false)}
                                    className="text-slate-400 hover:text-slate-600"
                                >
                                    ✕
                                </button>
                            </div>

                            <form onSubmit={handleSaveFolio} className="mt-4 space-y-4">
                                <div>
                                    <label className="text-xs font-semibold text-slate-500 dark:text-neutral-400">
                                        Orden Mercado Libre
                                    </label>
                                    <p className="font-mono text-sm font-bold text-slate-800 dark:text-slate-200">
                                        {selectedOrder.referencia_ml} ({selectedOrder.order_id})
                                    </p>
                                </div>

                                <div>
                                    <label className="block text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300">
                                        Folio SYSCOM <span className="text-rose-500">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        autoFocus
                                        value={folioInput}
                                        onChange={(e) => setFolioInput(e.target.value)}
                                        placeholder="Ej. 576-5934968/26-API"
                                        className="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 font-mono text-sm font-bold text-slate-900 placeholder-slate-400 outline-none focus:border-indigo-600 focus:ring-1 focus:ring-indigo-600 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                    />
                                    <p className="mt-1.5 text-[11px] text-slate-500 dark:text-neutral-400">
                                        Pega el folio asignado por SYSCOM en tu portal o factura. Al guardar, quedará registrado permanentemente para recolección y auditoría.
                                    </p>
                                </div>

                                <div className="flex items-center justify-end gap-2 pt-2">
                                    <button
                                        type="button"
                                        onClick={() => setEditModalOpen(false)}
                                        className="rounded-xl border border-slate-200 px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                                    >
                                        Cancelar
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={savingFolio || !folioInput.trim()}
                                        className="rounded-xl bg-indigo-600 px-5 py-2 text-xs font-bold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50"
                                    >
                                        {savingFolio ? 'Guardando...' : 'Guardar Folio'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

                {/* MODAL: VER RESPUESTA CRUDA SYSCOM (JSON) */}
                {rawModalOpen && rawModalOrder && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-xs">
                        <div className="w-full max-w-2xl max-h-[85vh] flex flex-col rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="flex items-center justify-between border-b border-slate-100 pb-3 dark:border-neutral-800">
                                <div>
                                    <h3 className="text-base font-black text-slate-900 dark:text-white">
                                        Respuesta Cruda de SYSCOM (API)
                                    </h3>
                                    <p className="font-mono text-xs text-slate-500">
                                        {rawModalOrder.referencia_ml}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setRawModalOpen(false)}
                                    className="text-slate-400 hover:text-slate-600"
                                >
                                    ✕
                                </button>
                            </div>

                            <div className="mt-4 flex-1 overflow-y-auto rounded-xl bg-slate-900 p-4 font-mono text-xs text-emerald-400 scrollbar-thin">
                                <pre className="whitespace-pre-wrap">
                                    {rawModalOrder.syscom_order_raw
                                        ? JSON.stringify(rawModalOrder.syscom_order_raw, null, 2)
                                        : '// No hay datos JSON registrados para esta orden.'}
                                </pre>
                            </div>

                            <div className="mt-4 flex items-center justify-between pt-2">
                                <span className="text-[11px] text-slate-400">
                                    {rawModalOrder.syscom_order_folio
                                        ? `Folio activo: ${rawModalOrder.syscom_order_folio}`
                                        : 'Sin folio extraído de esta respuesta'}
                                </span>
                                <div className="flex items-center gap-2">
                                    <button
                                        type="button"
                                        onClick={copyRawJson}
                                        className="rounded-xl border border-slate-200 px-4 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-200"
                                    >
                                        {copiedRaw ? '✓ Copiado' : '📋 Copiar JSON'}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setRawModalOpen(false)}
                                        className="rounded-xl bg-slate-900 px-5 py-2 text-xs font-bold text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900"
                                    >
                                        Cerrar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AppShell>
    )
}
