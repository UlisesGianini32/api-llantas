import AppShell from '@/Components/layout/AppShell'
import { configureQzSecurity } from '@/lib/qzSecurity'
import {
    canStartLabelPrint,
    DEFAULT_LABEL_PRINTER,
    exclusiveLabelPrint,
    isQzConnected,
    LABEL_PRINTER_STORAGE_KEY,
    labelPrintButtonText,
    labelPrintProgress,
    labelTypePresentation,
    preferredLabelPrinter,
    sendLabelBatch,
} from '@/lib/meliLabelPrinting'
import { router, usePage } from '@inertiajs/react'
import axios from 'axios'
import qz from 'qz-tray'
import { useEffect, useRef, useState } from 'react'

const primaryButton = 'rounded-xl bg-indigo-600 px-4 py-2 font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40'
const secondaryButton = 'rounded-xl border border-slate-300 px-4 py-2 font-semibold disabled:cursor-not-allowed disabled:opacity-40 dark:border-neutral-700'
const field = 'w-full rounded-xl border border-slate-300 p-3 dark:border-neutral-700 dark:bg-neutral-900'

function formatDate(value) {
    return value ? new Intl.DateTimeFormat('es-MX', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value)) : '—'
}

function historyStatus(status) {
    return { analyzed: 'Analizado', printed: 'Impreso', failed: 'Fallido' }[status] || status
}

function historyQuantity(item) {
    const blocks = item.zpl_blocks_count ?? item.labels_count
    const physical = item.physical_labels_count ?? item.labels_count

    return item.type === 'product'
        ? `${blocks} productos / ${physical} etiquetas`
        : `${physical} ${physical === 1 ? 'guía' : 'guías'}`
}

