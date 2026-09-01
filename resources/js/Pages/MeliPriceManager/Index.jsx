import AppShell from '@/Components/layout/AppShell'
import { selectionScopeKey } from '@/lib/meliPriceManagerSelection'
import { canContinueWithSimulation, currentReceivableForItem, initialSimulationPrice, shippingPresentation, simulationMatchesDraft, simulationResultPresentation } from '@/lib/meliPriceSimulation'
import { Head, Link, router } from '@inertiajs/react'
import { useEffect, useMemo, useRef, useState } from 'react'

const fieldClass =
    'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white'
const secondaryButton =
    'rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-40 dark:border-neutral-700 dark:text-slate-200 dark:hover:bg-neutral-800'
const primaryButton =
    'rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-40'

function number(value) {
    return new Intl.NumberFormat('es-MX').format(Number(value || 0))
}

function money(value, currency = 'MXN') {
    if (value === null || value === undefined) return '—'
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: currency || 'MXN' }).format(Number(value))
}

function dateTime(value) {
    if (!value) return 'Nunca'
    return new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
}

async function simulatePrice(itemId, price) {
    const response = await fetch(`/meli-price-manager/items/${itemId}/simulate-price`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ price }),
    })
    const body = await response.json().catch(() => ({}))

    if (!response.ok) {
        const validation = body?.errors ? Object.values(body.errors).flat().join('\n') : ''
        throw new Error(validation || body?.message || `Error HTTP ${response.status}`)
    }

    return body.data
}

async function updatePrice(itemId, simulationToken, price) {
    const response = await fetch(`/meli-price-manager/items/${itemId}/price`, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ simulation_token: simulationToken, price }),
    })
    const body = await response.json().catch(() => ({}))

    if (!response.ok) {
        const validation = body?.errors ? Object.values(body.errors).flat().join('\n') : ''
        const requestError = new Error(validation || body?.message || `Error HTTP ${response.status}`)
        requestError.code = body?.code
        throw requestError
    }

    return body.data
}

function ChargeRow({ label, value, currency, negative = false, detail = null }) {
    return <div className="flex justify-between gap-4">
        <dt><span>{label}</span>{detail && <span className="mt-0.5 block text-xs text-slate-500">{detail}</span>}</dt>
        <dd className={`text-right font-bold ${negative ? 'text-rose-600' : ''}`}>{negative ? '-' : ''}{money(value, currency)}</dd>
    </div>
}

