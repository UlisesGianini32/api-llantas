<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramAlertService
{
    public function notifyQueueFailure(
        string $connection,
        string $queue,
        string $jobName,
        ?string $exceptionMessage = null,
        ?string $exceptionClass = null
    ): void {
        if (!filter_var((string) env('QUEUE_FAIL_TELEGRAM_ENABLED', true), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $token = trim((string) env('TELEGRAM_BOT_TOKEN', ''));
        if ($token === '') {
            return;
        }

        $chatIds = $this->resolveChatIds();
        if ($chatIds === []) {
            return;
        }

        $appName = (string) config('app.name', 'Laravel');
        $appEnv = (string) config('app.env', 'unknown');
        $shortError = trim((string) $exceptionMessage);
        if ($shortError !== '' && mb_strlen($shortError) > 500) {
            $shortError = mb_substr($shortError, 0, 500) . '...';
        }

        $message = "ALERTA COLA FALLIDA\n"
            . "App: {$appName} ({$appEnv})\n"
            . "Conexion: {$connection}\n"
            . "Queue: {$queue}\n"
            . "Job: {$jobName}";

        $class = trim((string) $exceptionClass);
        if ($class !== '') {
            $message .= "\nTipo: {$class}";
        }

        if ($shortError !== '') {
            $message .= "\nError: {$shortError}";
        }

        foreach ($chatIds as $chatId) {
            try {
                Http::timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $message,
                ]);
            } catch (\Throwable $e) {
                Log::warning('TelegramAlertService: no se pudo enviar alerta de cola', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Cliente pidió asesor humano (menú posventa MeLi, opción 4).
     */
    public function notifyMeliAdvisorRequest(string $orderId, string $buyerName, string $productLines): void
    {
        if (! filter_var((string) env('MELI_ADVISOR_TELEGRAM_ENABLED', true), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $token = trim((string) env('TELEGRAM_BOT_TOKEN', ''));
        if ($token === '') {
            return;
        }

        $chatIds = $this->resolveChatIds();
        if ($chatIds === []) {
            return;
        }

        $appName = (string) config('app.name', 'Laravel');
        $orderId = trim($orderId);
        $buyerName = trim($buyerName) !== '' ? trim($buyerName) : '—';
        $productLines = trim($productLines) !== '' ? trim($productLines) : '—';
        if (mb_strlen($productLines) > 1200) {
            $productLines = mb_substr($productLines, 0, 1200) . '...';
        }

        $message = "MELI — Asesor solicitado (opcion 4)\n"
            . "App: {$appName}\n"
            . "Venta: {$orderId}\n"
            . "Cliente: {$buyerName}\n"
            . "Producto / unidades:\n{$productLines}";

        if (mb_strlen($message) > 4000) {
            $message = mb_substr($message, 0, 3990) . '...';
        }

        foreach ($chatIds as $chatId) {
            try {
                Http::timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $message,
                ]);
            } catch (\Throwable $e) {
                Log::warning('TelegramAlertService: no se pudo enviar alerta asesor MeLi', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function resolveChatIds(): array
    {
        $raw = trim((string) env('TELEGRAM_ALERT_CHAT_IDS', ''));
        if ($raw === '') {
            $raw = trim((string) env('TELEGRAM_ALLOWED_CHAT_IDS', ''));
        }

        $parts = array_map(
            static fn (string $v) => trim($v),
            explode(',', $raw)
        );

        return array_values(array_filter($parts, static fn (string $v) => $v !== ''));
    }
}
