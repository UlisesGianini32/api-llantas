import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { after, test } from 'node:test'
import React from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { JSDOM } from 'jsdom'
import { transformWithEsbuild } from 'vite'
import ClaimMessageBody from '../../resources/js/Components/ClaimMessageBody.js'
import { listData, playerRole } from '../../resources/js/lib/meliClaimsPresentation.js'

// No scripts or external resource loading are enabled in this DOM.
const dom = new JSDOM('', { url: 'https://example.test' })
globalThis.window = dom.window

function render(element) {
    const markup = renderToStaticMarkup(element)
    const container = dom.window.document.createElement('div')
    container.innerHTML = markup
    return { markup, container }
}
const body = value => render(React.createElement(ClaimMessageBody, { value }))

test('plain text is React text, preserving newlines and literal angle brackets', () => {
    const value = 'Hola equipo\nCantidad < 3 y precio > 1 & gracias'
    const { container, markup } = body(value)
    assert.equal(container.firstChild.tagName, 'P')
    assert.equal(container.textContent, value)
    assert.ok(container.firstChild.classList.contains('whitespace-pre-wrap'))
    assert.match(markup, /&lt; 3/)
    assert.equal(container.querySelectorAll('p').length, 1)
})

test('script-shaped content inside plain text is escaped by React', () => {
    const value = 'Hola <script>alert(1)</script>'
    const { container, markup } = body(value)
    assert.equal(container.textContent, value)
    assert.equal(container.querySelector('script'), null)
    assert.match(markup, /&lt;script&gt;/)
})

test('renders MeLi p/span/strong/br and supported emphasis and lists', () => {
    const { container } = body('<p><span>Hola <strong>equipo</strong><br>segunda línea</span></p><ul><li><b>Uno</b></li></ul><ol><li><em>Dos</em> <i>tres</i></li></ol>')
    assert.equal(container.querySelector('p span strong').textContent, 'equipo')
    assert.ok(container.querySelector('p span br'))
    assert.equal(container.querySelector('ul li b').textContent, 'Uno')
    assert.equal(container.querySelector('ol li em').textContent, 'Dos')
    assert.equal(container.querySelector('ol li i').textContent, 'tres')
})

test('absolute HTTPS links keep their destination/title and open in the same tab', () => {
    const { container } = body('<a href="https://www.mercadolibre.com.mx/ayuda?a=1&amp;b=2" title="Ayuda" target="_blank" rel="opener">Consultar</a>')
    const link = container.querySelector('a')
    assert.equal(link.getAttribute('href'), 'https://www.mercadolibre.com.mx/ayuda?a=1&b=2')
    assert.equal(link.title, 'Ayuda')
    assert.equal(link.hasAttribute('target'), false)
    assert.equal(link.hasAttribute('rel'), false)
})

