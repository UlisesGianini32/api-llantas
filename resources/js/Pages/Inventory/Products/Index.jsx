import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'

function money(value) {
    if (value === null || value === undefined || value === '') return '—'

    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    }).format(Number(value))
}

function ReportModal({ isOpen, onClose, brands = [] }) {
    const [reportScope, setReportScope] = useState('all') // 'all' or 'brand'
    const [selectedBrand, setSelectedBrand] = useState(brands[0]?.name || '')
    const [statusFilter, setStatusFilter] = useState('all') // 'all', 'with_stock', 'zero_stock', 'active'
    const [kitsFilter, setKitsFilter] = useState('all') // 'all', 'exclude', 'only'
    const [isDownloadingPdf, setIsDownloadingPdf] = useState(false)

    if (!isOpen) return null

    const brandParam = reportScope === 'brand' ? selectedBrand : ''

    const getCsvUrl = () => {
        const params = new URLSearchParams()
        if (brandParam) params.append('brand', brandParam)
        if (statusFilter !== 'all') params.append('status', statusFilter)
        if (kitsFilter !== 'all') params.append('kits', kitsFilter)
        return `/almacen/productos/exportar?${params.toString()}`
    }

    const getPdfUrl = () => {
        const params = new URLSearchParams()
        if (brandParam) params.append('brand', brandParam)
        if (statusFilter !== 'all') params.append('status', statusFilter)
        if (kitsFilter !== 'all') params.append('kits', kitsFilter)
        return `/almacen/productos/reporte-pdf?${params.toString()}`
    }

    const handleDownloadCsv = () => {
        window.location.href = getCsvUrl()
        onClose()
    }

    const handleOpenPdf = () => {
        window.open(getPdfUrl(), '_blank')
        onClose()
    }

    // Helper to dynamically load html2pdf from CDN
    const getHtml2Pdf = () => {
        return new Promise((resolve, reject) => {
            if (typeof window !== 'undefined' && window.html2pdf) {
                return resolve(window.html2pdf)
            }
            const script = document.createElement('script')
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.2/html2pdf.bundle.min.js'
            script.onload = () => {
                if (window.html2pdf) resolve(window.html2pdf)
                else reject(new Error('html2pdf no se cargó correctamente'))
            }
            script.onerror = () => reject(new Error('No se pudo cargar la librería html2pdf'))
            document.head.appendChild(script)
        })
    }

    // Direct PDF download to user's computer
    const handleDownloadPdf = async () => {
        setIsDownloadingPdf(true)
        try {
            const html2pdf = await getHtml2Pdf()
            const url = getPdfUrl()
            const res = await fetch(url)
            const html = await res.text()

            const parser = new DOMParser()
            const doc = parser.parseFromString(html, 'text/html')
            const sheet = doc.querySelector('.page-sheet')
            const style = doc.querySelector('style')

            if (!sheet) {
                window.open(`${url}&download=1`, '_blank')
                onClose()
                return
            }

            const container = document.createElement('div')
            container.style.position = 'fixed'
            container.style.left = '-9999px'
            container.style.top = '0'
            container.style.width = '1024px'
            container.style.background = '#ffffff'

            if (style) {
                container.appendChild(style.cloneNode(true))
            }
            container.appendChild(sheet)
            document.body.appendChild(container)

            const cleanBrand = brandParam ? brandParam.replace(/\s+/g, '-') : 'General'
            const filename = `Reporte-Inventario-${cleanBrand}-${new Date().toISOString().slice(0, 10)}.pdf`

            await html2pdf().set({
                margin: [8, 8, 8, 8],
                filename: filename,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true, logging: false },
                jsPDF: { unit: 'mm', format: 'letter', orientation: 'portrait' },
                pagebreak: {
                    mode: ['avoid-all', 'css', 'legacy'],
                    avoid: ['.report-row', '.kpi-card', '.signatures', '.header-grid', '.avoid-break']
                }
            }).from(sheet).save()

            document.body.removeChild(container)
            onClose()
        } catch (err) {
            console.error('Error al generar PDF en segundo plano:', err)
            window.open(`${getPdfUrl()}&download=1`, '_blank')
            onClose()
        } finally {
            setIsDownloadingPdf(false)
        }
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div className="relative w-full max-w-lg rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-neutral-800 dark:bg-neutral-900">
                {/* Header */}
                <div className="flex items-start justify-between border-b border-slate-100 pb-4 dark:border-neutral-800">
                    <div>
                        <span className="text-xs font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">
                            Inventario SBS · Exportación
                        </span>
                        <h3 className="mt-1 text-xl font-black text-slate-900 dark:text-white">
                            Descargar Reporte de Inventario
                        </h3>
                        <p className="mt-1 text-xs text-slate-500 dark:text-neutral-400">
                            Genera reportes de stock físico, reservado, disponible y valorización a costo.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-xl p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-neutral-800 dark:hover:text-slate-200"
                    >
                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div className="mt-5 space-y-4">
                    {/* 1. Alcance: General vs Por Marca */}
                    <div>
                        <label className="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300">
                            1. Alcance del Reporte
                        </label>
                        <div className="mt-2 grid grid-cols-2 gap-3">
                            <button
                                type="button"
                                onClick={() => setReportScope('all')}
                                className={`rounded-xl border p-3 text-left transition ${
                                    reportScope === 'all'
                                        ? 'border-indigo-600 bg-indigo-50/60 ring-2 ring-indigo-500/20 dark:border-indigo-500 dark:bg-indigo-950/30'
                                        : 'border-slate-200 bg-white hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800'
                                }`}
                            >
                                <span className="block text-xs font-bold text-slate-900 dark:text-white">
                                    🌐 General (Todo)
                                </span>
                                <span className="mt-0.5 block text-[11px] text-slate-500 dark:text-neutral-400">
                                    Catálogo completo SBS
                                </span>
                            </button>

                            <button
                                type="button"
                                onClick={() => setReportScope('brand')}
                                className={`rounded-xl border p-3 text-left transition ${
                                    reportScope === 'brand'
                                        ? 'border-indigo-600 bg-indigo-50/60 ring-2 ring-indigo-500/20 dark:border-indigo-500 dark:bg-indigo-950/30'
                                        : 'border-slate-200 bg-white hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800'
                                }`}
                            >
                                <span className="block text-xs font-bold text-slate-900 dark:text-white">
                                    🏷️ Por Marca
                                </span>
                                <span className="mt-0.5 block text-[11px] text-slate-500 dark:text-neutral-400">
                                    Filtrar por marca específica
                                </span>
                            </button>
                        </div>
                    </div>

                    {/* Selector de marca si eligió Por Marca */}
                    {reportScope === 'brand' && (
                        <div className="rounded-2xl border border-indigo-100 bg-indigo-50/40 p-3.5 dark:border-indigo-950/50 dark:bg-indigo-950/20">
                            <label className="block text-xs font-bold text-slate-700 dark:text-slate-200">
                                Seleccionar Marca:
                            </label>
                            <select
                                value={selectedBrand}
                                onChange={(e) => setSelectedBrand(e.target.value)}
                                className="mt-2 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 font-semibold outline-none focus:border-indigo-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                            >
                                {brands.length === 0 ? (
                                    <option value="">No hay marcas registradas</option>
                                ) : (
                                    brands.map((b) => (
                                        <option key={b.name} value={b.name}>
                                            {b.name} ({b.count} productos)
                                        </option>
                                    ))
                                )}
                            </select>
                        </div>
                    )}

                    {/* 2. Filtro de Existencias */}
                    <div>
                        <label className="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300">
                            2. Filtro de Existencias
                        </label>
                        <div className="mt-2 grid grid-cols-2 gap-2 text-xs">
                            <label className={`flex items-center gap-2 rounded-xl border p-2.5 cursor-pointer transition ${
                                statusFilter === 'all'
                                    ? 'border-indigo-600 bg-indigo-50/50 font-bold text-indigo-900 dark:border-indigo-500 dark:bg-indigo-950/30 dark:text-indigo-200'
                                    : 'border-slate-200 text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800'
                            }`}>
                                <input
                                    type="radio"
                                    name="statusFilter"
                                    value="all"
                                    checked={statusFilter === 'all'}
                                    onChange={(e) => setStatusFilter(e.target.value)}
                                    className="text-indigo-600"
                                />
                                Todos los productos
                            </label>

                            <label className={`flex items-center gap-2 rounded-xl border p-2.5 cursor-pointer transition ${
                                statusFilter === 'with_stock'
                                    ? 'border-emerald-600 bg-emerald-50/50 font-bold text-emerald-900 dark:border-emerald-500 dark:bg-emerald-950/30 dark:text-emerald-200'
                                    : 'border-slate-200 text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800'
                            }`}>
                                <input
                                    type="radio"
                                    name="statusFilter"
                                    value="with_stock"
                                    checked={statusFilter === 'with_stock'}
                                    onChange={(e) => setStatusFilter(e.target.value)}
                                    className="text-emerald-600"
                                />
                                Solo con existencias (&gt; 0)
                            </label>

                            <label className={`flex items-center gap-2 rounded-xl border p-2.5 cursor-pointer transition ${
                                statusFilter === 'zero_stock'
                                    ? 'border-rose-600 bg-rose-50/50 font-bold text-rose-900 dark:border-rose-500 dark:bg-rose-950/30 dark:text-rose-200'
                                    : 'border-slate-200 text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800'
                            }`}>
                                <input
                                    type="radio"
                                    name="statusFilter"
                                    value="zero_stock"
                                    checked={statusFilter === 'zero_stock'}
                                    onChange={(e) => setStatusFilter(e.target.value)}
                                    className="text-rose-600"
                                />
                                Solo agotados (Stock 0)
                            </label>

                            <label className={`flex items-center gap-2 rounded-xl border p-2.5 cursor-pointer transition ${
                                statusFilter === 'active'
                                    ? 'border-slate-800 bg-slate-100 font-bold text-slate-900 dark:border-neutral-500 dark:bg-neutral-800 dark:text-white'
                                    : 'border-slate-200 text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800'
                            }`}>
                                <input
                                    type="radio"
                                    name="statusFilter"
                                    value="active"
                                    checked={statusFilter === 'active'}
                                    onChange={(e) => setStatusFilter(e.target.value)}
                                    className="text-slate-800"
                                />
                                Solo productos activos
                            </label>
                        </div>
                    </div>

                    {/* 3. Filtro de Kits / Paquetes */}
                    <div>
                        <label className="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-300">
                            3. Kits / Paquetes Compuestos
                        </label>
                        <div className="mt-2 grid grid-cols-1 sm:grid-cols-3 gap-2 text-xs">
                            <label className={`flex items-center gap-2 rounded-xl border p-2.5 cursor-pointer transition ${
                                kitsFilter === 'all'
                                    ? 'border-indigo-600 bg-indigo-50/50 font-bold text-indigo-900 dark:border-indigo-500 dark:bg-indigo-950/30 dark:text-indigo-200'
                                    : 'border-slate-200 text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800'
                            }`}>
                                <input
                                    type="radio"
                                    name="kitsFilter"
                                    value="all"
                                    checked={kitsFilter === 'all'}
                                    onChange={(e) => setKitsFilter(e.target.value)}
                                    className="text-indigo-600"
                                />
                                <span>Todo (Simples y Kits)</span>
                            </label>

                            <label className={`flex items-center gap-2 rounded-xl border p-2.5 cursor-pointer transition ${
                                kitsFilter === 'exclude'
                                    ? 'border-indigo-600 bg-indigo-50/50 font-bold text-indigo-900 dark:border-indigo-500 dark:bg-indigo-950/30 dark:text-indigo-200'
                                    : 'border-slate-200 text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800'
                            }`}>
                                <input
                                    type="radio"
                                    name="kitsFilter"
                                    value="exclude"
                                    checked={kitsFilter === 'exclude'}
                                    onChange={(e) => setKitsFilter(e.target.value)}
                                    className="text-indigo-600"
                                />
                                <span>Sin Kits (Solo individuales)</span>
                            </label>

                            <label className={`flex items-center gap-2 rounded-xl border p-2.5 cursor-pointer transition ${
                                kitsFilter === 'only'
                                    ? 'border-indigo-600 bg-indigo-50/50 font-bold text-indigo-900 dark:border-indigo-500 dark:bg-indigo-950/30 dark:text-indigo-200'
                                    : 'border-slate-200 text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800'
                            }`}>
                                <input
                                    type="radio"
                                    name="kitsFilter"
                                    value="only"
                                    checked={kitsFilter === 'only'}
                                    onChange={(e) => setKitsFilter(e.target.value)}
                                    className="text-indigo-600"
                                />
                                <span>Solo Kits</span>
                            </label>
                        </div>
                    </div>
                </div>

                {/* Botones de Descarga */}
                <div className="mt-6 flex flex-col gap-2.5 sm:flex-row sm:justify-end border-t border-slate-100 pt-4 dark:border-neutral-800">
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-bold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-300"
                    >
                        Cancelar
                    </button>

                    <button
                        type="button"
                        onClick={handleOpenPdf}
                        className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-300"
                    >
                        <svg className="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                        </svg>
                        Ver / Imprimir
                    </button>

                    <button
                        type="button"
                        onClick={handleDownloadPdf}
                        disabled={isDownloadingPdf}
                        className="inline-flex items-center justify-center gap-2 rounded-xl bg-rose-600 px-4 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-rose-700 transition disabled:opacity-50"
                    >
                        {isDownloadingPdf ? (
                            <>
                                <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                                <span>Descargando...</span>
                            </>
                        ) : (
                            <>
                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                </svg>
                                <span>Descargar en PDF</span>
                            </>
                        )}
                    </button>

                    <button
                        type="button"
                        onClick={handleDownloadCsv}
                        className="inline-flex items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-xs font-bold text-white shadow-sm hover:bg-emerald-700 transition"
                    >
                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        Descargar en Excel
                    </button>
                </div>
            </div>
        </div>
    )
}

