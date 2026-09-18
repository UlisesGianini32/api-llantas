<?php

namespace App\Services\Telegram;

use App\Models\TelegramOperatorIdentity;
use App\Models\User;

class TelegramOperatorResolver
{
    public function resolve(string $chatId): ?User
    {
        if (! preg_match('/^-?\d{1,20}$/', $chatId)) {
            return null;
        }

        return TelegramOperatorIdentity::query()
            ->where('chat_id', $chatId)
            ->where('active', true)
            ->with('user')
            ->first()?->user;
    }
}
