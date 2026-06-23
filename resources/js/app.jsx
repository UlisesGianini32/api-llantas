import '../css/app.css'
import { createInertiaApp } from '@inertiajs/react'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import { createRoot } from 'react-dom/client'

const savedTheme = localStorage.getItem('theme') || 'system'
const root = document.documentElement

root.classList.remove('dark')

if (savedTheme === 'dark') {
    root.classList.add('dark')
} else if (savedTheme === 'system') {
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches
    if (prefersDark) {
        root.classList.add('dark')
    }
}

createInertiaApp({
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx')
        ),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />)
    },
    progress: {
        color: '#4f46e5',
    },
})