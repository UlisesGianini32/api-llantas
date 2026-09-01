import assert from 'node:assert/strict'
import test from 'node:test'
import { selectionScopeKey } from '../../resources/js/lib/meliPriceManagerSelection.js'

const base = {
    accountId: 1,
    brandId: 5,
    page: 2,
    filters: {
        search: 'shampoo',
        status: 'active',
        category_id: 'MLM438195',
        stock: 'in_stock',
        sync: 'recent',
        sort: 'title',
        direction: 'asc',
        per_page: 50,
    },
}

test('el alcance de selección cambia con cuenta, marca, página o filtros aplicados', () => {
    const current = selectionScopeKey(base)

    assert.notEqual(selectionScopeKey({ ...base, accountId: 2 }), current)
    assert.notEqual(selectionScopeKey({ ...base, brandId: 8 }), current)
    assert.notEqual(selectionScopeKey({ ...base, page: 3 }), current)
    assert.notEqual(selectionScopeKey({ ...base, filters: { ...base.filters, search: 'acondicionador' } }), current)
})

test('el mismo alcance produce una clave estable', () => {
    assert.equal(selectionScopeKey(base), selectionScopeKey({ ...base, filters: { ...base.filters } }))
})
