<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeliQuestion extends Model
{
    protected $fillable = [
        'user_id',
        'meli_account_id',
        'question_id',
        'item_id',
        'seller_id',
        'buyer_id',
        'status',
        'text',
        'answer_text',
        'answer_status',
        'question_created_at',
        'answered_at',
        'deleted_from_listing',
        'hold',
        'suspected_spam',
        'item_title',
        'item_thumbnail',
        'item_permalink',
        'item_price',
        'currency_id',
        'sku',
        'available_quantity',
        'raw',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'question_created_at' => 'datetime',
            'answered_at' => 'datetime',
            'deleted_from_listing' => 'boolean',
            'hold' => 'boolean',
            'suspected_spam' => 'boolean',
            'item_price' => 'decimal:2',
            'available_quantity' => 'integer',
            'raw' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function meliAccount(): BelongsTo
    {
        return $this->belongsTo(MeliAccount::class);
    }

    public function getIsUnansweredAttribute(): bool
    {
        return strtoupper((string) $this->status) === 'UNANSWERED';
    }
}
