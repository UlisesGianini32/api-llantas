import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { canContinueWithSimulation, canReviewProjection, currentReceivableForItem, initialSimulationPrice, listingTypeName, priceInCents, projectionChanges, projectionMatchesDraft, projectionResponseIsCurrent, shippingPresentation, simulationMatchesDraft, simulationResultPresentation } from '../../resources/js/lib/meliPriceSimulation.js'

const priceManagerSource = readFileSync(new URL('../../resources/js/Pages/MeliPriceManager/Index.jsx', import.meta.url), 'utf8')

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

test('un snapshot local inválido reemplaza la cifra inicial por pendiente', () => {
    const item = { id: 7, current_estimated_receivable: 113.90 }

    assert.equal(currentReceivableForItem(item), 113.90)
    assert.equal(currentReceivableForItem(item, { 7: { amount: 120, price: 200 } }, 200), 120)
    assert.equal(currentReceivableForItem(item, { 7: { amount: 120, price: 200 } }, 220), null)
    assert.equal(currentReceivableForItem(item, { 7: null }), null)
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

test('presenta los listing types soportados con sus nombres de negocio', () => {
    assert.equal(listingTypeName('gold_special'), 'Clásica')
    assert.equal(listingTypeName('gold_pro'), 'Premium')
    assert.equal(listingTypeName('gold_unknown'), null)
})

test('precio y listing type deben coincidir con la última proyección', () => {
    assert.equal(projectionMatchesDraft(198, 'gold_special', 198, 'gold_special'), true)
    assert.equal(projectionMatchesDraft(220, 'gold_special', 198, 'gold_special'), false)
    assert.equal(projectionMatchesDraft(198, 'gold_pro', 198, 'gold_special'), false)
    assert.equal(canReviewProjection(198, 'gold_pro', { proposed_price: 198, listing_type_id: 'gold_pro' }), true)
    assert.equal(canReviewProjection(198, 'gold_pro', { proposed_price: 198, listing_type_id: 'gold_special' }), false)
})

test('una respuesta vieja o de otros parámetros nunca queda vigente', () => {
    const result = { proposed_price: 198, listing_type_id: 'gold_special' }

    assert.equal(projectionResponseIsCurrent(2, 2, 198, 'gold_special', result), true)
    assert.equal(projectionResponseIsCurrent(1, 2, 198, 'gold_special', result), false)
    assert.equal(projectionResponseIsCurrent(2, 2, 220, 'gold_special', result), false)
    assert.equal(projectionResponseIsCurrent(2, 2, 198, 'gold_pro', result), false)
})

test('detecta precio, tipo, cambio combinado y no-op por separado', () => {
    assert.deepEqual(projectionChanges(198, 'gold_special', 220, 'gold_special'), {
        priceChanged: true,
        listingTypeChanged: false,
        combined: false,
        none: false,
    })
    assert.deepEqual(projectionChanges(198, 'gold_special', 198, 'gold_pro'), {
        priceChanged: false,
        listingTypeChanged: true,
        combined: false,
        none: false,
    })
    assert.equal(projectionChanges(198, 'gold_special', 220, 'gold_pro').combined, true)
    assert.equal(projectionChanges(198, 'gold_special', 198, 'gold_special').none, true)
})

test('la interfaz usa la terminología de Proyección de venta', () => {
    for (const expected of ['Proyección de venta', 'Calcular resultado', 'Revisar cambios', 'Confirmar cambios']) {
        assert.match(priceManagerSource, new RegExp(expected, 'i'))
    }
    for (const obsolete of ['Simulación de precio', 'Simulación de cargos', 'Calcular cargos', 'Continuar con cambio']) {
        assert.doesNotMatch(priceManagerSource, new RegExp(obsolete, 'i'))
    }
})