function ChargesBreakdown({ result, currency }) {
    const charges = result.charges || {}
    const saleFee = charges.sale_fee || {}
    const listingFee = charges.listing_fee || {}
    const shipping = charges.shipping || {}
    const taxes = charges.taxes || {}
    const shippingView = shippingPresentation(result, currency)
    const legacyMeliCharges = result.meli_charges_total ?? result.confirmed_charges_total
    const platformCharges = result.platform_charges_total
        ?? (legacyMeliCharges === null || legacyMeliCharges === undefined
            ? null
            : Math.max(0, Number(legacyMeliCharges) - Number(shippingView.cost ?? 0)))
    const historicalTaxes = taxes.source === 'historical_account_tax_rule'
    const other = Array.isArray(charges.other) ? charges.other : []
    const isPositive = (value) => Number.isFinite(Number(value)) && Number(value) > 0
    const otherDeductions = other.filter((charge) => charge.included_in_total === true && isPositive(charge.value))
    const shippingLabel = shipping.logistic_type === 'fulfillment' || result.logistic_type === 'fulfillment' ? 'Envío Full' : 'Envío'
    const percentagePoints = (value) => value === null || value === undefined ? '—' : `${number(value)}%`
    const fractionalPercentage = (value) => value === null || value === undefined ? '—' : `${number(Number(value) * 100)}%`

    return <div className="space-y-3">
        <div><p className="text-xs font-extrabold uppercase tracking-[0.15em] text-rose-600">Deducciones estimadas</p><p className="mt-1 text-xs text-slate-500">Cargos que se descuentan del precio de venta.</p></div>
        <dl className="space-y-2.5 text-sm">
            <ChargeRow label="Cargo por venta" value={saleFee.amount ?? result.sale_fee} currency={currency} negative />
            {isPositive(listingFee.amount) && <ChargeRow label="Costo de publicación" value={listingFee.amount} currency={currency} negative />}
            {isPositive(taxes.vat?.amount ?? taxes.iva) && <ChargeRow label="Retención de IVA" value={taxes.vat?.amount ?? taxes.iva} currency={currency} negative />}
            {isPositive(taxes.income_tax?.amount ?? taxes.isr) && <ChargeRow label="Retención de ISR" value={taxes.income_tax?.amount ?? taxes.isr} currency={currency} negative />}
            {otherDeductions.map((charge) => <ChargeRow key={charge.key} label={charge.label || charge.key} value={charge.value} currency={currency} negative />)}

            <div className="flex justify-between gap-4 border-t border-slate-200 pt-2.5 dark:border-neutral-700"><dt className="font-bold">Subtotal de cargos de plataforma</dt><dd className="font-bold text-rose-600">-{money(platformCharges, currency)}</dd></div>
            {shippingView.available && <ChargeRow label="Costo de envío estimado" value={shippingView.cost} currency={shippingView.currency} negative={Number(shippingView.cost) > 0} />}
            {isPositive(result.taxes_total ?? taxes.amount) && <ChargeRow label="Retenciones fiscales estimadas" value={result.taxes_total ?? taxes.amount} currency={currency} negative />}
            <div className="flex justify-between gap-4 border-t border-slate-200 pt-2.5 dark:border-neutral-700"><dt className="font-extrabold">Total de cargos estimados</dt><dd className="font-extrabold text-rose-600">-{money(result.total_charges, currency)}</dd></div>
        </dl>

        {taxes.available && <p className="text-[11px] font-medium text-slate-500">Fuente fiscal: {historicalTaxes ? (taxes.stale ? 'última regla histórica válida' : 'historial real de Mercado Libre') : 'perfil de la cuenta'}.</p>}
        {!taxes.available && <p className="text-[11px] font-medium text-amber-700 dark:text-amber-300">{taxes.message || 'Retenciones fiscales no disponibles en esta simulación.'}</p>}
        {!shippingView.available && <p className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">{shippingView.warning} Este monto todavía no descuenta el envío.</p>}

        <div className="rounded-2xl border-2 border-emerald-400 bg-emerald-50 p-4 text-center dark:bg-emerald-500/10"><p className="text-xs font-extrabold uppercase tracking-[0.14em] text-emerald-700 dark:text-emerald-300">{result.estimated_receivable_label || 'Recibes estimado'}</p><p className="mt-1 text-4xl font-black text-emerald-700 dark:text-emerald-300">{money(result.estimated_receivable, currency)}</p><p className="mt-1 text-xs font-semibold text-emerald-800 dark:text-emerald-200">{result.estimated_receivable_message || 'El monto final puede variar al procesarse la venta.'}</p></div>

        <details className="rounded-xl border border-slate-200 bg-slate-50 text-xs dark:border-neutral-700 dark:bg-neutral-950/50">
            <summary className="cursor-pointer select-none px-3 py-2.5 font-bold text-slate-600 dark:text-slate-300">Ver detalle</summary>
            <dl className="space-y-2 border-t border-slate-200 px-3 py-3 dark:border-neutral-700">
                <ChargeRow label="Precio de venta" value={result.proposed_price} currency={currency} />
                <div className="flex justify-between gap-4"><dt>Tipo de publicación</dt><dd className="font-bold">{result.listing_type_name || result.listing_type_id || 'No informado'}</dd></div>
                {saleFee.percentage !== null && saleFee.percentage !== undefined && <div className="flex justify-between gap-4"><dt>Comisión total</dt><dd className="font-bold">{percentagePoints(saleFee.percentage)}</dd></div>}
                {saleFee.meli_percentage !== null && saleFee.meli_percentage !== undefined && <div className="flex justify-between gap-4"><dt>Comisión de plataforma</dt><dd className="font-bold">{percentagePoints(saleFee.meli_percentage)}</dd></div>}
                {saleFee.fixed_fee !== null && saleFee.fixed_fee !== undefined && <ChargeRow label="Cargo fijo" value={saleFee.fixed_fee} currency={currency} />}
                {saleFee.financing_add_on_fee !== null && saleFee.financing_add_on_fee !== undefined && <div className="flex justify-between gap-4"><dt>Componente por cuotas</dt><dd className="font-bold">{percentagePoints(saleFee.financing_add_on_fee)}</dd></div>}
                {saleFee.gross_amount !== null && saleFee.gross_amount !== undefined && <ChargeRow label="Cargo bruto antes de descuentos" value={saleFee.gross_amount} currency={currency} detail="Informativo; no se suma nuevamente." />}
                {listingFee.fixed_fee !== null && listingFee.fixed_fee !== undefined && <ChargeRow label="Detalle fijo de publicación" value={listingFee.fixed_fee} currency={currency} />}
                {listingFee.gross_amount !== null && listingFee.gross_amount !== undefined && <ChargeRow label="Costo bruto de publicación" value={listingFee.gross_amount} currency={currency} detail="Informativo; no se suma nuevamente." />}
                {shipping.promoted_amount !== null && shipping.promoted_amount !== undefined && <ChargeRow label="Monto promovido informativo" value={shipping.promoted_amount} currency={shipping.currency_id || currency} />}
                {shipping.discount_rate !== null && shipping.discount_rate !== undefined && <div className="flex justify-between gap-4"><dt>Descuento Mercado Libre</dt><dd className="font-bold">{fractionalPercentage(shipping.discount_rate)}</dd></div>}
                {shipping.discount_amount !== null && shipping.discount_amount !== undefined && <ChargeRow label="Descuento informado" value={shipping.discount_amount} currency={shipping.currency_id || currency} />}
                {shipping.billable_weight !== null && shipping.billable_weight !== undefined && <div className="flex justify-between gap-4"><dt>Peso facturable</dt><dd className="font-bold">{number(shipping.billable_weight)} g</dd></div>}
                <div className="flex justify-between gap-4"><dt>Tipo de logística</dt><dd className="font-bold">{shipping.logistic_type || result.logistic_type || shippingLabel}</dd></div>
                <div className="flex justify-between gap-4"><dt>Modo de envío</dt><dd className="font-bold">{shipping.mode || result.shipping_mode || 'No informado'}</dd></div>
                <div className="flex justify-between gap-4"><dt>Envío gratis</dt><dd className="font-bold">{shipping.free_shipping ? 'Sí' : 'No'}</dd></div>
                <div className="flex justify-between gap-4"><dt>Moneda del envío</dt><dd className="font-bold">{shipping.currency_id || 'No informada'}</dd></div>
                {taxes.available && <>
                    <ChargeRow label="Base gravable (sin IVA)" value={taxes.taxable_base} currency={currency} />
                    <div className="flex justify-between gap-4"><dt>IVA incluido en precio</dt><dd className="font-bold">{percentagePoints(taxes.vat?.included_rate)}</dd></div>
                    <div className="flex justify-between gap-4"><dt>Tasa de retención IVA</dt><dd className="font-bold">{percentagePoints(taxes.vat?.withholding_rate)}</dd></div>
                    <div className="flex justify-between gap-4"><dt>Tasa de retención ISR</dt><dd className="font-bold">{percentagePoints(taxes.income_tax?.withholding_rate)}</dd></div>
                    {historicalTaxes && <>
                        <div className="flex justify-between gap-4"><dt>Confianza</dt><dd className="font-bold">Alta</dd></div>
                        <div className="flex justify-between gap-4"><dt>Muestra histórica</dt><dd className="font-bold">{number(taxes.sample_count)} ventas</dd></div>
                        <div className="flex justify-between gap-4"><dt>Publicaciones distintas</dt><dd className="font-bold">{number(taxes.evidence?.distinct_items)}</dd></div>
                        <div className="flex justify-between gap-4"><dt>Última observación</dt><dd className="font-bold">{dateTime(taxes.last_observed_at)}</dd></div>
                    </>}
                </>}
                {other.filter((charge) => charge.included_in_total !== true).map((charge) => <div key={charge.key} className="flex justify-between gap-4"><dt>{charge.label}<span className="block text-[10px] text-slate-500">{charge.key} · informativo, no sumado</span></dt><dd className="font-bold">{money(charge.value, currency)}</dd></div>)}
            </dl>
        </details>
    </div>
}

function PriceSimulationModal({ item, price, simulatedPrice, result, loading, initialLoading, updating, confirming, success, error, onPriceChange, onSubmit, onContinue, onCancelConfirmation, onConfirm, onClose }) {
    if (!item) return null

    const currency = result?.currency_id || item.currency_id || 'MXN'
    const priceDifference = result ? Number(result.proposed_price) - Number(result.current_price) : 0
    const busy = loading || updating
    const resultPresentation = simulationResultPresentation(price, simulatedPrice, Boolean(result))
    const priceMatchesSimulation = resultPresentation.visible && !resultPresentation.stale
    const currentSimulation = canContinueWithSimulation(price, simulatedPrice, { hasResult: Boolean(result), error, loading, updating })
    const priceRelation = result?.price_relations || item.price_relations
    const relatedItems = (priceRelation?.items || []).filter((member) => member.meli_item_id !== item.meli_item_id)

    return <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4" role="dialog" aria-modal="true" aria-labelledby="price-simulation-title">
        <div className="max-h-[95vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-2xl dark:bg-neutral-900">
            <div className="flex items-start justify-between gap-4 border-b border-slate-200 p-5 dark:border-neutral-800">
                <div><p className="text-xs font-bold uppercase tracking-wide text-indigo-600">Simulación de precio</p><h2 id="price-simulation-title" className="mt-1 text-xl font-bold">{item.title}</h2><p className="mt-1 text-xs text-slate-500">{item.meli_item_id} · SKU: {item.sku || '—'}</p></div>
                <button type="button" onClick={onClose} disabled={updating} className={secondaryButton}>Cerrar</button>
            </div>

            {success ? <div className="space-y-5 p-5">
                <div className="rounded-2xl border-2 border-emerald-400 bg-emerald-50 p-5 dark:bg-emerald-500/10"><p className="text-sm font-extrabold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Precio actualizado correctamente en Mercado Libre</p><dl className="mt-4 space-y-2 text-sm"><div className="flex justify-between"><dt>MLM</dt><dd className="font-bold">{success.meli_item_id}</dd></div><div className="flex justify-between"><dt>Precio anterior</dt><dd className="font-bold">{money(success.old_price, currency)}</dd></div><div className="flex justify-between"><dt>Precio confirmado</dt><dd className="font-bold text-emerald-700 dark:text-emerald-300">{money(success.new_price, currency)}</dd></div><div className="flex justify-between"><dt>Hora</dt><dd className="font-bold">{dateTime(success.updated_at)}</dd></div></dl></div>
                <div className="flex justify-end"><button type="button" onClick={onClose} className={primaryButton}>Cerrar</button></div>
            </div> : <>
            {priceRelation?.linked && <div className="mx-5 mt-5 rounded-xl border border-indigo-200 bg-indigo-50 p-4 text-sm dark:border-indigo-500/30 dark:bg-indigo-500/10"><p className="font-extrabold text-indigo-800 dark:text-indigo-200">Publicación vinculada</p><p className="mt-1">Este precio está sincronizado por Mercado Libre con otra publicación.</p>{relatedItems.map((member) => <div key={member.meli_item_id}><p className="mt-2 font-bold">{item.meli_item_id} ({item.catalog_listing ? 'Catálogo' : 'No catálogo'}) ↔ Mercado Libre SYNC ↔ {member.meli_item_id} ({member.catalog_listing ? 'Catálogo' : 'No catálogo'})</p>{confirming && <p className="mt-2 rounded-lg bg-amber-100 p-2 font-semibold text-amber-900">Este cambio también puede reflejarse automáticamente en {member.meli_item_id} porque Mercado Libre mantiene ambas publicaciones sincronizadas.</p>}</div>)}</div>}
            {priceRelation?.detected && !priceRelation?.linked && <div className="mx-5 mt-5 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm font-semibold text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">Relación detectada, pero Mercado Libre no indica sincronización activa.</div>}
            {initialLoading && <p className="mx-5 mt-5 rounded-xl bg-slate-50 p-3 text-sm font-semibold text-slate-600 dark:bg-neutral-800 dark:text-slate-300">Calculando cargos del precio actual…</p>}
            <form onSubmit={onSubmit} className="grid gap-4 border-b border-slate-200 p-5 sm:grid-cols-2 dark:border-neutral-800">
                <div><p className="text-xs font-bold text-slate-500">Precio actual</p><p className="mt-1 text-2xl font-bold">{money(item.current_price, currency)}</p></div>
                <label><span className="mb-1 block text-xs font-bold">Nuevo precio</span><input type="number" min="0.01" max="999999999.99" step="0.01" required autoFocus disabled={confirming || updating} value={price} onChange={(event) => onPriceChange(event.target.value)} className={fieldClass} /></label>
                {error && <p className="sm:col-span-2 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-200">{error}</p>}
                <div className="sm:col-span-2 flex justify-end"><button type="submit" disabled={confirming || busy || !price || Number(price) <= 0} className={primaryButton}>{loading ? 'Calculando…' : 'Calcular cargos'}</button></div>
            </form>

            {result && <div className="space-y-4 p-5">
                <p className="text-xs font-bold text-slate-500">Resultados calculados para {money(simulatedPrice, currency)}</p>
                <ChargesBreakdown result={result} currency={currency} />
                {!priceMatchesSimulation && <p className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">El precio cambió. Calcula nuevamente los cargos para continuar.</p>}
                {!confirming && <div className="flex justify-end"><button type="button" onClick={onContinue} disabled={!currentSimulation} className="rounded-xl bg-amber-500 px-4 py-2 text-sm font-extrabold text-slate-950 transition hover:bg-amber-400 disabled:cursor-not-allowed disabled:opacity-40">Continuar con cambio</button></div>}
            </div>}

            {result && confirming && <div className="space-y-5 border-t border-slate-200 p-5 dark:border-neutral-800"><div><p className="text-xs font-extrabold uppercase tracking-[0.18em] text-rose-600">Confirmar cambio de precio</p><dl className="mt-3 space-y-3 text-sm"><div className="flex justify-between"><dt>Precio actual</dt><dd className="font-bold">{money(result.current_price, currency)}</dd></div><div className="flex justify-between"><dt>Nuevo precio</dt><dd className="font-bold">{money(simulatedPrice, currency)}</dd></div><div className="flex justify-between"><dt>Diferencia</dt><dd className={`font-bold ${priceDifference >= 0 ? 'text-emerald-600' : 'text-rose-600'}`}>{priceDifference >= 0 ? '+' : ''}{money(priceDifference, currency)}</dd></div></dl></div><p className="rounded-xl border border-rose-300 bg-rose-50 p-3 text-sm font-bold text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">Este cambio modificará el precio real en Mercado Libre.</p><div className="flex flex-wrap justify-end gap-2"><button type="button" onClick={onCancelConfirmation} disabled={updating} className={secondaryButton}>Cancelar</button><button type="button" onClick={onConfirm} disabled={updating || !currentSimulation} className="rounded-xl bg-rose-600 px-4 py-2 text-sm font-extrabold text-white transition hover:bg-rose-700 disabled:opacity-40">{updating ? 'Verificando y actualizando…' : `Confirmar cambio a ${money(simulatedPrice, currency)}`}</button></div></div>}

            <p className="border-t border-slate-200 px-5 py-4 text-xs font-semibold text-slate-500 dark:border-neutral-800">Esta es una simulación consultada con Mercado Libre. No se ha modificado el precio de la publicación.</p>
            </>}
        </div>
    </div>
}

function Metric({ label, value, tone = 'slate', detail = null }) {
    const tones = {
        slate: 'border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900',
        green: 'border-emerald-200 bg-emerald-50 dark:border-emerald-500/20 dark:bg-emerald-500/10',
        amber: 'border-amber-200 bg-amber-50 dark:border-amber-500/20 dark:bg-amber-500/10',
        rose: 'border-rose-200 bg-rose-50 dark:border-rose-500/20 dark:bg-rose-500/10',
        indigo: 'border-indigo-200 bg-indigo-50 dark:border-indigo-500/20 dark:bg-indigo-500/10',
    }
    return <div className={`rounded-2xl border p-4 ${tones[tone]}`}><p className="text-xs font-bold uppercase tracking-wide text-slate-500">{label}</p><p className="mt-1 text-3xl font-bold">{number(value)}</p>{detail && <p className="mt-1 text-xs text-slate-500">{detail}</p>}</div>
}

function BrandChangeModal({ item, brands, brandId, processing, error, onBrandChange, onConfirm, onClose }) {
    if (!item) return null

    return <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4" role="dialog" aria-modal="true" aria-labelledby="brand-change-title">
        <div className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl dark:bg-neutral-900">
            <div className="flex items-start justify-between gap-4"><div><p className="text-xs font-bold uppercase tracking-wide text-indigo-600">Clasificación interna</p><h2 id="brand-change-title" className="mt-1 text-xl font-bold">Cambiar marca</h2></div><button type="button" onClick={onClose} disabled={processing} className={secondaryButton}>Cerrar</button></div>
            <dl className="mt-5 space-y-3 text-sm"><div><dt className="font-bold text-slate-500">Producto</dt><dd>{item.title}</dd></div><div><dt className="font-bold text-slate-500">Marca actual</dt><dd>{item.brand_group?.name || 'Sin marca'}</dd></div></dl>
            <label className="mt-4 block"><span className="mb-1 block text-sm font-bold">Nueva marca</span><select value={brandId} onChange={(event) => onBrandChange(event.target.value)} disabled={processing} className={fieldClass}><option value="">Seleccionar</option>{brands.map((brand) => <option key={brand.id} value={brand.id}>{brand.name}</option>)}</select></label>
            {error && <p className="mt-3 rounded-xl bg-rose-50 p-3 text-sm font-semibold text-rose-700">{error}</p>}
            <div className="mt-5 flex justify-end gap-2"><button type="button" onClick={onClose} disabled={processing} className={secondaryButton}>Cancelar</button><button type="button" onClick={onConfirm} disabled={processing || !brandId} className={primaryButton}>{processing ? 'Cambiando…' : 'Cambiar marca'}</button></div>
        </div>
    </div>
}

function BulkBrandChangeModal({ count, brands, brandId, confirmed, processing, error, onBrandChange, onConfirmedChange, onConfirm, onClose }) {
    if (count < 1) return null

    return <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4" role="dialog" aria-modal="true" aria-labelledby="bulk-brand-change-title">
        <div className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl dark:bg-neutral-900">
            <div className="flex items-start justify-between gap-4"><div><p className="text-xs font-bold uppercase tracking-wide text-indigo-600">Clasificación interna</p><h2 id="bulk-brand-change-title" className="mt-1 text-xl font-bold">Cambiar marca de {number(count)} publicaciones</h2></div><button type="button" onClick={onClose} disabled={processing} className={secondaryButton}>Cerrar</button></div>
            <label className="mt-5 block"><span className="mb-1 block text-sm font-bold">Nueva marca interna</span><select value={brandId} onChange={(event) => onBrandChange(event.target.value)} disabled={processing} className={fieldClass}><option value="">Seleccionar</option>{brands.map((brand) => <option key={brand.id} value={brand.id}>{brand.name}</option>)}</select></label>
            <p className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-200">Este cambio sólo modifica la clasificación interna. No cambia la marca ni el precio en Mercado Libre.</p>
            <label className="mt-4 flex items-start gap-3 text-sm font-semibold"><input type="checkbox" checked={confirmed} onChange={(event) => onConfirmedChange(event.target.checked)} disabled={processing} className="mt-1" /><span>Confirmo el cambio de marca interna para {number(count)} publicaciones seleccionadas.</span></label>
            {error && <p className="mt-3 rounded-xl bg-rose-50 p-3 text-sm font-semibold text-rose-700 dark:bg-rose-500/10 dark:text-rose-200">{error}</p>}
            <div className="mt-5 flex justify-end gap-2"><button type="button" onClick={onClose} disabled={processing} className={secondaryButton}>Cancelar</button><button type="button" onClick={onConfirm} disabled={processing || !brandId || !confirmed} className={primaryButton}>{processing ? 'Cambiando…' : 'Cambiar marca'}</button></div>
        </div>
    </div>
}

function StatusBadge({ status, stock }) {
    const label = stock !== null && stock <= 0 ? 'Sin stock' : ({ active: 'Activo', paused: 'Pausado', closed: 'Cerrado', under_review: 'En revisión' }[status] || status || 'Sin estado')
    const tone = stock !== null && stock <= 0
        ? 'bg-rose-100 text-rose-800 dark:bg-rose-500/15 dark:text-rose-200'
        : status === 'active'
            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200'
            : 'bg-slate-100 text-slate-700 dark:bg-neutral-800 dark:text-slate-200'
    return <span className={`rounded-full px-2.5 py-1 text-xs font-bold ${tone}`}>{label}</span>
}

function TaxProfileForm({ selectedAccountId, taxProfile, historicalTaxRule }) {
    const [data, setData] = useState({
        enabled: Boolean(taxProfile?.enabled),
        vat_included_rate: taxProfile?.vat_included_rate ?? '',
        vat_withholding_rate: taxProfile?.vat_withholding_rate ?? '',
        income_tax_withholding_rate: taxProfile?.income_tax_withholding_rate ?? '',
        effective_from: taxProfile?.effective_from ?? '',
        notes: taxProfile?.notes ?? '',
    })
    const [saving, setSaving] = useState(false)

    const submit = (event) => {
        event.preventDefault()
        if (!selectedAccountId || saving) return

        setSaving(true)
        router.put('/meli-price-manager/tax-profile', {
            meli_account_id: selectedAccountId,
            enabled: data.enabled,
            vat_included_rate: data.vat_included_rate === '' ? null : data.vat_included_rate,
            vat_withholding_rate: data.vat_withholding_rate === '' ? null : data.vat_withholding_rate,
            income_tax_withholding_rate: data.income_tax_withholding_rate === '' ? null : data.income_tax_withholding_rate,
            effective_from: data.effective_from || null,
            notes: data.notes || null,
        }, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        })
    }

    return <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
        {historicalTaxRule?.available && historicalTaxRule?.confidence === 'high' && <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-200">Actualmente se está utilizando automáticamente una regla derivada del historial de Mercado Libre. El perfil manual se utilizará solamente como respaldo.</div>}
        <div className="flex flex-col justify-between gap-3 lg:flex-row lg:items-start">
            <div><h2 className="font-bold">Configuración fiscal</h2><p className="mt-1 max-w-3xl text-xs text-slate-500">Configuración exclusiva de esta cuenta. Las tasas se aplican a la base sin IVA y cada retención se redondea por separado.</p><p className="mt-2 max-w-3xl rounded-lg bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">Estos porcentajes deben corresponder a la situación fiscal real del vendedor. No se obtienen automáticamente de Mercado Libre.</p></div>
            <label className="flex items-center gap-2 text-sm font-bold"><input type="checkbox" checked={data.enabled} onChange={(event) => setData({ ...data, enabled: event.target.checked })} /> Usar estimación fiscal</label>
        </div>
        <form onSubmit={submit} className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <label><span className="mb-1 block text-xs font-bold">IVA incluido (%)</span><input type="number" min="0" max="100" step="0.0001" required={data.enabled} value={data.vat_included_rate} onChange={(event) => setData({ ...data, vat_included_rate: event.target.value })} className={fieldClass} /></label>
            <label><span className="mb-1 block text-xs font-bold">Retención IVA (%)</span><input type="number" min="0" max="100" step="0.0001" required={data.enabled} value={data.vat_withholding_rate} onChange={(event) => setData({ ...data, vat_withholding_rate: event.target.value })} className={fieldClass} /></label>
            <label><span className="mb-1 block text-xs font-bold">Retención ISR (%)</span><input type="number" min="0" max="100" step="0.0001" required={data.enabled} value={data.income_tax_withholding_rate} onChange={(event) => setData({ ...data, income_tax_withholding_rate: event.target.value })} className={fieldClass} /></label>
            <label><span className="mb-1 block text-xs font-bold">Vigente desde</span><input type="date" value={data.effective_from} onChange={(event) => setData({ ...data, effective_from: event.target.value })} className={fieldClass} /></label>
            <div className="flex items-end"><button type="submit" disabled={!selectedAccountId || saving} className={primaryButton}>{saving ? 'Guardando…' : 'Guardar perfil fiscal'}</button></div>
            <label className="md:col-span-2 xl:col-span-5"><span className="mb-1 block text-xs font-bold">Notas internas</span><textarea maxLength="1000" rows="2" value={data.notes} onChange={(event) => setData({ ...data, notes: event.target.value })} className={fieldClass} /></label>
        </form>
    </section>
}

export default function Index({
    accounts = [],
    selectedAccountId = null,
    summary = {},
    syncStatus = {},
    brands = [],
    brandOptions = [],
    selectedBrandId = null,
    items = { data: [], links: [] },
    availableStatuses = [],
    availableCategories = [],
    filters = {},
    taxProfile = null,
    historicalTaxRule = null,
}) {
    const [filterData, setFilterData] = useState({
        search: filters.search ?? '',
        status: filters.status ?? '',
        category_id: filters.category_id ?? '',
        min_price: filters.min_price ?? '',
        max_price: filters.max_price ?? '',
        stock: filters.stock ?? 'all',
        sync: filters.sync ?? 'all',
        sort: filters.sort ?? 'title',
        direction: filters.direction ?? 'asc',
        per_page: filters.per_page ?? 50,
    })
    const [selectedIds, setSelectedIds] = useState([])
    const [simulationItem, setSimulationItem] = useState(null)
    const [simulationPrice, setSimulationPrice] = useState('')
    const [simulatedPrice, setSimulatedPrice] = useState(null)
    const [simulationResult, setSimulationResult] = useState(null)
    const [simulationLoading, setSimulationLoading] = useState(false)
    const [initialSimulationLoading, setInitialSimulationLoading] = useState(false)
    const [priceUpdating, setPriceUpdating] = useState(false)
    const [simulationConfirming, setSimulationConfirming] = useState(false)
    const [updateSuccess, setUpdateSuccess] = useState(null)
    const [simulationError, setSimulationError] = useState('')
    const [updatedPrices, setUpdatedPrices] = useState({})
    const [updatedReceivables, setUpdatedReceivables] = useState({})
    const [brandChangeItem, setBrandChangeItem] = useState(null)
    const [brandChangeId, setBrandChangeId] = useState('')
    const [brandChangeProcessing, setBrandChangeProcessing] = useState(false)
    const [brandChangeError, setBrandChangeError] = useState('')
    const [bulkBrandOpen, setBulkBrandOpen] = useState(false)
    const [bulkBrandId, setBulkBrandId] = useState('')
    const [bulkBrandConfirmed, setBulkBrandConfirmed] = useState(false)
    const [bulkBrandProcessing, setBulkBrandProcessing] = useState(false)
    const [bulkBrandError, setBulkBrandError] = useState('')
    const simulationRequestId = useRef(0)
    const allSelected = items.data.length > 0 && items.data.every((item) => selectedIds.includes(item.id))
    const selectedAccount = useMemo(() => accounts.find((account) => Number(account.id) === Number(selectedAccountId)), [accounts, selectedAccountId])
    const selectionScope = selectionScopeKey({
        accountId: selectedAccountId,
        brandId: selectedBrandId,
        page: items.current_page,
        filters,
    })

    useEffect(() => {
        setSelectedIds([])
        setBulkBrandOpen(false)
        setBulkBrandId('')
        setBulkBrandConfirmed(false)
        setBulkBrandError('')
    }, [selectionScope])

    const visit = (overrides = {}, preserveState = true) => router.get(
        '/meli-price-manager',
        { account: selectedAccountId, brand: selectedBrandId, ...filterData, ...overrides },
        {
            preserveState,
            preserveScroll: true,
            replace: true,
            onSuccess: () => {
                setSelectedIds([])
                setBulkBrandOpen(false)
            },
        },
    )

    const changeAccount = (event) => {
        setSelectedIds([])
        setBulkBrandOpen(false)
        router.get('/meli-price-manager', { account: event.target.value })
    }

    const clearFilters = () => {
        setSelectedIds([])
        setBulkBrandOpen(false)
        router.get('/meli-price-manager', { account: selectedAccountId })
    }

    const sync = () => {
        if (!selectedAccountId || syncStatus.queued) return
        router.post('/meli-price-manager/sync', { meli_account_id: selectedAccountId }, { preserveScroll: true })
    }

    const openBrandChange = (item) => {
        setBrandChangeItem(item)
        setBrandChangeId(item.brand_group_id ?? '')
        setBrandChangeError('')
    }

    const confirmBrandChange = () => {
        if (!brandChangeItem || !brandChangeId || brandChangeProcessing) return
        setBrandChangeProcessing(true)
        setBrandChangeError('')
        router.post(`/meli-price-manager/items/${brandChangeItem.id}/brand`, {
            meli_account_id: selectedAccountId,
            brand_group_id: brandChangeId,
        }, {
            preserveScroll: true,
            onSuccess: () => setBrandChangeItem(null),
            onError: (errors) => setBrandChangeError(errors.brand_group_id || errors.item || 'No fue posible cambiar la marca.'),
            onFinish: () => setBrandChangeProcessing(false),
        })
    }

    const openBulkBrandChange = () => {
        if (selectedIds.length < 1) return
        setBulkBrandId('')
        setBulkBrandConfirmed(false)
        setBulkBrandError('')
        setBulkBrandOpen(true)
    }

    const closeBulkBrandChange = () => {
        if (bulkBrandProcessing) return
        setBulkBrandOpen(false)
        setBulkBrandError('')
    }

    const confirmBulkBrandChange = () => {
        if (!selectedAccountId || selectedIds.length < 1 || !bulkBrandId || !bulkBrandConfirmed || bulkBrandProcessing) return

        setBulkBrandProcessing(true)
        setBulkBrandError('')
        router.post('/meli-price-manager/items/bulk-brand', {
            meli_account_id: selectedAccountId,
            item_ids: selectedIds,
            brand_group_id: bulkBrandId,
            confirm: bulkBrandConfirmed,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setBulkBrandOpen(false)
                setBulkBrandId('')
                setBulkBrandConfirmed(false)
                setSelectedIds([])
            },
            onError: (errors) => setBulkBrandError(
                errors.item_ids
                || errors.brand_group_id
                || errors.meli_account_id
                || errors.confirm
                || 'No fue posible cambiar la marca de las publicaciones seleccionadas.',
            ),
            onFinish: () => setBulkBrandProcessing(false),
        })
    }

    const stale = (item) => !item.last_synced_at || new Date(item.last_synced_at) < new Date(syncStatus.stale_before)

    const runSimulation = async (item, price, initial = false) => {
        const requestId = ++simulationRequestId.current
        setSimulationLoading(true)
        setInitialSimulationLoading(initial)
        setSimulationError('')
        setSimulationConfirming(false)
        setUpdateSuccess(null)
        try {
            const result = await simulatePrice(item.id, price)
            if (requestId !== simulationRequestId.current) return
            setSimulationResult(result)
            setSimulatedPrice(result.proposed_price)
            if (result.receivable_snapshot) {
                setUpdatedReceivables((current) => ({ ...current, [item.id]: result.receivable_snapshot }))
            }
        } catch (requestError) {
            if (requestId !== simulationRequestId.current) return
            setSimulationError(requestError instanceof Error ? requestError.message : 'No fue posible calcular los cargos.')
        } finally {
            if (requestId === simulationRequestId.current) {
                setSimulationLoading(false)
                setInitialSimulationLoading(false)
            }
        }
    }

    const openSimulation = (item) => {
        const currentPrice = initialSimulationPrice(item, updatedPrices)
        const selectedItem = { ...item, current_price: currentPrice }
        ++simulationRequestId.current
        setSimulationItem(selectedItem)
        setSimulationPrice(currentPrice ?? '')
        setSimulationResult(null)
        setSimulatedPrice(null)
        setSimulationConfirming(false)
        setUpdateSuccess(null)
        setSimulationError('')
        void runSimulation(selectedItem, currentPrice, true)
    }

    const calculateSimulation = async (event) => {
        event.preventDefault()
        if (!simulationItem || simulationLoading) return

        await runSimulation(simulationItem, simulationPrice)
    }

    const closeSimulation = () => {
        if (priceUpdating) return
        ++simulationRequestId.current
        setSimulationItem(null)
        setSimulationResult(null)
        setSimulatedPrice(null)
        setSimulationError('')
        setSimulationLoading(false)
        setInitialSimulationLoading(false)
        setSimulationConfirming(false)
        setUpdateSuccess(null)
    }

    const confirmPriceUpdate = async () => {
        if (!simulationItem || !canContinueWithSimulation(simulationPrice, simulatedPrice, { hasResult: Boolean(simulationResult), error: simulationError, loading: simulationLoading, updating: priceUpdating })) return

        setPriceUpdating(true)
        setSimulationError('')
        try {
            const update = await updatePrice(simulationItem.id, simulationResult.simulation_token, simulationResult.proposed_price)
            setUpdatedPrices((current) => ({ ...current, [simulationItem.id]: update.new_price }))
            setUpdatedReceivables((current) => ({
                ...current,
                [simulationItem.id]: update.receivable_snapshot,
            }))
            setUpdateSuccess(update)
            setSimulationConfirming(false)
        } catch (requestError) {
            const requiresNewSimulation = ['simulation_expired', 'simulation_price_mismatch', 'concurrent_price_change'].includes(requestError?.code)
            if (requiresNewSimulation) {
                setSimulationResult(null)
                setSimulationConfirming(false)
            }
            setSimulationError(requestError instanceof Error ? requestError.message : 'No fue posible actualizar el precio.')
        } finally {
            setPriceUpdating(false)
        }
    }

    return (
        <AppShell title="Meli Price Manager">
            <Head title="Publicaciones por marca · Meli Price Manager" />
            <div className="space-y-6">
                <header className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
                    <div>
                        <p className="text-xs font-bold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Meli Price Manager</p>
                        <h1 className="mt-1 text-3xl font-bold">Publicaciones por marca</h1>
                        <p className="mt-2 text-sm text-slate-500">Consulta el impacto de un precio y aplica cambios individuales con verificación y confirmación explícita.</p>
                    </div>
                    <nav className="flex flex-wrap gap-2">
                        <Link href={`/meli-price-manager/brands?account=${selectedAccountId ?? ''}`} className={secondaryButton}>Administrar marcas</Link>
                        <Link href={`/meli-price-manager/uncategorized?account=${selectedAccountId ?? ''}`} className={secondaryButton}>Pendientes ({number(summary.pending)})</Link>
                    </nav>
                </header>

                <section className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="grid gap-4 lg:grid-cols-[minmax(18rem,1fr)_minmax(0,2fr)_auto] lg:items-end">
                        <label><span className="mb-1 block text-xs font-bold">Cuenta de Mercado Libre</span><select value={selectedAccountId ?? ''} onChange={changeAccount} disabled={!accounts.length} className={fieldClass}>{!accounts.length && <option value="">Sin cuentas vinculadas</option>}{accounts.map((account) => <option key={account.id} value={account.id}>{account.nickname || `Cuenta #${account.id}`}{account.is_default ? ' · predeterminada' : ''}</option>)}</select></label>
                        <div><p className="text-sm font-semibold">Última sincronización: {dateTime(summary.last_synced_at)}</p><p className="mt-1 text-xs text-slate-500">{number(summary.recently_synced)} registros sincronizados en las últimas {syncStatus.stale_after_hours}h · {number(summary.never_synced)} nunca sincronizados.</p><p className="mt-1 text-xs font-semibold text-indigo-600">Sincronizar solo descarga el estado actual; no modifica publicaciones en Mercado Libre.</p></div>
                        <button type="button" onClick={sync} disabled={!selectedAccountId || syncStatus.queued} className={primaryButton}>{syncStatus.queued ? 'Sincronización en cola' : 'Sincronizar Mercado Libre'}</button>
                    </div>
                </section>

                {selectedAccountId && <TaxProfileForm key={selectedAccountId} selectedAccountId={selectedAccountId} taxProfile={taxProfile} historicalTaxRule={historicalTaxRule} />}

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                    <Metric label="Total" value={summary.total} />
                    <Metric label="Categorizadas" value={summary.categorized} tone="green" />
                    <Metric label="Sugeridas" value={summary.suggested} tone="amber" />
                    <Metric label="Sin categorizar" value={summary.uncategorized} tone="rose" />
                    <Metric label="Ignoradas" value={summary.ignored} />
                    <Metric label="Marcas activas" value={summary.active_brands} tone="indigo" detail={`${number(summary.stale)} sin sincronizar en ${syncStatus.stale_after_hours}h`} />
                </section>

                <section>
                    <div className="mb-3 flex items-end justify-between"><div><h2 className="text-xl font-bold">Marcas</h2><p className="text-sm text-slate-500">Conteos y rangos corresponden únicamente a {selectedAccount?.nickname || 'la cuenta seleccionada'}.</p></div></div>
                    <div className="flex gap-3 overflow-x-auto pb-2">
                        <button type="button" onClick={() => visit({ brand: null, page: 1 })} className={`min-w-52 rounded-2xl border p-4 text-left ${selectedBrandId === null ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-500/10' : 'border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900'}`}><p className="font-bold">Todas las marcas</p><p className="mt-1 text-2xl font-bold">{number(summary.categorized)}</p><p className="text-xs text-slate-500">publicaciones categorizadas</p></button>
                        {brands.map((brand) => <button key={brand.id} type="button" onClick={() => visit({ brand: brand.id, page: 1 })} className={`min-w-64 rounded-2xl border p-4 text-left ${Number(selectedBrandId) === Number(brand.id) ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-500/10' : 'border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900'}`}><div className="flex items-center justify-between gap-2"><p className="font-bold">{brand.name}</p><span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800">Activa</span></div><p className="mt-2 text-2xl font-bold">{number(brand.categorized_items_count)}</p><p className="text-xs text-slate-500">publicaciones · {number(brand.suggested_items_count)} sugeridas</p><p className="mt-2 text-xs font-semibold">{brand.min_price === null ? 'Sin precios' : `${money(brand.min_price)} – ${money(brand.max_price)}`}</p><p className="text-xs text-slate-500">Stock total: {number(brand.total_stock)}</p></button>)}
                    </div>
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <form onSubmit={(event) => { event.preventDefault(); visit({ page: 1 }) }} className="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                        <label className="xl:col-span-2"><span className="mb-1 block text-xs font-bold">Buscar título, SKU, MLM o marca ML</span><input value={filterData.search} onChange={(event) => setFilterData({ ...filterData, search: event.target.value })} className={fieldClass} /></label>
                        <label><span className="mb-1 block text-xs font-bold">Estado ML</span><select value={filterData.status} onChange={(event) => setFilterData({ ...filterData, status: event.target.value })} className={fieldClass}><option value="">Todos</option>{availableStatuses.map((status) => <option key={status} value={status}>{status}</option>)}</select></label>
                        <label><span className="mb-1 block text-xs font-bold">Categoría</span><select value={filterData.category_id} onChange={(event) => setFilterData({ ...filterData, category_id: event.target.value })} className={fieldClass}><option value="">Todas</option>{availableCategories.map((category) => <option key={category.category_id} value={category.category_id}>{category.name} ({category.category_id})</option>)}</select></label>
                        <label><span className="mb-1 block text-xs font-bold">Stock</span><select value={filterData.stock} onChange={(event) => setFilterData({ ...filterData, stock: event.target.value })} className={fieldClass}><option value="all">Todos</option><option value="in_stock">Con stock</option><option value="out_of_stock">Sin stock</option></select></label>
                        <label><span className="mb-1 block text-xs font-bold">Sincronización</span><select value={filterData.sync} onChange={(event) => setFilterData({ ...filterData, sync: event.target.value })} className={fieldClass}><option value="all">Todas</option><option value="recent">Recientes</option><option value="stale">Desactualizadas</option><option value="never">Nunca</option></select></label>
                        <label><span className="mb-1 block text-xs font-bold">Precio mínimo</span><input type="number" min="0" step="0.01" value={filterData.min_price} onChange={(event) => setFilterData({ ...filterData, min_price: event.target.value })} className={fieldClass} /></label>
                        <label><span className="mb-1 block text-xs font-bold">Precio máximo</span><input type="number" min="0" step="0.01" value={filterData.max_price} onChange={(event) => setFilterData({ ...filterData, max_price: event.target.value })} className={fieldClass} /></label>
                        <label><span className="mb-1 block text-xs font-bold">Ordenar</span><select value={filterData.sort} onChange={(event) => setFilterData({ ...filterData, sort: event.target.value })} className={fieldClass}><option value="title">Producto</option><option value="sku">SKU</option><option value="price">Precio</option><option value="stock">Stock</option><option value="last_synced_at">Última sincronización</option></select></label>
                        <label><span className="mb-1 block text-xs font-bold">Dirección</span><select value={filterData.direction} onChange={(event) => setFilterData({ ...filterData, direction: event.target.value })} className={fieldClass}><option value="asc">Ascendente</option><option value="desc">Descendente</option></select></label>
                        <label><span className="mb-1 block text-xs font-bold">Por página</span><select value={filterData.per_page} onChange={(event) => setFilterData({ ...filterData, per_page: event.target.value })} className={fieldClass}><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></label>
                        <div className="flex items-end gap-2"><button className={primaryButton}>Aplicar</button><button type="button" onClick={clearFilters} className={secondaryButton}>Limpiar filtros</button></div>
                    </form>
                </section>

                {selectedIds.length > 0 && <div className="flex flex-col justify-between gap-3 rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm dark:border-indigo-500/20 dark:bg-indigo-500/10 sm:flex-row sm:items-center"><div><p className="font-bold">{number(selectedIds.length)} publicaciones seleccionadas</p><p className="mt-1 text-xs text-slate-500">Los cambios masivos de precio permanecen deshabilitados.</p></div><button type="button" onClick={openBulkBrandChange} className={primaryButton}>Cambiar marca</button></div>}

                <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="border-b border-slate-200 px-4 py-3 dark:border-neutral-800"><h2 className="font-bold">Publicaciones categorizadas</h2><p className="text-xs text-slate-500">Solo lectura · {number(items.total)} resultados</p></div>
                    <div className="overflow-x-auto">
                        <table className="min-w-[1300px] text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-neutral-950"><tr><th className="px-3 py-3"><input type="checkbox" checked={allSelected} onChange={() => setSelectedIds(allSelected ? [] : items.data.map((item) => item.id))} /></th><th className="px-3 py-3">Imagen</th><th className="px-3 py-3">Producto</th><th className="px-3 py-3">Marca ML</th><th className="px-3 py-3">Marca interna</th><th className="px-3 py-3">Precio</th><th className="px-3 py-3">Recibes</th><th className="px-3 py-3">Stock</th><th className="px-3 py-3">Estado ML</th><th className="px-3 py-3">Sincronización</th><th className="px-3 py-3">Acción</th></tr></thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                {!items.data.length && <tr><td colSpan="11" className="px-5 py-12 text-center text-slate-500">No hay publicaciones categorizadas para estos filtros.</td></tr>}
                                {items.data.map((item) => <tr key={item.id}><td className="px-3 py-4"><input type="checkbox" checked={selectedIds.includes(item.id)} onChange={() => setSelectedIds((current) => current.includes(item.id) ? current.filter((id) => id !== item.id) : [...current, item.id])} /></td><td className="px-3 py-4">{item.thumbnail ? <img src={item.thumbnail} alt="" className="h-12 w-12 rounded-lg object-cover" referrerPolicy="no-referrer" /> : <div className="flex h-12 w-12 items-center justify-center rounded-lg bg-slate-100 text-[10px] text-slate-400 dark:bg-neutral-800">Sin imagen</div>}</td><td className="max-w-sm px-3 py-4"><p className="font-semibold">{item.title}</p><p className="mt-1 text-xs text-slate-500">SKU: {item.sku || '—'} · {item.meli_item_id}</p><p className="text-xs text-slate-500">Categoría: {item.category?.name || item.category_id || '—'}{item.category?.name && <span className="ml-1 text-[10px]">({item.category_id})</span>}</p></td><td className="px-3 py-4">{item.meli_brand || 'Sin marca'}</td><td className="px-3 py-4 font-bold text-indigo-700 dark:text-indigo-300">{item.brand_group?.name || '—'}</td><td className="px-3 py-4 font-semibold">{money(updatedPrices[item.id] ?? item.current_price, item.currency_id)}</td><td className="px-3 py-4 font-bold text-emerald-700 dark:text-emerald-300">{money(currentReceivableForItem(item, updatedReceivables, updatedPrices[item.id] ?? item.current_price), item.currency_id)}{currentReceivableForItem(item, updatedReceivables, updatedPrices[item.id] ?? item.current_price) == null && <span className="block text-[10px] font-normal text-slate-400">Pendiente</span>}</td><td className="px-3 py-4">{item.available_quantity ?? '—'}</td><td className="px-3 py-4"><StatusBadge status={item.status} stock={item.available_quantity} /></td><td className="px-3 py-4"><p>{dateTime(item.last_synced_at)}</p>{stale(item) && <span className="mt-1 inline-block rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800">Desactualizada</span>}</td><td className="px-3 py-4"><div className="flex flex-col items-start gap-2"><button type="button" onClick={() => openSimulation(item)} className={primaryButton}>Simular precio</button><button type="button" onClick={() => openBrandChange(item)} className={secondaryButton}>Cambiar marca</button>{item.permalink && <a href={item.permalink} target="_blank" rel="noreferrer" className={secondaryButton}>Abrir en Mercado Libre</a>}</div></td></tr>)}
                                {items.data.map((item) => (item.price_relations?.linked || item.stock_relations?.shared) && <tr key={`links-${item.id}`} className="bg-slate-50/70 dark:bg-neutral-950/40"><td colSpan="2" /><td colSpan="8" className="px-3 pb-3 text-xs"><div className="flex flex-wrap gap-3">{item.price_relations?.linked && <details><summary className="cursor-pointer rounded-full bg-indigo-100 px-3 py-1 font-bold text-indigo-800">Precio vinculado · {item.price_relations.items.length}</summary><div className="mt-1 rounded-lg border bg-white p-2 dark:bg-neutral-900">{item.price_relations.items.map((member) => <p key={member.meli_item_id}>{member.meli_item_id} · {money(member.price, item.currency_id)} · {member.catalog_listing ? 'Catálogo' : 'No catálogo'}</p>)}</div></details>}{item.stock_relations?.shared && <details><summary className="cursor-pointer rounded-full bg-emerald-100 px-3 py-1 font-bold text-emerald-800">Stock compartido · {item.stock_relations.items.length}</summary><div className="mt-1 rounded-lg border bg-white p-2 dark:bg-neutral-900"><p className="font-bold">Inventario {item.stock_relations.inventory_id}</p>{item.stock_relations.items.map((member) => <p key={member.meli_item_id}>{member.meli_item_id} · {member.stock ?? '—'}</p>)}</div></details>}</div></td></tr>)}
                            </tbody>
                        </table>
                    </div>
                    {items.links?.length > 0 && <div className="flex flex-wrap gap-2 border-t border-slate-200 p-4 dark:border-neutral-800">{items.links.map((link, index) => <Link key={index} href={link.url ?? '#'} preserveScroll onClick={() => { setSelectedIds([]); setBulkBrandOpen(false) }} className={`rounded-lg px-3 py-2 text-sm ${link.active ? 'bg-indigo-600 text-white' : 'border border-slate-200 dark:border-neutral-700'} ${!link.url ? 'pointer-events-none opacity-40' : ''}`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}
                </section>
            </div>
            <PriceSimulationModal item={simulationItem} price={simulationPrice} simulatedPrice={simulatedPrice} result={simulationResult} loading={simulationLoading} initialLoading={initialSimulationLoading} updating={priceUpdating} confirming={simulationConfirming} success={updateSuccess} error={simulationError} onPriceChange={(value) => { setSimulationPrice(value); setSimulationConfirming(false); setUpdateSuccess(null) }} onSubmit={calculateSimulation} onContinue={() => { if (simulationMatchesDraft(simulationPrice, simulatedPrice) && !simulationError) setSimulationConfirming(true) }} onCancelConfirmation={() => setSimulationConfirming(false)} onConfirm={confirmPriceUpdate} onClose={closeSimulation} />
            <BrandChangeModal item={brandChangeItem} brands={brandOptions} brandId={brandChangeId} processing={brandChangeProcessing} error={brandChangeError} onBrandChange={setBrandChangeId} onConfirm={confirmBrandChange} onClose={() => { if (!brandChangeProcessing) setBrandChangeItem(null) }} />
            {bulkBrandOpen && <BulkBrandChangeModal count={selectedIds.length} brands={brandOptions} brandId={bulkBrandId} confirmed={bulkBrandConfirmed} processing={bulkBrandProcessing} error={bulkBrandError} onBrandChange={(value) => { setBulkBrandId(value); setBulkBrandError('') }} onConfirmedChange={setBulkBrandConfirmed} onConfirm={confirmBulkBrandChange} onClose={closeBulkBrandChange} />}
        </AppShell>
    )
}
