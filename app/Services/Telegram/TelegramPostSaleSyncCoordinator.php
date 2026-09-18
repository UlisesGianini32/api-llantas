<?php

namespace App\Services\Telegram;

use App\Jobs\SyncMeliPostSaleForTelegramJob;
use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Throwable;

class TelegramPostSaleSyncCoordinator
{
    private const LOCK_NAME = 'telegram-post-sale-sync:global';

    private const LOCK_SECONDS = 43200;

    public function start(string $chatId): bool
    {
        $lock = Cache::lock(self::LOCK_NAME, self::LOCK_SECONDS);
        if (! $lock->get()) {
            return false;
        }

        try {
            SyncMeliPostSaleForTelegramJob::dispatch($chatId, lockOwner: $lock->owner());
        } catch (Throwable $error) {
            $lock->release();
            throw $error;
        }

        return true;
    }

    public function owns(string $owner): bool
    {
        return $this->restore($owner)->isOwnedByCurrentProcess();
    }

    public function release(string $owner): void
    {
        $lock = $this->restore($owner);
        if ($lock->isOwnedByCurrentProcess()) {
            $lock->release();
        }
    }

    private function restore(string $owner): Lock
    {
        return Cache::restoreLock(self::LOCK_NAME, $owner);
    }
}
