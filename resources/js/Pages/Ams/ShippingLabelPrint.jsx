import AppShell from '@/Components/layout/AppShell'
import { Link } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'

export default function ShippingLabelPrint({ shippingId = '', pdfUrl = '', procesarUrl = '/ams/pedidos-procesar' }) {
    const [phase, setPhase] = useState('loading')
    const [errorMessage, setErrorMessage] = useState('')
    const [blobUrl, setBlobUrl] = useState(null)
    const blobUrlRef = useRef(null)
    const iframeRef = useRef(null)
    const printedRef = useRef(false)

    useEffect(() => {
        let cancelled = false

        const showError = (msg) => {
            if (cancelled) return
            setPhase('error')
            setErrorMessage(msg)
            printedRef.current = true
        }

        const run = async () => {
            try {
                const res = await fetch(pdfUrl, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/pdf' },
                })
                if (!res.ok) {
                    throw new Error(`ML respondió ${res.status}`)
                }
                const blob = await res.blob()
                if (!blob || blob.size === 0) {
                    throw new Error('PDF vacío')
                }
                const url = URL.createObjectURL(blob)
                blobUrlRef.current = url
                if (!cancelled) {
                    setBlobUrl(url)
                }

                const iframe = document.createElement('iframe')
                iframe.setAttribute('title', 'Etiqueta')
                iframe.style.position = 'fixed'
                iframe.style.width = '1px'
                iframe.style.height = '1px'
                iframe.style.left = '-9999px'
                iframe.style.top = '0'
                iframe.style.border = '0'
                iframe.src = url
                document.body.appendChild(iframe)
                iframeRef.current = iframe

                iframe.onload = () => {
                    if (cancelled) return
                    try {
                        iframe.contentWindow?.focus()
                        iframe.contentWindow?.print()
                        printedRef.current = true
                        setPhase('done')
                    } catch {
                        showError('Tu navegador no pudo abrir la impresión automática. Usá «Abrir PDF».')
                    }
                }

                window.setTimeout(() => {
                    if (cancelled || printedRef.current) return
                    try {
                        iframe.contentWindow?.focus()
                        iframe.contentWindow?.print()
                        printedRef.current = true
                        setPhase('done')
                    } catch {
                        /* ignore */
                    }
                }, 1500)
            } catch (e) {
                showError(e?.message || 'Error al descargar la etiqueta')
            }
        }

        run()

        return () => {
            cancelled = true
            if (blobUrlRef.current) {
                URL.revokeObjectURL(blobUrlRef.current)
                blobUrlRef.current = null
            }
            if (iframeRef.current?.parentNode) {
                iframeRef.current.parentNode.removeChild(iframeRef.current)
            }
            iframeRef.current = null
        }
    }, [pdfUrl])

    const openPdfHref = blobUrl || pdfUrl

    return (
        <AppShell title="Imprimiendo etiqueta">
            <section className="flex min-h-[calc(100vh-4rem)] flex-col items-center justify-center bg-[#0b1220] px-4 py-10">
                <div className="w-full max-w-md rounded-xl border border-slate-600 bg-[#1b2a41] p-8 text-center">
                    {phase === 'loading' ? (
                        <>
                            <div
                                className="mx-auto mb-5 h-12 w-12 animate-spin rounded-full border-4 border-slate-600 border-t-lime-400"
                                aria-hidden
                            />
                            <h1 className="text-xl font-bold text-white">Imprimiendo etiqueta…</h1>
                            <p className="mt-2 text-sm text-slate-400">
                                Preparando envío #{shippingId}. Se abrirá el diálogo de impresión de tu tablet.
                            </p>
                        </>
                    ) : null}

                    {phase === 'done' ? (
                        <>
                            <h1 className="text-xl font-bold text-white">Listo</h1>
                            <p className="mt-2 text-sm text-slate-400">
                                Si no viste el diálogo de impresión, tocá «Abrir PDF» o volvé a pedidos.
                            </p>
                        </>
                    ) : null}

                    {phase === 'error' ? (
                        <>
                            <h1 className="text-xl font-bold text-red-200">No se pudo imprimir</h1>
                            <p className="mt-2 text-sm text-slate-400">Revisá tu conexión o el token de Mercado Libre.</p>
                            {errorMessage ? <p className="mt-3 text-sm text-red-300">{errorMessage}</p> : null}
                        </>
                    ) : null}

                    {(phase === 'done' || phase === 'error') && (
                        <div className="mt-6 flex flex-wrap items-center justify-center gap-3">
                            <Link
                                href={procesarUrl}
                                className="rounded-lg border border-slate-500 bg-slate-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-slate-600"
                            >
                                Volver a pedidos
                            </Link>
                            <a
                                href={openPdfHref}
                                target="_blank"
                                rel="noreferrer"
                                className="rounded-lg border border-lime-500/60 bg-lime-800/40 px-4 py-2.5 text-sm font-semibold text-lime-100 transition hover:bg-lime-800/60"
                            >
                                Abrir PDF
                            </a>
                        </div>
                    )}
                </div>
            </section>
        </AppShell>
    )
}