for (const href of ['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'java&#x09;script:alert(1)', 'data:text/html,malicious', 'vbscript:alert(1)', '//example.test', '/relative']) {
    test(`rejects link destination: ${href}`, () => {
        const { container } = body(`<a href="${href}">click</a>`)
        assert.equal(container.querySelector('a').getAttribute('href'), null)
        assert.equal(container.textContent, 'click')
    })
}

test('scripts and their executable content are removed, including mixed text', () => {
    const { container } = body('<p>Hola</p><script>alert(1)</script><p>equipo</p>')
    assert.equal(container.querySelector('script'), null)
    assert.deepEqual([...container.querySelectorAll('p')].map(paragraph => paragraph.textContent), ['Hola', 'equipo'])
    assert.equal(body('<script>alert(1)</script>').container.textContent, 'Mensaje sin texto visible')
})

test('removes forbidden elements, event handlers and remote style/class attributes', () => {
    const value = '<p onclick="alert(1)" style="color:red" class="remote" id="remote" data-test="x" aria-label="remote">Seguro <span style="white-space: pre-wrap" onmouseover="alert(2)">texto</span></p>'
        + '<img src=x onerror=alert(1)><svg onload="alert(1)"><a href="javascript:alert(1)">svg</a></svg>'
        + '<style>body{display:none}</style><iframe src="https://example.test"></iframe><object data="x"></object><embed src="x">'
        + '<form action="https://example.test"><input value="x"><button onclick="alert(1)">botón</button></form>'
    const { container } = body(value)
    assert.equal(container.querySelector('script,style,iframe,object,embed,img,svg,form,input,button'), null)
    for (const element of container.firstChild.querySelectorAll('*')) {
        for (const attribute of element.attributes) {
            assert.ok(['href', 'title'].includes(attribute.name), `Unexpected ${attribute.name}`)
        }
    }
    assert.match(container.textContent, /Seguro texto/)
})

test('drops empty nbsp/whitespace/br paragraphs and preserves real paragraphs', () => {
    const { container } = body('<p>&nbsp;</p>\n<p> \n </p><p><span>&nbsp; </span><br></p><p>Primero&nbsp;real</p><p><strong>Segundo</strong></p>')
    assert.equal(container.querySelectorAll('p').length, 2)
    assert.equal(container.querySelectorAll('p')[0].textContent, 'Primero\u00a0real')
    assert.equal(container.querySelector('strong').textContent, 'Segundo')
})

test('empty text or sanitized messages retain the visible-text fallback', () => {
    for (const value of ['', null, undefined, ' \n ', '<p>&nbsp;</p>', '<img src=x onerror=alert(1)>']) {
        const { container } = body(value)
        assert.equal(container.textContent, 'Mensaje sin texto visible')
        assert.equal(container.querySelector('div'), null)
    }
})

test('malformed and namespace markup does not become executable after reparse', () => {
    for (const value of [
        '<svg><g/onload=alert(2)//<p>texto</p>',
        '<math><mtext><table><mglyph><style><!--</style><img title="--><img src=x onerror=alert(1)>">',
        '<p><a href="javascript:alert(1)"><strong>texto</p>',
    ]) {
        const { container } = body(value)
        assert.equal(container.querySelector('script,style,svg,math,img,iframe,[onerror],[onload]'), null)
        for (const link of container.querySelectorAll('a[href]')) assert.match(link.getAttribute('href'), /^https?:\/\//i)
    }
})

// Exercise the actual Messages function without mounting unrelated Inertia page controls.
const pageSource = readFileSync(new URL('../../resources/js/Pages/MeliClaims/Show.jsx', import.meta.url), 'utf8')
const messagesSource = pageSource.slice(pageSource.indexOf('function Messages('), pageSource.indexOf('function Changes('))
const compiled = await transformWithEsbuild(messagesSource, 'Messages.jsx', { loader: 'jsx', jsx: 'transform' })
const Messages = new Function('React', 'ClaimMessageBody', 'listData', 'playerRole', 'date', 'fileSize', 'Empty', `${compiled.code}\nreturn Messages`)(
    React, ClaimMessageBody, listData, playerRole,
    value => value ? new Date(value).toLocaleString('es-MX') : '—',
    bytes => `${Math.round(bytes / 1024)} KB`,
    ({ children }) => React.createElement('p', null, children),
)

test('Messages retains translated fallback, order, roles, dates and download attachments', () => {
    const value = [
        { message: '', translated_message: '<p>Traducción <strong>visible</strong></p>', sender_role: 'complainant', receiver_role: 'respondent', date_created: '2026-09-01T10:00:00Z', attachments: [{ filename: 'reporte uno.pdf', original_filename: 'Reporte.pdf', type: 'pdf', size: 2048 }] },
        { message: 'Mensaje reciente', translated_message: 'No usar', sender_role: 'respondent', receiver_role: 'mediator', message_date: '2026-09-02T10:00:00Z', attachments: [{ file_name: 'foto.jpg' }] },
    ]
    const original = structuredClone(value)
    const { container } = render(React.createElement(Messages, { value, claimId: 42 }))
    const articles = container.querySelectorAll('article')
    assert.equal(articles.length, 2)
    assert.match(articles[0].textContent, /Mensaje reciente/)
    assert.doesNotMatch(articles[0].textContent, /No usar/)
    assert.match(articles[1].textContent, /Comprador → Vendedor/)
    assert.ok(articles[1].textContent.includes(new Date(value[0].date_created).toLocaleString('es-MX')))
    assert.equal(articles[1].querySelector('strong').textContent, 'visible')
    assert.match(articles[1].textContent, /Adjunto: Reporte.pdf · pdf · 2 KB/)
    assert.equal(articles[1].querySelector('a').getAttribute('href'), '/meli-claims/42/attachments/reporte%20uno.pdf/download')
    assert.equal(articles[0].querySelector('a').getAttribute('href'), '/meli-claims/42/attachments/foto.jpg/download')
    assert.equal(articles[1].querySelector('a').textContent, 'Ver / Descargar')
    assert.deepEqual(value, original)
})

after(() => { delete globalThis.window; dom.window.close() })
