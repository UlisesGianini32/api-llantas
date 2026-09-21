<?php

namespace App\Jobs;

use App\Models\MeliAccount;
use App\Services\MeliOrderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMeliOrderNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(public array $payload) {}

    public function handle(MeliOrderSyncService $orders): void
    {
        $resource = (string) ($this->payload['resource'] ?? '');
        preg_match('#/orders/(\d+)#', $resource, $m);
        $orderId = $m[1] ?? null;

        if ($orderId === null) {
            return;
        }

        $sellerId = trim((string) ($this->payload['user_id'] ?? ''));
        $account = MeliAccount::query()
            ->with('user')
            ->where('meli_user_id', $sellerId)
            ->whereNotNull('access_token')
            ->first();

        if (! $account || ! $account->user) {
            Log::warning('MELI WEBHOOK: cuenta no encontrada para orders_v2', [
                'order_id' => $orderId,
                'meli_user_id' => $sellerId,
            ]);

            return;
        }

        $apiUser = $account->user->replicate();
        $apiUser->forceFill([
            'id' => $account->user_id,
            'meli_id' => $account->meli_user_id,
            'access_token' => $account->access_token,
            'refresh_token' => $account->refresh_token,
            'expires_at' => $account->expires_at,
        ]);
        $apiUser->exists = true;

        $orders->syncOrderById($apiUser, $orderId);
    }
}
