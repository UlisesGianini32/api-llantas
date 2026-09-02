const PNG_SIGNATURE = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]
const BASE64_CHUNK_SIZE = 0x8000

export function esAndroid(userAgent = typeof navigator !== 'undefined' ? navigator.userAgent : '') {
    return /Android/i.test(userAgent)
}

export function validarPngBytes(bytes) {
    if (!(bytes instanceof Uint8Array) || bytes.length === 0) {
        throw new Error('El servidor devolvió una imagen PNG vacía.')
    }

    if (
        bytes.length <= PNG_SIGNATURE.length
        || PNG_SIGNATURE.some((expected, index) => bytes[index] !== expected)
    ) {
        throw new Error('El servidor no devolvió una imagen PNG válida.')
    }
}

export function validarPngContentType(contentType) {
    const mimeType = String(contentType || '').split(';', 1)[0].trim().toLowerCase()

    if (mimeType !== 'image/png') {
        throw new Error('El servidor no devolvió una imagen PNG.')
    }
}

export function pngBytesToBase64(bytes, encodeBase64 = (binary) => window.btoa(binary)) {
    validarPngBytes(bytes)

    let binary = ''

    for (let offset = 0; offset < bytes.length; offset += BASE64_CHUNK_SIZE) {
        binary += String.fromCharCode(
            ...bytes.subarray(
                offset,
                Math.min(offset + BASE64_CHUNK_SIZE, bytes.length)
            )
        )
    }

    return encodeBase64(binary)
}

export function rawBtPngUrl(base64) {
    if (!base64) {
        throw new Error('No se pudo codificar la imagen PNG para RawBT.')
    }

    return `rawbt:base64,${base64}`
}

export async function prepararPngParaRawBt(pngUrl) {
    if (!pngUrl) {
        throw new Error('No se pudo generar la URL de la imagen PNG.')
    }

    const response = await fetch(pngUrl, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'image/png',
        },
    })

    if (!response.ok) {
        const message = await response.text()

        throw new Error(message || `Error HTTP ${response.status}`)
    }

    validarPngContentType(response.headers.get('content-type'))

    const bytes = new Uint8Array(await response.arrayBuffer())

    return rawBtPngUrl(pngBytesToBase64(bytes))
}
