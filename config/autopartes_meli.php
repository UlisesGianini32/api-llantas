<?php

return [
    'enabled' => filter_var(env('AUTOPARTES_MELI_ENABLED', false), FILTER_VALIDATE_BOOL),
    'site_id' => env('AUTOPARTES_MELI_SITE_ID', 'MLM'),
    'base_url' => env('AUTOPARTES_MELI_BASE_URL', 'https://api.mercadolibre.com'),
    'timeout' => (int) env('AUTOPARTES_MELI_TIMEOUT', 20),
    'cache_ttl' => (int) env('AUTOPARTES_MELI_CACHE_TTL', 86400),
    'max_batch' => (int) env('AUTOPARTES_MELI_MAX_BATCH', 10),
    'max_daily_requests' => (int) env('AUTOPARTES_MELI_MAX_DAILY_REQUESTS', 100),
    'max_candidates' => (int) env('AUTOPARTES_MELI_MAX_CANDIDATES', 5),
    'rules_version' => 'v1',
    'paths' => [
        'site_categories' => '/sites/{site_id}/categories',
        'domain_discovery' => '/sites/{site_id}/domain_discovery/search',
        'category' => '/categories/{category_id}',
        'category_attributes' => '/categories/{category_id}/attributes',
        'domain' => '/catalog_domains/{domain_id}',
        'compatibility_restrictions' => '/catalog_compatibilities/restrictions/values',
    ],

    // Sin IDs predeterminados: cada regla debe configurarse con un category_id
    // confirmado contra metadatos oficiales antes de producir candidatos.
    'deterministic_rules' => [],
];
