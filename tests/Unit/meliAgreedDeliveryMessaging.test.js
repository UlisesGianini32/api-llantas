import assert from 'node:assert/strict'
import test from 'node:test'
import fs from 'node:fs'
import { JSDOM } from 'jsdom'
import {
    deliveryDetailsMessage,
    deliveryRequestButtonLabel,
    deliveryRequestLabels,
    deliveryRequestTone,
} from '../../resources/js/Pages/Ams/meliAgreedDeliveryMessaging.js'

test('conserva la vista previa exacta y cambia la acción después del envío', () => {
    assert.match(deliveryDetailsMessage, /Buen día, ¿nos podría proporcionar sus datos de envío\?/)
    assert.match(deliveryDetailsMessage, /- Teléfono/)
    assert.match(deliveryDetailsMessage, /- Referencia/)
    assert.equal(deliveryRequestButtonLabel(null), 'Solicitar datos de envío')
    assert.equal(deliveryRequestButtonLabel('sent'), 'Reenviar solicitud')
    assert.equal(deliveryRequestLabels.sent, 'Datos solicitados')
})

test('clasifica moderación e incertidumbre como advertencias', () => {
    assert.equal(deliveryRequestTone('sent'), 'success')
    assert.equal(deliveryRequestTone('pending_moderation'), 'warning')
    assert.equal(deliveryRequestTone('uncertain'), 'warning')
    assert.equal(deliveryRequestTone('rejected'), 'error')
    assert.equal(deliveryRequestTone('blocked'), 'error')
    assert.equal(deliveryRequestTone('unavailable'), 'error')
})

test('la tarjeta incluye confirmación, estados y conversación sin renderizar errores técnicos', () => {
    const source = fs.readFileSync(new URL('../../resources/js/Pages/Ams/PedidosIndex.jsx', import.meta.url), 'utf8')
    const dom = new JSDOM('<div role="dialog"><button>Cancelar</button><button>Enviar mensaje</button></div>')

    assert.equal(dom.window.document.querySelector('[role="dialog"] button').textContent, 'Cancelar')
    assert.match(source, /deliveryRequestLabels/)
    assert.match(source, /Ver conversación/)
    assert.match(source, /Enviar mensaje/)
    assert.doesNotMatch(source, /technical_error/)
})
