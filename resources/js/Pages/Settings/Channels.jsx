import { Head } from '@inertiajs/react'
import SettingsLayout from '@/Components/settings/SettingsLayout'
import ChannelCards from '@/Components/settings/ChannelCards'

export default function Channels() {
    return (
        <>
            <Head title="Canales de venta" />

            <SettingsLayout
                title="Canales de venta"
                description="Gestiona y monitorea la vinculación de tus cuentas de Mercado Libre, Shopify y Amazon SP-API."
                current="channels"
            >
                <ChannelCards />
            </SettingsLayout>
        </>
    )
}
