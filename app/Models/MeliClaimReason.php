<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeliClaimReason extends Model
{
    protected $fillable = ['reason_id', 'name', 'detail', 'flow', 'raw_data', 'last_synced_at'];

    protected function casts(): array
    {
        return ['raw_data' => 'array', 'last_synced_at' => 'datetime'];
    }
}
