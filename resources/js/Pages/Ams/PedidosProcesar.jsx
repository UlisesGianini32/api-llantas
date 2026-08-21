import AppShell from '@/Components/layout/AppShell'
import { useState } from 'react'
import { router } from '@inertiajs/react'
import qz from 'qz-tray'

let qzSecurityConfigured = false

// AMS_PRIMARY_THERMAL_PRINTER_V2
const THERMAL_PRINTER_STORAGE_KEY = 'ams_primary_thermal_printer_v2'

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

function etiquetaUrl(pedido, labelBaseUrl) {
    const shippingId = String(pedido?.shipping_id || '').trim()
    if (!shippingId) {
        return null
    }

    const base = String(labelBaseUrl || '/ams/pedidos/shipping-label').replace(/\/+$/, '')
    return `${base}/${encodeURIComponent(shippingId)}/print`
}

function esImpresoraKamo(printerName) {
    const name = String(printerName || '')
        .trim()
        .toLowerCase()

    return name.includes('kamo ka-l1')
        || name.includes('kamo')
        || name.includes('td-402s')
}

function etiquetaKamoTsplUrl(pedido, labelBaseUrl) {
    const shippingId = String(
        pedido?.shipping_id || ''
    ).trim()

    if (!shippingId) {
        return null
    }

    const base = String(
        labelBaseUrl || '/ams/pedidos/shipping-label'
    ).replace(/\/+$/, '')

    return `${base}/${encodeURIComponent(shippingId)}/kamo-tspl`
}

function etiquetaKamoPngUrl(pedido, labelBaseUrl) {
    const shippingId = String(
        pedido?.shipping_id || ''
    ).trim()

    if (!shippingId) {
        return null
    }

    const base = String(
        labelBaseUrl || '/ams/pedidos/shipping-label'
    ).replace(/\/+$/, '')

    return `${base}/${encodeURIComponent(shippingId)}/kamo-png`
}

