import React, { useMemo, useState } from 'react'
import { Head, Link, useForm, usePage } from '@inertiajs/react'

function mlItemPublicUrl(mlm) {
    if (!mlm || typeof mlm !== 'string') return null
    const id = mlm.trim()
    if (!id) return null
    return `https://articulo.mercadolibre.com.mx/${id}`
}

function mlCategoryId(c) {
    return String(c?.id ?? c?.category_id ?? '').trim()
}

function mlCategoryLabel(c) {
    const n = c?.name ?? c?.category_name ?? c?.domain_name
    const s = typeof n === 'string' ? n.trim() : ''
    return s !== '' ? s : 'Sin nombre'
}

export default function Publish() {
    const {
        compuesto,
        baseLlanta,
        packQty,
        defaultTitle,
        errors: pageErrors = {},
        flash = {},
    } = usePage().props

    const publishedMlm =
        typeof flash?.success === 'string' && flash.success.includes('Publicado OK:')
            ? flash.success.replace(/^Publicado OK:\s*/i, '').trim()
            : null
    const mlPublicUrl = publishedMlm ? mlItemPublicUrl(publishedMlm) : null

    const [step, setStep] = useState(1)

    const [catQuery, setCatQuery] = useState(
        [
            baseLlanta?.marca,
            baseLlanta?.medida,
            baseLlanta?.title_familyname || compuesto?.title_familyname,
        ]
            .filter(Boolean)
            .join(' ')
            .trim()
    )
    const [catLoading, setCatLoading] = useState(false)
    const [catResults, setCatResults] = useState([])
    const [catErr, setCatErr] = useState('')
    const [catHint, setCatHint] = useState(null)

    const [catalogQuery, setCatalogQuery] = useState(
        [
            baseLlanta?.marca,
            baseLlanta?.medida,
            baseLlanta?.title_familyname || compuesto?.title_familyname,
        ]
            .filter(Boolean)
            .join(' ')
            .trim()
    )

    const [catalogLoading, setCatalogLoading] = useState(false)
    const [catalogResults, setCatalogResults] = useState([])
    const [catalogErr, setCatalogErr] = useState('')

    const form = useForm({
        category_id: '',
        category_name: '',
        catalog_mode: 'search',
        catalog_product_id: '',

        title: defaultTitle || compuesto?.title_familyname || '',

        description: '',

        brand: baseLlanta?.marca ?? '',
        model: '',
        load_index: '',
        tire_quantity: packQty || 2,
        line: '',
        sidewall: '',
        service_type: 'LT',
        run_flat: '0',

        package_width_cm: 83,
        package_height_cm: 83,
        package_length_cm: 30,
        package_weight_kg: 26,

        condition: 'new',

        speed_rating: '',
        stock_input: Math.max(1, Number(compuesto?.stock ?? 1)),
        gtin: '',
        seller_sku: compuesto?.sku ?? '',

        pictures_files: [],
        pictures_urls: ['', '', '', '', '', ''],

        aspect_ratio: '',
        section_width: '',
        rim_diameter: '',
        utqg: '',
        load_range: '',
        terrain_type: 'AT',
        construction_type: 'Radial',

        official_store_mode: 'tobeauty',
        warranty_type: 'seller',
        warranty_time_value: 30,
        warranty_time_unit: 'days',

        listing_type_id: 'gold_special',
    })

    const selectedCategory = useMemo(() => {
        return form.data.category_id
            ? {
                  id: form.data.category_id,
                  name: form.data.category_name,
              }
            : null
    }, [form.data.category_id, form.data.category_name])

    const selectedCatalog = useMemo(() => {
        return form.data.catalog_product_id
            ? {
                  id: form.data.catalog_product_id,
              }
            : null
    }, [form.data.catalog_product_id])

    const goStep = (targetStep) => {
        if (targetStep === 2 && !form.data.category_id) {
            setCatErr('Primero selecciona una categoría.')
            setStep(1)
            return
        }

        if (targetStep < 1) targetStep = 1
        if (targetStep > 3) targetStep = 3

        setStep(targetStep)
    }

    const searchCategories = async () => {
        setCatErr('')
        setCatResults([])
        setCatHint(null)

        if (!catQuery.trim()) {
            setCatErr('Escribe algo para buscar la categoría.')
            return
        }

        setCatLoading(true)

        try {
            const response = await fetch('/ml/categories/suggest', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    Accept: 'application/json',
                },
                body: JSON.stringify({ q: catQuery }),
            })

            const json = await response.json()

            if (!response.ok || !json.ok) {
                throw new Error(json.message || 'No se pudieron cargar categorías.')
            }

            setCatResults(Array.isArray(json.data) ? json.data : [])
        } catch (error) {
            setCatErr(error.message || 'Error consultando categorías.')
        } finally {
            setCatLoading(false)
        }
    }

    const selectCategory = async (category) => {
        setCatErr('')
        setCatalogErr('')
        setCatalogResults([])
        setCatHint(null)

        const cid = mlCategoryId(category)
        const cname = String(category?.name ?? category?.category_name ?? category?.domain_name ?? '').trim()

        form.setData((data) => ({
            ...data,
            category_id: cid,
            category_name: cname,
            catalog_product_id: '',
            catalog_mode: 'search',
        }))

        try {
            const response = await fetch('/ml/categories/meta', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    Accept: 'application/json',
                },
                body: JSON.stringify({ category_id: cid }),
            })

            const json = await response.json()

            if (!response.ok || !json.ok) {
                throw new Error(json.message || 'No se pudo consultar la categoría.')
            }

            const isCatalog = Boolean(json?.data?.is_catalog_category)

            setCatHint({
                isCatalog,
                text: isCatalog
                    ? 'Esta categoría parece usar catálogo. Selecciona un producto del catálogo o usa “No encuentro mi opción”.'
                    : 'Esta categoría permite seguir normalmente. Puedes buscar catálogo si aplica.',
            })
        } catch (error) {
            setCatErr(error.message || 'No se pudo consultar la categoría.')
        }
    }

    const searchCatalog = async () => {
        setCatalogErr('')
        setCatalogResults([])

        if (!form.data.category_id) {
            setCatalogErr('Primero selecciona una categoría.')
            return
        }

        if (!catalogQuery.trim()) {
            setCatalogErr('Escribe algo para buscar en el catálogo.')
            return
        }

        setCatalogLoading(true)

        try {
            const response = await fetch('/ml/catalog/search', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':
                        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    q: catalogQuery,
                    category_id: form.data.category_id,
                }),
            })

            const json = await response.json()

            if (!response.ok || !json.ok) {
                throw new Error(json.message || 'No se pudo buscar el catálogo.')
            }

            setCatalogResults(Array.isArray(json.data) ? json.data : [])
        } catch (error) {
            setCatalogErr(error.message || 'Error buscando catálogo.')
        } finally {
            setCatalogLoading(false)
        }
    }

    const selectCatalog = (item) => {
        form.setData((data) => ({
            ...data,
            catalog_product_id: item.catalog_product_id ?? '',
            catalog_mode: 'search',
        }))
    }

    const clearCatalog = () => {
        form.setData((data) => ({
            ...data,
            catalog_product_id: '',
            catalog_mode: 'search',
        }))
    }

    const continueWithoutCatalog = () => {
        form.setData((data) => ({
            ...data,
            catalog_product_id: '',
            catalog_mode: 'no_catalog',
        }))
    }

    const handleFileChange = (e) => {
        const files = Array.from(e.target.files || [])
        form.setData('pictures_files', files)
    }

    const updatePictureUrl = (index, value) => {
        const next = [...form.data.pictures_urls]
        next[index] = value
        form.setData('pictures_urls', next)
    }

    const submit = (e) => {
        e.preventDefault()

        form.post(`/productos/${compuesto.id}/ml/publish`, {
            forceFormData: true,
            preserveScroll: false,
            onSuccess: () => {
                window.scrollTo({ top: 0, behavior: 'smooth' })
            },
        })
    }

    return (
        <>
            <Head title="Publicar compuestos en MercadoLibre" />

            <div className="mx-auto max-w-6xl space-y-6 p-4 md:p-6">
                {flash?.success && (
                    <div className="rounded-2xl border border-emerald-300 bg-emerald-50 px-4 py-4 text-emerald-950 shadow-sm dark:border-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-50">
                        <p className="text-sm font-bold">Publicación creada en Mercado Libre</p>
                        <p className="mt-2 text-sm leading-relaxed">{flash.success}</p>
                        {publishedMlm && (
                            <p className="mt-2 font-mono text-sm">
                                MLM: <span className="font-semibold">{publishedMlm}</span>
                            </p>
                        )}
                        {mlPublicUrl && (
                            <a
                                href={mlPublicUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="mt-3 inline-flex items-center rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-emerald-700"
                            >
                                Ver publicación en Mercado Libre (nueva pestaña)
                            </a>
                        )}
                    </div>
                )}

                {flash?.error && (
                    <div className="whitespace-pre-line rounded-2xl border border-rose-300 bg-rose-50 px-4 py-4 text-sm text-rose-950 dark:border-rose-900 dark:bg-rose-950/50 dark:text-rose-100">
                        <p className="font-bold">No se pudo publicar</p>
                        <p className="mt-2">{flash.error}</p>
                    </div>
                )}

                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                            Publicar compuestos en MercadoLibre
                        </h1>
                        <p className="mt-1 text-sm text-zinc-500 dark:text-neutral-400">
                            Mismo flujo que en Mercado Libre: categoría, catálogo opcional y datos del anuncio.
                        </p>

                        <div className="mt-3 flex flex-wrap gap-2">
                            <span className="inline-flex items-center gap-2 rounded-full border border-zinc-200 bg-zinc-100 px-3 py-1 text-xs font-medium text-zinc-800 dark:border-neutral-600 dark:bg-neutral-800 dark:text-zinc-100">
                                <span className="text-zinc-500 dark:text-zinc-400">SKU</span>
                                <span className="font-mono">{compuesto.sku}</span>
                            </span>
                            <span className="inline-flex items-center gap-2 rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-medium text-indigo-900 dark:border-indigo-900 dark:bg-indigo-950/60 dark:text-indigo-100">
                                Paquete <span className="font-semibold">{packQty}</span>
                            </span>
                            <span className="inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/60 dark:text-emerald-100">
                                Precio ML{' '}
                                <span className="font-semibold">
                                    ${Number(compuesto.precio_ML ?? 0).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                                </span>
                            </span>
                            <span className="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-3 py-1 text-xs dark:border-neutral-600 dark:bg-neutral-900">
                                Stock <span className="font-semibold">{compuesto.stock}</span>
                            </span>
                        </div>
                        <p className="mt-2 text-xs text-zinc-500 dark:text-neutral-500">
                            Llanta base: {baseLlanta?.marca || '—'} {baseLlanta?.medida || '—'} · SKU base{' '}
                            <span className="font-mono">{baseLlanta?.sku || '—'}</span>
                        </p>
                    </div>

                    <Link
                        href="/productos"
                        className="inline-flex shrink-0 items-center justify-center rounded-xl border border-zinc-300 px-4 py-2 text-sm font-medium hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                    >
                        ← Volver a compuestos
                    </Link>
                </div>

                {Object.keys(pageErrors).length > 0 && (
                    <div className="rounded-lg bg-red-100 p-4 text-sm text-red-800 dark:bg-red-900 dark:text-red-200">
                        <div className="mb-2 font-semibold">Hay errores:</div>
                        <ul className="list-disc space-y-1 pl-5">
                            {Object.entries(pageErrors).map(([key, value]) => (
                                <li key={key}>{value}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="border-b border-zinc-200 px-6 py-4 dark:border-neutral-800">
                        <div className="flex flex-wrap gap-2">
                            <button
                                type="button"
                                onClick={() => goStep(1)}
                                className={`rounded-xl px-4 py-2.5 text-sm font-semibold transition ${step === 1 ? 'bg-emerald-600 text-white shadow-sm ring-2 ring-emerald-600/30' : 'border border-zinc-300 text-zinc-700 hover:bg-zinc-50 dark:border-neutral-600 dark:text-zinc-200 dark:hover:bg-neutral-800'}`}
                            >
                                1 · Categoría / Catálogo
                            </button>

                            <button
                                type="button"
                                onClick={() => goStep(2)}
                                className={`rounded-xl px-4 py-2.5 text-sm font-semibold transition ${step === 2 ? 'bg-emerald-600 text-white shadow-sm ring-2 ring-emerald-600/30' : 'border border-zinc-300 text-zinc-700 hover:bg-zinc-50 dark:border-neutral-600 dark:text-zinc-200 dark:hover:bg-neutral-800'}`}
                            >
                                2 · Datos del producto
                            </button>

                            <button
                                type="button"
                                onClick={() => goStep(3)}
                                className={`rounded-xl px-4 py-2.5 text-sm font-semibold transition ${step === 3 ? 'bg-emerald-600 text-white shadow-sm ring-2 ring-emerald-600/30' : 'border border-zinc-300 text-zinc-700 hover:bg-zinc-50 dark:border-neutral-600 dark:text-zinc-200 dark:hover:bg-neutral-800'}`}
                            >
                                3 · Condiciones de venta
                            </button>
                        </div>
                    </div>

                    <form onSubmit={submit} className="space-y-0">
                        {step === 1 && (
                            <section className="space-y-6 p-6">
                                <div>
                                    <h2 className="text-lg font-bold text-zinc-900 dark:text-white">
                                        Paso 1. Categoría / Catálogo
                                    </h2>
                                    <p className="mt-1 text-sm text-zinc-500 dark:text-neutral-400">
                                        Igual que en Mercado Libre: primero eliges la categoría y, si aplica, el producto del catálogo.
                                    </p>
                                </div>

                                <div className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-950">
                                    <h3 className="text-base font-semibold text-zinc-900 dark:text-white">Buscar categoría</h3>
                                    <p className="mt-1 text-xs text-zinc-500 dark:text-neutral-400">
                                        Usa marca, medida o términos como «llanta», «juego», etc.
                                    </p>

                                    <div className="mt-3 flex flex-col gap-2 sm:flex-row">
                                        <input
                                            type="text"
                                            value={catQuery}
                                            onChange={(e) => setCatQuery(e.target.value)}
                                            placeholder="Ej: llanta 285 65 r18"
                                            className="min-w-0 flex-1 rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                        />
                                        <button
                                            type="button"
                                            onClick={searchCategories}
                                            disabled={catLoading}
                                            className="shrink-0 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50"
                                        >
                                            {catLoading ? 'Buscando…' : 'Buscar'}
                                        </button>
                                    </div>

                                    <div className="mt-3 text-sm">
                                        {catResults.length === 0 ? (
                                            <div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs leading-relaxed text-slate-600 dark:border-neutral-700 dark:bg-neutral-900/60 dark:text-slate-300">
                                                {catQuery.trim()
                                                    ? 'Pulsa «Buscar» para cargar categorías sugeridas. Luego elige una fila de la lista.'
                                                    : 'Escribe palabras clave o deja el texto sugerido y pulsa «Buscar».'}
                                            </div>
                                        ) : (
                                            <div className="space-y-2">
                                                {catResults.map((item, idx) => (
                                                    <button
                                                        key={`${mlCategoryId(item) || idx}-${idx}`}
                                                        type="button"
                                                        onClick={() => selectCategory(item)}
                                                        className="block w-full rounded-lg border border-zinc-200 px-3 py-3 text-left hover:bg-zinc-50 dark:border-neutral-800 dark:hover:bg-neutral-900"
                                                    >
                                                        <div className="font-semibold text-zinc-900 dark:text-white">
                                                            {mlCategoryLabel(item)}
                                                        </div>
                                                        <div className="font-mono text-xs text-zinc-500 dark:text-neutral-400">
                                                            ID: {mlCategoryId(item) || '—'}
                                                        </div>
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                    </div>

                                    {selectedCategory && (
                                        <div className="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">
                                            Categoría seleccionada: <span className="font-semibold">{selectedCategory.name}</span> · ID: <span className="font-mono font-bold">{selectedCategory.id}</span>
                                        </div>
                                    )}

                                    {catHint && (
                                        <div className={`mt-3 rounded-lg border p-3 text-sm ${catHint.isCatalog ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200' : 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-200'}`}>
                                            {catHint.text}
                                        </div>
                                    )}

                                    {catErr && (
                                        <div
                                            className={`mt-3 rounded-xl border px-3 py-2 text-sm ${
                                                [
                                                    'Escribe algo para buscar la categoría.',
                                                    'Primero selecciona una categoría.',
                                                ].includes(String(catErr).trim())
                                                    ? 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900 dark:bg-sky-950/50 dark:text-sky-100'
                                                    : 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-100'
                                            }`}
                                        >
                                            {[
                                                'Escribe algo para buscar la categoría.',
                                                'Primero selecciona una categoría.',
                                            ].includes(String(catErr).trim()) && (
                                                <span className="mr-1 font-semibold text-sky-700 dark:text-sky-300">Indicación · </span>
                                            )}
                                            {catErr}
                                        </div>
                                    )}
                                </div>

                                <div className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-950">
                                    <h3 className="text-base font-semibold text-zinc-900 dark:text-white">Catálogo / Buy Box</h3>

                                    <div className="mt-1 text-xs text-zinc-500 dark:text-neutral-400">
                                        Si la categoría usa catálogo, elige un producto. Si no aplica, usa «No encuentro mi opción».
                                    </div>

                                    <div className="mt-4">
                                        <label className="text-xs font-semibold text-zinc-600 dark:text-neutral-300">
                                            ID en catálogo Mercado Libre (opcional)
                                        </label>

                                        <input
                                            type="text"
                                            value={form.data.catalog_product_id}
                                            onChange={(e) => form.setData('catalog_product_id', e.target.value)}
                                            placeholder="Ej: MLPxxxx"
                                            className="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                        />
                                    </div>

                                    <div className="mt-4">
                                        <label className="text-xs font-semibold text-zinc-600 dark:text-neutral-300">
                                            Buscar producto en catálogo
                                        </label>

                                        <div className="mt-2 flex flex-col gap-2 sm:flex-row">
                                            <input
                                                type="text"
                                                value={catalogQuery}
                                                onChange={(e) => setCatalogQuery(e.target.value)}
                                                placeholder="Ej: BLACK MUD 275/70R18"
                                                className="min-w-0 flex-1 rounded-xl border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                            />
                                            <button
                                                type="button"
                                                onClick={searchCatalog}
                                                disabled={catalogLoading}
                                                className="shrink-0 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50"
                                            >
                                                {catalogLoading ? 'Buscando…' : 'Buscar catálogo'}
                                            </button>
                                        </div>

                                        <div className="mt-3 text-sm">
                                            {catalogResults.length === 0 ? (
                                                <div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:border-neutral-700 dark:bg-neutral-900/60 dark:text-slate-300">
                                                    Primero confirma una categoría arriba; luego busca el producto del catálogo.
                                                </div>
                                            ) : (
                                                <div className="space-y-2">
                                                    {catalogResults.map((item, idx) => (
                                                        <button
                                                            key={`${item.catalog_product_id}-${idx}`}
                                                            type="button"
                                                            onClick={() => selectCatalog(item)}
                                                            className="block w-full rounded-lg border border-zinc-200 px-3 py-3 text-left hover:bg-zinc-50 dark:border-neutral-800 dark:hover:bg-neutral-900"
                                                        >
                                                            <div className="font-semibold text-zinc-900 dark:text-white">
                                                                {item.title || item.catalog_product_id}
                                                            </div>
                                                            <div className="text-xs text-zinc-500 dark:text-neutral-400">
                                                                catalog_product_id: {item.catalog_product_id}
                                                            </div>
                                                        </button>
                                                    ))}
                                                </div>
                                            )}
                                        </div>

                                        <div className="mt-3 flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                onClick={continueWithoutCatalog}
                                                className="rounded-lg border border-zinc-300 px-3 py-2 text-sm hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                            >
                                                No encuentro mi opción
                                            </button>

                                            <button
                                                type="button"
                                                onClick={clearCatalog}
                                                className="rounded-lg border border-zinc-300 px-3 py-2 text-sm hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                            >
                                                Limpiar selección de catálogo
                                            </button>
                                        </div>

                                        {catalogErr && (
                                            <div
                                                className={`mt-3 rounded-xl border px-3 py-2 text-sm ${
                                                    [
                                                        'Escribe algo para buscar en el catálogo.',
                                                        'Primero selecciona una categoría.',
                                                    ].includes(String(catalogErr).trim())
                                                        ? 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-900 dark:bg-sky-950/50 dark:text-sky-100'
                                                        : 'border-rose-200 bg-rose-50 text-rose-900 dark:border-rose-900 dark:bg-rose-950/40 dark:text-rose-100'
                                                }`}
                                            >
                                                {[
                                                    'Escribe algo para buscar en el catálogo.',
                                                    'Primero selecciona una categoría.',
                                                ].includes(String(catalogErr).trim()) && (
                                                    <span className="mr-1 font-semibold text-sky-700 dark:text-sky-300">Indicación · </span>
                                                )}
                                                {catalogErr}
                                            </div>
                                        )}

                                        {selectedCatalog && (
                                            <div className="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">
                                                Catálogo seleccionado: <span className="font-semibold">{selectedCatalog.id}</span>
                                            </div>
                                        )}

                                        {form.data.catalog_mode === 'no_catalog' && !form.data.catalog_product_id && (
                                            <div className="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                                                Continuarás sin catálogo.
                                            </div>
                                        )}
                                    </div>
                                </div>

                                <div className="flex justify-end gap-2 border-t border-zinc-100 pt-4 dark:border-neutral-800">
                                    <button
                                        type="button"
                                        onClick={() => goStep(2)}
                                        className="rounded-xl bg-emerald-600 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-emerald-700"
                                    >
                                        Continuar a datos del producto →
                                    </button>
                                </div>
                            </section>
                        )}

                        {step === 2 && (
                            <section className="space-y-6 p-6">
                                <div>
                                    <h2 className="text-lg font-bold text-zinc-900 dark:text-white">
                                        Paso 2. Datos del producto
                                    </h2>
                                </div>

                                <div className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-950">
                                    <div className="text-sm font-semibold text-zinc-900 dark:text-white">
                                        Características principales
                                    </div>

                                    <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <Field label="Marca">
                                            <input type="text" value={form.data.brand} onChange={(e) => form.setData('brand', e.target.value)} className={inputCls()} />
                                        </Field>

                                        <Field label="Modelo">
                                            <input type="text" value={form.data.model} onChange={(e) => form.setData('model', e.target.value)} className={inputCls()} />
                                        </Field>

                                        <Field label="Índice de carga">
                                            <input type="text" value={form.data.load_index} onChange={(e) => form.setData('load_index', e.target.value)} className={inputCls()} />
                                        </Field>

                                        <Field label="Cantidad de llantas">
                                            <input type="number" min="2" max="4" value={form.data.tire_quantity} onChange={(e) => form.setData('tire_quantity', e.target.value)} className={inputCls()} disabled />
                                        </Field>

                                        <Field label="Línea">
                                            <input type="text" value={form.data.line} onChange={(e) => form.setData('line', e.target.value)} className={inputCls()} />
                                        </Field>

                                        <Field label="Lateral">
                                            <input type="text" value={form.data.sidewall} onChange={(e) => form.setData('sidewall', e.target.value)} className={inputCls()} />
                                        </Field>

                                        <Field label="Tipo de servicio">
                                            <select value={form.data.service_type} onChange={(e) => form.setData('service_type', e.target.value)} className={inputCls()}>
                                                <option value="">Selecciona</option>
                                                <option value="P">P</option>
                                                <option value="LT">LT</option>
                                                <option value="T">T</option>
                                                <option value="ST">ST</option>
                                            </select>
                                        </Field>

                                        <Field label="¿Es run flat?">
                                            <select value={form.data.run_flat} onChange={(e) => form.setData('run_flat', e.target.value)} className={inputCls()}>
                                                <option value="0">No</option>
                                                <option value="1">Sí</option>
                                            </select>
                                        </Field>
                                    </div>
                                </div>

                                <div className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-950">
                                    <div className="text-sm font-semibold text-zinc-900 dark:text-white">
                                        Empaque / medidas del paquete
                                    </div>

                                    <div className="mt-4 grid grid-cols-2 gap-4 md:grid-cols-4">
                                        <Field label="Ancho (cm)">
                                            <input type="number" step="0.01" value={form.data.package_width_cm} onChange={(e) => form.setData('package_width_cm', e.target.value)} className={inputCls()} />
                                        </Field>
                                        <Field label="Alto (cm)">
                                            <input type="number" step="0.01" value={form.data.package_height_cm} onChange={(e) => form.setData('package_height_cm', e.target.value)} className={inputCls()} />
                                        </Field>
                                        <Field label="Profundidad / largo (cm)">
                                            <input type="number" step="0.01" value={form.data.package_length_cm} onChange={(e) => form.setData('package_length_cm', e.target.value)} className={inputCls()} />
                                        </Field>
                                        <Field label="Peso (kg)">
                                            <input type="number" step="0.01" value={form.data.package_weight_kg} onChange={(e) => form.setData('package_weight_kg', e.target.value)} className={inputCls()} />
                                        </Field>
                                    </div>
                                </div>

                                <div className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-950">
                                    <div className="text-sm font-semibold text-zinc-900 dark:text-white">
                                        Condición
                                    </div>

                                    <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                                        <Radio name="condition" value="new" current={form.data.condition} onChange={(val) => form.setData('condition', val)} label="Nueva" />
                                        <Radio name="condition" value="used" current={form.data.condition} onChange={(val) => form.setData('condition', val)} label="Usada" />
                                        <Radio name="condition" value="not_specified" current={form.data.condition} onChange={(val) => form.setData('condition', val)} label="No especificar" />
                                    </div>
                                </div>

                                <div className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-950">
                                    <div className="text-sm font-semibold text-zinc-900 dark:text-white">
                                        Variantes y fotos
                                    </div>

                                    <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <Field label="Índice de velocidad">
                                            <input type="text" value={form.data.speed_rating} onChange={(e) => form.setData('speed_rating', e.target.value)} className={inputCls()} />
                                        </Field>

                                        <Field label="Stock a publicar">
                                            <input type="number" min="1" max="9999" value={form.data.stock_input} onChange={(e) => form.setData('stock_input', e.target.value)} className={inputCls()} />
                                        </Field>

                                        <Field label="GTIN / Código universal">
                                            <input type="text" value={form.data.gtin} onChange={(e) => form.setData('gtin', e.target.value)} className={inputCls()} />
                                        </Field>

                                        <Field label="SKU">
                                            <input type="text" value={form.data.seller_sku} onChange={(e) => form.setData('seller_sku', e.target.value)} className={inputCls()} />
                                        </Field>
                                    </div>

                                    <div className="mt-5">
                                        <label className="text-xs font-semibold text-zinc-600 dark:text-neutral-300">
                                            Subir imágenes
                                        </label>

                                        <input
                                            type="file"
                                            multiple
                                            accept="image/jpeg,image/png,image/webp"
                                            onChange={handleFileChange}
                                            className="mt-2 block w-full text-sm text-zinc-700 dark:text-neutral-200"
                                        />
                                    </div>

                                    <div className="mt-4">
                                        <label className="text-xs font-semibold text-zinc-600 dark:text-neutral-300">
                                            Imágenes por URL (opcional)
                                        </label>

                                        <div className="mt-1 grid grid-cols-1 gap-2 md:grid-cols-2">
                                            {form.data.pictures_urls.map((url, index) => (
                                                <input
                                                    key={index}
                                                    type="text"
                                                    value={url}
                                                    onChange={(e) => updatePictureUrl(index, e.target.value)}
                                                    placeholder="https://....jpg"
                                                    className={inputCls()}
                                                />
                                            ))}
                                        </div>
                                    </div>
                                </div>

                                <div className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-950">
                                    <div className="text-sm font-semibold text-zinc-900 dark:text-white">
                                        Título
                                    </div>
                                    <input
                                        type="text"
                                        maxLength="60"
                                        value={form.data.title}
                                        onChange={(e) => form.setData('title', e.target.value)}
                                        className={`${inputCls()} mt-3`}
                                    />
                                </div>

                                <div className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-950">
                                    <div className="text-sm font-semibold text-zinc-900 dark:text-white">
                                        Características secundarias
                                    </div>

                                    <div className="mt-4 grid grid-cols-2 gap-4 md:grid-cols-4">
                                        <Field label="Relación de aspecto">
                                            <input type="number" step="0.01" value={form.data.aspect_ratio} onChange={(e) => form.setData('aspect_ratio', e.target.value)} className={inputCls()} />
                                        </Field>
                                        <Field label="Ancho de sección (mm)">
                                            <input type="number" step="0.01" value={form.data.section_width} onChange={(e) => form.setData('section_width', e.target.value)} className={inputCls()} />
                                        </Field>
                                        <Field label="Diámetro del rin">
                                            <input type="number" step="0.01" value={form.data.rim_diameter} onChange={(e) => form.setData('rim_diameter', e.target.value)} className={inputCls()} />
                                        </Field>
                                        <Field label="UTQG">
                                            <input type="text" value={form.data.utqg} onChange={(e) => form.setData('utqg', e.target.value)} className={inputCls()} />
                                        </Field>
                                        <Field label="Rango de cargas">
                                            <input type="text" value={form.data.load_range} onChange={(e) => form.setData('load_range', e.target.value)} className={inputCls()} />
                                        </Field>
                                        <Field label="Tipo de terreno">
                                            <select value={form.data.terrain_type} onChange={(e) => form.setData('terrain_type', e.target.value)} className={inputCls()}>
                                                <option value="">Selecciona</option>
                                                <option value="HT">HT</option>
                                                <option value="AT">AT</option>
                                                <option value="MT">MT</option>
                                                <option value="AS">AS</option>
                                            </select>
                                        </Field>
                                        <Field label="Tipo de construcción">
                                            <select value={form.data.construction_type} onChange={(e) => form.setData('construction_type', e.target.value)} className={inputCls()}>
                                                <option value="">Selecciona</option>
                                                <option value="Radial">Radial</option>
                                                <option value="Diagonal">Diagonal</option>
                                            </select>
                                        </Field>
                                    </div>
                                </div>

                                <div className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-950">
                                    <div className="text-sm font-semibold text-zinc-900 dark:text-white">
                                        Descripción
                                    </div>
                                    <textarea
                                        rows="8"
                                        value={form.data.description}
                                        onChange={(e) => form.setData('description', e.target.value)}
                                        className={`${inputCls()} mt-3`}
                                        placeholder="Describe el producto..."
                                    />
                                </div>

                                <div className="flex justify-between gap-2">
                                    <button
                                        type="button"
                                        onClick={() => goStep(1)}
                                        className="rounded-lg border border-zinc-300 px-5 py-2 hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                    >
                                        Volver
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() => goStep(3)}
                                        className="rounded-lg bg-emerald-600 px-5 py-2 text-white hover:bg-emerald-700"
                                    >
                                        Continuar a condiciones de venta
                                    </button>
                                </div>
                            </section>
                        )}

                        {step === 3 && (
                            <section className="space-y-6 p-6">
                                <div>
                                    <h2 className="text-lg font-bold text-zinc-900 dark:text-white">
                                        Paso 3. Condiciones de venta
                                    </h2>
                                </div>

                                <div className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-950">
                                    <div className="text-sm font-semibold text-zinc-900 dark:text-white">
                                        Tienda oficial
                                    </div>

                                    <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                                        <Radio name="official_store_mode" value="marketmax" current={form.data.official_store_mode} onChange={(val) => form.setData('official_store_mode', val)} label="MARKETMAX" />
                                        <Radio name="official_store_mode" value="tobeauty" current={form.data.official_store_mode} onChange={(val) => form.setData('official_store_mode', val)} label="TOBEAUTY" />
                                        <Radio name="official_store_mode" value="none" current={form.data.official_store_mode} onChange={(val) => form.setData('official_store_mode', val)} label="Publicar fuera de tienda oficial" />
                                    </div>
                                </div>

                                <div className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-950">
                                    <div className="text-sm font-semibold text-zinc-900 dark:text-white">
                                        Garantía
                                    </div>

                                    <div className="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
                                        <Field label="Tipo de garantía">
                                            <select value={form.data.warranty_type} onChange={(e) => form.setData('warranty_type', e.target.value)} className={inputCls()}>
                                                <option value="seller">Garantía del vendedor</option>
                                                <option value="factory">Garantía de fábrica</option>
                                                <option value="none">Sin garantía</option>
                                            </select>
                                        </Field>

                                        <Field label="Tiempo">
                                            <input type="number" min="1" max="120" value={form.data.warranty_time_value} onChange={(e) => form.setData('warranty_time_value', e.target.value)} className={inputCls()} />
                                        </Field>

                                        <Field label="Unidad">
                                            <select value={form.data.warranty_time_unit} onChange={(e) => form.setData('warranty_time_unit', e.target.value)} className={inputCls()}>
                                                <option value="days">Días</option>
                                                <option value="months">Meses</option>
                                                <option value="years">Años</option>
                                            </select>
                                        </Field>
                                    </div>
                                </div>

                                <div className="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-950">
                                    <div className="text-sm font-semibold text-zinc-900 dark:text-white">
                                        Tipo de publicación
                                    </div>

                                    <div className="mt-4">
                                        <select
                                            value={form.data.listing_type_id}
                                            onChange={(e) => form.setData('listing_type_id', e.target.value)}
                                            className={inputCls()}
                                        >
                                            <option value="gold_special">gold_special</option>
                                            <option value="gold_pro">gold_pro</option>
                                        </select>
                                    </div>
                                </div>

                                <div className="flex justify-between gap-2">
                                    <button
                                        type="button"
                                        onClick={() => goStep(2)}
                                        className="rounded-lg border border-zinc-300 px-5 py-2 hover:bg-zinc-50 dark:border-neutral-700 dark:hover:bg-neutral-800"
                                    >
                                        Volver
                                    </button>

                                    <button
                                        type="submit"
                                        disabled={form.processing}
                                        className="rounded-lg bg-emerald-600 px-5 py-2 text-white hover:bg-emerald-700 disabled:opacity-50"
                                    >
                                        {form.processing ? 'Publicando...' : 'Publicar en Mercado Libre'}
                                    </button>
                                </div>
                            </section>
                        )}
                    </form>
                </div>
            </div>
        </>
    )
}

function Field({ label, children }) {
    return (
        <div>
            <label className="text-xs font-semibold text-zinc-600 dark:text-neutral-300">
                {label}
            </label>
            <div className="mt-1">{children}</div>
        </div>
    )
}

function Radio({ name, value, current, onChange, label }) {
    return (
        <label className="inline-flex items-center gap-2 text-sm">
            <input
                type="radio"
                name={name}
                value={value}
                checked={current === value}
                onChange={() => onChange(value)}
            />
            <span>{label}</span>
        </label>
    )
}

function inputCls() {
    return 'w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white'
}