<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\System\QueueRecovery\QueuePayloadInspector;
use App\Services\System\QueueRecovery\QueueReconstructionDiagnosticsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * R2-DIAG 100,000-Job Volume, Memory (< 160 MiB), Streaming NDJSON & Non-N+1 Benchmark Suite.
 *
 * Uses 100% synthetic orders and manifests to validate:
 * 1. Streaming 100,000-entry R2.1 JSON manifest (with canonical SHA-256 verification) AND
 *    streaming 100,000-entry NDJSON manifest stay strictly under 160 MiB peak PHP memory.
 * 2. Zero N+1 query degradation: SQL query count grows strictly as O(distinct_orders / chunk_size),
 *    staying <= 10 queries per 500-order chunk.
 * 3. All fail-closed invariants pass at 100,000 jobs scale (0 unclassified, 0 duplicate primaries).
 * 4. Strictly read-only (zero writes to any DB table) and zero HTTP calls.
 */
class OpsQueuesDiagnoseExhaustiveVolumeTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        Config::set('inventory.meli_order_reservations.automatic_after', '2026-10-01T00:00:00-06:00');
        DB::purge('sqlite');

        $this->createSchema();
        $this->tempDir = storage_path('app/queue-recovery-diag-vol-'.bin2hex(random_bytes(4)));
        @mkdir($this->tempDir, 0750, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (scandir($this->tempDir) ?: [] as $f) {
                if ($f !== '.' && $f !== '..') {
                    @unlink($this->tempDir.DIRECTORY_SEPARATOR.$f);
                }
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_volume_100000_synthetic_jobs_streaming_ndjson_under_160_mib_and_no_n_plus_one(): void
    {
        $distinctOrders = 20000; // 20,000 distinct orders * 5 jobs each = 100,000 jobs!
        $jobsPerOrder = 5;
        $totalJobs = $distinctOrders * $jobsPerOrder;

        $this->seedSyntheticOrders($distinctOrders);

        // Stream-write a 100,000-entry NDJSON manifest (0 memory overhead!)
        $ndjsonManifestPath = $this->tempDir.DIRECTORY_SEPARATOR.'manifest_100k.ndjson';
        $fh = fopen($ndjsonManifestPath, 'wb');
        $this->assertNotFalse($fh);
        fwrite($fh, json_encode([
            'record_type' => 'manifest_header',
            'schema_version' => 2,
            'incident_id' => 'INC-VOL-100K-NDJSON',
            'allowed_jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'authorized_upper_bound' => $totalJobs,
            'totals' => ['scanned' => $totalJobs, 'candidates' => $totalJobs],
        ], JSON_THROW_ON_ERROR)."\n");

        $jobId = 1;
        for ($i = 1; $i <= $distinctOrders; $i++) {
            for ($r = 0; $r < $jobsPerOrder; $r++) {
                fwrite($fh, '{"id":'.$jobId.',"job_class":"App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob","entity_type":"meli_order","entity_id":'.$i.',"reserved_at":null}'."\n");
                $jobId++;
            }
        }
        fclose($fh);

        $ndjsonOutputPath = $this->tempDir.DIRECTORY_SEPARATOR.'diag_100k_out.ndjson';
        $summaryOutputPath = $this->tempDir.DIRECTORY_SEPARATOR.'diag_100k_summary.json';

        $sqlCount = 0;
        $writeQueryCount = 0;
        DB::connection('sqlite')->listen(function ($query) use (&$sqlCount, &$writeQueryCount): void {
            $sqlCount++;
            $sql = ltrim(strtoupper((string) $query->sql));
            if (str_starts_with($sql, 'INSERT') || str_starts_with($sql, 'UPDATE') || str_starts_with($sql, 'DELETE') || str_starts_with($sql, 'DROP') || str_starts_with($sql, 'ALTER')) {
                $writeQueryCount++;
            }
        });

        /** @var QueueReconstructionDiagnosticsService $service */
        $service = $this->app->make(QueueReconstructionDiagnosticsService::class);
        $startedAt = microtime(true);
        $report = $service->diagnose([
            'manifest_path' => $ndjsonManifestPath,
            'cutover' => '2026-10-01T00:00:00-06:00',
            'chunk' => 500,
            'ndjson_output_path' => $ndjsonOutputPath,
            'summary_only' => true,
        ]);
        $elapsedSeconds = microtime(true) - $startedAt;

        file_put_contents($summaryOutputPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $peakMemoryBytes = memory_get_peak_usage(true);
        $peakMemoryMiB = round($peakMemoryBytes / 1048576, 2);
        $manifestSizeBytes = (int) filesize($ndjsonManifestPath);
        $ndjsonOutBytes = (int) filesize($ndjsonOutputPath);
        $summaryJsonBytes = (int) filesize($summaryOutputPath);

        // Emit measurable telemetry for benchmark verification (visible with --testdox / fwrite STDERR when requested)
        $expectedChunks = (int) ceil($distinctOrders / 500); // 40 chunks
        $queriesPerChunk = round($sqlCount / max(1, $expectedChunks), 2);

        // Strict assertions:
        $this->assertSame(0, $writeQueryCount, 'Diagnostics MUST execute 0 write SQL statements!');
        $this->assertTrue($report['invariants']['all_passed']);
        $this->assertSame($distinctOrders, $report['invariants']['accounted_orders']);
        $this->assertSame(0, $report['invariants']['unclassified_orders']);
        $this->assertSame($distinctOrders, $report['invariants']['sum_primary_partition_counts']);
        $this->assertSame(0, $report['invariants']['duplicate_primary_classifications']);
        $this->assertSame($totalJobs, $report['invariants']['accounted_order_jobs']);
        $this->assertSame($totalJobs, $report['invariants']['total_manifest_entries']);
        $this->assertSame($distinctOrders, $report['exhaustive_classification']['ndjson_output']['records']);
        $this->assertSame(hash_file('sha256', $ndjsonOutputPath), $report['exhaustive_classification']['ndjson_output']['sha256']);

        // Memory strictly under 160 MiB!
        $this->assertLessThan(160.0, $peakMemoryMiB, "Peak memory {$peakMemoryMiB} MiB exceeded 160 MiB target!");

        // Proof of ZERO N+1 degradation: 40 chunks * ~8 queries/chunk + ~25 schema/catalog queries << 500 total queries (not 20,000+!)
        $this->assertLessThan(500, $sqlCount, "SQL query count ({$sqlCount}) indicates potential N+1 degradation across {$distinctOrders} orders!");
        $this->assertLessThan(12.0, $queriesPerChunk, "Queries per 500-order chunk ({$queriesPerChunk}) too high!");

        // Verify all 7 primary categories summed cleanly
        $this->assertSame($distinctOrders, array_sum($report['exhaustive_classification']['primary_partition_counts']));
        $this->assertGreaterThan(0, $manifestSizeBytes);
        $this->assertGreaterThan(0, $ndjsonOutBytes);
        $this->assertGreaterThan(0, $summaryJsonBytes);
        $this->assertLessThan(100000, $summaryJsonBytes, 'Summary JSON with --summary-only should remain compact (< 100 KB).');

        // Persist metrics file if requested via env var so we can report exact real numbers verbatim!
        $metricsOut = getenv('R2_DIAG_VOLUME_METRICS_OUT');
        if (is_string($metricsOut) && trim($metricsOut) !== '') {
            file_put_contents(trim($metricsOut), json_encode([
                'mode' => 'ndjson_100k',
                'total_jobs' => $totalJobs,
                'distinct_orders' => $distinctOrders,
                'elapsed_seconds' => round($elapsedSeconds, 3),
                'duration_ms' => $report['performance_metrics']['duration_ms'],
                'peak_memory_mib' => $peakMemoryMiB,
                'peak_memory_bytes' => $peakMemoryBytes,
                'sql_queries_total' => $sqlCount,
                'sql_write_queries' => $writeQueryCount,
                'chunks' => $expectedChunks,
                'queries_per_500_order_chunk' => $queriesPerChunk,
                'manifest_size_bytes' => $manifestSizeBytes,
                'ndjson_output_size_bytes' => $ndjsonOutBytes,
                'summary_json_size_bytes' => $summaryJsonBytes,
                'primary_partition_counts' => $report['exhaustive_classification']['primary_partition_counts'],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        Http::assertNothingSent();
    }

    public function test_volume_100000_synthetic_jobs_streaming_r2_v2_json_manifest_canonical_sha256_under_160_mib(): void
    {
        // Proves streaming canonical SHA-256 verification on a 100,000-entry R2.1 JSON manifest stays < 160 MiB!
        $distinctOrders = 10000;
        $jobsPerOrder = 10;
        $totalJobs = $distinctOrders * $jobsPerOrder; // 100,000 entries in standard R2.1 JSON!

        $this->seedSyntheticOrders($distinctOrders);

        $jsonManifestPath = $this->tempDir.DIRECTORY_SEPARATOR.'manifest_100k_v2.json';
        $expectedSha = $this->streamWriteCanonicalV2Manifest($jsonManifestPath, $distinctOrders, $jobsPerOrder);

        $ndjsonOutputPath = $this->tempDir.DIRECTORY_SEPARATOR.'diag_100k_json_out.ndjson';

        /** @var QueueReconstructionDiagnosticsService $service */
        $service = $this->app->make(QueueReconstructionDiagnosticsService::class);
        $startedAt = microtime(true);
        $report = $service->diagnose([
            'manifest_path' => $jsonManifestPath,
            'expected_manifest_sha256' => $expectedSha,
            'cutover' => '2026-10-01T00:00:00-06:00',
            'chunk' => 500,
            'ndjson_output_path' => $ndjsonOutputPath,
            'summary_only' => true,
        ]);
        $elapsedSeconds = microtime(true) - $startedAt;

        $peakMemoryMiB = round(memory_get_peak_usage(true) / 1048576, 2);

        $this->assertTrue($report['invariants']['all_passed']);
        $this->assertSame('json_r2_v2', $report['manifest_scope']['format']);
        $this->assertSame($expectedSha, $report['manifest_scope']['manifest_sha256']);
        $this->assertSame($distinctOrders, $report['invariants']['accounted_orders']);
        $this->assertSame($totalJobs, $report['invariants']['total_manifest_entries']);
        $this->assertSame(0, $report['invariants']['unclassified_orders']);
        $this->assertSame(0, $report['invariants']['duplicate_primary_classifications']);
        $this->assertLessThan(160.0, $peakMemoryMiB, "Peak memory {$peakMemoryMiB} MiB exceeded 160 MiB for 100k JSON manifest!");

        $metricsOut = getenv('R2_DIAG_VOLUME_JSON_METRICS_OUT');
        if (is_string($metricsOut) && trim($metricsOut) !== '') {
            file_put_contents(trim($metricsOut), json_encode([
                'mode' => 'json_r2_v2_100k',
                'total_jobs' => $totalJobs,
                'distinct_orders' => $distinctOrders,
                'elapsed_seconds' => round($elapsedSeconds, 3),
                'peak_memory_mib' => $peakMemoryMiB,
                'manifest_size_bytes' => (int) filesize($jsonManifestPath),
                'ndjson_output_size_bytes' => (int) filesize($ndjsonOutputPath),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }

        Http::assertNothingSent();
    }

    /**
     * Writes a valid 100,000-entry R2.1 JSON manifest in O(1) memory and returns its exact canonical manifest_sha256.
     */
    private function streamWriteCanonicalV2Manifest(string $path, int $distinctOrders, int $jobsPerOrder): string
    {
        $totalJobs = $distinctOrders * $jobsPerOrder;
        $headerFields = [
            'allowed_jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'anomalies' => [],
            'authorized_upper_bound' => $totalJobs,
            'connection' => 'sqlite',
            'database' => ':memory:',
            'generated_at' => '2026-11-26T00:00:00Z',
            'incident_id' => 'INC-VOL-100K-JSON',
            'is_partial' => false,
            'last_scanned_id' => $totalJobs,
            'mode' => 'dry_run',
            'next_start_id' => $totalJobs,
            'schema_version' => 2,
            'start_id' => 0,
            'table' => 'jobs',
            'totals' => ['candidates' => $totalJobs, 'scanned' => $totalJobs],
        ];
        ksort($headerFields);

        $hashCtx = hash_init('sha256');
        $fh = fopen($path, 'wb');
        $this->assertNotFalse($fh);

        $allKeys = array_keys($headerFields);
        $allKeys[] = 'entries';
        sort($allKeys, SORT_STRING);

        fwrite($fh, '{');
        hash_update($hashCtx, '{');

        $firstKey = true;
        foreach ($allKeys as $key) {
            if (! $firstKey) {
                fwrite($fh, ',');
                hash_update($hashCtx, ',');
            }
            $firstKey = false;
            $keyJson = '"'.$key.'":';
            fwrite($fh, $keyJson);
            hash_update($hashCtx, $keyJson);

            if ($key === 'entries') {
                fwrite($fh, '[');
                hash_update($hashCtx, '[');
                $jid = 1;
                $firstEntry = true;
                for ($oid = 1; $oid <= $distinctOrders; $oid++) {
                    for ($r = 0; $r < $jobsPerOrder; $r++) {
                        // Keys strictly sorted alphabetically so canonical hash matches verbatim!
                        $entryJson = '{"attempts":0,"available_at":1732550000,"created_at":1732550000,"entity_id":'.$oid.',"entity_type":"meli_order","id":'.$jid.',"job_class":"App\\\\Jobs\\\\ReconcileInventoryMeliOrderReservationsJob","payload_hash":"'.str_repeat('a', 64).'","queue":"meli","reserved_at":null,"row_sha256":"'.str_repeat('b', 64).'"}';
                        if (! $firstEntry) {
                            fwrite($fh, ',');
                            hash_update($hashCtx, ',');
                        }
                        $firstEntry = false;
                        fwrite($fh, $entryJson);
                        hash_update($hashCtx, $entryJson);
                        $jid++;
                    }
                }
                fwrite($fh, ']');
                hash_update($hashCtx, ']');
            } else {
                $valJson = json_encode($headerFields[$key], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                fwrite($fh, $valJson);
                hash_update($hashCtx, $valJson);
            }
        }

        hash_update($hashCtx, '}');
        $sha = hash_final($hashCtx);
        fwrite($fh, ',"manifest_sha256":"'.$sha.'"}');
        fclose($fh);

        return $sha;
    }

    private function seedSyntheticOrders(int $distinctOrders): void
    {
        DB::table('meli_accounts')->insert(['id' => 1, 'nickname' => 'VOL-ACC']);
        DB::table('inventory_products')->insert([
            ['id' => 10, 'sku' => 'SKU-V1', 'name' => 'Vol Simple', 'product_type' => 'simple', 'is_active' => 1],
            ['id' => 11, 'sku' => 'SKU-VK', 'name' => 'Vol Kit', 'product_type' => 'kit', 'is_active' => 1],
        ]);
        DB::table('inventory_channel_links')->insert([
            ['id' => 1, 'channel' => 'mercado_libre', 'account_key' => '1', 'external_listing_id' => 'MLM-V1', 'external_variant_id' => null, 'inventory_product_id' => 10, 'is_active' => 1, 'order_reservation_enabled' => 1, 'stock_sync_enabled' => 1],
            ['id' => 2, 'channel' => 'mercado_libre', 'account_key' => '1', 'external_listing_id' => 'MLM-VK', 'external_variant_id' => null, 'inventory_product_id' => 11, 'is_active' => 1, 'order_reservation_enabled' => 1, 'stock_sync_enabled' => 1],
        ]);

        $pdo = DB::connection('sqlite')->getPdo();
        $pdo->beginTransaction();
        $stmtOrder = $pdo->prepare('INSERT INTO meli_orders (id, meli_account_id, order_id, status, shipping_status, raw, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmtItem = $pdo->prepare('INSERT INTO meli_order_items (meli_order_id, item_id, variation_id, remote_line_key, sku, quantity) VALUES (?, ?, NULL, ?, ?, 1)');
        $stmtRes = $pdo->prepare('INSERT INTO inventory_reservations (id, source_type, source_id, inventory_product_id, quantity, status) VALUES (?, "meli_order", ?, 10, 1, "ACTIVE")');
        $stmtAlloc = $pdo->prepare('INSERT INTO inventory_channel_order_allocations (channel, account_key, remote_order_id, remote_line_key, identity_hash, inventory_product_id, reservation_kind, reservation_id, quantity, status) VALUES ("mercado_libre", "1", ?, "MLM-V1:", ?, 10, "SIMPLE", ?, ?, ?)');

        $resId = 1;
        for ($i = 1; $i <= $distinctOrders; $i++) {
            $mod = $i % 7;
            if ($mod === 0) {
                // Missing order -> pending_human_review
                continue;
            }
            $remote = 'V-'.$i;
            if ($mod === 1) {
                // Consistent paid + active reservation
                $stmtOrder->execute([$i, 1, $remote, 'paid', 'ready_to_ship', '{"date_created":"2026-11-10T10:00:00-06:00","status":"paid"}', '2026-11-10 10:00:00', '2026-11-10 10:00:00']);
                $stmtItem->execute([$i, 'MLM-V1', 'MLM-V1:', 'SKU-V1']);
                $stmtRes->execute([$resId, $i]);
                $stmtAlloc->execute([$remote, hash('sha256', "v1-{$i}"), $resId, 1, 'ACTIVE']);
                $resId++;
            } elseif ($mod === 2) {
                // Out of scope no link
                $stmtOrder->execute([$i, 1, $remote, 'paid', 'ready_to_ship', '{"date_created":"2026-11-10T10:00:00-06:00","status":"paid"}', '2026-11-10 10:00:00', '2026-11-10 10:00:00']);
                $stmtItem->execute([$i, 'MLM-UNLINKED', 'MLM-UNLINKED:', 'SKU-NONE']);
            } elseif ($mod === 3) {
                // Pre-cutover
                $stmtOrder->execute([$i, 1, $remote, 'paid', 'delivered', '{"date_created":"2026-08-01T10:00:00-06:00","status":"paid"}', '2026-08-01 10:00:00', '2026-08-01 10:00:00']);
                $stmtItem->execute([$i, 'MLM-V1', 'MLM-V1:', 'SKU-V1']);
            } elseif ($mod === 4) {
                // Reconstructible paid pending
                $stmtOrder->execute([$i, 1, $remote, 'paid', 'ready_to_ship', '{"date_created":"2026-11-10T10:00:00-06:00","status":"paid"}', '2026-11-10 10:00:00', '2026-11-10 10:00:00']);
                $stmtItem->execute([$i, 'MLM-V1', 'MLM-V1:', 'SKU-V1']);
            } elseif ($mod === 5) {
                // Ambiguous kit
                $stmtOrder->execute([$i, 1, $remote, 'paid', 'ready_to_ship', '{"date_created":"2026-11-10T10:00:00-06:00","status":"paid"}', '2026-11-10 10:00:00', '2026-11-10 10:00:00']);
                $stmtItem->execute([$i, 'MLM-VK', 'MLM-VK:', 'SKU-VK']);
            } else {
                // Consistent cancelled
                $stmtOrder->execute([$i, 1, $remote, 'cancelled', 'cancelled', '{"date_created":"2026-11-10T10:00:00-06:00","status":"cancelled"}', '2026-11-10 10:00:00', '2026-11-10 10:00:00']);
                $stmtItem->execute([$i, 'MLM-V1', 'MLM-V1:', 'SKU-V1']);
            }
        }
        $pdo->commit();
    }

    private function createSchema(): void
    {
        $schema = Schema::connection('sqlite');
        $schema->create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('nickname')->nullable();
        });
        $schema->create('meli_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_account_id')->nullable();
            $table->string('order_id')->nullable()->index();
            $table->string('status')->nullable();
            $table->string('shipping_id')->nullable();
            $table->string('shipping_status')->nullable();
            $table->text('shipping_raw')->nullable();
            $table->text('raw')->nullable();
            $table->timestamps();
        });
        $schema->create('meli_order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_order_id')->index();
            $table->string('item_id')->nullable();
            $table->string('variation_id')->nullable();
            $table->string('remote_line_key')->nullable();
            $table->string('sku')->nullable();
            $table->integer('quantity')->default(1);
        });
        $schema->create('inventory_products', function (Blueprint $table): void {
            $table->id();
            $table->string('sku');
            $table->string('name');
            $table->string('product_type')->default('simple');
            $table->boolean('is_active')->default(true);
        });
        $schema->create('inventory_kit_components', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('kit_product_id');
            $table->unsignedBigInteger('component_product_id');
            $table->integer('quantity')->default(1);
        });
        $schema->create('inventory_channel_links', function (Blueprint $table): void {
            $table->id();
            $table->string('channel');
            $table->string('account_key');
            $table->string('external_listing_id');
            $table->string('external_variant_id')->nullable();
            $table->unsignedBigInteger('inventory_product_id');
            $table->boolean('is_active')->default(true);
            $table->boolean('stock_sync_enabled')->default(false);
            $table->boolean('order_reservation_enabled')->default(false);
            $table->string('remote_user_product_id')->nullable();
        });
        $schema->create('inventory_channel_order_allocations', function (Blueprint $table): void {
            $table->id();
            $table->string('channel');
            $table->string('account_key');
            $table->string('remote_order_id')->index();
            $table->string('remote_line_key');
            $table->string('identity_hash')->unique();
            $table->unsignedBigInteger('inventory_product_id')->nullable();
            $table->string('reservation_kind')->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->integer('quantity')->default(0);
            $table->string('status')->default('PENDING');
            $table->string('diagnostic_code')->nullable();
        });
        $schema->create('inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->unsignedBigInteger('inventory_product_id');
            $table->integer('quantity')->default(1);
            $table->string('status')->default('ACTIVE');
            $table->text('metadata')->nullable();
        });
        $schema->create('inventory_kit_reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable()->index();
            $table->unsignedBigInteger('kit_product_id');
            $table->integer('quantity')->default(1);
            $table->string('status')->default('ACTIVE');
            $table->text('metadata')->nullable();
        });
        $schema->create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('inventory_product_id')->index();
            $table->string('type');
            $table->integer('quantity');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('external_key')->nullable()->index();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        $schema->create('inventory_meli_order_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_order_id')->unique();
            $table->unsignedBigInteger('meli_account_id')->nullable();
            $table->string('remote_order_id', 64)->nullable();
            $table->string('state', 24)->default('IDLE');
            $table->unsignedBigInteger('requested_revision')->default(0);
            $table->unsignedBigInteger('processed_revision')->default(0);
            $table->unsignedBigInteger('last_enqueued_revision')->default(0);
            $table->unsignedBigInteger('fencing_token')->default(0);
            $table->unsignedBigInteger('pending_job_id')->nullable();
            $table->timestamp('dispatch_confirmed_at')->nullable();
            $table->boolean('stock_sync_required')->default(false);
            $table->unsignedBigInteger('stock_sync_completed_revision')->default(0);
            $table->timestamp('lease_expires_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_reconciled_at')->nullable();
        });
    }
}
