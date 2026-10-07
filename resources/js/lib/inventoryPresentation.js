const MOVEMENT_TYPE_LABELS = {
    INITIAL: 'Inventario inicial',
    RECEIPT: 'Entrada',
    TRANSFER: 'Mover (Transferencia)',
    TRANSFER_IN: 'Transferencia (Entrada)',
    TRANSFER_OUT: 'Transferencia (Salida)',
    RETURN: 'Devolución',
    ADJUSTMENT_IN: 'Ajuste de entrada',
    SALE: 'Venta',
    ADJUSTMENT_OUT: 'Ajuste de salida',
    DAMAGE: 'Merma / daño',
}

const RESERVATION_STATUS_LABELS = {
    ACTIVE: 'Activa',
    FULFILLED: 'Cumplida',
    RELEASED: 'Liberada',
    CANCELLED: 'Cancelada',
    EXPIRED: 'Expirada',
}

const RESERVATION_SOURCE_LABELS = {
    inventory_kit_reservation: 'Reserva de kit',
}

const dateTimeFormatter = new Intl.DateTimeFormat('es-MX', {
    timeZone: 'America/Hermosillo',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
})

export function formatDateTime(value) {
    if (value === null || value === undefined || value === '') return '—'

    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return '—'

    const parts = dateTimeFormatter.formatToParts(date).reduce((result, part) => {
        result[part.type] = part.value
        return result
    }, {})

    return `${parts.day}/${parts.month}/${parts.year} ${parts.hour}:${parts.minute}`
}

export function movementTypeLabel(value) {
    return MOVEMENT_TYPE_LABELS[value] || value || '—'
}

export function reservationStatusLabel(value) {
    return RESERVATION_STATUS_LABELS[value] || value || '—'
}

export function reservationSourceLabel({ source_type: sourceType, source_id: sourceId } = {}) {
    const hasSourceType = sourceType !== null && sourceType !== undefined && sourceType !== ''
    const hasSourceId = sourceId !== null && sourceId !== undefined && sourceId !== ''

    if (!hasSourceType && !hasSourceId) return 'Manual'

    const sourceLabel = RESERVATION_SOURCE_LABELS[sourceType] || sourceType
    if (hasSourceType && hasSourceId) return `${sourceLabel} · #${sourceId}`

    return hasSourceType ? sourceLabel : `#${sourceId}`
}
