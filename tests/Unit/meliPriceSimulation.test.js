import assert from 'node:assert/strict'
import test from 'node:test'
import { canContinueWithSimulation, initialSimulationPrice, priceInCents, shippingPresentation, simulationMatchesDraft, simulationResultPresentation } from '../../resources/js/lib/meliPriceSimulation.js'

test('la apertura usa el precio actual o el último precio confirmado localmente', () => {
    const item = { id: 7, current_price: '200.00' }

    assert.equal(initialSimulationPrice(item), '200.00')
    assert.equal(initialSimulationPrice(item, { 7: 220 }), 220)
    assert.equal(initialSimulationPrice({ id: 8, current_price: null }), '')
})

test('cambiar el draft conserva visible el resultado anterior y lo marca desactualizado', () => {
    assert.deepEqual(simulationResultPresentation(200, 200, true), { visible: true, stale: false })
    assert.deepEqual(simulationResultPresentation(220, 200, true), { visible: true, stale: true })
    assert.deepEqual(simulationResultPresentation(220, null, false), { visible: false, stale: false })
})

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

test('después de editar exige recalcular y vuelve a habilitar al simular el nuevo precio', () => {
    assert.equal(canContinueWithSimulation(220, 200, { hasResult: true }), false)
    assert.equal(canContinueWithSimulation(220, 220, { hasResult: true }), true)
})

test('presenta envío disponible, cero y no disponible sin confundir null con cero', () => {
    assert.deepEqual(shippingPresentation({ charges: { shipping: { available: true, cost: 79, currency_id: 'MXN' } } }), { available: true, cost: 79, currency: 'MXN', warning: null })
    assert.equal(shippingPresentation({ charges: { shipping: { available: true, cost: 0 } } }).cost, 0)
    const unavailable = shippingPresentation({ charges: { shipping: { available: false, cost: null, error: 'No disponible' } } })
    assert.equal(unavailable.cost, null)
    assert.equal(unavailable.warning, 'No disponible')
})
