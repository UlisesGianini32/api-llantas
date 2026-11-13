import assert from 'node:assert/strict'
import fs from 'node:fs'
import path from 'node:path'
import test from 'node:test'

import {
    AUTOPARTES_CAPABILITIES,
    getAutopartesNavigationSections,
    isAutopartesRouteActive,
    normalizeAutopartesPath,
} from '../../resources/js/lib/autopartesCapabilities.js'

const REPO_ROOT = path.resolve(import.meta.dirname, '..', '..')
const HMO_BRANCH = { code: 'HMO', name: 'HERMOSILLO' }

test('User ID 4 profile only gets authorized operational links and sees POS blocked with explanation', () => {
    const C = AUTOPARTES_CAPABILITIES
    const user4Caps = [C.CATALOG_VIEW, C.STOCK_VIEW, C.RECEIPT_DRAFT]
    const navMeta = {
        pos_policy: {
            pos_sales_enabled: false,
            fiscal_policy_validated: false,
            ready_for_real_sales: false,
        },
        routes: {
            catalog: '/autopartes/HMO',
            inventory: '/autopartes/HMO/inventario',
            receipts: '/autopartes/HMO/entradas',
            movements: '/autopartes/HMO/movimientos',
            pos: '/autopartes/HMO/pos',
        },
    }

    const { primary, admin } = getAutopartesNavigationSections(HMO_BRANCH, user4Caps, navMeta)

    assert.deepEqual(
        primary.map(i => i.key),
        ['catalog', 'inventory', 'receipts', 'pos'],
    )
    assert.equal(admin.length, 0)

    const catalog = primary.find(i => i.key === 'catalog')
    const inventory = primary.find(i => i.key === 'inventory')
    const receipts = primary.find(i => i.key === 'receipts')
    const pos = primary.find(i => i.key === 'pos')

    assert.equal(catalog.enabled, true)
    assert.equal(catalog.href, '/autopartes/HMO')
    assert.equal(inventory.enabled, true)
    assert.equal(inventory.href, '/autopartes/HMO/inventario')
    assert.equal(receipts.enabled, true)
    assert.equal(receipts.href, '/autopartes/HMO/entradas')
    assert.ok(receipts.note.includes('confirmación física restringida'))

    // POS must not be clickable for User ID 4 and must explain why
    assert.equal(pos.enabled, false)
    assert.equal(pos.href, null)
    assert.equal(pos.badge, 'Bloqueado')
    assert.ok(pos.disabledReason.includes('Sin permiso POS'))
    assert.ok(pos.disabledReason.includes('política comercial'))
})

test('POS becomes active in the same menu when user receives POS permission and commercial policy is enabled', () => {
    const C = AUTOPARTES_CAPABILITIES
    const cashierCaps = [C.CATALOG_VIEW, C.STOCK_VIEW, C.POS_VIEW, C.POS_SELL]
    const activePolicyNav = {
        pos_policy: {
            pos_sales_enabled: true,
            fiscal_policy_validated: true,
            ready_for_real_sales: true,
        },
        routes: {
            catalog: '/autopartes/HMO',
            inventory: '/autopartes/HMO/inventario',
            receipts: '/autopartes/HMO/entradas',
            pos: '/autopartes/HMO/pos',
        },
    }

    const { primary } = getAutopartesNavigationSections(HMO_BRANCH, cashierCaps, activePolicyNav)
    const pos = primary.find(i => i.key === 'pos')

    assert.equal(pos.enabled, true)
    assert.equal(pos.href, '/autopartes/HMO/pos')
    assert.equal(pos.badge, null)
    assert.equal(pos.disabledReason, null)
})

test('Unauthorized user with zero capabilities receives empty drawer sections', () => {
    const sections = getAutopartesNavigationSections(HMO_BRANCH, [], null)
    assert.deepEqual(sections, { primary: [], admin: [] })
})

