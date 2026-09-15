<?php

namespace App\Services;

use App\Models\MeliClaim;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramAlertService
{
    /** At most one delivery attempt per chat; the timestamp is a reservation, not a receipt. */
    public function notifyMeliNewClaim(MeliClaim $claim): void
    {
        try {
            $token = trim((string) env('TELEGRAM_BOT_TOKEN', ''));
            $chatIds = array_unique($this->resolveChatIds());
            if ($token === '' || $chatIds === []) {
                Log::notice('TelegramAlertService: alerta de reclamo sin configuración', ['claim_id' => $claim->claim_id]);
                return;
            }

            $claim->loadMissing(['meliAccount', 'reason', 'order.items']);
            $lines = [
                '🚨 NUEVO RECLAMO MERCADO LIBRE',
                'Cuenta: '.($claim->meliAccount?->nickname ?: 'Cuenta '.$claim->meli_account_id),
                'Claim ID: '.$claim->claim_id,
                'Pedido: '.($claim->order_id ?: '—'),
            ];
            if ($claim->order && (int) $claim->order->meli_account_id === (int) $claim->meli_account_id) {
                foreach ($claim->order->items->take(10) as $item) {
                    $lines[] = 'Producto: '.mb_substr((string) $item->title, 0, 160)
                        .' | SKU: '.mb_substr((string) $item->sku, 0, 80).' | Cantidad: '.$item->quantity;
                }
            }
            $lines[] = 'Cantidad reclamada: '.($claim->claimed_quantity ?? '—');
            $lines[] = 'Motivo: '.mb_substr((string) ($claim->reason?->detail ?: $claim->reason?->name ?: $claim->reason_id ?: $claim->problem ?: '—'), 0, 300);
            $lines[] = 'Etapa: '.($claim->stage ?: '—');
            $lines[] = 'Responsable de acción: '.($claim->action_responsible ?: '—');
            $lines[] = 'Afecta reputación: '.match ($claim->affects_reputation) { true => 'Sí', false => 'No', default => 'Sin determinar' };
            $lines[] = 'Fecha límite: '.($claim->due_date?->toIso8601String() ?? '—');
            // Bound UTF-16 code units as well as characters, keeping the direct URL intact.
            $body = implode("\n", $lines);
            while (strlen(mb_convert_encoding($body, 'UTF-16LE', 'UTF-8')) > 6400) {
                $body = mb_substr($body, 0, mb_strlen($body) - 100);
            }
            $message = $body."\n".route('meli.claims.show', $claim);

            // Atomic compare-and-set protects even callers holding stale model instances.
            $reserved = MeliClaim::query()->whereKey($claim->id)->whereNull('telegram_notified_at')
                ->whereIn('status', ['opened', 'open'])->update(['telegram_notified_at' => now()]);
            if ($reserved !== 1) return;

            foreach ($chatIds as $chatId) {
                try {
                    $response = Http::connectTimeout(5)->timeout(15)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                        'chat_id' => $chatId, 'text' => $message,
                    ]);
                    if (! $response->successful() || $response->json('ok') !== true) {
                        Log::warning('TelegramAlertService: envío de reclamo rechazado', ['claim_id' => $claim->claim_id, 'status' => $response->status()]);
                    }
                } catch (\Throwable $e) {
                    // Exception messages may contain the bot URL/token; log only the type.
                    Log::warning('TelegramAlertService: envío de reclamo no confirmado', ['claim_id' => $claim->claim_id, 'exception' => $e::class]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('TelegramAlertService: alerta de reclamo fallida', ['claim_id' => $claim->claim_id, 'exception' => $e::class]);
        }
    }

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
