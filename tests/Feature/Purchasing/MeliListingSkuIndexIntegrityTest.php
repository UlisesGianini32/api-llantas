<?php

namespace Tests\Feature\Purchasing;

use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\MeliAccount;
use App\Models\MeliPriceManagerItem;
use App\Models\MeliPublication;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\Purchasing\MeliListingSkuIndexService;
use App\Services\Purchasing\PurchaseOrderMeliImageResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class MeliListingSkuIndexIntegrityTest extends TestCase
{
    private User $admin;

    private InventoryLocation $location;

    private MeliAccount $account;

    private MeliListingSkuIndexService $indexService;

    private PurchaseOrderMeliImageResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        $this->createSchema();

        $this->admin = User::forceCreate([
            'name' => 'Admin Integrity',
            'email' => 'admin-integrity@sbs.test',
            'password' => bcrypt('secret'),
            'role' => User::ROLE_ADMIN,
        ]);

        $this->location = InventoryLocation::create([
            'code' => 'BOD-INT',
            'name' => 'Bodega Integridad',
            'type' => 'warehouse',
            'is_active' => true,
        ]);

        $this->account = MeliAccount::create([
            'user_id' => $this->admin->id,
            'meli_user_id' => '900111222',
            'nickname' => 'SBS_INTEGRITY',
            'is_default' => true,
        ]);

        $this->indexService = app(MeliListingSkuIndexService::class);
        $this->resolver = app(PurchaseOrderMeliImageResolver::class);
    }

    public function test_resolver_never_treats_partial_or_uninitialized_index_as_ready_even_if_rows_exist(): void
    {
        $prodVar = $this->createProduct('VAR-ONLY-1', 'Atomizador Variación 1');
        $order = $this->createOrderWithItems([$prodVar]);
        $item = $order->items->firstOrFail();

        // Creating a publication via Eloquent BEFORE initial backfill must NOT insert rows into the index!
        $pub = $this->createVariationPublication('MLM100001', 'PARENT-1', [
            ['id' => 1101, 'sku' => 'VAR-ONLY-1', 'url' => 'https://http2.mlstatic.com/var-only-1.jpg'],
        ]);

        $this->assertSame(0, DB::table(MeliListingSkuIndexService::TABLE)->count());
        $this->assertFalse($this->indexService->isReady());
        $this->assertNull($this->indexService->getActiveReadyGeneration());

        // Manually inject partial rows into generation 1 while state is uninitialized / building / failed
        DB::table(MeliListingSkuIndexService::TABLE)->insert([
            'generation' => 1,
            'source_table' => MeliListingSkuIndexService::SOURCE_PUBLICATIONS,
            'source_row_id' => $pub->id,
            'meli_account_id' => $this->account->id,
            'mlm' => 'MLM100001',
            'variation_id' => '1101',
            'variation_key' => '1101',
            'sku' => 'OTHER-PARTIAL-SKU',
            'sku_normalized' => 'OTHER-PARTIAL-SKU',
            'listing_status' => 'active',
            'is_current' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Even with rows physically present, resolver MUST NOT consider index ready when uninitialized
        $resolvedUninit = $this->resolver->resolveForOrder($order, false);
        $this->assertFalse($resolvedUninit[$item->id]['variation_index_used']);
        $this->assertSame('https://http2.mlstatic.com/var-only-1.jpg', $resolvedUninit[$item->id]['url']);

        // Same when state is 'building' with active_generation = 0
        DB::table(MeliListingSkuIndexService::STATE_TABLE)->insert([
            'id' => 1,
            'status' => MeliListingSkuIndexService::STATUS_BUILDING,
            'active_generation' => 0,
            'building_generation' => 1,
            'last_rebuild_status' => MeliListingSkuIndexService::REBUILD_STATUS_RUNNING,
            'build_started_at' => now(),
            'build_heartbeat_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolvedBuilding = $this->resolver->resolveForOrder($order, false);
        $this->assertFalse($resolvedBuilding[$item->id]['variation_index_used']);
        $this->assertSame('https://http2.mlstatic.com/var-only-1.jpg', $resolvedBuilding[$item->id]['url']);

        // Same when state is 'failed' with active_generation = 0
        DB::table(MeliListingSkuIndexService::STATE_TABLE)->where('id', 1)->update([
            'status' => MeliListingSkuIndexService::STATUS_FAILED,
            'building_generation' => null,
            'last_rebuild_status' => MeliListingSkuIndexService::REBUILD_STATUS_FAILED,
            'last_error' => 'Simulated crash',
        ]);

        $resolvedFailed = $this->resolver->resolveForOrder($order, false);
        $this->assertFalse($resolvedFailed[$item->id]['variation_index_used']);
        $this->assertSame('https://http2.mlstatic.com/var-only-1.jpg', $resolvedFailed[$item->id]['url']);
    }

    public function test_failed_initial_build_marks_failed_cleans_partial_rows_and_falls_back_cleanly(): void
    {
        Log::spy();

        $prod = $this->createProduct('INIT-FAIL-VAR', 'Producto Fallo Inicial');
        $order = $this->createOrderWithItems([$prod]);
        $item = $order->items->firstOrFail();

        for ($i = 1; $i <= 30; $i++) {
            $this->createVariationPublication(sprintf('MLM200%03d', $i), "P-{$i}", [
                ['id' => 2000 + $i, 'sku' => $i === 30 ? 'INIT-FAIL-VAR' : "NOISE-{$i}", 'url' => "https://http2.mlstatic.com/init-{$i}.jpg"],
            ]);
        }

        try {
            $this->indexService->rebuildIndex(25, static function (string $stage, int $gen, int $chunkIdx): void {
                if ($stage === 'publications_chunk' && $chunkIdx === 1) {
                    throw new RuntimeException('Fallo simulado a mitad del primer lote');
                }
            });
            $this->fail('Expected RuntimeException during initial rebuild.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Fallo simulado', $e->getMessage());
        }

        $status = $this->indexService->getDiagnosticStatus();
        $this->assertSame(MeliListingSkuIndexService::STATUS_FAILED, $status['status']);
        $this->assertSame('failed', $status['effective_health']);
        $this->assertFalse($status['usable_by_resolver']);
        $this->assertSame(0, $status['active_generation']);
        $this->assertNull($status['building_generation']);
        $this->assertSame(1, $status['failed_rebuilds_count']);
        $this->assertStringContainsString('Fallo simulado', (string) $status['last_error']);
        $this->assertSame(0, DB::table(MeliListingSkuIndexService::TABLE)->count(), 'Partial rows from failed candidate generation must be cleaned up.');

        // Resolver still resolves the photo cleanly via bounded fallback (zero silent photo loss)
        $resolved = $this->resolver->resolveForOrder($order, false);
        $this->assertFalse($resolved[$item->id]['variation_index_used']);
        $this->assertSame('https://http2.mlstatic.com/init-30.jpg', $resolved[$item->id]['url']);
    }

    public function test_failed_rebuilds_preserve_previous_valid_generation_at_every_stage_and_recover_on_retry(): void
    {
        Log::spy();

        $prodPub = $this->createProduct('PRESERVE-PUB-1', 'Atomizador Preservado');
        $prodPm = $this->createProduct('PRESERVE-PM-1', 'Camilla Preservada');
        $order = $this->createOrderWithItems([$prodPub, $prodPm]);
        [$itemPub, $itemPm] = [$order->items[0], $order->items[1]];

        $this->createVariationPublication('MLM300001', 'PARENT-300', [
            ['id' => 3001, 'sku' => 'PRESERVE-PUB-1', 'url' => 'https://http2.mlstatic.com/preserve-pub.jpg'],
        ]);
        $this->createVariationPriceManagerItem('MLM300002', 'PARENT-PM-300', [
            ['id' => 3002, 'sku' => 'PRESERVE-PM-1', 'url' => 'https://http2.mlstatic.com/preserve-pm.jpg'],
        ]);

        // Initial clean build -> Generation #1
        $gen1 = $this->indexService->rebuildIndex(25);
        $this->assertSame(1, $gen1['generation']);
        $this->assertSame(1, $this->indexService->getActiveReadyGeneration());

        // Simulate 3 distinct failures: mid-publications, mid-price-manager, and right before atomic publish
        foreach (['publications_chunk', 'price_manager_chunk', 'before_publish'] as $attempt => $failStage) {
            try {
                $this->indexService->rebuildIndex(25, static function (string $stage) use ($failStage): void {
                    if ($stage === $failStage) {
                        throw new RuntimeException("Fallo simulado en etapa {$failStage}");
                    }
                });
                $this->fail("Expected exception at stage {$failStage}.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($failStage, $e->getMessage());
            }

            // Generation #1 MUST remain active and completely intact!
            $diag = $this->indexService->getDiagnosticStatus();
            $this->assertSame(1, $diag['active_generation']);
            $this->assertNull($diag['building_generation']);
            $this->assertTrue($diag['usable_by_resolver']);
            $this->assertSame('ready_with_failed_rebuild', $diag['effective_health']);
            $this->assertSame(MeliListingSkuIndexService::REBUILD_STATUS_FAILED, $diag['last_rebuild_status']);
            $this->assertSame($attempt + 1, $diag['failed_rebuilds_count']);
            $this->assertSame(0, $diag['counts']['orphan_rows']);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $resolved = $this->resolver->resolveForOrder($order, false);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            $likeQueries = array_filter($queries, static fn (array $q): bool => stripos($q['query'], ' like ') !== false);
            $this->assertCount(0, $likeQueries);
            $this->assertTrue($resolved[$itemPub->id]['variation_index_used']);
            $this->assertSame(1, $resolved[$itemPub->id]['variation_index_generation']);
            $this->assertSame('https://http2.mlstatic.com/preserve-pub.jpg', $resolved[$itemPub->id]['url']);
            $this->assertSame('https://http2.mlstatic.com/preserve-pm.jpg', $resolved[$itemPm->id]['url']);
        }

        // Subsequent repeated rebuild succeeds cleanly and publishes Generation #2 (since failed generations were cleaned up)
        $recovered = $this->indexService->rebuildIndex(25);
        $this->assertGreaterThan(1, $recovered['generation']);
        $diagAfter = $this->indexService->getDiagnosticStatus();
        $this->assertSame('ready', $diagAfter['effective_health']);
        $this->assertSame($recovered['generation'], $diagAfter['active_generation']);
        $this->assertNull($diagAfter['last_error']);
        $this->assertSame(0, $diagAfter['counts']['orphan_rows']);
    }

    public function test_malformed_json_payload_is_logged_counted_and_does_not_break_rebuild(): void
    {
        Log::spy();

        $prodValid = $this->createProduct('VALID-AFTER-CORRUPT', 'Válido Después De Corrupto');
        $order = $this->createOrderWithItems([$prodValid]);
        $item = $order->items->firstOrFail();

        // Insert a row with malformed JSON directly via Query Builder (mirrors real production issue in MeliPublishService)
        DB::table('meli_publications')->insert([
            'user_id' => $this->admin->id,
            'meli_account_id' => $this->account->id,
            'sku' => 'CORRUPT-COL-SKU',
            'mlm' => 'MLM400001',
            'status' => 'active',
            'is_current' => 1,
            'last_sync_at' => now(),
            'raw' => '{invalid-json-payload',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createVariationPublication('MLM400002', 'PARENT-400', [
            ['id' => 4002, 'sku' => 'VALID-AFTER-CORRUPT', 'url' => 'https://http2.mlstatic.com/valid-after-corrupt.jpg'],
        ]);

        $stats = $this->indexService->rebuildIndex(25);

        $this->assertSame(1, $stats['json_errors']);
        $this->assertSame(1, $stats['generation']);
        $this->assertDatabaseHas(MeliListingSkuIndexService::TABLE, [
            'generation' => 1,
            'mlm' => 'MLM400001',
            'sku_normalized' => 'CORRUPT-COL-SKU',
        ]);
        $this->assertDatabaseHas(MeliListingSkuIndexService::TABLE, [
            'generation' => 1,
            'mlm' => 'MLM400002',
            'sku_normalized' => 'VALID-AFTER-CORRUPT',
        ]);

        $resolved = $this->resolver->resolveForOrder($order, false);
        $this->assertTrue($resolved[$item->id]['variation_index_used']);
        $this->assertSame('https://http2.mlstatic.com/valid-after-corrupt.jpg', $resolved[$item->id]['url']);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_concurrent_reads_and_mutations_during_rebuild_lose_zero_associations_and_block_overlapping_rebuilds(): void
    {
        $pInitial = $this->createProduct('CONC-INIT', 'Inicial Gen 1');
        $pCreated = $this->createProduct('CONC-CREATE', 'Creado Durante Rebuild');
        $pUpdated = $this->createProduct('CONC-UPDATE', 'Actualizado Durante Rebuild');
        $pQbUpdated = $this->createProduct('CONC-QB', 'QueryBuilder Durante Rebuild');
        $pDeleted = $this->createProduct('CONC-DEL', 'Eliminado Durante Rebuild');
        $pRawDeleted = $this->createProduct('CONC-RAWDEL', 'Eliminado SQL Durante Rebuild');

        $order = $this->createOrderWithItems([$pInitial, $pCreated, $pUpdated, $pQbUpdated, $pDeleted, $pRawDeleted]);
        $itemsBySku = $order->items->keyBy('sku');

        // Seed 30 initial publications so rebuild runs in 2 chunks (chunkSize = 25).
        // Put rows to mutate/delete in chunk 1 (IDs 1..4) so they are ALREADY scanned when chunk 1 callback fires!
        $pubToUpdate = $this->createVariationPublication('MLM500001', 'P-500-1', [
            ['id' => 5001, 'sku' => 'CONC-INIT', 'url' => 'https://http2.mlstatic.com/conc-init.jpg'],
        ]);
        $pubToQbUpdate = $this->createVariationPublication('MLM500002', 'P-500-2', [
            ['id' => 5002, 'sku' => 'BEFORE-QB', 'url' => 'https://http2.mlstatic.com/before-qb.jpg'],
        ]);
        $pubToDelete = $this->createVariationPublication('MLM500003', 'P-500-3', [
            ['id' => 5003, 'sku' => 'CONC-DEL', 'url' => 'https://http2.mlstatic.com/conc-del.jpg'],
        ]);
        $pubToRawDelete = $this->createVariationPublication('MLM500004', 'P-500-4', [
            ['id' => 5004, 'sku' => 'CONC-RAWDEL', 'url' => 'https://http2.mlstatic.com/conc-rawdel.jpg'],
        ]);

        for ($i = 5; $i <= 30; $i++) {
            $this->createVariationPublication(sprintf('MLM500%03d', $i), "P-500-{$i}", [
                ['id' => 5000 + $i, 'sku' => "FILLER-{$i}", 'url' => "https://http2.mlstatic.com/filler-{$i}.jpg"],
            ]);
        }

        // Publish Generation #1 cleanly
        $gen1 = $this->indexService->rebuildIndex(25);
        $this->assertSame(1, $gen1['generation']);

        $midBuildReadGeneration = null;
        $midBuildOverlapBlocked = false;

        // Run Generation #2 rebuild and inject concurrent reads, Eloquent writes, Query Builder writes, deletes, and overlapping rebuild!
        $gen2 = $this->indexService->rebuildIndex(25, function (string $stage, int $buildingGen, int $chunkIdx) use (
            $order,
            $itemsBySku,
            $pubToUpdate,
            $pubToQbUpdate,
            $pubToDelete,
            $pubToRawDelete,
            &$midBuildReadGeneration,
            &$midBuildOverlapBlocked
        ): void {
            if ($stage !== 'publications_chunk' || $chunkIdx !== 1) {
                return;
            }

            // 1. Concurrent read while Gen #2 is building MUST still serve Gen #1 cleanly!
            $midResolved = $this->resolver->resolveForOrder($order, false);
            $midBuildReadGeneration = $midResolved[$itemsBySku['CONC-INIT']->id]['variation_index_generation'];
            $this->assertSame('https://http2.mlstatic.com/conc-init.jpg', $midResolved[$itemsBySku['CONC-INIT']->id]['url']);

            // 2. Overlapping second rebuild must be rejected cleanly
            try {
                $this->indexService->rebuildIndex(25);
            } catch (RuntimeException $e) {
                $midBuildOverlapBlocked = str_contains($e->getMessage(), 'reconstrucción activa');
            }

            // 3. Concurrent Eloquent create (new listing arrives during rebuild)
            $this->createVariationPublication('MLM500999', 'P-500-NEW', [
                ['id' => 5999, 'sku' => 'CONC-CREATE', 'url' => 'https://http2.mlstatic.com/conc-create.jpg'],
            ]);

            // 4. Concurrent Eloquent update of an ALREADY-SCANNED row (ID #1)
            $pubToUpdate->update([
                'raw' => [
                    'item' => [
                        'pictures' => [
                            ['id' => 'p1', 'secure_url' => 'https://http2.mlstatic.com/conc-init.jpg'],
                            ['id' => 'p2', 'secure_url' => 'https://http2.mlstatic.com/conc-update.jpg'],
                        ],
                        'variations' => [
                            ['id' => 5001, 'seller_custom_field' => 'CONC-INIT', 'picture_ids' => ['p1']],
                            ['id' => 5091, 'seller_custom_field' => 'CONC-UPDATE', 'picture_ids' => ['p2']],
                        ],
                    ],
                ],
            ]);

            // 5. Concurrent Query Builder update (bypassing Eloquent hooks!) on ALREADY-SCANNED row (ID #2)
            DB::table('meli_publications')->where('id', $pubToQbUpdate->id)->update([
                'raw' => json_encode([
                    'item' => [
                        'pictures' => [['id' => 'qb1', 'secure_url' => 'https://http2.mlstatic.com/conc-qb.jpg']],
                        'variations' => [['id' => 5092, 'seller_custom_field' => 'CONC-QB', 'picture_ids' => ['qb1']]],
                    ],
                ]),
                'updated_at' => now()->addSecond()->toDateTimeString(),
            ]);

            // 6. Concurrent Eloquent delete on ALREADY-SCANNED row (ID #3)
            $pubToDelete->delete();

            // 7. Concurrent raw SQL / Query Builder delete (bypassing Eloquent hooks!) on ALREADY-SCANNED row (ID #4)
            DB::table('meli_publications')->where('id', $pubToRawDelete->id)->delete();
        });

        $this->assertSame(1, $midBuildReadGeneration);
        $this->assertTrue($midBuildOverlapBlocked, 'Overlapping concurrent rebuild must be rejected.');
        $this->assertSame(2, $gen2['generation']);
        $this->assertGreaterThanOrEqual(1, $gen2['pruned_deleted_rows']);

        // After Gen #2 publishes atomically: ZERO lost associations, ZERO stale deleted rows, ZERO LIKE queries!
        DB::flushQueryLog();
        DB::enableQueryLog();
        $finalResolved = $this->resolver->resolveForOrder($order->fresh('items.product'), false);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $likeQueries = array_filter($queries, static fn (array $q): bool => stripos($q['query'], ' like ') !== false);
        $this->assertCount(0, $likeQueries);

        $this->assertSame(2, $finalResolved[$itemsBySku['CONC-INIT']->id]['variation_index_generation']);
        $this->assertSame('https://http2.mlstatic.com/conc-init.jpg', $finalResolved[$itemsBySku['CONC-INIT']->id]['url']);
        $this->assertSame('https://http2.mlstatic.com/conc-create.jpg', $finalResolved[$itemsBySku['CONC-CREATE']->id]['url']);
        $this->assertSame('https://http2.mlstatic.com/conc-update.jpg', $finalResolved[$itemsBySku['CONC-UPDATE']->id]['url']);
        $this->assertSame('https://http2.mlstatic.com/conc-qb.jpg', $finalResolved[$itemsBySku['CONC-QB']->id]['url']);
        $this->assertNull($finalResolved[$itemsBySku['CONC-DEL']->id]['url']);
        $this->assertNull($finalResolved[$itemsBySku['CONC-RAWDEL']->id]['url']);

        // Generation #1 rows were purged only after Gen #2 published
        $this->assertSame(0, DB::table(MeliListingSkuIndexService::TABLE)->where('generation', 1)->count());
    }

    public function test_incremental_reconciliation_and_readonly_status_command_handle_query_builder_drift_and_stale_builds(): void
    {
        $pKeep = $this->createProduct('INC-KEEP', 'Existente');
        $pQbNew = $this->createProduct('INC-QB-NEW', 'Insertado Sin Hook');
        $pQbGone = $this->createProduct('INC-QB-GONE', 'Eliminado Sin Hook');
        $order = $this->createOrderWithItems([$pKeep, $pQbNew, $pQbGone]);
        $itemsBySku = $order->items->keyBy('sku');

        $this->createVariationPublication('MLM600001', 'P-600-1', [
            ['id' => 6001, 'sku' => 'INC-KEEP', 'url' => 'https://http2.mlstatic.com/inc-keep.jpg'],
        ]);
        $pubGone = $this->createVariationPublication('MLM600002', 'P-600-2', [
            ['id' => 6002, 'sku' => 'INC-QB-GONE', 'url' => 'https://http2.mlstatic.com/inc-gone.jpg'],
        ]);

        // Initial build via Artisan command
        $this->assertSame(0, Artisan::call('purchasing:sync-meli-photo-sku-index', ['--chunk' => 50]));

        // Simulate Query Builder insert + raw delete (bypassing Eloquent hooks) + recorded incremental hook error
        DB::table('meli_publications')->insert([
            'user_id' => $this->admin->id,
            'meli_account_id' => $this->account->id,
            'sku' => 'PARENT-QB-NEW',
            'mlm' => 'MLM600003',
            'status' => 'active',
            'is_current' => 1,
            'last_sync_at' => now(),
            'raw' => json_encode([
                'item' => [
                    'pictures' => [['id' => 'qn1', 'secure_url' => 'https://http2.mlstatic.com/inc-qb-new.jpg']],
                    'variations' => [['id' => 6003, 'seller_custom_field' => 'INC-QB-NEW', 'picture_ids' => ['qn1']]],
                ],
            ]),
            'created_at' => now()->addSecond(),
            'updated_at' => now()->addSecond(),
        ]);
        DB::table('meli_publications')->where('id', $pubGone->id)->delete();

        DB::table(MeliListingSkuIndexService::STATE_TABLE)->where('id', 1)->update([
            'incremental_sync_errors_count' => 2,
            'last_incremental_error' => '[sync meli_publications#99] Lock wait timeout',
            'last_incremental_error_at' => now(),
        ]);

        // Read-only diagnostic command detects incremental errors without writing anything
        $statusCode = Artisan::call('purchasing:meli-photo-sku-index-status', ['--json' => true]);
        $this->assertSame(0, $statusCode);
        $diagBefore = json_decode(Artisan::output(), true);
        $this->assertSame('ready_with_incremental_errors', $diagBefore['effective_health']);
        $this->assertSame(2, $diagBefore['incremental_sync']['errors_count']);
        $this->assertTrue($diagBefore['incremental_sync']['drift_suspected']);

        // Run --incremental reconciliation command
        $incCode = Artisan::call('purchasing:sync-meli-photo-sku-index', ['--incremental' => true, '--chunk' => 50]);
        $this->assertSame(0, $incCode);

        $resolved = $this->resolver->resolveForOrder($order->fresh('items.product'), false);
        $this->assertTrue($resolved[$itemsBySku['INC-QB-NEW']->id]['variation_index_used']);
        $this->assertSame('https://http2.mlstatic.com/inc-qb-new.jpg', $resolved[$itemsBySku['INC-QB-NEW']->id]['url']);
        $this->assertNull($resolved[$itemsBySku['INC-QB-GONE']->id]['url']);

        // Read-only status now reports clean 'ready'
        Artisan::call('purchasing:meli-photo-sku-index-status', ['--json' => true]);
        $diagAfter = json_decode(Artisan::output(), true);
        $this->assertSame('ready', $diagAfter['effective_health']);
        $this->assertSame(0, $diagAfter['incremental_sync']['errors_count']);
        $this->assertFalse($diagAfter['incremental_sync']['drift_suspected']);

        // Simulate an OOM/SIGKILL interrupted rebuild (stale heartbeat + orphaned Gen #2 rows while Gen #1 stays active)
        DB::table(MeliListingSkuIndexService::TABLE)->insert([
            'generation' => 2,
            'source_table' => MeliListingSkuIndexService::SOURCE_PUBLICATIONS,
            'source_row_id' => 99999,
            'meli_account_id' => $this->account->id,
            'mlm' => 'MLM-ORPHAN',
            'variation_id' => null,
            'variation_key' => '',
            'sku' => 'ORPHAN-SKU',
            'sku_normalized' => 'ORPHAN-SKU',
            'listing_status' => 'active',
            'is_current' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table(MeliListingSkuIndexService::STATE_TABLE)->where('id', 1)->update([
            'status' => MeliListingSkuIndexService::STATUS_BUILDING,
            'building_generation' => 2,
            'build_started_at' => now()->subHours(2),
            'build_heartbeat_at' => now()->subHours(2),
        ]);

        Artisan::call('purchasing:meli-photo-sku-index-status', ['--json' => true]);
        $diagStale = json_decode(Artisan::output(), true);
        $this->assertSame('ready_with_interrupted_rebuild', $diagStale['effective_health']);
        $this->assertTrue($diagStale['build_is_stale']);
        $this->assertTrue($diagStale['usable_by_resolver']);
        $this->assertSame(1, $diagStale['active_generation']);
        $this->assertSame(1, $diagStale['counts']['orphan_rows']);

        // Next rebuild automatically recovers from stale interrupted build, publishes Gen #3, and purges Gen #1 & #2
        $recoverCode = Artisan::call('purchasing:sync-meli-photo-sku-index', ['--chunk' => 50]);
        $this->assertSame(0, $recoverCode);

        Artisan::call('purchasing:meli-photo-sku-index-status', ['--json' => true]);
        $diagRecovered = json_decode(Artisan::output(), true);
        $this->assertSame('ready', $diagRecovered['effective_health']);
        $this->assertSame(3, $diagRecovered['active_generation']);
        $this->assertSame(0, $diagRecovered['counts']['orphan_rows']);
    }

    /** @param list<array{id: int, sku: string, url: string}> $variations */
    private function createVariationPublication(string $mlm, string $parentSku, array $variations): MeliPublication
    {
        $pictures = [];
        $varPayloads = [];
        foreach ($variations as $idx => $v) {
            $picId = "p-{$mlm}-{$idx}";
            $pictures[] = ['id' => $picId, 'secure_url' => $v['url']];
            $varPayloads[] = [
                'id' => $v['id'],
                'seller_custom_field' => $v['sku'],
                'picture_ids' => [$picId],
            ];
        }

        return MeliPublication::create([
            'user_id' => $this->admin->id,
            'meli_account_id' => $this->account->id,
            'sku' => $parentSku,
            'mlm' => $mlm,
            'status' => 'active',
            'is_current' => true,
            'last_sync_at' => now(),
            'raw' => [
                'item' => [
                    'title' => "Publicación {$mlm}",
                    'pictures' => $pictures,
                    'variations' => $varPayloads,
                ],
            ],
        ]);
    }

    /** @param list<array{id: int, sku: string, url: string}> $variations */
    private function createVariationPriceManagerItem(string $mlm, string $parentSku, array $variations): MeliPriceManagerItem
    {
        $pictures = [];
        $varPayloads = [];
        foreach ($variations as $idx => $v) {
            $picId = "pm-{$mlm}-{$idx}";
            $pictures[] = ['id' => $picId, 'secure_url' => $v['url']];
            $varPayloads[] = [
                'id' => $v['id'],
                'seller_custom_field' => $v['sku'],
                'picture_ids' => [$picId],
            ];
        }

        return MeliPriceManagerItem::create([
            'meli_account_id' => $this->account->id,
            'meli_item_id' => $mlm,
            'sku' => $parentSku,
            'title' => "PriceManager {$mlm}",
            'current_price' => 100,
            'status' => 'active',
            'last_synced_at' => now(),
            'raw_item' => [
                'id' => $mlm,
                'pictures' => $pictures,
                'variations' => $varPayloads,
            ],
        ]);
    }

    private function createProduct(string $sku, string $name): InventoryProduct
    {
        return InventoryProduct::create([
            'sku' => $sku,
            'name' => $name,
            'product_type' => InventoryProduct::SIMPLE,
            'is_active' => true,
            'cost' => 50,
            'brand' => 'CHINA',
        ]);
    }

    /** @param list<InventoryProduct> $products */
    private function createOrderWithItems(array $products): PurchaseOrder
    {
        $order = PurchaseOrder::create([
            'order_number' => 'OC-20261120-'.str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'supplier_name' => 'Compras China',
            'inventory_location_id' => $this->location->id,
            'user_id' => $this->admin->id,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'total_items_count' => count($products),
            'total_units_ordered' => count($products) * 10,
            'total_units_received' => 0,
            'subtotal' => 0,
            'total_cost' => 0,
        ]);

        foreach ($products as $product) {
            PurchaseOrderItem::create([
                'purchase_order_id' => $order->id,
                'inventory_product_id' => $product->id,
                'sku' => $product->sku,
                'product_name' => $product->name,
                'quantity_ordered' => 10,
                'quantity_received' => 0,
                'unit_cost' => 50,
                'subtotal' => 500,
                'status' => 'PENDING',
            ]);
        }

        return $order->fresh(['items.product']);
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default(User::ROLE_ADMIN);
            $table->timestamps();
        });

        Schema::create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('meli_user_id');
            $table->string('nickname')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('meli_publications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('meli_account_id')->nullable();
            $table->string('sku')->nullable();
            $table->string('mlm')->nullable();
            $table->string('source_mlm')->nullable();
            $table->string('status')->nullable();
            $table->json('sub_status')->nullable();
            $table->string('permalink')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->json('raw')->nullable();
            $table->json('pictures')->nullable();
            $table->boolean('is_current')->default(true);
            $table->timestamps();
        });

        Schema::create('meli_price_manager_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('meli_account_id');
            $table->string('meli_item_id', 64);
            $table->string('sku', 191)->nullable();
            $table->string('title');
            $table->decimal('current_price', 15, 2)->default(0);
            $table->string('status', 64)->nullable();
            $table->string('thumbnail', 2048)->nullable();
            $table->json('raw_attributes')->nullable();
            $table->json('raw_item')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        foreach (glob(database_path('migrations/2026_09_24_00000*.php')) as $path) {
            (require $path)->up();
        }
        foreach (glob(database_path('migrations/2026_09_25_00000*.php')) as $path) {
            (require $path)->up();
        }
        (require database_path('migrations/2026_09_26_000001_add_remote_user_product_id_to_inventory_channel_links.php'))->up();
        (require database_path('migrations/2026_09_30_000003_create_restock_configurations_table.php'))->up();
        (require database_path('migrations/2026_09_30_000004_create_purchase_orders_tables.php'))->up();
        (require database_path('migrations/2026_10_21_160000_create_supplier_products_table.php'))->up();
        (require database_path('migrations/2026_11_20_000001_add_photo_selection_fields_to_purchase_order_items_table.php'))->up();
        (require database_path('migrations/2026_11_21_000001_create_meli_listing_sku_index_table.php'))->up();
    }
}
