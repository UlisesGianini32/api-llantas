<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryChannelLink extends Model
{
    public const MERCADO_LIBRE = 'mercado_libre';

    public const AMAZON = 'amazon';

    public const SHOPIFY = 'shopify';

    public const CHANNELS = [self::MERCADO_LIBRE, self::AMAZON, self::SHOPIFY];

    protected $fillable = [
        'inventory_product_id',
        'channel',
        'account_key',
        'external_product_id',
        'external_variant_id',
        'external_listing_id',
        'external_url',
        'remote_status',
        'remote_price',
        'remote_currency',
        'last_synced_at',
        'metadata',
        'is_active',
        'identity_key',
    ];

    protected function casts(): array
    {
        return [
            'remote_price' => 'decimal:2',
            'last_synced_at' => 'datetime',
            'metadata' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'inventory_product_id');
    }

    public static function channelLabel(string $channel): string
    {
        return match ($channel) {
            self::MERCADO_LIBRE => 'Mercado Libre',
            self::AMAZON => 'Amazon',
            self::SHOPIFY => 'Shopify',
            default => $channel,
        };
    }

    public function externalIdentifier(): ?string
    {
        return $this->external_listing_id
            ?? $this->external_product_id
            ?? $this->external_variant_id;
    }

    public function isMercadoLibre(): bool
    {
        return $this->channel === self::MERCADO_LIBRE;
    }

    public function isAmazon(): bool
    {
        return $this->channel === self::AMAZON;
    }

    public function isShopify(): bool
    {
        return $this->channel === self::SHOPIFY;
    }
}
