<?php

namespace Tests\Unit;

use App\Models\MeliBeautyScheduledDiscount;
use App\Services\MercadoLibre\PriceManager\MeliBeautyPromotionWindow;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class MeliBeautyPromotionWindowTest extends TestCase
{
    public function test_overnight_period_uses_inclusive_start_and_exclusive_final_end(): void
    {
        $promotion = $this->promotion('2028-09-08', '2028-09-12', '20:00', '06:00');
        $window = app(MeliBeautyPromotionWindow::class);

        $this->assertFalse($window->contains($promotion, $this->at('2028-09-08 19:59:59')));
        $this->assertTrue($window->contains($promotion, $this->at('2028-09-08 20:00:00')));
        $this->assertTrue($window->contains($promotion, $this->at('2028-09-12 05:59:59')));
        $this->assertFalse($window->contains($promotion, $this->at('2028-09-12 06:00:00')));
        $this->assertFalse($window->contains($promotion, $this->at('2028-09-12 20:00:00')));
    }

    public function test_daytime_period_includes_the_final_date_until_its_end_time(): void
    {
        $promotion = $this->promotion('2028-09-08', '2028-09-12', '09:00', '17:00');
        $window = app(MeliBeautyPromotionWindow::class);

        $this->assertTrue($window->contains($promotion, $this->at('2028-09-12 16:59:59')));
        $this->assertFalse($window->contains($promotion, $this->at('2028-09-12 17:00:00')));
    }

    public function test_overlap_is_based_on_actual_daily_occurrences(): void
    {
        $window = app(MeliBeautyPromotionWindow::class);
        $first = $this->promotion('2028-09-08', '2028-09-12', '09:00', '12:00');
        $touching = $this->promotion('2028-09-10', '2028-09-11', '12:00', '14:00');
        $overlapping = $this->promotion('2028-09-10', '2028-09-11', '11:59', '14:00');

        $this->assertFalse($window->overlaps($first, $touching));
        $this->assertTrue($window->overlaps($first, $overlapping));
    }

    public function test_legacy_and_invalid_same_date_overnight_periods_are_not_executable(): void
    {
        $window = app(MeliBeautyPromotionWindow::class);
        $legacy = new MeliBeautyScheduledDiscount([
            'active' => true, 'starts_at' => '20:00', 'ends_at' => '06:00',
        ]);
        $invalid = $this->promotion('2028-09-08', '2028-09-08', '20:00', '06:00');

        $this->assertFalse($window->shouldApply($legacy, $this->at('2028-09-08 21:00')));
        $this->assertSame('requires_configuration', $window->status($legacy, $this->at('2028-09-08 21:00')));
        $this->assertNull($window->bounds($invalid));
    }

    private function promotion(string $startsOn, string $endsOn, string $startsAt, string $endsAt): MeliBeautyScheduledDiscount
    {
        return new MeliBeautyScheduledDiscount([
            'active' => true,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'timezone' => MeliBeautyPromotionWindow::TIMEZONE,
        ]);
    }

    private function at(string $dateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($dateTime, MeliBeautyPromotionWindow::TIMEZONE);
    }
}
