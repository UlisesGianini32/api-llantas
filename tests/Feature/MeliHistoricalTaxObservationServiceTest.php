<?php

namespace Tests\Feature;

use App\Models\MeliAccount;
use App\Models\MeliOrder;
use App\Models\MeliPriceManagerItem;
use App\Services\MercadoLibre\PriceManager\MeliHistoricalTaxDataService;
use App\Services\MercadoLibre\PriceManager\MeliHistoricalTaxObservationService;
use App\Services\MercadoLibre\PriceManager\MeliHistoricalTaxRuleDetector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class MeliHistoricalTaxObservationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(1);
            $table->string('meli_user_id');
            $table->timestamps();
        });
        Schema::create('meli_price_manager_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_account_id');
            $table->string('meli_item_id');
            $table->string('sku')->nullable();
            $table->timestamps();
        });
        Schema::create('meli_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_account_id');
            $table->string('order_id');
            $table->string('status')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();
        });
        Schema::create('llantas', function (Blueprint $table): void {
            $table->id();
            $table->string('sku')->nullable();
            $table->string('MLM')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('llantas');
        Schema::dropIfExists('meli_orders');
        Schema::dropIfExists('meli_price_manager_items');
        Schema::dropIfExists('meli_accounts');
        DB::purge('sqlite');

        parent::tearDown();
    }

    public function test_real_699_fixture_without_tax_status_produces_a_detector_usable_observation_and_excludes_unsafe_data(): void
    {
        $account = MeliAccount::query()->create(['meli_user_id' => '100']);
        $foreign = MeliAccount::query()->create(['meli_user_id' => '200']);
        foreach (['MLM1', 'MLM2', 'MLM3', 'MLM4', 'MLM5', 'MLM6'] as $itemId) {
            MeliPriceManagerItem::query()->create([
                'meli_account_id' => $account->id,
                'meli_item_id' => $itemId,
                'sku' => 'SKU-'.$itemId,
            ]);
        }
        DB::table('llantas')->insert(['MLM' => 'MLM5', 'sku' => 'EXTERNAL']);

        $this->order($account, '1001', [[
            'item' => ['id' => 'MLM1'],
            'quantity' => 1,
            'unit_price' => 699,
            'gross_price' => 699,
        ]], 699);
        $this->order($account, '1002', [['item' => ['id' => 'MLM2'], 'quantity' => 2, 'unit_price' => 100]]);
        $this->order($account, '1003', [['item' => ['id' => 'MLM3'], 'quantity' => 1, 'gross_price' => 356]]);
        $this->order($account, '1004', [
            ['item' => ['id' => 'MLM4'], 'quantity' => 1, 'gross_price' => 100],
            ['item' => ['id' => 'MLM1'], 'quantity' => 1, 'gross_price' => 100],
        ]);
        $this->order($account, '1005', [['item' => ['id' => 'MLM5'], 'quantity' => 1, 'gross_price' => 229]]);
        $this->order($account, '1006', [['item' => ['id' => 'MLM6'], 'quantity' => 1, 'gross_price' => 660]]);
        $this->order($foreign, '2001', [['item' => ['id' => 'MLM1'], 'quantity' => 1, 'gross_price' => 1001.28]]);

        $billing = Mockery::mock(MeliHistoricalTaxDataService::class);
        $billing->shouldReceive('forOrders')
            ->once()
            ->withArgs(fn (MeliAccount $receivedAccount, array $orderIds): bool =>
                $receivedAccount->is($account)
                && collect($orderIds)->map('strval')->sort()->values()->all() === ['1001', '1002', '1003', '1006'])
            ->andReturn([
                'available' => true,
                'orders' => [
                    $this->billingOrder('1001', 'MLM1', $this->taxes(48.21, 15.06, false)),
                    $this->billingOrder('1002', 'MLM2', [$this->tax('iva', 13.79)]),
                    $this->billingOrder('1003', 'MLM3', [
                        $this->tax('iva', 24.55, 1.00),
                        $this->tax('isr', 7.67),
                    ]),
                    $this->billingOrder('1006', 'MLM6', [
                        $this->tax('iva', 45.52, 0, 'cancelled'),
                        $this->tax('isr', 14.22),
                    ]),
                ],
            ]);

        $observations = (new MeliHistoricalTaxObservationService($billing))->forAccount($account);

        $this->assertCount(1, $observations);
        $this->assertSame('1001', $observations[0]['order_id']);
        $this->assertSame('MLM1', $observations[0]['item_id']);
        $this->assertSame(699.0, $observations[0]['gross_sale_amount']);
        $this->assertSame(48.21, $observations[0]['vat_amount']);
        $this->assertSame(15.06, $observations[0]['income_tax_amount']);
        $this->assertSame('single_item', $observations[0]['attribution_scope']);

        $detectorInput = [];
        for ($index = 0; $index < 5; $index++) {
            $detectorInput[] = [
                ...$observations[0],
                'order_id' => (string) (9000 + $index),
                'item_id' => 'MLM-FIXTURE-'.($index % 3),
            ];
        }
        $rule = app(MeliHistoricalTaxRuleDetector::class)->detect((int) $account->id, $detectorInput);
        $this->assertTrue($rule['available']);
        $this->assertSame(16.0, $rule['vat_included_rate']);
        $this->assertSame(8.0, $rule['vat_withholding_rate']);
        $this->assertSame(2.5, $rule['income_tax_withholding_rate']);

        $json = strtolower(json_encode($observations, JSON_THROW_ON_ERROR));
        foreach (['buyer', 'email', 'phone', 'address', 'rfc', 'document', 'access_token', 'refresh_token'] as $piiKey) {
            $this->assertStringNotContainsString($piiKey, $json);
        }
    }

    public function test_gross_price_is_already_the_total_for_quantity_one_and_two(): void
    {
        $account = MeliAccount::query()->create(['meli_user_id' => '300']);
        foreach (['MLM-Q1', 'MLM-Q2', 'MLM-BAD'] as $itemId) {
            MeliPriceManagerItem::query()->create([
                'meli_account_id' => $account->id,
                'meli_item_id' => $itemId,
                'sku' => 'SKU-'.$itemId,
            ]);
        }

        $this->order($account, '3001', [[
            'item' => ['id' => 'MLM-Q1'],
            'quantity' => 1,
            'unit_price' => 699,
            'gross_price' => 699,
        ]], 699);
        $this->order($account, '3002', [[
            'item' => ['id' => 'MLM-Q2'],
            'quantity' => 2,
            'unit_price' => 699,
            'gross_price' => 1398,
        ]], 1398);
        $this->order($account, '3003', [[
            'item' => ['id' => 'MLM-BAD'],
            'quantity' => 2,
            'unit_price' => 699,
            'gross_price' => 699,
        ]], 1398);

        $billing = Mockery::mock(MeliHistoricalTaxDataService::class);
        $billing->shouldReceive('forOrders')
            ->once()
            ->withArgs(fn (MeliAccount $receivedAccount, array $orderIds): bool =>
                $receivedAccount->is($account)
                && collect($orderIds)->map('strval')->sort()->values()->all() === ['3001', '3002'])
            ->andReturn([
                'available' => true,
                'orders' => [
                    $this->billingOrder('3001', 'MLM-Q1', $this->taxes(48.21, 15.06, false)),
                    $this->billingOrder('3002', 'MLM-Q2', $this->taxes(96.41, 30.13, false)),
                ],
            ]);

        $observations = (new MeliHistoricalTaxObservationService($billing))->forAccount($account);

        $this->assertCount(2, $observations);
        $byOrder = collect($observations)->keyBy('order_id');
        $this->assertSame(699.0, $byOrder['3001']['gross_sale_amount']);
        $this->assertSame(1398.0, $byOrder['3002']['gross_sale_amount']);
    }

    /** @param list<array<string, mixed>> $items */
    private function order(MeliAccount $account, string $orderId, array $items, ?float $totalAmount = null): MeliOrder
    {
        return MeliOrder::query()->create([
            'meli_account_id' => $account->id,
            'order_id' => $orderId,
            'status' => 'paid',
            'raw' => array_filter([
                'order_items' => $items,
                'total_amount' => $totalAmount,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    /** @param list<array<string, mixed>> $taxes
     * @return array<string, mixed>
     */
    private function billingOrder(string $orderId, string $itemId, array $taxes): array
    {
        return [
            'order_id' => $orderId,
            'item_ids' => [$itemId],
            'payments' => [[
                'status' => 'approved',
                'date_approved' => '2026-08-20T12:00:00-06:00',
                'taxes' => $taxes,
            ]],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function taxes(float $vat, float $incomeTax, bool $withStatus = true): array
    {
        $status = $withStatus ? 'applied' : null;

        return [$this->tax('iva', $vat, 0, $status), $this->tax('isr', $incomeTax, 0, $status)];
    }

    /** @return array<string, mixed> */
    private function tax(string $type, float $amount, float $refunded = 0, ?string $status = 'applied'): array
    {
        return array_filter([
            'mov_detail' => 'tax_withholding',
            'mov_financial_entity' => $type,
            'tax_status' => $status,
            'original_amount' => $amount,
            'refunded_amount' => $refunded,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
