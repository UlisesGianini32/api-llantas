import { defineConfig, loadEnv } from 'vite'
import laravel from 'laravel-vite-plugin'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import path from 'path'

/**
 * Laravel + React (Inertia). En Plesk en producción solo usas `npm run build`;
 * el bloque `server` aplica en desarrollo local o si corres Vite en el servidor.
 *
 * Variables útiles (.env):
 * - APP_URL / VITE_APP_URL: URL pública del sitio (ej. https://tudominio.com en Plesk).
 * - VITE_DEV_BIND: interfaz donde escucha Vite (default 0.0.0.0 = toda la red).
 * - VITE_DEV_PORT: puerto del dev server (default 5173).
 * - VITE_DEV_HMR_HOST: host que el navegador usa para WebSocket HMR (IP o dominio).
 * - VITE_DEV_HMR_PROTOCOL: ws | wss (en HTTPS detrás de Plesk a veces hace falta wss).
 * - VITE_DEV_HMR_CLIENT_PORT: puerto visible para el cliente si hay proxy (443, 8443, etc.).
 */
export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), '')

    const devBind = env.VITE_DEV_BIND?.trim() || '0.0.0.0'
    const devPort = parseInt(env.VITE_DEV_PORT || '5173', 10)
    const hmrHost =
        env.VITE_DEV_HMR_HOST?.trim() ||
        env.VITE_DEV_HOST?.trim() ||
        'localhost'
    const hmrProtocol =
        env.VITE_DEV_HMR_PROTOCOL === 'https' || env.VITE_DEV_HMR_PROTOCOL === 'wss'
            ? 'wss'
            : 'ws'
    const hmrClientPort = env.VITE_DEV_HMR_CLIENT_PORT?.trim()
        ? parseInt(env.VITE_DEV_HMR_CLIENT_PORT, 10)
        : undefined

    return {
        plugins: [
            laravel({
                input: [
                    'resources/css/app.css',
                    'resources/js/app.jsx',
                ],
                refresh: true,
            }),
            react(),
            tailwindcss(),
        ],
        resolve: {
            alias: {
                '@': path.resolve(__dirname, 'resources/js'),
            },
        },
        server: {
            host: devBind,
            port: devPort,
            strictPort: true,
            hmr: {
                host: hmrHost,
                protocol: hmrProtocol,
                ...(hmrClientPort ? { clientPort: hmrClientPort } : {}),
            },
        },
    }
})
