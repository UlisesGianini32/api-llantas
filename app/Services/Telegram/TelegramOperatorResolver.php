<?php

namespace App\Services\Telegram;

use App\Models\TelegramOperatorIdentity;
use App\Models\User;

class TelegramOperatorResolver
{
    public function resolve(string $telegramUserId): ?User
    {
        if (! preg_match('/^\d{1,20}$/', $telegramUserId)) {
            return null;
        }

        return TelegramOperatorIdentity::query()
            ->where('telegram_user_id', $telegramUserId)
            ->where('active', true)
            ->with('user')
            ->first()?->user;
    }
}
