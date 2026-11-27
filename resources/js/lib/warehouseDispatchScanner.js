export const INDIVIDUAL_UNITS = ['UNIT', 'TUBE', 'BOTTLE', 'SACHET']
export const CONTAINER_UNITS = ['BOX', 'PACK']

export const UOM_LABELS = {
    UNIT: 'Pieza',
    TUBE: 'Tubo',
    BOTTLE: 'Botella',
    SACHET: 'Sobre',
    BOX: 'Caja',
    PACK: 'Paquete',
}

export const OPERATION_TYPE_LABELS = {
    INTERNAL_CONSUMPTION: 'Consumo interno',
    SALON_SALE: 'Venta al salón',
    TRANSFER: 'Traspaso de mercancía',
}

export const DISPATCH_STATUS_LABELS = {
    DRAFT: 'Borrador',
    CONFIRMED: 'Confirmada',
    PARTIALLY_RETURNED: 'Devuelta parcialmente',
    FULLY_RETURNED: 'Devuelta totalmente',
    CANCELLED: 'Cancelada / Anulada',
}

export const APPLY_STATUS_LABELS = {
    PENDING: 'Pendiente',
    APPLIED: 'Aplicada a inventario',
    SKIPPED_PROVISIONAL: 'Provisional (sin movimiento)',
    SKIPPED_LEDGER_DISABLED: 'Registrada sin descuento',
    SKIPPED_UNKNOWN_CONVERSION: 'Sin equivalencia física',
    SKIPPED_PRE_PHYSICAL_COUNT: 'Pre-conteo físico (omitida)',
    DISCREPANCY_INSUFFICIENT_STOCK: 'Discrepancia: stock insuficiente',
    NEEDS_RECONCILIATION: 'Requiere conciliación',
    CANCELLED_NO_MOVEMENT: 'Anulada sin movimiento',
}

export function isIndividualUnit(uom) {
    return INDIVIDUAL_UNITS.includes(String(uom || 'UNIT').toUpperCase())
}

export function computeNormalizedPhysicalQuantity(capturedQuantity, uom, conversionFactor) {
    const qty = Number.parseInt(capturedQuantity, 10)
    if (!Number.isFinite(qty) || qty <= 0) return null

    if (isIndividualUnit(uom)) {
        return qty
    }

    const factor = Number.parseInt(conversionFactor, 10)
    if (!Number.isFinite(factor) || factor < 2) {
        return null
    }

    return qty * factor
}

/**
 * Composite consolidation key (H05 + H09 + Section 5):
 * Considers product/provisional identity, origin location, unit of measure,
 * AND conversion factor so distinct locations (D3-7 vs D3-8) or distinct
 * box conversions never merge together.
 */
export function buildConsolidationKey(line) {
    const kind = line.kind === 'registered' ? 'registered' : 'provisional'
    const loc = line.inventory_location_id ? String(line.inventory_location_id) : 'none'
    const uom = String(line.captured_unit_of_measure || 'UNIT').toUpperCase()
    const factor = isIndividualUnit(uom)
        ? '1'
        : (line.unit_conversion_factor ? String(line.unit_conversion_factor) : 'unknown')

    if (kind === 'registered') {
        return `reg:${line.inventory_product_id}:loc:${loc}:uom:${uom}:conv:${factor}`
    }

    if (line.provisional_item_id) {
        return `prov:${line.provisional_item_id}:loc:${loc}:uom:${uom}:conv:${factor}`
    }

    const manualSig = [
        String(line.captured_barcode || '').trim().toLowerCase(),
        String(line.captured_sku || '').trim().toLowerCase(),
        String(line.captured_name || '').trim().toLowerCase(),
        String(line.captured_brand || '').trim().toLowerCase(),
        String(line.captured_tone || '').trim().toLowerCase(),
        String(line.captured_presentation || '').trim().toLowerCase(),
    ].join('|')

    return `manual:${manualSig}:loc:${loc}:uom:${uom}:conv:${factor}`
}

/**
 * Adds a line or increments quantity if and only if the exact composite
 * consolidation key (identity + location + uom + conversion_factor) matches.
 */
