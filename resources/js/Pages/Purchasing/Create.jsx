import { useState, useMemo, useEffect } from 'react'
import { Head, Link, useForm } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'
import axios from 'axios'

function formatCurrency(val) {
    return new Intl.NumberFormat('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    }).format(val || 0)
}

export default function PurchasingCreate({
    locations = [],
    products = [],
    registeredSuppliers = [],
    prefillSupplier = '',
    prefillBrand = '',
}) {
    const defaultLocationId = locations[0]?.id || ''

    const { data, setData, post, processing, errors } = useForm({
        supplier_name: prefillSupplier || '',
        brand: prefillBrand || '',
        inventory_location_id: '',
        expected_delivery_date: '',
        supplier_quote_reference: '',
        notes: '',
        tax_amount: 0,
        shipping_cost: 0,
        items: [],
    })

    // Search product modal/dropdown state
    const [productSearch, setProductSearch] = useState('')
    const [selectedBrandFilter, setSelectedBrandFilter] = useState(prefillBrand || '')
    const [serverSearchResults, setServerSearchResults] = useState([])
    const [isSearchingServer, setIsSearchingServer] = useState(false)

    // Quick create product modal state
    const [quickModalOpen, setQuickModalOpen] = useState(false)
    const [quickSku, setQuickSku] = useState('')
    const [quickBarcode, setQuickBarcode] = useState('')
    const [quickName, setQuickName] = useState('')
    const [quickBrand, setQuickBrand] = useState('')
    const [quickCost, setQuickCost] = useState(0)
    const [quickQty, setQuickQty] = useState(1)
    const [quickSaving, setQuickSaving] = useState(false)
    const [quickError, setQuickError] = useState(null)

    // Live backend search with debounce
    useEffect(() => {
        const clean = productSearch.trim()
        if (clean.length < 2) {
            setServerSearchResults([])
            return
        }

        const timer = setTimeout(() => {
            setIsSearchingServer(true)
            axios.get('/compras/ordenes/buscar-productos', { params: { q: clean } })
                .then((res) => {
                    setServerSearchResults(res.data?.data || [])
                })
                .catch(() => {})
                .finally(() => setIsSearchingServer(false))
        }, 200)

        return () => clearTimeout(timer)
    }, [productSearch])

    // Helper to get supplier SKU for a product matching current supplier
    const getSupplierSkuForProduct = (product, supplierName = data.supplier_name) => {
        if (!product?.supplier_products || !Array.isArray(product.supplier_products) || product.supplier_products.length === 0) {
            return ''
        }
        const cleanSup = (supplierName || '').toLowerCase().trim()
        if (cleanSup) {
            const match = product.supplier_products.find((sp) =>
                (sp.supplier_name && sp.supplier_name.toLowerCase().trim() === cleanSup) ||
                (sp.supplier_name && cleanSup.includes(sp.supplier_name.toLowerCase().trim())) ||
                (sp.supplier_name && sp.supplier_name.toLowerCase().trim().includes(cleanSup))
            )
            if (match) return match.supplier_sku
        }
        const primary = product.supplier_products.find((sp) => sp.is_primary)
        return primary?.supplier_sku || product.supplier_products[0]?.supplier_sku || ''
    }

    // Filter available local products with barcode & SKU normalization
    const availableProducts = useMemo(() => {
        const s = productSearch.toLowerCase().trim()
        const sStripped = s.replace(/^0+/, '')

        return products.filter((p) => {
            const matchesBrand = !selectedBrandFilter || (p.brand && p.brand.toLowerCase() === selectedBrandFilter.toLowerCase())
            const matchesBarcode = (p.barcode && (p.barcode.toLowerCase().includes(s) || (sStripped && p.barcode.toLowerCase().includes(sStripped)))) ||
                                   (p.barcode_secondary && (p.barcode_secondary.toLowerCase().includes(s) || (sStripped && p.barcode_secondary.toLowerCase().includes(sStripped))))
            const matchesSku = p.sku && (p.sku.toLowerCase().includes(s) || (sStripped && p.sku.toLowerCase().includes(sStripped)))
            const matchesName = p.name && p.name.toLowerCase().includes(s)
            const matchesBrandName = p.brand && p.brand.toLowerCase().includes(s)
            const matchesSupplierSku = p.supplier_products && p.supplier_products.some((sp) => sp.supplier_sku && (sp.supplier_sku.toLowerCase().includes(s) || (sStripped && sp.supplier_sku.toLowerCase().includes(sStripped))))

            const isCodeMatch = matchesBarcode || matchesSku || matchesSupplierSku
            if (s && isCodeMatch) {
                return true // Si coincide el código de barras o SKU exacto, se muestra sin importar el filtro de marca
            }

            const matchesSearch = !s || isCodeMatch || matchesName || matchesBrandName
            return matchesBrand && matchesSearch
        })
    }, [products, productSearch, selectedBrandFilter])

    // Merge local and server search results deduplicated by ID
    const combinedSearchResults = useMemo(() => {
        const map = new Map()
        availableProducts.forEach((p) => map.set(p.id, p))
        serverSearchResults.forEach((p) => {
            if (!map.has(p.id)) {
                map.set(p.id, p)
            }
        })
        return Array.from(map.values())
    }, [availableProducts, serverSearchResults])

    const handleSupplierSelect = (val) => {
        setData('supplier_name', val)
        const match = registeredSuppliers.find((s) => s.name.toLowerCase() === val.toLowerCase().trim())
        const updates = { supplier_name: val }

        if (match) {
            if (match.brands && match.brands.length > 0 && !data.brand) {
                const primaryBrand = match.brands.find((b) => b.is_primary)?.brand || match.brands[0]?.brand
                if (primaryBrand) {
                    updates.brand = primaryBrand
                    setSelectedBrandFilter(primaryBrand)
                }
            }
            if (match.lead_time_days > 0 && !data.expected_delivery_date) {
                const date = new Date()
                date.setDate(date.getDate() + match.lead_time_days)
                updates.expected_delivery_date = date.toISOString().split('T')[0]
            }
        }

        // Auto-refresh supplier_sku on added items if empty
        if (data.items.length > 0) {
            updates.items = data.items.map((item) => {
                if (item.supplier_sku) return item
                const prod = products.find((p) => p.id === item.inventory_product_id)
                const newSku = getSupplierSkuForProduct(prod, val)
                return newSku ? { ...item, supplier_sku: newSku } : item
            })
        }

        setData((prev) => ({ ...prev, ...updates }))
    }

    const distinctBrands = useMemo(() => {
        const set = new Set()
        products.forEach((p) => {
            if (p.brand) set.add(p.brand)
        })
        return Array.from(set).sort()
    }, [products])

    const addProductItem = (product, initialQty = 1, initialCost = null) => {
        const qty = Math.max(1, parseInt(initialQty) || 1)
        // If already added, increment quantity
        const existingIdx = data.items.findIndex((item) => item.inventory_product_id === product.id)
        if (existingIdx !== -1) {
            const updated = [...data.items]
            updated[existingIdx].quantity_ordered += qty
            setData('items', updated)
            return
        }

        const supplierSku = getSupplierSkuForProduct(product)
        const unitCost = initialCost !== null ? Math.max(0, parseFloat(initialCost) || 0) : (parseFloat(product.cost) || 0)

        const newItem = {
            inventory_product_id: product.id,
            sku: product.sku,
            barcode: product.barcode,
            supplier_sku: supplierSku,
            name: product.name,
            brand: product.brand,
            quantity_ordered: qty,
            unit_cost: unitCost,
            primary_location_id: product.primary_location_id || product.primary_location?.id || null,
            primary_location_code: product.primary_location?.code || null,
            primary_location_name: product.primary_location?.name || null,
        }

        setData('items', [...data.items, newItem])
    }

    const handleOpenQuickCreate = (code = '') => {
        const cleanCode = (code || productSearch || '').trim()
        setQuickSku(cleanCode)
        setQuickBarcode(cleanCode)
        setQuickName('')
        setQuickBrand(data.brand || selectedBrandFilter || (data.supplier_name === 'Compras USA' ? 'Spring Valley' : ''))
        setQuickCost(0)
        setQuickQty(1)
        setQuickError(null)
        setQuickModalOpen(true)
    }

    const handleSaveQuickCreate = async (e) => {
        e.preventDefault()
        if (!quickSku.trim() || !quickName.trim()) {
            setQuickError('El SKU/Código y el nombre del producto son obligatorios.')
            return
        }

        setQuickSaving(true)
        setQuickError(null)

        try {
            const res = await axios.post('/compras/ordenes/producto-rapido', {
                sku: quickSku.trim(),
                barcode: quickBarcode.trim() || quickSku.trim(),
                name: quickName.trim(),
                brand: quickBrand.trim() || data.brand || 'General',
                cost: parseFloat(quickCost) || 0,
                supplier_name: data.supplier_name || 'Compras USA',
                primary_location_id: data.inventory_location_id || defaultLocationId || null,
            })

            if (res.data?.ok && res.data?.product) {
                addProductItem(res.data.product, quickQty, quickCost)
                setQuickModalOpen(false)
                setProductSearch('')
            } else {
                setQuickError(res.data?.error || 'No se pudo guardar el producto.')
            }
        } catch (err) {
            setQuickError(err.response?.data?.message || err.response?.data?.error || 'Error al registrar el producto.')
        } finally {
            setQuickSaving(false)
        }
    }

    const updateItemQuantity = (index, qty) => {
        const updated = [...data.items]
        updated[index].quantity_ordered = Math.max(1, parseInt(qty) || 1)
        setData('items', updated)
    }

    const updateItemSupplierSku = (index, sku) => {
        const updated = [...data.items]
        updated[index].supplier_sku = sku
        setData('items', updated)
    }

    const updateItemCost = (index, cost) => {
        const updated = [...data.items]
        updated[index].unit_cost = Math.max(0, parseFloat(cost) || 0)
        setData('items', updated)
    }

    const removeItem = (index) => {
        const updated = data.items.filter((_, i) => i !== index)
        setData('items', updated)
    }

    const subtotal = useMemo(() => {
        return data.items.reduce((acc, item) => {
            return acc + (item.quantity_ordered * (parseFloat(item.unit_cost) || 0))
        }, 0)
    }, [data.items])

    const totalCost = useMemo(() => {
        const tax = parseFloat(data.tax_amount) || 0
        const shipping = parseFloat(data.shipping_cost) || 0
        return subtotal + tax + shipping
    }, [subtotal, data.tax_amount, data.shipping_cost])

    const handleSubmit = (e) => {
        e.preventDefault()
        post('/compras/ordenes')
    }

    return (
        <AppShell title="Nueva Orden de Compra">
            <Head title="Nueva Orden de Compra" />

            <div className="mx-auto max-w-6xl space-y-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <Link href="/compras/ordenes" className="hover:text-indigo-600 dark:hover:text-indigo-400">
                                Órdenes de Compra
                            </Link>
                            <span>/</span>
                            <span className="text-slate-700 dark:text-slate-300">Nueva Orden</span>
                        </div>
                        <h1 className="mt-1 text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                            Crear Orden de Compra (Borrador)
                        </h1>
                    </div>

                    <Link
                        href="/compras/ordenes"
                        className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200 dark:hover:bg-neutral-700"
                    >
                        Cancelar
                    </Link>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* General Information Card */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <h2 className="text-base font-semibold text-slate-900 dark:text-white">
                            Información del Proveedor y Almacén
                        </h2>
                        <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                            Datos del fabricante o distribuidor, almacén físico de entrega y fecha estimada.
                        </p>

                        <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                    Proveedor / Fabricante *
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={data.supplier_name}
                                    onChange={(e) => handleSupplierSelect(e.target.value)}
                                    placeholder="Selecciona o escribe un proveedor..."
                                    list="registered-suppliers-list"
                                    className="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                />
                                <datalist id="registered-suppliers-list">
                                    {registeredSuppliers.map((s) => (
                                        <option key={s.id} value={s.name}>
                                            {s.contact_name ? `${s.contact_name} - ` : ''}{s.lead_time_days ? `${s.lead_time_days}d entrega` : ''}
                                        </option>
                                    ))}
                                </datalist>
                                {errors.supplier_name && (
                                    <p className="mt-1 text-xs text-rose-500">{errors.supplier_name}</p>
                                )}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                    Marca Primaria
                                </label>
                                <input
                                    type="text"
                                    value={data.brand}
                                    onChange={(e) => setData('brand', e.target.value)}
                                    placeholder="Ej. MICHELIN, BFGOODRICH..."
                                    className="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                />
                                {errors.brand && (
                                    <p className="mt-1 text-xs text-rose-500">{errors.brand}</p>
                                )}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                    Almacén / Ubicación de Respaldo
                                </label>
                                <select
                                    value={data.inventory_location_id}
                                    onChange={(e) => setData('inventory_location_id', e.target.value)}
                                    className="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                >
                                    <option value="">📍 Automático por producto (Catálogo Maestro)</option>
                                    {locations.map((loc) => (
                                        <option key={loc.id} value={loc.id}>
                                            {loc.name} ({loc.code})
                                        </option>
                                    ))}
                                </select>
                                <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                                    💡 Cada producto se enviará directamente a su estante oficial asignado. Si algún producto no tiene estante asignado, irá a este almacén de respaldo.
                                </p>
                                {errors.inventory_location_id && (
                                    <p className="mt-1 text-xs text-rose-500">{errors.inventory_location_id}</p>
                                )}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                    Fecha Estimada de Entrega
                                </label>
                                <input
                                    type="date"
                                    value={data.expected_delivery_date}
                                    onChange={(e) => setData('expected_delivery_date', e.target.value)}
                                    className="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                />
                                {errors.expected_delivery_date && (
                                    <p className="mt-1 text-xs text-rose-500">{errors.expected_delivery_date}</p>
                                )}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                    No. Cotización / Referencia Proveedor
                                </label>
                                <input
                                    type="text"
                                    value={data.supplier_quote_reference}
                                    onChange={(e) => setData('supplier_quote_reference', e.target.value)}
                                    placeholder="Ej. COT-2026-8812"
                                    className="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                    Notas o Instrucciones de Envío
                                </label>
                                <input
                                    type="text"
                                    value={data.notes}
                                    onChange={(e) => setData('notes', e.target.value)}
                                    placeholder="Condiciones de pago, transporte, etc."
                                    className="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                />
                            </div>
                        </div>
                    </div>

                    {/* Product Selection & Items Table Card */}
                    <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 className="text-base font-semibold text-slate-900 dark:text-white">
                                    Productos en la Orden ({data.items.length})
                                </h2>
                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                    Busque y agregue los códigos/SKUs a ordenar junto con las cantidades y costos negociados.
                                </p>
                            </div>
                        </div>

                        {/* Search and Quick Add Bar */}
                        <div className="mt-4 rounded-xl border border-dashed border-slate-300 bg-slate-50/75 p-3.5 dark:border-neutral-700 dark:bg-neutral-950">
                            <div className="flex flex-wrap items-center gap-3">
                                <div className="relative min-w-[240px] flex-1">
                                    <input
                                        type="text"
                                        value={productSearch}
                                        onChange={(e) => setProductSearch(e.target.value)}
                                        placeholder="Buscar producto por SKU o nombre para agregar..."
                                        className="w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2 pl-9 text-sm text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                    />
                                    <svg
                                        className="absolute left-3 top-2.5 h-4 w-4 text-slate-400"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                        stroke="currentColor"
                                    >
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </div>

                                <select
                                    value={selectedBrandFilter}
                                    onChange={(e) => setSelectedBrandFilter(e.target.value)}
                                    className="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                >
                                    <option value="">Todas las Marcas</option>
                                    {distinctBrands.map((b) => (
                                        <option key={b} value={b}>{b}</option>
                                    ))}
                                </select>

                                <button
                                    type="button"
                                    onClick={() => handleOpenQuickCreate('')}
                                    className="inline-flex items-center gap-1.5 rounded-xl border border-emerald-300 bg-emerald-50 px-3.5 py-2 text-xs font-bold text-emerald-800 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300"
                                >
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                                    </svg>
                                    + Producto Manual
                                </button>
                            </div>

                            {/* Dropdown search results */}
                            {productSearch.trim().length > 0 && (
                                <div className="mt-2 max-h-72 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-xl dark:border-neutral-700 dark:bg-neutral-900">
                                    {isSearchingServer && (
                                        <div className="border-b border-slate-100 p-2 text-center text-xs text-indigo-600 dark:border-neutral-800 dark:text-indigo-400">
                                            Buscando en catálogo del almacén...
                                        </div>
                                    )}

                                    {combinedSearchResults.length === 0 ? (
                                        <div className="p-4 text-center">
                                            <p className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                                                No se encontraron productos con "{productSearch}"
                                            </p>
                                            <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                                                Puedes registrarlo inmediatamente con este código y agregarlo a tu orden de compra.
                                            </p>
                                            <button
                                                type="button"
                                                onClick={() => handleOpenQuickCreate(productSearch)}
                                                className="mt-3 inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-indigo-700"
                                            >
                                                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                                                </svg>
                                                ➕ Registrar y agregar "{productSearch}" a la orden
                                            </button>
                                        </div>
                                    ) : (
                                        <div>
                                            <div className="divide-y divide-slate-100 dark:divide-neutral-800">
                                                {combinedSearchResults.slice(0, 20).map((prod) => (
                                                    <div
                                                        key={prod.id}
                                                        onClick={() => {
                                                            addProductItem(prod)
                                                            setProductSearch('')
                                                        }}
                                                        className="flex cursor-pointer items-center justify-between p-2.5 transition hover:bg-indigo-50 dark:hover:bg-indigo-950/40"
                                                    >
                                                        <div>
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                <span className="font-bold text-slate-900 dark:text-white">
                                                                    {prod.sku}
                                                                </span>
                                                                {prod.barcode && prod.barcode !== prod.sku && (
                                                                    <span className="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-mono text-slate-600 dark:bg-neutral-800 dark:text-slate-300">
                                                                        UPC: {prod.barcode}
                                                                    </span>
                                                                )}
                                                                {getSupplierSkuForProduct(prod) && (
                                                                    <span className="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-mono font-bold text-indigo-700 border border-indigo-200 dark:bg-indigo-950/60 dark:text-indigo-300 dark:border-indigo-800">
                                                                        Cód: {getSupplierSkuForProduct(prod)}
                                                                    </span>
                                                                )}
                                                                <span className="text-xs font-medium text-slate-500 dark:text-slate-400">
                                                                    [{prod.brand || 'S/M'}]
                                                                </span>
                                                                {prod.primary_location?.code ? (
                                                                    <span className="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold text-emerald-700 border border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800">
                                                                        📍 {prod.primary_location.code}
                                                                    </span>
                                                                ) : (
                                                                    <span className="text-[10px] text-slate-400">
                                                                        📍 Sin estante
                                                                    </span>
                                                                )}
                                                            </div>
                                                            <p className="text-xs text-slate-600 dark:text-slate-300">
                                                                {prod.name}
                                                            </p>
                                                        </div>
                                                        <div className="flex items-center gap-3">
                                                            <span className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                                                                Costo: {formatCurrency(prod.cost)}
                                                            </span>
                                                            <button
                                                                type="button"
                                                                className="rounded-lg bg-indigo-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-indigo-700"
                                                            >
                                                                + Agregar
                                                            </button>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>

                                            <div className="border-t border-slate-100 bg-slate-50 p-2.5 text-center dark:border-neutral-800 dark:bg-neutral-800/40">
                                                <button
                                                    type="button"
                                                    onClick={() => handleOpenQuickCreate(productSearch)}
                                                    className="text-xs font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400"
                                                >
                                                    ➕ ¿No encuentras tu presentación? Registrar nuevo producto con código '{productSearch}'
                                                </button>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>

                        {/* Items Table */}
                        <div className="mt-4 overflow-x-auto">
                            {data.items.length === 0 ? (
                                <div className="rounded-xl border border-dashed border-slate-300 py-10 text-center dark:border-neutral-700">
                                    <p className="text-sm font-medium text-slate-500 dark:text-slate-400">
                                        No has agregado ningún producto a esta orden todavía.
                                    </p>
                                    <p className="text-xs text-slate-400 dark:text-slate-500">
                                        Utiliza el buscador superior para agregar SKUs a la orden de compra.
                                    </p>
                                </div>
                            ) : (
                                <table className="w-full text-left text-sm">
                                    <thead className="border-b border-slate-200 bg-slate-50/75 text-xs font-semibold uppercase tracking-wider text-slate-600 dark:border-neutral-800 dark:bg-neutral-950 dark:text-slate-400">
                                        <tr>
                                            <th className="px-3 py-2.5 w-44 text-indigo-700 dark:text-indigo-400">Cód. Proveedor</th>
                                            <th className="px-3 py-2.5">SKU / Producto</th>
                                            <th className="px-3 py-2.5 w-32">Cantidad</th>
                                            <th className="px-3 py-2.5 w-36">Costo Unit. ($)</th>
                                            <th className="px-3 py-2.5 text-right w-36">Subtotal</th>
                                            <th className="px-3 py-2.5 text-center w-16">Quitar</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                        {data.items.map((item, index) => {
                                            const lineTotal = item.quantity_ordered * (parseFloat(item.unit_cost) || 0)

                                            return (
                                                <tr key={item.inventory_product_id} className="hover:bg-slate-50/50 dark:hover:bg-neutral-800/40">
                                                    <td className="px-3 py-3">
                                                        <input
                                                            type="text"
                                                            value={item.supplier_sku || ''}
                                                            onChange={(e) => updateItemSupplierSku(index, e.target.value)}
                                                            placeholder="Cód. catálogo..."
                                                            title="Código con el que tu proveedor identifica este producto en su lista"
                                                            className="w-36 rounded-lg border border-indigo-200 bg-indigo-50/40 px-2.5 py-1.5 font-mono text-xs font-bold text-indigo-900 focus:border-indigo-500 focus:outline-none dark:border-indigo-900/50 dark:bg-indigo-950/30 dark:text-indigo-200"
                                                        />
                                                    </td>

                                                    <td className="px-3 py-3">
                                                        <div className="font-semibold text-slate-900 dark:text-white">
                                                            {item.sku}
                                                        </div>
                                                        <div className="text-xs text-slate-500 dark:text-slate-400">
                                                            {item.name} {item.brand && `• ${item.brand}`}
                                                        </div>
                                                        <div className="mt-1">
                                                            {item.primary_location_code ? (
                                                                <span className="inline-flex items-center gap-1 rounded bg-emerald-50 px-1.5 py-0.5 text-[11px] font-bold text-emerald-700 border border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-800">
                                                                    📍 Destino: {item.primary_location_code}
                                                                </span>
                                                            ) : (
                                                                <span className="inline-flex items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600 dark:bg-neutral-800 dark:text-slate-400">
                                                                    📍 Destino: Almacén de respaldo
                                                                </span>
                                                            )}
                                                        </div>
                                                    </td>

                                                    <td className="px-3 py-3">
                                                        <input
                                                            type="number"
                                                            min={1}
                                                            value={item.quantity_ordered}
                                                            onChange={(e) => updateItemQuantity(index, e.target.value)}
                                                            className="w-24 rounded-lg border border-slate-300 px-2.5 py-1.5 text-center text-sm font-bold text-slate-900 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                                        />
                                                    </td>

                                                    <td className="px-3 py-3">
                                                        <input
                                                            type="number"
                                                            step="0.01"
                                                            min={0}
                                                            value={item.unit_cost}
                                                            onChange={(e) => updateItemCost(index, e.target.value)}
                                                            className="w-28 rounded-lg border border-slate-300 px-2.5 py-1.5 text-right text-sm font-semibold text-slate-900 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                                        />
                                                    </td>

                                                    <td className="px-3 py-3 text-right font-bold text-slate-900 dark:text-white">
                                                        {formatCurrency(lineTotal)}
                                                    </td>

                                                    <td className="px-3 py-3 text-center">
                                                        <button
                                                            type="button"
                                                            onClick={() => removeItem(index)}
                                                            className="text-slate-400 hover:text-rose-600 dark:hover:text-rose-400"
                                                            title="Eliminar producto"
                                                        >
                                                            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                            </svg>
                                                        </button>
                                                    </td>
                                                </tr>
                                            )
                                        })}
                                    </tbody>
                                </table>
                            )}
                            {errors.items && (
                                <p className="mt-2 text-xs font-semibold text-rose-500">{errors.items}</p>
                            )}
                        </div>
                    </div>

                    {/* Order Financial Totals & Actions */}
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <div className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <h3 className="text-sm font-semibold text-slate-900 dark:text-white">
                                Costos Adicionales de Importación / Envío
                            </h3>
                            <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                        Flete / Costo de Envío ($)
                                    </label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min={0}
                                        value={data.shipping_cost}
                                        onChange={(e) => setData('shipping_cost', e.target.value)}
                                        className="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                        Impuestos / Aranceles / IVA ($)
                                    </label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min={0}
                                        value={data.tax_amount}
                                        onChange={(e) => setData('tax_amount', e.target.value)}
                                        className="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                    />
                                </div>
                            </div>
                        </div>

                        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <h3 className="text-sm font-semibold text-slate-900 dark:text-white">
                                Resumen Financiero de la Orden
                            </h3>

                            <div className="mt-4 space-y-2 text-sm">
                                <div className="flex justify-between text-slate-600 dark:text-slate-400">
                                    <span>Subtotal Productos ({data.items.reduce((acc, i) => acc + i.quantity_ordered, 0)} unidades):</span>
                                    <span className="font-semibold text-slate-900 dark:text-white">{formatCurrency(subtotal)}</span>
                                </div>
                                <div className="flex justify-between text-slate-600 dark:text-slate-400">
                                    <span>Flete / Envío:</span>
                                    <span className="font-semibold text-slate-900 dark:text-white">{formatCurrency(data.shipping_cost)}</span>
                                </div>
                                <div className="flex justify-between text-slate-600 dark:text-slate-400">
                                    <span>Impuestos / Aranceles:</span>
                                    <span className="font-semibold text-slate-900 dark:text-white">{formatCurrency(data.tax_amount)}</span>
                                </div>
                                <div className="border-t border-slate-200 pt-3 text-base font-bold text-slate-900 dark:border-neutral-700 dark:text-white flex justify-between">
                                    <span>Total Estimado:</span>
                                    <span className="text-xl text-indigo-600 dark:text-indigo-400">{formatCurrency(totalCost)}</span>
                                </div>
                            </div>

                            <div className="mt-6 flex items-center justify-end gap-3">
                                <Link
                                    href="/compras/ordenes"
                                    className="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200 dark:hover:bg-neutral-700"
                                >
                                    Cancelar
                                </Link>

                                <button
                                    type="submit"
                                    disabled={processing || data.items.length === 0}
                                    className="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-50 dark:bg-indigo-500 dark:hover:bg-indigo-600"
                                >
                                    {processing ? 'Guardando...' : 'Crear Orden en Borrador'}
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            {/* Modal: Registrar Producto Rápido / Manual */}
            {quickModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                    <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl dark:bg-neutral-900 border border-slate-200 dark:border-neutral-800">
                        <div className="flex items-center justify-between border-b border-slate-100 pb-3 dark:border-neutral-800">
                            <div>
                                <h3 className="text-base font-bold text-slate-900 dark:text-white">
                                    Registrar Producto Manual
                                </h3>
                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                    Agrega un nuevo producto directamente a tu catálogo y a esta orden de compra.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setQuickModalOpen(false)}
                                className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 dark:hover:bg-neutral-800"
                            >
                                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        {quickError && (
                            <div className="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/20 dark:text-rose-400">
                                {quickError}
                            </div>
                        )}

                        <form onSubmit={handleSaveQuickCreate} className="mt-4 space-y-4">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div>
                                    <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                        Código SKU / Código Tienda *
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        value={quickSku}
                                        onChange={(e) => setQuickSku(e.target.value)}
                                        placeholder="Ej. 681131096034"
                                        className="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100 font-mono"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                        Código de Barras (UPC)
                                    </label>
                                    <input
                                        type="text"
                                        value={quickBarcode}
                                        onChange={(e) => setQuickBarcode(e.target.value)}
                                        placeholder="Ej. 681131096034"
                                        className="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100 font-mono"
                                    />
                                </div>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                    Nombre o Descripción del Producto *
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={quickName}
                                    onChange={(e) => setQuickName(e.target.value)}
                                    placeholder="Ej. Spring Valley Melatonin 5mg Gummies Strawberry 120 gomitas"
                                    className="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                />
                            </div>

                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div>
                                    <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                        Marca
                                    </label>
                                    <input
                                        type="text"
                                        value={quickBrand}
                                        onChange={(e) => setQuickBrand(e.target.value)}
                                        placeholder="Ej. Spring Valley"
                                        className="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                        Costo ($ MXN)
                                    </label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={quickCost}
                                        onChange={(e) => setQuickCost(e.target.value)}
                                        className="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                        Cantidad a Pedir *
                                    </label>
                                    <input
                                        type="number"
                                        min="1"
                                        required
                                        value={quickQty}
                                        onChange={(e) => setQuickQty(e.target.value)}
                                        className="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100 font-bold"
                                    />
                                </div>
                            </div>

                            <div className="mt-6 flex items-center justify-end gap-3 pt-3 border-t border-slate-100 dark:border-neutral-800">
                                <button
                                    type="button"
                                    onClick={() => setQuickModalOpen(false)}
                                    className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    disabled={quickSaving}
                                    className="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white shadow hover:bg-indigo-700 disabled:opacity-50"
                                >
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                    </svg>
                                    {quickSaving ? 'Guardando...' : 'Guardar y Agregar a la Orden'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AppShell>
    )
}
