<?php

return [
    'meli_order_reservations' => [
        'automatic' => (bool) env('INVENTORY_MELI_ORDER_RESERVATIONS_AUTOMATIC', false),
        'automatic_after' => env('INVENTORY_MELI_ORDER_RESERVATIONS_AUTOMATIC_AFTER'),
        'queue' => 'meli',
    ],

    'warehouse_dispatches' => [
        'enabled' => (bool) env('INVENTORY_WAREHOUSE_DISPATCHES_ENABLED', false),
        'read_only' => (bool) env('INVENTORY_WAREHOUSE_DISPATCHES_READ_ONLY', false),
        'apply_ledger' => (bool) env('INVENTORY_WAREHOUSE_DISPATCHES_APPLY_LEDGER', false),
        'retroactive_tolerance_hours' => max(1, (int) env('INVENTORY_DISPATCH_RETROACTIVE_HOURS', 24)),
        'allow_kits' => false,
        // Strictly disabled throughout Phase 1 (fail-closed; not env-enabled).
        'meli_stock_sync_enabled' => false,
    ],
];
