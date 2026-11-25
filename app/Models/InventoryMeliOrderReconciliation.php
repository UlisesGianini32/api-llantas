<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryMeliOrderReconciliation extends Model
{
    public const STATE_IDLE = 'IDLE';

    public const STATE_PENDING = 'PENDING';

    public const STATE_PROCESSING = 'PROCESSING';

    public const STATE_FAILED = 'FAILED';

    protected $table = 'inventory_meli_order_reconciliations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'meli_order_id' => 'integer',
            'meli_account_id' => 'integer',
            'requested_revision' => 'integer',
            'processed_revision' => 'integer',
            'last_enqueued_revision' => 'integer',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
        ];
    }
}
