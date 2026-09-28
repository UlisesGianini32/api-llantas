<?php

namespace App\Services;

use App\Jobs\ReconcileInventoryMeliOrderReservationsJob;
use App\Models\MeliOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class InventoryMeliOrderReservationDispatcher
{
    public function dispatchAfterCommit(MeliOrder $order): void
    {
        if (! (bool) config('inventory.meli_order_reservations.automatic', false)) {
            return;
        }

        $localId = (int) $order->getKey();
        $remoteId = $order->order_id;
        $accountId = $order->meli_account_id;
        $queue = (string) config('inventory.meli_order_reservations.queue', 'meli');

        try {
            DB::afterCommit(function () use ($localId, $remoteId, $accountId, $queue): void {
                try {
                    // The sync driver executes the job here. Keep the dispatch boundary
                    // best effort so legacy order persistence remains successful.
                    ReconcileInventoryMeliOrderReservationsJob::dispatch($localId)->onQueue($queue);
                } catch (Throwable $exception) {
                    $this->logEnqueueFailure($localId, $remoteId, $accountId, $exception);
                }
            });
        } catch (Throwable $exception) {
            $this->logEnqueueFailure($localId, $remoteId, $accountId, $exception);
        }
    }

    private function logEnqueueFailure(int $localId, mixed $remoteId, mixed $accountId, Throwable $exception): void
    {
        $message = $this->sanitizeMessage($exception->getMessage());

        Log::error('Inventory Meli order reservation enqueue failed', [
            'meli_order_id' => $localId,
            'remote_order_id' => $remoteId,
            'meli_account_id' => $accountId,
            'exception' => $exception::class,
            'message' => $message,
        ]);
    }

    private function sanitizeMessage(string $message): string
    {
        $patterns = [
            '/(authorization)\s*[:=]\s*bearer\s+[^\s,;&]+/iu' => '$1: Bearer [redacted]',
            '/(access_token|refresh_token|token)\s*[:=]\s*(?:bearer\s+)?[^\s,;&]+/iu' => '$1=[redacted]',
            '/([?&](?:access_token|refresh_token|token|authorization)=)([^&#\s]+)/iu' => '$1[redacted]',
        ];

        $sanitized = preg_replace(array_keys($patterns), array_values($patterns), $message);

        return mb_substr($sanitized ?: 'enqueue failed', 0, 500);
    }
}