export default function Etiquetas() {
    const { history: initialHistory = [], historyFilter: initialHistoryFilter = '' } = usePage().props
    const [file, setFile] = useState(null)
    const [batch, setBatch] = useState(null)
    const [printers, setPrinters] = useState([])
    const [printer, setPrinter] = useState('')
    const [qzConnected, setQzConnected] = useState(false)
    const [busy, setBusy] = useState('')
    const [error, setError] = useState('')
    const [message, setMessage] = useState('')
    const [results, setResults] = useState([])
    const [reprintConfirmed, setReprintConfirmed] = useState(false)
    const [completed, setCompleted] = useState(false)
    const [history, setHistory] = useState(initialHistory)
    const [historyFilter, setHistoryFilter] = useState(initialHistoryFilter || 'all')
    const [dragging, setDragging] = useState(false)
    const inputRef = useRef(null)
    const locked = useRef(false)

    useEffect(() => {
        const beforeUnload = (event) => {
            if (locked.current) {
                event.preventDefault()
                event.returnValue = ''
            }
        }
        window.addEventListener('beforeunload', beforeUnload)
        const remove = router.on('before', (event) => {
            if (locked.current) event.preventDefault()
        })

        return () => {
            window.removeEventListener('beforeunload', beforeUnload)
            remove()
        }
    }, [])

    async function connect() {
        configureQzSecurity()
        try {
            if (!isQzConnected(qz)) await qz.websocket.connect({ usingSecure: true })
            setQzConnected(true)
        } catch {
            setQzConnected(false)
            throw new Error('No se pudo conectar con QZ Tray. Comprueba que esté instalado y abierto en esta computadora y acepta la conexión.')
        }
    }

    async function detect() {
        if (locked.current) return
        locked.current = true
        setBusy('detecting')
        setError('')
        setMessage('Buscando impresoras...')
        try {
            await connect()
            const found = await qz.printers.find()
            const list = (Array.isArray(found) ? found : [found]).filter(Boolean)
            setPrinters(list)
            let remembered = ''
            try { remembered = localStorage.getItem(LABEL_PRINTER_STORAGE_KEY) || '' } catch { /* optional preference */ }
            const selected = preferredLabelPrinter(list, remembered)
            setPrinter(selected)
            setMessage(selected ? `Impresora seleccionada: ${selected}` : 'Selecciona una impresora ZPL de la lista.')
            if (!list.length) throw new Error('QZ Tray no encontró impresoras instaladas en esta computadora.')
        } catch (exception) {
            setQzConnected(false)
            setError(exception.message)
            setMessage('')
        } finally {
            locked.current = false
            setBusy('')
        }
    }

    function chooseFile(selected) {
        setFile(selected || null)
        setBatch(null)
        setResults([])
        setReprintConfirmed(false)
        setCompleted(false)
        setError('')
        setMessage('')
    }

    function clearBatch() {
        chooseFile(null)
        if (inputRef.current) inputRef.current.value = ''
    }

    async function process(event) {
        event.preventDefault()
        if (locked.current || !file) return
        locked.current = true
        setBusy('processing')
        setError('')
        setBatch(null)
        setResults([])
        setReprintConfirmed(false)
        setCompleted(false)
        setMessage('Analizando archivo...')
        try {
            const data = new FormData()
            data.append('file', file)
            const response = await axios.post('/mercado-libre/etiquetas/procesar', data, { headers: { Accept: 'application/json' } })
            setBatch(response.data)
            setResults(response.data.labels.map(() => 'pending'))
            setMessage(response.data.previous_print
                ? 'Este archivo ya fue impreso. Confirma la reimpresión antes de continuar.'
                : 'Archivo listo para imprimir.')
        } catch (exception) {
            setMessage('')
            setError(exception.response?.data?.errors?.file?.[0] || 'No se pudo procesar el archivo. Comprueba tu sesión y que el TXT no supere 5 MB.')
        } finally {
            locked.current = false
            setBusy('')
        }
    }

    async function persistResult(status, errorMessage = null) {
        const response = await axios.post(`/mercado-libre/etiquetas/registrar-impresion/${batch.print_id}`, {
            printer_name: printer,
            status,
            confirmed_reprint: reprintConfirmed,
            error_message: errorMessage,
        }, { headers: { Accept: 'application/json' } })
        const saved = response.data.print
        setHistory((current) => [saved, ...current.filter((item) => item.id !== saved.id)])

        return saved
    }

    async function print(retry = false) {
        if (locked.current || !batch || !printer || completed) return
        if (batch.previous_print && !reprintConfirmed) {
            setError('Debes confirmar la reimpresión antes de enviar el archivo a QZ Tray.')
            return
        }
        if (retry && !window.confirm('Se reintentará cada bloque no confirmado. El bloque incierto puede haber generado una o varias etiquetas antes del error. Revisa físicamente la impresora para evitar duplicados. ¿Continuar?')) return

        locked.current = true
        setBusy('printing')
        setError('')
        let qzAttemptStarted = false
        try {
            await exclusiveLabelPrint(async () => {
                await connect()
                const installed = await qz.printers.find()
                if (!(Array.isArray(installed) ? installed : [installed]).includes(printer)) {
                    throw new Error('La impresora seleccionada ya no está disponible. Vuelve a detectar las impresoras.')
                }

                const state = retry ? [...results] : batch.labels.map(() => 'pending')
                const indices = state.flatMap((status, index) => status === 'sent' ? [] : [index])
                setResults([...state])
                setMessage(`Enviando ${indices.length} bloques ZPL (${batch.count} ${labelTypePresentation(batch.type, batch.count).noun}) a ${printer}...`)
                qzAttemptStarted = true

                try {
                    await sendLabelBatch(qz, printer, batch.labels, indices, (index, status) => {
                        state[index] = status
                        setResults([...state])
                        const currentProgress = labelPrintProgress(state, batch.quantities)
                        setMessage(`Bloque ${index + 1} de ${batch.block_count}: ${currentProgress.sent} / ${batch.count} ${labelTypePresentation(batch.type, batch.count).noun} ${status === 'sent' ? 'enviadas a la cola' : 'con envío incierto'}.`)
                    })
                } catch (exception) {
                    try { await persistResult('failed', exception.message) } catch (historyError) { console.error('No se pudo registrar el fallo de impresión.', historyError) }
                    throw exception
                }

                setCompleted(true)
                setMessage(`Impresión terminada. ${batch.count} ${labelTypePresentation(batch.type, batch.count).noun} enviadas a ${printer}.`)
                try {
                    const saved = await persistResult('printed')
                    setBatch((current) => ({ ...current, previous_print: saved }))
                    setReprintConfirmed(false)
                } catch (historyError) {
                    console.error('QZ completó el envío, pero no se guardó el historial.', historyError)
                    setError('QZ completó el envío, pero no fue posible guardar el historial. No vuelvas a imprimir sin comprobar primero la impresora.')
                }
            })
        } catch (exception) {
            setError(qzAttemptStarted
                ? exception.message || 'No se pudo completar la impresión. Revisa la impresora antes de reintentar.'
                : exception.message || 'No se pudo imprimir. Verifica QZ Tray y los permisos del navegador.')
        } finally {
            locked.current = false
            setBusy('')
        }
    }

    function selectPrinter(name) {
        setPrinter(name)
        try {
            if (name) localStorage.setItem(LABEL_PRINTER_STORAGE_KEY, name)
            else localStorage.removeItem(LABEL_PRINTER_STORAGE_KEY)
        } catch { /* optional preference */ }
    }

    const presentation = batch ? labelTypePresentation(batch.type, batch.count) : null
    const progress = labelPrintProgress(results, batch?.quantities || [])
    const retryAvailable = results.includes('uncertain')
    const unconfirmedBlocks = results.filter((status) => status !== 'sent').length
    const canPrint = canStartLabelPrint({ batch, printer, qzConnected, busy, reprintConfirmed, completed })
    const filteredHistory = historyFilter === 'all' ? history : history.filter((item) => item.type === historyFilter)

    return <AppShell title="Etiquetas Mercado Libre">
        <div className="mx-auto max-w-5xl space-y-6 text-slate-900 dark:text-slate-100">
            <div>
                <h1 className="text-2xl font-bold">Etiquetas Mercado Libre</h1>
                <p className="mt-1 text-slate-600 dark:text-slate-300">Carga las etiquetas de productos o las guías de bultos descargadas desde Mercado Libre.</p>
            </div>

            <form onSubmit={process} className="space-y-4 rounded-2xl border border-slate-200 p-5 dark:border-neutral-800">
                <input ref={inputRef} id="labels-file" type="file" accept=".txt,text/plain" disabled={!!busy} className="sr-only" onChange={(event) => chooseFile(event.target.files?.[0])} />
                <label
                    htmlFor="labels-file"
                    className={`block cursor-pointer rounded-2xl border-2 border-dashed p-10 text-center transition ${dragging ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-950/30' : 'border-slate-300 dark:border-neutral-700'}`}
                    onDragEnter={(event) => { event.preventDefault(); setDragging(true) }}
                    onDragOver={(event) => event.preventDefault()}
                    onDragLeave={() => setDragging(false)}
                    onDrop={(event) => {
                        event.preventDefault()
                        setDragging(false)
                        if (!busy) chooseFile(event.dataTransfer.files?.[0])
                    }}
                >
                    <span className="block text-lg font-semibold">Arrastra tu archivo TXT aquí</span>
                    <span className="mt-1 block">o haz clic para seleccionarlo</span>
                    <span className="mt-3 block text-sm text-slate-500">Productos · Bultos · máximo 5 MB / 500 bloques / 10,000 etiquetas físicas</span>
                </label>
                {file && <p className="break-all text-sm">Seleccionado: <strong>{file.name}</strong></p>}
                <button className={primaryButton} disabled={!!busy || !file}>{busy === 'processing' ? 'Analizando...' : 'Analizar archivo'}</button>
            </form>

            <section className="space-y-4 rounded-2xl border border-slate-200 p-5 dark:border-neutral-800">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div><h2 className="text-lg font-semibold">Impresora QZ Tray</h2><p className={qzConnected ? 'text-emerald-600' : 'text-amber-600'}>{qzConnected ? 'QZ Tray conectado' : 'QZ Tray desconectado'}</p></div>
                    <button type="button" className={secondaryButton} disabled={!!busy} onClick={detect}>{busy === 'detecting' ? 'Buscando...' : 'Detectar impresoras'}</button>
                </div>
                <label htmlFor="label-printer" className="block font-semibold">Impresora <span className="font-normal text-slate-500">(preferida: {DEFAULT_LABEL_PRINTER})</span></label>
                <select id="label-printer" className={field} value={printer} disabled={!!busy} onChange={(event) => selectPrinter(event.target.value)}>
                    <option value="">Selecciona una impresora ZPL</option>
                    {printers.map((name) => <option key={name} value={name}>{name}</option>)}
                </select>
                {!qzConnected && <p className="text-sm text-slate-500">Detecta las impresoras y mantén QZ Tray abierto para habilitar la impresión.</p>}
            </section>

            {batch && <section className="space-y-4 rounded-2xl border border-slate-200 p-5 dark:border-neutral-800">
                <div className="flex items-center gap-3"><span className="text-3xl" aria-hidden="true">{presentation.icon}</span><div><h2 className="text-lg font-semibold">{presentation.name}</h2><p className="text-emerald-600">Archivo válido</p></div></div>
                <dl className="grid gap-3 sm:grid-cols-2">
                    <div><dt className="text-sm text-slate-500">Envío</dt><dd className="font-semibold">{batch.shipment_id || `Hash ${batch.file_hash.slice(0, 12)}`}</dd></div>
                    {batch.type === 'product' ? <>
                        <div><dt className="text-sm text-slate-500">Productos / diseños</dt><dd className="font-semibold">{batch.block_count}</dd></div>
                        <div><dt className="text-sm text-slate-500">Etiquetas físicas</dt><dd className="font-semibold">{batch.physical_label_count}</dd></div>
                        <div><dt className="text-sm text-slate-500">Formato</dt><dd className="font-semibold">2 × 1 horizontal</dd></div>
                        <div><dt className="text-sm text-slate-500">Resolución</dt><dd className="font-semibold">203 dpi · 406 × 203 dots</dd></div>
                    </> : <div><dt className="text-sm text-slate-500">Guías</dt><dd className="font-semibold">{batch.physical_label_count}</dd></div>}
                    <div className="sm:col-span-2"><dt className="text-sm text-slate-500">Archivo</dt><dd className="break-all font-semibold">{batch.filename}</dd></div>
                </dl>

                {batch.previous_print && !reprintConfirmed && !completed && <div className="space-y-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100">
                    <p className="font-bold">Este archivo ya fue impreso anteriormente.</p>
                    <p>Envío: {batch.previous_print.shipment_id || 'No identificado'} · {historyQuantity(batch.previous_print)} · Fecha: {formatDate(batch.previous_print.printed_at)} · Impresora: {batch.previous_print.printer_name || 'No registrada'}</p>
                    <div className="flex gap-3"><button type="button" className={secondaryButton} onClick={clearBatch}>Cancelar</button><button type="button" className={primaryButton} onClick={() => { setReprintConfirmed(true); setMessage('Reimpresión confirmada. Pulsa Imprimir para continuar.') }}>Reimprimir</button></div>
                </div>}

                {(!batch.previous_print || reprintConfirmed) && !completed && <button type="button" className={primaryButton} disabled={!canPrint} onClick={() => print()}>{busy === 'printing' ? 'Imprimiendo...' : labelPrintButtonText(batch.type, batch.count)}</button>}
                {retryAvailable && !completed && <button type="button" className={secondaryButton} disabled={!!busy || !printer || !qzConnected} onClick={() => print(true)}>Reintentar {unconfirmedBlocks} bloques no confirmados</button>}
                <progress className="h-3 w-full" value={progress.sent} max={batch.count} aria-label={`${presentation.name} enviadas`} />
                <p>{progress.sent} / {batch.count} {presentation.noun} enviadas correctamente a QZ Tray ({progress.percentage}%).</p>
                {completed && <p className="rounded-xl bg-emerald-50 p-4 font-semibold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200">Impresión terminada. Para reimprimir, carga nuevamente el TXT y confirma la advertencia.</p>}
                <p className="text-sm text-slate-500">QZ confirma el envío a la cola; la salida física y los códigos deben comprobarse en la impresora.</p>
            </section>}

            <p role="status" aria-live="polite">{message}</p>
            {error && <p role="alert" className="rounded-xl bg-red-50 p-4 text-red-800 dark:bg-red-950 dark:text-red-200">{error}</p>}

            <section className="space-y-4 rounded-2xl border border-slate-200 p-5 dark:border-neutral-800">
                <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-lg font-semibold">Historial</h2><div className="flex gap-2" aria-label="Filtrar historial">{[['all', 'Todos'], ['product', 'Productos'], ['package', 'Bultos']].map(([value, label]) => <button key={value} type="button" className={historyFilter === value ? primaryButton : secondaryButton} onClick={() => setHistoryFilter(value)}>{label}</button>)}</div></div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-left text-sm">
                        <thead><tr className="border-b dark:border-neutral-700"><th className="p-2">Fecha</th><th className="p-2">Envío</th><th className="p-2">Tipo</th><th className="p-2">Archivo</th><th className="p-2">Cantidad</th><th className="p-2">Impresora</th><th className="p-2">Estado</th><th className="p-2">Acciones</th></tr></thead>
                        <tbody>{filteredHistory.map((item) => <tr key={item.id} className="border-b align-top dark:border-neutral-800"><td className="whitespace-nowrap p-2">{formatDate(item.printed_at || item.created_at)}</td><td className="p-2">{item.shipment_id || '—'}</td><td className="p-2">{labelTypePresentation(item.type).name.replace('Etiquetas de ', '')}</td><td className="max-w-56 break-all p-2">{item.original_filename}</td><td className="p-2">{historyQuantity(item)}</td><td className="p-2">{item.printer_name || '—'}</td><td className="p-2">{historyStatus(item.status)}</td><td className="p-2 text-slate-500">Cargar nuevamente el TXT para reimprimir.</td></tr>)}</tbody>
                    </table>
                    {!filteredHistory.length && <p className="p-4 text-center text-slate-500">No hay registros para este filtro.</p>}
                </div>
            </section>
        </div>
    </AppShell>
}
