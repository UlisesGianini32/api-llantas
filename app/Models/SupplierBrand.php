<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierBrand extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'brand',
        'is_primary',
        'lead_time_override',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'is_primary' => 'boolean',
            'lead_time_override' => 'integer',
        ];
    }

    public function setBrandAttribute(string $value): void
    {
        $this->attributes['brand'] = mb_strtoupper(trim($value));
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
