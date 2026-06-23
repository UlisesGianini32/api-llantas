<?php

namespace App\Support;

/**
 * Constantes y reglas alineadas con la API pública de mensajería posventa de Mercado Libre.
 *
 * @see https://developers.mercadolibre.cl/es_ar/mensajeria-post-venta
 * @see https://developers.mercadolibre.com.mx/es_ar/mensajeria-post-venta
 */
final class MeliPostSaleMessaging
{
    public const DOCS_URL = 'https://developers.mercadolibre.cl/es_ar/mensajeria-post-venta';

    public const API_BASE = 'https://api.mercadolibre.com';

    /** Query obligatorio en GET/POST posventa según documentación. */
    public const TAG_POST_SALE = 'post_sale';

    /**
     * @return list<string>
     */
    public static function allAgentUserIds(): array
    {
        $agents = config('meli_menu.message_agents', []);

        return array_values(array_map(static fn ($id) => (string) $id, $agents));
    }

    public static function isMessagingAgentUserId(string $userId): bool
    {
        $id = trim($userId);
        if ($id === '') {
            return false;
        }

        foreach (self::allAgentUserIds() as $agentId) {
            if ($agentId === $id) {
                return true;
            }
        }

        return false;
    }

    public static function agentUserIdForSite(string $siteId): ?string
    {
        $agents = config('meli_menu.message_agents', []);

        if (! isset($agents[$siteId])) {
            return null;
        }

        return (string) $agents[$siteId];
    }

    /**
     * MLB y MLC: desde la nueva capa de agentes, el POST debe usar to.user_id = agente (doc ML).
     *
     * @return list<string>
     */
    public static function sitesWhereAgentRecipientIsMandatory(): array
    {
        $sites = config('meli_menu.message_agent_required_sites', ['MLB', 'MLC']);

        return is_array($sites) ? array_values($sites) : ['MLB', 'MLC'];
    }

    public static function mustSendToMessagingAgent(string $siteId): bool
    {
        return in_array($siteId, self::sitesWhereAgentRecipientIsMandatory(), true);
    }
}