export function upsertDispatchLine(existingLines, incomingLine) {
    const incomingKey = buildConsolidationKey(incomingLine)
    const addQty = Math.max(1, Number.parseInt(incomingLine.captured_quantity || 1, 10))
    const nextLines = existingLines.map((line) => ({ ...line }))

    const idx = nextLines.findIndex((line) => buildConsolidationKey(line) === incomingKey)
    if (idx >= 0) {
        const current = nextLines[idx]
        const nextQty = Math.max(1, Number.parseInt(current.captured_quantity || 1, 10)) + addQty
        nextLines[idx] = {
            ...current,
            captured_quantity: nextQty,
            normalized_physical_quantity: computeNormalizedPhysicalQuantity(
                nextQty,
                current.captured_unit_of_measure,
                current.unit_conversion_factor,
            ),
        }
        return { lines: nextLines, mergedIndex: idx, added: false }
    }

    const uom = String(incomingLine.captured_unit_of_measure || 'UNIT').toUpperCase()
    const factor = isIndividualUnit(uom)
        ? 1
        : (incomingLine.unit_conversion_factor ? Number.parseInt(incomingLine.unit_conversion_factor, 10) : null)

    const created = {
        ...incomingLine,
        captured_quantity: addQty,
        captured_unit_of_measure: uom,
        unit_conversion_factor: factor,
        normalized_physical_quantity: computeNormalizedPhysicalQuantity(addQty, uom, factor),
    }

    nextLines.push(created)
    return { lines: nextLines, mergedIndex: nextLines.length - 1, added: true }
}

/**
 * Prevents repeated rapid camera frames from accidentally incrementing the same barcode.
 */
export function shouldIgnoreCameraDuplicateScan(lastScan, rawCode, nowMs, cooldownMs = 1500) {
    const normalized = String(rawCode || '').trim()
    if (!normalized) return true
    if (!lastScan || !lastScan.code) return false

    return lastScan.code === normalized && (nowMs - lastScan.at) < cooldownMs
}

/**
 * Computes totals without ever summing unconverted container lines into physical units (H09).
 */
export function summarizeLines(lines) {
    let totalPhysicalUnits = 0
    let unconvertedContainersCount = 0
    let unconvertedContainersQty = 0
    let provisionalCount = 0
    let registeredCount = 0

    for (const line of lines) {
        if (line.kind === 'registered') {
            registeredCount++
        } else {
            provisionalCount++
        }

        const norm = computeNormalizedPhysicalQuantity(
            line.captured_quantity,
            line.captured_unit_of_measure,
            line.unit_conversion_factor,
        )

        if (norm !== null) {
            totalPhysicalUnits += norm
        } else {
            unconvertedContainersCount++
            unconvertedContainersQty += Math.max(0, Number.parseInt(line.captured_quantity || 0, 10))
        }
    }

    return {
        totalLines: lines.length,
        registeredCount,
        provisionalCount,
        totalPhysicalUnits,
        unconvertedContainersCount,
        unconvertedContainersQty,
    }
}

export function generateIdempotencyKey() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID()
    }

    return 'wd-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12)
}

export function operatingModeMeta(mode) {
    switch (mode) {
        case 'READ_ONLY':
            return {
                label: 'Modo Solo Consulta',
                description: 'Las operaciones de escritura están temporalmente deshabilitadas.',
                badgeClass: 'bg-amber-100 text-amber-800 border-amber-200 dark:bg-amber-500/20 dark:text-amber-300 dark:border-amber-500/30',
            }
        case 'RECORD_ONLY':
            return {
                label: 'Registro sin Descuento de Inventario',
                description: 'Las salidas confirmadas se guardan en historial sin generar movimientos en el kardex ni tocar Mercado Libre.',
                badgeClass: 'bg-sky-100 text-sky-800 border-sky-200 dark:bg-sky-500/20 dark:text-sky-300 dark:border-sky-500/30',
            }
        case 'APPLY_LEDGER':
            return {
                label: 'Registro con Aplicación al Inventario',
                description: 'Las partidas registradas elegibles descuentan existencias del almacén (Mercado Libre permanece deshabilitado).',
                badgeClass: 'bg-emerald-100 text-emerald-800 border-emerald-200 dark:bg-emerald-500/20 dark:text-emerald-300 dark:border-emerald-500/30',
            }
        default:
            return {
                label: 'Módulo Deshabilitado',
                description: 'El módulo se encuentra apagado.',
                badgeClass: 'bg-slate-100 text-slate-700 border-slate-200',
            }
    }
}
