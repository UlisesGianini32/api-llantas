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
