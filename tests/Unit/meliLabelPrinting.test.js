import assert from 'node:assert/strict'
import test from 'node:test'
import {
    canStartLabelPrint,
    exclusiveLabelPrint,
    isQzConnected,
    labelPrintButtonText,
    labelPrintProgress,
    labelTypePresentation,
    preferredLabelPrinter,
    sendLabelBatch,
} from '../../resources/js/lib/meliLabelPrinting.js'

test('printer preference: exact, variant, manual; never selects unrelated default', () => {
    assert.equal(preferredLabelPrinter(['Zebra', '4BARCODE copia', '4BARCODE 4B-2054A']), '4BARCODE 4B-2054A')
    assert.equal(preferredLabelPrinter(['Zebra', '4B-2054A (Copiar 1)']), '4B-2054A (Copiar 1)')
    assert.equal(preferredLabelPrinter(['Office']), '')
})

test('remembered installed printer wins and a missing remembered printer is ignored', () => {
    assert.equal(preferredLabelPrinter(['Zebra', '4BARCODE 4B-2054A'], 'Zebra'), 'Zebra')
    assert.equal(preferredLabelPrinter(['4BARCODE 4B-2054A'], 'Missing'), '4BARCODE 4B-2054A')
})

test('QZ connection state is safe and reflects the websocket', () => {
    assert.equal(isQzConnected(undefined), false)
    assert.equal(isQzConnected({ websocket: { isActive: () => false } }), false)
    assert.equal(isQzConnected({ websocket: { isActive: () => true } }), true)
})

test('print action stays disabled without QZ, printer, or reprint confirmation', () => {
    const base = { batch: { previous_print: null }, printer: 'Zebra', qzConnected: true, busy: '', reprintConfirmed: false, completed: false }
    assert.equal(canStartLabelPrint(base), true)
    assert.equal(canStartLabelPrint({ ...base, qzConnected: false }), false)
    assert.equal(canStartLabelPrint({ ...base, printer: '' }), false)
    assert.equal(canStartLabelPrint({ ...base, busy: 'printing' }), false)
    assert.equal(canStartLabelPrint({ ...base, completed: true }), false)
    assert.equal(canStartLabelPrint({ ...base, batch: { previous_print: { id: 1 } } }), false)
    assert.equal(canStartLabelPrint({ ...base, batch: { previous_print: { id: 1 } }, reprintConfirmed: true }), true)
})

test('product and package batches have distinct presentation and quantity labels', () => {
    assert.deepEqual(labelTypePresentation('product', 2), { icon: '🏷️', name: 'Etiquetas de productos', noun: 'etiquetas' })
    assert.deepEqual(labelTypePresentation('package', 1), { icon: '📦', name: 'Etiquetas de bultos', noun: 'guía' })
    assert.equal(labelPrintButtonText('product', 164), 'IMPRIMIR 164 ETIQUETAS')
    assert.equal(labelPrintButtonText('package', 2), 'IMPRIMIR 2 GUÍAS')
})

test('progress reflects each confirmed label and only completes at the total', () => {
    assert.deepEqual(labelPrintProgress(['sent', 'pending', 'uncertain'], 3), { sent: 1, total: 3, complete: false, percentage: 33 })
    assert.deepEqual(labelPrintProgress(['sent', 'sent'], 2), { sent: 2, total: 2, complete: true, percentage: 100 })
})

test('product progress adds physical quantities per confirmed ZPL block', () => {
    const quantities = [4, 12, 12, 6, 6, 12, 6, 20, 20, 12, 12, 12, 12, 12, 6]
    const statuses = quantities.map(() => 'pending')

    statuses[0] = 'sent'
    assert.deepEqual(labelPrintProgress(statuses, quantities), { sent: 4, total: 164, complete: false, percentage: 2 })
    statuses[1] = 'sent'
    assert.deepEqual(labelPrintProgress(statuses, quantities), { sent: 16, total: 164, complete: false, percentage: 10 })
    statuses.fill('sent')
    assert.deepEqual(labelPrintProgress(statuses, quantities), { sent: 164, total: 164, complete: true, percentage: 100 })
})

test('package progress remains one physical guide per block', () => {
    assert.deepEqual(labelPrintProgress(['sent', 'pending'], [1, 1]), { sent: 1, total: 2, complete: false, percentage: 50 })
})

test('synchronous lock rejects double clicks and releases on failure', async () => {
    let release
    const first = exclusiveLabelPrint(() => new Promise((resolve) => { release = resolve }))
    assert.equal(await exclusiveLabelPrint(() => assert.fail('duplicate')), false)
    release()
    assert.equal(await first, true)
    await assert.rejects(exclusiveLabelPrint(() => { throw new Error('failed') }))
    assert.equal(await exclusiveLabelPrint(async () => {}), true)
})

test('sends one RAW copy sequentially, stops on uncertainty, retry skips sent labels', async () => {
    const calls = []
    const results = []
    const config = { printer: 'test' }
    const qz = {
        configs: { create: (printer, options) => {
            assert.equal(printer, 'test'); assert.deepEqual(options, { copies: 1 }); return config
        } },
        print: async (actual, data) => {
            assert.equal(actual, config)
            calls.push(data)
            if (calls.length === 2) throw new Error('connection lost')
        },
    }
    const labels = ['first', 'second', 'third']
    await assert.rejects(sendLabelBatch(qz, 'test', labels, [0, 1, 2], (i, status) => results.push([i, status])), /bloque 2 de 3/)
    assert.deepEqual(results, [[0, 'sent'], [1, 'uncertain']])
    assert.deepEqual(calls[0], [{ type: 'raw', format: 'command', flavor: 'base64', data: 'first' }])
    await sendLabelBatch(qz, 'test', labels, [1, 2], () => {})
    assert.deepEqual(calls.map((data) => data[0].data), ['first', 'second', 'second', 'third'])
})

test('QZ sends a product block with its embedded PQ once and never multiplies copies', async () => {
    const embeddedQuantity = Buffer.from('^XA^PQ12,0,1,Y^XZ').toString('base64')
    const calls = []
    const qz = {
        configs: { create: (printer, options) => {
            assert.equal(printer, 'Zebra')
            assert.deepEqual(options, { copies: 1 })
            return { printer }
        } },
        print: async (_config, data) => { calls.push(data) },
    }

    await sendLabelBatch(qz, 'Zebra', [embeddedQuantity], [0], () => {})

    assert.equal(calls.length, 1)
    assert.deepEqual(calls[0], [{ type: 'raw', format: 'command', flavor: 'base64', data: embeddedQuantity }])
})

test('sends exactly N labels in order and reports a completed batch', async () => {
    const calls = []
    const statuses = ['pending', 'pending', 'pending']
    const qz = {
        configs: { create: () => ({ copies: 1 }) },
        print: async (_config, data) => { calls.push(data[0].data) },
    }

    await sendLabelBatch(qz, 'printer', ['one', 'two', 'three'], [0, 1, 2], (index, status) => { statuses[index] = status })

    assert.deepEqual(calls, ['one', 'two', 'three'])
    assert.deepEqual(labelPrintProgress(statuses, 3), { sent: 3, total: 3, complete: true, percentage: 100 })
})
