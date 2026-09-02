import AppShell from '@/Components/layout/AppShell'
import { esAndroid, prepararBinarioParaRawBt } from '@/lib/rawBtPng'
import { router, usePage } from '@inertiajs/react'
import qz from 'qz-tray'
import { useRef, useState } from 'react'


let qzSecurityConfigured = false

const THERMAL_PRINTER_STORAGE_KEY = 'ams_thermal_printer_name'
const THERMAL_PRINTER_HINTS = [
    '4BARCODE 4B-2054A',
    'ZDesigner GK420t',
    'Zebra GK420t',
    'GK420t',
]

function normalizePrinterName(value) {
    return String(value || '').trim().toLocaleLowerCase()
}

function storedThermalPrinterName() {
    if (typeof window === 'undefined') {
        return ''
    }

    try {
        return String(window.localStorage.getItem(THERMAL_PRINTER_STORAGE_KEY) || '').trim()
    } catch {
        return ''
    }
}

function saveThermalPrinterName(value) {
    if (typeof window === 'undefined') {
        return
    }

    try {
        const printerName = String(value || '').trim()

        if (printerName) {
            window.localStorage.setItem(THERMAL_PRINTER_STORAGE_KEY, printerName)
        } else {
            window.localStorage.removeItem(THERMAL_PRINTER_STORAGE_KEY)
        }
    } catch {
        // La impresión puede continuar aunque el navegador bloquee localStorage.
    }
}

function preferredThermalPrinter(printers, selectedPrinter = '') {
    const installed = Array.from(
        new Set(
            (Array.isArray(printers) ? printers : [printers])
                .map((printer) => String(printer || '').trim())
                .filter(Boolean)
        )
    )

    const selected = String(selectedPrinter || '').trim()

    if (selected) {
        const selectedMatch = installed.find(
            (printer) => normalizePrinterName(printer) === normalizePrinterName(selected)
        )

        if (selectedMatch) {
            return selectedMatch
        }
    }

    for (const hint of THERMAL_PRINTER_HINTS) {
        const exactMatch = installed.find(
            (printer) => normalizePrinterName(printer) === normalizePrinterName(hint)
        )

        if (exactMatch) {
            return exactMatch
        }
    }

    for (const hint of THERMAL_PRINTER_HINTS) {
        const partialMatch = installed.find((printer) =>
            normalizePrinterName(printer).includes(normalizePrinterName(hint))
        )

        if (partialMatch) {
            return partialMatch
        }
    }

    return installed.length === 1 ? installed[0] : ''
}

function csrfToken() {
    const metaToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content')

    if (metaToken) {
        return metaToken
    }

    const cookie = document.cookie
        .split('; ')
        .find((item) => item.startsWith('XSRF-TOKEN='))

    return cookie
        ? decodeURIComponent(cookie.substring('XSRF-TOKEN='.length))
        : ''
}

function configureQzSecurity() {
    if (qzSecurityConfigured) {
        return
    }

    qz.security.setCertificatePromise((resolve, reject) => {
        fetch('/qz/certificate', {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'text/plain',
            },
        })
            .then(async (response) => {
                const body = await response.text()

                if (!response.ok) {
                    throw new Error(
                        body || `No se pudo cargar el certificado QZ (${response.status}).`
                    )
                }

                return body
            })
            .then(resolve)
            .catch(reject)
    })

    qz.security.setSignatureAlgorithm('SHA512')

    qz.security.setSignaturePromise((toSign) => {
        return (resolve, reject) => {
            const token = csrfToken()

            fetch('/qz/sign', {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'text/plain',
                    'Content-Type': 'application/json',
                    ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                },
                body: JSON.stringify({
                    request: toSign,
                }),
            })
                .then(async (response) => {
                    const body = await response.text()

                    if (!response.ok) {
                        throw new Error(
                            body || `No se pudo firmar la solicitud QZ (${response.status}).`
                        )
                    }

                    return body.trim()
                })
                .then(resolve)
                .catch(reject)
        }
    })

    qzSecurityConfigured = true
}

function formatFechaCorta(iso) {
    if (!iso) {
        return ''
    }
    const [y, m, d] = String(iso).split('-')
    if (!y || !m || !d) {
        return iso
    }
    return `${d}/${m}/${y}`
}

function tipoBadgeClass(tipo) {
    switch (tipo) {
        case 'FLEX':
            return 'border-emerald-500/60 bg-emerald-600/25 text-emerald-100'
        case 'COLECTA':
            return 'border-sky-500/60 bg-sky-600/25 text-sky-100'
        case 'FULL':
            return 'border-purple-500/60 bg-purple-600/25 text-purple-100'
        default:
            return 'border-slate-500/60 bg-slate-600/25 text-slate-200'
    }
}

function tipoLabel(tipo) {
    switch (tipo) {
        case 'FLEX':
            return 'Flex · entrega propia'
        case 'COLECTA':
            return 'Colecta · retiro MeLi'
        case 'FULL':
            return 'Full · depósito MeLi'
        default:
            return 'Otro'
    }
}

function envioBadgeClass(status, substatus) {
    if (status === 'shipped' || status === 'in_transit') {
        return 'border-amber-500/60 bg-amber-900/40 text-amber-100'
    }
    if (status === 'delivered' || substatus === 'delivered') {
        return 'border-slate-500/60 bg-slate-800/60 text-slate-300'
    }
    if (status === 'ready_to_ship' && substatus === 'ready_to_print') {
        return 'border-lime-500/60 bg-lime-900/30 text-lime-100'
    }
    if (status === 'ready_to_ship' && substatus === 'printed') {
        return 'border-cyan-500/60 bg-cyan-900/30 text-cyan-100'
    }
    return 'border-slate-500/60 bg-slate-700/40 text-slate-200'
}

