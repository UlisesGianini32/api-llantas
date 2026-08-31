<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeliClaimActionLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'request_payload_sanitized' => 'array',
            'success' => 'boolean',
        ];
    }
}
