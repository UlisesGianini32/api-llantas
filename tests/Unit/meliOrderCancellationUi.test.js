import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const source = readFileSync(new URL('../../resources/js/Pages/Ams/PedidosProcesar_secondary.jsx', import.meta.url), 'utf8')

test('secondary orders expose guarded per-order cancellation UI', () => {
    assert.match(source, /Cancelar compra/)
    assert.match(source, /CANCELAR COMPRA/)
    assert.match(source, /pedido\.pack_id/)
    assert.match(source, /cancelOrder\.order\.order_id/)
    assert.match(source, /Motivo de cancelación/)
    assert.match(source, /type="checkbox"/)
    assert.match(source, /Confirmo que deseo cancelar esta compra/)
    assert.match(source, /afectar tus métricas\/reputación/)
    assert.match(source, /order\.cancelled/)
    assert.match(source, /\(pedido\.orders \|\| \[\]\)\.map/)
    assert.doesNotMatch(source, /name="reason"|type="text"[^>]*reason/)
    assert.match(source, /disabled=\{!cancelReason \|\| !cancelConfirmed/)
    assert.match(source, /cancelOrder\.order\.items/)
    assert.match(source, /cancelOrder\.order\.total_amount/)
    assert.doesNotMatch(source, /cancelOrder\.pedido\.items/)
    assert.doesNotMatch(source, /cancelOrder\.pedido\.total_pedido/)
})
