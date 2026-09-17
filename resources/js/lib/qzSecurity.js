import qz from 'qz-tray'

let qzSecurityConfigured = false

function csrfToken() {
    const metaToken = document
        .querySelector('meta[name="csrf-token"]')
        ?.getAttribute('content')

    if (metaToken) {
        return metaToken
    }

    const cookie = document.cookie
        .split('; ')
        .find((item) => item.startsWith('XSRF-TOKEN='))

    return cookie
        ? decodeURIComponent(cookie.substring('XSRF-TOKEN='.length))
        : ''
}

export function configureQzSecurity() {
    if (qzSecurityConfigured) {
        return
    }

    qz.security.setCertificatePromise((resolve, reject) => {
        fetch('/qz/certificate', {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                Accept: 'text/plain',
            },
        })
            .then(async (response) => {
                const body = await response.text()

                if (!response.ok) {
                    throw new Error(
                        body || `No se pudo cargar el certificado QZ (${response.status}).`
                    )
                }

                return body
            })
            .then(resolve)
            .catch(reject)
    })

    qz.security.setSignatureAlgorithm('SHA512')

    qz.security.setSignaturePromise((toSign) => {
        return (resolve, reject) => {
            const token = csrfToken()

            fetch('/qz/sign', {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'text/plain',
                    'Content-Type': 'application/json',
                    ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                },
                body: JSON.stringify({
                    request: toSign,
                }),
            })
                .then(async (response) => {
                    const body = await response.text()

                    if (!response.ok) {
                        throw new Error(
                            body || `No se pudo firmar la solicitud QZ (${response.status}).`
                        )
                    }

                    return body.trim()
                })
                .then(resolve)
                .catch(reject)
        }
    })

    qzSecurityConfigured = true
}
