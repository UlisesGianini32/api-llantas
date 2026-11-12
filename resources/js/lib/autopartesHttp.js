import axios from 'axios'

/**
 * Resolve CSRF token from the Laravel Blade meta tag when running in a browser DOM.
 */
export function resolveCsrfToken(doc = typeof document !== 'undefined' ? document : null) {
    const meta = doc?.querySelector?.('meta[name="csrf-token"]')
    const content = meta?.getAttribute?.('content')

    return typeof content === 'string' && content.trim() !== '' ? content.trim() : null
}

/**
 * Create an isolated Axios client for Autopartes pages without mutating window.axios.
 */
export function createAutopartesHttpClient(customDocument) {
    const client = axios.create({
        withCredentials: true,
        withXSRFToken: true,
        xsrfCookieName: 'XSRF-TOKEN',
        xsrfHeaderName: 'X-XSRF-TOKEN',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    })

    client.interceptors.request.use(config => {
        const csrfToken = resolveCsrfToken(customDocument)
        if (csrfToken) {
            config.headers = config.headers || {}
            config.headers['X-CSRF-TOKEN'] = csrfToken
        }

        return config
    })

    return client
}

export const autopartesHttp = createAutopartesHttpClient()

/**
 * Format Axios/Laravel HTTP errors (403, 419, 422, network) into clear Spanish UI feedback.
 */
export function formatAutopartesHttpError(error, fallback = 'Error al comunicarse con el servidor.') {
    const status = error?.response?.status
    const data = error?.response?.data

    if (status === 419) {
        return 'Tu sesión o token CSRF expiró (419). Recarga la página e inicia sesión nuevamente.'
    }

    if (status === 403) {
        const serverMsg = typeof data?.message === 'string' ? data.message.trim() : ''
        if (serverMsg !== '' && serverMsg !== 'This action is unauthorized.' && serverMsg !== 'Forbidden') {
            return serverMsg
        }

        return 'No cuentas con permisos en esta sucursal para realizar esta acción (403).'
    }

    if (status === 422 && data?.errors && typeof data.errors === 'object') {
        const flattened = Object.values(data.errors)
            .flat()
            .filter(msg => typeof msg === 'string' && msg.trim() !== '')

        if (flattened.length > 0) {
            return flattened.join(' ')
        }
    }

    if (typeof data?.message === 'string' && data.message.trim() !== '') {
        return data.message.trim()
    }

    return fallback
}
