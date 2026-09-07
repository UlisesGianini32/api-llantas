<?php

namespace Tests\Unit;

use App\Models\MeliBeautyScheduledDiscount;
use App\Models\MeliScheduledPriceState;
use App\Services\MercadoLibre\PriceManager\MeliBeautyScheduledPriceService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class MeliBeautyScheduledPriceServiceTest extends TestCase
{
    private MeliBeautyScheduledPriceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MeliBeautyScheduledPriceService::class);
    }

    public function test_cross_midnight_schedule_uses_local_timezone_and_excludes_boundaries(): void
    {
        $rule = new MeliBeautyScheduledDiscount([
            'active' => true,
            'starts_at' => '20:00',
            'ends_at' => '06:00',
            'timezone' => 'America/Hermosillo',
        ]);

        $this->assertFalse($this->service->isRuleActiveAt($rule, CarbonImmutable::parse('2026-09-08 19:59:00', 'America/Hermosillo')));
        $this->assertTrue($this->service->isRuleActiveAt($rule, CarbonImmutable::parse('2026-09-08 20:00:00', 'America/Hermosillo')));
        $this->assertTrue($this->service->isRuleActiveAt($rule, CarbonImmutable::parse('2026-09-09 00:30:00', 'America/Hermosillo')));
        $this->assertTrue($this->service->isRuleActiveAt($rule, CarbonImmutable::parse('2026-09-09 05:59:00', 'America/Hermosillo')));
        $this->assertFalse($this->service->isRuleActiveAt($rule, CarbonImmutable::parse('2026-09-09 06:00:00', 'America/Hermosillo')));
    }

    public function test_non_cross_midnight_schedule_uses_start_inclusive_end_exclusive(): void
    {
        $rule = new MeliBeautyScheduledDiscount([
            'active' => true,
            'starts_at' => '08:00',
            'ends_at' => '18:00',
            'timezone' => 'America/Hermosillo',
        ]);

        $this->assertFalse($this->service->isRuleActiveAt($rule, CarbonImmutable::parse('2026-09-08 07:59:00', 'America/Hermosillo')));
        $this->assertTrue($this->service->isRuleActiveAt($rule, CarbonImmutable::parse('2026-09-08 08:00:00', 'America/Hermosillo')));
        $this->assertTrue($this->service->isRuleActiveAt($rule, CarbonImmutable::parse('2026-09-08 17:59:00', 'America/Hermosillo')));
        $this->assertFalse($this->service->isRuleActiveAt($rule, CarbonImmutable::parse('2026-09-08 18:00:00', 'America/Hermosillo')));
    }

    public function test_calculation_is_rounded_and_never_uses_promotional_price_as_base(): void
    {
        $rule = new MeliBeautyScheduledDiscount(['discount_percentage' => 10]);
        $state = new MeliScheduledPriceState([
            'base_price' => 2000,
            'promotional_price' => 1800,
            'status' => MeliScheduledPriceState::STATUS_ACTIVE,
        ]);

        $this->assertSame(1800.0, $this->service->calculatePromotionalPrice(2000, 10));
        $this->assertSame(1800.0, $this->service->determineTransition($rule, $state, true, 1800)['promotional_price']);
        $this->assertSame(1890.0, $this->service->determineTransition($rule, $state, true, 2100)['promotional_price']);
        $this->assertSame('rebase', $this->service->determineTransition($rule, $state, true, 2100)['action']);
    }

    public function test_external_change_is_restored_to_the_new_base_without_put(): void
    {
        $rule = new MeliBeautyScheduledDiscount(['discount_percentage' => 10]);
        $state = new MeliScheduledPriceState([
            'base_price' => 2000,
            'promotional_price' => 1800,
            'status' => MeliScheduledPriceState::STATUS_ACTIVE,
        ]);

        $transition = $this->service->determineTransition($rule, $state, false, 2100);

        $this->assertSame('rebase', $transition['action']);
        $this->assertSame(2100.0, $transition['base_price']);
        $this->assertSame('restored', $transition['status']);
        $this->assertNull($transition['target_price']);
    }

    public function test_discount_change_recalculates_from_stored_base_not_old_promotion(): void
    {
        $rule = new MeliBeautyScheduledDiscount(['discount_percentage' => 15]);
        $state = new MeliScheduledPriceState([
            'base_price' => 2000,
            'promotional_price' => 1800,
            'status' => MeliScheduledPriceState::STATUS_ACTIVE,
        ]);

        $transition = $this->service->determineTransition($rule, $state, true, 1800);

        $this->assertSame('rebase', $transition['action']);
        $this->assertSame(2000.0, $transition['base_price']);
        $this->assertSame(1700.0, $transition['promotional_price']);
    }

    public function test_decimal_discount_uses_price_manager_rounding(): void
    {
        $this->assertSame(1850.0, $this->service->calculatePromotionalPrice(2000, 7.5));
    }
}
