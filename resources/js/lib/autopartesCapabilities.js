/**
 * Canonical capability identifiers returned by App\Services\Autopartes\BranchCatalogAccess::capabilities().
 * UI checks are presentation-only and must never replace backend authorization checks.
 */
export const AUTOPARTES_CAPABILITIES = Object.freeze({
    CATALOG_VIEW: 'automotive.catalog.view',
    STOCK_VIEW: 'inventory.stock.view',
    SOURCE_REVIEW: 'automotive.sources.review',
    IMPORT: 'automotive.imports.manage',
    RECEIPT_DRAFT: 'automotive.receipts.draft',
    RECEIPT_CONFIRM: 'automotive.receipts.confirm',
    MOVEMENTS_VIEW: 'automotive.movements.view',
    ADJUSTMENT_CREATE: 'automotive.adjustments.create',
    ADJUSTMENT_APPROVE: 'automotive.adjustments.approve',
    IDENTITY_RESOLVE: 'automotive.identities.resolve',
    POS_VIEW: 'automotive.pos.view',
    POS_SELL: 'automotive.pos.sell',
    POS_SHIFT_MANAGE: 'automotive.pos.shifts.manage',
    SELLERS_MANAGE: 'automotive.sellers.manage',
    PRICES_VIEW: 'automotive.prices.view',
    PRICES_MANAGE: 'automotive.prices.manage',
    DISCOUNTS_VIEW: 'automotive.discounts.view',
    DISCOUNTS_MANAGE: 'automotive.discounts.manage',
    DISCOUNTS_APPROVE: 'automotive.discounts.approve',
    SALES_VIEW: 'automotive.sales.view',
    SALES_CANCEL: 'automotive.sales.cancel',
    SALES_RETURN: 'automotive.sales.return',
    REPORTS_VIEW: 'automotive.reports.view',
    REPORTS_EXPORT: 'automotive.reports.export',
})

export function hasCapability(capabilities, requiredCapability) {
    return (
        Array.isArray(capabilities) &&
        typeof requiredCapability === 'string' &&
        capabilities.includes(requiredCapability)
    )
}

export function hasAnyCapability(capabilities, ...requiredCapabilities) {
    if (!Array.isArray(capabilities)) {
        return false
    }

    return requiredCapabilities.flat().some(cap => typeof cap === 'string' && capabilities.includes(cap))
}

export function branchBaseUrl(branch) {
    return `/autopartes/${encodeURIComponent(branch.code)}`
}

export function getAutopartesNavItems(branch, capabilities) {
    const base = branchBaseUrl(branch)
    const C = AUTOPARTES_CAPABILITIES

    return [
        { label: `Punto de venta — ${branch.name}`, href: `${base}/pos`, show: hasAnyCapability(capabilities, C.POS_VIEW, C.POS_SELL) },
        { label: `Ventas — ${branch.name}`, href: `${base}/ventas`, show: hasAnyCapability(capabilities, C.SALES_VIEW, C.SALES_CANCEL, C.SALES_RETURN) },
        { label: `Precios de autopartes — ${branch.name}`, href: `${base}/precios`, show: hasAnyCapability(capabilities, C.PRICES_VIEW, C.PRICES_MANAGE) },
        { label: 'Descuentos por mayoreo', href: `${base}/descuentos`, show: hasAnyCapability(capabilities, C.DISCOUNTS_VIEW, C.DISCOUNTS_MANAGE, C.DISCOUNTS_APPROVE) },
        { label: `Vendedores — ${branch.name}`, href: `${base}/vendedores`, show: hasAnyCapability(capabilities, C.SELLERS_MANAGE, C.REPORTS_VIEW) },
        { label: `Reportes — ${branch.name}`, href: `${base}/reportes`, show: hasAnyCapability(capabilities, C.REPORTS_VIEW, C.REPORTS_EXPORT) },
        { label: `Inventario ${branch.name}`, href: `${base}/inventario`, show: hasCapability(capabilities, C.STOCK_VIEW) },
        { label: 'Entrada de autopartes', href: `${base}/entradas`, show: hasAnyCapability(capabilities, C.RECEIPT_DRAFT, C.RECEIPT_CONFIRM, C.STOCK_VIEW) },
        { label: 'Movimientos', href: `${base}/movimientos`, show: hasCapability(capabilities, C.MOVEMENTS_VIEW) },
        { label: 'Catálogo Excel', href: base, show: hasCapability(capabilities, C.CATALOG_VIEW) },
        { label: 'Importaciones', href: `${base}/importaciones`, show: hasCapability(capabilities, C.IMPORT) },
    ].filter(item => item.show)
}
