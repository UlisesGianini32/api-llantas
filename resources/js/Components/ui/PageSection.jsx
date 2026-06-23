/**
 * Encabezado de sección reutilizable (dashboard, listados, formularios largos).
 */
export default function PageSection({ eyebrow, title, description }) {
    return (
        <div className="border-b border-slate-200 pb-4 dark:border-neutral-800">
            <p className="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500 dark:text-slate-400">{eyebrow}</p>
            <h3 className="mt-1 text-lg font-bold text-slate-900 dark:text-white">{title}</h3>
            {description && (
                <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">{description}</p>
            )}
        </div>
    )
}
