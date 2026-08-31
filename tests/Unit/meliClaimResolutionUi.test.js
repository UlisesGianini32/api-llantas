import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const source = readFileSync(new URL('../../resources/js/Pages/MeliClaims/Show.jsx', import.meta.url), 'utf8')

test('economic resolutions require explicit guarded confirmation and server offers', () => {
    assert.match(source, /claim\.resolution_actions/)
    assert.match(source, /partial-refund\/offers/)
    assert.match(source, /type="checkbox"/)
    assert.match(source, /disabled=!confirmed|disabled=\{!confirmed/)
    assert.match(source, /type="button"/)
    assert.doesNotMatch(source, /type="number"|type="range"/)
    assert.match(source, /setResolving\(true\)/)
    assert.match(source, /percentage: resolution\.offer\.percentage/)
    assert.match(source, /No se puede deshacer desde este panel/)
    assert.match(source, /El reembolso se procesará de acuerdo con el flujo de devolución/)
    assert.match(source, /claim\.products\?\.\[0\]/)
    assert.doesNotMatch(source, /claim\.product\?\./)
})
