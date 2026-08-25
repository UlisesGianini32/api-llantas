<?php

$channels = json_decode((string) env('AUTOPARTES_MELI_PUBLISHER_CHANNELS_JSON', '[]'), true);

return [
    'enabled' => filter_var(env('AUTOPARTES_MELI_PUBLISHER_ENABLED', false), FILTER_VALIDATE_BOOL),
    'remote_validation_enabled' => filter_var(env('AUTOPARTES_MELI_REMOTE_VALIDATION_ENABLED', false), FILTER_VALIDATE_BOOL),
    'image_upload_enabled' => filter_var(env('AUTOPARTES_MELI_IMAGE_UPLOAD_ENABLED', false), FILTER_VALIDATE_BOOL),
    'live_enabled' => filter_var(env('AUTOPARTES_MELI_LIVE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'account_id' => env('AUTOPARTES_MELI_PUBLISHER_ACCOUNT_ID'),
    'listing_type_id' => env('AUTOPARTES_MELI_LISTING_TYPE_ID'),
    'buying_mode' => env('AUTOPARTES_MELI_BUYING_MODE', 'buy_it_now'),
    'channels' => is_array($channels) ? array_values($channels) : [],
    'max_batch' => (int) env('AUTOPARTES_MELI_PUBLISHER_MAX_BATCH', 1),
    'max_daily_items' => (int) env('AUTOPARTES_MELI_PUBLISHER_MAX_DAILY_ITEMS', 1),
    'timeout' => (int) env('AUTOPARTES_MELI_PUBLISHER_TIMEOUT', 30),
    'validation_ttl_minutes' => (int) env('AUTOPARTES_MELI_VALIDATION_TTL_MINUTES', 60),
    'rules_version' => env('AUTOPARTES_MELI_PUBLISHER_RULES_VERSION', 'v1'),
    'base_url' => 'https://api.mercadolibre.com',
    'max_persisted_response_bytes' => 65535,
];
