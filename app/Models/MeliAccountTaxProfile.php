<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeliAccountTaxProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'meli_account_id',
        'enabled',
        'vat_included_rate',
        'vat_withholding_rate',
        'income_tax_withholding_rate',
        'effective_from',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'vat_included_rate' => 'decimal:4',
            'vat_withholding_rate' => 'decimal:4',
            'income_tax_withholding_rate' => 'decimal:4',
            'effective_from' => 'date',
        ];
    }

    public function meliAccount(): BelongsTo
    {
        return $this->belongsTo(MeliAccount::class);
    }
}
