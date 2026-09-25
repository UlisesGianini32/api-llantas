<?php

namespace App\Jobs;

use App\Services\InventoryMeliStockSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncInventoryMeliStockLinkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 420;

    public function __construct(public readonly int $linkId, public readonly ?int $userId = null)
    {
        $this->onQueue('meli');
    }

    public function handle(InventoryMeliStockSyncService $sync): void
    {
        $sync->syncLink($this->linkId, $this->userId, 'job');
    }
}
