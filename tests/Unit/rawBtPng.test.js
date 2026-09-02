import assert from 'node:assert/strict'
import test from 'node:test'
import { esAndroid, pngBytesToBase64, rawBtPngUrl, validarPngBytes, validarPngContentType } from '../../resources/js/lib/rawBtPng.js'

const pngBytes = (length = 64) => {
    const bytes = new Uint8Array(length)
    bytes.set([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])

    for (let index = 8; index < bytes.length; index++) {
        bytes[index] = index % 256
    }

    return bytes
}

test('detecta Android sin depender de navigator durante SSR', () => {
    assert.equal(esAndroid('Mozilla/5.0 (Linux; Android 14)'), true)
    assert.equal(esAndroid('Mozilla/5.0 (Windows NT 10.0; Win64; x64)'), false)
    assert.equal(esAndroid(''), false)
})

test('rechaza una respuesta vacía o sin firma PNG', () => {
    assert.throws(() => validarPngBytes(new Uint8Array()), /vacía/)
    assert.throws(() => validarPngBytes(new Uint8Array(64)), /PNG válida/)
    assert.doesNotThrow(() => validarPngBytes(pngBytes()))
})

test('acepta únicamente el Content-Type de una imagen PNG', () => {
    assert.doesNotThrow(() => validarPngContentType('image/png'))
    assert.doesNotThrow(() => validarPngContentType('image/png; charset=binary'))
    assert.throws(() => validarPngContentType('text/html'), /imagen PNG/)
    assert.throws(() => validarPngContentType(''), /imagen PNG/)
})

test('convierte todos los bytes PNG por bloques sin tratarlos como UTF-8', () => {
    const bytes = pngBytes(0x8000 + 17)
    const base64 = pngBytesToBase64(
        bytes,
        (binary) => Buffer.from(binary, 'latin1').toString('base64')
    )

    assert.equal(base64, Buffer.from(bytes).toString('base64'))
})

test('construye exactamente el esquema base64 esperado por RawBT', () => {
    assert.equal(
        rawBtPngUrl('iVBORw0KGgo='),
        'rawbt:base64,iVBORw0KGgo='
    )
})
