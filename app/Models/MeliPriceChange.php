<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeliPriceChange extends Model
{
    use HasFactory;

    public const STATUSES = ['pending', 'processing', 'success', 'failed', 'cancelled'];

    protected $fillable = [
        'batch_id', 'price_manager_item_id', 'meli_item_id', 'old_price', 'new_price',
        'selling_fee', 'shipping_cost', 'tax_withholding', 'other_charges', 'estimated_net',
        'status', 'error_message', 'changed_by', 'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'old_price' => 'decimal:2',
            'new_price' => 'decimal:2',
            'selling_fee' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'tax_withholding' => 'decimal:2',
            'other_charges' => 'decimal:2',
            'estimated_net' => 'decimal:2',
            'changed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MeliPriceChangeBatch::class, 'batch_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MeliPriceManagerItem::class, 'price_manager_item_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
