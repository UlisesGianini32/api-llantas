export function priceInCents(value) {
    const numeric = Number(value)

    return Number.isFinite(numeric) && numeric > 0 ? Math.round(numeric * 100) : null
}

export function simulationMatchesDraft(draftPrice, simulatedPrice) {
    const draft = priceInCents(draftPrice)
    const simulated = priceInCents(simulatedPrice)

    return draft !== null && simulated !== null && draft === simulated
}

export function canContinueWithSimulation(draftPrice, simulatedPrice, { hasResult, error = '', loading = false, updating = false }) {
    return Boolean(hasResult) && !error && !loading && !updating && simulationMatchesDraft(draftPrice, simulatedPrice)
}

export function shippingPresentation(result, fallbackCurrency = 'MXN') {
    const shipping = result?.charges?.shipping || {}
    const available = shipping.available === true

    return {
        available,
        cost: available ? (shipping.cost ?? shipping.seller_cost) : null,
        currency: shipping.currency_id || fallbackCurrency,
        warning: available ? null : (shipping.error || 'Costo de envío no disponible para esta simulación.'),
    }
}
