<?php

namespace App\Services\Autopartes\Meli;

class AutomotivePartMeliConfiguration
{
    public function isEnabled(): bool
    {
        return (bool) config('autopartes_meli.enabled', false);
    }

    public function siteId(): string
    {
        return strtoupper(trim((string) config('autopartes_meli.site_id', 'MLM')));
    }

    public function assertReady(): void
    {
        if (! $this->isEnabled()) {
            throw new AutomotivePartMeliException(
                'La integración de categorías de Mercado Libre está deshabilitada.',
                'integration_disabled',
            );
        }

        if (! preg_match('/^[A-Z]{3}$/', $this->siteId())) {
            throw new AutomotivePartMeliException('AUTOPARTES_MELI_SITE_ID no es válido.', 'invalid_site_id');
        }

        $baseUrl = rtrim((string) config('autopartes_meli.base_url'), '/');
        if (parse_url($baseUrl, PHP_URL_SCHEME) !== 'https' || parse_url($baseUrl, PHP_URL_HOST) !== 'api.mercadolibre.com') {
            throw new AutomotivePartMeliException('AUTOPARTES_MELI_BASE_URL no es un endpoint oficial permitido.', 'invalid_base_url');
        }
    }

    public function publicSettings(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'site_id' => $this->siteId(),
            'max_batch' => max(1, (int) config('autopartes_meli.max_batch', 10)),
            'max_candidates' => max(1, min(8, (int) config('autopartes_meli.max_candidates', 5))),
            'rules_version' => (string) config('autopartes_meli.rules_version', 'v1'),
        ];
    }
}
