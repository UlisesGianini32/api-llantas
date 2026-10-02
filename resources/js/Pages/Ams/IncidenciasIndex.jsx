import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router } from '@inertiajs/react'
import { useState } from 'react'
import axios from 'axios'

function issueTypeBadgeClass(type) {
    switch (type) {
        case 'sin_imagen':
            return 'border-rose-500/60 bg-rose-600/20 text-rose-200'
        case 'imagen_incorrecta':
            return 'border-amber-500/60 bg-amber-600/20 text-amber-200'
        case 'sku_incorrecto':
            return 'border-yellow-500/60 bg-yellow-600/20 text-yellow-200'
        case 'titulo_incorrecto':
            return 'border-sky-500/60 bg-sky-600/20 text-sky-200'
        default:
            return 'border-slate-500/60 bg-slate-600/20 text-slate-200'
    }
}

function issueTypeIcon(type) {
    switch (type) {
        case 'sin_imagen':
            return '🖼️❌'
        case 'imagen_incorrecta':
            return '🖼️⚠️'
        case 'sku_incorrecto':
            return '🏷️'
        case 'titulo_incorrecto':
            return '✏️'
        default:
            return '⚠️'
    }
}

function mlmUrl(itemId) {
    if (!itemId) return '#'
    const cleanId = String(itemId).trim().replace(/^MLM-?/i, 'MLM')
    return `https://articulo.mercadolibre.com.mx/${cleanId}`
}

