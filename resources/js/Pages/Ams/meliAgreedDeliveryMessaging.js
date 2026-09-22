export const deliveryDetailsMessage = `Buen día, ¿nos podría proporcionar sus datos de envío?
- Nombre
- Teléfono
- Calle
- Número
- Código postal
- Colonia
- Ciudad
- Estado
- Referencia`

export const deliveryRequestLabels = {
    sent: 'Datos solicitados',
    pending_moderation: 'Pendiente de moderación',
    rejected: 'Rechazado por Mercado Libre',
    blocked: 'Conversación bloqueada',
    unavailable: 'Sin mensajes disponibles',
    error: 'Error de envío',
    uncertain: 'Envío sin confirmar',
    sending: 'Enviando...',
}

export function isDeliveryRequestRepeat(status) {
    return ['sent', 'pending_moderation', 'uncertain', 'sending'].includes(status)
}

export function deliveryRequestTone(status) {
    if (status === 'sent') return 'success'
    if (['pending_moderation', 'uncertain'].includes(status)) return 'warning'
    return 'error'
}

export function deliveryRequestButtonLabel(status, sending = false) {
    if (sending) return 'Enviando...'
    return isDeliveryRequestRepeat(status) ? 'Reenviar solicitud' : 'Solicitar datos de envío'
}
