import assert from 'node:assert/strict'
import test from 'node:test'

import { sidebarSectionsForRole } from '../../resources/js/lib/userAccessNavigation.js'

test('operations sees exactly the allowed sidebar modules without empty admin sections', () => {
    const sections = sidebarSectionsForRole('operations')

    assert.deepEqual(sections.map((section) => section.label), ['General', 'Mercado Libre', 'Operaciones'])
    assert.deepEqual(sections.flatMap((section) => section.items.map((item) => item.label)), [
        'Dashboard',
        'Preguntas de productos',
        'Mensajería posventa',
        'Reclamos',
        'Publicaciones Mercado Libre',
        'Inventario FULL',
        'AMS Pedidos',
        'AMS Procesar',
        'AMS Secundaria',
        'AMS Mañana',
    ])
    assert.equal(sections.some((section) => section.label === 'Inventario'), false)
    assert.equal(sections.some((section) => section.label === 'Sistema'), false)
})

test('admin retains every existing sidebar section and module', () => {
    const sections = sidebarSectionsForRole('admin')
    const labels = sections.flatMap((section) => section.items.map((item) => item.label))

    assert.deepEqual(sections.map((section) => section.label), ['General', 'Inventario', 'Mercado Libre', 'Operaciones', 'Sistema'])
    assert.ok(labels.includes('Meli Price Manager'))
    assert.ok(labels.includes('Marcas y alias'))
    assert.ok(labels.includes('Pendientes de clasificación'))
    assert.ok(labels.includes('Logs'))
})