function mlVentaUrl(pedido) {
    const term = encodeURIComponent(pedido?.display_id || pedido?.order_id || '')
    return `https://www.mercadolibre.com.mx/ventas?search=${term}`
}

function etiquetaUrl(pedido, labelBaseUrl, alcance, fechaSeleccionada, selectedMeliAccountId) {
    const shippingId = String(pedido?.shipping_id || '').trim()
    if (!shippingId) {
        return null
    }

    const base = String(labelBaseUrl || '/ams/pedidos/shipping-label').replace(/\/+$/, '')
    const params = new URLSearchParams({
        volver: alcance || 'ml_listado',
        fecha: fechaSeleccionada || '',
    })

    if (selectedMeliAccountId) {
        params.set('account_id', String(selectedMeliAccountId))
    }

    return `${base}/${encodeURIComponent(shippingId)}/print?${params.toString()}`
}

function etiquetaTermicaUrl(pedido, labelBaseUrl) {
    const shippingId = String(pedido?.shipping_id || '').trim()
    if (!shippingId) {
        return null
    }

    const base = String(labelBaseUrl || '/ams/pedidos/shipping-label').replace(/\/+$/, '')
    return `${base}/${encodeURIComponent(shippingId)}/zpl`
}

function etiquetaTermicaRawUrl(pedido, labelBaseUrl) {
    const shippingId = String(pedido?.shipping_id || '').trim()
    if (!shippingId) {
        return null
    }

    const base = String(labelBaseUrl || '/ams/pedidos/shipping-label').replace(/\/+$/, '')
    return `${base}/${encodeURIComponent(shippingId)}/zpl-raw`
}



function esImpresoraKamo(printerName) {
    const name = String(printerName || '')
        .trim()
        .toLowerCase()

    return name.includes('kamo')
        || name.includes('td-402s')
        || name.includes('td-401')
}

function etiquetaKamoTsplUrl(
    pedido,
    labelBaseUrl,
    selectedMeliAccountId
) {
    const shippingId = String(
        pedido?.shipping_id || ''
    ).trim()

    if (!shippingId) {
        return null
    }

    const base = String(
        labelBaseUrl
        || '/ams/secundaria/pedidos/shipping-label'
    ).replace(/\/+$/, '')

    const params = new URLSearchParams()

    if (selectedMeliAccountId) {
        params.set(
            'account_id',
            String(selectedMeliAccountId)
        )
    }

    const query = params.toString()

    return `${base}/${encodeURIComponent(shippingId)}/kamo-tspl`
        + (query ? `?${query}` : '')
}

function etiquetaKamoPngUrl(
    pedido,
    labelBaseUrl,
    selectedMeliAccountId
) {
    const shippingId = String(
        pedido?.shipping_id || ''
    ).trim()

    if (!shippingId) {
        return null
    }

    const base = String(
        labelBaseUrl
        || '/ams/secundaria/pedidos/shipping-label'
    ).replace(/\/+$/, '')

    const params = new URLSearchParams()

    if (selectedMeliAccountId) {
        params.set(
            'account_id',
            String(selectedMeliAccountId)
        )
    }

    const query = params.toString()

    return `${base}/${encodeURIComponent(shippingId)}/kamo-png`
        + (query ? `?${query}` : '')
}


