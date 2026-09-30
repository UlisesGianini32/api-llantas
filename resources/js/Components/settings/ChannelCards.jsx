import { useState } from 'react'
import { Link, router, usePage } from '@inertiajs/react'
import SettingsCard from '@/Components/settings/SettingsCard'

export default function ChannelCards() {
    const { auth, channels, flash } = usePage().props
    const user = auth?.user
    const meliAccounts = user?.meli_accounts ?? []

    const hasMeliLinked =
        meliAccounts.length > 0 ||
        user?.meli_linked === true ||
        (user?.meli_id != null && String(user.meli_id).trim() !== '')

    const shopifyConfig = channels?.shopify ?? {}
    const amazonConfig = channels?.amazon ?? {}

    // Testing states
    const [shopifyTesting, setShopifyTesting] = useState(false)
    const [shopifyTestResult, setShopifyTestResult] = useState(null)

    const [amazonTesting, setAmazonTesting] = useState(false)
    const [amazonTestResult, setAmazonTestResult] = useState(null)

    // Modals
    const [shopifyModalOpen, setShopifyModalOpen] = useState(false)
    const [amazonModalOpen, setAmazonModalOpen] = useState(false)

    // Shopify form
    const [shopifyForm, setShopifyForm] = useState({
        store_domain: shopifyConfig.store_domain || '',
        client_id: '',
        client_secret: '',
        api_version: shopifyConfig.api_version || '2025-01',
    })

    // Amazon form
    const [amazonForm, setAmazonForm] = useState({
        seller_id: amazonConfig.seller_id || '',
        marketplace_id: amazonConfig.marketplace_id || 'A1AM78C64UM0Y8',
        lwa_client_id: '',
        lwa_client_secret: '',
        lwa_refresh_token: '',
    })

    const [savingShopify, setSavingShopify] = useState(false)
    const [savingAmazon, setSavingAmazon] = useState(false)

    const getCsrfToken = () => {
        return (
            document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        )
    }

    const testShopify = async () => {
        setShopifyTesting(true)
        setShopifyTestResult(null)
        try {
            const res = await fetch('/settings/channels/shopify/test', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    Accept: 'application/json',
                },
            })
            const data = await res.json()
            setShopifyTestResult(data)
        } catch (err) {
            setShopifyTestResult({
                ok: false,
                message: 'No se pudo contactar al servidor: ' + (err.message || String(err)),
            })
        } finally {
            setShopifyTesting(false)
        }
    }

    const testAmazon = async () => {
        setAmazonTesting(true)
        setAmazonTestResult(null)
        try {
            const res = await fetch('/settings/channels/amazon/test', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    Accept: 'application/json',
                },
            })
            const data = await res.json()
            setAmazonTestResult(data)
        } catch (err) {
            setAmazonTestResult({
                ok: false,
                message: 'No se pudo contactar al servidor: ' + (err.message || String(err)),
            })
        } finally {
            setAmazonTesting(false)
        }
    }

    const handleSaveShopify = (e) => {
        e.preventDefault()
        setSavingShopify(true)
        router.post(
            '/settings/channels/save',
            {
                channel: 'shopify',
                ...shopifyForm,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSavingShopify(false)
                    setShopifyModalOpen(false)
                },
            }
        )
    }

    const handleSaveAmazon = (e) => {
        e.preventDefault()
        setSavingAmazon(true)
        router.post(
            '/settings/channels/save',
            {
                channel: 'amazon',
                ...amazonForm,
            },
            {
                preserveScroll: true,
                onFinish: () => {
                    setSavingAmazon(false)
                    setAmazonModalOpen(false)
                },
            }
        )
    }

    return (
        <div className="space-y-6">
            {/* MERCADO LIBRE CARD */}
            <SettingsCard
                title="Cuenta de Mercado Libre"
                description="Puedes vincular varias tiendas al mismo perfil (misma app de MeLi). La marcada como principal es la que usan sincronización y jobs por defecto."
            >
                <div className="space-y-4">
                    <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-neutral-800 dark:bg-neutral-950">
                        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div className="flex items-center gap-3">
                                <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600 font-bold dark:bg-amber-400/10 dark:text-amber-400">
                                    ML
                                </span>
                                <div>
                                    <p className="text-sm font-semibold text-slate-900 dark:text-white">
                                        Estado de la conexión
                                    </p>
                                    <p className="mt-0.5 text-xs text-slate-600 dark:text-slate-300">
                                        {hasMeliLinked
                                            ? `${meliAccounts.length || 1} cuenta(s) vinculada(s)`
                                            : 'No tienes una cuenta de Mercado Libre vinculada.'}
                                    </p>
                                </div>
                            </div>

                            <span
                                className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${
                                    hasMeliLinked
                                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                                        : 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'
                                }`}
                            >
                                {hasMeliLinked ? 'Vinculada ✓' : 'Sin vincular'}
                            </span>
                        </div>
                    </div>

                    {meliAccounts.length > 0 && (
                        <ul className="space-y-3">
                            {meliAccounts.map((acc) => (
                                <li
                                    key={acc.id}
                                    className="flex flex-col gap-3 rounded-xl border border-slate-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-900 md:flex-row md:items-center md:justify-between"
                                >
                                    <div>
                                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                            ID Mercado Libre (user_id)
                                        </p>
                                        <p className="mt-1 font-mono text-sm font-semibold text-slate-900 dark:text-white">
                                            {acc.meli_user_id}
                                            {acc.is_default && (
                                                <span className="ml-2 inline-flex rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-semibold text-indigo-800 dark:bg-indigo-500/20 dark:text-indigo-200">
                                                    Principal
                                                </span>
                                            )}
                                        </p>
                                        {acc.nickname && (
                                            <p className="mt-1 text-sm text-slate-600 dark:text-slate-300">
                                                {acc.nickname}
                                            </p>
                                        )}
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <a
                                            href={`/auth/meli?account=${acc.id}`}
                                            className="inline-flex items-center justify-center rounded-xl bg-sky-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-sky-700"
                                        >
                                            Reautorizar
                                        </a>
                                        <Link
                                            href={`/auth/meli/unlink/${acc.id}`}
                                            method="delete"
                                            as="button"
                                            className="inline-flex items-center justify-center rounded-xl border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-600 transition hover:bg-red-50 dark:border-red-800 dark:bg-neutral-950 dark:text-red-400 dark:hover:bg-red-500/10"
                                        >
                                            Desvincular
                                        </Link>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}

                    <div className="flex flex-col gap-3 md:flex-row md:flex-wrap">
                        {!hasMeliLinked ? (
                            <a
                                href="/auth/meli"
                                className="inline-flex items-center justify-center rounded-xl bg-sky-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-sky-700"
                            >
                                Vincular Mercado Libre
                            </a>
                        ) : (
                            <>
                                <a
                                    href="/auth/meli?additional=1"
                                    className="inline-flex items-center justify-center rounded-xl bg-sky-600 px-5 py-3 text-sm font-semibold text-white transition hover:bg-sky-700"
                                >
                                    Vincular otra cuenta
                                </a>
                                <p className="text-sm text-slate-500 dark:text-slate-400 md:self-center">
                                    Inicia sesión en Mercado Libre con el usuario de la otra tienda cuando el
                                    navegador te lo pida.
                                </p>
                            </>
                        )}
                    </div>
                </div>
            </SettingsCard>

            {/* SHOPIFY CARD */}
            <SettingsCard
                title="Cuenta de Shopify"
                description="Conexión con la API REST y GraphQL de Shopify para sincronización de inventario en tiempo real y reservas automáticas de pedidos."
            >
                <div className="space-y-4">
                    <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-neutral-800 dark:bg-neutral-950">
                        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div className="flex items-center gap-3">
                                <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500/10 text-emerald-600 font-bold dark:bg-emerald-400/10 dark:text-emerald-400">
                                    SH
                                </span>
                                <div>
                                    <p className="text-sm font-semibold text-slate-900 dark:text-white">
                                        Estado de la conexión
                                    </p>
                                    <p className="mt-0.5 text-xs text-slate-600 dark:text-slate-300">
                                        {shopifyConfig.is_configured
                                            ? `Tienda configurada: ${shopifyConfig.store_domain || 'Dominio activo'}`
                                            : 'Falta configurar credenciales de la tienda Shopify.'}
                                    </p>
                                </div>
                            </div>

                            <span
                                className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${
                                    shopifyConfig.is_configured
                                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                                        : 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'
                                }`}
                            >
                                {shopifyConfig.is_configured ? 'Configurada ✓' : 'Sin configurar'}
                            </span>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div className="rounded-xl border border-slate-200 bg-white p-3.5 dark:border-neutral-800 dark:bg-neutral-900">
                            <p className="text-xs font-medium text-slate-500 dark:text-slate-400">
                                Tienda / Dominio
                            </p>
                            <p className="mt-1 font-mono text-sm font-semibold text-slate-900 dark:text-white">
                                {shopifyConfig.store_domain || '—'}
                            </p>
                        </div>
                        <div className="rounded-xl border border-slate-200 bg-white p-3.5 dark:border-neutral-800 dark:bg-neutral-900">
                            <p className="text-xs font-medium text-slate-500 dark:text-slate-400">
                                API Version
                            </p>
                            <p className="mt-1 font-mono text-sm font-semibold text-slate-900 dark:text-white">
                                {shopifyConfig.api_version || '2025-01'}
                            </p>
                        </div>
                        <div className="rounded-xl border border-slate-200 bg-white p-3.5 dark:border-neutral-800 dark:bg-neutral-900">
                            <p className="text-xs font-medium text-slate-500 dark:text-slate-400">
                                Client ID / Token
                            </p>
                            <p className="mt-1 font-mono text-sm font-semibold text-slate-900 dark:text-white">
                                {shopifyConfig.masked_client_id || (shopifyConfig.has_client_id ? 'Configurado ✓' : '—')}
                            </p>
                        </div>
                    </div>

                    {/* TEST RESULT ALERT */}
                    {shopifyTestResult && (
                        <div
                            className={`rounded-xl border p-4 text-sm ${
                                shopifyTestResult.ok
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                                    : 'border-red-200 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300'
                            }`}
                        >
                            <p className="font-semibold">
                                {shopifyTestResult.ok ? '✓ Conexión exitosa' : '✗ Falló la prueba de conexión'}
                            </p>
                            <p className="mt-1">{shopifyTestResult.message}</p>
                        </div>
                    )}

                    <div className="flex flex-wrap items-center gap-3 pt-2">
                        <button
                            type="button"
                            onClick={testShopify}
                            disabled={shopifyTesting}
                            className="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-60"
                        >
                            {shopifyTesting ? 'Probando conexión...' : 'Probar conexión'}
                        </button>

                        <button
                            type="button"
                            onClick={() => setShopifyModalOpen(true)}
                            className="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200 dark:hover:bg-neutral-800"
                        >
                            Configurar credenciales
                        </button>

                        {shopifyConfig.store_domain && (
                            <a
                                href={`/auth/shopify?shop=${encodeURIComponent(shopifyConfig.store_domain)}`}
                                className="inline-flex items-center justify-center rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-800 dark:bg-neutral-800 dark:hover:bg-neutral-700"
                            >
                                Vincular por OAuth
                            </a>
                        )}
                    </div>
                </div>
            </SettingsCard>

            {/* AMAZON SP-API CARD */}
            <SettingsCard
                title="Cuenta de Amazon SP-API"
                description="Conexión con Selling Partner API (SP-API) para sincronización de inventario FBM y reservas seguras sin alterar stock FBA."
            >
                <div className="space-y-4">
                    <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-neutral-800 dark:bg-neutral-950">
                        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div className="flex items-center gap-3">
                                <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-500/10 text-amber-600 font-bold dark:bg-amber-400/10 dark:text-amber-400">
                                    AZ
                                </span>
                                <div>
                                    <p className="text-sm font-semibold text-slate-900 dark:text-white">
                                        Estado de la conexión
                                    </p>
                                    <p className="mt-0.5 text-xs text-slate-600 dark:text-slate-300">
                                        {amazonConfig.is_configured
                                            ? `Vendedor configurado: ${amazonConfig.seller_id || 'Seller Central activo'}`
                                            : 'Falta configurar credenciales de Amazon SP-API (LWA Client ID / Refresh Token).'}
                                    </p>
                                </div>
                            </div>

                            <span
                                className={`inline-flex rounded-full px-3 py-1 text-xs font-semibold ${
                                    amazonConfig.is_configured
                                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'
                                        : 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'
                                }`}
                            >
                                {amazonConfig.is_configured ? 'Configurada ✓' : 'Sin configurar'}
                            </span>
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div className="rounded-xl border border-slate-200 bg-white p-3.5 dark:border-neutral-800 dark:bg-neutral-900">
                            <p className="text-xs font-medium text-slate-500 dark:text-slate-400">
                                Seller ID (Merchant)
                            </p>
                            <p className="mt-1 font-mono text-sm font-semibold text-slate-900 dark:text-white">
                                {amazonConfig.seller_id || '—'}
                            </p>
                        </div>
                        <div className="rounded-xl border border-slate-200 bg-white p-3.5 dark:border-neutral-800 dark:bg-neutral-900">
                            <p className="text-xs font-medium text-slate-500 dark:text-slate-400">
                                Marketplace
                            </p>
                            <p className="mt-1 font-mono text-sm font-semibold text-slate-900 dark:text-white">
                                {amazonConfig.marketplace_id || 'A1AM78C64UM0Y8 (México)'}
                            </p>
                        </div>
                        <div className="rounded-xl border border-slate-200 bg-white p-3.5 dark:border-neutral-800 dark:bg-neutral-900">
                            <p className="text-xs font-medium text-slate-500 dark:text-slate-400">
                                LWA Client ID
                            </p>
                            <p className="mt-1 font-mono text-sm font-semibold text-slate-900 dark:text-white">
                                {amazonConfig.masked_client_id || (amazonConfig.has_client_id ? 'Configurado ✓' : '—')}
                            </p>
                        </div>
                    </div>

                    {/* TEST RESULT ALERT */}
                    {amazonTestResult && (
                        <div
                            className={`rounded-xl border p-4 text-sm ${
                                amazonTestResult.ok
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'
                                    : 'border-red-200 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300'
                            }`}
                        >
                            <p className="font-semibold">
                                {amazonTestResult.ok ? '✓ Autenticación exitosa' : '✗ Falló la autenticación con Amazon'}
                            </p>
                            <p className="mt-1">{amazonTestResult.message}</p>
                        </div>
                    )}

                    <div className="flex flex-wrap items-center gap-3 pt-2">
                        <button
                            type="button"
                            onClick={testAmazon}
                            disabled={amazonTesting}
                            className="inline-flex items-center justify-center rounded-xl bg-amber-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-amber-700 disabled:opacity-60"
                        >
                            {amazonTesting ? 'Probando conexión...' : 'Probar conexión SP-API'}
                        </button>

                        <button
                            type="button"
                            onClick={() => setAmazonModalOpen(true)}
                            className="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-slate-200 dark:hover:bg-neutral-800"
                        >
                            Configurar credenciales
                        </button>
                    </div>
                </div>
            </SettingsCard>

            {/* SHOPIFY MODAL */}
            {shopifyModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                        <div className="flex items-center justify-between border-b pb-3 dark:border-neutral-800">
                            <h3 className="text-lg font-bold text-slate-900 dark:text-white">
                                Configuración de Shopify
                            </h3>
                            <button
                                type="button"
                                onClick={() => setShopifyModalOpen(false)}
                                className="text-slate-400 hover:text-slate-600 dark:hover:text-white"
                            >
                                ✕
                            </button>
                        </div>

                        <form onSubmit={handleSaveShopify} className="mt-4 space-y-4">
                            <div>
                                <label className="block text-xs font-semibold uppercase text-slate-600 dark:text-slate-300">
                                    Dominio de la tienda (Store Domain)
                                </label>
                                <input
                                    type="text"
                                    required
                                    placeholder="ej. mi-tienda.myshopify.com"
                                    value={shopifyForm.store_domain}
                                    onChange={(e) =>
                                        setShopifyForm({ ...shopifyForm, store_domain: e.target.value })
                                    }
                                    className="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                                <p className="mt-1 text-xs text-slate-500">
                                    El subdominio .myshopify.com de tu tienda.
                                </p>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold uppercase text-slate-600 dark:text-slate-300">
                                    Client ID o Access Token (shpat_...)
                                </label>
                                <input
                                    type="password"
                                    placeholder={
                                        shopifyConfig.has_client_id
                                            ? '•••••••••••••••• (dejar en blanco para mantener)'
                                            : 'shpat_... o Client ID'
                                    }
                                    value={shopifyForm.client_id}
                                    onChange={(e) =>
                                        setShopifyForm({ ...shopifyForm, client_id: e.target.value })
                                    }
                                    className="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold uppercase text-slate-600 dark:text-slate-300">
                                    Client Secret (si usa Client Credentials)
                                </label>
                                <input
                                    type="password"
                                    placeholder={
                                        shopifyConfig.has_client_secret
                                            ? '•••••••••••••••• (dejar en blanco para mantener)'
                                            : 'Client Secret de la app'
                                    }
                                    value={shopifyForm.client_secret}
                                    onChange={(e) =>
                                        setShopifyForm({ ...shopifyForm, client_secret: e.target.value })
                                    }
                                    className="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold uppercase text-slate-600 dark:text-slate-300">
                                    Versión de API
                                </label>
                                <input
                                    type="text"
                                    value={shopifyForm.api_version}
                                    onChange={(e) =>
                                        setShopifyForm({ ...shopifyForm, api_version: e.target.value })
                                    }
                                    className="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div className="flex justify-end gap-3 pt-4 border-t dark:border-neutral-800">
                                <button
                                    type="button"
                                    onClick={() => setShopifyModalOpen(false)}
                                    className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    disabled={savingShopify}
                                    className="rounded-xl bg-emerald-600 px-5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60"
                                >
                                    {savingShopify ? 'Guardando...' : 'Guardar credenciales'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {/* AMAZON MODAL */}
            {amazonModalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                        <div className="flex items-center justify-between border-b pb-3 dark:border-neutral-800">
                            <h3 className="text-lg font-bold text-slate-900 dark:text-white">
                                Configuración de Amazon SP-API
                            </h3>
                            <button
                                type="button"
                                onClick={() => setAmazonModalOpen(false)}
                                className="text-slate-400 hover:text-slate-600 dark:hover:text-white"
                            >
                                ✕
                            </button>
                        </div>

                        <form onSubmit={handleSaveAmazon} className="mt-4 space-y-4">
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="block text-xs font-semibold uppercase text-slate-600 dark:text-slate-300">
                                        Seller ID (Merchant Token)
                                    </label>
                                    <input
                                        type="text"
                                        placeholder="ej. A29B..."
                                        value={amazonForm.seller_id}
                                        onChange={(e) =>
                                            setAmazonForm({ ...amazonForm, seller_id: e.target.value })
                                        }
                                        className="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                </div>
                                <div>
                                    <label className="block text-xs font-semibold uppercase text-slate-600 dark:text-slate-300">
                                        Marketplace ID
                                    </label>
                                    <input
                                        type="text"
                                        placeholder="A1AM78C64UM0Y8"
                                        value={amazonForm.marketplace_id}
                                        onChange={(e) =>
                                            setAmazonForm({ ...amazonForm, marketplace_id: e.target.value })
                                        }
                                        className="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                    />
                                </div>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold uppercase text-slate-600 dark:text-slate-300">
                                    LWA Client ID
                                </label>
                                <input
                                    type="text"
                                    placeholder={
                                        amazonConfig.has_client_id
                                            ? '•••••••••••••••• (dejar en blanco para mantener)'
                                            : 'amzn1.application-oa2-client.xxx'
                                    }
                                    value={amazonForm.lwa_client_id}
                                    onChange={(e) =>
                                        setAmazonForm({ ...amazonForm, lwa_client_id: e.target.value })
                                    }
                                    className="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold uppercase text-slate-600 dark:text-slate-300">
                                    LWA Client Secret
                                </label>
                                <input
                                    type="password"
                                    placeholder={
                                        amazonConfig.has_client_secret
                                            ? '•••••••••••••••• (dejar en blanco para mantener)'
                                            : 'Secret key de la app LWA'
                                    }
                                    value={amazonForm.lwa_client_secret}
                                    onChange={(e) =>
                                        setAmazonForm({ ...amazonForm, lwa_client_secret: e.target.value })
                                    }
                                    className="mt-1 w-full rounded-xl border border-slate-300 px-3.5 py-2.5 text-sm dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold uppercase text-slate-600 dark:text-slate-300">
                                    LWA Refresh Token
                                </label>
                                <textarea
                                    rows={3}
                                    placeholder={
                                        amazonConfig.has_refresh_token
                                            ? '•••••••••••••••• (dejar en blanco para mantener)'
                                            : 'Atzr|IwEB...'
                                    }
                                    value={amazonForm.lwa_refresh_token}
                                    onChange={(e) =>
                                        setAmazonForm({ ...amazonForm, lwa_refresh_token: e.target.value })
                                    }
                                    className="mt-1 w-full font-mono rounded-xl border border-slate-300 px-3.5 py-2 text-xs dark:border-neutral-700 dark:bg-neutral-950 dark:text-white"
                                />
                            </div>

                            <div className="flex justify-end gap-3 pt-4 border-t dark:border-neutral-800">
                                <button
                                    type="button"
                                    onClick={() => setAmazonModalOpen(false)}
                                    className="rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 dark:border-neutral-700 dark:text-slate-300 dark:hover:bg-neutral-800"
                                >
                                    Cancelar
                                </button>
                                <button
                                    type="submit"
                                    disabled={savingAmazon}
                                    className="rounded-xl bg-amber-600 px-5 py-2 text-sm font-semibold text-white hover:bg-amber-700 disabled:opacity-60"
                                >
                                    {savingAmazon ? 'Guardando...' : 'Guardar credenciales'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    )
}
