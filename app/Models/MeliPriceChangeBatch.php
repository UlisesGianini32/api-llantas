<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeliPriceChangeBatch extends Model
{
    use HasFactory;

    public const TYPES = ['individual', 'percentage', 'fixed', 'excel'];
    public const STATUSES = ['draft', 'preview', 'processing', 'completed', 'partial', 'failed', 'cancelled'];

    protected $fillable = [
        'meli_account_id', 'brand_group_id', 'created_by', 'type', 'status', 'notes',
        'total_items', 'successful_items', 'failed_items',
    ];

    protected function casts(): array
    {
        return ['total_items' => 'integer', 'successful_items' => 'integer', 'failed_items' => 'integer'];
    }

    public function meliAccount(): BelongsTo
    {
        return $this->belongsTo(MeliAccount::class);
    }

    public function brandGroup(): BelongsTo
    {
        return $this->belongsTo(MeliBrandGroup::class, 'brand_group_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(MeliPriceChange::class, 'batch_id');
    }
}
