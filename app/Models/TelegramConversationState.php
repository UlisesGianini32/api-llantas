<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramConversationState extends Model
{
    protected $fillable = [
        'chat_id',
        'mode',
        'entity_type',
        'entity_id',
        'payload',
        'expires_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'expires_at' => 'datetime',
    ];
}
