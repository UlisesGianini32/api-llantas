<?php

return [
    'meli_order_reservations' => [
        'automatic' => (bool) env('INVENTORY_MELI_ORDER_RESERVATIONS_AUTOMATIC', false),
        'automatic_after' => env('INVENTORY_MELI_ORDER_RESERVATIONS_AUTOMATIC_AFTER'),
        'queue' => 'meli',
        'lease_seconds' => (int) env('INVENTORY_MELI_ORDER_RESERVATIONS_LEASE_SECONDS', 300),
    ],
];
