import { useState, useEffect, useRef } from 'react'
import { Head, Link, router, usePage } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'
import {
    connectQz,
    getThermalPrinters,
    isQzActive,
    kickDrawer,
    openRawBtTicket,
    printViaBrowser,
    printViaQz,
    THERMAL_PAPER_STORAGE_KEY,
    THERMAL_PRINTER_STORAGE_KEY,
} from '@/lib/posPrinting'
import { esAndroid } from '@/lib/rawBtPng'

export default function PosIndex({
    locations = [],
    defaultLocationId = null,
    initialProducts = [],
    recentSales = [],
    customers = [],
    creditAlerts = {},
    currentShift = null,
    shiftSummary = null,
}) {
    const { auth, flash, errors } = usePage().props
    const cashier = auth?.user

    // State: Selected Location ('' = Todo el Almacén / Global)
    const [selectedLocationId, setSelectedLocationId] = useState(
        defaultLocationId !== undefined && defaultLocationId !== null ? defaultLocationId : ''
    )
    const [catalogFilter, setCatalogFilter] = useState('all') // 'all' | 'stock' | 'kits' | 'zero'

    // State: Shifts & Drawer
    const [shift, setShift] = useState(currentShift)
    const [summary, setSummary] = useState(shiftSummary)
    const [shiftOpenModalOpen, setShiftOpenModalOpen] = useState(false)
    const [openingCashInput, setOpeningCashInput] = useState('500')
    const [openingNotesInput, setOpeningNotesInput] = useState('')
    const [shiftLoading, setShiftLoading] = useState(false)

    // State: Cash Movements
    const [cashMovementModalOpen, setCashMovementModalOpen] = useState(false)
    const [movementType, setMovementType] = useState('OUT') // 'IN' | 'OUT'
    const [movementAmount, setMovementAmount] = useState('')
    const [movementReason, setMovementReason] = useState('')
    const [movementNotes, setMovementNotes] = useState('')

    // State: Shift Cut (Arqueo & Cierre)
    const [shiftCutModalOpen, setShiftCutModalOpen] = useState(false)
    const [closingCashCounted, setClosingCashCounted] = useState('')
    const [closingNotes, setClosingNotes] = useState('')
    const [cutReceiptModal, setCutReceiptModal] = useState(null)

    // State: Thermal Printer & Settings
    const [paperWidth, setPaperWidth] = useState(() => {
        try {
            return localStorage.getItem(THERMAL_PAPER_STORAGE_KEY) || '80mm'
        } catch {
            return '80mm'
        }
    })
    const [printerName, setPrinterName] = useState(() => {
        try {
            return localStorage.getItem(THERMAL_PRINTER_STORAGE_KEY) || ''
        } catch {
            return ''
        }
    })
    const [printerList, setPrinterList] = useState([])
    const [qzConnected, setQzConnected] = useState(false)
    const [settingsModalOpen, setSettingsModalOpen] = useState(false)

    // State: Customer & Credit
    const [customerList, setCustomerList] = useState(customers)
    const [selectedCustomer, setSelectedCustomer] = useState(null)
    const [customerType, setCustomerType] = useState('public') // 'public' | 'stylist'
    const [customerName, setCustomerName] = useState('Público en general')
    const [customerPhone, setCustomerPhone] = useState('')
    const [customerDropdownOpen, setCustomerDropdownOpen] = useState(false)
    const [quickCustomerModalOpen, setQuickCustomerModalOpen] = useState(false)
    const [newCustomerForm, setNewCustomerForm] = useState({
        name: '',
        business_name: '',
        phone: '',
        credit_limit: '5000',
        credit_days_default: 15,
        address: '',
    })

    // State: Credit terms
    const [creditDays, setCreditDays] = useState(15) // 7, 15, 30
    const [creditDownpayment, setCreditDownpayment] = useState('')

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
    }, [lastSaleModal, recentSalesModalOpen, shiftOpenModalOpen, cashMovementModalOpen, shiftCutModalOpen])

    // Reload products when location changes
    useEffect(() => {
        fetchProducts(searchQuery, selectedLocationId)
        fetchCurrentShift(selectedLocationId)
    }, [selectedLocationId])

    // Detect QZ Tray
    useEffect(() => {
        setQzConnected(isQzActive())
    }, [])

    const getCsrfToken = () => {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
    }

    const fetchCurrentShift = async (locId) => {
        try {
            const url = new URL('/pos/shifts/current', window.location.origin)
            if (locId) url.searchParams.set('location_id', locId)

            const res = await fetch(url.toString(), {
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
            })
            const data = await res.json()
            if (data.active) {
                setShift(data.shift)
                setSummary(data.summary)
            } else {
                setShift(null)
                setSummary(null)
            }
        } catch (e) {
            console.error('Error obteniendo turno actual:', e)
        }
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
                    },
                ]
            }
        })
    }

    // Remove from cart
    const removeFromCart = (index) => {
        setCart((prev) => prev.filter((_, i) => i !== index))
    }

    // Update quantity
    const updateQuantity = (index, newQty) => {
        if (newQty <= 0) {
            removeFromCart(index)
            return
        }
        setCart((prev) => {
            const updated = [...prev]
            const item = updated[index]
            updated[index] = {
                ...item,
                quantity: newQty,
                subtotal: (newQty * Number(item.unit_price)) - Number(item.discount || 0),
            }
            return updated
        })
    }

    // Update unit price on the fly (for custom prices or products with $0)
    const updateItemPrice = (index, newPrice) => {
        const p = Math.max(0, Number(newPrice) || 0)
        setCart((prev) => {
            const updated = [...prev]
            const item = updated[index]
            updated[index] = {
                ...item,
                unit_price: p,
                subtotal: (item.quantity * p) - Number(item.discount || 0),
            }
            return updated
        })
    }

    // Handle barcode scanner enter key
    const handleBarcodeKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault()
            const code = barcodeInput.trim()
            if (!code) return

            const found = products.find(
                (p) =>
                    (p.barcode && p.barcode.toLowerCase() === code.toLowerCase()) ||
                    (p.sku && p.sku.toLowerCase() === code.toLowerCase())
            )

            if (found) {
                addToCart(found, 1)
                setBarcodeInput('')
            } else {
                fetchProducts(code, selectedLocationId).then(() => {
                    setBarcodeInput('')
                })
            }
        }
    }

    // Calculate cart totals
    const cartSubtotal = cart.reduce((acc, item) => acc + Number(item.subtotal || 0), 0)
    const discountVal = Number(globalDiscount || 0)
    const cartTotal = Math.max(0, cartSubtotal - discountVal)
    const tenderedVal = Number(amountTendered || 0)
    const changeDue = paymentMethod === 'cash' && tenderedVal >= cartTotal ? tenderedVal - cartTotal : 0

    const handleSelectCustomer = (cust) => {
        if (!cust) {
            setSelectedCustomer(null)
            setCustomerName('Público en general')
            setCustomerPhone('')
            setCustomerDropdownOpen(false)
            return
        }
        setSelectedCustomer(cust)
        setCustomerName(cust.name)
        setCustomerPhone(cust.phone || '')
        if (cust.credit_days_default) {
            setCreditDays(cust.credit_days_default)
        }
        setCustomerDropdownOpen(false)
    }

    const handleQuickCreateCustomer = async (e) => {
        e.preventDefault()
        if (!newCustomerForm.name.trim()) return

        try {
            const res = await fetch('/pos/clientes', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify(newCustomerForm),
            })
            const data = await res.json()
            if (data.ok && data.customer) {
                setCustomerList((prev) => [data.customer, ...prev])
                handleSelectCustomer(data.customer)
                setQuickCustomerModalOpen(false)
                setNewCustomerForm({
                    name: '',
                    business_name: '',
                    phone: '',
                    credit_limit: '5000',
                    credit_days_default: 15,
                    address: '',
                })
            } else {
                window.alert('Error creando cliente: ' + (data.message || 'Datos incompletos'))
            }
        } catch (err) {
            window.alert('Error de red creando cliente: ' + err.message)
        }
    }

    const calculateDueDate = (days) => {
        const d = new Date()
        d.setDate(d.getDate() + Number(days))
        return d.toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric' })
    }

    // Switch customer type and update cart prices
    const handleCustomerTypeChange = (type) => {
        setCustomerType(type)
        setCart((prev) =>
            prev.map((item) => {
                const prod = products.find((p) => p.id === item.product_id)
                const price =
                    type === 'stylist'
                        ? Number(prod?.price_stylist || prod?.price_public || item.unit_price)
                        : Number(prod?.price_public || item.unit_price)
                return {
                    ...item,
                    unit_price: price,
                    subtotal: (item.quantity * price) - Number(item.discount || 0),
                }
            })
        )
    }

    // Checkout submit
    const handleCheckout = async (e) => {
        e.preventDefault()
        if (cart.length === 0) return

        if (paymentMethod === 'cash' && tenderedVal > 0 && tenderedVal < cartTotal) {
            setStockError('El monto en efectivo recibido es menor al total a pagar.')
            return
        }

        if (paymentMethod === 'credit') {
            if (!selectedCustomer && (!customerName || customerName.trim() === '' || customerName.trim() === 'Público en general')) {
                setStockError('Para otorgar venta a crédito es obligatorio seleccionar o registrar un cliente/estilista.')
                return
            }
        }

        setSubmitting(true)
        setStockError(null)

        const downpaymentVal = paymentMethod === 'credit' && creditDownpayment ? Number(creditDownpayment) : null

        const payload = {
            inventory_location_id: selectedLocationId,
            customer_id: selectedCustomer ? selectedCustomer.id : null,
            customer_name: customerName,
            customer_phone: customerPhone,
            customer_type: customerType,
            payment_method: paymentMethod,
            credit_days: paymentMethod === 'credit' ? creditDays : null,
            amount_tendered: paymentMethod === 'cash' ? tenderedVal : downpaymentVal,
            discount_amount: discountVal,
            tax_amount: 0,
            notes: notes,
            paper_type: paperWidth,
            items: cart.map((item) => ({
                inventory_product_id: item.product_id,
                quantity: item.quantity,
                unit_price: item.unit_price,
                discount: item.discount,
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
                setStockError(data.error || 'Ocurrió un error al procesar la venta.')
                setSubmitting(false)
                return
            }

            // Success: clear cart and show receipt modal
            setCart([])
            setAmountTendered('')
            setCreditDownpayment('')
            setGlobalDiscount('')
            setNotes('')
            setLastSaleModal({
                ...data.sale,
                receipt: data.receipt,
            })

            // Refresh shift summary & catalog stock
            fetchCurrentShift(selectedLocationId)
            fetchProducts(searchQuery, selectedLocationId)
        } catch (err) {
            console.error('Error en checkout POS:', err)
            setStockError('Error de red al procesar la venta: ' + err.message)
        } finally {
            setSubmitting(false)
        }
    }

    // Print Receipt via QZ Tray
    const handlePrintQz = async (escposBase64) => {
        try {
            await printViaQz(printerName, escposBase64)
            setQzConnected(true)
        } catch (err) {
            console.error('Error imprimiendo con QZ Tray:', err)
            window.alert('Error al imprimir con QZ Tray: ' + (err.message || 'Verifica que QZ Tray esté ejecutándose.'))
        }
    }

    // Print Receipt via RawBT
    const handlePrintRawBt = (escposBase64) => {
        try {
            openRawBtTicket(escposBase64)
        } catch (err) {
            window.alert('Error con RawBT: ' + err.message)
        }
    }

    // Kick Drawer
    const handleKickDrawer = async () => {
        try {
            await kickDrawer(printerName)
        } catch (err) {
            window.alert('No se pudo abrir el cajón: ' + err.message)
        }
    }

    // Open Shift
    const handleOpenShift = async (e) => {
        e.preventDefault()
        setShiftLoading(true)
        try {
            const res = await fetch('/pos/shifts/open', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    inventory_location_id: selectedLocationId,
                    opening_cash: Number(openingCashInput || 0),
                    notes: openingNotesInput,
                }),
            })
            const data = await res.json()
            if (!res.ok || !data.ok) {
                window.alert('Error: ' + (data.error || 'No se pudo abrir el turno.'))
                return
            }
            setShift(data.shift)
            setSummary(data.summary)
            setShiftOpenModalOpen(false)
            setOpeningNotesInput('')
        } catch (err) {
            window.alert('Error de red al abrir turno: ' + err.message)
        } finally {
            setShiftLoading(false)
        }
    }

    // Register Cash Movement
    const handleAddCashMovement = async (e) => {
        e.preventDefault()
        if (!shift) return
        setShiftLoading(true)
        try {
            const res = await fetch(`/pos/shifts/${shift.id}/movement`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    type: movementType,
                    amount: Number(movementAmount || 0),
                    reason: movementReason,
                    notes: movementNotes,
                }),
            })
            const data = await res.json()
            if (!res.ok || !data.ok) {
                window.alert('Error: ' + (data.error || 'No se pudo registrar el movimiento.'))
                return
            }
            setSummary(data.summary)
            setCashMovementModalOpen(false)
            setMovementAmount('')
            setMovementReason('')
            setMovementNotes('')
            window.alert(data.message)
        } catch (err) {
            window.alert('Error al registrar movimiento: ' + err.message)
        } finally {
            setShiftLoading(false)
        }
    }

    // Close Shift (Corte de Caja)
    const handleCloseShift = async (e) => {
        e.preventDefault()
        if (!shift) return
        setShiftLoading(true)
        try {
            const res = await fetch(`/pos/shifts/${shift.id}/close`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    closing_cash_counted: Number(closingCashCounted || 0),
                    notes: closingNotes,
                    paper_type: paperWidth,
                }),
            })
            const data = await res.json()
            if (!res.ok || !data.ok) {
                window.alert('Error: ' + (data.error || 'No se pudo cerrar el turno.'))
                return
            }
            setShift(null)
            setSummary(null)
            setShiftCutModalOpen(false)
            setClosingCashCounted('')
            setClosingNotes('')
            setCutReceiptModal(data.receipt)
        } catch (err) {
            window.alert('Error al cerrar turno: ' + err.message)
        } finally {
            setShiftLoading(false)
        }
    }

    // Detect Printers via QZ
    const handleDetectPrinters = async () => {
        try {
            const list = await getThermalPrinters()
            setPrinterList(list)
            setQzConnected(true)
            if (list.length > 0 && !printerName) {
                setPrinterName(list[0])
                localStorage.setItem(THERMAL_PRINTER_STORAGE_KEY, list[0])
            }
        } catch (err) {
            window.alert('Error detectando impresoras con QZ Tray: ' + err.message)
        }
    }

    // Cancel sale
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
            if (!res.ok || !data.ok) {
                window.alert('Error al cancelar: ' + (data.error || 'Error desconocido'))
                return
            }

            setCancellingSale(null)
            setCancelReason('')
            setRecentSalesModalOpen(false)
            router.reload({ only: ['recentSales'] })
            fetchCurrentShift(selectedLocationId)
            fetchProducts(searchQuery, selectedLocationId)
        } catch (err) {
            window.alert('Error de red al cancelar venta: ' + err.message)
        }
    }

    // Difference in Corte de caja
    const expectedCashInDrawer = summary ? Number(summary.expected_cash || 0) : 0
    const countedVal = Number(closingCashCounted || 0)
    const cutDifference = closingCashCounted !== '' ? countedVal - expectedCashInDrawer : 0

    return (
        <AppShell>
            <Head title="Punto de Venta (POS) - Mostrador" />

            <div className="mx-auto max-w-7xl space-y-4 px-4 py-4">
                {/* TOP HEADER: TITLE & LOCATION & SHIFT ACTIONS */}
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-white p-4 shadow-sm dark:bg-neutral-900 border border-slate-200 dark:border-neutral-800">
                    <div className="flex items-center gap-3">
                        <div className="rounded-xl bg-indigo-600 p-2.5 text-white shadow-md">
                            <span className="text-xl">🏪</span>
                        </div>
                        <div>
                            <h1 className="text-xl font-bold tracking-tight text-slate-900 dark:text-white">
                                Salón & Barbershop POS
                            </h1>
                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                Cajero activo: <span className="font-semibold text-slate-800 dark:text-slate-200">{cashier?.name}</span>
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {/* Location picker (Global Almacén by default) */}
                        <div className="flex items-center gap-1.5 rounded-xl border border-slate-300 bg-slate-50 px-3 py-1.5 text-xs shadow-sm dark:border-neutral-700 dark:bg-neutral-800">
                            <span className="text-base">📍</span>
                            <select
                                value={selectedLocationId}
                                onChange={(e) => setSelectedLocationId(e.target.value ? Number(e.target.value) : '')}
                                className="bg-transparent font-bold text-slate-800 outline-none dark:text-slate-200 cursor-pointer"
                            >
                                <option value="" className="font-bold text-indigo-700 dark:bg-neutral-900 dark:text-indigo-400">
                                    🏬 Todo el Almacén (Global - Stock total)
                                </option>
                                {locations.map((loc) => (
                                    <option key={loc.id} value={loc.id} className="dark:bg-neutral-900">
                                        📍 {loc.name} ({loc.code})
                                    </option>
                                ))}
                            </select>
                        </div>

                        {/* Customer Pricing Tier Toggle */}
                        <div className="flex rounded-xl bg-slate-100 p-1 text-xs font-semibold dark:bg-neutral-800 border border-slate-200 dark:border-neutral-700 shadow-sm">
                            <button
                                type="button"
                                onClick={() => handleCustomerTypeChange('public')}
                                className={`flex items-center gap-1 rounded-lg px-3 py-1.5 transition ${
                                    customerType === 'public'
                                        ? 'bg-white text-indigo-700 font-bold shadow dark:bg-neutral-700 dark:text-indigo-300'
                                        : 'text-slate-500 hover:text-slate-800 dark:text-slate-400'
                                }`}
                            >
                                <span>👤</span> Público
                            </button>
                            <button
                                type="button"
                                onClick={() => handleCustomerTypeChange('stylist')}
                                className={`flex items-center gap-1.5 rounded-lg px-3 py-1.5 transition ${
                                    customerType === 'stylist'
                                        ? 'bg-purple-600 text-white font-bold shadow'
                                        : 'text-slate-500 hover:text-purple-600 dark:text-slate-400'
                                }`}
                            >
                                <span>💇</span> Estilista / Mayoreo
                            </button>
                        </div>

                        {/* Printer Settings Button */}
                        <button
                            type="button"
                            onClick={() => setSettingsModalOpen(true)}
                            className="rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                            title="Configurar impresora térmica"
                        >
                            🖨️ {paperWidth} {qzConnected ? '• QZ' : ''}
                        </button>

                        {/* Recent Sales Button */}
                        <button
                            type="button"
                            onClick={() => setRecentSalesModalOpen(true)}
                            className="rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                        >
                            Ventas ({recentSales.length})
                        </button>

                        {/* Clientes & Cartera Button with Alerts */}
                        <Link
                            href="/pos/clientes"
                            className="flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                        >
                            <span>👥</span> Clientes
                            {((creditAlerts?.overdue_sales_count || 0) + (creditAlerts?.due_soon_sales_count || 0)) > 0 && (
                                <span className="rounded-full bg-red-600 px-1.5 py-0.2 text-[10px] font-extrabold text-white animate-pulse" title="Créditos por vencer o vencidos">
                                    {(creditAlerts.overdue_sales_count || 0) + (creditAlerts.due_soon_sales_count || 0)}
                                </span>
                            )}
                        </Link>
                    </div>
                </div>

                {/* SHIFT & CASH DRAWER STATUS BAR */}
                {shift ? (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-emerald-200 bg-emerald-50/70 p-3.5 shadow-sm dark:border-emerald-900/60 dark:bg-emerald-950/20">
                        <div className="flex flex-wrap items-center gap-3">
                            <span className="flex items-center gap-1.5 rounded-full bg-emerald-600 px-3 py-1 text-xs font-bold text-white shadow-sm">
                                <span className="h-2 w-2 rounded-full bg-white animate-pulse" />
                                Turno #{shift.id} Abierto
                            </span>
                            <div className="flex flex-wrap items-center gap-2 text-xs font-medium text-emerald-900 dark:text-emerald-300">
                                <span className="rounded-lg bg-emerald-100/80 px-2 py-0.5 dark:bg-emerald-900/50">
                                    Fondo: <strong className="font-mono">${Number(shift.opening_cash).toFixed(2)}</strong>
                                </span>
                                <span className="rounded-lg bg-emerald-100/80 px-2 py-0.5 dark:bg-emerald-900/50">
                                    Ventas Efectivo: <strong className="font-mono">${Number(summary?.sales_cash || 0).toFixed(2)}</strong> ({summary?.sales_count || 0})
                                </span>
                                <span className="rounded-lg bg-emerald-100/80 px-2 py-0.5 dark:bg-emerald-900/50">
                                    En Caja: <strong className="font-mono">${Number(summary?.expected_cash || shift.opening_cash).toFixed(2)}</strong>
                                </span>
                            </div>
                        </div>

                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => setCashMovementModalOpen(true)}
                                className="rounded-xl border border-emerald-300 bg-white px-3 py-1.5 text-xs font-semibold text-emerald-800 shadow-sm hover:bg-emerald-50 dark:border-emerald-800 dark:bg-neutral-900 dark:text-emerald-300"
                            >
                                💵 Movimiento (+/-)
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    setClosingCashCounted('')
                                    setClosingNotes('')
                                    setShiftCutModalOpen(true)
                                }}
                                className="rounded-xl bg-emerald-700 px-3 py-1.5 text-xs font-bold text-white shadow hover:bg-emerald-800 dark:bg-emerald-600"
                            >
                                ✂️ Corte de Caja
                            </button>
                            <button
                                type="button"
                                onClick={handleKickDrawer}
                                className="rounded-xl border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-300"
                                title="Abrir cajón portamonedas"
                            >
                                🔓 Cajón
                            </button>
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50/70 p-3.5 shadow-sm dark:border-amber-900/60 dark:bg-amber-950/20">
                        <div className="flex items-center gap-2 text-xs font-semibold text-amber-900 dark:text-amber-300">
                            <span className="text-base">⚠️</span>
                            <span>Caja Cerrada: Para cobrar en efectivo y llevar control de arqueo, abre un turno.</span>
                        </div>
                        <button
                            type="button"
                            onClick={() => setShiftOpenModalOpen(true)}
                            className="rounded-xl bg-amber-600 px-4 py-1.5 text-xs font-bold text-white shadow hover:bg-amber-700"
                        >
                            🟢 Abrir Caja / Turno
                        </button>
                    </div>
                )}

                {/* ERROR / STOCK ALERT */}
                {stockError && (
                    <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300">
                        {stockError}
                    </div>
                )}

                {/* MAIN GRID: 2 COLUMNS */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                    {/* LEFT COLUMN: SCANNER, SEARCH & CATALOG (7 COLS) */}
                    <div className="space-y-3 lg:col-span-7">
                        {/* UNIFIED SEARCH & SCANNER INPUT */}
                        <div className="rounded-2xl border-2 border-indigo-500/40 bg-white p-2.5 shadow-sm dark:border-indigo-500/30 dark:bg-neutral-900">
                            <div className="flex items-center gap-2.5">
                                <span className="text-xl pl-1">🔍</span>
                                <input
                                    ref={barcodeInputRef}
                                    type="text"
                                    placeholder="Buscar producto por nombre, SKU o escanear código de barras (Enter)..."
                                    value={barcodeInput || searchQuery}
                                    onChange={(e) => {
                                        setBarcodeInput(e.target.value)
                                        handleSearchChange(e)
                                    }}
                                    onKeyDown={handleBarcodeKeyDown}
                                    className="w-full bg-transparent text-sm font-medium outline-none dark:text-white"
                                />
                                {(barcodeInput || searchQuery) && (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setBarcodeInput('')
                                            setSearchQuery('')
                                            fetchProducts('', selectedLocationId ? Number(selectedLocationId) : null)
                                        }}
                                        className="rounded-lg p-1 text-xs text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-neutral-800"
                                    >
                                        ✕
                                    </button>
                                )}
                                {searching && <span className="text-xs text-indigo-500 animate-pulse font-semibold">Buscando...</span>}
                            </div>
                        </div>

                        {/* FILTER CHIPS */}
                        <div className="flex flex-wrap items-center justify-between gap-2 text-xs">
                            <div className="flex items-center gap-1.5">
                                <button
                                    type="button"
                                    onClick={() => setCatalogFilter('all')}
                                    className={`rounded-xl px-3 py-1 font-semibold transition ${
                                        catalogFilter === 'all'
                                            ? 'bg-slate-900 text-white dark:bg-white dark:text-slate-900'
                                            : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-neutral-800 dark:text-slate-300'
                                    }`}
                                >
                                    Todos ({products.length})
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setCatalogFilter('stock')}
                                    className={`rounded-xl px-3 py-1 font-semibold transition ${
                                        catalogFilter === 'stock'
                                            ? 'bg-emerald-600 text-white'
                                            : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950/40 dark:text-emerald-300'
                                    }`}
                                >
                                    ✅ Con Stock ({products.filter((p) => p.available_stock > 0 || p.global_available_stock > 0).length})
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setCatalogFilter('kits')}
                                    className={`rounded-xl px-3 py-1 font-semibold transition ${
                                        catalogFilter === 'kits'
                                            ? 'bg-purple-600 text-white'
                                            : 'bg-purple-50 text-purple-700 hover:bg-purple-100 dark:bg-purple-950/40 dark:text-purple-300'
                                    }`}
                                >
                                    📦 Kits ({products.filter((p) => p.is_kit).length})
                                </button>
                            </div>

                            <span className="text-[11px] text-slate-400 font-medium">
                                {selectedLocationId ? 'Filtrado por ubicación' : '🌐 Stock global de almacén'}
                            </span>
                        </div>

                        {/* PRODUCT CATALOG GRID */}
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 max-h-[560px] overflow-y-auto pr-1">
                            {products.length === 0 ? (
                                <div className="col-span-full rounded-2xl border border-dashed border-slate-300 p-12 text-center text-sm text-slate-400 dark:border-neutral-800">
                                    No se encontraron productos disponibles con los criterios de búsqueda.
                                </div>
                            ) : (
                                products
                                    .filter((prod) => {
                                        if (catalogFilter === 'stock') {
                                            return prod.available_stock > 0 || prod.global_available_stock > 0
                                        }
                                        if (catalogFilter === 'kits') {
                                            return prod.is_kit
                                        }
                                        return true
                                    })
                                    .map((prod) => {
                                        const price = customerType === 'stylist' ? prod.price_stylist : prod.price_public
                                        const isAvailable = prod.available_stock > 0 || prod.global_available_stock > 0
                                        const inCartItem = cart.find((i) => i.product_id === prod.id)
                                        const inCartQty = inCartItem ? inCartItem.quantity : 0

                                        return (
                                            <div
                                                key={prod.id}
                                                onClick={() => addToCart(prod, 1)}
                                                className={`group relative flex flex-col justify-between rounded-2xl border p-3.5 transition-all duration-150 cursor-pointer select-none shadow-sm ${
                                                    inCartQty > 0
                                                        ? 'border-indigo-500 bg-indigo-50/30 shadow-md ring-2 ring-indigo-500/20 dark:bg-indigo-950/20'
                                                        : 'border-slate-200/90 bg-white hover:-translate-y-0.5 hover:border-indigo-400 hover:shadow-lg dark:border-neutral-800 dark:bg-neutral-900'
                                                }`}
                                            >
                                                <div>
                                                    <div className="flex items-center justify-between gap-1">
                                                        <span className="rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] font-bold text-slate-600 dark:bg-neutral-800 dark:text-slate-300">
                                                            {prod.sku}
                                                        </span>

                                                        <div className="flex items-center gap-1">
                                                            {prod.primary_location_name && (
                                                                <span className="rounded-md bg-amber-50 px-1.5 py-0.5 text-[9px] font-bold text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                                                                    📍 {prod.primary_location_name}
                                                                </span>
                                                            )}
                                                            {prod.is_kit && (
                                                                <span className="rounded-md bg-purple-100 px-1.5 py-0.5 text-[10px] font-extrabold text-purple-700 dark:bg-purple-950 dark:text-purple-300">
                                                                    KIT
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>

                                                    <h3 className="mt-2 line-clamp-2 text-xs font-semibold leading-snug text-slate-800 dark:text-slate-100 group-hover:text-indigo-600 dark:group-hover:text-indigo-400">
                                                        {prod.name}
                                                    </h3>
                                                </div>

                                                <div className="mt-3 flex items-end justify-between border-t border-slate-100 pt-2.5 dark:border-neutral-800">
                                                    <div>
                                                        {/* Stock badge */}
                                                        <div className="flex items-center gap-1 mb-1">
                                                            {prod.available_stock > 0 ? (
                                                                <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300">
                                                                    <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                                                                    {prod.available_stock} disp.
                                                                    {selectedLocationId && prod.global_available_stock > prod.available_stock && (
                                                                        <span className="text-[9px] text-slate-400 font-normal">
                                                                            ({prod.global_available_stock} tot)
                                                                        </span>
                                                                    )}
                                                                </span>
                                                            ) : prod.global_available_stock > 0 ? (
                                                                <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700 dark:bg-amber-950/50 dark:text-amber-300">
                                                                    0 aquí ({prod.global_available_stock} almacén)
                                                                </span>
                                                            ) : (
                                                                <span className="inline-flex items-center rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-bold text-rose-600 dark:bg-rose-950/40 dark:text-rose-400">
                                                                    Sin stock
                                                                </span>
                                                            )}
                                                        </div>

                                                        {/* Price */}
                                                        <div>
                                                            {price > 0 ? (
                                                                <div className="flex items-baseline gap-1.5">
                                                                    <span className={`font-mono text-base font-extrabold ${
                                                                        customerType === 'stylist'
                                                                            ? 'text-purple-700 dark:text-purple-300'
                                                                            : 'text-slate-900 dark:text-white'
                                                                    }`}>
                                                                        ${Number(price).toFixed(2)}
                                                                    </span>
                                                                    {customerType === 'stylist' && prod.price_public > price && (
                                                                        <span className="font-mono text-[10px] text-slate-400 line-through">
                                                                            ${Number(prod.price_public).toFixed(2)}
                                                                        </span>
                                                                    )}
                                                                </div>
                                                            ) : (
                                                                <span className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-800 dark:bg-amber-900 dark:text-amber-200">
                                                                    ⚠️ Sin precio
                                                                </span>
                                                            )}
                                                        </div>
                                                    </div>

                                                    {/* Add button / Cart counter */}
                                                    {inCartQty > 0 ? (
                                                        <span className="flex h-8 items-center rounded-xl bg-indigo-600 px-2 text-xs font-bold text-white shadow-sm">
                                                            x{inCartQty}
                                                        </span>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="flex h-8 w-8 items-center justify-center rounded-xl bg-slate-100 text-slate-700 shadow-sm transition hover:bg-indigo-600 hover:text-white dark:bg-neutral-800 dark:text-slate-200"
                                                        >
                                                            +
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                        )
                                    })
                            )}
                        </div>
                    </div>

                    {/* RIGHT COLUMN: TICKET CART & PAYMENT (5 COLS) */}
                    <div className="space-y-4 lg:col-span-5">
                        <div className="flex flex-col justify-between rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-neutral-800 dark:bg-neutral-900 min-h-[580px]">
                            {/* CART HEADER */}
                            <div>
                                <div className="flex items-center justify-between border-b pb-3 dark:border-neutral-800">
                                    <h2 className="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                        <span>🛒</span> Carrito de Venta
                                        <span className="rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-bold text-indigo-600 dark:bg-indigo-950 dark:text-indigo-400">
                                            {cart.reduce((sum, i) => sum + i.quantity, 0)}
                                        </span>
                                    </h2>
                                    {cart.length > 0 && (
                                        <button
                                            type="button"
                                            onClick={() => setCart([])}
                                            className="text-xs text-red-500 hover:text-red-700 font-semibold"
                                        >
                                            Vaciar
                                        </button>
                                    )}
                                </div>

                                {/* CUSTOMER INFO & SELECTION */}
                                <div className="mt-3 rounded-xl border border-slate-200 bg-slate-50/70 p-3 dark:border-neutral-800 dark:bg-neutral-950 text-xs">
                                    <div className="flex items-center justify-between pb-1.5">
                                        <span className="font-bold text-slate-700 dark:text-slate-300 flex items-center gap-1.5">
                                            <span>👤</span> Cliente / Estilista:
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => setQuickCustomerModalOpen(true)}
                                            className="text-[11px] font-bold text-indigo-600 hover:underline dark:text-indigo-400"
                                        >
                                            + Registrar Nuevo
                                        </button>
                                    </div>

                                    {selectedCustomer ? (
                                        <div className="rounded-xl bg-indigo-50/90 p-3 text-indigo-950 dark:bg-indigo-950/40 dark:text-indigo-200 border border-indigo-200 dark:border-indigo-800">
                                            <div className="flex items-start justify-between">
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-1.5">
                                                        <span className="h-6 w-6 rounded-full bg-indigo-600 text-white flex items-center justify-center font-bold text-[10px]">
                                                            {selectedCustomer.name.charAt(0).toUpperCase()}
                                                        </span>
                                                        <p className="font-bold truncate text-sm">{selectedCustomer.name}</p>
                                                        {selectedCustomer.business_name && (
                                                            <span className="rounded bg-indigo-200 px-1.5 py-0.2 text-[10px] font-semibold text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300">
                                                                {selectedCustomer.business_name}
                                                            </span>
                                                        )}
                                                    </div>
                                                    <div className="mt-1 flex flex-wrap items-center gap-2 text-[11px] text-slate-500 dark:text-slate-400">
                                                        <span>Tel: {selectedCustomer.phone || 'Sin tel'}</span>
                                                        <span>• Plazo: {creditDays} días</span>
                                                    </div>
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={() => handleSelectCustomer(null)}
                                                    className="ml-2 rounded-lg p-1 text-slate-400 hover:bg-white hover:text-slate-700 dark:hover:bg-neutral-800"
                                                    title="Quitar cliente seleccionado"
                                                >
                                                    ✕
                                                </button>
                                            </div>

                                            {/* Available Credit Meter */}
                                            {selectedCustomer.credit_limit && (
                                                <div className="mt-2 pt-2 border-t border-indigo-200/60 dark:border-indigo-800/60">
                                                    <div className="flex justify-between text-[10px] font-semibold">
                                                        <span className="text-slate-500">Límite: ${Number(selectedCustomer.credit_limit).toFixed(2)}</span>
                                                        <span className="text-emerald-700 dark:text-emerald-400">
                                                            Disponible: ${Math.max(0, Number(selectedCustomer.credit_limit) - Number(selectedCustomer.total_debt || 0)).toFixed(2)}
                                                        </span>
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    ) : (
                                        <div className="space-y-1.5 relative">
                                            <div className="grid grid-cols-2 gap-2">
                                                <div className="relative">
                                                    <input
                                                        type="text"
                                                        value={customerName}
                                                        onChange={(e) => {
                                                            setCustomerName(e.target.value)
                                                            setCustomerDropdownOpen(true)
                                                        }}
                                                        onFocus={() => setCustomerDropdownOpen(true)}
                                                        placeholder="Nombre del cliente..."
                                                        className="w-full rounded-lg border border-slate-300 p-2 text-xs dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                                                    />
                                                </div>
                                                <div>
                                                    <input
                                                        type="text"
                                                        value={customerPhone}
                                                        onChange={(e) => setCustomerPhone(e.target.value)}
                                                        placeholder="WhatsApp o celular"
                                                        className="w-full rounded-lg border border-slate-300 p-2 text-xs dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                                                    />
                                                </div>
                                            </div>

                                            {/* Dropdown list of existing customers */}
                                            {customerDropdownOpen && customerList.length > 0 && (
                                                <div className="absolute left-0 right-0 top-full z-20 mt-1 max-h-48 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl dark:border-neutral-700 dark:bg-neutral-900 divide-y dark:divide-neutral-800 text-xs">
                                                    <div className="flex items-center justify-between px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-slate-400">
                                                        <span>Clientes registrados</span>
                                                        <button
                                                            type="button"
                                                            onClick={() => setCustomerDropdownOpen(false)}
                                                            className="text-slate-400 hover:text-slate-600"
                                                        >
                                                            ✕
                                                        </button>
                                                    </div>
                                                    {customerList
                                                        .filter(
                                                            (c) =>
                                                                !customerName ||
                                                                customerName === 'Público en general' ||
                                                                c.name.toLowerCase().includes(customerName.toLowerCase()) ||
                                                                (c.business_name && c.business_name.toLowerCase().includes(customerName.toLowerCase()))
                                                        )
                                                        .slice(0, 10)
                                                        .map((c) => (
                                                            <div
                                                                key={c.id}
                                                                onClick={() => handleSelectCustomer(c)}
                                                                className="cursor-pointer p-2 hover:bg-indigo-50 dark:hover:bg-neutral-800 transition rounded-lg flex items-center justify-between"
                                                            >
                                                                <div>
                                                                    <span className="font-bold text-slate-800 dark:text-slate-200">{c.name}</span>
                                                                    {c.business_name && (
                                                                        <span className="text-[10px] text-slate-500 ml-1">({c.business_name})</span>
                                                                    )}
                                                                </div>
                                                                <span className="font-mono text-[10px] text-slate-400">{c.phone || ''}</span>
                                                            </div>
                                                        ))}
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>

                                {/* CART ITEMS LIST */}
                                <div className="mt-3 max-h-[220px] overflow-y-auto space-y-2 pr-1">
                                    {cart.length === 0 ? (
                                        <div className="py-12 text-center text-xs text-slate-400">
                                            No hay productos en el carrito.<br />Escanea un código de barras o pulsa en el catálogo.
                                        </div>
                                    ) : (
                                        cart.map((item, index) => (
                                            <div
                                                key={item.product_id}
                                                className="flex items-center justify-between gap-2 rounded-xl border border-slate-100 bg-slate-50/70 p-2.5 text-xs dark:border-neutral-800 dark:bg-neutral-950 shadow-sm"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate font-semibold text-slate-800 dark:text-slate-200">
                                                        {item.name}
                                                    </p>
                                                    {/* Editable Unit Price on the fly */}
                                                    <div className="flex items-center gap-1 mt-0.5">
                                                        <span className="text-[10px] text-slate-400">$</span>
                                                        <input
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                            value={item.unit_price}
                                                            onChange={(e) => updateItemPrice(index, e.target.value)}
                                                            className="w-16 rounded border border-slate-200 bg-white px-1 py-0.5 text-right font-mono font-bold text-xs text-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                                                            title="Clic para editar precio unitario"
                                                        />
                                                        <span className="text-[10px] text-slate-400">c/u</span>
                                                    </div>
                                                </div>

                                                {/* Quantity Controls */}
                                                <div className="flex items-center gap-1.5">
                                                    <button
                                                        type="button"
                                                        onClick={() => updateQuantity(index, item.quantity - 1)}
                                                        className="flex h-7 w-7 items-center justify-center rounded-lg bg-white border border-slate-200 text-xs font-bold hover:bg-slate-100 dark:border-neutral-700 dark:bg-neutral-800 shadow-sm"
                                                    >
                                                        -
                                                    </button>
                                                    <span className="w-6 text-center font-mono font-bold text-slate-800 dark:text-slate-200">
                                                        {item.quantity}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        onClick={() => updateQuantity(index, item.quantity + 1)}
                                                        className="flex h-7 w-7 items-center justify-center rounded-lg bg-white border border-slate-200 text-xs font-bold hover:bg-slate-100 dark:border-neutral-700 dark:bg-neutral-800 shadow-sm"
                                                    >
                                                        +
                                                    </button>
                                                </div>

                                                {/* Subtotal */}
                                                <div className="w-16 text-right font-mono font-bold text-slate-900 dark:text-white">
                                                    ${Number(item.subtotal).toFixed(2)}
                                                </div>

                                                <button
                                                    type="button"
                                                    onClick={() => removeFromCart(index)}
                                                    className="p-1 text-slate-400 hover:text-red-500"
                                                    title="Eliminar del carrito"
                                                >
                                                    ✕
                                                </button>
                                            </div>
                                        ))
                                    )}
                                </div>
                            </div>

                            {/* TOTALS & CHECKOUT PANEL */}
                            <form onSubmit={handleCheckout} className="border-t border-slate-200 pt-3 dark:border-neutral-800 space-y-3">
                                <div className="space-y-1.5 text-xs">
                                    <div className="flex justify-between text-slate-500">
                                        <span>Subtotal:</span>
                                        <span className="font-mono font-medium">${cartSubtotal.toFixed(2)}</span>
                                    </div>
                                    <div className="flex items-center justify-between text-slate-500">
                                        <span>Descuento global ($):</span>
                                        <input
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            placeholder="0.00"
                                            value={globalDiscount}
                                            onChange={(e) => setGlobalDiscount(e.target.value)}
                                            className="w-24 rounded-lg border border-slate-200 p-1 text-right font-mono text-xs dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                        />
                                    </div>
                                    <div className="flex justify-between text-base font-bold text-slate-900 dark:text-white pt-1 border-t dark:border-neutral-800">
                                        <span>TOTAL A PAGAR:</span>
                                        <span className="font-mono text-xl font-extrabold text-indigo-600 dark:text-indigo-400">
                                            ${cartTotal.toFixed(2)}
                                        </span>
                                    </div>
                                </div>

                                {/* PAYMENT METHODS */}
                                <div>
                                    <label className="block text-[11px] font-semibold text-slate-500 mb-1">Método de pago:</label>
                                    <div className="grid grid-cols-4 gap-1.5 text-xs font-semibold">
                                        <button
                                            type="button"
                                            onClick={() => setPaymentMethod('cash')}
                                            className={`rounded-xl border p-2 text-center transition ${
                                                paymentMethod === 'cash'
                                                    ? 'border-indigo-600 bg-indigo-50 text-indigo-700 shadow-sm font-bold dark:border-indigo-500 dark:bg-indigo-950/40 dark:text-indigo-300'
                                                    : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-neutral-800 dark:bg-neutral-800 dark:text-slate-300'
                                            }`}
                                        >
                                            💵 Efectivo
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setPaymentMethod('card')}
                                            className={`rounded-xl border p-2 text-center transition ${
                                                paymentMethod === 'card'
                                                    ? 'border-indigo-600 bg-indigo-50 text-indigo-700 shadow-sm font-bold dark:border-indigo-500 dark:bg-indigo-950/40 dark:text-indigo-300'
                                                    : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-neutral-800 dark:bg-neutral-800 dark:text-slate-300'
                                            }`}
                                        >
                                            💳 Tarjeta
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setPaymentMethod('transfer')}
                                            className={`rounded-xl border p-2 text-center transition ${
                                                paymentMethod === 'transfer'
                                                    ? 'border-indigo-600 bg-indigo-50 text-indigo-700 shadow-sm font-bold dark:border-indigo-500 dark:bg-indigo-950/40 dark:text-indigo-300'
                                                    : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50 dark:border-neutral-800 dark:bg-neutral-800 dark:text-slate-300'
                                            }`}
                                        >
                                            📱 Transf.
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setPaymentMethod('credit')
                                                if (customerType !== 'stylist') {
                                                    handleCustomerTypeChange('stylist')
                                                }
                                            }}
                                            className={`rounded-xl border p-2 text-center transition ${
                                                paymentMethod === 'credit'
                                                    ? 'border-purple-600 bg-purple-600 text-white font-extrabold shadow-md'
                                                    : 'border-purple-300 bg-purple-50 text-purple-700 hover:bg-purple-100 font-bold dark:border-purple-800 dark:bg-purple-950/40 dark:text-purple-300'
                                            }`}
                                        >
                                            📜 A Crédito
                                        </button>
                                    </div>
                                </div>

                                {/* EXECUTIVE CREDIT TERMS & FINANCING PANEL */}
                                {paymentMethod === 'credit' && (
                                    <div className="rounded-2xl border-2 border-purple-400 bg-purple-50/70 p-3.5 dark:border-purple-700 dark:bg-purple-950/30 text-xs space-y-3 shadow-sm">
                                        <div className="flex items-center justify-between">
                                            <span className="font-extrabold text-purple-900 dark:text-purple-200 flex items-center gap-1.5">
                                                <span>📋</span> Plazo de Crédito:
                                            </span>
                                            <div className="flex gap-1.5 font-bold">
                                                {[7, 15, 30].map((days) => (
                                                    <button
                                                        key={days}
                                                        type="button"
                                                        onClick={() => setCreditDays(days)}
                                                        className={`px-3 py-1.5 rounded-xl transition font-bold ${
                                                            creditDays === days
                                                                ? 'bg-purple-700 text-white shadow-md'
                                                                : 'bg-white text-purple-800 border border-purple-200 hover:bg-purple-100 dark:bg-neutral-900 dark:text-purple-300 dark:border-purple-800'
                                                        }`}
                                                    >
                                                        {days} días
                                                    </button>
                                                ))}
                                            </div>
                                        </div>

                                        <div className="flex items-center justify-between rounded-xl bg-white/80 p-2 text-slate-700 dark:bg-neutral-900/80 dark:text-slate-300">
                                            <span className="text-[11px] font-medium">📅 Fecha límite de pago:</span>
                                            <span className="font-bold text-red-600 font-mono text-xs">
                                                {calculateDueDate(creditDays)}
                                            </span>
                                        </div>

                                        <div className="grid grid-cols-2 gap-2">
                                            <div>
                                                <label className="block text-[10px] font-bold text-purple-900 dark:text-purple-300">
                                                    Anticipo / Enganche ($):
                                                </label>
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    max={cartTotal}
                                                    placeholder="0.00"
                                                    value={creditDownpayment}
                                                    onChange={(e) => setCreditDownpayment(e.target.value)}
                                                    className="mt-1 w-full rounded-xl border border-purple-300 bg-white p-2 text-right font-mono font-bold text-xs dark:border-purple-800 dark:bg-neutral-900 dark:text-white"
                                                />
                                            </div>
                                            <div>
                                                <label className="block text-[10px] font-bold text-purple-900 dark:text-purple-300">
                                                    Saldo en Pagaré:
                                                </label>
                                                <div className="mt-1 p-2 rounded-xl bg-purple-100/80 text-right font-mono font-black text-sm text-purple-900 dark:bg-purple-900/50 dark:text-purple-200">
                                                    ${Math.max(0, cartTotal - (Number(creditDownpayment) || 0)).toFixed(2)}
                                                </div>
                                            </div>
                                        </div>

                                        <p className="text-[10px] text-purple-700 dark:text-purple-300 italic">
                                            ℹ️ Se generará pagaré mercantil y nota de remisión para firma del cliente.
                                        </p>
                                    </div>
                                )}

                                {/* CASH TENDERED & CHANGE CALCULATOR */}
                                {paymentMethod === 'cash' && (
                                    <div className="space-y-2 rounded-2xl bg-slate-50 p-3 dark:bg-neutral-950 text-xs border border-slate-200 dark:border-neutral-800">
                                        {/* Quick Bills Shortcuts */}
                                        <div className="flex items-center gap-1.5 flex-wrap">
                                            <span className="text-[10px] font-bold text-slate-400">Atajos:</span>
                                            {[50, 100, 200, 500, 1000].map((bill) => (
                                                <button
                                                    key={bill}
                                                    type="button"
                                                    onClick={() => setAmountTendered(String(bill))}
                                                    className="rounded-lg bg-white border border-slate-200 px-2 py-0.5 font-mono text-[10px] font-bold text-slate-700 hover:bg-indigo-50 hover:text-indigo-600 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-300"
                                                >
                                                    ${bill}
                                                </button>
                                            ))}
                                            <button
                                                type="button"
                                                onClick={() => setAmountTendered(String(cartTotal))}
                                                className="rounded-lg bg-indigo-50 border border-indigo-200 px-2 py-0.5 text-[10px] font-bold text-indigo-700 hover:bg-indigo-100 dark:bg-indigo-950/40 dark:text-indigo-300"
                                            >
                                                Exacto
                                            </button>
                                        </div>

                                        <div className="grid grid-cols-2 gap-2 pt-1">
                                            <div>
                                                <label className="block text-[11px] font-semibold text-slate-600 dark:text-slate-400">
                                                    Efectivo recibido:
                                                </label>
                                                <div className="mt-1 flex items-center gap-1 rounded-xl border border-slate-300 bg-white px-2.5 py-1.5 dark:border-neutral-700 dark:bg-neutral-900">
                                                    <span className="font-mono text-slate-400">$</span>
                                                    <input
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        placeholder="0.00"
                                                        value={amountTendered}
                                                        onChange={(e) => setAmountTendered(e.target.value)}
                                                        className="w-full bg-transparent font-mono text-xs font-bold outline-none dark:text-white"
                                                    />
                                                </div>
                                            </div>
                                            <div>
                                                <label className="block text-[11px] font-semibold text-slate-600 dark:text-slate-400">
                                                    Cambio:
                                                </label>
                                                <div className="mt-1 flex items-center px-2 py-1.5 font-mono text-base font-extrabold text-emerald-600 dark:text-emerald-400">
                                                    ${changeDue.toFixed(2)}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                )}

                                <button
                                    type="submit"
                                    disabled={cart.length === 0 || submitting}
                                    className={`w-full rounded-2xl py-3.5 text-sm font-extrabold text-white shadow-xl transition disabled:cursor-not-allowed disabled:opacity-40 ${
                                        paymentMethod === 'credit'
                                            ? 'bg-purple-600 hover:bg-purple-700'
                                            : 'bg-indigo-600 hover:bg-indigo-700'
                                    }`}
                                >
                                    {submitting
                                        ? 'Procesando...'
                                        : paymentMethod === 'credit'
                                        ? `📜 Generar Venta a Crédito y Pagaré ($${cartTotal.toFixed(2)})`
                                        : `✓ Cobrar $${cartTotal.toFixed(2)}`}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            {/* MODAL: SALE COMPLETED & THERMAL RECEIPT */}
            {lastSaleModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl dark:bg-neutral-900">
                        <div className="text-center">
                            <span className="inline-flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-2xl text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400">
                                ✓
                            </span>
                            <h3 className="mt-2 text-lg font-bold text-slate-900 dark:text-white">
                                Venta completada
                            </h3>
                            <p className="font-mono text-xs text-slate-500">
                                Folio: <strong>{lastSaleModal.sale_number}</strong>
                            </p>
                        </div>

                        {/* THERMAL TICKET PREVIEW */}
                        <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-neutral-800 dark:bg-neutral-950">
                            <div className="flex items-center justify-between pb-2 mb-2 border-b text-[10px] text-slate-400 dark:border-neutral-800">
                                <span>Vista previa de ticket ({paperWidth})</span>
                                <div className="flex gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setPaperWidth('80mm')}
                                        className={`px-1.5 py-0.5 rounded ${paperWidth === '80mm' ? 'bg-indigo-600 text-white font-bold' : ''}`}
                                    >
                                        80mm
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setPaperWidth('58mm')}
                                        className={`px-1.5 py-0.5 rounded ${paperWidth === '58mm' ? 'bg-indigo-600 text-white font-bold' : ''}`}
                                    >
                                        58mm
                                    </button>
                                </div>
                            </div>
                            <pre className="max-h-48 overflow-y-auto whitespace-pre-wrap font-mono text-[10px] leading-tight text-slate-700 dark:text-slate-300">
                                {lastSaleModal.receipt?.text || 'Generando ticket...'}
                            </pre>
                        </div>

                        {/* PRINTING BUTTONS */}
                        <div className="mt-4 grid grid-cols-2 gap-2">
                            {lastSaleModal.payment_method === 'credit' && (
                                <a
                                    href={`/pos/sales/${lastSaleModal.id}/voucher`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="col-span-2 flex items-center justify-center gap-1.5 rounded-xl bg-purple-600 py-2.5 text-xs font-bold text-white shadow hover:bg-purple-700"
                                >
                                    📄 Ver Pagaré / Comprobante de Crédito (PDF)
                                </a>
                            )}

                            {lastSaleModal.receipt?.escpos_base64 && (
                                <button
                                    type="button"
                                    onClick={() => handlePrintQz(lastSaleModal.receipt.escpos_base64)}
                                    className="rounded-xl bg-indigo-600 py-2.5 text-xs font-bold text-white hover:bg-indigo-700 shadow"
                                >
                                    🖨️ Imprimir Térmica (QZ)
                                </button>
                            )}

                            {esAndroid() && lastSaleModal.receipt?.escpos_base64 && (
                                <button
                                    type="button"
                                    onClick={() => handlePrintRawBt(lastSaleModal.receipt.escpos_base64)}
                                    className="rounded-xl border border-cyan-500 bg-cyan-50 py-2.5 text-xs font-bold text-cyan-800 hover:bg-cyan-100 dark:bg-cyan-950 dark:text-cyan-300"
                                >
                                    📱 Abrir en RawBT
                                </button>
                            )}

                            <button
                                type="button"
                                onClick={() => printViaBrowser(lastSaleModal.receipt?.text || '', paperWidth)}
                                className="rounded-xl border border-slate-300 bg-white py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                            >
                                📄 Imprimir Navegador
                            </button>

                            <button
                                type="button"
                                onClick={() => setLastSaleModal(null)}
                                className="rounded-xl bg-slate-900 py-2.5 text-xs font-bold text-white hover:bg-black dark:bg-neutral-800"
                            >
                                + Nueva venta
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL: OPEN SHIFT (ABRIR CAJA) */}
            {shiftOpenModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl dark:bg-neutral-900">
                        <h3 className="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>🟢</span> Apertura de Turno / Caja
                        </h3>
                        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Ingresa el fondo de efectivo inicial en caja para cambio.
                        </p>

                        <form onSubmit={handleOpenShift} className="mt-4 space-y-3">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                                    Fondo inicial en efectivo ($):
                                </label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    required
                                    value={openingCashInput}
                                    onChange={(e) => setOpeningCashInput(e.target.value)}
                                    placeholder="500.00"
                                    className="mt-1 w-full rounded-xl border border-slate-300 p-2.5 font-mono text-sm font-bold dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                                    Notas u observaciones (opcional):
                                </label>
                                <textarea
                                    rows={2}
                                    value={openingNotesInput}
                                    onChange={(e) => setOpeningNotesInput(e.target.value)}
                                    placeholder="ej. Billetes de 20 y monedas entregadas por supervisor"
                                    className="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => setShiftOpenModalOpen(false)}
                                    className="rounded-xl border px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    disabled={shiftLoading}
                                    className="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white shadow hover:bg-emerald-700 disabled:opacity-40"
                                >
                                    {shiftLoading ? 'Abriendo...' : 'Confirmar Apertura'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* MODAL: CASH MOVEMENT (INGRESO / RETIRO) */}
            {cashMovementModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl dark:bg-neutral-900">
                        <h3 className="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                            <span>💵</span> Movimiento Extraordinario de Caja
                        </h3>
                        <p className="mt-1 text-xs text-slate-500">
                            Registra entradas o salidas de efectivo que no corresponden a ventas directas.
                        </p>

                        <form onSubmit={handleAddCashMovement} className="mt-4 space-y-3">
                            <div className="grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    onClick={() => setMovementType('OUT')}
                                    className={`rounded-xl border p-2 text-center text-xs font-bold transition ${
                                        movementType === 'OUT'
                                            ? 'border-red-500 bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-400'
                                            : 'border-slate-200 text-slate-600 dark:border-neutral-800 dark:text-slate-400'
                                    }`}
                                >
                                    (-) Retiro / Gasto
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setMovementType('IN')}
                                    className={`rounded-xl border p-2 text-center text-xs font-bold transition ${
                                        movementType === 'IN'
                                            ? 'border-emerald-500 bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400'
                                            : 'border-slate-200 text-slate-600 dark:border-neutral-800 dark:text-slate-400'
                                    }`}
                                >
                                    (+) Ingreso Extra
                                </button>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                                    Importe ($):
                                </label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    required
                                    value={movementAmount}
                                    onChange={(e) => setMovementAmount(e.target.value)}
                                    placeholder="0.00"
                                    className="mt-1 w-full rounded-xl border border-slate-300 p-2 font-mono text-sm font-bold dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                                    Motivo / Concepto:
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={movementReason}
                                    onChange={(e) => setMovementReason(e.target.value)}
                                    placeholder="ej. Pago de garrafón de agua, cambio traído del banco..."
                                    className="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => setCashMovementModalOpen(false)}
                                    className="rounded-xl border px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    disabled={shiftLoading}
                                    className="rounded-xl bg-indigo-600 px-4 py-1.5 text-xs font-bold text-white shadow hover:bg-indigo-700 disabled:opacity-40"
                                >
                                    {shiftLoading ? 'Guardando...' : 'Registrar'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* MODAL: CORTE DE CAJA (ARQUEO Y CIERRE DE TURNO) */}
            {shiftCutModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                    <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl dark:bg-neutral-900 max-h-[90vh] overflow-y-auto">
                        <div className="flex items-center justify-between border-b pb-3 dark:border-neutral-800">
                            <div>
                                <h3 className="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                    <span>✂️</span> Arqueo y Corte de Caja (Turno #{shift?.id})
                                </h3>
                                <p className="text-xs text-slate-500">Cajero: {shift?.cashier?.name}</p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setShiftCutModalOpen(false)}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                ✕
                            </button>
                        </div>

                        {/* LIVE SUMMARY ARQUEO */}
                        <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3.5 space-y-2 text-xs dark:border-neutral-800 dark:bg-neutral-950">
                            <h4 className="font-bold text-slate-700 dark:text-slate-300 uppercase tracking-wide text-[10px]">
                                Desglose de Efectivo
                            </h4>
                            <div className="flex justify-between text-slate-600 dark:text-slate-400">
                                <span>(+) Fondo inicial de apertura:</span>
                                <span className="font-mono font-bold">${Number(shift?.opening_cash || 0).toFixed(2)}</span>
                            </div>
                            <div className="flex justify-between text-slate-600 dark:text-slate-400">
                                <span>(+) Ventas en efectivo ({summary?.sales_count || 0} tickets):</span>
                                <span className="font-mono font-bold text-emerald-600">${Number(summary?.sales_cash || 0).toFixed(2)}</span>
                            </div>
                            <div className="flex justify-between text-slate-600 dark:text-slate-400">
                                <span>(+) Ingresos extraordinarios:</span>
                                <span className="font-mono font-bold text-emerald-600">${Number(summary?.cash_in || 0).toFixed(2)}</span>
                            </div>
                            <div className="flex justify-between text-slate-600 dark:text-slate-400">
                                <span>(-) Retiros / Gastos de caja:</span>
                                <span className="font-mono font-bold text-red-500">-${Number(summary?.cash_out || 0).toFixed(2)}</span>
                            </div>
                            <div className="border-t pt-2 flex justify-between text-sm font-bold text-slate-900 dark:text-white dark:border-neutral-800">
                                <span>(=) EFECTIVO ESPERADO EN CAJA:</span>
                                <span className="font-mono text-indigo-600 dark:text-indigo-400">${expectedCashInDrawer.toFixed(2)}</span>
                            </div>
                        </div>

                        {/* OTROS MÉTODOS */}
                        <div className="mt-3 grid grid-cols-2 gap-2 text-xs">
                            <div className="rounded-xl border border-slate-200 bg-white p-2.5 dark:border-neutral-800 dark:bg-neutral-950">
                                <span className="text-[10px] text-slate-400 block">Ventas Tarjeta:</span>
                                <span className="font-mono text-sm font-bold text-slate-800 dark:text-slate-200">
                                    ${Number(summary?.sales_card || 0).toFixed(2)}
                                </span>
                            </div>
                            <div className="rounded-xl border border-slate-200 bg-white p-2.5 dark:border-neutral-800 dark:bg-neutral-950">
                                <span className="text-[10px] text-slate-400 block">Ventas Transferencia:</span>
                                <span className="font-mono text-sm font-bold text-slate-800 dark:text-slate-200">
                                    ${Number(summary?.sales_transfer || 0).toFixed(2)}
                                </span>
                            </div>
                        </div>

                        {/* COUNTED CASH INPUT & DISCREPANCY */}
                        <form onSubmit={handleCloseShift} className="mt-4 space-y-3">
                            <div>
                                <label className="block text-xs font-bold text-slate-700 dark:text-slate-300">
                                    Efectivo Físico Contado en Caja ($):
                                </label>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    required
                                    value={closingCashCounted}
                                    onChange={(e) => setClosingCashCounted(e.target.value)}
                                    placeholder="Ingresa el dinero físico que hay en el cajón"
                                    className="mt-1 w-full rounded-xl border border-slate-300 p-2.5 font-mono text-base font-extrabold dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            {/* DISCREPANCY ALERT */}
                            {closingCashCounted !== '' && (
                                <div
                                    className={`rounded-xl p-3 text-xs font-semibold ${
                                        cutDifference === 0
                                            ? 'bg-emerald-50 text-emerald-800 border border-emerald-200 dark:bg-emerald-950 dark:text-emerald-300 dark:border-emerald-800'
                                            : cutDifference > 0
                                            ? 'bg-blue-50 text-blue-800 border border-blue-200 dark:bg-blue-950 dark:text-blue-300 dark:border-blue-800'
                                            : 'bg-red-50 text-red-800 border border-red-200 dark:bg-red-950 dark:text-red-300 dark:border-red-800'
                                    }`}
                                >
                                    {cutDifference === 0 && '✓ Caja cuadrada al centavo. No hay diferencias.'}
                                    {cutDifference > 0 && `▲ Sobrante de efectivo: +$${cutDifference.toFixed(2)}`}
                                    {cutDifference < 0 && `▼ Faltante de efectivo: -$${Math.abs(cutDifference).toFixed(2)}`}
                                </div>
                            )}

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                                    Observaciones o notas de cierre:
                                </label>
                                <textarea
                                    rows={2}
                                    value={closingNotes}
                                    onChange={(e) => setClosingNotes(e.target.value)}
                                    placeholder="ej. Todo en orden, propinas contabilizadas..."
                                    className="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => setShiftCutModalOpen(false)}
                                    className="rounded-xl border px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    disabled={shiftLoading || closingCashCounted === ''}
                                    className="rounded-xl bg-red-600 px-4 py-2 text-xs font-bold text-white shadow hover:bg-red-700 disabled:opacity-40"
                                >
                                    {shiftLoading ? 'Cerrando turno...' : 'Confirmar y Cerrar Caja'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* MODAL: CORTE DE CAJA COMPLETED RECEIPT */}
            {cutReceiptModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl dark:bg-neutral-900">
                        <div className="text-center">
                            <span className="inline-flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-2xl text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400">
                                ✂️
                            </span>
                            <h3 className="mt-2 text-lg font-bold text-slate-900 dark:text-white">
                                Turno Cerrado Exitosamente
                            </h3>
                            <p className="text-xs text-slate-500">Ticket de Corte de Caja (Z-Report)</p>
                        </div>

                        <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3 dark:border-neutral-800 dark:bg-neutral-950">
                            <pre className="max-h-56 overflow-y-auto whitespace-pre-wrap font-mono text-[10px] leading-tight text-slate-700 dark:text-slate-300">
                                {cutReceiptModal.text}
                            </pre>
                        </div>

                        <div className="mt-4 grid grid-cols-2 gap-2">
                            {cutReceiptModal.escpos_base64 && (
                                <button
                                    type="button"
                                    onClick={() => handlePrintQz(cutReceiptModal.escpos_base64)}
                                    className="rounded-xl bg-indigo-600 py-2.5 text-xs font-bold text-white hover:bg-indigo-700 shadow"
                                >
                                    🖨️ Imprimir Térmica (QZ)
                                </button>
                            )}

                            {esAndroid() && cutReceiptModal.escpos_base64 && (
                                <button
                                    type="button"
                                    onClick={() => handlePrintRawBt(cutReceiptModal.escpos_base64)}
                                    className="rounded-xl border border-cyan-500 bg-cyan-50 py-2.5 text-xs font-bold text-cyan-800 hover:bg-cyan-100 dark:bg-cyan-950 dark:text-cyan-300"
                                >
                                    📱 Abrir en RawBT
                                </button>
                            )}

                            <button
                                type="button"
                                onClick={() => printViaBrowser(cutReceiptModal.text, paperWidth)}
                                className="rounded-xl border border-slate-300 bg-white py-2.5 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                            >
                                📄 Imprimir Navegador
                            </button>

                            <button
                                type="button"
                                onClick={() => setCutReceiptModal(null)}
                                className="rounded-xl bg-slate-900 py-2.5 text-xs font-bold text-white hover:bg-black dark:bg-neutral-800"
                            >
                                Finalizar
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {/* MODAL: PRINTER & TICKET SETTINGS */}
            {settingsModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                    <div className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl dark:bg-neutral-900 space-y-4">
                        <div className="flex items-center justify-between border-b pb-3 dark:border-neutral-800">
                            <h3 className="text-base font-bold text-slate-900 dark:text-white">
                                Configuración de Tickets Térmicos
                            </h3>
                            <button
                                type="button"
                                onClick={() => setSettingsModalOpen(false)}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                ✕
                            </button>
                        </div>

                        <div>
                            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                                Ancho de papel:
                            </label>
                            <div className="mt-1 grid grid-cols-2 gap-2 text-xs font-bold">
                                <button
                                    type="button"
                                    onClick={() => {
                                        setPaperWidth('80mm')
                                        localStorage.setItem(THERMAL_PAPER_STORAGE_KEY, '80mm')
                                    }}
                                    className={`rounded-xl border p-2 text-center transition ${
                                        paperWidth === '80mm'
                                            ? 'border-indigo-600 bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300'
                                            : 'border-slate-200 dark:border-neutral-800'
                                    }`}
                                >
                                    80 mm (Estándar)
                                </button>
                                <button
                                    type="button"
                                    onClick={() => {
                                        setPaperWidth('58mm')
                                        localStorage.setItem(THERMAL_PAPER_STORAGE_KEY, '58mm')
                                    }}
                                    className={`rounded-xl border p-2 text-center transition ${
                                        paperWidth === '58mm'
                                            ? 'border-indigo-600 bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300'
                                            : 'border-slate-200 dark:border-neutral-800'
                                    }`}
                                >
                                    58 mm (Portátil)
                                </button>
                            </div>
                        </div>

                        <div>
                            <div className="flex items-center justify-between">
                                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                                    Impresora QZ Tray:
                                </label>
                                <button
                                    type="button"
                                    onClick={handleDetectPrinters}
                                    className="text-[11px] font-bold text-indigo-600 hover:underline dark:text-indigo-400"
                                >
                                    Buscar impresoras
                                </button>
                            </div>

                            {printerList.length > 0 ? (
                                <select
                                    value={printerName}
                                    onChange={(e) => {
                                        setPrinterName(e.target.value)
                                        localStorage.setItem(THERMAL_PRINTER_STORAGE_KEY, e.target.value)
                                    }}
                                    className="mt-1 w-full rounded-xl border border-slate-300 p-2 text-xs dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                >
                                    {printerList.map((prn) => (
                                        <option key={prn} value={prn}>
                                            {prn}
                                        </option>
                                    ))}
                                </select>
                            ) : (
                                <p className="mt-1 text-[11px] text-slate-400">
                                    {qzConnected
                                        ? 'QZ Tray conectado. Pulsa buscar impresoras.'
                                        : 'Abre QZ Tray en la computadora para enviar comandos directos sin diálogo.'}
                                </p>
                            )}
                        </div>

                        <div className="pt-2 flex justify-end">
                            <button
                                type="button"
                                onClick={() => setSettingsModalOpen(false)}
                                className="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white shadow hover:bg-indigo-700"
                            >
                                Listo
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
                                                <div className="flex items-center gap-1.5">
                                                    {sale.payment_method === 'credit' && (
                                                        <a
                                                            href={`/pos/sales/${sale.id}/voucher`}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="rounded-lg border border-purple-200 bg-purple-50 px-2 py-1 text-[11px] font-semibold text-purple-700 hover:bg-purple-100 dark:border-purple-800 dark:bg-purple-950/50 dark:text-purple-300"
                                                        >
                                                            📄 Pagaré
                                                        </a>
                                                    )}
                                                    <button
                                                        type="button"
                                                        onClick={async () => {
                                                            try {
                                                                const res = await fetch(`/pos/sales/${sale.id}/receipt?paper_type=${paperWidth}`, {
                                                                    headers: { Accept: 'application/json' },
                                                                })
                                                                const data = await res.json()
                                                                if (data.receipt) {
                                                                    setLastSaleModal({
                                                                        ...sale,
                                                                        receipt: data.receipt,
                                                                    })
                                                                }
                                                            } catch (err) {
                                                                window.alert('Error cargando ticket: ' + err.message)
                                                            }
                                                        }}
                                                        className="rounded-lg border border-slate-300 px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-100 dark:border-neutral-700 dark:text-slate-300"
                                                    >
                                                        🖨️ Ticket
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() => setCancellingSale(sale)}
                                                        className="rounded-lg border border-red-200 px-2 py-1 text-[11px] font-semibold text-red-600 hover:bg-red-50 dark:border-red-900 dark:text-red-400"
                                                    >
                                                        Cancelar
                                                    </button>
                                                </div>
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

            {/* MODAL: REGISTRAR CLIENTE / ESTILISTA RÁPIDO */}
            {quickCustomerModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl dark:bg-neutral-900">
                        <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-neutral-800">
                            <h3 className="text-base font-bold text-slate-900 dark:text-white flex items-center gap-2">
                                <span>👤</span> Registrar Nuevo Cliente / Estilista
                            </h3>
                            <button
                                type="button"
                                onClick={() => setQuickCustomerModalOpen(false)}
                                className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-neutral-800"
                            >
                                ✕
                            </button>
                        </div>

                        <form onSubmit={handleQuickCreateCustomer} className="mt-4 space-y-3">
                            <div>
                                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                                    Nombre completo *
                                </label>
                                <input
                                    type="text"
                                    required
                                    placeholder="ej. Laura Sánchez"
                                    value={newCustomerForm.name}
                                    onChange={(e) =>
                                        setNewCustomerForm({ ...newCustomerForm, name: e.target.value })
                                    }
                                    className="mt-1 w-full rounded-xl border border-slate-300 p-2.5 text-xs dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-2">
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                                        Negocio / Salón
                                    </label>
                                    <input
                                        type="text"
                                        placeholder="ej. Laura Studio"
                                        value={newCustomerForm.business_name}
                                        onChange={(e) =>
                                            setNewCustomerForm({ ...newCustomerForm, business_name: e.target.value })
                                        }
                                        className="mt-1 w-full rounded-xl border border-slate-300 p-2.5 text-xs dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                                        Teléfono / WhatsApp
                                    </label>
                                    <input
                                        type="tel"
                                        placeholder="6621234567"
                                        value={newCustomerForm.phone}
                                        onChange={(e) =>
                                            setNewCustomerForm({ ...newCustomerForm, phone: e.target.value })
                                        }
                                        className="mt-1 w-full rounded-xl border border-slate-300 p-2.5 text-xs dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                </div>
                            </div>

                            <div className="grid grid-cols-2 gap-2">
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                                        Límite de crédito ($)
                                    </label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={newCustomerForm.credit_limit}
                                        onChange={(e) =>
                                            setNewCustomerForm({ ...newCustomerForm, credit_limit: e.target.value })
                                        }
                                        className="mt-1 w-full rounded-xl border border-slate-300 p-2.5 text-xs dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                                        Días de crédito
                                    </label>
                                    <select
                                        value={newCustomerForm.credit_days_default}
                                        onChange={(e) =>
                                            setNewCustomerForm({
                                                ...newCustomerForm,
                                                credit_days_default: Number(e.target.value),
                                            })
                                        }
                                        className="mt-1 w-full rounded-xl border border-slate-300 p-2.5 text-xs dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    >
                                        <option value={7}>7 días</option>
                                        <option value={15}>15 días</option>
                                        <option value={30}>30 días</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300">
                                    Dirección (opcional)
                                </label>
                                <input
                                    type="text"
                                    placeholder="Colonia, calle, etc."
                                    value={newCustomerForm.address}
                                    onChange={(e) =>
                                        setNewCustomerForm({ ...newCustomerForm, address: e.target.value })
                                    }
                                    className="mt-1 w-full rounded-xl border border-slate-300 p-2.5 text-xs dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div className="flex justify-end gap-2 pt-3 border-t border-slate-100 dark:border-neutral-800">
                                <button
                                    type="button"
                                    onClick={() => setQuickCustomerModalOpen(false)}
                                    className="rounded-xl border border-slate-200 px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    className="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold text-white hover:bg-indigo-700 shadow"
                                >
                                    Guardar y Seleccionar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AppShell>
    )
}
