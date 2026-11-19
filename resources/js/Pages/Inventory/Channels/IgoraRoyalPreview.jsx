import AppShell from '@/Components/layout/AppShell'
import Pagination from '@/Components/ui/Pagination'
import { Head, router } from '@inertiajs/react'
import { useState } from 'react'

const confidenceBadge = {
    HIGH: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800',
    MEDIUM: 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800',
    LOW: 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:border-rose-800',
}

const presentationBadge = {
    SINGLE_DYE: 'bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-950/40 dark:text-indigo-300 dark:border-indigo-800',
    DYE_WITH_PEROXIDE: 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-950/40 dark:text-purple-300 dark:border-purple-800',
    MULTIPACK: 'bg-sky-50 text-sky-700 border-sky-200 dark:bg-sky-950/40 dark:text-sky-300 dark:border-sky-800',
    STANDALONE_PEROXIDE: 'bg-teal-50 text-teal-700 border-teal-200 dark:bg-teal-950/40 dark:text-teal-300 dark:border-teal-800',
    UNKNOWN_PRESENTATION: 'bg-slate-100 text-slate-700 border-slate-300 dark:bg-neutral-800 dark:text-neutral-300 dark:border-neutral-700',
}

const conflictLabels = {
    LINE_CONFLICT_ROYAL_VS_VIBRANCE: 'Conflicto Royal vs Vibrance',
    LINE_AMBIGUOUS: 'Línea ambigua',
    TONE_NOT_DETECTED: 'Tono no detectado',
    MULTIPLE_TONES_IN_SINGLE_LISTING: 'Múltiples tonos en título sin variaciones',
    TONE_MISMATCH_ATTRIBUTE_VS_TITLE: 'Tono distinto entre atributo y título',
    MISSING_SKU: 'Sin SKU',
    PEROXIDE_COMPOSITION_INCOMPLETE: 'Peróxido incompleto (concentración/vol/cant)',
    AMBIGUOUS_KIT_PRESENTATION: 'Kit sin especificar peróxido/piezas',
    KIT_ATTRIBUTES_CONTRADICTORY: 'Atributos de kit/activador contradictorios',
    MULTIPACK_QUANTITY_AMBIGUOUS: 'Cantidad de multipack ambigua',
    INVENTORY_LINE_MISMATCH: 'Producto Inventory de otra línea',
    SKU_TONE_MISMATCH: 'SKU coincide con producto de otro tono',
    SKU_GTIN_MISMATCH_SUSPECT: 'Posible error de captura entre SKU y GTIN',
    INVENTORY_TONE_UNVERIFIED: 'Tono sin verificar en producto Inventory',
    AMBIGUOUS_INVENTORY_MATCH: 'Múltiples coincidencias en Inventory',
    AMBIGUOUS_PEROXIDE_INVENTORY_MATCH: 'Múltiples peróxidos en Inventory',
    EXISTING_LINK_CONFLICT: 'Vínculo existente apunta a otro producto',
    DUPLICATE_SKU_CROSS_PRESENTATION: 'SKU repetido entre presentaciones distintas',
    DUPLICATE_SKU_CROSS_LINE: 'SKU repetido entre líneas distintas',
    DUPLICATE_SKU_CROSS_TONE: 'SKU repetido entre tonos distintos',
    USER_PRODUCT_SHARED_DIFFERENT_GROUPS: 'User Product compartido entre grupos distintos',
    GROUP_HAS_MULTIPLE_SKUS: 'Múltiples SKUs en el mismo grupo',
    FULFILLMENT_MANAGED_BY_MELI: 'FULL (controlado por Mercado Libre)',
    LEGACY_SHARED_STOCK_ACTIVE: 'En grupo legacy meli_shared_stock activo',
    PUBLICATION_PAUSED: 'Publicación pausada',
    PUBLICATION_MODERATED_OR_DENOUNCED: 'Publicación moderada/denunciada',
    SNAPSHOT_STALE: 'Snapshot desactualizado',
    DATA_INCOMPLETE: 'Datos incompletos',
}

