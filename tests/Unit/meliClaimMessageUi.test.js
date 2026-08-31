import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const source = readFileSync(new URL('../../resources/js/Pages/MeliClaims/Show.jsx', import.meta.url), 'utf8')

test('exige confirmación antes de ejecutar el POST de mensaje', () => {
    assert.match(source, /onClick=\{confirmMessage\}[^>]*>Enviar mensaje/)
    assert.match(source, /Vas a enviar este mensaje a Mercado Libre\./)
    assert.match(source, /onClick=\{sendMessage\}[^>]*disabled=\{sending\}/)
    assert.match(source, />\{sending \? 'Enviando…' : 'Confirmar y enviar'\}<\/button>/)
})

test('envía solamente message y bloquea doble click mientras procesa', () => {
    assert.match(source, /router\.post\(`\/meli-claims\/\$\{claim\.id\}\/messages`, \{ message, attachments \}/)
    assert.match(source, /if \(sending\) return/)
    assert.match(source, /disabled=\{sending \|\| !message\.trim\(\)\}/)
    assert.match(source, /JPG, PNG o PDF/)
    assert.match(source, />Quitar<\/button>/)
    assert.match(source, /<FileList files=\{attachments\} \/>/)
    assert.match(source, /Ver \/ Descargar/)
})