function etiquetaTermicaRawUrl(pedido, labelBaseUrl) {
    const shippingId = String(pedido?.shipping_id || '').trim()
    if (!shippingId) {
        return null
    }

    const base = String(labelBaseUrl || '/ams/pedidos/shipping-label').replace(/\/+$/, '')
    return `${base}/${encodeURIComponent(shippingId)}/zpl-raw`
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
}) {
    const [printingShippingId, setPrintingShippingId] = useState(null)
    const [printerBusy, setPrinterBusy] = useState(false)
    const [selectedThermalPrinter, setSelectedThermalPrinter] = useState(() => {
        try {
            return window.localStorage.getItem(THERMAL_PRINTER_STORAGE_KEY) || ''
        } catch {
            return ''
        }
    })

    const connectQz = async () => {
        configureQzSecurity()

        if (!qz.websocket.isActive()) {
            await qz.websocket.connect({
                retries: 3,
                delay: 1,
            })
        }
    }

    const listThermalPrinters = async () => {
        await connectQz()

        const found = await qz.printers.find()
        const printers = (Array.isArray(found) ? found : [found])
            .map((name) => String(name || '').trim())
            .filter(Boolean)

        if (printers.length === 0) {
            throw new Error(
                'QZ Tray no encontró impresoras instaladas en esta computadora.'
            )
        }

        return printers
    }

    const saveThermalPrinter = (printer) => {
        const normalized = String(printer || '').trim()

        if (!normalized) {
            return
        }

        setSelectedThermalPrinter(normalized)

        try {
            window.localStorage.setItem(
                THERMAL_PRINTER_STORAGE_KEY,
                normalized
            )
        } catch {
            // La impresión puede continuar aunque el navegador bloquee localStorage.
        }
    }

    const resolverImpresoraTermica = async (forceSelection = false) => {
        const printers = await listThermalPrinters()
        const saved = String(selectedThermalPrinter || '').trim()

        if (!forceSelection && saved && printers.includes(saved)) {
            return saved
        }

        const savedIndex = printers.indexOf(saved)
        const zebraIndex = printers.findIndex((name) => {
            const normalized = name.toLowerCase()

            return normalized.includes('zebra')
                || normalized.includes('zdesigner')
                || normalized.includes('gk420')
        })

        const suggestedIndex = savedIndex >= 0
            ? savedIndex
            : (zebraIndex >= 0 ? zebraIndex : 0)

        const list = printers
            .map((name, index) => `${index + 1}. ${name}`)
            .join('\n')

        const answer = window.prompt(
            'Impresoras detectadas por QZ Tray:\n\n'
                + list
                + '\n\nEscribe el número de la impresora térmica que deseas usar.',
            String(suggestedIndex + 1)
        )

        if (answer === null) {
            throw new Error('No seleccionaste una impresora.')
        }

        const index = Number.parseInt(String(answer).trim(), 10) - 1

        if (!Number.isInteger(index) || index < 0 || index >= printers.length) {
            throw new Error('El número de impresora seleccionado no es válido.')
        }

        const printer = printers[index]
        saveThermalPrinter(printer)

        return printer
    }

    const seleccionarImpresoraTermica = async () => {
        setPrinterBusy(true)

        try {
            const printer = await resolverImpresoraTermica(true)

            window.alert(
                `Impresora guardada para esta computadora: ${printer}`
            )
        } catch (error) {
            console.error('No se pudo seleccionar la impresora:', error)

            const message = error instanceof Error
                ? error.message
                : String(error)

            window.alert(`No se pudo seleccionar la impresora. ${message}`)
        } finally {
            setPrinterBusy(false)
        }
    }

    const probarImpresoraTermica = async () => {
        setPrinterBusy(true)

        try {
            const printer = await resolverImpresoraTermica(false)
            const config = qz.configs.create(printer, {
                encoding: 'UTF-8',
            })

            const testZpl = [
                '^XA',
                '^PW812',
                '^LL1218',
                '^FO80,100^A0N,55,55^FDPRUEBA DE IMPRESORA^FS',
                '^FO80,190^A0N,38,38^FDAMS - ETIQUETA TERMICA^FS',
                `^FO80,270^A0N,30,30^FD${printer.replace(/[\^~]/g, ' ')}^FS`,
                '^FO80,350^GB650,3,3^FS',
                '^FO80,410^A0N,32,32^FDSi ves esta etiqueta, QZ Tray funciona.^FS',
                '^XZ',
            ].join('\n')

            await qz.print(config, [
                {
                    type: 'raw',
                    format: 'command',
                    flavor: 'plain',
                    data: testZpl,
                },
            ])

            window.alert(`Prueba enviada correctamente a: ${printer}`)
        } catch (error) {
            console.error('Error en prueba de impresora:', error)

            const message = error instanceof Error
                ? error.message
                : String(error)

            window.alert(`No se pudo imprimir la prueba. ${message}`)
        } finally {
            setPrinterBusy(false)
        }
    }

    const imprimirTermica = async (pedido) => {
        const shippingId = String(
            pedido?.shipping_id || ''
        ).trim()

        const rawUrl = etiquetaTermicaRawUrl(
            pedido,
            labelBaseUrl
        )

        const kamoUrl = etiquetaKamoPngUrl(
            pedido,
            labelBaseUrl
        )

        if (!shippingId || !rawUrl) {
            window.alert(
                'Este pedido no tiene shipping_id.'
            )
            return
        }

        setPrintingShippingId(shippingId)

        try {
            const printer =
                await resolverImpresoraTermica(false)

            /*
             * ============================================
             * KAMO KA-L1
             * ============================================
             *
             * No interpreta ZPL.
             * Pedimos el PNG renderizado y QZ lo imprime
             * como imagen mediante el driver de Windows.
             */
            if (esImpresoraKamo(printer)) {
                const kamoTsplUrl =
                    etiquetaKamoTsplUrl(
                        pedido,
                        labelBaseUrl
                    )

                if (!kamoTsplUrl) {
                    throw new Error(
                        'No se pudo generar la URL TSPL KAMO.'
                    )
                }

                /*
                 * Pedimos el archivo binario TSPL.
                 */
                const response = await fetch(
                    kamoTsplUrl,
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

                /*
                 * Convertimos el binario a Base64
                 * sin alterar ningún byte del BITMAP.
                 */
                let binary = ''

                const chunkSize = 0x8000

                for (
                    let offset = 0;
                    offset < bytes.length;
                    offset += chunkSize
                ) {
                    const chunk = bytes.subarray(
                        offset,
                        Math.min(
                            offset + chunkSize,
                            bytes.length
                        )
                    )

                    binary +=
                        String.fromCharCode(...chunk)
                }

                const base64 =
                    window.btoa(binary)

                /*
                 * RAW puro.
                 *
                 * La KAMO interpreta el TSPL directamente.
                 */
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
                            flavor: 'base64',
                            data: base64,
                        },
                    ]
                )

                window.alert(
                    `Etiqueta KAMO enviada correctamente a: ${printer}`
                )

                return
            }

            /*
             * ============================================
             * ZEBRA / 4BARCODE / ZPL
             * ============================================
             */
            const response = await fetch(rawUrl, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'text/plain',
                },
            })

            const body = await response.text()

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

            const config = qz.configs.create(
                printer,
                {
                    encoding: 'UTF-8',
                }
            )

            await qz.print(config, [
                {
                    type: 'raw',
                    format: 'command',
                    flavor: 'plain',
                    data: zpl,
                },
            ])

            window.alert(
                `Etiqueta enviada correctamente a: ${printer}`
            )
        } catch (error) {
            console.error(
                'Error al imprimir con QZ Tray:',
                error
            )

            const message =
                error instanceof Error
                    ? error.message
                    : String(error)

            window.alert(
                `No se pudo imprimir la etiqueta térmica. ${message}`
            )
        } finally {
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

    return (
        <AppShell title={tituloPagina}>
            <section className="bg-[#0b1220] min-h-[calc(100vh-4rem)] py-4 sm:py-6">
                <div className="mx-auto max-w-[1400px] px-4 sm:px-6 lg:px-8">
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
                                        title="Pedidos con etiqueta impresa o cuyo envío ya avanzó"
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
                                              ? 'Cambia la fecha o vuelve a entrar para actualizar el estado de los envíos.'
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
                                                {etiquetaUrl(pedido, labelBaseUrl) ? (
                                                    <>
                                                        <a
                                                            href={etiquetaUrl(pedido, labelBaseUrl)}
                                                            className="rounded-md border border-lime-500/60 bg-lime-700/20 px-3 py-1.5 text-xs font-semibold text-lime-100 transition hover:bg-lime-700/35"
                                                            title="Abre el PDF y el diálogo de impresión"
                                                        >
                                                            Imprimir PDF
                                                        </a>

                                                        <button
                                                            type="button"
                                                            onClick={seleccionarImpresoraTermica}
                                                            disabled={printerBusy || printingShippingId !== null}
                                                            className="rounded-md border border-violet-500/60 bg-violet-700/20 px-3 py-1.5 text-xs font-semibold text-violet-100 transition hover:bg-violet-700/35 disabled:cursor-wait disabled:opacity-60"
                                                            title={selectedThermalPrinter
                                                                ? `Impresora actual: ${selectedThermalPrinter}`
                                                                : 'Elegir la impresora térmica de esta computadora'}
                                                        >
                                                            {printerBusy
                                                                ? 'Buscando...'
                                                                : (selectedThermalPrinter ? 'Cambiar impresora' : 'Elegir impresora')}
                                                        </button>

                                                        <button
                                                            type="button"
                                                            onClick={probarImpresoraTermica}
                                                            disabled={printerBusy || printingShippingId !== null}
                                                            className="rounded-md border border-fuchsia-500/60 bg-fuchsia-700/20 px-3 py-1.5 text-xs font-semibold text-fuchsia-100 transition hover:bg-fuchsia-700/35 disabled:cursor-wait disabled:opacity-60"
                                                            title={selectedThermalPrinter
                                                                ? `Enviar prueba a ${selectedThermalPrinter}`
                                                                : 'Seleccionar impresora y enviar una etiqueta de prueba'}
                                                        >
                                                            Probar impresora
                                                        </button>

                                                        <button
                                                            type="button"
                                                            onClick={() => imprimirTermica(pedido)}
                                                            disabled={printingShippingId === String(pedido?.shipping_id || '').trim()}
                                                            className="rounded-md border border-cyan-500/60 bg-cyan-700/20 px-3 py-1.5 text-xs font-semibold text-cyan-100 transition hover:bg-cyan-700/35 disabled:cursor-wait disabled:opacity-60"
                                                            title="Imprime la etiqueta modificada mediante QZ Tray"
                                                        >
                                                            {printingShippingId === String(pedido?.shipping_id || '').trim()
                                                                ? 'Imprimiendo...'
                                                                : 'Imprimir térmica'}
                                                        </button>
                                                    </>
                                                ) : (
                                                    <a
                                                        href={mlVentaUrl(pedido)}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="rounded-md border border-amber-500/60 bg-amber-700/20 px-3 py-1.5 text-xs font-semibold text-amber-100 transition hover:bg-amber-700/35"
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
        </AppShell>
    )
}