<?php

return [
    'meli_order_reservations' => [
        'automatic' => (bool) env('INVENTORY_MELI_ORDER_RESERVATIONS_AUTOMATIC', false),
        'automatic_after' => env('INVENTORY_MELI_ORDER_RESERVATIONS_AUTOMATIC_AFTER'),
        'queue' => 'meli',
    ],
];
