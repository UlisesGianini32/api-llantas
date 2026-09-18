<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramProcessedUpdate extends Model
{
    protected $fillable = ['update_key', 'processed_at'];

    protected $casts = ['processed_at' => 'datetime'];
}
