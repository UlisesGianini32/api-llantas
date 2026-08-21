<?php

namespace App\Jobs;

use App\Models\MeliAccount;
use App\Services\MeliQuestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMeliQuestionNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** @var array<int, int> */
    public array $backoff = [15, 60, 180];

    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload)
    {
    }

    public function handle(MeliQuestionService $service): void
    {
        $resource = trim((string) ($this->payload['resource'] ?? ''));
        $sellerId = trim((string) ($this->payload['user_id'] ?? ''));

        if (! preg_match('#/questions/(\d+)#', $resource, $matches)) {
            Log::warning('MELI QUESTIONS WEBHOOK: resource inválido', [
                'resource' => $resource,
            ]);

            return;
        }

        $account = MeliAccount::query()
            ->where('meli_user_id', $sellerId)
            ->first();

        if (! $account) {
            Log::warning('MELI QUESTIONS WEBHOOK: cuenta no encontrada', [
                'seller_id' => $sellerId,
                'resource' => $resource,
            ]);

            return;
        }

        $service->syncQuestion($account, $matches[1]);

        Log::info('MELI QUESTIONS WEBHOOK: pregunta sincronizada', [
            'question_id' => $matches[1],
            'meli_account_id' => $account->id,
        ]);
    }
}
