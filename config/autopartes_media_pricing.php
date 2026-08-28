<?php

return [
    'enabled' => env('AUTOPARTES_MEDIA_PRICING_ENABLED', false),
    'media_disk' => env('AUTOPARTES_MEDIA_DISK', 'local'),
    'media_max_file_kb' => (int) env('AUTOPARTES_MEDIA_MAX_FILE_KB', 5120),
    'media_max_width' => (int) env('AUTOPARTES_MEDIA_MAX_WIDTH', 4096),
    'media_max_height' => (int) env('AUTOPARTES_MEDIA_MAX_HEIGHT', 4096),
    'media_max_images_per_part' => (int) env('AUTOPARTES_MEDIA_MAX_IMAGES_PER_PART', 10),
    'price_max_batch' => (int) env('AUTOPARTES_PRICE_MAX_BATCH', 25),
    'max_markup_percent' => 1000,
    'max_meli_fee_percent' => 95,

    // Compatibility is deliberate: database-backed sources always win when present.
    'allow_phase5_image_fallback' => true,
    'allow_phase5_price_fallback' => true,
];
