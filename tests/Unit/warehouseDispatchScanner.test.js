import assert from 'node:assert/strict'
import test from 'node:test'

import {
    buildConsolidationKey,
    computeNormalizedPhysicalQuantity,
    operatingModeMeta,
    shouldIgnoreCameraDuplicateScan,
    summarizeLines,
    upsertDispatchLine,
} from '../../resources/js/lib/warehouseDispatchScanner.js'

test('same product from different locations (D3-7 vs D3-8) never merges on repeated scans', () => {
    let lines = []
    let res = upsertDispatchLine(lines, {
        kind: 'registered',
        inventory_product_id: 42,
        inventory_location_id: 342,
        captured_quantity: 4,
        captured_unit_of_measure: 'TUBE',
    })
    lines = res.lines

    res = upsertDispatchLine(lines, {
        kind: 'registered',
        inventory_product_id: 42,
        inventory_location_id: 343,
        captured_quantity: 3,
        captured_unit_of_measure: 'TUBE',
    })
    lines = res.lines

    assert.equal(lines.length, 2)
    assert.equal(lines[0].captured_quantity, 4)
    assert.equal(lines[1].captured_quantity, 3)

    // Scanning same product + same location D3-7 increments existing line
    res = upsertDispatchLine(lines, {
        kind: 'registered',
        inventory_product_id: 42,
        inventory_location_id: 342,
        captured_quantity: 1,
        captured_unit_of_measure: 'TUBE',
    })
    assert.equal(res.lines.length, 2)
    assert.equal(res.lines[0].captured_quantity, 5)
    assert.equal(res.lines[1].captured_quantity, 3)
})

test('provisional container lines with different conversion factors never merge', () => {
    let lines = []
    lines = upsertDispatchLine(lines, {
        kind: 'provisional',
        provisional_item_id: 10,
        inventory_location_id: null,
        captured_quantity: 1,
        captured_unit_of_measure: 'BOX',
        unit_conversion_factor: 12,
    }).lines

    lines = upsertDispatchLine(lines, {
        kind: 'provisional',
        provisional_item_id: 10,
        inventory_location_id: null,
        captured_quantity: 1,
        captured_unit_of_measure: 'BOX',
        unit_conversion_factor: 24,
    }).lines

    lines = upsertDispatchLine(lines, {
        kind: 'provisional',
        provisional_item_id: 10,
        inventory_location_id: null,
        captured_quantity: 2,
        captured_unit_of_measure: 'BOX',
        unit_conversion_factor: null,
    }).lines

    assert.equal(lines.length, 3)
    assert.notEqual(buildConsolidationKey(lines[0]), buildConsolidationKey(lines[1]))
    assert.notEqual(buildConsolidationKey(lines[0]), buildConsolidationKey(lines[2]))

    const summary = summarizeLines(lines)
    // 1*12 + 1*24 = 36 physical units; the 2 unconverted boxes are NEVER mixed into physical units!
    assert.equal(summary.totalPhysicalUnits, 36)
    assert.equal(summary.unconvertedContainersCount, 1)
    assert.equal(summary.unconvertedContainersQty, 2)
})

test('camera duplicate scan cooldown blocks accidental rapid increments of same barcode', () => {
    const last = { code: '7702045538977', at: 10000 }
    assert.equal(shouldIgnoreCameraDuplicateScan(last, '7702045538977', 10800, 1500), true)
    assert.equal(shouldIgnoreCameraDuplicateScan(last, '7702045538977', 11600, 1500), false)
    assert.equal(shouldIgnoreCameraDuplicateScan(last, '7702045552393', 10200, 1500), false)
})

test('operatingModeMeta and normalized quantity behave deterministically', () => {
    assert.equal(computeNormalizedPhysicalQuantity(3, 'UNIT', null), 3)
    assert.equal(computeNormalizedPhysicalQuantity(2, 'BOX', null), null)
    assert.equal(computeNormalizedPhysicalQuantity(2, 'BOX', 12), 24)
    assert.equal(operatingModeMeta('RECORD_ONLY').label, 'Registro sin Descuento de Inventario')
    assert.equal(operatingModeMeta('READ_ONLY').label, 'Modo Solo Consulta')
    assert.equal(operatingModeMeta('APPLY_LEDGER').label, 'Registro con Aplicación al Inventario')
})
