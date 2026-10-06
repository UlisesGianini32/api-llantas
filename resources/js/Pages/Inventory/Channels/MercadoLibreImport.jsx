import ChannelLinksImport from './ChannelLinksImport'

export default function MercadoLibreImport(props) {
    return <ChannelLinksImport {...props} activeChannel={props.activeChannel || 'mercado_libre'} />
}
