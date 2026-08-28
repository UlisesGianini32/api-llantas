<?php

return [
    'stale_after_hours' => 24,
    'focused_catalog' => [
        // Populate only with root category IDs verified from real Mercado Libre category paths.
        'allowed_root_category_ids' => [],
        // Verified category IDs. Each ID permits that category and its complete subtree.
        'allowed_category_ids' => [],
        'category_cache_ttl_days' => 30,
    ],
];
