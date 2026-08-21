<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemHeartbeat extends Model
{
    protected $fillable = ['name', 'ran_at', 'meta'];

    protected function casts(): array
    {
        return ['ran_at' => 'datetime', 'meta' => 'array'];
    }
}
