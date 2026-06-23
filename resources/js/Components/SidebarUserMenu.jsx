import React, { useEffect, useRef, useState } from 'react'

export default function SidebarUserMenu({
    user = {
        name: 'Usuario',
        email: 'usuario@correo.com',
    },
}) {
    const [open, setOpen] = useState(false)
    const menuRef = useRef(null)

    useEffect(() => {
        function handleClickOutside(event) {
            if (menuRef.current && !menuRef.current.contains(event.target)) {
                setOpen(false)
            }
        }

        document.addEventListener('mousedown', handleClickOutside)

        return () => {
            document.removeEventListener('mousedown', handleClickOutside)
        }
    }, [])

    const handleLogout = () => {
        const csrf = document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content')

        if (!csrf) {
            alert('No se encontró el token CSRF.')
            return
        }

        const form = document.createElement('form')
        form.method = 'POST'
        form.action = '/logout'

        const tokenInput = document.createElement('input')
        tokenInput.type = 'hidden'
        tokenInput.name = '_token'
        tokenInput.value = csrf

        form.appendChild(tokenInput)
        document.body.appendChild(form)
        form.submit()
    }

    return (
        <div className="relative" ref={menuRef}>
            <button
                type="button"
                onClick={() => setOpen((prev) => !prev)}
                className="w-full rounded-2xl border border-slate-200 bg-white p-4 text-left shadow-sm transition hover:border-slate-300 hover:shadow-md"
            >
                <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                    Usuario
                </div>

                <div className="mt-2 flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <div className="truncate text-base font-semibold text-slate-900">
                            {user.name}
                        </div>
                        <div className="truncate text-sm text-slate-500">
                            {user.email}
                        </div>
                    </div>

                    <svg
                        className={`h-5 w-5 text-slate-400 transition-transform ${
                            open ? 'rotate-180' : ''
                        }`}
                        viewBox="0 0 20 20"
                        fill="currentColor"
                    >
                        <path
                            fillRule="evenodd"
                            d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
                            clipRule="evenodd"
                        />
                    </svg>
                </div>
            </button>

            {open && (
                <div className="absolute bottom-full left-0 z-50 mb-2 w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl">
                    <a
                        href="/settings/profile"
                        className="block px-4 py-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                    >
                        Settings
                    </a>

                    <button
                        type="button"
                        onClick={handleLogout}
                        className="block w-full px-4 py-3 text-left text-sm font-medium text-red-600 transition hover:bg-red-50"
                    >
                        Logout
                    </button>
                </div>
            )}
        </div>
    )
}