test('Admin user sees all operational and commercial/administrative sections under HMO', () => {
    const adminCaps = Object.values(AUTOPARTES_CAPABILITIES)
    const { primary, admin } = getAutopartesNavigationSections(HMO_BRANCH, adminCaps, null)

    assert.deepEqual(
        primary.map(i => i.key),
        ['catalog', 'inventory', 'receipts', 'pos', 'movements'],
    )
    assert.deepEqual(
        admin.map(i => i.key),
        ['sales', 'prices', 'discounts', 'sellers', 'reports', 'imports'],
    )
    assert.ok(primary.every(i => i.href.startsWith('/autopartes/HMO')))
    assert.ok(admin.every(i => i.href.startsWith('/autopartes/HMO')))
})

test('Active route detection highlights current section including detail subroutes and query strings', () => {
    const catalog = { key: 'catalog', href: '/autopartes/HMO', enabled: true }
    const inventory = { key: 'inventory', href: '/autopartes/HMO/inventario', enabled: true }
    const receipts = { key: 'receipts', href: '/autopartes/HMO/entradas', enabled: true }
    const pos = { key: 'pos', href: null, enabled: false }

    assert.equal(normalizeAutopartesPath('/autopartes/HMO/?page=2'), '/autopartes/HMO')
    assert.equal(isAutopartesRouteActive('/autopartes/HMO?search=balata', catalog), true)
    assert.equal(isAutopartesRouteActive('/autopartes/HMO/registros/55', catalog), true)
    assert.equal(isAutopartesRouteActive('/autopartes/HMO/inventario', catalog), false)
    assert.equal(isAutopartesRouteActive('/autopartes/HMO/inventario?stock_filter=positive', inventory), true)
    assert.equal(isAutopartesRouteActive('/autopartes/HMO/entradas/1', receipts), true)
    assert.equal(isAutopartesRouteActive('/autopartes/HMO/pos', pos), false)
})

test('AutopartesLayout provides hamburger button, drawer navigation, accessibility, and mobile safety', () => {
    const layoutPath = path.join(REPO_ROOT, 'resources', 'js', 'Components', 'Autopartes', 'AutopartesLayout.jsx')
    const wrapperPath = path.join(REPO_ROOT, 'resources', 'js', 'Pages', 'Autopartes', 'Layout.jsx')

    const source = fs.readFileSync(layoutPath, 'utf8')
    const wrapper = fs.readFileSync(wrapperPath, 'utf8')

    // Re-exported cleanly by Pages/Autopartes/Layout.jsx
    assert.ok(wrapper.includes("import AutopartesLayout from '@/Components/Autopartes/AutopartesLayout'"))
    assert.ok(wrapper.includes('export default AutopartesLayout'))

    // Required header elements
    assert.ok(source.includes('HamburgerIcon'))
    assert.ok(source.includes('Sucursal {branchCode}'))
    assert.ok(source.includes('Autopartes ${branchDisplayName}'))
    assert.ok(source.includes("auth?.user?.name"))
    assert.ok(source.includes('href="/logout"'))
    assert.ok(source.includes('Cerrar sesión'))

    // Accessibility, Escape, backdrop click, close on navigation, mobile width & zero horizontal overflow
    assert.ok(source.includes('aria-expanded={isDrawerOpen}'))
    assert.ok(source.includes('aria-controls={drawerId}'))
    assert.ok(source.includes('role="dialog"'))
    assert.ok(source.includes('aria-modal="true"'))
    assert.ok(source.includes('aria-current={active ? \'page\' : undefined}'))
    assert.ok(source.includes('aria-disabled="true"'))
    assert.ok(source.includes("if (e.key === 'Escape')"))
    assert.ok(source.includes('onClick={() => closeDrawer(false)}'))
    assert.ok(source.includes('w-[min(20.5rem,88vw)]'))
    assert.ok(source.includes('overflow-x-clip'))
})