const actionLabels = {
    EXCLUIR_FULL_CONTROLADO_POR_ML: 'Excluir (FULL administrado por ML)',
    RESOLVER_CONFLICTO_USER_PRODUCT: 'Resolver conflicto de User Product ID',
    REVISAR_LINEA_ROYAL_VS_VIBRANCE: 'Revisar línea (Royal vs Vibrance)',
    REVISAR_VINCULO_EXISTENTE_EN_CANAL: 'Revisar vínculo existente en canal',
    REVISAR_CONFLICTO_SKU_TONO: 'Revisar conflicto de SKU vs tono',
    REVISAR_ATRIBUTOS_KIT_CONTRADICTORIOS: 'Revisar atributos contradictorios de kit',
    REVISAR_CONFLICTO_SKU_GTIN: 'Revisar inconsistencia entre SKU y GTIN',
    REVISAR_PUBLICACION_MODERADA: 'Revisar publicación moderada/denunciada',
    VALIDAR_IDENTIDAD_Y_DATOS_INCOMPLETOS: 'Validar identidad/datos antes de alta',
    REFRESCAR_SNAPSHOT_ANTES_DE_VINCULAR: 'Refrescar snapshot antes de vincular',
    CONFIRMAR_COMPONENTES_KIT_CON_PEROXIDO: 'Confirmar componentes (tinte + peróxido)',
    REVISION_HUMANA_CLASIFICACION: 'Revisión humana de clasificación',
    YA_VINCULADO_VALIDAR_ACTIVACION_FUTURA: 'Ya vinculado (validar activación futura)',
    CANDIDATO_A_VINCULAR_A_INVENTORY_EXISTENTE: 'Candidato a vincular a Inventory existente',
    CANDIDATO_A_CREAR_Y_AGRUPAR_EN_INVENTORY: 'Candidato a crear y agrupar en Inventory',
}

