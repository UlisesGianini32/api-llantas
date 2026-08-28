<?php

$images = json_decode((string) env('AUTOPARTES_DRAFT_IMAGES_JSON', '[]'), true);

return [
    'enabled' => filter_var(env('AUTOPARTES_DRAFTS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'usd_mxn_rate' => env('AUTOPARTES_USD_MXN_RATE'),
    'price_markup_percent' => env('AUTOPARTES_PRICE_MARKUP', 0),
    'meli_fee_percent' => env('AUTOPARTES_MELI_FEE_PERCENT', 0),
    'max_batch' => (int) env('AUTOPARTES_DRAFT_MAX_BATCH', 10),
    'condition' => env('AUTOPARTES_DRAFT_CONDITION'),
    'currency' => 'MXN',
    'title_min_length' => 10,
    'title_max_length' => 60,
    'description_min_length' => 40,
    'description_max_length' => 50000,
    'rules_version' => 'v1',

    // Solo URLs respaldadas y configuradas expresamente por source_key.
    // No se descargan, validan externamente ni buscan imágenes en esta fase.
    'images_by_source_key' => is_array($images) ? $images : [],
];
