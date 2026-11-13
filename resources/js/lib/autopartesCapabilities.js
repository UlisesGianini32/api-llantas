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

export function normalizeAutopartesPath(url) {
    if (!url || typeof url !== 'string') {
        return ''
    }
    const clean = url.split('#')[0].split('?')[0].replace(/\/+$/, '')
    return clean || '/'
}

export function isAutopartesRouteActive(currentUrl, item) {
    if (!item || !item.enabled || !item.href) {
        return false
    }
    const currentPath = normalizeAutopartesPath(currentUrl)
    const targetPath = normalizeAutopartesPath(item.href)
    if (!currentPath || !targetPath) {
        return false
    }
    if (item.key === 'catalog') {
        return currentPath === targetPath || currentPath.startsWith(`${targetPath}/registros`)
    }
    return currentPath === targetPath || currentPath.startsWith(`${targetPath}/`)
}

export function getAutopartesNavigationSections(branch, capabilities, autopartesNav = null) {
    if (!branch?.code || !Array.isArray(capabilities) || capabilities.length === 0) {
        return { primary: [], admin: [] }
    }

    const base = branchBaseUrl(branch)
    const C = AUTOPARTES_CAPABILITIES
    const routes = autopartesNav?.routes || {}
    const posPolicy = autopartesNav?.pos_policy || null

    const branchLabel = branch.name && branch.name.toUpperCase() !== 'HERMOSILLO'
        ? branch.name
        : 'Hermosillo'

    const hasPosPermission = hasAnyCapability(capabilities, C.POS_VIEW, C.POS_SELL)
    const posPolicyReady = posPolicy
        ? Boolean(posPolicy.pos_sales_enabled && posPolicy.fiscal_policy_validated)
        : null

    const canDraftReceipt = hasCapability(capabilities, C.RECEIPT_DRAFT)
    const canConfirmReceipt = hasCapability(capabilities, C.RECEIPT_CONFIRM)

    const primary = [
        {
            key: 'catalog',
            label: 'Catálogo de autopartes',
            href: routes.catalog || base,
            enabled: true,
            show: hasCapability(capabilities, C.CATALOG_VIEW),
        },
        {
            key: 'inventory',
            label: `Inventario ${branchLabel}`,
            href: routes.inventory || `${base}/inventario`,
            enabled: true,
            show: hasCapability(capabilities, C.STOCK_VIEW),
        },
        {
            key: 'receipts',
            label: 'Entrada de autopartes',
            href: routes.receipts || `${base}/entradas`,
            enabled: true,
            note: canDraftReceipt && !canConfirmReceipt
                ? 'Solo borradores · confirmación física restringida'
                : null,
            show: hasAnyCapability(capabilities, C.RECEIPT_DRAFT, C.RECEIPT_CONFIRM, C.STOCK_VIEW),
        },
        {
            key: 'pos',
            label: 'Punto de venta',
            href: hasPosPermission ? (routes.pos || `${base}/pos`) : null,
            enabled: hasPosPermission,
            badge: hasPosPermission
                ? (posPolicyReady === false ? 'Ventas bloqueadas' : null)
                : 'Bloqueado',
            note: hasPosPermission && posPolicyReady === false
                ? 'Solo consulta de turnos · ventas deshabilitadas por política'
                : null,
            disabledReason: !hasPosPermission
                ? (posPolicyReady === false
                    ? 'Sin permiso POS y ventas POS deshabilitadas por política comercial.'
                    : 'No cuentas con permiso de Punto de venta en esta sucursal.')
                : null,
            show: true,
        },
        {
            key: 'movements',
            label: 'Historial de movimientos',
            href: routes.movements || `${base}/movimientos`,
            enabled: true,
            show: hasCapability(capabilities, C.MOVEMENTS_VIEW),
        },
    ].filter(item => item.show)

    const admin = [
        {
            key: 'sales',
            label: 'Historial de ventas',
            href: routes.sales || `${base}/ventas`,
            enabled: true,
            show: hasAnyCapability(capabilities, C.SALES_VIEW, C.SALES_CANCEL, C.SALES_RETURN),
        },
        {
            key: 'prices',
            label: 'Precios MXN',
            href: routes.prices || `${base}/precios`,
            enabled: true,
            show: hasAnyCapability(capabilities, C.PRICES_VIEW, C.PRICES_MANAGE),
        },
        {
            key: 'discounts',
            label: 'Descuentos por mayoreo',
            href: routes.discounts || `${base}/descuentos`,
            enabled: true,
            show: hasAnyCapability(capabilities, C.DISCOUNTS_VIEW, C.DISCOUNTS_MANAGE, C.DISCOUNTS_APPROVE),
        },
        {
            key: 'sellers',
            label: 'Vendedores',
            href: routes.sellers || `${base}/vendedores`,
            enabled: true,
            show: hasAnyCapability(capabilities, C.SELLERS_MANAGE, C.REPORTS_VIEW),
        },
        {
            key: 'reports',
            label: 'Reportes',
            href: routes.reports || `${base}/reportes`,
            enabled: true,
            show: hasAnyCapability(capabilities, C.REPORTS_VIEW, C.REPORTS_EXPORT),
        },
        {
            key: 'imports',
            label: 'Importaciones Excel',
            href: routes.imports || `${base}/importaciones`,
            enabled: true,
            show: hasCapability(capabilities, C.IMPORT),
        },
    ].filter(item => item.show)

    return { primary, admin }
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