export default function PedidosProcesar({
    pedidos = [],
    fechaSeleccionada = '',
    totalPedidos = 0,
    totalPiezas = 0,
    tituloPagina = 'AMS - Pedidos por procesar',
    subtitulo = 'Mostrando pedidos que te toca procesar el día:',
    formAction = '/ams/pedidos-procesar',
    syncAction = '',
    labelBaseUrl = '/ams/pedidos/shipping-label',
    orden = 'fecha',
    alcance = 'ml_listado',
    meliAccounts = [],
    selectedMeliAccountId = null,
    selectedMeliAccountLabel = '',
    cancelReasons = {},
}) {
    const android = esAndroid()
    const rawBtPreparingRef = useRef(false)
    const [printingShippingId, setPrintingShippingId] = useState(null)
    const [preparedRawBtLabel, setPreparedRawBtLabel] = useState(null)
    const [thermalPrinterName, setThermalPrinterName] = useState(storedThermalPrinterName)
    const [thermalPrinters, setThermalPrinters] = useState([])
    const [loadingThermalPrinters, setLoadingThermalPrinters] = useState(false)
    const [thermalPrinterMessage, setThermalPrinterMessage] = useState('')
    const [cancelOrder, setCancelOrder] = useState(null)
    const [cancelReason, setCancelReason] = useState('')
    const [cancelConfirmed, setCancelConfirmed] = useState(false)
    const [cancellingOrderId, setCancellingOrderId] = useState(null)
    const flash = usePage().props.flash || {}

    const openCancellation = (pedido, order) => {
        if (cancellingOrderId || order.cancelled) return
        setCancelReason('')
        setCancelConfirmed(false)
        setCancelOrder({ pedido, order })
    }

    const confirmCancellation = () => {
        if (!cancelOrder || !cancelReason || !cancelConfirmed || cancellingOrderId) return
        const localOrderId = cancelOrder.order.id
        router.post(`/ams/pedidos-secundaria/orders/${localOrderId}/cancel`, { reason: cancelReason, confirmed: true }, {
            preserveScroll: true,
            onStart: () => setCancellingOrderId(localOrderId),
            onSuccess: () => { setCancelOrder(null); setCancelReason(''); setCancelConfirmed(false) },
            onFinish: () => setCancellingOrderId(null),
        })
    }

    const connectQzTray = async () => {
        configureQzSecurity()

        if (!qz.websocket.isActive()) {
            await qz.websocket.connect({ usingSecure: true })
        }
    }

    const loadThermalPrinters = async ({ notify = false } = {}) => {
        setLoadingThermalPrinters(true)
        setThermalPrinterMessage('Conectando con QZ Tray...')

        try {
            await connectQzTray()

            const found = await qz.printers.find()
            const printers = Array.from(
                new Set(
                    (Array.isArray(found) ? found : [found])
                        .map((printer) => String(printer || '').trim())
                        .filter(Boolean)
                )
            ).sort((a, b) => a.localeCompare(b))

            setThermalPrinters(printers)

            if (printers.length === 0) {
                throw new Error('QZ Tray no encontró impresoras instaladas en esta computadora.')
            }

            const preferred = preferredThermalPrinter(
                printers,
                thermalPrinterName || storedThermalPrinterName()
            )

            if (preferred) {
                setThermalPrinterName(preferred)
                saveThermalPrinterName(preferred)
                setThermalPrinterMessage(`Lista para imprimir: ${preferred}`)

                if (notify) {
                    window.alert(`Impresora térmica seleccionada: ${preferred}`)
                }
            } else {
                setThermalPrinterMessage('Selecciona la impresora térmica de esta computadora.')

                if (notify) {
                    window.alert(
                        'Se encontraron varias impresoras. Selecciona la 4BARCODE o la Zebra GK420t en la lista.'
                    )
                }
            }

            return {
                printers,
                preferred,
            }
        } catch (error) {
            console.error('Error al buscar impresoras con QZ Tray:', error)

            const message = error instanceof Error ? error.message : String(error)
            setThermalPrinterMessage(message)

            if (notify) {
                window.alert(`No se pudieron consultar las impresoras. ${message}`)
            }

            throw error
        } finally {
            setLoadingThermalPrinters(false)
        }
    }

    const selectThermalPrinter = (printerName) => {
        const value = String(printerName || '').trim()
        setThermalPrinterName(value)
        saveThermalPrinterName(value)
        setThermalPrinterMessage(
            value
                ? `Lista para imprimir: ${value}`
                : 'Se detectará automáticamente al imprimir.'
        )
    }

    const resolveThermalPrinter = async () => {
        const result = await loadThermalPrinters()
        const printer = preferredThermalPrinter(
            result.printers,
            thermalPrinterName || result.preferred || storedThermalPrinterName()
        )

        if (!printer) {
            throw new Error(
                'Selecciona una impresora térmica en la parte superior de la página y vuelve a intentar.'
            )
        }

        if (printer !== thermalPrinterName) {
            setThermalPrinterName(printer)
            saveThermalPrinterName(printer)
        }

        return printer
    }

    const imprimirTermica = async (pedido) => {
        const shippingId = String(
            pedido?.shipping_id || ''
        ).trim()

        const rawUrl = etiquetaTermicaRawUrl(
            pedido,
            labelBaseUrl
        )

        if (!shippingId || !rawUrl) {
            window.alert(
                'Este pedido no tiene shipping_id.'
            )
            return
        }

        if (android && rawBtPreparingRef.current) {
            return
        }

        if (android) {
            rawBtPreparingRef.current = true
            setPreparedRawBtLabel(null)
        }

        setPrintingShippingId(shippingId)

        try {
            if (android) {
                const kamoUrl = etiquetaKamoTsplUrl(
                    pedido,
                    labelBaseUrl,
                    selectedMeliAccountId
                )

                if (!kamoUrl) {
                    throw new Error('No se pudo generar la URL TSPL de la KAMO.')
                }

                const rawBtUrl = await prepararBinarioParaRawBt(
                    kamoUrl,
                    {
                        accept: 'application/octet-stream',
                        minimumBytes: 1000,
                    }
                )

                setPreparedRawBtLabel({
                    shippingId,
                    url: rawBtUrl,
                })

                return
            }

            const printer =
                await resolveThermalPrinter()

            /*
             * ========================================
             * KAMO KA-L1 / KA-L1 WiFi
             * ========================================
             *
             * La KAMO no interpreta ZPL.
             * Solicitamos el mismo diseño convertido
             * a TSPL BITMAP.
             */
            if (esImpresoraKamo(printer)) {
                const kamoUrl =
                    etiquetaKamoTsplUrl(
                        pedido,
                        labelBaseUrl,
                        selectedMeliAccountId
                    )

                if (!kamoUrl) {
                    throw new Error(
                        'No se pudo generar la URL KAMO.'
                    )
                }

                const response = await fetch(
                    kamoUrl,
                    {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {
                            Accept:
                                'application/octet-stream',
                        },
                    }
                )

                if (!response.ok) {
                    const message =
                        await response.text()

                    throw new Error(
                        message
                        || `Error HTTP ${response.status}`
                    )
                }

                const buffer =
                    await response.arrayBuffer()

                const bytes =
                    new Uint8Array(buffer)

                if (bytes.length < 1000) {
                    throw new Error(
                        `El archivo KAMO parece inválido (${bytes.length} bytes).`
                    )
                }

                /*
                 * IMPORTANTE:
                 * No usamos el driver de Windows.
                 *
                 * QZ envía directamente el TSPL a la
                 * KAMO por TCP 9100, igual que la prueba
                 * que funcionó con PowerShell.
                 */
                const config =
                    qz.configs.create({
                        host: '192.168.68.119',
                        port: 9100,
                    })

                let binary = ''
                const chunkSize = 0x8000

                for (
                    let offset = 0;
                    offset < bytes.length;
                    offset += chunkSize
                ) {
                    const chunk = bytes.subarray(
                        offset,
                        Math.min(offset + chunkSize, bytes.length)
                    )

                    binary += String.fromCharCode(...chunk)
                }

                const base64 = window.btoa(binary)

                await qz.print(
                    config,
                    [
                        {
                            type: 'raw',
                            format: 'command',
                            flavor: 'base64',
                            data: base64,
                        },
                    ]
                )

                return
            }

            /*
             * ========================================
             * 4BARCODE / ZEBRA
             * ========================================
             *
             * Se conserva el flujo ZPL existente.
             */
            const response = await fetch(
                rawUrl,
                {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'text/plain',
                    },
                }
            )

            const body =
                await response.text()

            if (!response.ok) {
                throw new Error(
                    body
                    || `Error HTTP ${response.status}`
                )
            }

            if (
                !body.includes('^XA')
                || !body.includes('^XZ')
            ) {
                throw new Error(
                    'El servidor no devolvió una etiqueta ZPL válida.'
                )
            }

            let zpl = body

            const printerNormalized = normalizePrinterName(printer)

            if (
                printerNormalized.includes('4barcode')
                || printerNormalized.includes('4b-2054a')
            ) {
                zpl = body.replace(
                    '^XA',
                    '^XA\n^LL1624'
                )
            }

            const config =
                qz.configs.create(
                    printer,
                    {
                        encoding: 'UTF-8',
                    }
                )

            await qz.print(
                config,
                [
                    {
                        type: 'raw',
                        format: 'command',
                        flavor: 'plain',
                        data: zpl,
                    },
                ]
            )
        } catch (error) {
            const message =
                error instanceof Error
                    ? error.message
                    : String(error)

            if (android) {
                console.error('Error al preparar TSPL para RawBT:', error)
                window.alert(`No se pudo preparar la etiqueta KAMO para RawBT. ${message}`)

                return
            }

            console.error(
                'Error al imprimir con QZ Tray:',
                error
            )

            window.alert(
                `No se pudo imprimir la etiqueta térmica. ${message}`
            )
        } finally {
            if (android) {
                rawBtPreparingRef.current = false
            }

            setPrintingShippingId(null)
        }
    }

    const queryNav = (patch) => {
        router.get(
            formAction,
            {
                fecha: fechaSeleccionada,
                orden,
                alcance,
                account_id: selectedMeliAccountId || undefined,
                ...patch,
            },
            { preserveState: true, preserveScroll: true }
        )
    }

    const onSubmitFecha = (e) => {
        e.preventDefault()
        const form = new FormData(e.currentTarget)
        const fecha = form.get('fecha') || fechaSeleccionada
        queryNav({ fecha })
    }

    const setOrden = (nuevo) => {
        if (nuevo === orden) {
            return
        }
        queryNav({ orden: nuevo })
    }

    const setAlcance = (nuevo) => {
        if (nuevo === alcance) {
            return
        }
        queryNav({ alcance: nuevo })
    }

    const setAccount = (accountId) => {
        queryNav({ account_id: accountId })
    }

    const syncSecondaryOrders = () => {
        if (!syncAction || !selectedMeliAccountId) {
            return
        }

        router.post(
            syncAction,
            {
                account_id: selectedMeliAccountId,
                days: 7,
                fecha: fechaSeleccionada,
                orden,
                alcance,
            },
            { preserveScroll: true }
        )
    }

    const printerOptions = thermalPrinterName
        && !thermalPrinters.some(
            (printer) => normalizePrinterName(printer) === normalizePrinterName(thermalPrinterName)
        )
        ? [thermalPrinterName, ...thermalPrinters]
        : thermalPrinters

    return (
        <AppShell title={tituloPagina}>
            <section className="bg-[#0b1220] min-h-[calc(100vh-4rem)] py-4 sm:py-6">
                <div className="mx-auto max-w-[1400px] px-4 sm:px-6 lg:px-8">
                    {(flash.success || flash.error) ? <div className={`mb-4 rounded-lg border p-4 font-semibold ${flash.error ? 'border-red-500 bg-red-950/60 text-red-100' : 'border-emerald-500 bg-emerald-950/60 text-emerald-100'}`}>{flash.error || flash.success}</div> : null}
                    <div className="rounded-none border border-slate-700 bg-[#00153b] p-6 shadow-2xl">
                        <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                            <div>
                                <h1 className="text-2xl font-bold text-white sm:text-3xl">{tituloPagina}</h1>
                                <p className="mt-2 text-sm text-slate-300">
                                    {subtitulo}
                                    {alcance === 'colecta' ? (
                                        <> {formatFechaCorta(fechaSeleccionada)}</>
                                    ) : null}
                                </p>
                            </div>

                            {meliAccounts.length > 0 ? (
                                <div className="flex w-full flex-col gap-2 md:max-w-[260px]">
                                    <label
                                        htmlFor="meli-account"
                                        className="text-xs font-medium uppercase tracking-wide text-slate-400"
                                    >
                                        Cuenta Mercado Libre
                                    </label>

                                    <select
                                        id="meli-account"
                                        value={selectedMeliAccountId || ''}
                                        onChange={(event) => setAccount(event.target.value)}
                                        className="rounded-lg border border-slate-500 bg-slate-800 px-4 py-2 text-white outline-none focus:border-sky-400"
                                    >
                                        {meliAccounts.map((account) => (
                                            <option key={account.id} value={account.id}>
                                                {account.nickname}
                                            </option>
                                        ))}
                                    </select>

                                    <button
                                        type="button"
                                        onClick={syncSecondaryOrders}
                                        disabled={!selectedMeliAccountId}
                                        className="rounded-lg border border-emerald-500/60 bg-emerald-700/25 px-4 py-2 text-sm font-semibold text-emerald-100 transition hover:bg-emerald-700/40 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Sincronizar últimos 7 días
                                    </button>

                                    {selectedMeliAccountLabel ? (
                                        <span className="text-xs text-slate-400">
                                            Mostrando: {selectedMeliAccountLabel}
                                        </span>
                                    ) : null}
                                </div>
                            ) : null}

                            <form
                                onSubmit={onSubmitFecha}
                                className="flex flex-col gap-3 sm:flex-row sm:items-end"
                            >
                                <div>
                                    <label htmlFor="fecha" className="mb-1 block text-sm font-medium text-white">
                                        Seleccionar fecha
                                    </label>
                                    <input
                                        type="date"
                                        id="fecha"
                                        name="fecha"
                                        defaultValue={fechaSeleccionada}
                                        disabled={alcance === 'ml_listado'}
                                        className="w-full rounded-lg border border-slate-500 bg-slate-800 px-4 py-2 text-white outline-none focus:border-sky-400 disabled:cursor-not-allowed disabled:opacity-50"
                                    />
                                    {alcance === 'ml_listado' ? (
                                        <p className="mt-1 max-w-[220px] text-xs text-slate-500">
                                            En “Como ML” no se filtra por día.
                                        </p>
                                    ) : null}
                                </div>
                                <div>
                                    <button
                                        type="submit"
                                        className="rounded-lg border border-slate-500 bg-slate-800 px-5 py-2 text-white transition hover:bg-slate-700"
                                    >
                                        Ver pedidos
                                    </button>
                                </div>
                            </form>

                            <div className="flex w-full flex-col gap-2 md:max-w-[280px]">
                                <span className="text-xs font-medium uppercase tracking-wide text-slate-400">
                                    Qué pedidos ver
                                </span>
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setAlcance('colecta')}
                                        className={`rounded-lg border px-3 py-2 text-xs font-semibold transition sm:text-sm ${
                                            alcance === 'colecta'
                                                ? 'border-sky-400 bg-sky-500/20 text-sky-100'
                                                : 'border-slate-500 bg-slate-800 text-slate-200 hover:bg-slate-700'
                                        }`}
                                        title="Solo Flex/Colecta y ventana de fecha (tu lote diario)"
                                    >
                                        Lote colecta / Flex
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setAlcance('ml_listado')}
                                        className={`rounded-lg border px-3 py-2 text-xs font-semibold transition sm:text-sm ${
                                            alcance === 'ml_listado'
                                                ? 'border-sky-400 bg-sky-500/20 text-sky-100'
                                                : 'border-slate-500 bg-slate-800 text-slate-200 hover:bg-slate-700'
                                        }`}
                                        title="Como el listado de ML con filtro Etiquetas listas (más pedidos)"
                                    >
                                        Como ML · etiqueta lista
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setAlcance('procesados')}
                                        className={`rounded-lg border px-3 py-2 text-xs font-semibold transition sm:text-sm ${
                                            alcance === 'procesados'
                                                ? 'border-violet-400 bg-violet-500/20 text-violet-100'
                                                : 'border-slate-500 bg-slate-800 text-slate-200 hover:bg-slate-700'
                                        }`}
                                        title="Pedidos con etiqueta impresa, enviados, en tránsito o entregados"
                                    >
                                        Procesados
                                    </button>
                                </div>
                            </div>

                            <div className="flex w-full flex-col gap-2 md:w-auto">
                                <span className="text-xs font-medium uppercase tracking-wide text-slate-400">
                                    Orden de lista
                                </span>
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={() => setOrden('fecha')}
                                        className={`rounded-lg border px-4 py-2 text-sm font-semibold transition ${
                                            orden === 'fecha'
                                                ? 'border-amber-400 bg-amber-500/20 text-amber-100'
                                                : 'border-slate-500 bg-slate-800 text-slate-200 hover:bg-slate-700'
                                        }`}
                                    >
                                        Por fecha
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setOrden('marca')}
                                        className={`rounded-lg border px-4 py-2 text-sm font-semibold transition ${
                                            orden === 'marca'
                                                ? 'border-amber-400 bg-amber-500/20 text-amber-100'
                                                : 'border-slate-500 bg-slate-800 text-slate-200 hover:bg-slate-700'
                                        }`}
                                    >
                                        Por marca (orden fijo)
                                    </button>
                                </div>
                            </div>
                        </div>

                        {android ? (
                            <div className="mt-5 border border-cyan-700/70 bg-cyan-950/30 p-4">
                                <p className="text-xs font-medium uppercase tracking-wide text-cyan-200">Impresión térmica en Android</p>
                                <p className="mt-1 text-sm font-semibold text-cyan-50">Envía el PNG 4x8 directamente a RawBT.</p>
                                <p className="mt-1 text-xs text-cyan-100/80">RawBT convertirá la imagen una sola vez y utilizará la impresora predeterminada configurada en la tablet.</p>
                            </div>
                        ) : (
                            <div className="mt-5 flex flex-col gap-3 border border-cyan-700/70 bg-cyan-950/30 p-4 lg:flex-row lg:items-end">
                                <div className="min-w-0 flex-1">
                                    <label
                                        htmlFor="thermal-printer"
                                        className="mb-1 block text-xs font-medium uppercase tracking-wide text-cyan-200"
                                    >
                                        Impresora térmica de esta computadora
                                    </label>
                                    <select
                                        id="thermal-printer"
                                        value={thermalPrinterName}
                                        onChange={(event) => selectThermalPrinter(event.target.value)}
                                        className="w-full rounded-lg border border-cyan-700 bg-slate-900 px-4 py-2 text-white outline-none focus:border-cyan-400"
                                    >
                                        <option value="">Detectar automáticamente</option>
                                        {printerOptions.map((printer) => (
                                            <option key={printer} value={printer}>
                                                {printer}
                                            </option>
                                        ))}
                                    </select>
                                    <p className="mt-1 text-xs text-cyan-100/80">
                                        {thermalPrinterMessage
                                            || (thermalPrinterName
                                                ? `Seleccionada en esta computadora: ${thermalPrinterName}`
                                                : 'Compatible con 4BARCODE y Zebra GK420t. La elección se guarda solo en este navegador.')}
                                    </p>
                                </div>

                                <button
                                    type="button"
                                    onClick={() => loadThermalPrinters({ notify: true })}
                                    disabled={loadingThermalPrinters}
                                    className="rounded-lg border border-cyan-500 bg-cyan-700/30 px-4 py-2 text-sm font-semibold text-cyan-50 transition hover:bg-cyan-700/50 disabled:cursor-wait disabled:opacity-60"
                                >
                                    {loadingThermalPrinters ? 'Buscando...' : 'Buscar impresoras'}
                                </button>
                            </div>
                        )}

                        <div className="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div className="border border-slate-700 bg-slate-800/70 p-4">
                                <div className="text-sm text-slate-300">Pedidos</div>
                                <div className="mt-2 text-3xl font-bold text-white">{totalPedidos}</div>
                            </div>
                            <div className="border border-slate-700 bg-slate-800/70 p-4">
                                <div className="text-sm text-slate-300">Piezas vendidas</div>
                                <div className="mt-2 text-3xl font-bold text-white">{totalPiezas}</div>
                            </div>
                        </div>

                        <div className="mt-6">
                            {pedidos.length === 0 ? (
                                <div className="border border-slate-700 bg-slate-800/60 px-6 py-16 text-center">
                                    <p className="text-3xl font-semibold text-white">
                                        {alcance === 'ml_listado'
                                            ? 'No hay pedidos con etiqueta lista en la base'
                                            : alcance === 'procesados'
                                              ? 'No hay pedidos procesados para esta fecha'
                                              : 'No hay pedidos para esta fecha'}
                                    </p>
                                    <p className="mt-3 text-lg text-slate-300">
                                        {alcance === 'ml_listado'
                                            ? 'Sincronizá órdenes desde ML o revisá que no sean Full (esos no entran en AMS).'
                                            : alcance === 'procesados'
                                              ? 'Cambia la fecha o sincroniza nuevamente para actualizar el estado de los envíos.'
                                              : 'Cambia la fecha o probá “Como ML · etiqueta lista” para ver todo lo listo para imprimir.'}
                                    </p>
                                </div>
                            ) : (
                                pedidos.map((pedido) => (
                                    <div
                                        key={pedido.group_key}
                                        className="mb-6 border border-slate-500 bg-[#1b2a41]"
                                    >
                                        <div className="flex flex-col gap-2 border-b border-slate-500 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                            <div className="flex flex-wrap items-center gap-2 text-sm font-semibold text-white">
                                                <span>Pedido #{pedido.display_id}</span>
                                                <span
                                                    className={`rounded-md border px-2 py-0.5 text-xs font-semibold ${tipoBadgeClass(pedido.ams_tipo)}`}
                                                    title="Tipo de logística según datos de la orden y del envío"
                                                >
                                                    {tipoLabel(pedido.ams_tipo)}
                                                </span>
                                                {pedido.ml_envio_label ? (
                                                    <span
                                                        className={`rounded-md border px-2 py-0.5 text-xs font-semibold ${envioBadgeClass(
                                                            pedido.ml_envio_status,
                                                            pedido.ml_envio_substatus
                                                        )}`}
                                                        title={`MeLi envío: ${pedido.ml_envio_status || '—'}${pedido.ml_envio_substatus ? ` · ${pedido.ml_envio_substatus}` : ''}`}
                                                    >
                                                        {pedido.ml_envio_label}
                                                    </span>
                                                ) : null}
                                                {orden === 'marca' && pedido.ams_marca_label ? (
                                                    <span
                                                        className="rounded-md border border-amber-500/50 bg-amber-900/40 px-2 py-0.5 text-xs font-semibold text-amber-100"
                                                        title="Marca según título/SKU (primera coincidencia en la lista fija)"
                                                    >
                                                        {pedido.ams_marca_label}
                                                    </span>
                                                ) : null}
                                            </div>
                                            <div className="flex items-center gap-2">
                                                {(pedido.orders || []).map((order) => order.cancelled ? (
                                                    <span key={order.id} className="rounded-md border border-slate-400 bg-slate-700 px-3 py-1.5 text-xs font-semibold text-slate-100">Orden {order.order_id}: Cancelada / No concretada</span>
                                                ) : (
                                                    <button key={order.id} type="button" onClick={() => openCancellation(pedido, order)} disabled={cancellingOrderId !== null} className="rounded-md border border-red-500 bg-red-800/40 px-3 py-1.5 text-xs font-bold text-red-100 transition hover:bg-red-800/70 disabled:cursor-wait disabled:opacity-50">{cancellingOrderId === order.id ? 'Cancelando…' : `Cancelar compra · ${order.order_id}`}</button>
                                                ))}
                                                {etiquetaUrl(pedido, labelBaseUrl, alcance, fechaSeleccionada, selectedMeliAccountId) ? (
                                                    <>
                                                        <a
                                                            href={etiquetaUrl(
                                                                pedido,
                                                                labelBaseUrl,
                                                                alcance,
                                                                fechaSeleccionada,
                                                                selectedMeliAccountId
                                                            )}
                                                            onClick={(event) => { if (cancellingOrderId !== null) event.preventDefault() }}
                                                            aria-disabled={cancellingOrderId !== null}
                                                            className={`rounded-md border border-lime-500/60 bg-lime-700/20 px-3 py-1.5 text-xs font-semibold text-lime-100 transition hover:bg-lime-700/35 ${cancellingOrderId !== null ? 'pointer-events-none opacity-50' : ''}`}
                                                            title="Abre el PDF y el diálogo de impresión del navegador"
                                                        >
                                                            {alcance === 'procesados'
                                                                ? 'Reimprimir PDF'
                                                                : 'Imprimir PDF'}
                                                        </a>

                                                        <button
                                                            type="button"
                                                            onClick={() => imprimirTermica(pedido)}
                                                            disabled={cancellingOrderId !== null || (android
                                                                ? printingShippingId !== null
                                                                : printingShippingId === String(pedido?.shipping_id || '').trim())}
                                                            className="rounded-md border border-cyan-500/60 bg-cyan-700/20 px-3 py-1.5 text-xs font-semibold text-cyan-100 transition hover:bg-cyan-700/35 disabled:cursor-wait disabled:opacity-60"
                                                            title={android
                                                                ? 'Prepara la etiqueta KAMO 4x8 para abrirla en RawBT'
                                                                : `Imprime mediante QZ Tray en ${thermalPrinterName || 'la impresora térmica detectada'}`}
                                                        >
                                                            {printingShippingId === String(pedido?.shipping_id || '').trim()
                                                                ? (android ? 'Preparando KAMO...' : 'Imprimiendo...')
                                                                : (android ? 'Preparar impresión' : 'Imprimir térmica')}
                                                        </button>

                                                        {android
                                                        && preparedRawBtLabel?.shippingId === String(pedido?.shipping_id || '').trim() ? (
                                                            <>
                                                                <span className="text-xs font-semibold text-cyan-100">
                                                                    Etiqueta lista. Pulsa Abrir RawBT
                                                                </span>
                                                                <a
                                                                    href={preparedRawBtLabel.url}
                                                                    onClick={() => {
                                                                        const preparedLabel = preparedRawBtLabel

                                                                        window.setTimeout(() => {
                                                                            setPreparedRawBtLabel((current) => (
                                                                                current?.shippingId === preparedLabel.shippingId
                                                                                && current?.url === preparedLabel.url
                                                                                    ? null
                                                                                    : current
                                                                            ))
                                                                        }, 1500)
                                                                    }}
                                                                    className="rounded-md border border-cyan-400 bg-cyan-600 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-cyan-500"
                                                                >
                                                                    Abrir RawBT
                                                                </a>
                                                            </>
                                                        ) : null}

                                                        <a
                                                            href={etiquetaTermicaUrl(pedido, labelBaseUrl)}
                                                            onClick={(event) => { if (cancellingOrderId !== null) event.preventDefault() }}
                                                            aria-disabled={cancellingOrderId !== null}
                                                            className={`rounded-md border border-slate-500 bg-slate-800 px-3 py-1.5 text-xs font-semibold text-slate-200 transition hover:bg-slate-700 ${cancellingOrderId !== null ? 'pointer-events-none opacity-50' : ''}`}
                                                            title="Descarga el ZIP/ZPL como respaldo"
                                                        >
                                                            Descargar ZPL
                                                        </a>
                                                    </>
                                                ) : (
                                                    <a
                                                        href={mlVentaUrl(pedido)}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        onClick={(event) => { if (cancellingOrderId !== null) event.preventDefault() }}
                                                        aria-disabled={cancellingOrderId !== null}
                                                        className={`rounded-md border border-amber-500/60 bg-amber-700/20 px-3 py-1.5 text-xs font-semibold text-amber-100 transition hover:bg-amber-700/35 ${cancellingOrderId !== null ? 'pointer-events-none opacity-50' : ''}`}
                                                        title="Sin shipping_id; abre ventas de ML como respaldo"
                                                    >
                                                        Abrir en Mercado Libre
                                                    </a>
                                                )}
                                                <div className="text-sm text-slate-200">
                                                    {pedido.fecha_pedido_formateada}
                                                </div>
                                            </div>
                                        </div>

                                        <div className="divide-y divide-slate-500">
                                            {pedido.items.map((item, idx) => (
                                                <div key={`${item.sku}-${item.titulo}-${idx}`} className="p-4">
                                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-[110px_minmax(0,1fr)]">
                                                        <div className="flex items-start justify-center md:justify-start">
                                                            {item.imagen ? (
                                                                <img
                                                                    src={item.imagen}
                                                                    alt={item.titulo}
                                                                    className="h-[110px] w-[110px] rounded-xl bg-white object-contain p-1"
                                                                />
                                                            ) : (
                                                                <div className="flex h-[110px] w-[110px] items-center justify-center rounded-xl bg-slate-700 text-xs text-slate-300">
                                                                    Sin imagen
                                                                </div>
                                                            )}
                                                        </div>
                                                        <div className="min-w-0">
                                                            <div className="mb-3 flex flex-wrap items-center gap-2">
                                                                <h3 className="text-lg font-semibold leading-tight text-white">
                                                                    {item.titulo}
                                                                </h3>
                                                                {orden === 'marca' && item.ams_marca_label ? (
                                                                    <span className="rounded border border-slate-500 bg-slate-900/80 px-2 py-0.5 text-xs text-slate-300">
                                                                        {item.ams_marca_label}
                                                                    </span>
                                                                ) : null}
                                                            </div>
                                                            <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                                                                <div className="rounded-xl border border-slate-400 bg-[#1b2a41] px-4 py-3">
                                                                    <div className="text-xs uppercase tracking-wide text-slate-300">
                                                                        Piezas
                                                                    </div>
                                                                    <div className="mt-1 text-3xl font-semibold text-white">
                                                                        {item.cantidad}
                                                                    </div>
                                                                </div>
                                                                <div className="rounded-xl border border-slate-400 bg-[#1b2a41] px-4 py-3">
                                                                    <div className="text-xs uppercase tracking-wide text-slate-300">
                                                                        SKU
                                                                    </div>
                                                                    <div className="mt-1 break-all text-3xl font-semibold text-white">
                                                                        {item.sku || 'N/A'}
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>

                                        <div className="border-t border-slate-500 px-4 py-3 text-sm text-white">
                                            Total de piezas del pedido:
                                            <span className="font-semibold"> {pedido.total_piezas}</span>
                                        </div>
                                    </div>
                                ))
                            )}
                        </div>
                    </div>
                </div>
            </section>
            {cancelOrder ? <div role="dialog" aria-modal="true" className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"><div className="w-full max-w-2xl rounded-xl border border-red-500 bg-slate-950 p-6 text-white shadow-2xl"><h2 className="text-2xl font-black text-red-400">CANCELAR COMPRA</h2><dl className="mt-5 grid gap-3 sm:grid-cols-2"><div><dt className="text-xs font-bold uppercase text-slate-400">Cuenta Mercado Libre</dt><dd>{selectedMeliAccountLabel}</dd></div><div><dt className="text-xs font-bold uppercase text-slate-400">Pack</dt><dd>{cancelOrder.pedido.pack_id || 'Sin pack'}</dd></div><div><dt className="text-xs font-bold uppercase text-slate-400">Pedido real</dt><dd>{cancelOrder.order.order_id}</dd></div><div><dt className="text-xs font-bold uppercase text-slate-400">Estado del pedido</dt><dd>{cancelOrder.order.status || '—'}</dd></div><div><dt className="text-xs font-bold uppercase text-slate-400">Estado del envío</dt><dd>{cancelOrder.order.shipping_label || cancelOrder.order.shipping_status || '—'}</dd></div><div><dt className="text-xs font-bold uppercase text-slate-400">Total</dt><dd>{cancelOrder.order.total_amount == null ? 'No disponible' : `${cancelOrder.order.currency_id || ''} $${Number(cancelOrder.order.total_amount).toFixed(2)}`}</dd></div><div className="sm:col-span-2"><dt className="text-xs font-bold uppercase text-slate-400">Producto / SKU / cantidad</dt><dd>{(cancelOrder.order.items || []).map((item) => `${item.titulo} · SKU ${item.sku || 'N/A'} · ${item.cantidad}`).join(' | ') || 'No disponible'}</dd></div></dl><p className="mt-5 rounded-lg border border-red-700 bg-red-950/70 p-4 font-semibold">Esta acción marcará la venta como no concretada y Mercado Libre podrá devolver el dinero al comprador. Una cancelación realizada por el vendedor puede afectar tus métricas/reputación.</p><label className="mt-5 block font-bold" htmlFor="cancel-reason">Motivo de cancelación</label><select id="cancel-reason" value={cancelReason} disabled={cancellingOrderId !== null} onChange={(event) => setCancelReason(event.target.value)} className="mt-2 w-full rounded-lg border border-slate-600 bg-slate-900 p-3"><option value="">Selecciona un motivo</option>{Object.entries(cancelReasons).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>{cancelReason ? <p className="mt-2 text-sm text-slate-300">Se enviará el mensaje seguro definido por el sistema para este motivo.</p> : null}<label className="mt-5 flex gap-3 font-bold"><input type="checkbox" checked={cancelConfirmed} disabled={cancellingOrderId !== null} onChange={(event) => setCancelConfirmed(event.target.checked)} /><span>Confirmo que deseo cancelar esta compra.</span></label><div className="mt-6 flex justify-end gap-3"><button type="button" disabled={cancellingOrderId !== null} onClick={() => setCancelOrder(null)} className="rounded-lg border border-slate-500 px-4 py-2 font-bold disabled:opacity-50">Cerrar</button><button type="button" disabled={!cancelReason || !cancelConfirmed || cancellingOrderId !== null} onClick={confirmCancellation} className="rounded-lg bg-red-700 px-4 py-2 font-black text-white disabled:opacity-40">{cancellingOrderId !== null ? 'Procesando…' : 'Confirmar cancelación'}</button></div></div></div> : null}
        </AppShell>
    )
}
