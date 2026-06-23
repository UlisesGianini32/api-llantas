import { useCallback, useEffect, useState } from 'react'

/**
 * Selector de categoría ML (México): búsqueda domain_discovery + navegación por árbol API pública.
 * No se cargan todas las categorías a la vez (son miles); se navega por niveles.
 */
export default function MeliCategoryPicker({ value, onChange, meliLinked = false }) {
    const [open, setOpen] = useState(false)
    const [tab, setTab] = useState('browse')
    const [loading, setLoading] = useState(false)
    const [node, setNode] = useState(null)
    const [searchQ, setSearchQ] = useState('')
    const [searchLoading, setSearchLoading] = useState(false)
    const [searchResults, setSearchResults] = useState([])
    const [searchError, setSearchError] = useState('')
    const [browseError, setBrowseError] = useState('')

    const loadBrowse = useCallback(async (parentId) => {
        setLoading(true)
        setBrowseError('')
        try {
            const qs = parentId ? `?parent=${encodeURIComponent(parentId)}` : ''
            const r = await fetch(`/syscom-ml/meli-categories/browse${qs}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
            const j = await r.json()
            if (!j.ok) {
                setNode(j.node ?? null)
                setBrowseError(j.message || 'Respuesta inválida del servidor.')
                return
            }
            setNode(j.node)
        } catch {
            setNode(null)
            setBrowseError('Error de red al consultar Mercado Libre.')
        } finally {
            setLoading(false)
        }
    }, [])

    const runSearch = useCallback(async () => {
        const q = searchQ.trim()
        if (q.length < 2) {
            setSearchError('Escribí al menos 2 caracteres.')
            return
        }
        if (!meliLinked) {
            setSearchError('Vinculá Mercado Libre para buscar.')
            return
        }
        setSearchError('')
        setSearchLoading(true)
        try {
            const r = await fetch(`/syscom-ml/meli-categories/search?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
            const j = await r.json()
            if (!j.ok) {
                setSearchResults([])
                setSearchError(j.message || 'No se pudo buscar.')
                return
            }
            setSearchResults(Array.isArray(j.results) ? j.results : [])
        } catch {
            setSearchResults([])
            setSearchError('Error de red.')
        } finally {
            setSearchLoading(false)
        }
    }, [searchQ, meliLinked])

    useEffect(() => {
        if (open && tab === 'browse' && !node) {
            loadBrowse('')
        }
    }, [open, tab, node, loadBrowse])

    const selectId = (id) => {
        onChange(id)
        setOpen(false)
    }

    const pathCrumbs = node?.path_from_root && Array.isArray(node.path_from_root) ? node.path_from_root : []

    return (
        <>
            <div className="mt-0.5 flex gap-1">
                <input
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    className="min-w-0 flex-1 rounded border border-zinc-200 bg-white px-2 py-0.5 font-mono text-sm dark:border-neutral-700 dark:bg-neutral-900"
                    placeholder="MLM… vacío = automático"
                />
                <button
                    type="button"
                    onClick={() => {
                        setOpen(true)
                        setTab('browse')
                        setNode(null)
                        setSearchResults([])
                        setSearchError('')
                        setBrowseError('')
                    }}
                    className="shrink-0 rounded border border-indigo-300 bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-900 hover:bg-indigo-100 dark:border-indigo-800 dark:bg-indigo-950/50 dark:text-indigo-200"
                >
                    Elegir…
                </button>
            </div>

            {open && (
                <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
                    <button
                        type="button"
                        className="absolute inset-0 bg-black/50"
                        aria-label="Cerrar"
                        onClick={() => setOpen(false)}
                    />
                    <div className="relative z-10 max-h-[90vh] w-full max-w-lg overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xl dark:border-neutral-700 dark:bg-neutral-900">
                        <div className="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-neutral-800">
                            <h3 className="text-sm font-semibold text-zinc-900 dark:text-white">Categoría Mercado Libre (MLM)</h3>
                            <button
                                type="button"
                                className="text-sm text-zinc-500 hover:text-zinc-800 dark:text-zinc-400"
                                onClick={() => setOpen(false)}
                            >
                                Cerrar
                            </button>
                        </div>

                        <div className="flex border-b border-zinc-200 dark:border-neutral-800">
                            <button
                                type="button"
                                className={`flex-1 px-3 py-2 text-sm font-medium ${
                                    tab === 'browse'
                                        ? 'border-b-2 border-indigo-600 text-indigo-700 dark:text-indigo-300'
                                        : 'text-zinc-600 dark:text-zinc-400'
                                }`}
                                onClick={() => setTab('browse')}
                            >
                                Navegar árbol
                            </button>
                            <button
                                type="button"
                                className={`flex-1 px-3 py-2 text-sm font-medium ${
                                    tab === 'search'
                                        ? 'border-b-2 border-indigo-600 text-indigo-700 dark:text-indigo-300'
                                        : 'text-zinc-600 dark:text-zinc-400'
                                }`}
                                onClick={() => setTab('search')}
                            >
                                Buscar
                            </button>
                        </div>

                        <div className="max-h-[60vh] overflow-y-auto p-4">
                            {tab === 'browse' && (
                                <div className="space-y-3">
                                    <p className="text-xs text-zinc-500 dark:text-zinc-400">
                                        Mercado Libre tiene miles de categorías; abrí cada rama hasta la que corresponda a tu
                                        producto. Solo las categorías hoja suelen aceptar publicación nueva.
                                    </p>
                                    {loading && <p className="text-sm text-zinc-500">Cargando…</p>}
                                    {!loading && browseError && (
                                        <p className="text-sm text-amber-700 dark:text-amber-200">{browseError}</p>
                                    )}
                                    {!loading && node && (
                                        <>
                                            <nav className="flex flex-wrap items-center gap-1 text-xs">
                                                <button
                                                    type="button"
                                                    className="rounded bg-zinc-100 px-2 py-0.5 text-indigo-700 hover:bg-zinc-200 dark:bg-neutral-800 dark:text-indigo-300"
                                                    onClick={() => {
                                                        setNode(null)
                                                        loadBrowse('')
                                                    }}
                                                >
                                                    Inicio
                                                </button>
                                                {pathCrumbs.map((c) => (
                                                    <span key={c.id} className="inline-flex items-center gap-1">
                                                        <span className="text-zinc-400">/</span>
                                                        <button
                                                            type="button"
                                                            className="rounded bg-zinc-100 px-2 py-0.5 hover:bg-zinc-200 dark:bg-neutral-800"
                                                            onClick={() => loadBrowse(c.id)}
                                                        >
                                                            {c.name}
                                                        </button>
                                                    </span>
                                                ))}
                                            </nav>
                                            <p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">{node.name}</p>
                                            {node.children && node.children.length === 0 && node.id && (
                                                <button
                                                    type="button"
                                                    className="w-full rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-500"
                                                    onClick={() => selectId(node.id)}
                                                >
                                                    Usar <span className="font-mono">{node.id}</span>
                                                </button>
                                            )}
                                            <ul className="space-y-1">
                                                {(node.children || []).map((ch) => {
                                                    const expandable =
                                                        ch.has_children === true ||
                                                        ch.has_children === null ||
                                                        ch.has_children === undefined
                                                    const onlyLeaf = ch.has_children === false
                                                    return (
                                                        <li
                                                            key={ch.id}
                                                            className="flex items-center justify-between gap-2 rounded border border-zinc-100 px-2 py-2 dark:border-neutral-800"
                                                        >
                                                            <div className="min-w-0 flex-1">
                                                                <div className="truncate text-sm text-zinc-900 dark:text-zinc-100">
                                                                    {ch.name}
                                                                </div>
                                                                <div className="font-mono text-[10px] text-zinc-500">{ch.id}</div>
                                                            </div>
                                                            <div className="flex shrink-0 gap-1">
                                                                {onlyLeaf && (
                                                                    <button
                                                                        type="button"
                                                                        className="rounded bg-emerald-600 px-2 py-1 text-xs font-semibold text-white hover:bg-emerald-500"
                                                                        onClick={() => selectId(ch.id)}
                                                                    >
                                                                        Usar
                                                                    </button>
                                                                )}
                                                                {expandable && (
                                                                    <button
                                                                        type="button"
                                                                        className="rounded border border-zinc-300 px-2 py-1 text-xs font-medium dark:border-neutral-600"
                                                                        onClick={() => loadBrowse(ch.id)}
                                                                    >
                                                                        Abrir →
                                                                    </button>
                                                                )}
                                                            </div>
                                                        </li>
                                                    )
                                                })}
                                            </ul>
                                        </>
                                    )}
                                </div>
                            )}

                            {tab === 'search' && (
                                <div className="space-y-3">
                                    <p className="text-xs text-zinc-500 dark:text-zinc-400">
                                        Búsqueda por palabra (API de ML). Requiere cuenta vinculada.
                                    </p>
                                    <div className="flex gap-2">
                                        <input
                                            value={searchQ}
                                            onChange={(e) => setSearchQ(e.target.value)}
                                            onKeyDown={(e) => e.key === 'Enter' && runSearch()}
                                            className="flex-1 rounded border border-zinc-300 px-2 py-1.5 text-sm dark:border-neutral-600 dark:bg-neutral-950"
                                            placeholder="Ej. cámara vigilancia IP"
                                        />
                                        <button
                                            type="button"
                                            disabled={searchLoading}
                                            className="rounded bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50"
                                            onClick={() => runSearch()}
                                        >
                                            {searchLoading ? '…' : 'Buscar'}
                                        </button>
                                    </div>
                                    {searchError && <p className="text-xs text-red-600 dark:text-red-300">{searchError}</p>}
                                    <ul className="space-y-1">
                                        {searchResults.map((r) => (
                                            <li
                                                key={r.id}
                                                className="flex items-center justify-between gap-2 rounded border border-zinc-100 px-2 py-2 dark:border-neutral-800"
                                            >
                                                <div className="min-w-0">
                                                    <div className="text-sm text-zinc-900 dark:text-zinc-100">{r.name}</div>
                                                    <div className="font-mono text-[10px] text-zinc-500">{r.id}</div>
                                                </div>
                                                <button
                                                    type="button"
                                                    className="shrink-0 rounded bg-emerald-600 px-2 py-1 text-xs font-semibold text-white"
                                                    onClick={() => selectId(r.id)}
                                                >
                                                    Usar
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </>
    )
}
