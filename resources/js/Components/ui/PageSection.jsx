/**
 * Encabezado de sección reutilizable (dashboard, listados, formularios largos).
 */
export default function PageSection({ eyebrow, title, description, action }) {
    return (
        <div className="flex flex-col justify-between gap-3 border-b border-slate-200/80 pb-4 sm:flex-row sm:items-end dark:border-neutral-800/80">
            <div>
                {eyebrow && (
                    <span className="inline-flex items-center gap-1.5 rounded-md bg-indigo-50 px-2 py-0.5 text-[11px] font-bold tracking-wider uppercase text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300">
                        <span className="h-1.5 w-1.5 rounded-full bg-indigo-600 dark:bg-indigo-400" />
                        {eyebrow}
                    </span>
                )}
                <h3 className="mt-1.5 text-lg font-bold tracking-tight text-slate-900 dark:text-white sm:text-xl">
                    {title}
                </h3>
                {description && (
                    <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        {description}
                    </p>
                )}
            </div>
            {action && <div className="shrink-0">{action}</div>}
        </div>
    )
}