export default function IgoraRoyalPreview({
    preview = { rows: [], groups: [], counts: {}, facets: {}, filters: {}, pagination: null },
    accounts = [],
    lineLabels = {},
    presentationLabels = {},
}) {
    const initial = preview.filters || {}
    const [search, setSearch] = useState(initial.search || '')
    const [line, setLine] = useState(initial.line || '')
    const [tone, setTone] = useState(initial.tone || '')
    const [presentation, setPresentation] = useState(initial.presentation || '')
    const [status, setStatus] = useState(initial.status || '')
    const [accountKey, setAccountKey] = useState(initial.account_key || '')
    const [ambiguousOnly, setAmbiguousOnly] = useState(Boolean(initial.ambiguous_only))
    const [perPage, setPerPage] = useState(String(initial.per_page || 50))
    const [showGroups, setShowGroups] = useState(true)

    const rows = preview.rows || []
    const groups = preview.groups || []
    const counts = preview.counts || {}
    const facets = preview.facets || {}
    const pagination = preview.pagination || null

    const buildParams = (overrides = {}) => {
        const params = {
            search,
            line,
            tone,
            presentation,
            status,
            account_key: accountKey,
            ambiguous_only: ambiguousOnly ? 1 : '',
            per_page: perPage && perPage !== '50' ? perPage : '',
            ...overrides,
        }

        return Object.fromEntries(
            Object.entries(params).filter(([, value]) => value !== '' && value !== null && value !== undefined && value !== false)
        )
    }

    const applyFilters = (event, overrides = {}) => {
        if (event) event.preventDefault()
        router.get('/almacen/canales/mercado-libre/igora-royal', buildParams({ page: '', ...overrides }), {
            preserveState: true,
            replace: true,
        })
    }

    const resetFilters = () => {
        setSearch('')
        setLine('')
        setTone('')
        setPresentation('')
        setStatus('')
        setAccountKey('')
        setAmbiguousOnly(false)
        setPerPage('50')
        router.get('/almacen/canales/mercado-libre/igora-royal', {}, { preserveState: true, replace: true })
    }

    const exportHref = `/almacen/canales/mercado-libre/igora-royal/exportar?${new URLSearchParams(buildParams()).toString()}`

    return (
        <AppShell title="Agrupador Igora Royal (Vista Previa)">
            <Head title="Agrupador Igora Royal · Vista Previa" />

            <div className="space-y-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.18em] text-indigo-600 dark:text-indigo-400">
                            Almacén · Mercado Libre · Inventory*
                        </p>
                        <h1 className="mt-1 text-3xl font-bold text-slate-900 dark:text-white">
                            Agrupador Schwarzkopf Igora Royal
                        </h1>
                        <p className="mt-1 text-sm text-slate-600 dark:text-neutral-400">
                            Descubrimiento, normalización de tonos y vista previa sobre el inventario maestro{' '}
                            <span className="font-mono text-xs">inventory_products / inventory_movements</span>.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => setShowGroups((v) => !v)}
                            className="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200"
                        >
                            {showGroups ? 'Ocultar resumen de grupos' : 'Ver resumen de grupos'}
                        </button>
                        <a
                            href={exportHref}
                            className="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700"
                        >
                            Exportar CSV
                        </a>
                    </div>
                </div>

                <div className="rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-900/60 dark:bg-sky-950/40 dark:text-sky-200">
                    <strong>Modo Vista Previa (Solo Lectura):</strong> Este panel no crea vínculos en{' '}
                    <span className="font-mono text-xs">inventory_channel_links</span>, no modifica existencias, no crea kits automáticamente ni envía peticiones a Mercado Libre. Las cantidades remotas son solo referencia de snapshot y nunca se usan como stock físico.
                </div>

                <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <KpiCard label="Filas / Variaciones" value={counts.total_rows ?? 0} />
                    <KpiCard label="Grupos propuestos" value={counts.total_groups ?? 0} />
                    <KpiCard label="Igora Royal" value={counts.royal_rows ?? 0} />
                    <KpiCard label="Tinte c/ peróxido" value={counts.dye_with_peroxide_rows ?? 0} tone="purple" />
                    <KpiCard label="Ambiguos / Conflictos" value={counts.ambiguous_or_conflicted_rows ?? 0} tone="amber" />
                    <KpiCard label="FULL (excluidos)" value={counts.full_rows ?? 0} tone="slate" />
                </div>

                <form
                    onSubmit={(e) => applyFilters(e)}
                    className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4 dark:border-neutral-800 dark:bg-neutral-900"
                >
                    <input
                        type="text"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Buscar SKU, MLM, variación, tono o título..."
                        className="rounded-xl border border-slate-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                    />

                    <select
                        value={line}
                        onChange={(e) => setLine(e.target.value)}
                        className="rounded-xl border border-slate-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                    >
                        <option value="">Todas las líneas</option>
                        {(facets.lines || []).map((item) => (
                            <option key={item} value={item}>
                                {lineLabels[item] || item}
                            </option>
                        ))}
                    </select>

                    <select
                        value={tone}
                        onChange={(e) => setTone(e.target.value)}
                        className="rounded-xl border border-slate-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                    >
                        <option value="">Todos los tonos</option>
                        {(facets.tones || []).map((t) => (
                            <option key={t} value={t}>
                                {t}
                            </option>
                        ))}
                    </select>

                    <select
                        value={presentation}
                        onChange={(e) => setPresentation(e.target.value)}
                        className="rounded-xl border border-slate-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                    >
                        <option value="">Todas las presentaciones</option>
                        {(facets.presentations || []).map((p) => (
                            <option key={p} value={p}>
                                {presentationLabels[p] || p}
                            </option>
                        ))}
                    </select>

                    <select
                        value={status}
                        onChange={(e) => setStatus(e.target.value)}
                        className="rounded-xl border border-slate-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                    >
                        <option value="">Todos los estados ML</option>
                        {(facets.statuses || []).map((s) => (
                            <option key={s} value={s}>
                                {s}
                            </option>
                        ))}
                    </select>

                    <select
                        value={accountKey}
                        onChange={(e) => setAccountKey(e.target.value)}
                        className="rounded-xl border border-slate-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                    >
                        <option value="">Todas las cuentas</option>
                        {accounts.map((acc) => (
                            <option key={acc.id} value={String(acc.id)}>
                                {acc.nickname || acc.meli_user_id} (#{acc.id})
                            </option>
                        ))}
                    </select>

                    <label className="flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-700 dark:border-neutral-700 dark:text-neutral-200">
                        <input
                            type="checkbox"
                            checked={ambiguousOnly}
                            onChange={(e) => setAmbiguousOnly(e.target.checked)}
                            className="h-4 w-4 rounded border-slate-300 text-indigo-600"
                        />
                        Solo ambiguos / conflictos
                    </label>

                    <div className="flex gap-2">
                        <select
                            value={perPage}
                            onChange={(e) => {
                                const next = e.target.value
                                setPerPage(next)
                                applyFilters(null, { per_page: next !== '50' ? next : '' })
                            }}
                            aria-label="Filas por página"
                            className="rounded-xl border border-slate-300 px-2.5 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                        >
                            <option value="25">25 / pág</option>
                            <option value="50">50 / pág</option>
                            <option value="100">100 / pág</option>
                            <option value="250">250 / pág</option>
                        </select>
                        <button
                            type="submit"
                            className="flex-1 rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800 dark:bg-indigo-600 dark:hover:bg-indigo-500"
                        >
                            Filtrar
                        </button>
                        <button
                            type="button"
                            onClick={resetFilters}
                            className="rounded-xl border border-slate-300 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 dark:border-neutral-700 dark:text-neutral-300"
                        >
                            Limpiar
                        </button>
                    </div>
                </form>

                {pagination && (
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <p className="text-xs text-slate-600 dark:text-neutral-400">
                            Mostrando{' '}
                            <strong className="text-slate-900 dark:text-white">
                                {pagination.from}–{pagination.to}
                            </strong>{' '}
                            de <strong className="text-slate-900 dark:text-white">{pagination.total}</strong> filas
                            (página {pagination.current_page} de {pagination.last_page})
                        </p>
                        <Pagination links={pagination.links || []} />
                    </div>
                )}

                {showGroups && groups.length > 0 && (
                    <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
                        <h2 className="text-sm font-bold uppercase tracking-wider text-slate-700 dark:text-neutral-200">
                            Grupos Centrales Propuestos ({groups.length})
                        </h2>
                        <div className="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {groups.map((group) => (
                                <div
                                    key={group.group_key}
                                    className="rounded-xl border border-slate-200 p-3 text-xs dark:border-neutral-800 dark:bg-neutral-950/60"
                                >
                                    <div className="flex items-start justify-between gap-2">
                                        <div>
                                            <div className="font-semibold text-slate-900 dark:text-white">
                                                {group.label}
                                            </div>
                                            <div className="mt-0.5 font-mono text-[11px] text-slate-500">
                                                {group.group_key}
                                            </div>
                                        </div>
                                        <span className="rounded-full bg-slate-100 px-2 py-0.5 font-semibold text-slate-700 dark:bg-neutral-800 dark:text-neutral-200">
                                            {group.members_count} pub(s)
                                        </span>
                                    </div>
                                    <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-slate-600 dark:text-neutral-400">
                                        <span>
                                            Inventory:{' '}
                                            <strong className="text-slate-900 dark:text-white">
                                                {group.proposed_inventory_product_id
                                                    ? `#${group.proposed_inventory_product_id} (${group.proposed_inventory_product_sku})`
                                                    : 'Por crear / confirmar'}
                                            </strong>
                                        </span>
                                        <span>
                                            Stock local (F/D):{' '}
                                            <strong className="text-emerald-700 dark:text-emerald-400">
                                                {group.local_physical_stock !== null
                                                    ? `${group.local_physical_stock} / ${group.local_available_stock}`
                                                    : 'N/A'}
                                            </strong>
                                        </span>
                                        <span>UPs distintos: {group.distinct_user_products_count}</span>
                                    </div>
                                    {group.conflicts?.length > 0 && (
                                        <div className="mt-2 flex flex-wrap gap-1">
                                            {group.conflicts.map((c) => (
                                                <span
                                                    key={c}
                                                    className="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800 dark:bg-amber-950/60 dark:text-amber-300"
                                                >
                                                    {conflictLabels[c] || c}
                                                </span>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="overflow-x-auto">
                        <table className="min-w-full divide-y divide-slate-200 text-xs dark:divide-neutral-800">
                            <thead className="bg-slate-50 text-left uppercase tracking-wider text-slate-500 dark:bg-neutral-950 dark:text-neutral-400">
                                <tr>
                                    <th className="px-3 py-3">Línea / Tono / SKU</th>
                                    <th className="px-3 py-3">Publicación y Variación</th>
                                    <th className="px-3 py-3">Cuenta / ML IDs</th>
                                    <th className="px-3 py-3">Stock Local vs Remoto</th>
                                    <th className="px-3 py-3">Estado / Snapshot</th>
                                    <th className="px-3 py-3">Presentación</th>
                                    <th className="px-3 py-3">Componentes Candidatos</th>
                                    <th className="px-3 py-3">Coincidencia Propuesta</th>
                                    <th className="px-3 py-3">Confianza / Conflictos</th>
                                    <th className="px-3 py-3">Acción Recomendada</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-200 dark:divide-neutral-800">
                                {rows.length === 0 && (
                                    <tr>
                                        <td colSpan={10} className="px-4 py-10 text-center text-sm text-slate-500">
                                            No se encontraron publicaciones Igora con los filtros seleccionados.
                                        </td>
                                    </tr>
                                )}

                                {rows.map((row) => (
                                    <tr
                                        key={`${row.account_id}-${row.mlm}-${row.variation_id || 'main'}`}
                                        className="align-top hover:bg-slate-50/70 dark:hover:bg-neutral-950/40"
                                    >
                                        <td className="px-3 py-3 whitespace-nowrap">
                                            <div className="font-semibold text-slate-900 dark:text-white">
                                                {row.line_label}
                                            </div>
                                            <div className="mt-1 flex items-center gap-1.5">
                                                <span className="rounded bg-slate-900 px-1.5 py-0.5 font-mono text-[11px] font-bold text-white dark:bg-indigo-600">
                                                    {row.normalized_tone || 'SIN TONO'}
                                                </span>
                                                {row.tone_source && (
                                                    <span className="text-[10px] text-slate-400">
                                                        ({row.tone_source})
                                                    </span>
                                                )}
                                            </div>
                                            <div className="mt-1 font-mono text-[11px] text-slate-600 dark:text-neutral-300">
                                                SKU: {row.sku || '—'}
                                            </div>
                                        </td>

                                        <td className="px-3 py-3 min-w-[220px]">
                                            <div className="font-medium text-slate-900 dark:text-white">
                                                {row.title}
                                            </div>
                                            {row.variation_label && (
                                                <div className="mt-1 text-[11px] text-indigo-700 dark:text-indigo-300">
                                                    {row.variation_label}
                                                </div>
                                            )}
                                            {row.is_full && (
                                                <span className="mt-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900">
                                                    FULL · {row.full_stock_available ?? '—'} u.
                                                </span>
                                            )}
                                        </td>

                                        <td className="px-3 py-3 whitespace-nowrap">
                                            <div className="font-medium text-slate-800 dark:text-neutral-200">
                                                {row.account_name}
                                            </div>
                                            <div className="mt-1 font-mono text-[11px] text-slate-600 dark:text-neutral-400">
                                                {row.mlm}
                                            </div>
                                            <div className="font-mono text-[11px] text-slate-500">
                                                Var: {row.variation_id || '—'}
                                            </div>
                                            <div className="font-mono text-[11px] text-slate-500">
                                                UP: {row.user_product_id || '—'}
                                            </div>
                                        </td>

                                        <td className="px-3 py-3 whitespace-nowrap">
                                            <div className="rounded-lg border border-emerald-200 bg-emerald-50/70 px-2 py-1 dark:border-emerald-900/50 dark:bg-emerald-950/30">
                                                <div className="text-[10px] font-semibold uppercase text-emerald-800 dark:text-emerald-300">
                                                    Local (Inventory*)
                                                </div>
                                                <div className="font-mono font-bold text-emerald-900 dark:text-emerald-200">
                                                    {row.local_physical_stock !== null
                                                        ? `F ${row.local_physical_stock} · D ${row.local_available_stock}`
                                                        : 'Sin producto'}
                                                </div>
                                            </div>
                                            <div className="mt-1.5 rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 dark:border-neutral-800 dark:bg-neutral-950">
                                                <div className="text-[10px] font-semibold uppercase text-slate-500">
                                                    Remoto (Solo Ref.)
                                                </div>
                                                <div className="font-mono text-slate-700 dark:text-neutral-300">
                                                    Pub: {row.remote_publication_stock ?? '—'} · PM: {row.remote_price_manager_stock ?? '—'}
                                                </div>
                                            </div>
                                        </td>

                                        <td className="px-3 py-3 whitespace-nowrap">
                                            <div className="font-semibold text-slate-800 dark:text-neutral-200">
                                                {row.status}
                                            </div>
                                            {row.is_moderated_or_denounced && (
                                                <div className="mt-0.5 text-[10px] font-semibold text-rose-600 dark:text-rose-400">
                                                    {(row.moderation_signals || []).join(', ')}
                                                </div>
                                            )}
                                            <div className="mt-1 text-[11px] text-slate-500">
                                                {row.snapshot_at_human || 'Sin fecha'}
                                            </div>
                                            {row.snapshot_stale && (
                                                <span className="mt-0.5 inline-block text-[10px] font-medium text-amber-600">
                                                    Desactualizado
                                                </span>
                                            )}
                                        </td>

                                        <td className="px-3 py-3 whitespace-nowrap">
                                            <span
                                                className={`inline-block rounded-full border px-2 py-0.5 text-[11px] font-semibold ${
                                                    presentationBadge[row.presentation_type] || presentationBadge.UNKNOWN_PRESENTATION
                                                }`}
                                            >
                                                {row.presentation_label}
                                            </span>
                                            {row.presentation_type === 'DYE_WITH_PEROXIDE' && row.peroxide_info && (
                                                <div className="mt-1 text-[11px] text-slate-600 dark:text-neutral-400">
                                                    Peróx: {row.peroxide_info.concentration_label || '?'} ·{' '}
                                                    {row.peroxide_info.volume_ml ? `${row.peroxide_info.volume_ml}ml` : '?ml'}
                                                </div>
                                            )}
                                        </td>

                                        <td className="px-3 py-3 min-w-[200px]">
                                            {(row.candidate_components || []).length === 0 ? (
                                                <span className="text-slate-400">Requiere definición manual</span>
                                            ) : (
                                                <ul className="space-y-1">
                                                    {row.candidate_components.map((comp, idx) => (
                                                        <li
                                                            key={`${comp.role}-${idx}`}
                                                            className="rounded border border-slate-200 bg-slate-50 px-2 py-1 dark:border-neutral-800 dark:bg-neutral-950"
                                                        >
                                                            <div className="font-medium text-slate-800 dark:text-neutral-200">
                                                                {comp.name} (x{comp.quantity ?? '?'})
                                                            </div>
                                                            <div className="font-mono text-[10px] text-slate-500">
                                                                {comp.role} · {comp.sku || 'sin SKU'} · {comp.status}
                                                            </div>
                                                        </li>
                                                    ))}
                                                </ul>
                                            )}
                                        </td>

                                        <td className="px-3 py-3 min-w-[200px]">
                                            <div className="font-medium text-slate-900 dark:text-white">
                                                {row.proposed_match_label}
                                            </div>
                                            <div className="mt-1 font-mono text-[10px] text-slate-500">
                                                Grupo: {row.proposed_group_key}
                                            </div>
                                            {row.existing_channel_link_id && (
                                                <div className="mt-1 text-[10px] text-blue-700 dark:text-blue-300">
                                                    Link #{row.existing_channel_link_id} (Sync:{' '}
                                                    {row.existing_channel_link_stock_sync ? 'ON' : 'OFF'})
                                                </div>
                                            )}
                                        </td>

                                        <td className="px-3 py-3 min-w-[180px]">
                                            <span
                                                className={`inline-block rounded-full border px-2 py-0.5 text-[10px] font-bold ${
                                                    confidenceBadge[row.confidence] || confidenceBadge.LOW
                                                }`}
                                            >
                                                {row.confidence}
                                            </span>
                                            {row.requires_human_review && (
                                                <span className="ml-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900">
                                                    Revisión humana
                                                </span>
                                            )}
                                            <div className="mt-1.5 flex flex-wrap gap-1">
                                                {(row.conflicts || []).map((c) => (
                                                    <span
                                                        key={c}
                                                        className="rounded bg-rose-50 px-1.5 py-0.5 text-[10px] font-medium text-rose-700 dark:bg-rose-950/50 dark:text-rose-300"
                                                    >
                                                        {conflictLabels[c] || c}
                                                    </span>
                                                ))}
                                                {(row.notes || []).map((n) => (
                                                    <span
                                                        key={n}
                                                        className="rounded bg-sky-50 px-1.5 py-0.5 text-[10px] font-medium text-sky-700 dark:bg-sky-950/50 dark:text-sky-300"
                                                    >
                                                        {n}
                                                    </span>
                                                ))}
                                            </div>
                                        </td>

                                        <td className="px-3 py-3 min-w-[170px] font-medium text-slate-800 dark:text-neutral-200">
                                            {actionLabels[row.recommended_action] || row.recommended_action}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {pagination && pagination.last_page > 1 && (
                    <div className="flex justify-end">
                        <Pagination links={pagination.links || []} />
                    </div>
                )}
            </div>
        </AppShell>
    )
}

function KpiCard({ label, value, tone = 'indigo' }) {
    const toneMap = {
        indigo: 'text-indigo-700 dark:text-indigo-300',
        purple: 'text-purple-700 dark:text-purple-300',
        amber: 'text-amber-700 dark:text-amber-300',
        slate: 'text-slate-700 dark:text-neutral-300',
    }

    return (
        <div className="rounded-2xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900">
            <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-neutral-400">
                {label}
            </div>
            <div className={`mt-1 text-2xl font-bold ${toneMap[tone] || toneMap.indigo}`}>
                {value}
            </div>
        </div>
    )
}
