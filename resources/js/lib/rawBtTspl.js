const MIN_TSPL_BYTES = 1000
const BASE64_CHUNK_SIZE = 0x8000

export function esAndroid(userAgent = typeof navigator !== 'undefined' ? navigator.userAgent : '') {
    return /Android/i.test(userAgent)
}

export function validarTsplBytes(bytes) {
    if (!(bytes instanceof Uint8Array)) {
        throw new Error('El servidor no devolvió un archivo TSPL binario válido.')
    }

    if (bytes.length < MIN_TSPL_BYTES) {
        throw new Error(`El archivo TSPL parece incompleto (${bytes.length} bytes).`)
    }

    if (!bytes.some((byte) => byte !== 0)) {
        throw new Error('El archivo TSPL está vacío.')
    }
}

export function tsplBytesToBase64(bytes, encodeBase64 = (binary) => window.btoa(binary)) {
    validarTsplBytes(bytes)

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

export async function imprimirTsplConRawBt(tsplUrl) {
    if (!tsplUrl) {
        throw new Error('No se pudo generar la URL del archivo TSPL.')
    }

    const response = await fetch(tsplUrl, {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/octet-stream',
        },
    })

    if (!response.ok) {
        const message = await response.text()

        throw new Error(message || `Error HTTP ${response.status}`)
    }

    const contentType = String(response.headers.get('content-type') || '').toLowerCase()

    if (!contentType.includes('application/octet-stream')) {
        throw new Error('El servidor no devolvió un archivo TSPL binario.')
    }

    const bytes = new Uint8Array(await response.arrayBuffer())
    const base64 = tsplBytesToBase64(bytes)

    try {
        window.location.assign(`rawbt:base64,${base64}`)
    } catch (error) {
        throw new Error(
            'No se pudo abrir RawBT. Verifica que esté instalada y permitida para abrir enlaces rawbt.',
            { cause: error }
        )
    }
}
