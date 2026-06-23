import AppShell from '@/Components/layout/AppShell'
import { Head, useForm, usePage } from '@inertiajs/react'
import { useMemo, useState } from 'react'

function inputCls() {
    return 'w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900 font-mono placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white dark:placeholder-gray-500'
}

function cardCls() {
    return 'rounded-lg bg-white border border-zinc-200 overflow-hidden shadow-sm dark:bg-neutral-900 dark:border-neutral-800 dark:shadow-none'
}

function scopeTitle(scope) {
    if (scope === 'juego4') return 'JUEGO4'
    if (scope === 'llanta') return 'Llanta (1 pz / 1 unidad Syscom)'
    if (scope === 'par') return 'PAR'
    return scope
}

export default function Index({ rules }) {
    const { errors = {} } = usePage().props
    const [editingSet, setEditingSet] = useState('llantas')

    const orderedAll = useMemo(() => {
        const order = { juego4: 1, llanta: 2, par: 3 }
        return [...rules].sort((a, b) => {
            const rs = (a.rule_set || 'llantas').localeCompare(b.rule_set || 'llantas')
            if (rs !== 0) return rs
            return (order[a.scope] || 99) - (order[b.scope] || 99)
        })
    }, [rules])

    const rulesForm = useForm({
        rules: orderedAll.map((rule) => ({
            rule_set: rule.rule_set || 'llantas',
            scope: rule.scope,
            formula: rule.formula ?? '',
            active: !!rule.active,
        })),
    })

    const visibleRules = useMemo(
        () => rulesForm.data.rules.map((r, i) => ({ ...r, _i: i })).filter((r) => r.rule_set === editingSet),
        [rulesForm.data.rules, editingSet]
    )

    const testForm = useForm({
        rule_set: 'llantas',
        scope: 'llanta',
        costo: '',
    })

    const submitRules = (e) => {
        e.preventDefault()
        rulesForm.post('/price-rules', {
            preserveScroll: true,
        })
    }

    const submitTest = (e) => {
        e.preventDefault()
        testForm.post('/price-rules/test', {
            preserveScroll: true,
        })
    }

    const updateRule = (index, field, value) => {
        const next = [...rulesForm.data.rules]
        next[index] = {
            ...next[index],
            [field]: value,
        }
        rulesForm.setData('rules', next)
    }

    return (
        <>
            <Head title="Fórmulas de ventas" />

            <AppShell title="Fórmulas de ventas">
                <div className="mx-auto max-w-7xl space-y-6 text-zinc-900 dark:text-white">
                    {Object.keys(errors).length > 0 && (
                        <div className="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200">
                            <ul className="list-disc pl-5 space-y-1">
                                {Object.entries(errors).map(([key, value]) => (
                                    <li key={key}>{value}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <div className="rounded-lg bg-white p-4 border border-zinc-200 shadow-sm dark:bg-neutral-900 dark:border-neutral-800 dark:shadow-none">
                        <h1 className="text-2xl font-semibold">Fórmulas de ventas</h1>

                        <p className="mt-2 text-sm text-zinc-600 dark:text-gray-400">
                            Variables: <span className="font-mono">costo</span>, <span className="font-mono">piezas</span>{' '}
                            (1 / 2 / 4) | Operadores: <span className="font-mono">+ - * / ( )</span>
                        </p>

                        <p className="mt-2 text-sm text-amber-800 dark:text-amber-200/90 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 dark:border-amber-900 dark:bg-amber-950/40">
                            Elegí <b>qué conjunto editás</b> (llantas de inventario propio vs productos publicados desde{' '}
                            <b>SYSCOM</b>) para que las fórmulas y márgenes no se mezclen.
                        </p>
                    </div>

                    <div className={cardCls()}>
                        <div className="flex flex-col gap-3 border-b border-zinc-200 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-neutral-800">
                            <h2 className="font-semibold">Editar fórmulas</h2>
                            <div className="inline-flex rounded-lg border border-zinc-200 bg-zinc-50 p-0.5 dark:border-neutral-700 dark:bg-neutral-950">
                                <button
                                    type="button"
                                    onClick={() => setEditingSet('llantas')}
                                    className={
                                        editingSet === 'llantas'
                                            ? 'rounded-md bg-white px-3 py-1.5 text-sm font-medium shadow-sm dark:bg-neutral-800'
                                            : 'rounded-md px-3 py-1.5 text-sm text-zinc-600 dark:text-gray-400'
                                    }
                                >
                                    Llantas (inventario)
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setEditingSet('syscom')}
                                    className={
                                        editingSet === 'syscom'
                                            ? 'rounded-md bg-white px-3 py-1.5 text-sm font-medium shadow-sm dark:bg-neutral-800'
                                            : 'rounded-md px-3 py-1.5 text-sm text-zinc-600 dark:text-gray-400'
                                    }
                                >
                                    SYSCOM → Mercado Libre
                                </button>
                            </div>
                        </div>

                        <form onSubmit={submitRules} className="p-4 space-y-4">
                            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                                {visibleRules.map((rule) => {
                                    const i = rule._i
                                    return (
                                        <div
                                            key={`${rule.rule_set}-${rule.scope}`}
                                            className="rounded-lg bg-zinc-50 p-4 border border-zinc-200 space-y-3 dark:bg-neutral-950 dark:border-neutral-800"
                                        >
                                            <div className="flex items-center justify-between">
                                                <p className="text-sm font-semibold text-zinc-800 dark:text-gray-200">
                                                    {scopeTitle(rule.scope)}
                                                </p>
                                                <label className="flex items-center gap-2 text-xs text-zinc-700 dark:text-gray-300">
                                                    <input
                                                        type="checkbox"
                                                        checked={!!rule.active}
                                                        onChange={(e) => updateRule(i, 'active', e.target.checked)}
                                                        className="rounded border-zinc-300 bg-white dark:border-neutral-700 dark:bg-neutral-800"
                                                    />
                                                    Activa
                                                </label>
                                            </div>
                                            <div>
                                                <label className="block text-xs text-zinc-600 dark:text-gray-400 mb-1">
                                                    Fórmula
                                                </label>
                                                <input
                                                    value={rule.formula}
                                                    onChange={(e) => updateRule(i, 'formula', e.target.value)}
                                                    className={inputCls()}
                                                    placeholder="Ej: costo * 1.5"
                                                />
                                            </div>
                                        </div>
                                    )
                                })}
                            </div>

                            <p className="text-xs text-zinc-500 dark:text-gray-500">
                                Se guardan <b>los dos conjuntos</b> (llantas y Syscom) al pulsar abajo, aunque solo veas uno.
                            </p>

                            <button
                                type="submit"
                                disabled={rulesForm.processing}
                                className="rounded-md border border-zinc-300 bg-white px-4 py-3 font-semibold hover:bg-zinc-100 transition disabled:opacity-60 dark:border-neutral-800 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                            >
                                {rulesForm.processing ? 'Guardando...' : 'Guardar fórmulas'}
                            </button>
                        </form>
                    </div>

                    <div className={cardCls()}>
                        <div className="flex items-center gap-2 border-b border-zinc-200 p-4 dark:border-neutral-800">
                            <h2 className="font-semibold">Probar fórmula</h2>
                        </div>
                        <form
                            onSubmit={submitTest}
                            className="p-4 grid grid-cols-1 md:grid-cols-4 gap-3"
                        >
                            <select
                                value={testForm.data.rule_set}
                                onChange={(e) => testForm.setData('rule_set', e.target.value)}
                                className="rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                            >
                                <option value="llantas">Inventario llantas</option>
                                <option value="syscom">SYSCOM</option>
                            </select>
                            <select
                                value={testForm.data.scope}
                                onChange={(e) => testForm.setData('scope', e.target.value)}
                                className="rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
                            >
                                <option value="llanta">Llanta / 1 u.</option>
                                <option value="par">PAR</option>
                                <option value="juego4">JUEGO 4</option>
                            </select>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                value={testForm.data.costo}
                                onChange={(e) => testForm.setData('costo', e.target.value)}
                                placeholder="Costo base"
                                className="w-full rounded-md border border-zinc-300 bg-white px-3 py-2 text-zinc-900 placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white dark:placeholder-gray-500"
                            />
                            <button
                                type="submit"
                                disabled={testForm.processing}
                                className="rounded-md border border-zinc-300 bg-white px-4 py-2 font-semibold hover:bg-zinc-100 transition disabled:opacity-60 dark:border-neutral-800 dark:bg-neutral-900 dark:hover:bg-neutral-800"
                            >
                                {testForm.processing ? 'Probando...' : 'Probar'}
                            </button>
                        </form>
                    </div>
                </div>
            </AppShell>
        </>
    )
}
