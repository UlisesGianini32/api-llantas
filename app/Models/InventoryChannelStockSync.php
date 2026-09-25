<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryChannelStockSync extends Model
{
    public const SUCCESS = 'SUCCESS';

    public const FAILED = 'FAILED';

    public const STATUSES = [self::SUCCESS, self::FAILED];

    protected $fillable = [
        'inventory_channel_link_id', 'inventory_product_id', 'channel', 'account_key',
        'external_listing_id', 'external_variant_id', 'target_quantity',
        'previous_known_quantity', 'status', 'http_status', 'error_code',
        'error_message', 'triggered_by', 'created_by', 'started_at', 'finished_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'target_quantity' => 'integer',
            'previous_known_quantity' => 'integer',
            'http_status' => 'integer',
            'metadata' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(InventoryChannelLink::class, 'inventory_channel_link_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'inventory_product_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
