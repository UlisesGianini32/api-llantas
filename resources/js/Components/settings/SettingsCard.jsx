export default function SettingsCard({ title, description, children }) {
    return (
        <div className="rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
            <div className="border-b border-slate-200 px-6 py-5 dark:border-neutral-800">
                <h3 className="text-lg font-semibold text-slate-900 dark:text-white">
                    {title}
                </h3>
                {description && (
                    <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        {description}
                    </p>
                )}
            </div>

            <div className="p-6">{children}</div>
        </div>
    )
}