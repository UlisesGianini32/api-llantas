import AppShell from '@/Components/layout/AppShell'
import { Link, useForm } from '@inertiajs/react'

function RepublishForm({
    ml,
    defaults,
    isUserProduct,
    hasCatalogProduct,
    currentUniversalCode,
    requiredAttributes = [],
    pub,
    item,
}) {
    const { data, setData, post, processing, errors } = useForm(defaults)

    const submit = (e) => {
        e.preventDefault()
        post(`/producto/${encodeURIComponent(ml)}/republish`)
    }

    const currentLabel = isUserProduct
        ? (item?.family_name || item?.title || '—')
        : (item?.title || item?.family_name || '—')

    const skuDisplay = pub?.sku ?? item?.seller_custom_field ?? '—'

    return (
        <form onSubmit={submit} className="space-y-6">
            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div className="rounded-2xl border border-slate-200 p-4 dark:border-neutral-700">
                    <div className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">MLM original</div>
                    <div className="mt-1 font-mono text-sm text-slate-900 dark:text-white">{ml}</div>
                </div>
                <div className="rounded-2xl border border-slate-200 p-4 dark:border-neutral-700">
                    <div className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">SKU</div>
                    <div className="mt-1 text-sm text-slate-900 dark:text-white">{skuDisplay}</div>
                </div>
                <div className="rounded-2xl border border-slate-200 p-4 md:col-span-2 dark:border-neutral-700">
                    <div className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                        {isUserProduct ? 'Nombre de familia actual' : 'Título actual'}
                    </div>
                    <div className="mt-1 text-sm text-slate-900 dark:text-white">{currentLabel}</div>
                </div>
                <div className="rounded-2xl border border-slate-200 p-4 dark:border-neutral-700">
                    <div className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Precio actual</div>
                    <div className="mt-1 text-sm text-slate-900 dark:text-white">
                        ${Number(item?.price ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                    </div>
                </div>
                <div className="rounded-2xl border border-slate-200 p-4 dark:border-neutral-700">
                    <div className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Estado</div>
                    <div className="mt-1 text-sm text-slate-900 dark:text-white">{item?.status ?? '—'}</div>
                </div>
                <div className="rounded-2xl border border-slate-200 p-4 md:col-span-2 dark:border-neutral-700">
                    <div className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Código universal actual</div>
                    <div className="mt-1 font-mono text-sm text-slate-900 dark:text-white">
                        {currentUniversalCode?.trim() ? currentUniversalCode : '—'}
                    </div>
                </div>
            </div>

            {isUserProduct && (
                <div className="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-200">
                    Esta publicación usa User Product. Mercado Libre trabaja con <strong>family_name</strong> y el atributo <strong>NAME</strong>.
                </div>
            )}

            <div className="rounded-2xl border border-slate-200 p-4 dark:border-neutral-700">
                <span className="mb-3 block text-sm font-semibold text-slate-900 dark:text-white">Tienda oficial</span>
                <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
                    {[
                        { value: 'marketmax', label: 'MARKETMAX' },
                        { value: 'tobeauty', label: 'TOBEAUTY' },
                        { value: 'none', label: 'Publicar fuera de tienda oficial' },
                    ].map((opt) => (
                        <label
                            key={opt.value}
                            className="flex cursor-pointer items-center gap-3 rounded-2xl border border-slate-200 p-4 dark:border-neutral-600"
                        >
                            <input
                                type="radio"
                                name="official_store_mode"
                                value={opt.value}
                                checked={data.official_store_mode === opt.value}
                                onChange={() => setData('official_store_mode', opt.value)}
                                className="text-indigo-600 focus:ring-indigo-500"
                            />
                            <span className="text-sm text-slate-900 dark:text-white">{opt.label}</span>
                        </label>
                    ))}
                </div>
                {errors.official_store_mode && (
                    <p className="mt-2 text-sm text-red-600 dark:text-red-400">{errors.official_store_mode}</p>
                )}
            </div>

            <div>
                <label htmlFor="republish-title" className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    {isUserProduct ? 'Nuevo nombre de familia' : 'Nuevo título'}
                </label>
                <input
                    id="republish-title"
                    type="text"
                    value={data.title}
                    onChange={(e) => setData('title', e.target.value)}
                    maxLength={60}
                    required
                    className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white dark:border-neutral-600 dark:bg-neutral-950 dark:text-white"
                    placeholder={isUserProduct ? 'Nuevo nombre de familia' : 'Nuevo título'}
                />
                {errors.title && <p className="mt-1 text-sm text-red-600 dark:text-red-400">{errors.title}</p>}
            </div>

            <div>
                <label htmlFor="republish-price" className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Nuevo precio
                </label>
                <input
                    id="republish-price"
                    type="number"
                    step="0.01"
                    min="1"
                    value={data.price}
                    onChange={(e) => setData('price', e.target.value)}
                    required
                    className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white dark:border-neutral-600 dark:bg-neutral-950 dark:text-white"
                />
                {errors.price && <p className="mt-1 text-sm text-red-600 dark:text-red-400">{errors.price}</p>}
            </div>

            <div>
                <label htmlFor="republish-universal-code" className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300">
                    Nuevo código universal (GTIN/EAN/UPC)
                </label>
                <input
                    id="republish-universal-code"
                    type="text"
                    value={data.universal_code}
                    onChange={(e) => setData('universal_code', e.target.value)}
                    className="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 font-mono text-slate-900 outline-none transition focus:border-indigo-500 focus:bg-white dark:border-neutral-600 dark:bg-neutral-950 dark:text-white"
                    placeholder="8, 12, 13 o 14 dígitos"
                    inputMode="numeric"
                    autoComplete="off"
                />
                <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
                    Asigna un código distinto al republicar. Si lo dejas vacío, se conservará el de la publicación original.
                </p>
                {errors.universal_code && <p className="mt-1 text-sm text-red-600 dark:text-red-400">{errors.universal_code}</p>}
            </div>

            {requiredAttributes.length > 0 && (
                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30">
                    <div className="mb-4">
                        <h3 className="text-sm font-semibold text-amber-950 dark:text-amber-100">
                            Datos obligatorios de la categoría
                        </h3>
                        <p className="mt-1 text-xs text-amber-800 dark:text-amber-200">
                            Revisa las sugerencias antes de publicar. Las opciones vienen directamente de Mercado Libre.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        {requiredAttributes.map((attribute) => {
                            const fieldName = `required_attributes.${attribute.id}`
                            const fieldError = errors?.[fieldName]

                            return (
                                <div key={attribute.id}>
                                    <label
                                        htmlFor={`republish-required-${attribute.id}`}
                                        className="mb-2 block text-sm font-medium text-slate-700 dark:text-slate-300"
                                    >
                                        {attribute.name}
                                    </label>
                                    <select
                                        id={`republish-required-${attribute.id}`}
                                        value={data.required_attributes?.[attribute.id] ?? ''}
                                        onChange={(event) => setData('required_attributes', {
                                            ...(data.required_attributes ?? {}),
                                            [attribute.id]: event.target.value,
                                        })}
                                        required={Boolean(attribute.required)}
                                        disabled={processing}
                                        className="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-slate-900 outline-none transition focus:border-indigo-500 dark:border-neutral-600 dark:bg-neutral-950 dark:text-white"
                                    >
                                        <option value="">Selecciona una opción</option>
                                        {(attribute.options ?? []).map((option) => (
                                            <option key={`${attribute.id}-${option.id}`} value={option.id}>
                                                {option.name}
                                            </option>
                                        ))}
                                    </select>
                                    {fieldError && (
                                        <p className="mt-1 text-sm text-red-600 dark:text-red-400">{fieldError}</p>
                                    )}
                                </div>
                            )
                        })}
                    </div>
                </div>
            )}

            {hasCatalogProduct && (
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        checked={data.copy_catalog}
                        onChange={(e) => setData('copy_catalog', e.target.checked)}
                        className="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                    />
                    <span className="text-sm text-slate-700 dark:text-slate-300">Mantener catálogo original</span>
                </label>
            )}

            <div className="flex flex-col gap-3 sm:flex-row">
                <Link
                    href="/producto"
                    className="inline-flex justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200 dark:hover:bg-neutral-800"
                >
                    Cancelar
                </Link>
                <button
                    type="submit"
                    disabled={processing}
                    className="inline-flex justify-center rounded-2xl bg-indigo-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-60"
                >
                    {processing ? 'Republicando…' : 'Republicar'}
                </button>
            </div>
        </form>
    )
}

export default function ProductRepublish(props) {
    const { ml, defaults } = props
    const formKey = `${ml}-${defaults.title}-${defaults.price}-${defaults.official_store_mode}-${defaults.copy_catalog}-${defaults.universal_code}-${JSON.stringify(defaults.required_attributes ?? {})}`

    return (
        <AppShell title="Republicar publicación">
            <div className="mx-auto max-w-3xl space-y-6">
                <div>
                    <h2 className="text-2xl font-bold text-slate-900 dark:text-white">Republicar publicación</h2>
                    <p className="mt-2 text-sm text-slate-600 dark:text-slate-400">
                        Se copiarán fotos, descripción y configuración base de la publicación original. Podrás cambiar{' '}
                        {props.isUserProduct ? 'el nombre de familia, el precio' : 'el título, el precio'} y el código universal.
                    </p>
                </div>

                <div className="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
                    <RepublishForm key={formKey} {...props} />
                </div>
            </div>
        </AppShell>
    )
}
