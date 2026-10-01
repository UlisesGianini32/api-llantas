import { useEffect, useRef, useState } from 'react'
import { Html5Qrcode, Html5QrcodeSupportedFormats } from 'html5-qrcode'

export default function CameraBarcodeScanner({
    isOpen,
    onScan,
    onClose,
    title = 'Escanear Código de Barras con Cámara',
}) {
    const [cameras, setCameras] = useState([])
    const [selectedCameraId, setSelectedCameraId] = useState(null)
    const [torchOn, setTorchOn] = useState(false)
    const [hasTorch, setHasTorch] = useState(false)
    const [isScanning, setIsScanning] = useState(false)
    const [errorMessage, setErrorMessage] = useState(null)

    const scannerRef = useRef(null)
    const containerId = 'camera-barcode-reader-view'

    // Limpieza o inicialización al abrir/cerrar
    useEffect(() => {
        if (!isOpen) {
            stopScanning()
            return
        }

        let isMounted = true

        const initScanner = async () => {
            setErrorMessage(null)
            try {
                // Obtener lista de cámaras disponibles
                const devices = await Html5Qrcode.getCameras()
                if (!isMounted) return

                if (!devices || devices.length === 0) {
                    setErrorMessage('No se encontró ninguna cámara en este dispositivo.')
                    return
                }

                setCameras(devices)

                // Preferir cámara trasera ('back', 'rear', 'environment')
                const backCamera = devices.find(
                    (d) =>
                        d.label.toLowerCase().includes('back') ||
                        d.label.toLowerCase().includes('rear') ||
                        d.label.toLowerCase().includes('trasera') ||
                        d.label.toLowerCase().includes('environment')
                )
                const targetCameraId = backCamera ? backCamera.id : devices[0].id
                setSelectedCameraId(targetCameraId)

                // Arrancar el escáner
                await startScanner(targetCameraId)
            } catch (err) {
                if (!isMounted) return
                console.error('Error al inicializar cámara:', err)
                if (err.name === 'NotAllowedError' || String(err).includes('Permission')) {
                    setErrorMessage('Permiso de cámara denegado. Permite el acceso a la cámara en los ajustes de tu navegador.')
                } else {
                    setErrorMessage('No se pudo acceder a la cámara: ' + (err.message || String(err)))
                }
            }
        }

        initScanner()

        return () => {
            isMounted = false
            stopScanning()
        }
    }, [isOpen])

    const startScanner = async (cameraId) => {
        try {
            if (scannerRef.current) {
                await stopScanning()
            }

            const html5QrCode = new Html5Qrcode(containerId, {
                formatsToSupport: [
                    Html5QrcodeSupportedFormats.EAN_13,
                    Html5QrcodeSupportedFormats.EAN_8,
                    Html5QrcodeSupportedFormats.CODE_128,
                    Html5QrcodeSupportedFormats.CODE_39,
                    Html5QrcodeSupportedFormats.UPC_A,
                    Html5QrcodeSupportedFormats.UPC_E,
                    Html5QrcodeSupportedFormats.QR_CODE,
                    Html5QrcodeSupportedFormats.ITF,
                ],
                verbose: false,
            })
            scannerRef.current = html5QrCode

            const config = {
                fps: 15,
                qrbox: (viewfinderWidth, viewfinderHeight) => {
                    const minEdge = Math.min(viewfinderWidth, viewfinderHeight)
                    const qrboxWidth = Math.floor(minEdge * 0.85)
                    const qrboxHeight = Math.floor(qrboxWidth * 0.65)
                    return { width: qrboxWidth, height: qrboxHeight }
                },
                aspectRatio: 1.333333,
            }

            await html5QrCode.start(
                cameraId,
                config,
                (decodedText) => {
                    // Éxito en lectura
                    if (navigator.vibrate) {
                        navigator.vibrate(100)
                    }
                    onScan(decodedText)
                },
                () => {
                    // Error de decodificación por cuadro (normal mientras busca)
                }
            )

            setIsScanning(true)

            // Comprobar si soporta linterna (torch)
            try {
                const capabilities = html5QrCode.getRunningTrackCameraCapabilities()
                if (capabilities && typeof capabilities.torchFeature === 'function' && capabilities.torchFeature().isSupported()) {
                    setHasTorch(true)
                } else {
                    setHasTorch(false)
                }
            } catch {
                setHasTorch(false)
            }
        } catch (err) {
            console.error('Error al iniciar escaneo:', err)
            setErrorMessage('Error al iniciar el video de la cámara: ' + (err.message || String(err)))
        }
    }

    const stopScanning = async () => {
        if (scannerRef.current) {
            try {
                if (scannerRef.current.isScanning) {
                    await scannerRef.current.stop()
                }
                scannerRef.current.clear()
            } catch (err) {
                console.warn('Error al detener cámara:', err)
            }
            scannerRef.current = null
        }
        setIsScanning(false)
        setTorchOn(false)
    }

    const toggleTorch = async () => {
        if (!scannerRef.current || !hasTorch) return
        try {
            const nextState = !torchOn
            await scannerRef.current.applyVideoConstraints({
                advanced: [{ torch: nextState }],
            })
            setTorchOn(nextState)
        } catch (err) {
            console.warn('No se pudo activar la linterna:', err)
        }
    }

    const switchCamera = async (newId) => {
        setSelectedCameraId(newId)
        await startScanner(newId)
    }

    if (!isOpen) return null

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-3 backdrop-blur-sm">
            <div className="relative flex w-full max-w-md flex-col overflow-hidden rounded-3xl bg-neutral-900 shadow-2xl border border-neutral-800 text-white">
                {/* Header */}
                <div className="flex items-center justify-between border-b border-neutral-800 px-4 py-3">
                    <div className="flex items-center gap-2">
                        <span className="flex h-8 w-8 items-center justify-center rounded-xl bg-indigo-600/30 text-indigo-400">
                            📷
                        </span>
                        <div>
                            <h3 className="text-sm font-bold text-white">{title}</h3>
                            <p className="text-[11px] text-neutral-400">Apunta la cámara al código de barras</p>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-xl p-1.5 text-neutral-400 hover:bg-neutral-800 hover:text-white"
                        title="Cerrar cámara"
                    >
                        ✕
                    </button>
                </div>

                {/* Viewfinder area */}
                <div className="relative bg-black min-h-[300px] flex items-center justify-center overflow-hidden">
                    {errorMessage ? (
                        <div className="p-6 text-center text-xs text-rose-400">
                            <span className="text-2xl block mb-2">⚠️</span>
                            {errorMessage}
                        </div>
                    ) : (
                        <div id={containerId} className="w-full h-full overflow-hidden" />
                    )}

                    {/* Guía visual de escaneo */}
                    {isScanning && !errorMessage && (
                        <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                            <div className="relative w-64 h-36 rounded-2xl border-2 border-indigo-400/80 shadow-[0_0_20px_rgba(99,102,241,0.5)]">
                                <div className="absolute top-0 left-0 right-0 h-0.5 bg-indigo-400 shadow-[0_0_8px_#818cf8] animate-pulse" />
                                <div className="absolute -top-1 -left-1 w-3 h-3 border-t-2 border-l-2 border-white rounded-tl" />
                                <div className="absolute -top-1 -right-1 w-3 h-3 border-t-2 border-r-2 border-white rounded-tr" />
                                <div className="absolute -bottom-1 -left-1 w-3 h-3 border-b-2 border-l-2 border-white rounded-bl" />
                                <div className="absolute -bottom-1 -right-1 w-3 h-3 border-b-2 border-r-2 border-white rounded-br" />
                            </div>
                        </div>
                    )}
                </div>

                {/* Controls Bar */}
                <div className="flex items-center justify-between gap-2 border-t border-neutral-800 bg-neutral-950 px-4 py-3">
                    {cameras.length > 1 ? (
                        <select
                            value={selectedCameraId || ''}
                            onChange={(e) => switchCamera(e.target.value)}
                            className="rounded-xl border border-neutral-700 bg-neutral-900 px-2 py-1.5 text-xs text-white"
                        >
                            {cameras.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.label || `Cámara ${c.id}`}
                                </option>
                            ))}
                        </select>
                    ) : (
                        <span className="text-[11px] text-neutral-400">Cámara activa</span>
                    )}

                    <div className="flex items-center gap-2">
                        {hasTorch && (
                            <button
                                type="button"
                                onClick={toggleTorch}
                                className={`rounded-xl px-3 py-1.5 text-xs font-semibold transition ${
                                    torchOn
                                        ? 'bg-amber-500 text-black shadow-lg shadow-amber-500/30'
                                        : 'bg-neutral-800 text-neutral-300 hover:bg-neutral-700'
                                }`}
                            >
                                {torchOn ? '🔦 Linterna ON' : '💡 Linterna'}
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-xl bg-neutral-800 px-4 py-1.5 text-xs font-semibold text-white hover:bg-neutral-700"
                        >
                            Listo / Cerrar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    )
}
