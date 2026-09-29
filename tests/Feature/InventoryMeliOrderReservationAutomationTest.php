<?php

namespace Tests\Feature;

use App\Jobs\ReconcileInventoryMeliOrderReservationsJob;
use App\Models\MeliOrder;
use App\Services\InventoryMeliOrderReservationDispatcher;
use App\Services\InventoryMeliOrderReservationService;
use App\Services\MeliOrderStoreService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class InventoryMeliOrderReservationAutomationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        config()->set('inventory.meli_order_reservations.automatic', true);
        config()->set(
            'inventory.meli_order_reservations.automatic_after',
            '2000-01-01T00:00:00+00:00'
        );
        Schema::create('meli_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_account_id')->nullable();
            $table->unsignedBigInteger('order_id');
            $table->string('topic')->nullable();
            $table->string('resource')->nullable();
            $table->string('status')->nullable();
            $table->json('raw')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('meli_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_order_id')->constrained('meli_orders')->cascadeOnDelete();
            $table->string('item_id');
            $table->string('variation_id')->nullable();
            $table->string('remote_line_key')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('meli_order_items');
        Schema::dropIfExists('meli_orders');
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_global_flag_disabled_dispatches_nothing(): void
    {
        config()->set('inventory.meli_order_reservations.automatic', false);
        Bus::fake();
        $order = $this->order('paid');
        app(InventoryMeliOrderReservationDispatcher::class)->dispatchAfterCommit($order);
        Bus::assertNothingDispatched();
    }

    public function test_enabled_dispatches_only_after_commit_to_meli_queue(): void
    {
        config()->set('inventory.meli_order_reservations.automatic', true);
        Bus::fake();
        $order = $this->order('paid');
        DB::transaction(function () use ($order): void {
            app(InventoryMeliOrderReservationDispatcher::class)->dispatchAfterCommit($order);
            Bus::assertNothingDispatched();
        });
        Bus::assertDispatched(ReconcileInventoryMeliOrderReservationsJob::class, function (ReconcileInventoryMeliOrderReservationsJob $job) use ($order): bool {
            return $job->meliOrderId === $order->id && $job->queue === 'meli';
        });
    }

    public function test_rollback_discards_the_after_commit_dispatch(): void
    {
        config()->set('inventory.meli_order_reservations.automatic', true);
        Bus::fake();
        $order = $this->order('paid');
        try {
            DB::transaction(function () use ($order): void {
                app(InventoryMeliOrderReservationDispatcher::class)->dispatchAfterCommit($order);
                throw new \RuntimeException('rollback test');
            });
        } catch (\RuntimeException) {
        }
        Bus::assertNothingDispatched();
    }

    public function test_queued_job_does_nothing_if_automation_is_disabled_before_execution(): void
    {
        $order = $this->order('paid');

        config()->set('inventory.meli_order_reservations.automatic', false);

        $service = Mockery::mock(InventoryMeliOrderReservationService::class);
        $service->shouldNotReceive('apply');

        (new ReconcileInventoryMeliOrderReservationsJob($order->id))
            ->handle($service);
    }

    public function test_queued_job_does_nothing_for_order_before_cutover(): void
    {
        config()->set(
            'inventory.meli_order_reservations.automatic_after',
            '2026-09-29T12:00:00-07:00'
        );

        $order = $this->order(
            'paid',
            '2026-09-29T11:59:59-07:00'
        );

        $service = Mockery::mock(InventoryMeliOrderReservationService::class);
        $service->shouldNotReceive('apply');

        (new ReconcileInventoryMeliOrderReservationsJob($order->id))
            ->handle($service);
    }

    public function test_queued_job_does_nothing_when_cutover_is_missing(): void
    {
        config()->set(
            'inventory.meli_order_reservations.automatic_after',
            null
        );

        $order = $this->order('paid');

        $service = Mockery::mock(InventoryMeliOrderReservationService::class);
        $service->shouldNotReceive('apply');

        (new ReconcileInventoryMeliOrderReservationsJob($order->id))
            ->handle($service);
    }

    public function test_job_loads_the_current_order_state_and_repeated_jobs_are_allowed(): void
    {
        $order = $this->order('paid');
        $order->update(['status' => 'cancelled']);
        $service = Mockery::mock(InventoryMeliOrderReservationService::class);
        $service->expects('apply')->once()->with(Mockery::on(fn (MeliOrder $fresh): bool => $fresh->id === $order->id && $fresh->status === 'cancelled'))->andReturn([]);
        $job = new ReconcileInventoryMeliOrderReservationsJob($order->id);
        $job->handle($service);
    }

    public function test_job_does_not_use_http_or_unique_locking(): void
    {
        Http::fake();
        $order = $this->order('paid');
        $service = Mockery::mock(InventoryMeliOrderReservationService::class);
        $service->expects('apply')->once()->andReturn([['action' => 'NO_CHANGE']]);
        $job = new ReconcileInventoryMeliOrderReservationsJob($order->id);
        $this->assertFalse($job instanceof \Illuminate\Contracts\Queue\ShouldBeUnique);
        $this->assertSame('meli', $job->queue);
        $job->handle($service);
        Http::assertNothingSent();
    }

    public function test_order_store_integration_dispatches_after_its_transaction_commits(): void
    {
        config()->set('inventory.meli_order_reservations.automatic', true);
        Bus::fake();
        $order = app(MeliOrderStoreService::class)->storeFromOrderApiResponse([
            'id' => 4001,
            'status' => 'paid',
            'date_created' => '2026-09-29T12:00:00-07:00',
            'order_items' => [[
                'item' => ['id' => 'MLM-AUTOMATION'],
                'quantity' => 1,
            ]],
        ]);
        Bus::assertDispatched(ReconcileInventoryMeliOrderReservationsJob::class, fn (ReconcileInventoryMeliOrderReservationsJob $job): bool => $job->meliOrderId === $order->id);
    }

    public function test_unexpected_job_exception_is_not_swallowed(): void
    {
        $order = $this->order('paid');
        $service = Mockery::mock(InventoryMeliOrderReservationService::class);
        $service->expects('apply')->once()->andThrow(new \RuntimeException('unexpected inventory failure'));
        $this->expectException(\RuntimeException::class);
        (new ReconcileInventoryMeliOrderReservationsJob($order->id))->handle($service);
    }

    public function test_sync_queue_job_failure_is_logged_without_rolling_back_the_order(): void
    {
        config()->set('inventory.meli_order_reservations.automatic', true);
        config()->set('queue.default', 'sync');
        Log::spy();
        $service = Mockery::mock(InventoryMeliOrderReservationService::class);
        $service->expects('apply')->once()->andThrow(new \RuntimeException(
            'Authorization: Bearer SUPER_SECRET_BEARER_123 access_token=SUPER_SECRET_ACCESS_456 refresh_token=SUPER_SECRET_REFRESH_789 token=SUPER_SECRET_TOKEN_000'
        ));
        $this->app->instance(InventoryMeliOrderReservationService::class, $service);

        $order = app(MeliOrderStoreService::class)->storeFromOrderApiResponse([
            'id' => 4010,
            'status' => 'paid',
            'date_created' => '2026-09-29T12:00:00-07:00',
            'order_items' => [[
                'item' => ['id' => 'MLM-SYNC-FAIL'],
                'quantity' => 1,
            ]],
        ]);

        $this->assertDatabaseHas('meli_orders', ['id' => $order->id, 'order_id' => 4010]);
        $this->assertDatabaseHas('meli_order_items', ['meli_order_id' => $order->id, 'item_id' => 'MLM-SYNC-FAIL']);
        Log::shouldHaveReceived('error')
            ->once()
            ->with(
                'Inventory Meli order reservation enqueue failed',
                Mockery::on(function (array $context): bool {
                    $logged = (string) ($context['message'] ?? '');

                    return ! str_contains($logged, 'SUPER_SECRET_BEARER_123')
                        && ! str_contains($logged, 'SUPER_SECRET_ACCESS_456')
                        && ! str_contains($logged, 'SUPER_SECRET_REFRESH_789')
                        && ! str_contains($logged, 'SUPER_SECRET_TOKEN_000')
                        && str_contains($logged, '[redacted]');
                }),
            );
    }

    public function test_business_diagnostic_result_finishes_without_retry_exception(): void
    {
        $order = $this->order('paid');
        $service = Mockery::mock(InventoryMeliOrderReservationService::class);
        $service->expects('apply')->once()->andReturn([['action' => 'INSUFFICIENT_INVENTORY']]);
        (new ReconcileInventoryMeliOrderReservationsJob($order->id))->handle($service);
        $this->assertTrue(true);
    }

    public function test_no_scheduler_registration_was_added_for_the_job(): void
    {
        $consoleRoutes = file_get_contents(base_path('routes/console.php'));
        $this->assertStringNotContainsString('ReconcileInventoryMeliOrderReservationsJob', $consoleRoutes);
    }

    private function order(
        string $status,
        ?string $dateCreated = '2026-09-29T12:00:00-07:00'
    ): MeliOrder {
        return MeliOrder::create([
            'meli_account_id' => 1,
            'order_id' => 3000 + MeliOrder::query()->count(),
            'status' => $status,
            'raw' => $dateCreated === null
                ? []
                : ['date_created' => $dateCreated],
        ]);
    }
}
