import assert from 'node:assert/strict'
import test from 'node:test'
import { esAndroid, tsplBytesToBase64, validarTsplBytes } from '../../resources/js/lib/rawBtTspl.js'

test('detecta Android sin depender de navigator durante SSR', () => {
    assert.equal(esAndroid('Mozilla/5.0 (Linux; Android 14)'), true)
    assert.equal(esAndroid('Mozilla/5.0 (Windows NT 10.0; Win64; x64)'), false)
})

test('rechaza archivos TSPL pequeños o vacíos', () => {
    assert.throws(() => validarTsplBytes(new Uint8Array(999)), /incompleto/)
    assert.throws(() => validarTsplBytes(new Uint8Array(1000)), /vacío/)
})

test('convierte todos los bytes por bloques sin tratarlos como UTF-8', () => {
    const bytes = new Uint8Array(0x8000 + 17)

    for (let index = 0; index < bytes.length; index++) {
        bytes[index] = index % 256
    }

    const base64 = tsplBytesToBase64(
        bytes,
        (binary) => Buffer.from(binary, 'latin1').toString('base64')
    )

    assert.equal(base64, Buffer.from(bytes).toString('base64'))
})
