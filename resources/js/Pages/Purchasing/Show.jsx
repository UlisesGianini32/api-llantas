import { useState, useMemo, useRef, useEffect } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppShell from '@/Components/layout/AppShell'

function formatDate(dateStr) {
    if (!dateStr) return '-'
    const d = new Date(dateStr)
    return d.toLocaleDateString('es-MX', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    })
}

function formatDateTime(dateStr) {
    if (!dateStr) return '-'
    const d = new Date(dateStr)
    return d.toLocaleDateString('es-MX', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}

const STATUS_BADGES = {
    DRAFT: {
        label: 'Borrador',
        bg: 'bg-slate-100 text-slate-700 border-slate-300 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700',
    },
    ORDERED: {
        label: 'Colocada (En camino)',
        bg: 'bg-blue-100 text-blue-800 border-blue-300 dark:bg-blue-900/40 dark:text-blue-300 dark:border-blue-700',
    },
    PARTIAL: {
        label: 'Recepción Parcial',
        bg: 'bg-amber-100 text-amber-800 border-amber-300 dark:bg-amber-900/40 dark:text-amber-300 dark:border-amber-700',
    },
    RECEIVED: {
        label: 'Recibida Completa',
        bg: 'bg-emerald-100 text-emerald-800 border-emerald-300 dark:bg-emerald-900/40 dark:text-emerald-300 dark:border-emerald-700',
    },
    CANCELLED: {
        label: 'Cancelada',
        bg: 'bg-rose-100 text-rose-800 border-rose-300 dark:bg-rose-900/40 dark:text-rose-300 dark:border-rose-700',
    },
}

export default function PurchasingShow({ order, locations = [], supplier = null }) {
    const badge = STATUS_BADGES[order.status] || STATUS_BADGES.DRAFT

    // Autofill defaults
    const defaultQuoteRef = order.supplier_quote_reference || `${order.supplier_name || 'PROVEEDOR'} - ${order.order_number}`
    const todayDateStr = new Date().toISOString().slice(0, 10)
    const defaultOrderDate = order.ordered_at ? order.ordered_at.slice(0, 10) : todayDateStr

    // Modal states: Colocar orden
    const [orderModalOpen, setOrderModalOpen] = useState(false)
    const [orderQuoteRef, setOrderQuoteRef] = useState(defaultQuoteRef)
    const [orderDate, setOrderDate] = useState(defaultOrderDate)
    const [orderingSubmitting, setOrderingSubmitting] = useState(false)

    // Modal states: PDF Preview & WhatsApp send
    const [pdfModalOpen, setPdfModalOpen] = useState(false)
    const [whatsappPhone, setWhatsappPhone] = useState(supplier?.phone || '')
    const [downloadingPdf, setDownloadingPdf] = useState(false)
    const [pdfSentAlert, setPdfSentAlert] = useState(false)
    const pdfSheetRef = useRef(null)

    // Modal states: Recepción física
    const [receiveModalOpen, setReceiveModalOpen] = useState(
        typeof window !== 'undefined' && window.location.hash === '#recibir' && (order.status === 'ORDERED' || order.status === 'PARTIAL')
    )
    const [receiveLocationId, setReceiveLocationId] = useState(order.inventory_location_id || locations[0]?.id || '')
    const [receiveCarrier, setReceiveCarrier] = useState('')
    const [receiveTracking, setReceiveTracking] = useState('')
    const [receiveNotes, setReceiveNotes] = useState('')
    const [receivingItems, setReceivingItems] = useState(
        (order.items || []).map((item) => ({
            item_id: item.id,
            sku: item.product?.sku || item.sku || '-',
            name: item.product?.name || item.product_name || '',
            barcode: item.product?.barcode || '',
            barcode_secondary: item.product?.barcode_secondary || '',
            ordered: item.quantity_ordered,
            pending: Math.max(0, item.quantity_ordered - item.quantity_received),
            quantity_received: Math.max(0, item.quantity_ordered - item.quantity_received),
            is_extra: false,
        }))
    )
    const [receiveSubmitting, setReceiveSubmitting] = useState(false)

    // Scanner & catalog search in reception modal
    const [scanBarcode, setScanBarcode] = useState('')
    const [scanFeedback, setScanFeedback] = useState(null)
    const scanInputRef = useRef(null)

    const [showCatalogSearch, setShowCatalogSearch] = useState(false)
    const [catalogQuery, setCatalogQuery] = useState('')
    const [catalogResults, setCatalogResults] = useState([])
    const [catalogLoading, setCatalogLoading] = useState(false)

    // Modal states: Cancelación
    const [cancelModalOpen, setCancelModalOpen] = useState(false)
    const [cancelReason, setCancelReason] = useState('')
    const [cancelSubmitting, setCancelSubmitting] = useState(false)

    const percentReceived = order.total_units_ordered > 0
        ? Math.min(100, Math.round((order.total_units_received / order.total_units_ordered) * 100))
        : 0

    // Auto-focus scanner input when reception modal opens
    useEffect(() => {
        if (receiveModalOpen) {
            setTimeout(() => {
                scanInputRef.current?.focus()
            }, 100)
        }
    }, [receiveModalOpen])

    // Generate and download PDF from rendered sheet
    const handleDownloadPdf = async () => {
        if (!pdfSheetRef.current) return
        setDownloadingPdf(true)
        try {
            const html2pdf = (await import('html2pdf.js')).default
            const element = pdfSheetRef.current
            const opt = {
                margin: [8, 8, 8, 8],
                filename: `Orden-de-Compra-${order.order_number}.pdf`,
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true, logging: false },
                jsPDF: { unit: 'mm', format: 'letter', orientation: 'portrait' },
            }
            await html2pdf().set(opt).from(element).save()
        } catch (err) {
            console.error('Error al generar PDF:', err)
            window.open(`/compras/ordenes/${order.id}/pdf`, '_blank')
        } finally {
            setDownloadingPdf(false)
        }
    }

    // Send PDF via WhatsApp: downloads the PDF and opens WhatsApp with direct document link
    const handleSendWhatsAppWithPdf = async () => {
        // 1. Download PDF to machine so user can attach it
        await handleDownloadPdf()

        // 2. Open WhatsApp Web with supplier phone and direct link to official PDF
        const cleanPhone = (whatsappPhone || '').replace(/\D/g, '')
        const origin = typeof window !== 'undefined' ? window.location.origin : ''
        const publicPdfUrl = `${origin}/orden-compra/${order.id}/pdf`

        const msg = [
            `📄 *ORDEN DE COMPRA: ${order.order_number}*`,
            `*T.O. THE BEAUTY SHOP (SBS)*`,
            ``,
            `Estimado(a) ${order.supplier_name}, le comparto la orden de compra adjunta en formato PDF.`,
            ``,
            `Enlace directo al documento oficial:`,
            `${publicPdfUrl}`,
            ``,
            `*Total piezas solicitadas:* ${order.total_units_ordered} pzas`,
            `_Favor de confirmar acuse de recibo y fecha de entrega. ¡Muchas gracias!_`,
        ].join('\n')

        const encoded = encodeURIComponent(msg)
        const url = cleanPhone ? `https://wa.me/${cleanPhone}?text=${encoded}` : `https://wa.me/?text=${encoded}`
        window.open(url, '_blank')
        setPdfSentAlert(true)
    }

    // Handle "Mark as Ordered" (Colocar orden al proveedor)
    const handleMarkOrdered = (e) => {
        e.preventDefault()
        setOrderingSubmitting(true)
        router.post(
            `/compras/ordenes/${order.id}/ordenar`,
            {
                supplier_quote_reference: orderQuoteRef,
                ordered_at: orderDate,
                expected_delivery_date: orderDate,
            },
            {
                onFinish: () => {
                    setOrderingSubmitting(false)
                    setOrderModalOpen(false)
                },
            }
        )
    }

    // Barcode scanner trigger
    const handleScanBarcode = async (e) => {
        if (e) e.preventDefault()
        const code = scanBarcode.trim()
        if (!code) return

        setScanFeedback(null)

        // 1. Check if barcode or SKU matches existing receiving items
        const matchIndex = receivingItems.findIndex((it) => {
            const c = code.toLowerCase()
            return (
                (it.barcode && it.barcode.toLowerCase() === c) ||
                (it.barcode_secondary && it.barcode_secondary.toLowerCase() === c) ||
                (it.sku && it.sku.toLowerCase() === c)
            )
        })

        if (matchIndex !== -1) {
            const item = receivingItems[matchIndex]
            const newQty = (parseInt(item.quantity_received) || 0) + 1
            const updated = [...receivingItems]
            updated[matchIndex] = {
                ...item,
                quantity_received: newQty,
            }
            setReceivingItems(updated)
            setScanFeedback({
                type: 'success',
                text: `¡Escaneado (+1)! ${item.name || item.sku} - Total recibido: ${newQty}`,
            })
            setScanBarcode('')
            return
        }

        // 2. Query backend if not matched locally
        try {
            const res = await fetch(`/compras/ordenes/buscar-productos?q=${encodeURIComponent(code)}`)
            const data = await res.json()
            const foundProducts = data.data || []

            if (foundProducts.length > 0) {
                const prod = foundProducts.find(
                    (p) =>
                        (p.barcode && p.barcode.toLowerCase() === code.toLowerCase()) ||
                        (p.barcode_secondary && p.barcode_secondary.toLowerCase() === code.toLowerCase()) ||
                        (p.sku && p.sku.toLowerCase() === code.toLowerCase())
                ) || foundProducts[0]

                // Check if already in receivingItems by product_id
                const prodIndex = receivingItems.findIndex((it) => it.product_id === prod.id)
                if (prodIndex !== -1) {
                    const item = receivingItems[prodIndex]
                    const newQty = (parseInt(item.quantity_received) || 0) + 1
                    const updated = [...receivingItems]
                    updated[prodIndex] = { ...item, quantity_received: newQty }
                    setReceivingItems(updated)
                    setScanFeedback({
                        type: 'success',
                        text: `¡Escaneado (+1)! ${prod.name} - Total: ${newQty}`,
                    })
                } else {
                    // Add as extra product!
                    setReceivingItems((prev) => [
                        ...prev,
                        {
                            product_id: prod.id,
                            sku: prod.sku,
                            name: prod.name,
                            barcode: prod.barcode,
                            barcode_secondary: prod.barcode_secondary,
                            ordered: 0,
                            pending: 0,
                            quantity_received: 1,
                            is_extra: true,
                        },
                    ])
                    setScanFeedback({
                        type: 'success',
                        text: `¡Producto adicional ingresado! ${prod.name} (SKU: ${prod.sku})`,
                    })
                }
            } else {
                setScanFeedback({
                    type: 'error',
                    text: `Código "${code}" no encontrado en la orden ni en el catálogo de productos.`,
                })
            }
        } catch {
            setScanFeedback({
                type: 'error',
                text: 'Error de comunicación al buscar el producto escaneado.',
            })
        }

        setScanBarcode('')
    }

    // Catalog search for extra products
    const handleSearchCatalog = async (q) => {
        setCatalogQuery(q)
        if (!q || q.trim().length < 2) {
            setCatalogResults([])
            return
        }

        setCatalogLoading(true)
        try {
            const res = await fetch(`/compras/ordenes/buscar-productos?q=${encodeURIComponent(q)}`)
            const data = await res.json()
            setCatalogResults(data.data || [])
        } catch {
            setCatalogResults([])
        } finally {
            setCatalogLoading(false)
        }
    }

    const handleAddExtraProduct = (prod) => {
        const prodIndex = receivingItems.findIndex((it) => it.product_id === prod.id || it.sku === prod.sku)
        if (prodIndex !== -1) {
            const item = receivingItems[prodIndex]
            const newQty = (parseInt(item.quantity_received) || 0) + 1
            const updated = [...receivingItems]
            updated[prodIndex] = { ...item, quantity_received: newQty }
            setReceivingItems(updated)
        } else {
            setReceivingItems((prev) => [
                ...prev,
                {
                    product_id: prod.id,
                    sku: prod.sku,
                    name: prod.name,
                    barcode: prod.barcode,
                    barcode_secondary: prod.barcode_secondary,
                    ordered: 0,
                    pending: 0,
                    quantity_received: 1,
                    is_extra: true,
                },
            ])
        }
        setShowCatalogSearch(false)
        setCatalogQuery('')
        setCatalogResults([])
    }

    const handleRemoveExtraItem = (idx) => {
        setReceivingItems(receivingItems.filter((_, i) => i !== idx))
    }

    // Handle "Receive Items"
    const handleReceiveSubmit = (e) => {
        e.preventDefault()
        setReceiveSubmitting(true)

        const payloadItems = receivingItems
            .filter((item) => parseInt(item.quantity_received) > 0)
            .map((item) => {
                if (item.item_id) {
                    return {
                        item_id: item.item_id,
                        quantity_received: parseInt(item.quantity_received),
                    }
                }
                return {
                    product_id: item.product_id,
                    quantity_received: parseInt(item.quantity_received),
                }
            })

        if (payloadItems.length === 0) {
            alert('Debe ingresar al menos una cantidad mayor a 0 para recibir.')
            setReceiveSubmitting(false)
            return
        }

        router.post(
            `/compras/ordenes/${order.id}/recibir`,
            {
                inventory_location_id: receiveLocationId,
                carrier: receiveCarrier,
                tracking_number: receiveTracking,
                notes: receiveNotes,
                items: payloadItems,
            },
            {
                onFinish: () => {
                    setReceiveSubmitting(false)
                    setReceiveModalOpen(false)
                },
            }
        )
    }

    // Handle "Cancel Order"
    const handleCancelSubmit = (e) => {
        e.preventDefault()
        if (!cancelReason.trim()) return

        setCancelSubmitting(true)
        router.post(
            `/compras/ordenes/${order.id}/cancelar`,
            { reason: cancelReason },
            {
                onFinish: () => {
                    setCancelSubmitting(false)
                    setCancelModalOpen(false)
                },
            }
        )
    }

    const printOrder = () => {
        window.print()
    }

    return (
        <AppShell title={`Orden de Compra ${order.order_number}`}>
            <Head title={`Orden ${order.order_number} - ${order.supplier_name}`} />

            <style>{`
                @media print {
                    nav, header, aside, .print\\:hidden {
                        display: none !important;
                    }
                    body {
                        background: #ffffff !important;
                        color: #000000 !important;
                        font-size: 12pt !important;
                    }
                    .print-sheet {
                        border: none !important;
                        box-shadow: none !important;
                        padding: 0 !important;
                        margin: 0 !important;
                        width: 100% !important;
                    }
                }
            `}</style>

            <div className="space-y-6">
                {/* Top Action Bar (hidden when printing) */}
                <div className="print:hidden flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2 text-sm text-slate-500">
                            <Link href="/compras/ordenes" className="hover:text-indigo-600 dark:hover:text-indigo-400">
                                Órdenes de Compra
                            </Link>
                            <span>/</span>
                            <span className="font-semibold text-slate-800 dark:text-slate-200">
                                {order.order_number}
                            </span>
                        </div>
                        <div className="mt-1 flex items-center gap-3">
                            <h1 className="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">
                                {order.order_number}
                            </h1>
                            <span className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-bold ${badge.bg}`}>
                                {badge.label}
                            </span>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2.5">
                        {/* Imprimir / Descargar PDF directo */}
                        <button
                            type="button"
                            onClick={printOrder}
                            className="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200 dark:hover:bg-neutral-700"
                        >
                            <svg className="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
                            </svg>
                            Imprimir Formato
                        </button>

                        {/* Botón Principal: Ver PDF y Enviar por WhatsApp */}
                        <button
                            type="button"
                            onClick={() => {
                                setPdfSentAlert(false)
                                setPdfModalOpen(true)
                            }}
                            className="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 dark:bg-emerald-600 dark:hover:bg-emerald-500"
                        >
                            <svg className="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z" />
                            </svg>
                            Ver / Enviar PDF (WhatsApp)
                        </button>

                        {order.status === 'DRAFT' && (
                            <>
                                <button
                                    type="button"
                                    onClick={() => setOrderModalOpen(true)}
                                    className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700"
                                >
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                    </svg>
                                    Colocar Pedido a Proveedor
                                </button>

                                <button
                                    type="button"
                                    onClick={() => setCancelModalOpen(true)}
                                    className="inline-flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2 text-sm font-medium text-rose-700 transition hover:bg-rose-100 dark:border-rose-900/50 dark:bg-rose-950/20 dark:text-rose-400"
                                >
                                    Cancelar Orden
                                </button>
                            </>
                        )}

                        {(order.status === 'ORDERED' || order.status === 'PARTIAL') && (
                            <>
                                <button
                                    type="button"
                                    onClick={() => setReceiveModalOpen(true)}
                                    className="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700"
                                >
                                    <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                                    </svg>
                                    Recibir Mercancía en Almacén
                                </button>

                                {order.total_units_received === 0 && (
                                    <button
                                        type="button"
                                        onClick={() => setCancelModalOpen(true)}
                                        className="rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2 text-sm font-medium text-rose-700 hover:bg-rose-100 dark:border-rose-900/50 dark:bg-rose-950/20 dark:text-rose-400"
                                    >
                                        Cancelar
                                    </button>
                                )}
                            </>
                        )}
                    </div>
                </div>

                {/* Progress Summary Header (No financial costs displayed) */}
                <div className="print:hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Proveedor</p>
                            <p className="mt-1 text-base font-bold text-slate-900 dark:text-white">{order.supplier_name}</p>
                            {order.brand && <p className="text-xs text-indigo-600 dark:text-indigo-400 font-medium">Marca: {order.brand}</p>}
                        </div>

                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Almacén Destino</p>
                            <p className="mt-1 text-base font-bold text-slate-900 dark:text-white">
                                {order.location ? order.location.name : 'Sin asignar'}
                            </p>
                            <p className="text-xs text-slate-500">Cód: {order.location?.code}</p>
                        </div>

                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Progreso de Entrada</p>
                            <div className="mt-1 flex items-baseline gap-2">
                                <span className="text-xl font-bold text-slate-900 dark:text-white">
                                    {order.total_units_received} / {order.total_units_ordered}
                                </span>
                                <span className="text-xs font-semibold text-slate-500">unidades ({percentReceived}%)</span>
                            </div>
                            <div className="mt-1.5 h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-neutral-800">
                                <div
                                    className={`h-full ${percentReceived === 100 ? 'bg-emerald-500' : percentReceived > 0 ? 'bg-amber-500' : 'bg-slate-300'}`}
                                    style={{ width: `${percentReceived}%` }}
                                />
                            </div>
                        </div>

                        <div>
                            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Total Solicitado</p>
                            <p className="mt-1 text-xl font-bold text-slate-900 dark:text-white">
                                {order.total_units_ordered} <span className="text-sm font-medium text-slate-500">piezas</span>
                            </p>
                            <p className="text-xs text-slate-500">
                                {order.total_items_count || (order.items || []).length} partidas registradas
                            </p>
                        </div>
                    </div>
                </div>

                {/* Formal Printable Purchase Order Sheet (No unit cost, importe, or financial totals) */}
                <div className="print-sheet rounded-2xl border border-slate-200 bg-white p-8 shadow-sm print:border-none print:shadow-none print:p-0 dark:border-neutral-800 dark:bg-neutral-900">
                    {/* Header info */}
                    <div className="border-b border-slate-200 pb-6 dark:border-neutral-800">
                        <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                            <div>
                                <div className="flex items-center gap-3.5">
                                    <div className="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-full bg-slate-950 shadow-sm ring-1 ring-slate-900/10">
                                        <img
                                            src="/logo-beauty-shop.png"
                                            alt="T.O. THE BEAUTY SHOP"
                                            className="h-full w-full object-cover"
                                        />
                                    </div>
                                    <div>
                                        <h2 className="text-xl font-extrabold tracking-tight text-slate-900 dark:text-white">
                                            T.O. THE BEAUTY SHOP
                                        </h2>
                                        <p className="text-xs font-medium text-slate-500 dark:text-slate-400">
                                            Salon & Barber Supply (SBS) • Hermosillo, Sonora, México
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div className="text-right">
                                <h3 className="text-2xl font-black tracking-tight text-slate-900 dark:text-white">
                                    ORDEN DE COMPRA
                                </h3>
                                <p className="text-sm font-bold text-indigo-600 dark:text-indigo-400">
                                    {order.order_number}
                                </p>
                                <p className="mt-1 text-xs text-slate-500">
                                    Fecha de emisión: {formatDate(order.ordered_at || order.created_at)}
                                </p>
                            </div>
                        </div>

                        {/* Supplier & Delivery info grid */}
                        <div className="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <div className="rounded-xl border border-slate-200 bg-slate-50/50 p-4 dark:border-neutral-800 dark:bg-neutral-950/40">
                                <p className="text-xs font-bold uppercase tracking-wider text-slate-500">Datos del Proveedor</p>
                                <p className="mt-1 text-base font-bold text-slate-900 dark:text-white">{order.supplier_name}</p>
                                {order.brand && (
                                    <p className="text-xs font-semibold text-indigo-600 dark:text-indigo-400">
                                        Línea / Marca: {order.brand}
                                    </p>
                                )}
                                {order.supplier_quote_reference && (
                                    <p className="mt-1 text-xs text-slate-600 dark:text-slate-300">
                                        <span className="font-semibold">Folio / Cotización Referencia:</span> {order.supplier_quote_reference}
                                    </p>
                                )}
                            </div>

                            <div className="rounded-xl border border-slate-200 bg-slate-50/50 p-4 dark:border-neutral-800 dark:bg-neutral-950/40">
                                <p className="text-xs font-bold uppercase tracking-wider text-slate-500">Lugar de Entrega / Almacén</p>
                                <p className="mt-1 text-base font-bold text-slate-900 dark:text-white">
                                    {order.location ? `${order.location.name} (${order.location.code})` : 'Almacén General SBS'}
                                </p>
                                {order.buyer && (
                                    <p className="mt-1 text-xs text-slate-500">
                                        Comprador: {order.buyer.name} ({order.buyer.email})
                                    </p>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Items table: Only SKU, Producto, Pedidas (+ Recibidas and Saldo in system tracking) */}
                    <div className="mt-6 overflow-x-auto">
                        <table className="w-full text-left text-sm">
                            <thead className="border-b border-slate-200 bg-slate-50/75 text-xs font-bold uppercase tracking-wider text-slate-700 dark:border-neutral-800 dark:bg-neutral-950 dark:text-slate-300">
                                <tr>
                                    <th className="px-3 py-3 w-12 text-center">#</th>
                                    <th className="px-3 py-3 w-36">SKU</th>
                                    <th className="px-3 py-3">Producto / Descripción</th>
                                    <th className="px-3 py-3 text-center w-28">Pedidas</th>
                                    {order.status !== 'DRAFT' && (
                                        <>
                                            <th className="px-3 py-3 text-center w-28">Recibidas</th>
                                            <th className="px-3 py-3 text-center w-28">Saldo</th>
                                        </>
                                    )}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                {(order.items || []).map((item, idx) => {
                                    const pending = Math.max(0, item.quantity_ordered - item.quantity_received)
                                    return (
                                        <tr key={item.id} className="hover:bg-slate-50/30 dark:hover:bg-neutral-800/30">
                                            <td className="px-3 py-3 text-center text-xs text-slate-400">{idx + 1}</td>
                                            <td className="px-3 py-3 font-mono font-bold text-slate-900 dark:text-white">
                                                {item.product?.sku || item.sku || '-'}
                                            </td>
                                            <td className="px-3 py-3 text-slate-800 dark:text-slate-200">
                                                <p className="font-semibold">{item.product?.name || item.product_name || '-'}</p>
                                                {item.product?.brand && (
                                                    <span className="text-xs text-slate-400">Marca: {item.product.brand}</span>
                                                )}
                                            </td>
                                            <td className="px-3 py-3 text-center font-bold text-slate-900 dark:text-white text-base">
                                                {item.quantity_ordered}
                                            </td>
                                            {order.status !== 'DRAFT' && (
                                                <>
                                                    <td className="px-3 py-3 text-center font-semibold text-emerald-600 dark:text-emerald-400">
                                                        {item.quantity_received}
                                                    </td>
                                                    <td className="px-3 py-3 text-center font-semibold text-amber-600 dark:text-amber-400">
                                                        {pending}
                                                    </td>
                                                </>
                                            )}
                                        </tr>
                                    )
                                })}
                            </tbody>
                        </table>
                    </div>

                    {/* Summary of quantities & Notes (No costs anywhere) */}
                    <div className="mt-8 flex flex-col justify-between gap-6 border-t border-slate-200 pt-6 sm:flex-row dark:border-neutral-800">
                        <div className="flex-1 space-y-4">
                            <div>
                                <h4 className="text-xs font-bold uppercase tracking-wider text-slate-500">Notas / Términos de Entrega</h4>
                                <p className="mt-1 text-sm text-slate-700 dark:text-slate-300">
                                    {order.notes || 'Sin observaciones adicionales registradas.'}
                                </p>
                            </div>

                            {order.cancelled_at && (
                                <div className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-300">
                                    <p className="font-bold">Orden Cancelada:</p>
                                    <p>{order.cancel_reason}</p>
                                    <p className="mt-1 text-slate-500">Por: {order.cancelled_by?.name} en {formatDateTime(order.cancelled_at)}</p>
                                </div>
                            )}

                            {/* Signatures for formal print */}
                            <div className="mt-8 hidden print:grid grid-cols-2 gap-12 pt-8">
                                <div className="border-t border-slate-400 pt-2 text-center">
                                    <p className="text-xs font-bold text-slate-800">Autorizado por Compras</p>
                                    <p className="text-[10px] text-slate-500">T.O. THE BEAUTY SHOP (SBS)</p>
                                </div>
                                <div className="border-t border-slate-400 pt-2 text-center">
                                    <p className="text-xs font-bold text-slate-800">Recibido en Almacén</p>
                                    <p className="text-[10px] text-slate-500">Firma / Sello de Recepción</p>
                                </div>
                            </div>
                        </div>

                        {/* Summary of Units (Without costs) */}
                        <div className="w-full sm:w-80 space-y-2.5 rounded-xl border border-slate-200 bg-slate-50/50 p-4 dark:border-neutral-800 dark:bg-neutral-950/40 text-sm">
                            <div className="flex justify-between text-slate-600 dark:text-slate-400">
                                <span>Partidas totales:</span>
                                <span className="font-bold text-slate-900 dark:text-white">{(order.items || []).length}</span>
                            </div>
                            <div className="flex justify-between text-slate-600 dark:text-slate-400">
                                <span>Total piezas solicitadas:</span>
                                <span className="font-bold text-slate-900 dark:text-white">{order.total_units_ordered} pzas</span>
                            </div>
                            <div className="flex justify-between text-slate-600 dark:text-slate-400">
                                <span>Total piezas recibidas:</span>
                                <span className="font-bold text-emerald-600 dark:text-emerald-400">{order.total_units_received} pzas</span>
                            </div>
                            <div className="border-t border-slate-200 pt-2 flex justify-between font-bold text-slate-900 dark:border-neutral-700 dark:text-white">
                                <span>Saldo pendiente:</span>
                                <span className={`text-base ${Math.max(0, order.total_units_ordered - order.total_units_received) > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600'}`}>
                                    {Math.max(0, order.total_units_ordered - order.total_units_received)} pzas
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Historial de Recepciones Físicas en Almacén (print:hidden) */}
                <div className="print:hidden rounded-2xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="flex items-center justify-between">
                        <div>
                            <h3 className="text-base font-bold text-slate-900 dark:text-white">
                                Historial de Recepciones Físicas en Almacén ({order.receipts?.length || 0})
                            </h3>
                            <p className="text-xs text-slate-500 dark:text-slate-400">
                                Cada recepción genera automáticamente un movimiento de entrada física comprobable en el inventario.
                            </p>
                        </div>

                        {(order.status === 'ORDERED' || order.status === 'PARTIAL') && (
                            <button
                                type="button"
                                onClick={() => setReceiveModalOpen(true)}
                                className="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-3.5 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-emerald-700"
                            >
                                + Nueva Recepción
                            </button>
                        )}
                    </div>

                    <div className="mt-4 space-y-4">
                        {!order.receipts || order.receipts.length === 0 ? (
                            <p className="py-6 text-center text-xs text-slate-400">
                                Aún no se han registrado ingresos físicos para esta orden de compra.
                            </p>
                        ) : (
                            order.receipts.map((rcpt) => (
                                <div
                                    key={rcpt.id}
                                    className="rounded-xl border border-slate-200 bg-slate-50/50 p-4 dark:border-neutral-800 dark:bg-neutral-950/40"
                                >
                                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200/80 pb-3 dark:border-neutral-800">
                                        <div className="flex items-center gap-3">
                                            <span className="font-mono text-sm font-bold text-emerald-700 dark:text-emerald-400">
                                                {rcpt.receipt_number}
                                            </span>
                                            <span className="text-xs text-slate-500">
                                                {formatDateTime(rcpt.received_at)}
                                            </span>
                                            <span className="text-xs font-medium text-slate-600 dark:text-slate-400">
                                                Recibió: {rcpt.received_by?.name || 'Personal Almacén'}
                                            </span>
                                        </div>

                                        <div className="flex items-center gap-3 text-xs">
                                            {rcpt.carrier && (
                                                <span className="rounded bg-slate-200/70 px-2 py-0.5 font-medium text-slate-700 dark:bg-neutral-800 dark:text-slate-300">
                                                    Transporte: {rcpt.carrier} {rcpt.tracking_number && `(${rcpt.tracking_number})`}
                                                </span>
                                            )}
                                            <span className="font-bold text-slate-900 dark:text-white">
                                                {rcpt.total_units_received} unidades ingresadas
                                            </span>
                                        </div>
                                    </div>

                                    {/* Items in this receipt */}
                                    <div className="mt-3">
                                        <table className="w-full text-left text-xs">
                                            <thead>
                                                <tr className="text-slate-500">
                                                    <th className="py-1">SKU</th>
                                                    <th className="py-1">Producto</th>
                                                    <th className="py-1 text-right">Unidades Recibidas</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-900">
                                                {(rcpt.items || []).map((ri) => (
                                                    <tr key={ri.id}>
                                                        <td className="py-1.5 font-mono font-bold text-slate-900 dark:text-white">
                                                            {ri.product?.sku || '-'}
                                                        </td>
                                                        <td className="py-1.5 text-slate-700 dark:text-slate-300">
                                                            {ri.product?.name || '-'}
                                                        </td>
                                                        <td className="py-1.5 text-right font-bold text-emerald-600 dark:text-emerald-400">
                                                            +{ri.quantity_received}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>

                                    {rcpt.notes && (
                                        <p className="mt-2 text-xs italic text-slate-500">
                                            Nota: {rcpt.notes}
                                        </p>
                                    )}
                                </div>
                            ))
                        )}
                    </div>
                </div>
            </div>

            {/* Modal: Vista Previa y Envío de Orden de Compra (PDF) */}
            {pdfModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                    <div className="w-full max-w-4xl max-h-[92vh] flex flex-col rounded-2xl bg-white shadow-2xl dark:bg-neutral-900 overflow-hidden">
                        {/* Modal Header */}
                        <div className="flex items-center justify-between border-b border-slate-200 px-6 py-4 dark:border-neutral-800">
                            <div className="flex items-center gap-3">
                                <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-600 text-white shadow-sm">
                                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                </div>
                                <div>
                                    <h3 className="text-base font-bold text-slate-900 dark:text-white">
                                        Vista Previa del Documento PDF
                                    </h3>
                                    <p className="text-xs text-slate-500 dark:text-slate-400">
                                        Revise el formato formal de la orden de compra antes de descargarla o enviarla por WhatsApp.
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={() => setPdfModalOpen(false)}
                                className="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-neutral-800"
                            >
                                <svg className="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        {/* Confirmation Alert after sending */}
                        {pdfSentAlert && (
                            <div className="mx-6 mt-3 flex items-center justify-between rounded-xl bg-emerald-50 px-4 py-2.5 text-xs font-semibold text-emerald-800 border border-emerald-200 dark:bg-emerald-950/40 dark:border-emerald-900 dark:text-emerald-300">
                                <div className="flex items-center gap-2">
                                    <svg className="h-4 w-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M5 13l4 4L19 7" />
                                    </svg>
                                    <span>
                                        ¡Archivo PDF generado y descargado! En la ventana de WhatsApp abierta, simplemente adjunte o arrastre el archivo PDF para compartirlo con el proveedor.
                                    </span>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setPdfSentAlert(false)}
                                    className="text-emerald-700 hover:text-emerald-900 font-bold ml-2"
                                >
                                    ✕
                                </button>
                            </div>
                        )}

                        {/* Modal Body: Authentic Visual PDF Document Preview */}
                        <div className="flex-1 overflow-y-auto px-6 py-4 bg-slate-100/70 dark:bg-neutral-950/40">
                            <div
                                ref={pdfSheetRef}
                                className="mx-auto max-w-[760px] rounded-xl bg-white p-8 text-slate-900 shadow-sm border border-slate-200"
                            >
                                {/* Header */}
                                <div className="flex items-start justify-between border-b-2 border-slate-200 pb-5">
                                    <div className="flex items-center gap-3.5">
                                        <img
                                            src="/logo-beauty-shop.png"
                                            alt="T.O. THE BEAUTY SHOP"
                                            className="h-14 w-14 rounded-full border border-slate-200 object-cover bg-black"
                                        />
                                        <div>
                                            <h2 className="text-xl font-black text-slate-900">
                                                T.O. THE BEAUTY SHOP
                                            </h2>
                                            <p className="text-xs text-slate-500 font-medium">
                                                Salon & Barber Supply (SBS) • Hermosillo, Sonora, México
                                            </p>
                                        </div>
                                    </div>

                                    <div className="text-right">
                                        <span className="inline-block rounded bg-emerald-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-emerald-700 border border-emerald-200">
                                            Documento Oficial
                                        </span>
                                        <h3 className="text-xl font-black text-slate-900 mt-1">
                                            ORDEN DE COMPRA
                                        </h3>
                                        <p className="font-mono text-sm font-bold text-indigo-600">
                                            {order.order_number}
                                        </p>
                                        <p className="text-xs text-slate-500 mt-0.5">
                                            Fecha de emisión: {formatDate(order.ordered_at || order.created_at)}
                                        </p>
                                    </div>
                                </div>

                                {/* Supplier & Delivery Grid */}
                                <div className="mt-5 grid grid-cols-2 gap-4 text-xs">
                                    <div className="rounded-lg border border-slate-200 bg-slate-50/70 p-3">
                                        <p className="font-bold uppercase tracking-wider text-slate-500">Datos del Proveedor</p>
                                        <p className="mt-1 text-sm font-bold text-slate-900">{order.supplier_name}</p>
                                        {order.brand && (
                                            <p className="mt-0.5 text-slate-600"><strong>Línea / Marca:</strong> {order.brand}</p>
                                        )}
                                        {order.supplier_quote_reference && (
                                            <p className="mt-0.5 text-slate-600"><strong>Referencia / Folio:</strong> {order.supplier_quote_reference}</p>
                                        )}
                                        {supplier?.phone && (
                                            <p className="mt-0.5 text-slate-600"><strong>Teléfono:</strong> {supplier.phone}</p>
                                        )}
                                    </div>

                                    <div className="rounded-lg border border-slate-200 bg-slate-50/70 p-3">
                                        <p className="font-bold uppercase tracking-wider text-slate-500">Lugar de Entrega / Almacén</p>
                                        <p className="mt-1 text-sm font-bold text-slate-900">
                                            {order.location ? order.location.name : 'Almacén General SBS'}
                                        </p>
                                        {order.location?.code && (
                                            <p className="mt-0.5 text-slate-600"><strong>Código Almacén:</strong> {order.location.code}</p>
                                        )}
                                        {order.buyer && (
                                            <p className="mt-0.5 text-slate-600"><strong>Comprador SBS:</strong> {order.buyer.name}</p>
                                        )}
                                    </div>
                                </div>

                                {/* Items Table: Only SKU, Producto, Cantidad Pedida. Zero costs. */}
                                <div className="mt-5">
                                    <table className="w-full text-left text-xs">
                                        <thead>
                                            <tr className="border-b-2 border-slate-200 bg-slate-50 text-[11px] font-bold uppercase tracking-wider text-slate-600">
                                                <th className="py-2.5 px-3 w-10 text-center">#</th>
                                                <th className="py-2.5 px-3 w-32">SKU</th>
                                                <th className="py-2.5 px-3">Producto / Descripción</th>
                                                <th className="py-2.5 px-3 w-28 text-center">Cantidad Pedida</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {(order.items || []).map((it, idx) => (
                                                <tr key={it.id || idx}>
                                                    <td className="py-2.5 px-3 text-center text-slate-400 font-mono text-[11px]">{idx + 1}</td>
                                                    <td className="py-2.5 px-3 font-mono font-bold text-slate-900">
                                                        {it.product?.sku || it.sku || '-'}
                                                    </td>
                                                    <td className="py-2.5 px-3 text-slate-800">
                                                        <span className="font-semibold">{it.product?.name || it.product_name || '-'}</span>
                                                        {it.product?.brand && (
                                                            <span className="text-[10px] text-slate-400 block">Marca: {it.product.brand}</span>
                                                        )}
                                                    </td>
                                                    <td className="py-2.5 px-3 text-center font-bold text-slate-900 text-sm">
                                                        {it.quantity_ordered} pzas
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>

                                {/* Summary & Signatures */}
                                <div className="mt-6 flex justify-between items-start gap-4 border-t-2 border-slate-200 pt-4 text-xs">
                                    <div className="flex-1">
                                        <p className="font-bold uppercase tracking-wider text-slate-500">Observaciones</p>
                                        <p className="mt-1 text-slate-600">
                                            {order.notes || 'Favor de entregar la mercancía debidamente empacada e identificada con este folio de orden de compra.'}
                                        </p>
                                    </div>

                                    <div className="w-60 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                        <div className="flex justify-between text-slate-600">
                                            <span>Partidas ordenadas:</span>
                                            <strong className="text-slate-900">{(order.items || []).length}</strong>
                                        </div>
                                        <div className="mt-1.5 flex justify-between border-t border-slate-200 pt-1.5 font-bold text-slate-900">
                                            <span>Total piezas pedidas:</span>
                                            <span className="text-emerald-700 text-sm">{order.total_units_ordered} pzas</span>
                                        </div>
                                    </div>
                                </div>

                                <div className="mt-10 grid grid-cols-2 gap-8 text-center text-xs pt-4">
                                    <div className="border-t border-slate-300 pt-2">
                                        <p className="font-bold text-slate-800">Autorizado por Compras</p>
                                        <p className="text-[10px] text-slate-500">T.O. THE BEAUTY SHOP (SBS)</p>
                                    </div>
                                    <div className="border-t border-slate-300 pt-2">
                                        <p className="font-bold text-slate-800">Recibido en Almacén</p>
                                        <p className="text-[10px] text-slate-500">Firma / Sello de Recepción</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Modal Footer: Action Bar */}
                        <div className="border-t border-slate-200 bg-white px-6 py-4 dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                {/* Phone input */}
                                <div className="flex items-center gap-2">
                                    <label className="text-xs font-bold text-slate-600 dark:text-slate-400 whitespace-nowrap">
                                        WhatsApp:
                                    </label>
                                    <input
                                        type="text"
                                        value={whatsappPhone}
                                        onChange={(e) => setWhatsappPhone(e.target.value)}
                                        placeholder="Ej. 6621234567..."
                                        className="w-48 rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs text-slate-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                    />
                                    {supplier?.contact_name && (
                                        <span className="text-[11px] text-slate-400 hidden md:inline">
                                            ({supplier.contact_name})
                                        </span>
                                    )}
                                </div>

                                {/* Buttons */}
                                <div className="flex flex-wrap items-center gap-2.5">
                                    {/* Abrir en pestaña nueva */}
                                    <a
                                        href={`/compras/ordenes/${order.id}/pdf`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                                    >
                                        <svg className="h-3.5 w-3.5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                        </svg>
                                        Abrir en Pestaña / Imprimir
                                    </a>

                                    {/* Descargar archivo PDF */}
                                    <button
                                        type="button"
                                        disabled={downloadingPdf}
                                        onClick={handleDownloadPdf}
                                        className="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-2 text-xs font-semibold text-slate-800 hover:bg-slate-100 disabled:opacity-50 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-200"
                                    >
                                        <svg className="h-4 w-4 text-slate-600 dark:text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                        </svg>
                                        {downloadingPdf ? 'Generando PDF...' : 'Descargar PDF'}
                                    </button>

                                    {/* Enviar PDF por WhatsApp */}
                                    <button
                                        type="button"
                                        disabled={downloadingPdf}
                                        onClick={handleSendWhatsAppWithPdf}
                                        className="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50"
                                    >
                                        <svg className="h-4 w-4" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z" />
                                        </svg>
                                        Enviar PDF por WhatsApp
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* Modal: Colocar Orden al Proveedor (Auto-filled quote and auto-filled emission date) */}
            {orderModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                        <h3 className="text-lg font-bold text-slate-900 dark:text-white">
                            Colocar Orden al Proveedor
                        </h3>
                        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Cambia el estado de la orden a "Colocada" (en camino). Los datos se autocompletaron para su conveniencia.
                        </p>

                        <form onSubmit={handleMarkOrdered} className="mt-4 space-y-4">
                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                    No. de Cotización / Folio Referencia (Auto-completado)
                                </label>
                                <input
                                    type="text"
                                    value={orderQuoteRef}
                                    onChange={(e) => setOrderQuoteRef(e.target.value)}
                                    placeholder="Ej. PROVEEDOR - OC-20261007-0001"
                                    className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm text-slate-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                />
                                <p className="mt-1 text-[11px] text-slate-400">
                                    Generado automáticamente con el proveedor y folio de la orden.
                                </p>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                    Fecha de Emisión de la Orden (Automática)
                                </label>
                                <input
                                    type="date"
                                    value={orderDate}
                                    onChange={(e) => setOrderDate(e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm text-slate-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                />
                                <p className="mt-1 text-[11px] text-slate-400">
                                    Se pre-llena con la fecha de hoy ({todayDateStr}).
                                </p>
                            </div>

                            <div className="mt-6 flex justify-end gap-3">
                                <button
                                    type="button"
                                    onClick={() => setOrderModalOpen(false)}
                                    className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    disabled={orderingSubmitting}
                                    className="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                                >
                                    {orderingSubmitting ? 'Enviando...' : 'Confirmar Orden Colocada'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Modal: Recepción Física de Mercancía con Scanner y Productos Extras */}
            {receiveModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                    <div className="w-full max-w-3xl max-h-[92vh] overflow-y-auto rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                        <div className="flex items-center justify-between border-b border-slate-200 pb-3 dark:border-neutral-800">
                            <div>
                                <h3 className="text-lg font-bold text-slate-900 dark:text-white">
                                    Recepción Física de Mercancía en Almacén
                                </h3>
                                <p className="text-xs text-slate-500 dark:text-slate-400">
                                    Escanee productos con el lector de código de barras o ajuste manualmente las cantidades recibidas.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setReceiveModalOpen(false)}
                                className="text-slate-400 hover:text-slate-600"
                            >
                                <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        {/* Scanner Barcode Gun Input */}
                        <div className="mt-4 rounded-xl border border-indigo-200 bg-indigo-50/60 p-3.5 dark:border-indigo-900/50 dark:bg-indigo-950/20">
                            <form onSubmit={handleScanBarcode} className="flex items-center gap-2">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-600 text-white">
                                    <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z" />
                                    </svg>
                                </div>
                                <div className="flex-1">
                                    <input
                                        ref={scanInputRef}
                                        type="text"
                                        value={scanBarcode}
                                        onChange={(e) => setScanBarcode(e.target.value)}
                                        placeholder="Escanear código de barras (1 o 2) o teclear SKU y presionar Enter..."
                                        className="w-full rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-800 placeholder-slate-400 focus:border-indigo-500 focus:outline-none focus:ring-1 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                    />
                                </div>
                                <button
                                    type="submit"
                                    className="rounded-lg bg-indigo-600 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 shadow-sm"
                                >
                                    Escanear
                                </button>
                            </form>

                            {/* Scan Feedback Alert */}
                            {scanFeedback && (
                                <div
                                    className={`mt-2 flex items-center justify-between rounded-lg px-3 py-1.5 text-xs font-medium ${
                                        scanFeedback.type === 'success'
                                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                                            : 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300'
                                    }`}
                                >
                                    <span>{scanFeedback.text}</span>
                                    <button
                                        type="button"
                                        onClick={() => setScanFeedback(null)}
                                        className="text-slate-500 hover:text-slate-800 dark:hover:text-slate-200"
                                    >
                                        ✕
                                    </button>
                                </div>
                            )}
                        </div>

                        <form onSubmit={handleReceiveSubmit} className="mt-4 space-y-4">
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div>
                                    <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                        Almacén Receptor
                                    </label>
                                    <select
                                        value={receiveLocationId}
                                        onChange={(e) => setReceiveLocationId(e.target.value)}
                                        className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-1.5 text-sm text-slate-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                    >
                                        {locations.map((loc) => (
                                            <option key={loc.id} value={loc.id}>
                                                {loc.name} ({loc.code})
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                        Línea de Transporte
                                    </label>
                                    <input
                                        type="text"
                                        value={receiveCarrier}
                                        onChange={(e) => setReceiveCarrier(e.target.value)}
                                        placeholder="Ej. Castores, Paquetexpress..."
                                        className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-1.5 text-sm text-slate-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                    />
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                        Número de Guía / Rastreo
                                    </label>
                                    <input
                                        type="text"
                                        value={receiveTracking}
                                        onChange={(e) => setReceiveTracking(e.target.value)}
                                        placeholder="Ej. 1198273645"
                                        className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-1.5 text-sm text-slate-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                    />
                                </div>
                            </div>

                            {/* Items table for reception */}
                            <div className="mt-4">
                                <div className="flex flex-wrap items-center justify-between gap-2 mb-2">
                                    <span className="text-xs font-bold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                        Partidas de la Entrega ({receivingItems.length})
                                    </span>
                                    <div className="flex items-center gap-3">
                                        <button
                                            type="button"
                                            onClick={() => setShowCatalogSearch(!showCatalogSearch)}
                                            className="text-xs font-semibold text-emerald-600 hover:underline dark:text-emerald-400"
                                        >
                                            + Agregar producto extra que no venía en la orden
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setReceivingItems(
                                                    receivingItems.map((item) => ({
                                                        ...item,
                                                        quantity_received: item.pending,
                                                    }))
                                                )
                                            }}
                                            className="text-xs font-semibold text-indigo-600 hover:underline dark:text-indigo-400"
                                        >
                                            Recibir todo el saldo
                                        </button>
                                    </div>
                                </div>

                                {/* Extra product catalog search dropdown */}
                                {showCatalogSearch && (
                                    <div className="mb-3 rounded-xl border border-emerald-200 bg-emerald-50/50 p-3 dark:border-emerald-900/50 dark:bg-emerald-950/20">
                                        <p className="text-xs font-bold text-emerald-900 dark:text-emerald-200 mb-1.5">
                                            Buscar producto en el catálogo para agregarlo a esta recepción:
                                        </p>
                                        <input
                                            type="text"
                                            value={catalogQuery}
                                            onChange={(e) => handleSearchCatalog(e.target.value)}
                                            placeholder="Buscar por SKU, código de barras o nombre del producto..."
                                            className="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs text-slate-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                        />
                                        {catalogLoading && (
                                            <p className="mt-1 text-[11px] text-slate-500">Buscando productos...</p>
                                        )}
                                        {catalogResults.length > 0 && (
                                            <div className="mt-2 max-h-40 overflow-y-auto divide-y divide-emerald-100 rounded-lg border border-emerald-200 bg-white dark:border-neutral-700 dark:bg-neutral-800 dark:divide-neutral-700">
                                                {catalogResults.map((p) => (
                                                    <div
                                                        key={p.id}
                                                        className="flex items-center justify-between p-2 hover:bg-emerald-50 dark:hover:bg-neutral-700 text-xs"
                                                    >
                                                        <div>
                                                            <span className="font-mono font-bold text-slate-900 dark:text-white">[{p.sku}]</span>{' '}
                                                            <span className="text-slate-800 dark:text-slate-200">{p.name}</span>
                                                            {p.brand && <span className="text-slate-400 text-[10px]"> • {p.brand}</span>}
                                                        </div>
                                                        <button
                                                            type="button"
                                                            onClick={() => handleAddExtraProduct(p)}
                                                            className="rounded bg-emerald-600 px-2 py-0.5 text-white font-medium hover:bg-emerald-700"
                                                        >
                                                            + Agregar
                                                        </button>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </div>
                                )}

                                <div className="max-h-72 overflow-y-auto rounded-xl border border-slate-200 dark:border-neutral-800">
                                    <table className="w-full text-left text-xs">
                                        <thead className="bg-slate-50 text-slate-600 dark:bg-neutral-950 dark:text-slate-400">
                                            <tr>
                                                <th className="px-3 py-2 w-28">SKU</th>
                                                <th className="px-3 py-2">Producto</th>
                                                <th className="px-3 py-2 text-center w-20">Pendiente</th>
                                                <th className="px-3 py-2 text-center w-36">A Recibir Hoy</th>
                                                <th className="px-3 py-2 text-center w-28">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                            {receivingItems.map((item, idx) => {
                                                const isZero = parseInt(item.quantity_received) === 0
                                                return (
                                                    <tr key={item.item_id || `extra-${item.product_id}-${idx}`} className={item.is_extra ? 'bg-amber-50/40 dark:bg-amber-950/10' : ''}>
                                                        <td className="px-3 py-2 font-mono font-bold text-slate-900 dark:text-white">
                                                            {item.sku}
                                                            {item.is_extra && (
                                                                <span className="ml-1 inline-block rounded bg-amber-200 px-1 py-0.2 text-[9px] font-bold text-amber-900 dark:bg-amber-900/60 dark:text-amber-200">
                                                                    Extra
                                                                </span>
                                                            )}
                                                        </td>
                                                        <td className="px-3 py-2 text-slate-700 dark:text-slate-300">
                                                            <p className="font-medium">{item.name}</p>
                                                            {(item.barcode || item.barcode_secondary) && (
                                                                <p className="text-[10px] text-slate-400">
                                                                    Cód: {item.barcode || '-'} {item.barcode_secondary ? `| Sec: ${item.barcode_secondary}` : ''}
                                                                </p>
                                                            )}
                                                        </td>
                                                        <td className="px-3 py-2 text-center font-bold text-slate-600 dark:text-slate-400">
                                                            {item.is_extra ? '-' : item.pending}
                                                        </td>
                                                        <td className="px-3 py-2 text-center">
                                                            <div className="flex items-center justify-center gap-1">
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        const val = Math.max(0, (parseInt(item.quantity_received) || 0) - 1)
                                                                        const updated = [...receivingItems]
                                                                        updated[idx].quantity_received = val
                                                                        setReceivingItems(updated)
                                                                    }}
                                                                    className="h-7 w-7 rounded border border-slate-300 bg-white font-bold text-slate-700 hover:bg-slate-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                                                >
                                                                    -
                                                                </button>
                                                                <input
                                                                    type="number"
                                                                    min={0}
                                                                    value={item.quantity_received}
                                                                    onChange={(e) => {
                                                                        const val = Math.max(0, parseInt(e.target.value) || 0)
                                                                        const updated = [...receivingItems]
                                                                        updated[idx].quantity_received = val
                                                                        setReceivingItems(updated)
                                                                    }}
                                                                    className={`w-16 rounded-lg border px-1.5 py-1 text-center font-bold text-sm ${
                                                                        isZero
                                                                            ? 'border-rose-300 bg-rose-50 text-rose-800 dark:border-rose-900/60 dark:bg-rose-950/20 dark:text-rose-300'
                                                                            : 'border-slate-300 bg-white text-slate-900 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white'
                                                                    }`}
                                                                />
                                                                <button
                                                                    type="button"
                                                                    onClick={() => {
                                                                        const val = (parseInt(item.quantity_received) || 0) + 1
                                                                        const updated = [...receivingItems]
                                                                        updated[idx].quantity_received = val
                                                                        setReceivingItems(updated)
                                                                    }}
                                                                    className="h-7 w-7 rounded border border-slate-300 bg-white font-bold text-slate-700 hover:bg-slate-100 dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
                                                                >
                                                                    +
                                                                </button>
                                                            </div>
                                                        </td>
                                                        <td className="px-3 py-2 text-center">
                                                            <div className="flex items-center justify-center gap-1.5">
                                                                <button
                                                                    type="button"
                                                                    title="Marcar que no llegó (0)"
                                                                    onClick={() => {
                                                                        const updated = [...receivingItems]
                                                                        updated[idx].quantity_received = 0
                                                                        setReceivingItems(updated)
                                                                    }}
                                                                    className={`rounded px-1.5 py-0.5 text-[10px] font-semibold border ${
                                                                        isZero
                                                                            ? 'border-rose-400 bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300'
                                                                            : 'border-slate-300 text-slate-600 hover:bg-slate-100 dark:border-neutral-700 dark:text-slate-400'
                                                                    }`}
                                                                >
                                                                    No llegó (0)
                                                                </button>
                                                                {!item.is_extra ? (
                                                                    <button
                                                                        type="button"
                                                                        title="Recibir todo el saldo pendiente"
                                                                        onClick={() => {
                                                                            const updated = [...receivingItems]
                                                                            updated[idx].quantity_received = item.pending
                                                                            setReceivingItems(updated)
                                                                        }}
                                                                        className="rounded border border-indigo-200 bg-indigo-50 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-700 hover:bg-indigo-100 dark:border-indigo-900 dark:bg-indigo-950/40 dark:text-indigo-300"
                                                                    >
                                                                        Todo
                                                                    </button>
                                                                ) : (
                                                                    <button
                                                                        type="button"
                                                                        title="Quitar producto extra"
                                                                        onClick={() => handleRemoveExtraItem(idx)}
                                                                        className="text-rose-600 hover:text-rose-800 p-0.5"
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
                            </div>

                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                    Notas de Recepción (Estado del producto, condiciones del empaque, etc.)
                                </label>
                                <textarea
                                    rows={2}
                                    value={receiveNotes}
                                    onChange={(e) => setReceiveNotes(e.target.value)}
                                    placeholder="Comentarios adicionales sobre la descarga..."
                                    className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm text-slate-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                />
                            </div>

                            <div className="mt-6 flex justify-end gap-3 border-t border-slate-200 pt-4 dark:border-neutral-800">
                                <button
                                    type="button"
                                    onClick={() => setReceiveModalOpen(false)}
                                    className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    disabled={receiveSubmitting}
                                    className="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50 shadow-sm"
                                >
                                    {receiveSubmitting ? 'Procesando Entrada...' : 'Confirmar e Ingresar a Almacén'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* Modal: Cancel Order */}
            {cancelModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm">
                    <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                        <h3 className="text-lg font-bold text-rose-600 dark:text-rose-400">
                            Cancelar Orden de Compra
                        </h3>
                        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Esta acción cancelará la orden {order.order_number}. Indique el motivo.
                        </p>

                        <form onSubmit={handleCancelSubmit} className="mt-4 space-y-4">
                            <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 dark:text-slate-400">
                                    Motivo de Cancelación *
                                </label>
                                <textarea
                                    required
                                    rows={3}
                                    value={cancelReason}
                                    onChange={(e) => setCancelReason(e.target.value)}
                                    placeholder="Ej. Falta de stock con proveedor, cancelación de común acuerdo..."
                                    className="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2 text-sm text-slate-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-slate-100"
                                />
                            </div>

                            <div className="mt-6 flex justify-end gap-3">
                                <button
                                    type="button"
                                    onClick={() => setCancelModalOpen(false)}
                                    className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800"
                                >
                                    Regresar
                                </button>
                                <button
                                    type="submit"
                                    disabled={cancelSubmitting || !cancelReason.trim()}
                                    className="rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 disabled:opacity-50"
                                >
                                    {cancelSubmitting ? 'Cancelando...' : 'Confirmar Cancelación'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AppShell>
    )
}
