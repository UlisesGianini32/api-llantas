import createDOMPurify from 'dompurify'
import { createElement, useMemo } from 'react'

const emptyMessage = 'Mensaje sin texto visible'
const policy = {
    ALLOWED_TAGS: ['p', 'br', 'span', 'strong', 'b', 'em', 'i', 'a', 'ul', 'ol', 'li'],
    ALLOWED_ATTR: ['href', 'title'],
    ALLOW_DATA_ATTR: false,
    ALLOW_ARIA_ATTR: false,
    // Narrow the default URI policy to absolute HTTP(S) links. No target survives.
    ALLOWED_URI_REGEXP: /^https?:\/\//i,
}
let purifier

function prepareBody(value) {
    const text = typeof value === 'string' ? value : ''
    if (!text.trim()) return { text: emptyMessage }

    // Detection only, never sanitization. MeLi HTML starts with markup; embedded
    // angle-bracket text stays on React's escaped text path.
    if (!/^\s*<\/?[a-z][^>]*>/i.test(text) || typeof window === 'undefined') return { text }
    purifier ??= createDOMPurify(window)
    if (!purifier.isSupported) return { text }

    const fragment = purifier.sanitize(text, { ...policy, RETURN_DOM_FRAGMENT: true })
    for (const paragraph of fragment.querySelectorAll('p')) {
        if (!paragraph.textContent.trim()) paragraph.remove()
    }
    if (!fragment.textContent.trim()) return { text: emptyMessage }

    const container = window.document.createElement('div')
    container.append(fragment)
    // Sanitize the final serialization too; never insert the original MeLi markup.
    return { html: purifier.sanitize(container.innerHTML, policy) }
}

export default function ClaimMessageBody({ value }) {
    const body = useMemo(() => prepareBody(value), [value])
    if (body.text !== undefined) {
        return createElement('p', { className: 'mt-2 whitespace-pre-wrap break-words' }, body.text)
    }

    return createElement('div', {
        className: 'mt-2 break-words [&_p]:my-2 [&_p]:whitespace-pre-wrap [&_span]:whitespace-pre-wrap [&_a]:text-indigo-600 [&_a]:underline [&_ul]:list-disc [&_ul]:pl-5 [&_ol]:list-decimal [&_ol]:pl-5',
        dangerouslySetInnerHTML: { __html: body.html },
    })
}
