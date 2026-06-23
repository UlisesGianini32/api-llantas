import { router } from '@inertiajs/react'

/**
 * Paginación Laravel/Inertia (linkCollection).
 * Oculta el bloque cuando solo hay una página (≤3 enlaces: prev, número, next).
 */
export default function Pagination({ links, className = '' }) {
    if (!links || links.length <= 3) return null

    return (
        <div className={`flex flex-wrap items-center gap-2 ${className}`}>
            {links.map((link, index) => {
                const label = link.label
                    .replace('&laquo; Previous', 'Anterior')
                    .replace('Next &raquo;', 'Siguiente')

                if (!link.url) {
                    return (
                        <span
                            key={index}
                            className="rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-400 dark:border-neutral-800 dark:text-slate-600"
                            dangerouslySetInnerHTML={{ __html: label }}
                        />
                    )
                }

                return (
                    <button
                        key={index}
                        type="button"
                        onClick={() =>
                            router.visit(link.url, {
                                preserveScroll: true,
                                preserveState: true,
                            })
                        }
                        className={`rounded-xl border px-4 py-2 text-sm transition ${
                            link.active
                                ? 'border-indigo-500 bg-indigo-600 text-white'
                                : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50 dark:border-neutral-800 dark:bg-neutral-900 dark:text-slate-300 dark:hover:bg-neutral-800'
                        }`}
                        dangerouslySetInnerHTML={{ __html: label }}
                    />
                )
            })}
        </div>
    )
}
