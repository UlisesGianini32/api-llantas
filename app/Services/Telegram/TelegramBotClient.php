<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramBotClient
{
    public function sendMessage(string $chatId, string $text, array $keyboard = []): bool
    {
        $text = $this->boundedText($text);

        return $this->request('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => $keyboard === [] ? null : ['inline_keyboard' => $keyboard],
            'disable_web_page_preview' => true,
        ], static fn (mixed $value): bool => $value !== null));
    }

    public function editOrSend(
        string $chatId,
        ?string $messageId,
        string $text,
        array $keyboard = []
    ): bool {
        $text = $this->boundedText($text);

        if ($messageId !== null && $messageId !== '') {
            $response = $this->post('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'reply_markup' => ['inline_keyboard' => $keyboard],
                'disable_web_page_preview' => true,
            ]);

            if ($response?->successful() || str_contains(
                strtolower((string) $response?->json('description')),
                'message is not modified'
            )) {
                return true;
            }
        }

        return $this->sendMessage($chatId, $text, $keyboard);
    }

    public function answerCallback(string $callbackId, ?string $text = null): void
    {
        if ($callbackId === '') {
            return;
        }

        $this->request('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackId,
            'text' => $text,
        ], static fn (mixed $value): bool => $value !== null));
    }

    private function request(string $method, array $payload): bool
    {
        return (bool) $this->post($method, $payload)?->successful();
    }

    private function boundedText(string $text): string
    {
        return mb_strlen($text) > 4000
            ? rtrim(mb_substr($text, 0, 3999)).'…'
            : $text;
    }

    private function post(string $method, array $payload): ?Response
    {
        $token = trim((string) env('TELEGRAM_BOT_TOKEN'));

        if ($token === '') {
            Log::warning('Telegram bot: token no configurado', ['method' => $method]);

            return null;
        }

        try {
            return Http::timeout(20)->post(
                "https://api.telegram.org/bot{$token}/{$method}",
                $payload
            );
        } catch (Throwable $error) {
            Log::warning('Telegram bot: solicitud no confirmada', [
                'method' => $method,
                'error_type' => $error::class,
            ]);

            return null;
        }
    }
}
