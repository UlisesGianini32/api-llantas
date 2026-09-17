<?php

namespace App\Services\Telegram;

use App\Models\TelegramConversationState;
use Illuminate\Support\Facades\DB;

class TelegramStateService
{
    public function put(string $chatId, string $mode, ?string $entityType = null, ?int $entityId = null, array $payload = []): TelegramConversationState
    {
        return TelegramConversationState::query()->updateOrCreate(
            ['chat_id' => $chatId],
            [
                'mode' => $mode,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'payload' => $payload,
                'expires_at' => now()->addMinutes(30),
            ]
        );
    }

    public function get(string $chatId): ?TelegramConversationState
    {
        $state = TelegramConversationState::query()->where('chat_id', $chatId)->first();
        if ($state && $state->expires_at?->isPast()) {
            $state->delete();

            return null;
        }

        return $state;
    }

    public function clear(string $chatId): void
    {
        TelegramConversationState::query()->where('chat_id', $chatId)->delete();
    }

    public function claimConfirmation(string $chatId, string $mode, int $entityId): ?array
    {
        return DB::transaction(function () use ($chatId, $mode, $entityId): ?array {
            $state = TelegramConversationState::query()->where('chat_id', $chatId)->lockForUpdate()->first();
            if (! $state || $state->expires_at?->isPast() || $state->mode !== $mode || (int) $state->entity_id !== $entityId) {
                return null;
            }

            $payload = $state->payload ?? [];
            $state->forceFill(['mode' => 'sending', 'expires_at' => now()->addMinutes(30)])->save();

            return $payload;
        });
    }
}
