import AppShell from '@/Components/layout/AppShell'
import { Head, Link, router, useForm } from '@inertiajs/react'
import { useMemo, useState } from 'react'

const fieldClass =
    'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 dark:border-neutral-700 dark:bg-neutral-950 dark:text-white'
const secondaryButton =
    'rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-40 dark:border-neutral-700 dark:text-slate-200 dark:hover:bg-neutral-800'
const primaryButton =
    'rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-40'

function ErrorText({ message }) {
    return message ? <p className="mt-1 text-xs font-semibold text-rose-600 dark:text-rose-300">{message}</p> : null
}

function confidence(value) {
    if (value === null || value === undefined || value === '') return '—'
    return `${Math.round(Number(value) * 100)}%`
}

function money(value, currency = 'MXN') {
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: currency || 'MXN' }).format(Number(value || 0))
}

function Dialog({ title, children, onClose }) {
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4" role="dialog" aria-modal="true">
            <div className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl dark:bg-neutral-900">
                <div className="mb-4 flex items-center justify-between gap-3">
                    <h2 className="text-xl font-bold">{title}</h2>
                    <button type="button" onClick={onClose} className={secondaryButton}>Cerrar</button>
                </div>
                {children}
            </div>
        </div>
    )
}

export default function Uncategorized({
    accounts = [],
    selectedAccountId = null,
    items = { data: [], links: [] },
    brands = [],
    counts = {},
    filters = {},
    matchTypes = {},
}) {
    const [filterData, setFilterData] = useState({
        search: filters.search ?? '',
        sku: filters.sku ?? '',
        meli_item_id: filters.meli_item_id ?? '',
        meli_brand: filters.meli_brand ?? '',
        category_id: filters.category_id ?? '',
        classification_status: filters.classification_status ?? 'pending',
        min_price: filters.min_price ?? '',
        max_price: filters.max_price ?? '',
        per_page: filters.per_page ?? 25,
    })
    const [selectedIds, setSelectedIds] = useState([])
    const [dialog, setDialog] = useState(null)
    const [bulkAction, setBulkAction] = useState('assign')
    const [bulkBrandId, setBulkBrandId] = useState('')

    const assignForm = useForm({ meli_account_id: selectedAccountId, brand_group_id: '' })
    const aliasForm = useForm({
        meli_account_id: selectedAccountId,
        brand_group_id: '',
        alias: '',
        match_type: 'exact',
        priority: 0,
        active: true,
        confirm_conflict: false,
    })
    const brandForm = useForm({
        meli_account_id: selectedAccountId,
        name: '',
        slug: '',
        description: '',
        active: true,
        sort_order: 0,
        create_alias: true,
        alias: '',
        match_type: 'exact',
        alias_priority: 0,
        alias_active: true,
        confirm_conflict: false,
    })

    const selectedItems = useMemo(
        () => items.data.filter((item) => selectedIds.includes(item.id)),
        [items.data, selectedIds],
    )
    const allPageSelected = items.data.length > 0 && items.data.every((item) => selectedIds.includes(item.id))

    const visitFilters = (overrides = {}) => {
        router.get(
            '/meli-price-manager/uncategorized',
            { account: selectedAccountId, ...filterData, ...overrides },
            { preserveState: true, preserveScroll: true, replace: true, onSuccess: () => setSelectedIds([]) },
        )
    }

    const openAssign = (item) => {
        assignForm.clearErrors()
        assignForm.setData({ meli_account_id: selectedAccountId, brand_group_id: item.suggested_brand_group_id ?? '' })
        setDialog({ type: 'assign', item })
    }

    const openAlias = (item) => {
        aliasForm.clearErrors()
        aliasForm.setData({
            meli_account_id: selectedAccountId,
            brand_group_id: item.suggested_brand_group_id ?? '',
            alias: item.meli_brand ?? '',
            match_type: 'exact',
            priority: 0,
            active: true,
            confirm_conflict: false,
        })
        setDialog({ type: 'alias', item })
    }

    const openBrand = (item) => {
        brandForm.clearErrors()
        brandForm.setData({
            meli_account_id: selectedAccountId,
            name: item.meli_brand ?? '',
            slug: '',
            description: '',
            active: true,
            sort_order: 0,
            create_alias: Boolean(item.meli_brand),
            alias: item.meli_brand ?? '',
            match_type: 'exact',
            alias_priority: 0,
            alias_active: true,
            confirm_conflict: false,
        })
        setDialog({ type: 'brand', item })
    }

    const acceptSuggestion = (item) => {
        if (!window.confirm(`Aceptar la sugerencia ${item.suggested_brand_group?.name}?`)) return
        router.post(`/meli-price-manager/items/${item.id}/suggestion/accept`, { meli_account_id: selectedAccountId }, { preserveScroll: true })
    }

    const ignoreItem = (item) => {
        if (!window.confirm(`Ignorar "${item.title}"? Podrás restaurarla desde la vista de ignoradas.`)) return
        router.post(`/meli-price-manager/items/${item.id}/ignore`, { meli_account_id: selectedAccountId, confirm: true }, { preserveScroll: true })
    }

    const restoreItem = (item) => {
        router.post(`/meli-price-manager/items/${item.id}/restore`, { meli_account_id: selectedAccountId }, { preserveScroll: true })
    }

    const submitBulk = () => {
        if (selectedIds.length === 0) return
        if (bulkAction === 'assign' && !bulkBrandId) return
        if (bulkAction === 'accept_suggestions' && selectedItems.some((item) => item.classification_status !== 'suggested' || !item.suggested_brand_group_id)) {
            window.alert('Todas las publicaciones seleccionadas deben tener una sugerencia válida.')
            return
        }
        const labels = {
            assign: `asignar ${selectedIds.length} publicaciones`,
            accept_suggestions: `aceptar ${selectedIds.length} sugerencias`,
            ignore: `ignorar ${selectedIds.length} publicaciones`,
            restore: `restaurar ${selectedIds.length} publicaciones`,
        }
        if (!window.confirm(`Vas a ${labels[bulkAction]}. ¿Continuar?`)) return

        router.post('/meli-price-manager/uncategorized/bulk', {
            meli_account_id: selectedAccountId,
            item_ids: selectedIds,
            action: bulkAction,
            brand_group_id: bulkAction === 'assign' ? bulkBrandId : null,
            confirm: true,
        }, { preserveScroll: true, onSuccess: () => setSelectedIds([]) })
    }

    return (
        <AppShell title="Meli Price Manager">
            <Head title="Pendientes de clasificación · Meli Price Manager" />
            <div className="space-y-5">
                <header className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
                    <div>
                        <p className="text-xs font-bold uppercase tracking-[0.2em] text-indigo-600 dark:text-indigo-300">Meli Price Manager</p>
                        <h1 className="mt-1 text-3xl font-bold">Pendientes de clasificación</h1>
                        <p className="mt-2 text-sm text-slate-500">Revisa sugerencias y asigna marcas sin cambiar precios, stock ni la marca original de Mercado Libre.</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Link href={`/meli-price-manager/brands?account=${selectedAccountId ?? ''}`} className={secondaryButton}>Marcas y alias</Link>
                        <Link href={`/meli-price-manager/brands?account=${selectedAccountId ?? ''}`} className={primaryButton}>Vista previa de reclasificación</Link>
                    </div>
                </header>

                <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {[
                        ['pending', 'Pendientes'],
                        ['uncategorized', 'Sin categorizar'],
                        ['suggested', 'Sugeridas'],
                        ['ignored', 'Ignoradas'],
                    ].map(([status, label]) => (
                        <button key={status} type="button" onClick={() => { setFilterData((current) => ({ ...current, classification_status: status })); visitFilters({ classification_status: status, page: 1 }) }} className={`rounded-2xl border p-4 text-left ${filterData.classification_status === status ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-500/10' : 'border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900'}`}>
                            <p className="text-xs font-bold uppercase tracking-wide text-slate-500">{label}</p>
                            <p className="mt-1 text-3xl font-bold">{counts[status] ?? 0}</p>
                        </button>
                    ))}
                </section>

                <section className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                    <form onSubmit={(event) => { event.preventDefault(); visitFilters({ page: 1 }) }} className="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
                        <label className="xl:col-span-2"><span className="mb-1 block text-xs font-bold">Cuenta</span><select value={selectedAccountId ?? ''} onChange={(event) => router.get('/meli-price-manager/uncategorized', { account: event.target.value }, { preserveState: false })} disabled={!accounts.length} className={fieldClass}>{!accounts.length && <option value="">Sin cuentas</option>}{accounts.map((account) => <option key={account.id} value={account.id}>{account.nickname || `Cuenta #${account.id}`}{account.is_default ? ' · predeterminada' : ''}</option>)}</select></label>
                        <label className="xl:col-span-2"><span className="mb-1 block text-xs font-bold">Buscar título, SKU, MLM o marca</span><input value={filterData.search} onChange={(event) => setFilterData({ ...filterData, search: event.target.value })} className={fieldClass} /></label>
                        <label><span className="mb-1 block text-xs font-bold">Estado</span><select value={filterData.classification_status} onChange={(event) => setFilterData({ ...filterData, classification_status: event.target.value })} className={fieldClass}><option value="pending">Todos pendientes</option><option value="uncategorized">Sin categorizar</option><option value="suggested">Sugeridos</option><option value="ignored">Ignorados</option></select></label>
                        <label><span className="mb-1 block text-xs font-bold">Categoría ML</span><input value={filterData.category_id} onChange={(event) => setFilterData({ ...filterData, category_id: event.target.value })} className={fieldClass} /></label>
                        <label><span className="mb-1 block text-xs font-bold">SKU</span><input value={filterData.sku} onChange={(event) => setFilterData({ ...filterData, sku: event.target.value })} className={fieldClass} /></label>
                        <label><span className="mb-1 block text-xs font-bold">MLM</span><input value={filterData.meli_item_id} onChange={(event) => setFilterData({ ...filterData, meli_item_id: event.target.value })} className={fieldClass} /></label>
                        <label><span className="mb-1 block text-xs font-bold">Marca ML</span><input value={filterData.meli_brand} onChange={(event) => setFilterData({ ...filterData, meli_brand: event.target.value })} className={fieldClass} /></label>
                        <label><span className="mb-1 block text-xs font-bold">Precio mínimo</span><input type="number" min="0" step="0.01" value={filterData.min_price} onChange={(event) => setFilterData({ ...filterData, min_price: event.target.value })} className={fieldClass} /></label>
                        <label><span className="mb-1 block text-xs font-bold">Precio máximo</span><input type="number" min="0" step="0.01" value={filterData.max_price} onChange={(event) => setFilterData({ ...filterData, max_price: event.target.value })} className={fieldClass} /></label>
                        <div className="flex items-end gap-2"><button className={primaryButton}>Filtrar</button><button type="button" onClick={() => router.get('/meli-price-manager/uncategorized', { account: selectedAccountId })} className={secondaryButton}>Limpiar</button></div>
                    </form>
                </section>

                {selectedIds.length > 0 && (
                    <section className="flex flex-col gap-3 rounded-2xl border border-indigo-200 bg-indigo-50 p-4 lg:flex-row lg:items-end dark:border-indigo-500/30 dark:bg-indigo-500/10">
                        <p className="mr-auto self-center font-bold">{selectedIds.length} seleccionadas</p>
                        <label><span className="mb-1 block text-xs font-bold">Acción masiva</span><select value={bulkAction} onChange={(event) => setBulkAction(event.target.value)} className={fieldClass}><option value="assign">Asignar marca</option><option value="accept_suggestions">Aceptar sus sugerencias</option><option value="ignore">Ignorar</option><option value="restore">Volver a pendientes</option></select></label>
                        {bulkAction === 'assign' && <label><span className="mb-1 block text-xs font-bold">Marca activa</span><select value={bulkBrandId} onChange={(event) => setBulkBrandId(event.target.value)} className={fieldClass}><option value="">Seleccionar</option>{brands.map((brand) => <option key={brand.id} value={brand.id}>{brand.name}</option>)}</select></label>}
                        <button type="button" onClick={submitBulk} className={primaryButton}>Aplicar</button>
                    </section>
                )}

                <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="overflow-x-auto">
                        <table className="min-w-[1450px] text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-neutral-950">
                                <tr><th className="px-3 py-3"><input type="checkbox" checked={allPageSelected} onChange={() => setSelectedIds(allPageSelected ? [] : items.data.map((item) => item.id))} /></th><th className="px-3 py-3">Imagen</th><th className="px-3 py-3">Publicación</th><th className="px-3 py-3">Marca ML</th><th className="px-3 py-3">Clasificación</th><th className="px-3 py-3">Sugerencia</th><th className="px-3 py-3">Categoría</th><th className="px-3 py-3">Precio / stock</th><th className="px-3 py-3">Acciones</th></tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100 dark:divide-neutral-800">
                                {!items.data.length && <tr><td colSpan="9" className="px-5 py-12 text-center text-slate-500">No hay publicaciones para estos filtros.</td></tr>}
                                {items.data.map((item) => (
                                    <tr key={item.id} className="align-top">
                                        <td className="px-3 py-4"><input type="checkbox" checked={selectedIds.includes(item.id)} onChange={() => setSelectedIds((current) => current.includes(item.id) ? current.filter((id) => id !== item.id) : [...current, item.id])} /></td>
                                        <td className="px-3 py-4">{item.thumbnail ? <img src={item.thumbnail} alt="" className="h-14 w-14 rounded-lg object-cover" referrerPolicy="no-referrer" /> : <div className="flex h-14 w-14 items-center justify-center rounded-lg bg-slate-100 text-xs text-slate-400 dark:bg-neutral-800">Sin imagen</div>}</td>
                                        <td className="max-w-sm px-3 py-4"><p className="font-semibold">{item.title}</p><p className="mt-1 text-xs text-slate-500">SKU: {item.sku || '—'} · {item.meli_item_id}</p>{item.permalink && <a href={item.permalink} target="_blank" rel="noreferrer" className="mt-1 inline-block text-xs font-semibold text-indigo-600">Abrir en Mercado Libre ↗</a>}</td>
                                        <td className="px-3 py-4"><p className="font-semibold">{item.meli_brand || 'Sin marca'}</p><p className="mt-1 font-mono text-xs text-slate-500">{item.normalized_brand || '—'}</p></td>
                                        <td className="px-3 py-4"><span className={`rounded-full px-2 py-1 text-xs font-bold ${item.classification_status === 'suggested' ? 'bg-amber-100 text-amber-800' : item.classification_status === 'ignored' ? 'bg-slate-200 text-slate-700' : 'bg-rose-100 text-rose-800'}`}>{item.classification_status}</span><p className="mt-2 text-xs">{item.classification_source || 'Sin fuente'} · {confidence(item.classification_confidence)}</p></td>
                                        <td className="max-w-xs px-3 py-4"><p className="font-semibold">{item.suggested_brand_group?.name || '—'}</p>{item.matched_brand_alias && <p className="mt-1 text-xs text-slate-500">Alias: {item.matched_brand_alias.alias} ({item.matched_brand_alias.match_type})</p>}{item.classification_metadata && <details className="mt-2 text-xs"><summary className="cursor-pointer font-semibold text-indigo-600">Auditoría</summary><pre className="mt-1 max-h-32 overflow-auto whitespace-pre-wrap rounded bg-slate-50 p-2 dark:bg-neutral-950">{JSON.stringify(item.classification_metadata, null, 2)}</pre></details>}</td>
                                        <td className="px-3 py-4"><p>{item.category?.name || item.category_id || '—'}</p>{item.category?.name && <p className="text-xs text-slate-500">{item.category_id}</p>}</td>
                                        <td className="px-3 py-4"><p className="font-semibold">{money(item.current_price, item.currency_id)}</p><p className="text-xs text-slate-500">Stock: {item.available_quantity ?? '—'}</p></td>
                                        <td className="px-3 py-4"><div className="flex max-w-xs flex-wrap gap-2">{item.classification_status === 'suggested' && item.suggested_brand_group_id && <button type="button" onClick={() => acceptSuggestion(item)} className={primaryButton}>Aceptar sugerencia</button>}<button type="button" onClick={() => openAssign(item)} className={secondaryButton}>Elegir marca</button><button type="button" onClick={() => openAlias(item)} className={secondaryButton}>Crear alias</button><button type="button" onClick={() => openBrand(item)} className={secondaryButton}>Nueva marca</button>{item.classification_status === 'ignored' ? <button type="button" onClick={() => restoreItem(item)} className={secondaryButton}>Volver a pendientes</button> : <button type="button" onClick={() => ignoreItem(item)} className="rounded-xl border border-rose-200 px-3 py-2 text-sm font-semibold text-rose-700">Ignorar</button>}</div></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {items.links?.length > 0 && <div className="flex flex-wrap gap-2 border-t border-slate-200 p-4 dark:border-neutral-800">{items.links.map((link, index) => <Link key={index} href={link.url ?? '#'} preserveScroll className={`rounded-lg px-3 py-2 text-sm ${link.active ? 'bg-indigo-600 text-white' : 'border border-slate-200 dark:border-neutral-700'} ${!link.url ? 'pointer-events-none opacity-40' : ''}`} dangerouslySetInnerHTML={{ __html: link.label }} />)}</div>}
                </section>
            </div>

            {dialog?.type === 'assign' && <Dialog title="Asignar marca" onClose={() => setDialog(null)}><form onSubmit={(event) => { event.preventDefault(); assignForm.post(`/meli-price-manager/items/${dialog.item.id}/assign`, { preserveScroll: true, onSuccess: () => setDialog(null) }) }} className="space-y-4"><p className="text-sm text-slate-500">La asignación será manual y quedará protegida del clasificador automático.</p><label><span className="mb-1 block text-sm font-bold">Marca activa</span><select value={assignForm.data.brand_group_id} onChange={(event) => assignForm.setData('brand_group_id', event.target.value)} className={fieldClass}><option value="">Seleccionar</option>{brands.map((brand) => <option key={brand.id} value={brand.id}>{brand.name}</option>)}</select><ErrorText message={assignForm.errors.brand_group_id || assignForm.errors.item} /></label><button disabled={assignForm.processing} className={primaryButton}>Asignar publicación</button></form></Dialog>}

            {dialog?.type === 'alias' && <Dialog title="Crear alias y asignar" onClose={() => setDialog(null)}><form onSubmit={(event) => { event.preventDefault(); aliasForm.post(`/meli-price-manager/items/${dialog.item.id}/alias-and-assign`, { preserveScroll: true, onSuccess: () => setDialog(null) }) }} className="grid gap-4 md:grid-cols-2"><label><span className="mb-1 block text-sm font-bold">Marca destino</span><select value={aliasForm.data.brand_group_id} onChange={(event) => aliasForm.setData('brand_group_id', event.target.value)} className={fieldClass}><option value="">Seleccionar</option>{brands.map((brand) => <option key={brand.id} value={brand.id}>{brand.name}</option>)}</select><ErrorText message={aliasForm.errors.brand_group_id} /></label><label><span className="mb-1 block text-sm font-bold">Alias</span><input value={aliasForm.data.alias} onChange={(event) => aliasForm.setData('alias', event.target.value)} className={fieldClass} /><ErrorText message={aliasForm.errors.alias || aliasForm.errors.normalized_alias} /></label><label><span className="mb-1 block text-sm font-bold">Tipo</span><select value={aliasForm.data.match_type} onChange={(event) => aliasForm.setData('match_type', event.target.value)} className={fieldClass}>{Object.entries(matchTypes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label><label><span className="mb-1 block text-sm font-bold">Prioridad</span><input type="number" min="0" max="1000" value={aliasForm.data.priority} onChange={(event) => aliasForm.setData('priority', event.target.value)} className={fieldClass} /><ErrorText message={aliasForm.errors.priority} /></label>{aliasForm.errors.confirm_conflict && <label className="md:col-span-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm"><ErrorText message={aliasForm.errors.confirm_conflict} /><span className="mt-2 flex gap-2"><input type="checkbox" checked={aliasForm.data.confirm_conflict} onChange={(event) => aliasForm.setData('confirm_conflict', event.target.checked)} /> Comprendo el riesgo y deseo continuar.</span></label>}<label className="flex items-center gap-2"><input type="checkbox" checked={aliasForm.data.active} onChange={(event) => aliasForm.setData('active', event.target.checked)} /> Alias activo</label><div className="md:col-span-2 flex justify-end"><button disabled={aliasForm.processing} className={primaryButton}>Crear o reutilizar alias y asignar</button></div></form></Dialog>}

            {dialog?.type === 'brand' && <Dialog title="Crear nueva marca" onClose={() => setDialog(null)}><form onSubmit={(event) => { event.preventDefault(); brandForm.post(`/meli-price-manager/items/${dialog.item.id}/brand-and-assign`, { preserveScroll: true, onSuccess: () => setDialog(null) }) }} className="grid gap-4 md:grid-cols-2"><label><span className="mb-1 block text-sm font-bold">Nombre</span><input value={brandForm.data.name} onChange={(event) => brandForm.setData('name', event.target.value)} className={fieldClass} /><ErrorText message={brandForm.errors.name} /></label><label><span className="mb-1 block text-sm font-bold">Slug opcional</span><input value={brandForm.data.slug} onChange={(event) => brandForm.setData('slug', event.target.value)} placeholder="Se genera desde el nombre" className={fieldClass} /><ErrorText message={brandForm.errors.slug} /></label><label className="md:col-span-2"><span className="mb-1 block text-sm font-bold">Descripción</span><textarea value={brandForm.data.description} onChange={(event) => brandForm.setData('description', event.target.value)} className={fieldClass} /></label><label className="flex items-center gap-2"><input type="checkbox" checked={brandForm.data.active} onChange={(event) => brandForm.setData('active', event.target.checked)} /> Marca activa</label><label className="flex items-center gap-2"><input type="checkbox" checked={brandForm.data.create_alias} onChange={(event) => brandForm.setData('create_alias', event.target.checked)} /> Crear alias desde la marca ML</label>{brandForm.data.create_alias && <><label><span className="mb-1 block text-sm font-bold">Alias</span><input value={brandForm.data.alias} onChange={(event) => brandForm.setData('alias', event.target.value)} className={fieldClass} /><ErrorText message={brandForm.errors.alias || brandForm.errors.normalized_alias} /></label><label><span className="mb-1 block text-sm font-bold">Tipo</span><select value={brandForm.data.match_type} onChange={(event) => brandForm.setData('match_type', event.target.value)} className={fieldClass}>{Object.entries(matchTypes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label></>}{brandForm.errors.confirm_conflict && <label className="md:col-span-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm"><ErrorText message={brandForm.errors.confirm_conflict} /><span className="mt-2 flex gap-2"><input type="checkbox" checked={brandForm.data.confirm_conflict} onChange={(event) => brandForm.setData('confirm_conflict', event.target.checked)} /> Comprendo el riesgo y deseo continuar.</span></label>}<div className="md:col-span-2 flex justify-end"><button disabled={brandForm.processing} className={primaryButton}>Crear marca y asignar</button></div></form></Dialog>}
        </AppShell>
    )
}
