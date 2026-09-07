<?php

namespace Tests\Unit;

use App\Support\MeliBeautyScheduledPriceSchedule;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class MeliBeautyScheduledPriceScheduleTest extends TestCase
{
    public function test_scheduler_requires_both_flags(): void
    {
        foreach ([
            [false, false, false],
            [true, false, false],
            [false, true, false],
            [true, true, true],
        ] as [$enabled, $schedulerEnabled, $expected]) {
            config()->set('meli_price_manager.beauty_scheduled_prices.enabled', $enabled);
            config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', $schedulerEnabled);

            $this->assertSame($expected, MeliBeautyScheduledPriceSchedule::enabled());
        }
    }

    public function test_disabled_scheduler_does_not_register_an_event(): void
    {
        config()->set('meli_price_manager.beauty_scheduled_prices.enabled', true);
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', false);
        $before = count(app(Schedule::class)->events());

        $this->assertNull(MeliBeautyScheduledPriceSchedule::register());
        $this->assertCount($before, app(Schedule::class)->events());
    }

    public function test_enabled_scheduler_registers_explicit_global_apply_with_safety_controls(): void
    {
        config()->set('meli_price_manager.beauty_scheduled_prices.enabled', true);
        config()->set('meli_price_manager.beauty_scheduled_prices.scheduler_enabled', true);
        $before = count(app(Schedule::class)->events());

        $event = MeliBeautyScheduledPriceSchedule::register();

        $this->assertNotNull($event);
        $this->assertCount($before + 1, app(Schedule::class)->events());
        $this->assertStringContainsString('meli:beauty-scheduled-prices --apply --all', $event->command);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->runInBackground);
    }
}
