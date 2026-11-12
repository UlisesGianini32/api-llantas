<?php

return [
    // Enable only after the additive migrations and explicit grants are reviewed.
    'enabled' => (bool) env('AUTOPARTES_ENABLED', false),
    // Transition lock during R2->R3 while HTTP controllers/Inertia views are migrated from legacy tables to 'autopartes'.
    'controllers_enabled' => filter_var(env('AUTOPARTES_CONTROLLERS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'database_connection' => env('AUTOPARTES_DB_CONNECTION', 'autopartes'),
    'max_bytes' => 20 * 1024 * 1024,
    'max_rows' => 10000,
    'batch_size' => 100,
    'max_receipt_item_quantity' => (int) env('AUTOPARTES_MAX_RECEIPT_ITEM_QUANTITY', 5000),
    'max_adjustment_quantity' => (int) env('AUTOPARTES_MAX_ADJUSTMENT_QUANTITY', 5000),
    'queue_connection' => env('AUTOPARTES_QUEUE_CONNECTION', 'database'),
    'queue' => 'autopartes',
    'transaction_attempts' => max(1, (int) env('AUTOPARTES_DB_TX_ATTEMPTS', 3)),
    'block_external_channels' => filter_var(env('AUTOPARTES_BLOCK_EXTERNAL_CHANNELS', false), FILTER_VALIDATE_BOOLEAN),

    // Phase 4 (PASO 7 & 7.1): POS, pricing, discounts and tax policy.
    // All commercial decisions default to locked/0% until explicitly configured by an authorized administrator.
    'pos' => [
        'sales_enabled_by_default' => filter_var(env('AUTOPARTES_POS_SALES_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'fiscal_policy_validated_by_default' => filter_var(env('AUTOPARTES_POS_FISCAL_VALIDATED', false), FILTER_VALIDATE_BOOLEAN),
        'manual_discounts_enabled_by_default' => filter_var(env('AUTOPARTES_POS_MANUAL_DISCOUNTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'tax_enabled' => filter_var(env('AUTOPARTES_POS_TAX_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'tax_rate' => (float) env('AUTOPARTES_POS_TAX_RATE', 0.00),
        'cashier_max_manual_discount_percent' => (float) env('AUTOPARTES_POS_CASHIER_MAX_DISCOUNT_PCT', 0.00),
        'cashier_max_manual_discount_amount' => (float) env('AUTOPARTES_POS_CASHIER_MAX_DISCOUNT_AMT', 0.00),
        'discount_approval_ttl_minutes' => (int) env('AUTOPARTES_POS_APPROVAL_TTL_MINUTES', 30),
        'max_sale_item_quantity' => (int) env('AUTOPARTES_POS_MAX_ITEM_QUANTITY', 500),
        'display_cashier_name_on_ticket' => filter_var(env('AUTOPARTES_POS_TICKET_SHOW_CASHIER', true), FILTER_VALIDATE_BOOLEAN),
        'default_thermal_width' => env('AUTOPARTES_POS_THERMAL_WIDTH', '80mm'),
        'supported_thermal_widths' => ['58mm', '80mm'],
        'letter_dimensions_mm' => ['width' => 216, 'height' => 279],
    ],
];
