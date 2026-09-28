<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryChannelOrderAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['diagnostic_metadata' => 'array', 'quantity' => 'integer'];
    }
}
