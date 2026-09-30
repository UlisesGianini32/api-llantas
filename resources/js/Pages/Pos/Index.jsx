import { useState, useEffect, useRef } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

export default function PosIndex({
    locations = [],
    defaultLocationId = null,
    initialProducts = [],
    recentSales = [],
}) {
    const { auth, flash, errors } = usePage().props
    const cashier = auth?.user

    // State: Selected Location
    const [selectedLocationId, setSelectedLocationId] = useState(
        defaultLocationId || (locations[0]?.id ?? '')
    )

    // State: Customer
    const [customerType, setCustomerType] = useState('public') // 'public' | 'stylist'
    const [customerName, setCustomerName] = useState('Público en general')
    const [customerPhone, setCustomerPhone] = useState('')

    // State: Search & Catalog
    const [searchQuery, setSearchQuery] = useState('')
    const [products, setProducts] = useState(initialProducts)
    const [searching, setSearching] = useState(false)
    const [barcodeInput, setBarcodeInput] = useState('')

    // State: Cart
    const [cart, setCart] = useState([])
    const [globalDiscount, setGlobalDiscount] = useState('')
    const [notes, setNotes] = useState('')

    // State: Payment
    const [paymentMethod, setPaymentMethod] = useState('cash') // 'cash', 'card', 'transfer'
    const [amountTendered, setAmountTendered] = useState('')
    const [submitting, setSubmitting] = useState(false)
    const [stockError, setStockError] = useState(null)

    // State: Modals
    const [lastSaleModal, setLastSaleModal] = useState(null)
    const [recentSalesModalOpen, setRecentSalesModalOpen] = useState(false)
    const [cancellingSale, setCancellingSale] = useState(null)
    const [cancelReason, setCancelReason] = useState('')

    // Refs
    const barcodeInputRef = useRef(null)

    // Focus barcode scanner on mount and when modal closes
    useEffect(() => {
        barcodeInputRef.current?.focus()
    }, [lastSaleModal, recentSalesModalOpen])

    // Reload products when location changes
    useEffect(() => {
        fetchProducts(searchQuery, selectedLocationId)
    }, [selectedLocationId])

    const getCsrfToken = () => {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
    }

    const fetchProducts = async (q, locId) => {
        setSearching(true)
        try {
            const url = new URL('/pos/search', window.location.origin)
            if (q) url.searchParams.set('q', q)
            if (locId) url.searchParams.set('location_id', locId)

            const res = await fetch(url.toString(), {
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
            })
            const data = await res.json()
            setProducts(data.products || [])
        } catch (e) {
            console.error('Error buscando productos:', e)
        } finally {
            setSearching(false)
        }
    }

    const handleSearchChange = (e) => {
        const val = e.target.value
        setSearchQuery(val)
        fetchProducts(val, selectedLocationId)
    }

    // Add to cart helper
    const addToCart = (product, qtyToAdd = 1) => {
        setStockError(null)
        const price =
            customerType === 'stylist'
                ? Number(product.price_stylist || product.price_public || 0)
                : Number(product.price_public || 0)

        setCart((prev) => {
            const existingIndex = prev.findIndex((item) => item.product_id === product.id)
            if (existingIndex >= 0) {
                const updated = [...prev]
                const newQty = updated[existingIndex].quantity + qtyToAdd
                updated[existingIndex] = {
                    ...updated[existingIndex],
                    quantity: newQty,
                    unit_price: price,
                    subtotal: newQty * price,
                }
                return updated
            } else {
                return [
                    ...prev,
                    {
                        product_id: product.id,
                        sku: product.sku,
                        barcode: product.barcode,
                        name: product.name,
                        product_type: product.product_type,
                        unit_price: price,
                        quantity: qtyToAdd,
                        discount: 0,
                        subtotal: qtyToAdd * price,
                        available_stock: product.available_stock,
                        physical_stock: product.physical_stock,
                    },
                ]
            }
        })

        // Re-focus barcode input for continuous scanning
        setTimeout(() => barcodeInputRef.current?.focus(), 50)
    }

    // Barcode scanner handler (Enter key)
    const handleBarcodeKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault()
            const code = barcodeInput.trim()
            if (!code) return

            // 1. Try finding in current loaded products
            const match = products.find(
                (p) =>
                    (p.barcode && p.barcode.toLowerCase() === code.toLowerCase()) ||
                    (p.sku && p.sku.toLowerCase() === code.toLowerCase())
            )

            if (match) {
                addToCart(match, 1)
                setBarcodeInput('')
            } else {
                // 2. Fetch directly from server
                fetch(`/pos/search?q=${encodeURIComponent(code)}&location_id=${selectedLocationId}`, {
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
                })
                    .then((res) => res.json())
                    .then((data) => {
                        const directMatch = (data.products || []).find(
                            (p) =>
                                (p.barcode && p.barcode.toLowerCase() === code.toLowerCase()) ||
                                (p.sku && p.sku.toLowerCase() === code.toLowerCase())
                        ) || (data.products || [])[0]

                        if (directMatch) {
                            addToCart(directMatch, 1)
                            setBarcodeInput('')
                        } else {
                            setStockError(`No se encontró ningún producto con código/SKU "${code}".`)
                        }
                    })
                    .catch(() => {
                        setStockError('Error al escanear código de barras.')
                    })
            }
        }
    }

    const updateQuantity = (productId, newQty) => {
        if (newQty <= 0) {
            removeFromCart(productId)
            return
        }

        setCart((prev) =>
            prev.map((item) => {
                if (item.product_id === productId) {
                    return {
                        ...item,
                        quantity: newQty,
                        subtotal: (newQty * item.unit_price) - (item.discount || 0),
                    }
                }
                return item
            })
        )
    }

    const removeFromCart = (productId) => {
        setCart((prev) => prev.filter((item) => item.product_id !== productId))
    }

    const clearCart = () => {
        setCart([])
        setGlobalDiscount('')
        setAmountTendered('')
        setStockError(null)
        barcodeInputRef.current?.focus()
    }

    // Calculations
    const cartSubtotal = cart.reduce((acc, item) => acc + (item.quantity * item.unit_price), 0)
    const itemsDiscount = cart.reduce((acc, item) => acc + (item.discount || 0), 0)
    const discountVal = Number(globalDiscount || 0) + itemsDiscount
    const cartTotal = Math.max(0, cartSubtotal - discountVal)

    const tenderedVal = Number(amountTendered || 0)
    const changeDue = paymentMethod === 'cash' && tenderedVal >= cartTotal ? tenderedVal - cartTotal : 0

    // Check if any cart item exceeds available stock
    const hasStockIssue = cart.some((item) => item.quantity > item.available_stock)

    const handleCheckout = async (e) => {
        e.preventDefault()
        if (cart.length === 0) return
        if (hasStockIssue) {
            setStockError('No puedes procesar la venta: uno o más productos exceden el stock disponible.')
            return
        }

        setSubmitting(true)
        setStockError(null)

        const payload = {
            inventory_location_id: selectedLocationId,
            customer_name: customerName,
            customer_phone: customerPhone,
            customer_type: customerType,
            payment_method: paymentMethod,
            amount_tendered: paymentMethod === 'cash' ? tenderedVal : cartTotal,
            discount_amount: Number(globalDiscount || 0),
            tax_amount: 0,
            notes: notes,
            items: cart.map((item) => ({
                inventory_product_id: item.product_id,
                quantity: item.quantity,
                unit_price: item.unit_price,
                discount: item.discount || 0,
            })),
        }

        try {
            const res = await fetch('/pos/sales', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify(payload),
            })

            const data = await res.json()

            if (!res.ok || !data.ok) {
                setStockError(data.error || 'Error al procesar la venta.')
            } else {
                setLastSaleModal(data.sale)
                clearCart()
                // Refresh catalog stock
                fetchProducts(searchQuery, selectedLocationId)
            }
        } catch (err) {
            setStockError('Error al contactar con el servidor: ' + (err.message || String(err)))
        } finally {
            setSubmitting(false)
        }
    }

    const handleCancelSale = async (e) => {
        e.preventDefault()
        if (!cancellingSale || !cancelReason.trim()) return

        try {
            const res = await fetch(`/pos/sales/${cancellingSale.id}/cancel`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({ reason: cancelReason }),
            })
            const data = await res.json()
            if (data.ok) {
                setCancellingSale(null)
                setCancelReason('')
                setRecentSalesModalOpen(false)
                router.reload({ only: ['recentSales'] })
                fetchProducts(searchQuery, selectedLocationId)
            } else {
                alert(data.error || 'Error al cancelar la venta.')
            }
        } catch (err) {
            alert('Error al contactar servidor: ' + err.message)
        }
    }

    return (
        <AppShell title="Punto de Venta">
            <Head title="Punto de Venta (POS)" />

            <div className="mx-auto max-w-7xl space-y-4">
                {/* TOP BAR: LOCATION & CUSTOMER TOGGLE */}
                <div className="flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center sm:justify-between dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex items-center gap-3">
                        <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600 text-white font-bold">
                            POS
                        </span>
                        <div>
                            <h1 className="text-lg font-bold text-slate-900 dark:text-white">
                                Salón de Ventas / Mostrador
                            </h1>
                            <p className="text-xs text-slate-500">
                                Cajero: <span className="font-semibold text-slate-700 dark:text-slate-300">{cashier?.name}</span>
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {/* Location selector */}
                        <div className="flex items-center gap-1.5 rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs dark:border-neutral-700 dark:bg-neutral-950">
                            <span className="text-slate-500">Ubicación:</span>
                            <select
                                value={selectedLocationId}
                                onChange={(e) => setSelectedLocationId(Number(e.target.value))}
                                className="bg-transparent font-semibold text-slate-800 outline-none dark:text-white"
                            >
                                {locations.map((loc) => (
                                    <option key={loc.id} value={loc.id} className="dark:bg-neutral-900">
                                        {loc.name || loc.code}
                                    </option>
                                ))}
                            </select>
                        </div>

                        {/* Customer Type Toggle */}
                        <div className="flex rounded-xl border border-slate-200 bg-slate-100 p-0.5 text-xs font-semibold dark:border-neutral-700 dark:bg-neutral-950">
                            <button
                                type="button"
                                onClick={() => setCustomerType('public')}
                                className={`rounded-lg px-3 py-1.5 transition ${
                                    customerType === 'public'
                                        ? 'bg-white text-indigo-600 shadow-sm dark:bg-neutral-800 dark:text-indigo-400'
                                        : 'text-slate-600 dark:text-slate-400'
                                }`}
                            >
                                Público General
                            </button>
                            <button
                                type="button"
                                onClick={() => setCustomerType('stylist')}
                                className={`rounded-lg px-3 py-1.5 transition ${
                                    customerType === 'stylist'
                                        ? 'bg-white text-indigo-600 shadow-sm dark:bg-neutral-800 dark:text-indigo-400'
                                        : 'text-slate-600 dark:text-slate-400'
                                }`}
                            >
                                Estilista / Mayoreo
                            </button>
                        </div>

                        {/* Recent Sales Button */}
                        <button
                            type="button"
                            onClick={() => setRecentSalesModalOpen(true)}
                            className="rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200"
                        >
                            Ventas del día ({recentSales.length})
                        </button>
                    </div>
                </div>

                {/* ERROR / STOCK ALERT */}
                {stockError && (
                    <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300">
                        {stockError}
                    </div>
                )}

                {/* MAIN GRID: 2 COLUMNS */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                    {/* LEFT COLUMN: SCANNER & CATALOG (7 COLS) */}
                    <div className="space-y-4 lg:col-span-7">
                        {/* BARCODE SCANNER INPUT */}
                        <div className="rounded-2xl border-2 border-indigo-500/40 bg-white p-3 shadow-sm dark:border-indigo-500/30 dark:bg-neutral-900">
                            <div className="flex items-center gap-2">
                                <span className="text-xl">📷</span>
                                <input
                                    ref={barcodeInputRef}
                                    type="text"
                                    placeholder="Escanear código de barras o ingresar SKU y presionar Enter..."
                                    value={barcodeInput}
                                    onChange={(e) => setBarcodeInput(e.target.value)}
                                    onKeyDown={handleBarcodeKeyDown}
                                    className="w-full bg-transparent text-sm font-semibold outline-none dark:text-white"
                                />
                                {barcodeInput && (
                                    <button
                                        type="button"
                                        onClick={() => setBarcodeInput('')}
                                        className="text-xs text-slate-400 hover:text-slate-600"
                                    >
                                        ✕
                                    </button>
                                )}
                            </div>
                        </div>

                        {/* SEARCH INPUT */}
                        <div className="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm dark:border-neutral-800 dark:bg-neutral-900">
                            <span className="text-slate-400">🔍</span>
                            <input
                                type="text"
                                placeholder="Buscar producto por nombre, marca o SKU..."
                                value={searchQuery}
                                onChange={handleSearchChange}
                                className="w-full bg-transparent text-sm outline-none dark:text-white"
                            />
                            {searching && <span className="text-xs text-slate-400 animate-pulse">Buscando...</span>}
                        </div>

                        {/* PRODUCT CATALOG GRID */}
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 max-h-[520px] overflow-y-auto pr-1">
                            {products.length === 0 ? (
                                <div className="col-span-full py-12 text-center text-sm text-slate-400">
                                    No se encontraron productos disponibles.
                                </div>
                            ) : (
                                products.map((prod) => {
                                    const price =
                                        customerType === 'stylist'
                                            ? prod.price_stylist
                                            : prod.price_public
                                    const isAvailable = prod.available_stock > 0

                                    return (
                                        <div
                                            key={prod.id}
                                            onClick={() => isAvailable && addToCart(prod)}
                                            className={`flex flex-col justify-between rounded-xl border p-3 text-left transition ${
                                                isAvailable
                                                    ? 'cursor-pointer border-slate-200 bg-white hover:border-indigo-400 hover:shadow-md dark:border-neutral-800 dark:bg-neutral-900 dark:hover:border-indigo-500'
                                                    : 'cursor-not-allowed border-slate-100 bg-slate-50 opacity-60 dark:border-neutral-800 dark:bg-neutral-950'
                                            }`}
                                        >
                                            <div>
                                                <div className="flex items-center justify-between gap-1">
                                                    <span className="font-mono text-xs font-semibold text-slate-500 dark:text-slate-400 truncate">
                                                        {prod.sku}
                                                    </span>
                                                    {prod.is_kit && (
                                                        <span className="rounded bg-purple-100 px-1.5 py-0.5 text-[10px] font-bold text-purple-700 dark:bg-purple-900/30 dark:text-purple-300">
                                                            KIT
                                                        </span>
                                                    )}
                                                </div>

                                                <h3 className="mt-1 line-clamp-2 text-xs font-medium text-slate-800 dark:text-slate-200">
                                                    {prod.name}
                                                </h3>
                                            </div>

                                            <div className="mt-3 flex items-end justify-between border-t pt-2 dark:border-neutral-800">
                                                <div>
                                                    <p className="text-xs text-slate-400">
                                                        {customerType === 'stylist' ? 'Estilista' : 'Público'}
                                                    </p>
                                                    <p className="text-sm font-bold text-slate-900 dark:text-white">
                                                        ${price.toFixed(2)}
                                                    </p>
                                                </div>

                                                <span
                                                    className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${
                                                        isAvailable
                                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
                                                            : 'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-400'
                                                    }`}
                                                >
                                                    {isAvailable ? `${prod.available_stock} disp.` : 'Agotado'}
                                                </span>
                                            </div>
                                        </div>
                                    )
                                })
                            )}
                        </div>
                    </div>

                    {/* RIGHT COLUMN: CART & CHECKOUT (5 COLS) */}
                    <div className="space-y-4 lg:col-span-5">
                        <div className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                            {/* CART HEADER */}
                            <div className="flex items-center justify-between border-b pb-3 dark:border-neutral-800">
                                <div className="flex items-center gap-2">
                                    <h2 className="text-sm font-bold uppercase tracking-wider text-slate-900 dark:text-white">
                                        Carrito ({cart.length})
                                    </h2>
                                </div>
                                {cart.length > 0 && (
                                    <button
                                        type="button"
                                        onClick={clearCart}
                                        className="text-xs text-red-600 hover:underline dark:text-red-400"
                                    >
                                        Vaciar carrito
                                    </button>
                                )}
                            </div>

                            {/* CUSTOMER INFO COLLAPSIBLE */}
                            <div className="mt-3 grid grid-cols-2 gap-2 text-xs">
                                <div>
                                    <label className="text-slate-500 font-medium">Cliente</label>
                                    <input
                                        type="text"
                                        value={customerName}
                                        onChange={(e) => setCustomerName(e.target.value)}
                                        className="mt-0.5 w-full rounded-lg border px-2.5 py-1.5 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="text-slate-500 font-medium">Teléfono (opc.)</label>
                                    <input
                                        type="text"
                                        value={customerPhone}
                                        onChange={(e) => setCustomerPhone(e.target.value)}
                                        className="mt-0.5 w-full rounded-lg border px-2.5 py-1.5 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                </div>
                            </div>

                            {/* CART ITEMS LIST */}
                            <div className="mt-3 max-h-[260px] overflow-y-auto divide-y divide-slate-100 dark:divide-neutral-800">
                                {cart.length === 0 ? (
                                    <div className="py-12 text-center text-xs text-slate-400">
                                        El carrito está vacío. Escanea un código o selecciona un producto.
                                    </div>
                                ) : (
                                    cart.map((item) => {
                                        const exceedsStock = item.quantity > item.available_stock
                                        return (
                                            <div
                                                key={item.product_id}
                                                className={`py-2.5 flex items-center justify-between gap-2 ${
                                                    exceedsStock ? 'bg-red-50/60 p-2 rounded-lg dark:bg-red-950/20' : ''
                                                }`}
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-xs font-semibold text-slate-900 dark:text-white">
                                                        {item.name}
                                                    </p>
                                                    <p className="font-mono text-[10px] text-slate-500">
                                                        {item.sku} • ${item.unit_price.toFixed(2)} c/u
                                                    </p>
                                                    {exceedsStock && (
                                                        <p className="text-[10px] font-bold text-red-600 dark:text-red-400">
                                                            ⚠️ Excede stock disponible (Máx: {item.available_stock})
                                                        </p>
                                                    )}
                                                </div>

                                                <div className="flex items-center gap-1.5">
                                                    <button
                                                        type="button"
                                                        onClick={() => updateQuantity(item.product_id, item.quantity - 1)}
                                                        className="flex h-6 w-6 items-center justify-center rounded-lg border bg-slate-50 text-xs font-bold text-slate-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                                    >
                                                        -
                                                    </button>
                                                    <span className="w-6 text-center font-mono text-xs font-bold">
                                                        {item.quantity}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={() => updateQuantity(item.product_id, item.quantity + 1)}
                                                        className="flex h-6 w-6 items-center justify-center rounded-lg border bg-slate-50 text-xs font-bold text-slate-700 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                                    >
                                                        +
                                                    </button>
                                                </div>

                                                <div className="text-right">
                                                    <p className="font-mono text-xs font-bold text-slate-900 dark:text-white">
                                                        ${item.subtotal.toFixed(2)}
                                                    </p>
                                                    <button
                                                        type="button"
                                                        onClick={() => removeFromCart(item.product_id)}
                                                        className="text-[10px] text-slate-400 hover:text-red-600"
                                                    >
                                                        Quitar
                                                    </button>
                                                </div>
                                            </div>
                                        )
                                    })
                                )}
                            </div>

                            {/* TOTALS & PAYMENT SECTION */}
                            <div className="mt-4 space-y-3 border-t pt-3 dark:border-neutral-800">
                                <div className="flex justify-between text-xs text-slate-600 dark:text-slate-400">
                                    <span>Subtotal</span>
                                    <span className="font-mono font-semibold">${cartSubtotal.toFixed(2)}</span>
                                </div>

                                <div className="flex items-center justify-between text-xs text-slate-600 dark:text-slate-400">
                                    <span>Descuento global</span>
                                    <input
                                        type="number"
                                        min="0"
                                        placeholder="$0.00"
                                        value={globalDiscount}
                                        onChange={(e) => setGlobalDiscount(e.target.value)}
                                        className="w-24 rounded border px-2 py-1 text-right font-mono text-xs dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                </div>

                                <div className="flex items-baseline justify-between border-t pt-2 dark:border-neutral-800">
                                    <span className="text-sm font-bold text-slate-900 dark:text-white">
                                        TOTAL A COBRAR
                                    </span>
                                    <span className="text-2xl font-black text-emerald-600 dark:text-emerald-400 font-mono">
                                        ${cartTotal.toFixed(2)}
                                    </span>
                                </div>

                                {/* PAYMENT METHOD SELECTOR */}
                                <div className="pt-2">
                                    <label className="block text-[11px] font-semibold uppercase text-slate-500 mb-1.5">
                                        Método de pago
                                    </label>
                                    <div className="grid grid-cols-3 gap-1.5 text-xs font-semibold">
                                        <button
                                            type="button"
                                            onClick={() => setPaymentMethod('cash')}
                                            className={`rounded-xl border p-2 text-center transition ${
                                                paymentMethod === 'cash'
                                                    ? 'border-emerald-600 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                                                    : 'border-slate-200 text-slate-600 dark:border-neutral-700 dark:text-slate-300'
                                            }`}
                                        >
                                            💵 Efectivo
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setPaymentMethod('card')}
                                            className={`rounded-xl border p-2 text-center transition ${
                                                paymentMethod === 'card'
                                                    ? 'border-indigo-600 bg-indigo-50 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-300'
                                                    : 'border-slate-200 text-slate-600 dark:border-neutral-700 dark:text-slate-300'
                                            }`}
                                        >
                                            💳 Tarjeta
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setPaymentMethod('transfer')}
                                            className={`rounded-xl border p-2 text-center transition ${
                                                paymentMethod === 'transfer'
                                                    ? 'border-sky-600 bg-sky-50 text-sky-700 dark:bg-sky-950/40 dark:text-sky-300'
                                                    : 'border-slate-200 text-slate-600 dark:border-neutral-700 dark:text-slate-300'
                                            }`}
                                        >
                                            📱 Transferencia
                                        </button>
                                    </div>
                                </div>

                                {/* CASH QUICK INPUT */}
                                {paymentMethod === 'cash' && cartTotal > 0 && (
                                    <div className="space-y-2 rounded-xl bg-slate-50 p-2.5 dark:bg-neutral-950 text-xs">
                                        <div className="flex items-center justify-between">
                                            <span className="font-medium text-slate-600 dark:text-slate-300">Paga con:</span>
                                            <input
                                                type="number"
                                                step="any"
                                                placeholder={`$${cartTotal.toFixed(2)}`}
                                                value={amountTendered}
                                                onChange={(e) => setAmountTendered(e.target.value)}
                                                className="w-28 rounded-lg border px-2 py-1 text-right font-mono font-bold dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                                            />
                                        </div>

                                        <div className="flex flex-wrap gap-1 justify-end">
                                            {[100, 200, 500, 1000].map((val) => (
                                                <button
                                                    key={val}
                                                    type="button"
                                                    onClick={() => setAmountTendered(String(val))}
                                                    className="rounded border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-semibold text-slate-700 hover:bg-slate-100 dark:border-neutral-800 dark:bg-neutral-900 dark:text-slate-200"
                                                >
                                                    ${val}
                                                </button>
                                            ))}
                                            <button
                                                type="button"
                                                onClick={() => setAmountTendered(String(cartTotal))}
                                                className="rounded border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-[10px] font-semibold text-indigo-700 hover:bg-indigo-100 dark:border-indigo-800 dark:bg-indigo-950 dark:text-indigo-300"
                                            >
                                                Exacto
                                            </button>
                                        </div>

                                        {tenderedVal >= cartTotal && (
                                            <div className="flex justify-between border-t pt-1.5 font-semibold text-emerald-700 dark:text-emerald-400">
                                                <span>Cambio:</span>
                                                <span className="font-mono text-sm">${changeDue.toFixed(2)}</span>
                                            </div>
                                        )}
                                    </div>
                                )}

                                {/* CHECKOUT BUTTON */}
                                <button
                                    type="button"
                                    onClick={handleCheckout}
                                    disabled={cart.length === 0 || hasStockIssue || submitting}
                                    className="w-full rounded-xl bg-emerald-600 py-3.5 text-center text-sm font-bold text-white shadow-lg transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {submitting
                                        ? 'Procesando venta...'
                                        : `Cobrar Venta • $${cartTotal.toFixed(2)}`}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {/* MODAL: SALE COMPLETED / RECEIPT */}
            {lastSaleModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl dark:bg-neutral-900">
                        <div className="text-center">
                            <span className="inline-flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-2xl text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400">
                                ✓
                            </span>
                            <h3 className="mt-2 text-xl font-bold text-slate-900 dark:text-white">
                                Venta completada
                            </h3>
                            <p className="font-mono text-sm font-semibold text-indigo-600 dark:text-indigo-400">
                                {lastSaleModal.sale_number}
                            </p>
                        </div>

                        {/* RECEIPT SUMMARY */}
                        <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs space-y-2 dark:border-neutral-800 dark:bg-neutral-950">
                            <div className="flex justify-between">
                                <span className="text-slate-500">Cajero:</span>
                                <span className="font-semibold text-slate-800 dark:text-slate-200">
                                    {cashier?.name}
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-slate-500">Cliente:</span>
                                <span className="font-semibold text-slate-800 dark:text-slate-200">
                                    {lastSaleModal.customer_name}
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-slate-500">Método de pago:</span>
                                <span className="font-semibold uppercase text-slate-800 dark:text-slate-200">
                                    {lastSaleModal.payment_method}
                                </span>
                            </div>

                            <div className="border-t pt-2 my-2 space-y-1 dark:border-neutral-800">
                                {(lastSaleModal.items || []).map((item, idx) => (
                                    <div key={idx} className="flex justify-between text-slate-700 dark:text-slate-300">
                                        <span>
                                            {item.quantity}x {item.product_name}
                                        </span>
                                        <span className="font-mono">${Number(item.subtotal).toFixed(2)}</span>
                                    </div>
                                ))}
                            </div>

                            <div className="border-t pt-2 flex justify-between font-bold text-sm text-slate-900 dark:text-white">
                                <span>TOTAL PAGADO</span>
                                <span className="font-mono">${Number(lastSaleModal.total).toFixed(2)}</span>
                            </div>

                            {Number(lastSaleModal.change_due) > 0 && (
                                <div className="flex justify-between font-semibold text-emerald-600 dark:text-emerald-400">
                                    <span>Cambio entregado:</span>
                                    <span className="font-mono">${Number(lastSaleModal.change_due).toFixed(2)}</span>
                                </div>
                            )}
                        </div>

                        {/* ACTIONS */}
                        <div className="mt-5 flex gap-2">
                            <button
                                type="button"
                                onClick={() => window.print()}
                                className="flex-1 rounded-xl border border-slate-300 bg-white py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                            >
                                🖨️ Imprimir ticket
                            </button>
                            <button
                                type="button"
                                onClick={() => setLastSaleModal(null)}
                                className="flex-1 rounded-xl bg-indigo-600 py-2.5 text-xs font-bold text-white hover:bg-indigo-700"
                            >
                                + Nueva venta
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL: RECENT SALES OF THE DAY */}
            {recentSalesModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                    <div className="w-full max-w-2xl rounded-2xl bg-white p-6 shadow-2xl dark:bg-neutral-900 max-h-[85vh] flex flex-col">
                        <div className="flex items-center justify-between border-b pb-3 dark:border-neutral-800">
                            <h3 className="text-lg font-bold text-slate-900 dark:text-white">
                                Ventas del día (Mostrador)
                            </h3>
                            <button
                                type="button"
                                onClick={() => setRecentSalesModalOpen(false)}
                                className="text-slate-400 hover:text-slate-600 dark:hover:text-white"
                            >
                                ✕
                            </button>
                        </div>

                        <div className="mt-4 flex-1 overflow-y-auto space-y-3">
                            {recentSales.length === 0 ? (
                                <p className="py-8 text-center text-sm text-slate-400">
                                    No hay ventas registradas el día de hoy.
                                </p>
                            ) : (
                                recentSales.map((sale) => (
                                    <div
                                        key={sale.id}
                                        className="flex items-center justify-between rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs dark:border-neutral-800 dark:bg-neutral-950"
                                    >
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <span className="font-mono font-bold text-indigo-600 dark:text-indigo-400">
                                                    {sale.sale_number}
                                                </span>
                                                <span
                                                    className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${
                                                        sale.status === 'completed'
                                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400'
                                                            : 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-400'
                                                    }`}
                                                >
                                                    {sale.status === 'completed' ? 'Completada' : 'Cancelada'}
                                                </span>
                                            </div>
                                            <p className="mt-1 text-slate-600 dark:text-slate-400">
                                                {sale.customer_name} • Cajero: {sale.cashier?.name}
                                            </p>
                                        </div>

                                        <div className="flex items-center gap-3">
                                            <span className="font-mono text-sm font-bold text-slate-900 dark:text-white">
                                                ${Number(sale.total).toFixed(2)}
                                            </span>

                                            {sale.status === 'completed' && (
                                                <button
                                                    type="button"
                                                    onClick={() => setCancellingSale(sale)}
                                                    className="rounded-lg border border-red-200 px-2 py-1 text-[11px] font-semibold text-red-600 hover:bg-red-50 dark:border-red-900 dark:text-red-400"
                                                >
                                                    Cancelar
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL: CANCEL SALE CONFIRMATION */}
            {cancellingSale && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                    <div className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl dark:bg-neutral-900">
                        <h3 className="text-base font-bold text-slate-900 dark:text-white">
                            Cancelar venta {cancellingSale.sale_number}
                        </h3>
                        <p className="mt-1 text-xs text-slate-500">
                            Esta acción reingresará los productos vendidos al inventario físico de la ubicación.
                        </p>

                        <form onSubmit={handleCancelSale} className="mt-4 space-y-3">
                            <div>
                                <label className="block text-xs font-semibold text-slate-600 dark:text-slate-300">
                                    Motivo de cancelación:
                                </label>
                                <textarea
                                    required
                                    rows={2}
                                    placeholder="ej. Error de cobro, devolución del cliente..."
                                    value={cancelReason}
                                    onChange={(e) => setCancelReason(e.target.value)}
                                    className="mt-1 w-full rounded-xl border p-2 text-xs dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => setCancellingSale(null)}
                                    className="rounded-xl border px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                                >
                                    Atrás
                                </button>
                                <button
                                    type="submit"
                                    className="rounded-xl bg-red-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-red-700"
                                >
                                    Confirmar cancelación
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AppShell>
    )
}
