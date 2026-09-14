export const DEFAULT_LABEL_PRINTER = '4BARCODE 4B-2054A'
export const LABEL_PRINTER_STORAGE_KEY = 'meli_label_printer_name'

export function preferredLabelPrinter(printers, remembered = '') {
    return printers.find((name) => name === remembered)
        || printers.find((name) => name.toUpperCase() === DEFAULT_LABEL_PRINTER)
        || printers.find((name) => /4BARCODE|4B-2054A/i.test(name))
        || ''
}

export function isQzConnected(qz) {
    return Boolean(qz?.websocket?.isActive?.())
}

export function labelTypePresentation(type, count = 0) {
    if (type === 'package') {
        return { icon: '📦', name: 'Etiquetas de bultos', noun: count === 1 ? 'guía' : 'guías' }
    }

    return { icon: '🏷️', name: 'Etiquetas de productos', noun: count === 1 ? 'etiqueta' : 'etiquetas' }
}

export function labelPrintButtonText(type, physicalCount) {
    return `IMPRIMIR ${physicalCount} ${labelTypePresentation(type, physicalCount).noun.toUpperCase()}`
}

export function labelPrintProgress(results, quantitiesOrTotal) {
    const quantities = Array.isArray(quantitiesOrTotal)
        ? quantitiesOrTotal
        : results.map(() => 1)
    const total = Array.isArray(quantitiesOrTotal)
        ? quantities.reduce((sum, quantity) => sum + quantity, 0)
        : quantitiesOrTotal
    const sent = results.reduce(
        (sum, status, index) => sum + (status === 'sent' ? (quantities[index] || 0) : 0),
        0
    )

    return {
        sent,
        total,
        complete: total > 0 && sent === total,
        percentage: total > 0 ? Math.round((sent / total) * 100) : 0,
    }
}

export function canStartLabelPrint({ batch, printer, qzConnected, busy, reprintConfirmed, completed }) {
    return Boolean(
        batch
        && printer
        && qzConnected
        && !busy
        && !completed
        && (!batch.previous_print || reprintConfirmed)
    )
}

let running = false

// Shared across page mounts; acquire synchronously before the first await.
export async function exclusiveLabelPrint(work) {
    if (running) return false
    running = true
    try {
        await work()
        return true
    } finally {
        running = false
    }
}

export async function sendLabelBatch(qz, printer, labels, indices, onResult) {
    const config = qz.configs.create(printer, { copies: 1 })
    for (const index of indices) {
        try {
            await qz.print(config, [{ type: 'raw', format: 'command', flavor: 'base64', data: labels[index] }])
            onResult(index, 'sent')
        } catch (error) {
            onResult(index, 'uncertain')
            // A rejected promise can happen after the spooler accepted the job.
            throw new Error(`Falló el bloque ${index + 1} de ${labels.length}. El estado de este bloque es incierto: QZ puede haber entregado el trabajo al spooler antes del error. Verifica físicamente antes de reintentar. ${error?.message || String(error)}`)
        }
    }
}
