<?php

namespace Tests\Unit;

use App\Services\MercadoLibre\PriceManager\MeliHistoricalTaxRuleDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MeliHistoricalTaxRuleDetectorTest extends TestCase
{
    private MeliHistoricalTaxRuleDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new MeliHistoricalTaxRuleDetector;
    }

    public function test_seven_real_observations_detect_the_account_rule_with_rounding_tolerance(): void
    {
        $result = $this->detector->detect(10, $this->realObservations());

        $this->assertTrue($result['available']);
        $this->assertSame('historical_account_tax_rule', $result['source']);
        $this->assertSame('high', $result['confidence']);
        $this->assertSame(7, $result['sample_count']);
        $this->assertSame(7, $result['evidence']['distinct_items']);
        $this->assertSame(16.0, $result['vat_included_rate']);
        $this->assertSame(8.0, $result['vat_withholding_rate']);
        $this->assertSame(2.5, $result['income_tax_withholding_rate']);
        $this->assertSame(1, $result['evidence']['money_tolerance_cents']);
    }

    public function test_five_orders_and_three_distinct_items_are_the_minimum_valid_sample(): void
    {
        $observations = array_slice($this->realObservations(), 0, 5);
        $observations[3]['item_id'] = $observations[0]['item_id'];
        $observations[4]['item_id'] = $observations[1]['item_id'];

        $result = $this->detector->detect(10, $observations);

        $this->assertTrue($result['available']);
        $this->assertSame(5, $result['sample_count']);
        $this->assertSame(3, $result['evidence']['distinct_items']);
    }

    public function test_less_than_five_orders_or_three_distinct_items_is_insufficient(): void
    {
        $this->assertFalse($this->detector->detect(10, array_slice($this->realObservations(), 0, 4))['available']);

        $sameItem = array_slice($this->realObservations(), 0, 5);
        foreach ($sameItem as &$observation) {
            $observation['item_id'] = 'MLM-SAME';
        }
        unset($observation);

        $result = $this->detector->detect(10, $sameItem);
        $this->assertFalse($result['available']);
        $this->assertSame('insufficient', $result['confidence']);
    }

    public function test_other_accounts_and_ambiguous_or_refunded_orders_are_excluded(): void
    {
        $observations = $this->realObservations();
        $observations[0]['meli_account_id'] = 99;
        $observations[1]['attribution_scope'] = 'multi_item';
        $observations[2]['refunded'] = true;

        $result = $this->detector->detect(10, $observations);

        $this->assertFalse($result['available']);
        $this->assertSame(4, $result['sample_count']);
    }

    #[DataProvider('invalidTaxProvider')]
    public function test_materially_deviated_tax_data_does_not_produce_a_rule(string $field, mixed $value): void
    {
        $observations = $this->realObservations();
        $observations[0][$field] = $value;

        $result = $this->detector->detect(10, $observations);

        $this->assertFalse($result['available']);
        $this->assertSame('insufficient', $result['confidence']);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidTaxProvider(): iterable
    {
        yield 'material IVA deviation' => ['vat_amount', 75.00];
        yield 'material ISR deviation' => ['income_tax_amount', 18.00];
    }

    public function test_observations_missing_iva_or_isr_are_excluded(): void
    {
        $observations = $this->realObservations();
        $observations[0]['vat_amount'] = null;
        $observations[1]['income_tax_amount'] = null;

        $result = $this->detector->detect(10, $observations);

        $this->assertTrue($result['available']);
        $this->assertSame(5, $result['sample_count']);
    }

    /** @return list<array<string, mixed>> */
    private function realObservations(): array
    {
        $amounts = [
            [1001.28, 69.05, 21.58],
            [199.00, 13.72, 4.29],
            [356.00, 24.55, 7.67],
            [298.00, 20.55, 6.42],
            [229.00, 15.79, 4.94],
            [660.00, 45.52, 14.22],
            [735.00, 50.69, 15.84],
        ];

        return array_map(static fn (array $amount, int $index): array => [
            'meli_account_id' => 10,
            'order_id' => (string) (2000 + $index),
            'item_id' => 'MLM-'.(3000 + $index),
            'gross_sale_amount' => $amount[0],
            'vat_amount' => $amount[1],
            'income_tax_amount' => $amount[2],
            'payment_status' => 'approved',
            'refunded' => false,
            'attribution_scope' => 'single_item',
            'observed_at' => sprintf('2026-08-%02dT12:00:00-06:00', 10 + $index),
        ], $amounts, array_keys($amounts));
    }
}
