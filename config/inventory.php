<?php

return [
    'meli_order_reservations' => [
        'automatic' => (bool) env('INVENTORY_MELI_ORDER_RESERVATIONS_AUTOMATIC', false),
        'automatic_after' => env('INVENTORY_MELI_ORDER_RESERVATIONS_AUTOMATIC_AFTER'),
        'queue' => 'meli',
        // Lease TTL for active processing ownership (renewed per line). Must remain well below
        // DB_QUEUE_RETRY_AFTER (7300s in prod) and above job timeout (120s).
        'lease_seconds' => (int) env('INVENTORY_MELI_ORDER_RESERVATIONS_LEASE_SECONDS', 300),
        'job_timeout_seconds' => (int) env('INVENTORY_MELI_ORDER_RESERVATIONS_TIMEOUT_SECONDS', 120),
    ],
];
