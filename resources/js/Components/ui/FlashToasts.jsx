import { usePage } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'

function Toast({ type, message, onDismiss }) {
    const isSuccess = type === 'success'
    return (
        <div
            role="alert"
            className={`pointer-events-auto flex max-w-md items-start gap-3 rounded-2xl border px-4 py-3 shadow-lg ${
                isSuccess
                    ? 'border-emerald-200 bg-white text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100'
                    : 'border-red-200 bg-white text-red-900 dark:border-red-900 dark:bg-red-950 dark:text-red-100'
            }`}
        >
            <div className="min-w-0 flex-1 text-sm leading-relaxed">{message}</div>
            <button
                type="button"
                onClick={onDismiss}
                className={`shrink-0 rounded-lg p-1 transition ${
                    isSuccess
                        ? 'text-emerald-600 hover:bg-emerald-100 dark:hover:bg-emerald-900/50'
                        : 'text-red-600 hover:bg-red-100 dark:hover:bg-red-900/50'
                }`}
                aria-label="Cerrar"
            >
                <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
    )
}

/**
 * Notificaciones flotantes: flash.success/error/ok/err (HandleInertiaRequests).
 */
export default function FlashToasts() {
    const { flash } = usePage().props
    const [toasts, setToasts] = useState([])
    const seenRef = useRef({ success: null, error: null, ok: null, err: null })

    useEffect(() => {
        const push = (type, message, ttlMs) => {
            const id = `${type}-${Date.now()}-${Math.random()}`
            setToasts((prev) => [...prev, { id, type, message: String(message) }])
            window.setTimeout(() => {
                setToasts((prev) => prev.filter((t) => t.id !== id))
            }, ttlMs)
        }

        const s = flash?.success
        const e = flash?.error
        const ok = flash?.ok
        const err = flash?.err

        if (s && seenRef.current.success !== s) {
            seenRef.current.success = s
            push('success', s, 7000)
        }
        if (e && seenRef.current.error !== e) {
            seenRef.current.error = e
            push('error', e, 10000)
        }
        if (ok && seenRef.current.ok !== ok) {
            seenRef.current.ok = ok
            push('success', ok, 7000)
        }
        if (err && seenRef.current.err !== err) {
            seenRef.current.err = err
            push('error', err, 10000)
        }

        if (!s) seenRef.current.success = null
        if (!e) seenRef.current.error = null
        if (!ok) seenRef.current.ok = null
        if (!err) seenRef.current.err = null
    }, [flash?.success, flash?.error, flash?.ok, flash?.err])

    if (toasts.length === 0) return null

    return (
        <div className="pointer-events-none fixed bottom-4 right-4 z-[100] flex max-w-[min(100vw-2rem,24rem)] flex-col gap-2">
            {toasts.map((t) => (
                <Toast
                    key={t.id}
                    type={t.type}
                    message={t.message}
                    onDismiss={() => setToasts((prev) => prev.filter((x) => x.id !== t.id))}
                />
            ))}
        </div>
    )
}
