<?php

return [
    'stale_after_hours' => 24,
    'beauty_scheduled_prices' => [
        'enabled' => env('MELI_BEAUTY_SCHEDULED_PRICES_ENABLED', false),
        'default_timezone' => 'America/Hermosillo',
    ],
    'focused_catalog' => [
        // Populate only with root category IDs verified from real Mercado Libre category paths.
        'allowed_root_category_ids' => [
            'MLM1246', // Belleza y Cuidado Personal
        ],
        // Verified category IDs. Each ID permits that category and its complete subtree.
        'allowed_category_ids' => [
            'MLM438195', // Suplementos Alimenticios
            'MLM167994', // Suplementos Deportivos
        ],
        'category_cache_ttl_days' => 30,
    ],
    'beauty' => [
        'allowed_root_category_ids' => [
            'MLM1246', // Belleza y Cuidado Personal
        ],
        'allowed_category_ids' => [],
        'default_timezone' => 'America/Hermosillo',
    ],
];
