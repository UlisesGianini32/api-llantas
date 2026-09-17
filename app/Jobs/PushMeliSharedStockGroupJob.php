<?php

namespace App\Jobs;

use App\Services\MeliSharedStockPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PushMeliSharedStockGroupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;

    /** @var list<int> */
    public array $backoff = [15, 60, 180];

    public function __construct(public readonly int $groupId)
    {
        $this->onQueue('meli');
    }

    public function handle(MeliSharedStockPushService $service): void
    {
        $service->pushGroup($this->groupId);
    }
}
