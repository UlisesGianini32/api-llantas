<?php

namespace App\Jobs;

use App\Models\MeliBeautyScheduledDiscount;
use App\Services\MercadoLibre\PriceManager\MeliBeautyScheduledPriceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMeliBeautyScheduledPriceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    public function __construct(public readonly int $discountId, public readonly ?string $meliItemId = null)
    {
        $this->onQueue('meli');
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function uniqueId(): string
    {
        return 'meli-beauty-scheduled:'.$this->discountId.':'.($this->meliItemId ?? 'all');
    }

    public function handle(MeliBeautyScheduledPriceService $service): void
    {
        if (! config('meli_price_manager.beauty_scheduled_prices.enabled', false)
            || ! config('meli_price_manager.beauty_scheduled_prices.promotional_prices_enabled', false)) {
            Log::info('[MeliBeautyScheduledPrice] feature disabled', ['discount_id' => $this->discountId]);

            return;
        }

        $rule = MeliBeautyScheduledDiscount::query()->with('meliAccount')->findOrFail($this->discountId);
        $summary = $service->processRule($rule, $this->meliItemId);
        Log::info('[MeliBeautyScheduledPrice] job completed', ['discount_id' => $this->discountId, 'summary' => $summary]);
    }
}
