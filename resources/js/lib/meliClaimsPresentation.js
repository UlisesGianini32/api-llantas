const labels = {
    status: { open: 'Abierto', opened: 'Abierto', closed: 'Cerrado', resolved: 'Resuelto' },
    stage: { claim: 'Reclamo', dispute: 'Mediación / disputa', recontact: 'Recontacto', stale: 'Sin actividad', mediation: 'Mediación / disputa' },
    type: { mediations: 'Mediación' },
    role: { respondent: 'Vendedor', seller: 'Vendedor', complainant: 'Comprador', mediator: 'Mercado Libre / Mediador' },
    action: {
        send_message_to_respondent: 'Enviar mensaje al vendedor',
        send_message_to_complainant: 'Enviar mensaje al comprador',
        send_message_to_mediator: 'Enviar mensaje al mediador',
        refund: 'Reembolsar',
        open_dispute: 'Abrir mediación/disputa',
        allow_return: 'Permitir devolución',
        allow_partial_refund: 'Ofrecer reembolso parcial',
        return_review_fail: 'Informar problema con la devolución',
        return_review_ok: 'Confirmar devolución correcta',
        return_review_unified_fail: 'Informar problema con revisión de devolución',
        return_review_unified_ok: 'Confirmar revisión de devolución',
    },
    resolution: { return_product: 'Devolver producto', return: 'Devolver producto', change_product: 'Cambiar producto', refund: 'Reembolso', partial_refund: 'Reembolso parcial' },
    historyAction: {
        open_claim: 'Reclamo abierto',
        claim_opened: 'Reclamo abierto',
        send_message: 'Mensaje enviado',
        send_message_to_respondent: 'Mensaje enviado al vendedor',
        send_message_to_complainant: 'Mensaje enviado al comprador',
        send_message_to_mediator: 'Mensaje enviado al mediador',
        open_dispute: 'Mediación abierta',
        refund: 'Reembolso',
        allow_return: 'Devolución permitida',
    },
}

export const readable = (value) => {
    if (value === null || value === undefined || value === '') return 'Sin información'
    return String(value).replaceAll('_', ' ').replace(/\b\p{L}/gu, letter => letter.toUpperCase())
}

const translate = (group, value) => labels[group][value] || readable(value)

export const claimStatus = value => translate('status', value)
export const claimStage = value => translate('stage', value)
export const claimType = value => translate('type', value)
export const playerRole = value => translate('role', value)
export const availableAction = value => translate('action', value)
export const expectedResolution = value => translate('resolution', value)
export const historyAction = value => translate('historyAction', value)

export const accountName = account => {
    if (!account) return 'Sin cuenta'
    return account.nickname || `${account.is_default ? 'Cuenta principal' : 'Cuenta secundaria'} · ${account.meli_user_id}`
}

export const accountSecondary = account => account?.nickname ? account.meli_user_id : null

export const listData = value => Array.isArray(value) ? value : value?.data || value?.results || []

export const actionName = value => typeof value === 'string' ? value : value?.action_name || value?.action || value?.name || value?.type
export const resolutionName = value => typeof value === 'string' ? value : value?.type || value?.expected_resolution || value?.resolution || value?.name