function ModalCorregir({ issue, onClose, onSaved }) {
    const [sku, setSku] = useState(issue?.sku || '')
    const [title, setTitle] = useState(issue?.title || '')
    const [imageUrl, setImageUrl] = useState(issue?.effective_thumbnail || issue?.current_image_url || '')
    const [imageFile, setImageFile] = useState(null)
    const [imagePreview, setImagePreview] = useState(issue?.effective_thumbnail || issue?.current_image_url || '')
    const [resolutionNotes, setResolutionNotes] = useState('')
    const [markResolved, setMarkResolved] = useState(true)
    const [saving, setSaving] = useState(false)
    const [error, setError] = useState(null)

    const handleFileChange = (e) => {
        const file = e.target.files?.[0]
        if (file) {
            setImageFile(file)
            const reader = new FileReader()
            reader.onload = (loadEvt) => {
                setImagePreview(loadEvt.target?.result)
            }
            reader.readAsDataURL(file)
        }
    }

    const handleUrlChange = (val) => {
        setImageUrl(val)
        setImagePreview(val)
        setImageFile(null)
    }

    const handleSubmit = async (e) => {
        e.preventDefault()
        setSaving(true)
        setError(null)

        try {
            const formData = new FormData()
            if (sku.trim() !== '') formData.append('new_sku', sku.trim())
            if (title.trim() !== '') formData.append('new_title', title.trim())
            if (imageFile) {
                formData.append('new_image_file', imageFile)
            } else if (imageUrl.trim() !== '') {
                formData.append('new_image_url', imageUrl.trim())
            }
            if (resolutionNotes.trim() !== '') {
                formData.append('resolution_notes', resolutionNotes.trim())
            }
            formData.append('mark_resolved', markResolved ? '1' : '0')
            formData.append('_method', 'PUT')

            const response = await axios.post(`/ams/incidencias/${issue.id}`, formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            })

            if (response.data?.success) {
                onSaved(response.data.issue)
            } else {
                router.reload()
                onClose()
            }
        } catch (err) {
            console.error('Error al guardar corrección:', err)
            setError(err.response?.data?.message || 'Error al guardar los cambios.')
        } finally {
            setSaving(false)
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/75 p-4 backdrop-blur-sm">
            <div className="w-full max-w-2xl rounded-2xl border border-slate-700 bg-[#00153b] p-6 shadow-2xl text-white">
                <div className="flex items-start justify-between border-b border-slate-700 pb-4">
                    <div>
                        <h2 className="text-xl font-bold flex items-center gap-2">
                            <span>✏️ Corregir Datos del Producto</span>
                            <span className="text-sm font-mono text-sky-400">({issue.item_id})</span>
                        </h2>
                        <p className="mt-1 text-xs text-slate-300">
                            Problema reportado: <span className="font-semibold text-amber-300">{issue.issue_type_label}</span>
                            {issue.notes ? <> — &quot;{issue.notes}&quot;</> : null}
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg p-1 text-slate-400 hover:bg-slate-700 hover:text-white"
                    >
                        ✕
                    </button>
                </div>

                {error ? (
                    <div className="mt-4 rounded-lg border border-rose-500/50 bg-rose-950/60 p-3 text-sm text-rose-200">
                        {error}
                    </div>
                ) : null}

                <form onSubmit={handleSubmit} className="mt-4 space-y-4">
                    {/* Sección Imagen */}
                    <div className="rounded-xl border border-slate-700 bg-slate-800/60 p-4">
                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-2">
                            1. Imagen del Producto
                        </label>
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-[110px_minmax(0,1fr)]">
                            <div className="flex flex-col items-center">
                                <div className="h-[110px] w-[110px] rounded-xl border border-slate-600 bg-white p-1 flex items-center justify-center overflow-hidden">
                                    {imagePreview ? (
                                        <img
                                            src={imagePreview}
                                            alt="Preview"
                                            className="h-full w-full object-contain"
                                            onError={() => setImagePreview('')}
                                        />
                                    ) : (
                                        <span className="text-xs text-slate-500 text-center">Sin imagen válida</span>
                                    )}
                                </div>
                                <span className="mt-1 text-[11px] text-slate-400">Vista previa</span>
                            </div>

                            <div className="space-y-3">
                                <div>
                                    <label className="block text-xs text-slate-300 mb-1">
                                        URL de la nueva imagen (pegar link directo):
                                    </label>
                                    <input
                                        type="url"
                                        value={imageUrl}
                                        onChange={(e) => handleUrlChange(e.target.value)}
                                        placeholder="https://http2.mlstatic.com/... o enlace de foto"
                                        className="w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-1.5 text-sm text-white placeholder-slate-500 outline-none focus:border-sky-400"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs text-slate-300 mb-1">
                                        O subir archivo de imagen desde tu equipo:
                                    </label>
                                    <input
                                        type="file"
                                        accept="image/*"
                                        onChange={handleFileChange}
                                        className="w-full text-xs text-slate-300 file:mr-2 file:rounded-lg file:border-0 file:bg-sky-600 file:px-3 file:py-1 file:text-xs file:font-semibold file:text-white hover:file:bg-sky-500"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Sección SKU y Título */}
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="rounded-xl border border-slate-700 bg-slate-800/60 p-4">
                            <label className="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1">
                                2. SKU del Producto
                            </label>
                            <p className="text-[11px] text-slate-400 mb-2">
                                Se actualizará en productos, pedidos de AMS y publicaciones.
                            </p>
                            <input
                                type="text"
                                value={sku}
                                onChange={(e) => setSku(e.target.value)}
                                placeholder="Ej: FOAM1, PROD-123"
                                className="w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-2 text-sm font-semibold text-white outline-none focus:border-sky-400"
                            />
                        </div>

                        <div className="rounded-xl border border-slate-700 bg-slate-800/60 p-4">
                            <label className="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1">
                                3. Título / Nombre
                            </label>
                            <p className="text-[11px] text-slate-400 mb-2">
                                Nombre descriptivo que verán en el almacén al empacar.
                            </p>
                            <input
                                type="text"
                                value={title}
                                onChange={(e) => setTitle(e.target.value)}
                                placeholder="Nombre descriptivo del producto"
                                className="w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-2 text-sm text-white outline-none focus:border-sky-400"
                            />
                        </div>
                    </div>

                    {/* Sección Notas de Resolución */}
                    <div className="rounded-xl border border-slate-700 bg-slate-800/60 p-4">
                        <label className="block text-xs font-bold uppercase tracking-wider text-slate-300 mb-1">
                            4. Notas de la Corrección (opcional)
                        </label>
                        <input
                            type="text"
                            value={resolutionNotes}
                            onChange={(e) => setResolutionNotes(e.target.value)}
                            placeholder="Ej: Se corrigió foto de acondicionador a shampoo, SKU asignado correctamente."
                            className="w-full rounded-lg border border-slate-600 bg-slate-900 px-3 py-1.5 text-sm text-white placeholder-slate-500 outline-none focus:border-sky-400"
                        />

                        <label className="mt-3 flex items-center gap-2 text-xs font-semibold text-emerald-300 cursor-pointer">
                            <input
                                type="checkbox"
                                checked={markResolved}
                                onChange={(e) => setMarkResolved(e.target.checked)}
                                className="h-4 w-4 rounded border-slate-500 bg-slate-800 text-emerald-500 focus:ring-emerald-400"
                            />
                            <span>Marcar automáticamente esta incidencia como resuelta al guardar</span>
                        </label>
                    </div>

                    <div className="flex justify-end gap-3 pt-2">
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={saving}
                            className="rounded-lg border border-slate-600 bg-slate-800 px-4 py-2 text-sm text-slate-300 hover:bg-slate-700"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            disabled={saving}
                            className="rounded-lg border border-emerald-500/60 bg-emerald-600 px-5 py-2 text-sm font-bold text-white transition hover:bg-emerald-500 disabled:opacity-50"
                        >
                            {saving ? 'Guardando cambios...' : 'Guardar y Aplicar Cambios'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    )
}

