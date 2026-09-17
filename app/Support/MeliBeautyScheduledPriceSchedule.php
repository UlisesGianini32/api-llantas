<?php

namespace App\Support;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Schedule;

class MeliBeautyScheduledPriceSchedule
{
    public static function enabled(): bool
    {
        return (bool) config('meli_price_manager.beauty_scheduled_prices.enabled', false)
            && (bool) config('meli_price_manager.beauty_scheduled_prices.promotional_prices_enabled', false)
            && (bool) config('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', false);
    }

    public static function register(): ?Event
    {
        if (! self::enabled()) {
            return null;
        }

        return Schedule::command('meli:beauty-scheduled-prices --apply --all')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground();
    }
}
