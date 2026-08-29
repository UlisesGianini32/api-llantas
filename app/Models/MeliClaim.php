<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeliClaim extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fulfilled' => 'boolean', 'affects_reputation' => 'boolean',
            'reputation_has_incentive' => 'boolean', 'due_date' => 'datetime',
            'reputation_due_date' => 'datetime', 'date_created' => 'datetime',
            'last_updated' => 'datetime', 'last_synced_at' => 'datetime',
            'raw_claim' => 'array', 'raw_detail' => 'array', 'status_history' => 'array',
            'actions_history' => 'array', 'expected_resolutions' => 'array', 'available_actions' => 'array',
        ];
    }

    public function meliAccount(): BelongsTo { return $this->belongsTo(MeliAccount::class); }

    public function reason(): BelongsTo { return $this->belongsTo(MeliClaimReason::class, 'reason_id', 'reason_id'); }

    public function order(): BelongsTo { return $this->belongsTo(MeliOrder::class, 'meli_order_id'); }
}