export default function IncidenciasIndex({ issues, counts, issueTypes, filters }) {
    const [selectedIssueForEdit, setSelectedIssueForEdit] = useState(null)
    const [searchVal, setSearchVal] = useState(filters?.search || '')
    const [actionLoadingId, setActionLoadingId] = useState(null)

    const handleSearchSubmit = (e) => {
        e.preventDefault()
        router.get('/ams/incidencias', {
            ...filters,
            search: searchVal,
            page: 1,
        }, { preserveState: true })
    }

    const setStatusFilter = (st) => {
        router.get('/ams/incidencias', {
            ...filters,
            status: st,
            page: 1,
        }, { preserveState: true })
    }

    const setTypeFilter = (tp) => {
        router.get('/ams/incidencias', {
            ...filters,
            issue_type: tp === filters.issue_type ? undefined : tp,
            page: 1,
        }, { preserveState: true })
    }

    const handleResolve = async (issueId) => {
        setActionLoadingId(issueId)
        try {
            await axios.post(`/ams/incidencias/${issueId}/resolve`, {
                notes: 'Resuelto desde panel de incidencias.',
            })
            router.reload({ preserveScroll: true })
        } catch (err) {
            console.error('Error al resolver:', err)
            window.alert('No se pudo marcar como resuelto.')
        } finally {
            setActionLoadingId(null)
        }
    }

    const handleReopen = async (issueId) => {
        setActionLoadingId(issueId)
        try {
            await axios.post(`/ams/incidencias/${issueId}/reopen`)
            router.reload({ preserveScroll: true })
        } catch (err) {
            console.error('Error al reabrir:', err)
            window.alert('No se pudo reabrir la incidencia.')
        } finally {
            setActionLoadingId(null)
        }
    }

    const handleDelete = async (issueId) => {
        if (!window.confirm('¿Deseas descartar/eliminar esta incidencia?')) {
            return
        }
        setActionLoadingId(issueId)
        try {
            await axios.delete(`/ams/incidencias/${issueId}`)
            router.reload({ preserveScroll: true })
        } catch (err) {
            console.error('Error al descartar:', err)
            window.alert('No se pudo descartar la incidencia.')
        } finally {
            setActionLoadingId(null)
        }
    }

    return (
        <AppShell title="Incidencias de Catálogo y Productos">
            <Head title="Incidencias de Productos · AMS" />

            <section className="bg-[#0b1220] min-h-[calc(100vh-4rem)] py-4 sm:py-6">
                <div className="mx-auto max-w-[1400px] px-4 sm:px-6 lg:px-8 space-y-6">
                    {/* Header */}
                    <div className="rounded-none border border-slate-700 bg-[#00153b] p-6 shadow-2xl">
                        <div className="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h1 className="text-2xl font-bold text-white sm:text-3xl flex items-center gap-2">
                                    <span>⚠️ Incidencias de Catálogo y Productos</span>
                                </h1>
                                <p className="mt-1 text-sm text-slate-300">
                                    Productos reportados durante el empaque con imágenes rotas/incorrectas, SKUs faltantes o títulos confusos.
                                </p>
                            </div>

                            <div className="flex flex-wrap items-center gap-2">
                                <Link
                                    href="/ams/pedidos-procesar"
                                    className="rounded-lg border border-sky-500/60 bg-sky-600/20 px-4 py-2 text-sm font-semibold text-sky-200 transition hover:bg-sky-600/35"
                                >
                                    ← Ir a Procesar Pedidos
                                </Link>
                            </div>
                        </div>

                        {/* Tarjetas de Resumen / Filtros Rápidos */}
                        <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-6">
                            <button
                                type="button"
                                onClick={() => setStatusFilter('pending')}
                                className={`rounded-xl border p-3 text-left transition ${
                                    filters.status === 'pending' && !filters.issue_type
                                        ? 'border-amber-400 bg-amber-500/20'
                                        : 'border-slate-700 bg-slate-800/60 hover:bg-slate-800'
                                }`}
                            >
                                <span className="block text-xs uppercase font-medium text-slate-400">Total Pendientes</span>
                                <span className="mt-1 block text-2xl font-bold text-amber-300">{counts.total_pending}</span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setTypeFilter('sin_imagen')}
                                className={`rounded-xl border p-3 text-left transition ${
                                    filters.issue_type === 'sin_imagen'
                                        ? 'border-rose-400 bg-rose-500/20'
                                        : 'border-slate-700 bg-slate-800/60 hover:bg-slate-800'
                                }`}
                            >
                                <span className="block text-xs uppercase font-medium text-slate-400">Sin Imagen</span>
                                <span className="mt-1 block text-2xl font-bold text-rose-300">{counts.by_type?.sin_imagen || 0}</span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setTypeFilter('imagen_incorrecta')}
                                className={`rounded-xl border p-3 text-left transition ${
                                    filters.issue_type === 'imagen_incorrecta'
                                        ? 'border-amber-400 bg-amber-500/20'
                                        : 'border-slate-700 bg-slate-800/60 hover:bg-slate-800'
                                }`}
                            >
                                <span className="block text-xs uppercase font-medium text-slate-400">Imagen Incorrecta</span>
                                <span className="mt-1 block text-2xl font-bold text-amber-300">{counts.by_type?.imagen_incorrecta || 0}</span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setTypeFilter('sku_incorrecto')}
                                className={`rounded-xl border p-3 text-left transition ${
                                    filters.issue_type === 'sku_incorrecto'
                                        ? 'border-yellow-400 bg-yellow-500/20'
                                        : 'border-slate-700 bg-slate-800/60 hover:bg-slate-800'
                                }`}
                            >
                                <span className="block text-xs uppercase font-medium text-slate-400">SKU Incorrecto</span>
                                <span className="mt-1 block text-2xl font-bold text-yellow-300">{counts.by_type?.sku_incorrecto || 0}</span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setTypeFilter('titulo_incorrecto')}
                                className={`rounded-xl border p-3 text-left transition ${
                                    filters.issue_type === 'titulo_incorrecto'
                                        ? 'border-sky-400 bg-sky-500/20'
                                        : 'border-slate-700 bg-slate-800/60 hover:bg-slate-800'
                                }`}
                            >
                                <span className="block text-xs uppercase font-medium text-slate-400">Título Incorrecto</span>
                                <span className="mt-1 block text-2xl font-bold text-sky-300">{counts.by_type?.titulo_incorrecto || 0}</span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setStatusFilter('resolved')}
                                className={`rounded-xl border p-3 text-left transition ${
                                    filters.status === 'resolved'
                                        ? 'border-emerald-400 bg-emerald-500/20'
                                        : 'border-slate-700 bg-slate-800/60 hover:bg-slate-800'
                                }`}
                            >
                                <span className="block text-xs uppercase font-medium text-slate-400">Resueltos</span>
                                <span className="mt-1 block text-2xl font-bold text-emerald-300">{counts.total_resolved}</span>
                            </button>
                        </div>

                        {/* Barra de Filtros y Búsqueda */}
                        <div className="mt-6 flex flex-col gap-4 border-t border-slate-700 pt-4 md:flex-row md:items-center md:justify-between">
                            <div className="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => setStatusFilter('pending')}
                                    className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                        filters.status === 'pending'
                                            ? 'bg-amber-500 text-slate-950'
                                            : 'bg-slate-800 text-slate-300 hover:bg-slate-700'
                                    }`}
                                >
                                    Pendientes ({counts.total_pending})
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setStatusFilter('resolved')}
                                    className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                        filters.status === 'resolved'
                                            ? 'bg-emerald-500 text-slate-950'
                                            : 'bg-slate-800 text-slate-300 hover:bg-slate-700'
                                    }`}
                                >
                                    Resueltos ({counts.total_resolved})
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setStatusFilter('all')}
                                    className={`rounded-lg px-3 py-1.5 text-xs font-bold transition ${
                                        filters.status === 'all'
                                            ? 'bg-sky-500 text-slate-950'
                                            : 'bg-slate-800 text-slate-300 hover:bg-slate-700'
                                    }`}
                                >
                                    Todos ({counts.total_all})
                                </button>
                            </div>

                            <form onSubmit={handleSearchSubmit} className="flex gap-2">
                                <input
                                    type="text"
                                    value={searchVal}
                                    onChange={(e) => setSearchVal(e.target.value)}
                                    placeholder="Buscar por MLM, SKU, título, notas..."
                                    className="w-full md:w-80 rounded-lg border border-slate-600 bg-slate-900 px-3 py-1.5 text-sm text-white placeholder-slate-500 outline-none focus:border-sky-400"
                                />
                                <button
                                    type="submit"
                                    className="rounded-lg border border-slate-600 bg-slate-800 px-4 py-1.5 text-sm text-white hover:bg-slate-700"
                                >
                                    Buscar
                                </button>
                                {filters.search ? (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setSearchVal('')
                                            router.get('/ams/incidencias', { ...filters, search: undefined, page: 1 })
                                        }}
                                        className="rounded-lg border border-slate-600 bg-slate-800 px-2 py-1.5 text-xs text-slate-400 hover:text-white"
                                        title="Limpiar búsqueda"
                                    >
                                        ✕
                                    </button>
                                ) : null}
                            </form>
                        </div>
                    </div>

                    {/* Lista de Incidencias */}
                    <div className="space-y-4">
                        {issues.data.length === 0 ? (
                            <div className="rounded-none border border-slate-700 bg-[#00153b] p-12 text-center text-slate-400">
                                <span className="text-4xl block mb-2">🎉</span>
                                <h3 className="text-lg font-bold text-white">No hay incidencias que coincidan</h3>
                                <p className="text-sm mt-1">
                                    {filters.status === 'pending'
                                        ? '¡Excelente! No hay problemas pendientes de resolver con los filtros actuales.'
                                        : 'No se encontraron registros para los filtros seleccionados.'}
                                </p>
                            </div>
                        ) : (
                            issues.data.map((issue) => (
                                <div
                                    key={issue.id}
                                    className={`rounded-none border p-5 shadow-lg transition ${
                                        issue.status === 'resolved'
                                            ? 'border-emerald-900/60 bg-[#001828]/80'
                                            : 'border-slate-700 bg-[#00153b]'
                                    }`}
                                >
                                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-[120px_minmax(0,1fr)_auto]">
                                        {/* Columna Izquierda: Imagen y Badges */}
                                        <div className="flex flex-col items-center justify-start gap-2">
                                            <div className="h-[110px] w-[110px] rounded-xl border border-slate-600 bg-white p-1 flex items-center justify-center overflow-hidden">
                                                {issue.effective_thumbnail ? (
                                                    <img
                                                        src={issue.effective_thumbnail}
                                                        alt={issue.title || 'Producto'}
                                                        className="h-full w-full object-contain"
                                                    />
                                                ) : (
                                                    <div className="text-center text-xs text-slate-500 font-semibold p-2">
                                                        Sin imagen
                                                    </div>
                                                )}
                                            </div>

                                            <span
                                                className={`rounded-md border px-2 py-0.5 text-xs font-bold ${issueTypeBadgeClass(
                                                    issue.issue_type
                                                )}`}
                                            >
                                                {issueTypeIcon(issue.issue_type)} {issue.issue_type_label}
                                            </span>

                                            {issue.status === 'resolved' ? (
                                                <span className="rounded-md border border-emerald-500/50 bg-emerald-950/60 px-2 py-0.5 text-[11px] font-bold text-emerald-300">
                                                    ✓ Resuelto
                                                </span>
                                            ) : (
                                                <span className="rounded-md border border-amber-500/50 bg-amber-950/60 px-2 py-0.5 text-[11px] font-bold text-amber-300">
                                                    ● Pendiente
                                                </span>
                                            )}
                                        </div>

                                        {/* Columna Central: Detalles del Producto y Reporte */}
                                        <div className="min-w-0 space-y-2">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <a
                                                    href={mlmUrl(issue.item_id)}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="font-mono text-sm font-bold text-sky-400 hover:text-sky-300 hover:underline"
                                                    title="Abrir publicación en Mercado Libre"
                                                >
                                                    {issue.item_id} ↗
                                                </a>

                                                <span className="text-xs text-slate-500">|</span>

                                                <span className="text-xs text-slate-300">
                                                    SKU actual:{' '}
                                                    <span className="font-bold text-white bg-slate-800 px-2 py-0.5 rounded border border-slate-600">
                                                        {issue.sku || 'N/A'}
                                                    </span>
                                                </span>

                                                {issue.order_id ? (
                                                    <>
                                                        <span className="text-xs text-slate-500">|</span>
                                                        <span className="text-xs text-slate-400">
                                                            Visto en orden #{issue.order_id}
                                                        </span>
                                                    </>
                                                ) : null}
                                            </div>

                                            <h3 className="text-base font-semibold text-white leading-snug">
                                                {issue.title || 'Sin título especificado'}
                                            </h3>

                                            {issue.notes ? (
                                                <div className="rounded-lg border border-amber-500/30 bg-amber-950/30 p-2.5 text-xs text-amber-200">
                                                    <span className="font-bold">Nota del reporte:</span> {issue.notes}
                                                </div>
                                            ) : null}

                                            {issue.resolution_notes ? (
                                                <div className="rounded-lg border border-emerald-500/30 bg-emerald-950/30 p-2.5 text-xs text-emerald-200">
                                                    <span className="font-bold">Resolución:</span> {issue.resolution_notes}
                                                </div>
                                            ) : null}

                                            <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-400 pt-1">
                                                <span>
                                                    Reportado por:{' '}
                                                    <span className="text-slate-200">
                                                        {issue.reported_by?.name || 'Usuario'}
                                                    </span>
                                                </span>
                                                <span>•</span>
                                                <span>Fecha: {issue.created_at_formateada}</span>
                                                {issue.resolved_at_formateada ? (
                                                    <>
                                                        <span>•</span>
                                                        <span className="text-emerald-300">
                                                            Resuelto el: {issue.resolved_at_formateada}{' '}
                                                            {issue.resolved_by ? `por ${issue.resolved_by.name}` : ''}
                                                        </span>
                                                    </>
                                                ) : null}
                                            </div>
                                        </div>

                                        {/* Columna Derecha: Acciones */}
                                        <div className="flex flex-row lg:flex-col items-center lg:items-end justify-center gap-2 border-t border-slate-700/60 pt-3 lg:border-t-0 lg:pt-0">
                                            <button
                                                type="button"
                                                onClick={() => setSelectedIssueForEdit(issue)}
                                                className="w-full rounded-lg border border-sky-500/60 bg-sky-600/20 px-3.5 py-2 text-xs font-bold text-sky-100 transition hover:bg-sky-600/35 flex items-center justify-center gap-1.5"
                                            >
                                                <span>✏️ Corregir datos</span>
                                            </button>

                                            {issue.status === 'pending' ? (
                                                <button
                                                    type="button"
                                                    onClick={() => handleResolve(issue.id)}
                                                    disabled={actionLoadingId === issue.id}
                                                    className="w-full rounded-lg border border-emerald-500/60 bg-emerald-700/20 px-3.5 py-2 text-xs font-bold text-emerald-100 transition hover:bg-emerald-700/35 disabled:opacity-50"
                                                >
                                                    {actionLoadingId === issue.id ? 'Guardando...' : '✓ Marcar resuelto'}
                                                </button>
                                            ) : (
                                                <button
                                                    type="button"
                                                    onClick={() => handleReopen(issue.id)}
                                                    disabled={actionLoadingId === issue.id}
                                                    className="w-full rounded-lg border border-amber-500/60 bg-amber-700/20 px-3.5 py-2 text-xs font-bold text-amber-100 transition hover:bg-amber-700/35 disabled:opacity-50"
                                                >
                                                    {actionLoadingId === issue.id ? 'Reabriendo...' : '↩️ Reabrir'}
                                                </button>
                                            )}

                                            <button
                                                type="button"
                                                onClick={() => handleDelete(issue.id)}
                                                disabled={actionLoadingId === issue.id}
                                                className="w-full rounded-lg border border-slate-700 bg-slate-800/80 px-3.5 py-1.5 text-xs text-slate-400 transition hover:bg-rose-900/30 hover:text-rose-200 hover:border-rose-700/50"
                                            >
                                                Descartar
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>

                    {/* Paginación */}
                    {issues.links && issues.links.length > 3 ? (
                        <div className="flex justify-center gap-1 py-4">
                            {issues.links.map((link, idx) => (
                                <Link
                                    key={idx}
                                    href={link.url || '#'}
                                    preserveScroll
                                    className={`rounded-lg px-3 py-1.5 text-xs font-semibold ${
                                        link.active
                                            ? 'bg-sky-600 text-white'
                                            : link.url
                                            ? 'bg-slate-800 text-slate-300 hover:bg-slate-700'
                                            : 'bg-slate-900 text-slate-600 cursor-not-allowed'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    ) : null}
                </div>
            </section>

            {/* Modal de Corrección */}
            {selectedIssueForEdit ? (
                <ModalCorregir
                    issue={selectedIssueForEdit}
                    onClose={() => setSelectedIssueForEdit(null)}
                    onSaved={() => {
                        setSelectedIssueForEdit(null)
                        router.reload({ preserveScroll: true })
                    }}
                />
            ) : null}
        </AppShell>
    )
}