export default function InventoryProductsIndex({ products, filters = {}, brands = [] }) {
    const { flash = {} } = usePage().props
    const [search, setSearch] = useState(filters?.search || '')
    const [selectedBrandFilter, setSelectedBrandFilter] = useState(filters?.brand || '')
    const [isReportModalOpen, setIsReportModalOpen] = useState(false)

    const submitSearch = (event) => {
        event.preventDefault()
        router.get('/almacen/productos', {
            search,
            brand: selectedBrandFilter,
        }, { preserveState: true, replace: true })
    }

    const clearFilters = () => {
        setSearch('')
        setSelectedBrandFilter('')
        router.get('/almacen/productos', {}, { preserveState: true, replace: true })
    }

    const toggle = (id) => {
        router.patch(`/almacen/productos/${id}/estado`, {}, { preserveScroll: true })
    }

    const isFiltered = Boolean(search || selectedBrandFilter)

    return (
        <AppShell title="Almacén">
            <Head title="Productos de almacén - SBS" />
            <div className="space-y-6">
                {/* Header */}
                <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
                    <div>
                        <p className="text-sm font-semibold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-300">
                            Inventario SBS
                        </p>
                        <h1 className="mt-2 text-3xl font-black tracking-tight text-slate-900 dark:text-white">
                            Catálogo maestro
                        </h1>
                        <p className="mt-1 text-sm text-slate-500 dark:text-neutral-400">
                            Productos vendibles con SKU universal, control de stock físico, ubicaciones y códigos de barras.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-2.5">
                        {/* Botón Descargar Reporte */}
                        <button
                            type="button"
                            onClick={() => setIsReportModalOpen(true)}
                            className="inline-flex items-center gap-2 rounded-xl border border-emerald-300 bg-emerald-50 px-4 py-2.5 text-xs font-bold text-emerald-800 shadow-2xs transition hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-900/60"
                        >
                            <svg className="h-4 w-4 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            Descargar Reporte
                        </button>

                        <Link
                            href="/almacen/productos/crear"
                            className="inline-flex items-center justify-center rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-indigo-700"
                        >
                            + Nuevo producto
                        </Link>
                    </div>
                </div>

                {flash.success && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
                        {flash.success}
                    </div>
                )}

                {/* Formulario de Búsqueda y Filtro por Marca */}
                <form
                    onSubmit={submitSearch}
                    className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 lg:flex-row lg:items-center dark:border-neutral-800 dark:bg-neutral-900"
                >
                    <div className="flex-1">
                        <input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Buscar por nombre, SKU, código de barras o ubicación..."
                            className="w-full rounded-xl border border-slate-300 bg-slate-50 px-4 py-2.5 text-sm outline-none transition focus:border-indigo-500 focus:bg-white dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                        />
                    </div>

                    {/* Filtro por Marca */}
                    <div className="min-w-[200px]">
                        <select
                            value={selectedBrandFilter}
                            onChange={(e) => setSelectedBrandFilter(e.target.value)}
                            className="w-full rounded-xl border border-slate-300 bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-800 outline-none transition focus:border-indigo-500 focus:bg-white dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                        >
                            <option value="">Todas las marcas</option>
                            {brands.map((b) => (
                                <option key={b.name} value={b.name}>
                                    {b.name} ({b.count})
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="flex items-center gap-2">
                        <button
                            type="submit"
                            className="rounded-xl bg-slate-900 px-5 py-2.5 text-xs font-bold text-white transition hover:bg-slate-700 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-200"
                        >
                            Buscar
                        </button>
                        {isFiltered && (
                            <button
                                type="button"
                                onClick={clearFilters}
                                className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                            >
                                Limpiar
                            </button>
                        )}
                    </div>
                </form>

                {/* Tabla de Productos */}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="overflow-x-auto">
                        <table className="min-w-[980px] w-full divide-y divide-slate-200 text-sm dark:divide-neutral-800">
                            <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500 dark:bg-neutral-950 dark:text-slate-400">
                                <tr>
                                    <th className="w-64 px-4 py-3">Producto</th>
                                    <th className="min-w-40 px-4 py-3">Precios</th>
                                    <th className="px-4 py-3">Ubicación</th>
                                    <th className="px-4 py-3">Físico / derivado</th>
                                    <th className="px-4 py-3">Reservado</th>
                                    <th className="px-4 py-3">Disponible</th>
                                    <th className="px-4 py-3">Estado</th>
                                    <th className="px-4 py-3">Acciones</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                {products.data.length === 0 ? (
                                    <tr>
                                        <td colSpan="8" className="px-5 py-12 text-center text-slate-500">
                                            No hay productos para mostrar con los filtros seleccionados.
                                        </td>
                                    </tr>
                                ) : (
                                    products.data.map((product) => {
                                        const isKit = product.product_type === 'KIT'
                                        return (
                                            <tr key={product.id} className="align-top hover:bg-slate-50/70 dark:hover:bg-neutral-950/50">
                                                <td className="px-4 py-3">
                                                    <Link
                                                        href={`/almacen/productos/${product.id}`}
                                                        className="font-semibold text-slate-900 hover:text-indigo-600 dark:text-white dark:hover:text-indigo-300"
                                                    >
                                                        {product.name}
                                                    </Link>
                                                    <div className="mt-1 flex flex-wrap items-center gap-2">
                                                        <span className="font-mono text-xs text-slate-500 dark:text-slate-300">
                                                            {product.sku}
                                                        </span>
                                                        <span
                                                            className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${
                                                                isKit
                                                                    ? 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300'
                                                                    : 'bg-slate-100 text-slate-600 dark:bg-neutral-800 dark:text-slate-300'
                                                            }`}
                                                        >
                                                            {isKit ? 'KIT · derivado' : 'SIMPLE'}
                                                        </span>
                                                        {product.brand && (
                                                            <span className="rounded-md bg-slate-100 px-1.5 py-0.2 text-[10px] font-bold uppercase text-slate-600 dark:bg-neutral-800 dark:text-slate-300">
                                                                {product.brand}
                                                            </span>
                                                        )}
                                                    </div>
                                                    <div className="mt-1 font-mono text-[11px] text-slate-500">
                                                        {product.barcode && <span>Cód: {product.barcode}</span>}
                                                        {product.barcode_secondary && (
                                                            <span className="ml-1.5 text-indigo-600 dark:text-indigo-400 font-medium">
                                                                · Cód 2: {product.barcode_secondary}
                                                            </span>
                                                        )}
                                                        {!product.barcode && !product.barcode_secondary && (
                                                            <span>Código: —</span>
                                                        )}
                                                    </div>
                                                </td>

                                                <td className="px-4 py-3 text-xs leading-5">
                                                    <div>
                                                        <span className="text-slate-500">Costo:</span> {money(product.cost)}
                                                    </div>
                                                    <div>
                                                        <span className="text-slate-500">ML:</span> {money(product.price_mercado_libre)}
                                                    </div>
                                                    <div>
                                                        <span className="text-slate-500">Amazon:</span> {money(product.price_amazon)}
                                                    </div>
                                                    <div>
                                                        <span className="text-slate-500">Shopify:</span> {money(product.price_stylist)}
                                                    </div>
                                                    <div>
                                                        <span className="text-slate-500">Público:</span> {money(product.price_public)}
                                                    </div>
                                                </td>

                                                <td className="px-4 py-3 text-slate-600 dark:text-slate-300">
                                                    <div className="font-semibold text-slate-900 dark:text-white">
                                                        {product.primary_location?.code
                                                            ? `📍 ${product.primary_location.code}`
                                                            : 'Sin ubicación'}
                                                    </div>
                                                    {product.secondary_location && (
                                                        <div className="mt-0.5 text-xs font-medium text-amber-600 dark:text-amber-400">
                                                            📦 Res: {product.secondary_location.code}
                                                        </div>
                                                    )}
                                                    {product.reserve_notes && (
                                                        <div
                                                            className="mt-0.5 max-w-[140px] truncate text-[11px] text-slate-500"
                                                            title={product.reserve_notes}
                                                        >
                                                            {product.reserve_notes}
                                                        </div>
                                                    )}
                                                </td>

                                                <td className="px-4 py-3 font-semibold">
                                                    <span>{Number(product.physical_stock || 0)}</span>
                                                    {isKit && (
                                                        <span className="mt-1 block text-[11px] font-medium text-indigo-600 dark:text-indigo-300">
                                                            Derivado
                                                        </span>
                                                    )}
                                                </td>

                                                <td className="px-4 py-3">{Number(product.reserved_stock || 0)}</td>

                                                <td className="px-4 py-3 font-semibold">
                                                    {Number(
                                                        product.available_stock ??
                                                            (Number(product.physical_stock || 0) -
                                                                Number(product.reserved_stock || 0))
                                                    )}
                                                </td>

                                                <td className="px-4 py-3">
                                                    <span
                                                        className={`rounded-full px-3 py-1 text-xs font-semibold ${
                                                            product.is_active
                                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                                                                : 'bg-slate-100 text-slate-600 dark:bg-neutral-800 dark:text-slate-300'
                                                        }`}
                                                    >
                                                        {product.is_active ? 'Activo' : 'Inactivo'}
                                                    </span>
                                                </td>

                                                <td className="px-4 py-3">
                                                    <div className="flex flex-wrap gap-2">
                                                        <Link
                                                            href={`/almacen/productos/${product.id}`}
                                                            className="text-indigo-600 hover:underline dark:text-indigo-300"
                                                        >
                                                            Ver
                                                        </Link>
                                                        <Link
                                                            href={`/almacen/productos/${product.id}/editar`}
                                                            className="text-indigo-600 hover:underline dark:text-indigo-300"
                                                        >
                                                            Editar
                                                        </Link>
                                                        <button
                                                            type="button"
                                                            onClick={() => toggle(product.id)}
                                                            className="text-amber-700 hover:underline dark:text-amber-300"
                                                        >
                                                            {product.is_active ? 'Desactivar' : 'Activar'}
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        )
                                    })
                                )}
                            </tbody>
                        </table>
                    </div>

                    {products.links?.length > 3 && (
                        <div className="flex flex-wrap gap-2 border-t border-slate-200 p-4 dark:border-neutral-800">
                            {products.links.map((link, index) =>
                                link.url ? (
                                    <Link
                                        key={index}
                                        href={link.url}
                                        preserveState
                                        className={`rounded-lg border px-3 py-1.5 text-sm ${
                                            link.active
                                                ? 'border-indigo-600 bg-indigo-600 text-white'
                                                : 'border-slate-200 dark:border-neutral-700 dark:text-slate-300'
                                        }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ) : (
                                    <span
                                        key={index}
                                        className="rounded-lg border border-slate-100 px-3 py-1.5 text-sm text-slate-400 dark:border-neutral-800"
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                )
                            )}
                        </div>
                    )}
                </div>
            </div>

            {/* Modal de Descarga de Reportes */}
            <ReportModal
                isOpen={isReportModalOpen}
                onClose={() => setIsReportModalOpen(false)}
                brands={brands}
            />
        </AppShell>
    )
}
