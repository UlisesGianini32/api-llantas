import { Head } from '@inertiajs/react'
import SettingsLayout from '@/Components/settings/SettingsLayout'
import SettingsCard from '@/Components/settings/SettingsCard'

export default function TwoFactor() {
    return (
        <>
            <Head title="Autenticación de dos factores" />

            <SettingsLayout
                title="Ajustes"
                description="Gestiona tu perfil y la configuración de tu cuenta."
                current="two-factor"
            >
                <SettingsCard
                    title="Autenticación de dos factores"
                    description="Agrega una capa adicional de seguridad."
                >
                    <p className="text-sm text-slate-600 dark:text-slate-300">
                        Aquí puedes integrar después la lógica real de autenticación en dos pasos.
                    </p>
                </SettingsCard>
            </SettingsLayout>
        </>
    )
}