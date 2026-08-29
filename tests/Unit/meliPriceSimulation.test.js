import assert from 'node:assert/strict'
import test from 'node:test'
import { canContinueWithSimulation, priceInCents, shippingPresentation, simulationMatchesDraft } from '../../resources/js/lib/meliPriceSimulation.js'

test('habilita continuar sólo para el precio de la última simulación', () => {
    assert.equal(simulationMatchesDraft('220', 220), true)
    assert.equal(simulationMatchesDraft('220.00', '220'), true)
    assert.equal(simulationMatchesDraft('250', 220), false)
})

test('la comparación monetaria es segura a centavos', () => {
    assert.equal(priceInCents('240.10'), 24010)
    assert.equal(simulationMatchesDraft('240.1', '240.10'), true)
    assert.equal(simulationMatchesDraft('', null), false)
    assert.equal(simulationMatchesDraft('0', 0), false)
    assert.equal(simulationMatchesDraft('precio inválido', 220), false)
})

test('un error o una petición en curso impiden continuar aunque el precio coincida', () => {
    assert.equal(canContinueWithSimulation(220, 220, { hasResult: true }), true)
    assert.equal(canContinueWithSimulation(220, 220, { hasResult: true, error: 'Error remoto' }), false)
    assert.equal(canContinueWithSimulation(220, 220, { hasResult: true, loading: true }), false)
    assert.equal(canContinueWithSimulation(220, 220, { hasResult: false }), false)
})

test('presenta envío disponible, cero y no disponible sin confundir null con cero', () => {
    assert.deepEqual(shippingPresentation({ charges: { shipping: { available: true, cost: 79, currency_id: 'MXN' } } }), { available: true, cost: 79, currency: 'MXN', warning: null })
    assert.equal(shippingPresentation({ charges: { shipping: { available: true, cost: 0 } } }).cost, 0)
    const unavailable = shippingPresentation({ charges: { shipping: { available: false, cost: null, error: 'No disponible' } } })
    assert.equal(unavailable.cost, null)
    assert.equal(unavailable.warning, 'No disponible')
})
