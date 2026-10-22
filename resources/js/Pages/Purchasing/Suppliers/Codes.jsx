import { useState, useEffect } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

export default function SupplierCodesIndex({
    suppliers = [],
    selectedSupplier = null,
    products = { data: [] },
    availableBrands = [],
    filters = {},
    summary = {},
}) {
    const productItems = products.data || []

    const [search, setSearch] = useState(filters.search || '')
    const [selectedBrand, setSelectedBrand] = useState(filters.brand || '')
    const [selectedStatus, setSelectedStatus] = useState(filters.status || 'all')

    // Local state for inline editable fields: { [productId]: { supplier_sku, supplier_cost, isSaving, isSaved, isDirty } }
    const [rowStates, setRowStates] = useState({})

    // Modal state for linking any product
    const [linkModalOpen, setLinkModalOpen] = useState(false)
    const [searchCatalogQuery, setSearchCatalogQuery] = useState('')
    const [catalogResults, setCatalogResults] = useState([])
    const [searchingCatalog, setSearchingCatalog] = useState(false)
    const [selectedCatalogProduct, setSelectedCatalogProduct] = useState(null)
    const [modalSupplierSku, setModalSupplierSku] = useState('')
    const [modalSupplierCost, setModalSupplierCost] = useState('')
    const [modalSaving, setModalSaving] = useState(false)
    const [modalError, setModalError] = useState('')

    // Feedback notification
    const [toastMessage, setToastMessage] = useState(null)

    // Sync rowStates when products change
    useEffect(() => {
        const initial = {}
        productItems.forEach((p) => {
            initial[p.id] = {
                supplier_sku: p.supplier_sku || '',
                supplier_cost: p.supplier_cost !== null && p.supplier_cost !== undefined ? p.supplier_cost : (p.cost || ''),
                isSaving: false,
                isSaved: false,
                isDirty: false,
            }
        })
        setRowStates(initial)
    }, [products])

    const showToast = (msg) => {
        setToastMessage(msg)
        setTimeout(() => setToastMessage(null), 3500)
    }

    const handleFilterChange = (newFilters) => {
        const query = {
            supplier_id: filters.supplier_id,
            search: search,
            brand: selectedBrand,
            status: selectedStatus,
            ...newFilters,
        }

        Object.keys(query).forEach((key) => {
            if (!query[key] && query[key] !== 0) delete query[key]
        })

        router.get('/compras/proveedores/codigos', query, {
            preserveState: true,
            preserveScroll: true,
        })
    }

    const handleSearchSubmit = (e) => {
        e.preventDefault()
        handleFilterChange({ search })
    }

    const handleSupplierSelect = (supplierId) => {
        handleFilterChange({
            supplier_id: supplierId,
            brand: '',
            search: '',
        })
    }

    const handleInputChange = (productId, field, value) => {
        setRowStates((prev) => ({
            ...prev,
            [productId]: {
                ...prev[productId],
                [field]: value,
                isDirty: true,
                isSaved: false,
            },
        }))
    }

    const handleSaveRow = async (product) => {
        if (!selectedSupplier) return

        const row = rowStates[product.id] || { supplier_sku: '', supplier_cost: product.cost }
        const supplierSku = (row.supplier_sku || '').trim()

        if (!supplierSku) {
            alert('Por favor ingresa un código de proveedor válido.')
            return
        }

        setRowStates((prev) => ({
            ...prev,
            [product.id]: { ...prev[product.id], isSaving: true },
        }))

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            const response = await fetch('/compras/proveedores/codigos/guardar', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken || '',
                },
                body: JSON.stringify({
                    inventory_product_id: product.id,
                    supplier_id: selectedSupplier.id,
                    supplier_name: selectedSupplier.name,
                    supplier_sku: supplierSku,
                    supplier_cost: row.supplier_cost !== '' ? parseFloat(row.supplier_cost) : null,
                }),
            })

            const data = await response.json()

            if (response.ok && data.ok) {
                setRowStates((prev) => ({
                    ...prev,
                    [product.id]: {
                        ...prev[product.id],
                        isSaving: false,
                        isSaved: true,
                        isDirty: false,
                    },
                }))
                showToast(`✓ Código '${supplierSku}' guardado para ${product.name}`)
            } else {
                throw new Error(data.message || data.error || 'Error al guardar código')
            }
        } catch (err) {
            alert(err.message || 'Error al guardar el código de proveedor')
            setRowStates((prev) => ({
                ...prev,
                [product.id]: { ...prev[product.id], isSaving: false },
            }))
        }
    }

    const handleDeleteCode = async (product) => {
        if (!selectedSupplier) return
        if (!confirm(`¿Eliminar el código de proveedor para '${product.name}'?`)) return

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            const response = await fetch('/compras/proveedores/codigos/eliminar', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken || '',
                },
                body: JSON.stringify({
                    inventory_product_id: product.id,
                    supplier_name: selectedSupplier.name,
                }),
            })

            const data = await response.json()

            if (response.ok && data.ok) {
                setRowStates((prev) => ({
                    ...prev,
                    [product.id]: {
                        ...prev[product.id],
                        supplier_sku: '',
                        isDirty: false,
                        isSaved: false,
                    },
                }))
                showToast(`✓ Código de proveedor eliminado`)
            } else {
                throw new Error(data.message || 'Error al eliminar')
            }
        } catch (err) {
            alert(err.message || 'Error al eliminar el código')
        }
    }

    // Live search catalog for link modal
    useEffect(() => {
        const query = searchCatalogQuery.trim()
        if (query.length < 2) {
            setCatalogResults([])
            return
        }

        const timeout = setTimeout(async () => {
            setSearchingCatalog(true)
            try {
                const res = await fetch(`/compras/proveedores/codigos/buscar-productos?q=${encodeURIComponent(query)}`)
                const json = await res.json()
                if (json.ok) {
                    setCatalogResults(json.products || [])
                }
            } catch (e) {
                console.error(e)
            } finally {
                setSearchingCatalog(false)
            }
        }, 300)

        return () => clearTimeout(timeout)
    }, [searchCatalogQuery])

    const handleSelectCatalogProduct = (p) => {
        setSelectedCatalogProduct(p)
        setModalSupplierCost(p.cost || '')
        setModalSupplierSku('')
        setModalError('')
    }

    const handleSaveLinkedProduct = async (e) => {
        e.preventDefault()
        if (!selectedSupplier || !selectedCatalogProduct) return

        const sku = modalSupplierSku.trim()
        if (!sku) {
            setModalError('Debes ingresar el código de catálogo del proveedor.')
            return
        }

        setModalSaving(true)
        setModalError('')

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
            const response = await fetch('/compras/proveedores/codigos/guardar', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken || '',
                },
                body: JSON.stringify({
                    inventory_product_id: selectedCatalogProduct.id,
                    supplier_id: selectedSupplier.id,
                    supplier_name: selectedSupplier.name,
                    supplier_sku: sku,
                    supplier_cost: modalSupplierCost !== '' ? parseFloat(modalSupplierCost) : null,
                }),
            })

            const data = await response.json()

            if (response.ok && data.ok) {
                setLinkModalOpen(false)
                setSelectedCatalogProduct(null)
                setModalSupplierSku('')
                setSearchCatalogQuery('')
                showToast(`✓ Producto vinculado con código '${sku}'`)
                // Recargar página para reflejar
                router.reload({ preserveScroll: true })
            } else {
                throw new Error(data.message || data.error || 'Error al vincular')
            }
        } catch (err) {
            setModalError(err.message || 'Error al guardar')
        } finally {
            setModalSaving(false)
        }
    }

    return (
        <AppShell>
            <Head title="Códigos de Catálogo de Proveedores | Compras" />

            {/* FLOATING TOAST */}
            {toastMessage && (
                <div className="fixed top-5 right-5 z-50 flex items-center gap-2 rounded-2xl bg-slate-900 px-4 py-3 text-xs font-bold text-white shadow-2xl transition-all animate-bounce">
                    <span>✨</span> {toastMessage}
                </div>
            )}

            <div className="space-y-6">
                {/* BREADCRUMB & HEADER */}
                <div>
                    <nav className="mb-2 flex items-center gap-1.5 text-xs text-slate-400">
                        <Link href="/compras/ordenes" className="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Compras
                        </Link>
                        <span>/</span>
                        <Link href="/compras/proveedores" className="hover:text-indigo-600 dark:hover:text-indigo-400">
                            Proveedores
                        </Link>
                        <span>/</span>
                        <span className="font-semibold text-slate-700 dark:text-slate-200">
                            Códigos de Catálogo
                        </span>
                    </nav>

                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div className="flex items-center gap-2.5">
                                <h1 className="text-2xl font-black tracking-tight text-slate-900 dark:text-white">
                                    Códigos de Catálogo de Proveedores
                                </h1>
                                <span className="rounded-full bg-purple-100 px-2.5 py-0.5 text-xs font-extrabold text-purple-700 dark:bg-purple-950/60 dark:text-purple-300">
                                    🏷️ Mapeo de Claves
                                </span>
                            </div>
                            <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                Asigna los códigos internos que usan tus proveedores para sus productos sin modificar tus SKUs internos del sistema.
                            </p>
                        </div>

                        <div className="flex items-center gap-2">
                            <Link
                                href="/compras/proveedores"
                                className="rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-xs transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                            >
                                ← Volver a Proveedores
                            </Link>
                            {selectedSupplier && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setSelectedCatalogProduct(null)
                                        setSearchCatalogQuery('')
                                        setCatalogResults([])
                                        setModalSupplierSku('')
                                        setModalSupplierCost('')
                                        setModalError('')
                                        setLinkModalOpen(true)
                                    }}
                                    className="inline-flex items-center gap-1.5 rounded-xl bg-purple-600 px-4 py-2 text-xs font-bold text-white shadow-xs transition hover:bg-purple-500 active:scale-[0.98]"
                                >
                                    <span>➕</span> Vincular Producto al Proveedor
                                </button>
                            )}
                        </div>
                    </div>
                </div>

                {/* INFORMATIVE BANNER ABOUT SKUs */}
                <div className="rounded-2xl border border-indigo-100 bg-indigo-50/60 p-4 dark:border-indigo-950/60 dark:bg-indigo-950/20">
                    <div className="flex items-start gap-3">
                        <span className="text-xl">💡</span>
                        <div className="text-xs text-indigo-950 dark:text-indigo-200">
                            <p className="font-bold">
                                Tus SKUs del sistema se mantienen intactos y protegidos
                            </p>
                            <p className="mt-0.5 text-indigo-800/80 dark:text-indigo-300/80 leading-relaxed">
                                El código de proveedor configurado aquí se utiliza exclusivamente en la generación de Órdenes de Compra, archivos Excel y reportes PDF enviados a tus proveedores. Tu <strong>SKU interno SBS</strong> y códigos de barras permanecen seguros para la sincronización con Mercado Libre, Amazon y Shopify.
                            </p>
                        </div>
                    </div>
                </div>

                {/* SUPPLIER SELECTOR TABS */}
                {suppliers.length > 0 && (
                    <div>
                        <span className="mb-2 block text-[11px] font-bold uppercase tracking-wider text-slate-400 dark:text-neutral-500">
                            Seleccionar Proveedor
                        </span>
                        <div className="flex flex-wrap gap-2">
                            {suppliers.map((sup) => {
                                const isSelected = selectedSupplier?.id === sup.id
                                return (
                                    <button
                                        key={sup.id}
                                        type="button"
                                        onClick={() => handleSupplierSelect(sup.id)}
                                        className={`flex items-center gap-2 rounded-2xl px-4 py-2 text-xs font-bold transition shadow-2xs ${
                                            isSelected
                                                ? 'bg-purple-600 text-white ring-2 ring-purple-600/30 shadow-md'
                                                : 'border border-slate-200 bg-white text-slate-700 hover:border-purple-300 hover:bg-purple-50/50 dark:border-neutral-800 dark:bg-neutral-900 dark:text-slate-300 dark:hover:border-purple-800'
                                        }`}
                                    >
                                        <span>🏢 {sup.name}</span>
                                        {sup.codes_count > 0 && (
                                            <span
                                                className={`rounded-full px-2 py-0.5 text-[10px] font-mono ${
                                                    isSelected
                                                        ? 'bg-white/20 text-white'
                                                        : 'bg-purple-100 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300'
                                                }`}
                                            >
                                                {sup.codes_count} códigos
                                            </span>
                                        )}
                                    </button>
                                )
                            })}
                        </div>
                    </div>
                )}

                {/* SELECTED SUPPLIER METRICS */}
                {selectedSupplier && (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                            <span className="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                Proveedor Activo
                            </span>
                            <div className="mt-1 flex items-baseline gap-1.5">
                                <span className="text-xl font-black text-slate-900 dark:text-white truncate">
                                    {selectedSupplier.name}
                                </span>
                            </div>
                            {selectedSupplier.brands?.length > 0 && (
                                <p className="mt-1 text-[11px] text-slate-400 truncate">
                                    Marcas: {selectedSupplier.brands.join(', ')}
                                </p>
                            )}
                        </div>

                        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                            <span className="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                                Total Productos
                            </span>
                            <div className="mt-1 flex items-baseline gap-1.5">
                                <span className="text-2xl font-black text-slate-900 dark:text-white">
                                    {summary.total || 0}
                                </span>
                                <span className="text-xs text-slate-400">en catálogo</span>
                            </div>
                        </div>

                        <div className="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 shadow-xs dark:border-emerald-950 dark:bg-emerald-950/20">
                            <span className="text-[11px] font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">
                                Con Código Asignado
                            </span>
                            <div className="mt-1 flex items-baseline gap-1.5">
                                <span className="text-2xl font-black text-emerald-700 dark:text-emerald-400">
                                    {summary.with_code || 0}
                                </span>
                                <span className="text-xs text-emerald-600/70">productos</span>
                            </div>
                        </div>

                        <div className="rounded-2xl border border-amber-200 bg-amber-50/50 p-4 shadow-xs dark:border-amber-950 dark:bg-amber-950/20">
                            <span className="text-[11px] font-bold uppercase tracking-wider text-amber-700 dark:text-amber-400">
                                Pendientes Sin Código
                            </span>
                            <div className="mt-1 flex items-baseline gap-1.5">
                                <span className="text-2xl font-black text-amber-700 dark:text-amber-400">
                                    {summary.without_code || 0}
                                </span>
                                <span className="text-xs text-amber-600/70">por codificar</span>
                            </div>
                        </div>
                    </div>
                )}

                {/* FILTERS & SEARCH TOOLBAR */}
                <div className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-xs sm:flex-row sm:items-center sm:justify-between dark:border-neutral-800 dark:bg-neutral-900">
                    <form onSubmit={handleSearchSubmit} className="flex flex-1 items-center gap-2">
                        <div className="relative w-full max-w-md">
                            <span className="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                                🔍
                            </span>
                            <input
                                type="text"
                                placeholder="Buscar por producto, SKU interno, código de barras o código proveedor..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="w-full rounded-xl border border-slate-200 bg-slate-50 py-2 pr-3 pl-9 text-xs text-slate-900 outline-none transition focus:border-purple-500 focus:bg-white focus:ring-1 focus:ring-purple-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white dark:focus:bg-neutral-900"
                            />
                        </div>
                        <button
                            type="submit"
                            className="rounded-xl bg-slate-800 px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-slate-700 dark:bg-neutral-700 dark:hover:bg-neutral-600"
                        >
                            Buscar
                        </button>
                    </form>

                    <div className="flex flex-wrap items-center gap-2">
                        {/* Brand Filter */}
                        {availableBrands.length > 0 && (
                            <div className="flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                                <span className="text-slate-400">🏷️</span>
                                <select
                                    value={selectedBrand}
                                    onChange={(e) => {
                                        setSelectedBrand(e.target.value)
                                        handleFilterChange({ brand: e.target.value })
                                    }}
                                    className="bg-transparent font-medium text-slate-700 outline-none dark:text-slate-200"
                                >
                                    <option value="">Todas las marcas</option>
                                    {availableBrands.map((b) => (
                                        <option key={b} value={b}>
                                            {b}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}

                        {/* Status Filter */}
                        <div className="flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs dark:border-neutral-700 dark:bg-neutral-800">
                            <span className="text-slate-400">⚡</span>
                            <select
                                value={selectedStatus}
                                onChange={(e) => {
                                    setSelectedStatus(e.target.value)
                                    handleFilterChange({ status: e.target.value })
                                }}
                                className="bg-transparent font-medium text-slate-700 outline-none dark:text-slate-200"
                            >
                                <option value="all">Todos los productos</option>
                                <option value="with_code">Con código de proveedor</option>
                                <option value="without_code">Sin código (Pendientes)</option>
                            </select>
                        </div>

                        {(search || selectedBrand || selectedStatus !== 'all') && (
                            <button
                                type="button"
                                onClick={() => {
                                    setSearch('')
                                    setSelectedBrand('')
                                    setSelectedStatus('all')
                                    handleFilterChange({ search: '', brand: '', status: 'all' })
                                }}
                                className="text-xs font-bold text-red-500 hover:underline"
                            >
                                Limpiar filtros
                            </button>
                        )}
                    </div>
                </div>

                {/* TABLE OF PRODUCTS AND SUPPLIER CODES */}
                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs dark:border-neutral-800 dark:bg-neutral-900">
                    {productItems.length === 0 ? (
                        <div className="p-12 text-center">
                            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-purple-50 text-2xl dark:bg-purple-950/40">
                                🏷️
                            </div>
                            <h3 className="mt-3 text-base font-bold text-slate-900 dark:text-white">
                                No se encontraron productos
                            </h3>
                            <p className="mx-auto mt-1 max-w-md text-xs text-slate-500 dark:text-slate-400">
                                {search || selectedBrand || selectedStatus !== 'all'
                                    ? 'No hay productos que coincidan con los filtros aplicados. Intenta con otros términos o limpia los filtros.'
                                    : 'No hay productos asociados a este proveedor en el catálogo. Usa el botón "Vincular Producto al Proveedor" para agregarlos.'}
                            </p>
                            {selectedSupplier && (
                                <div className="mt-5">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setSelectedCatalogProduct(null)
                                            setSearchCatalogQuery('')
                                            setCatalogResults([])
                                            setModalSupplierSku('')
                                            setModalSupplierCost('')
                                            setModalError('')
                                            setLinkModalOpen(true)
                                        }}
                                        className="inline-flex items-center gap-1.5 rounded-xl bg-purple-600 px-4 py-2 text-xs font-bold text-white shadow-xs hover:bg-purple-500"
                                    >
                                        <span>➕</span> Vincular Producto del Catálogo
                                    </button>
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-xs">
                                <thead>
                                    <tr className="border-b border-slate-200 bg-slate-50/80 font-bold uppercase tracking-wider text-slate-500 dark:border-neutral-800 dark:bg-neutral-800/60 dark:text-slate-400">
                                        <th className="p-3.5">SKU SBS (Interno)</th>
                                        <th className="p-3.5">Producto y Marca</th>
                                        <th className="p-3.5 min-w-[200px]">Código de Proveedor (Catálogo)</th>
                                        <th className="p-3.5 min-w-[130px] text-right">Costo Proveedor ($)</th>
                                        <th className="p-3.5 text-center">Estado</th>
                                        <th className="p-3.5 text-right">Acción</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100 dark:divide-neutral-800/60">
                                    {productItems.map((p) => {
                                        const state = rowStates[p.id] || {
                                            supplier_sku: p.supplier_sku || '',
                                            supplier_cost: p.supplier_cost || p.cost || '',
                                            isSaving: false,
                                            isSaved: false,
                                            isDirty: false,
                                        }

                                        const hasSavedCode = Boolean(p.supplier_sku)

                                        return (
                                            <tr
                                                key={p.id}
                                                className={`transition hover:bg-slate-50/70 dark:hover:bg-neutral-800/30 ${
                                                    state.isSaved ? 'bg-emerald-50/40 dark:bg-emerald-950/20' : ''
                                                }`}
                                            >
                                                {/* SKU SBS (Interno) & Barcode */}
                                                <td className="p-3.5 align-top">
                                                    <div className="flex items-center gap-1.5">
                                                        <span className="font-mono font-bold text-indigo-700 dark:text-indigo-400">
                                                            {p.sku}
                                                        </span>
                                                        <button
                                                            type="button"
                                                            onClick={() => {
                                                                navigator.clipboard.writeText(p.sku)
                                                                showToast(`Copiado: ${p.sku}`)
                                                            }}
                                                            className="text-slate-300 hover:text-slate-600 dark:text-neutral-600 dark:hover:text-slate-300"
                                                            title="Copiar SKU Interno"
                                                        >
                                                            📋
                                                        </button>
                                                    </div>
                                                    {p.barcode && (
                                                        <span className="mt-0.5 block font-mono text-[10px] text-slate-400">
                                                            UPC: {p.barcode}
                                                        </span>
                                                    )}
                                                    {p.barcode_secondary && (
                                                        <span className="mt-0.5 block font-mono text-[10px] text-slate-400">
                                                            UPC 2: {p.barcode_secondary}
                                                        </span>
                                                    )}
                                                </td>

                                                {/* Producto y Marca */}
                                                <td className="p-3.5 align-top max-w-[320px]">
                                                    <span className="font-semibold text-slate-900 dark:text-white block line-clamp-2">
                                                        {p.name}
                                                    </span>
                                                    <div className="mt-1 flex items-center gap-1.5">
                                                        {p.brand && (
                                                            <span className="rounded-md bg-purple-100 px-2 py-0.5 font-bold text-[10px] text-purple-800 dark:bg-purple-950/70 dark:text-purple-300">
                                                                {p.brand}
                                                            </span>
                                                        )}
                                                        {p.cost > 0 && (
                                                            <span className="text-[10px] text-slate-400 font-mono">
                                                                Costo base: ${Number(p.cost).toFixed(2)}
                                                            </span>
                                                        )}
                                                    </div>
                                                </td>

                                                {/* Editable Input: Código de Proveedor */}
                                                <td className="p-3.5 align-top">
                                                    <div className="relative">
                                                        <input
                                                            type="text"
                                                            placeholder="ej. 60515, LOR-102..."
                                                            value={state.supplier_sku}
                                                            onChange={(e) => handleInputChange(p.id, 'supplier_sku', e.target.value)}
                                                            onKeyDown={(e) => {
                                                                if (e.key === 'Enter') {
                                                                    e.preventDefault()
                                                                    handleSaveRow(p)
                                                                }
                                                            }}
                                                            className={`w-full rounded-xl border px-3 py-1.5 text-xs font-mono font-bold outline-none transition uppercase ${
                                                                state.isDirty
                                                                    ? 'border-amber-400 bg-amber-50/40 text-amber-950 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-200'
                                                                    : state.supplier_sku
                                                                    ? 'border-purple-300 bg-purple-50/30 text-purple-950 focus:border-purple-500 focus:ring-1 focus:ring-purple-500 dark:border-purple-800 dark:bg-purple-950/20 dark:text-purple-200'
                                                                    : 'border-slate-200 bg-slate-50 text-slate-900 focus:border-indigo-500 focus:bg-white focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white'
                                                            }`}
                                                        />
                                                    </div>
                                                </td>

                                                {/* Editable Input: Costo Proveedor */}
                                                <td className="p-3.5 align-top text-right">
                                                    <div className="relative inline-block w-28">
                                                        <span className="absolute inset-y-0 left-0 flex items-center pl-2 text-slate-400 text-xs">
                                                            $
                                                        </span>
                                                        <input
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                            placeholder="0.00"
                                                            value={state.supplier_cost}
                                                            onChange={(e) => handleInputChange(p.id, 'supplier_cost', e.target.value)}
                                                            onKeyDown={(e) => {
                                                                if (e.key === 'Enter') {
                                                                    e.preventDefault()
                                                                    handleSaveRow(p)
                                                                }
                                                            }}
                                                            className="w-full rounded-xl border border-slate-200 bg-slate-50 py-1.5 pr-2 pl-6 text-right text-xs font-mono font-bold text-slate-800 outline-none transition focus:border-purple-500 focus:bg-white focus:ring-1 focus:ring-purple-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                                        />
                                                    </div>
                                                </td>

                                                {/* Estado */}
                                                <td className="p-3.5 align-top text-center">
                                                    {state.isSaving ? (
                                                        <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-bold text-indigo-700 animate-pulse dark:bg-indigo-950/80 dark:text-indigo-300">
                                                            Guardando...
                                                        </span>
                                                    ) : state.isSaved ? (
                                                        <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-800 dark:bg-emerald-950/80 dark:text-emerald-300">
                                                            ✓ Guardado
                                                        </span>
                                                    ) : state.isDirty ? (
                                                        <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-800 dark:bg-amber-950/80 dark:text-amber-300">
                                                            ● Modificado
                                                        </span>
                                                    ) : hasSavedCode ? (
                                                        <span className="rounded-full bg-purple-100 px-2 py-0.5 text-[10px] font-extrabold text-purple-800 dark:bg-purple-950/80 dark:text-purple-300">
                                                            Asignado
                                                        </span>
                                                    ) : (
                                                        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-neutral-800 dark:text-slate-400">
                                                            Sin código
                                                        </span>
                                                    )}
                                                </td>

                                                {/* Acciones */}
                                                <td className="p-3.5 align-top text-right">
                                                    <div className="flex items-center justify-end gap-1.5">
                                                        <button
                                                            type="button"
                                                            onClick={() => handleSaveRow(p)}
                                                            disabled={state.isSaving || (!state.isDirty && hasSavedCode)}
                                                            className={`rounded-xl px-3 py-1.5 text-xs font-bold transition shadow-2xs ${
                                                                state.isDirty
                                                                    ? 'bg-purple-600 text-white hover:bg-purple-500 active:scale-95'
                                                                    : hasSavedCode
                                                                    ? 'border border-slate-200 bg-white text-slate-400 cursor-default dark:border-neutral-700 dark:bg-neutral-800'
                                                                    : 'bg-indigo-600 text-white hover:bg-indigo-500 active:scale-95'
                                                            }`}
                                                            title="Guardar código de proveedor"
                                                        >
                                                            {state.isSaving ? '...' : 'Guardar'}
                                                        </button>

                                                        {hasSavedCode && (
                                                            <button
                                                                type="button"
                                                                onClick={() => handleDeleteCode(p)}
                                                                className="rounded-xl border border-rose-200 p-1.5 text-rose-500 hover:bg-rose-50 hover:text-rose-700 dark:border-rose-900/50 dark:hover:bg-rose-950/50"
                                                                title="Quitar / desvincular código de este proveedor"
                                                            >
                                                                ✕
                                                            </button>
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        )
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {/* PAGINATION */}
                    {products.links && products.links.length > 3 && (
                        <div className="flex items-center justify-between border-t border-slate-200 bg-slate-50/50 px-4 py-3 dark:border-neutral-800 dark:bg-neutral-900/50">
                            <span className="text-xs text-slate-500 dark:text-slate-400">
                                Mostrando {products.from || 0} a {products.to || 0} de {products.total || 0} productos
                            </span>
                            <div className="flex items-center gap-1">
                                {products.links.map((link, idx) => (
                                    <Link
                                        key={idx}
                                        href={link.url || '#'}
                                        preserveScroll
                                        preserveState
                                        className={`rounded-lg px-2.5 py-1 text-xs font-semibold transition ${
                                            link.active
                                                ? 'bg-purple-600 text-white'
                                                : link.url
                                                ? 'bg-white text-slate-700 border border-slate-200 hover:bg-slate-100 dark:bg-neutral-800 dark:border-neutral-700 dark:text-slate-300'
                                                : 'text-slate-300 dark:text-neutral-600 cursor-not-allowed'
                                        }`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                {/* MODAL VINCULAR PRODUCTO DEL CATÁLOGO AL PROVEEDOR */}
                {linkModalOpen && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-xs">
                        <div className="relative w-full max-w-xl max-h-[90vh] overflow-y-auto rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="flex items-center justify-between border-b border-slate-100 pb-4 dark:border-neutral-800">
                                <div>
                                    <h2 className="text-lg font-black text-slate-900 dark:text-white">
                                        Vincular Producto a {selectedSupplier?.name}
                                    </h2>
                                    <p className="text-xs text-slate-500 dark:text-slate-400">
                                        Busca cualquier producto del inventario para asignarle un código de catálogo de este proveedor.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setLinkModalOpen(false)}
                                    className="rounded-full p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-neutral-800"
                                >
                                    ✕
                                </button>
                            </div>

                            <div className="mt-4 space-y-4">
                                {/* Search box for catalog */}
                                <div>
                                    <label className="block text-xs font-bold text-slate-700 dark:text-slate-300 mb-1">
                                        1. Buscar Producto en Inventario SBS
                                    </label>
                                    <div className="relative">
                                        <span className="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                                            🔍
                                        </span>
                                        <input
                                            type="text"
                                            placeholder="Escribe nombre, SKU interno o código de barras..."
                                            value={searchCatalogQuery}
                                            onChange={(e) => setSearchCatalogQuery(e.target.value)}
                                            className="w-full rounded-xl border border-slate-300 bg-slate-50 py-2 pr-3 pl-9 text-xs text-slate-900 outline-none focus:border-purple-500 focus:bg-white focus:ring-1 focus:ring-purple-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                        />
                                        {searchingCatalog && (
                                            <span className="absolute inset-y-0 right-0 flex items-center pr-3 text-xs text-slate-400 animate-pulse">
                                                Buscando...
                                            </span>
                                        )}
                                    </div>
                                </div>

                                {/* Catalog search results list */}
                                {catalogResults.length > 0 && !selectedCatalogProduct && (
                                    <div className="max-h-52 overflow-y-auto rounded-xl border border-slate-200 divide-y divide-slate-100 dark:border-neutral-800 dark:divide-neutral-800">
                                        {catalogResults.map((p) => (
                                            <button
                                                key={p.id}
                                                type="button"
                                                onClick={() => handleSelectCatalogProduct(p)}
                                                className="w-full text-left p-3 text-xs transition hover:bg-purple-50/70 dark:hover:bg-purple-950/30 flex items-center justify-between"
                                            >
                                                <div>
                                                    <span className="font-bold text-slate-900 dark:text-white block">
                                                        {p.name}
                                                    </span>
                                                    <div className="mt-0.5 flex items-center gap-2 text-[10px] text-slate-400">
                                                        <span className="font-mono text-indigo-600 font-bold dark:text-indigo-400">
                                                            SKU: {p.sku}
                                                        </span>
                                                        {p.barcode && <span>UPC: {p.barcode}</span>}
                                                        {p.brand && <span>Marca: {p.brand}</span>}
                                                    </div>
                                                </div>
                                                <span className="rounded-lg bg-purple-100 px-2.5 py-1 text-[11px] font-bold text-purple-700 dark:bg-purple-950/70 dark:text-purple-300">
                                                    Seleccionar →
                                                </span>
                                            </button>
                                        ))}
                                    </div>
                                )}

                                {/* Selected product card */}
                                {selectedCatalogProduct && (
                                    <div className="rounded-2xl border border-purple-200 bg-purple-50/50 p-4 dark:border-purple-900/60 dark:bg-purple-950/20">
                                        <div className="flex items-center justify-between">
                                            <span className="text-[10px] font-bold uppercase tracking-wider text-purple-700 dark:text-purple-400">
                                                Producto Seleccionado
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() => setSelectedCatalogProduct(null)}
                                                className="text-xs text-purple-600 font-semibold hover:underline"
                                            >
                                                Cambiar
                                            </button>
                                        </div>
                                        <h4 className="mt-1 text-sm font-black text-slate-900 dark:text-white">
                                            {selectedCatalogProduct.name}
                                        </h4>
                                        <div className="mt-1 flex items-center gap-3 text-xs text-slate-500 font-mono">
                                            <span>SKU: {selectedCatalogProduct.sku}</span>
                                            {selectedCatalogProduct.barcode && <span>UPC: {selectedCatalogProduct.barcode}</span>}
                                            {selectedCatalogProduct.brand && <span>Marca: {selectedCatalogProduct.brand}</span>}
                                        </div>

                                        <form onSubmit={handleSaveLinkedProduct} className="mt-4 space-y-3 pt-3 border-t border-purple-200/60 dark:border-purple-900/60">
                                            <div>
                                                <label className="block text-xs font-bold text-slate-800 dark:text-slate-200">
                                                    2. Código de Catálogo del Proveedor <span className="text-red-500">*</span>
                                                </label>
                                                <input
                                                    type="text"
                                                    required
                                                    autoFocus
                                                    placeholder="ej. 60515, LOR-992..."
                                                    value={modalSupplierSku}
                                                    onChange={(e) => setModalSupplierSku(e.target.value.toUpperCase())}
                                                    className="mt-1 w-full rounded-xl border border-purple-300 bg-white px-3 py-2 text-xs font-mono font-bold text-slate-900 outline-none uppercase focus:border-purple-600 focus:ring-1 focus:ring-purple-600 dark:border-purple-800 dark:bg-neutral-800 dark:text-white"
                                                />
                                            </div>

                                            <div>
                                                <label className="block text-xs font-bold text-slate-800 dark:text-slate-200">
                                                    Costo de Compra Proveedor ($)
                                                </label>
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    placeholder="0.00"
                                                    value={modalSupplierCost}
                                                    onChange={(e) => setModalSupplierCost(e.target.value)}
                                                    className="mt-1 w-full rounded-xl border border-purple-300 bg-white px-3 py-2 text-xs font-mono font-bold text-slate-900 outline-none focus:border-purple-600 focus:ring-1 focus:ring-purple-600 dark:border-purple-800 dark:bg-neutral-800 dark:text-white"
                                                />
                                            </div>

                                            {modalError && (
                                                <p className="text-xs text-red-500 font-medium">{modalError}</p>
                                            )}

                                            <div className="flex items-center justify-end gap-2 pt-2">
                                                <button
                                                    type="button"
                                                    onClick={() => setLinkModalOpen(false)}
                                                    className="rounded-xl border border-slate-300 px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                                                >
                                                    Cancelar
                                                </button>
                                                <button
                                                    type="submit"
                                                    disabled={modalSaving}
                                                    className="rounded-xl bg-purple-600 px-4 py-2 text-xs font-bold text-white shadow-xs hover:bg-purple-500 active:scale-95"
                                                >
                                                    {modalSaving ? 'Guardando...' : 'Guardar y Vincular'}
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AppShell>
    )
}
