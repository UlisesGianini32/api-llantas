<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\System\QueueRecovery\QueueOrderExhaustiveClassifier;
use App\Services\System\QueueRecovery\QueuePayloadInspector;
use App\Services\System\QueueRecovery\QueueReconstructionDiagnosticsService;
use App\Services\System\QueueRecovery\QueueRecoveryException;
use App\Services\System\QueueRecovery\QueueRecoveryManifest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * R2-DIAG Exhaustive Classification Test Suite (100% synthetic fixtures, strictly read-only).
 * Covers all 25 mandatory functional scenarios + pilot-equivalent 1,000-job / 736-order manifest test.
 */
class OpsQueuesDiagnoseExhaustiveTest extends TestCase
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

        $this->createFullTestSchema('sqlite', includeR3Table: true);

        $this->tempDir = storage_path('app/queue-recovery-diag-tests-'.bin2hex(random_bytes(4)));
        @mkdir($this->tempDir, 0750, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    // =========================================================================
    // 1. All 7 primary categories + required entity fields + read-only proof + zero PII
    // =========================================================================
    public function test_01_all_seven_primary_categories_and_required_fields_strictly_read_only(): void
    {
        $this->seedBaseCatalog();

        // 1) pending_human_review (missing order 1001)
        // 2) ambiguous_entities (order 1002: multiline)
        $this->insertOrder(1002, 1, 'O-1002', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00', 'buyer' => ['email' => 'secret@example.test', 'access_token' => 'APP_USR-SECRET']]);
        $this->insertItem(1002, 'MLM-ACTIVE', 'MLM-ACTIVE:1', 'SKU-ACTIVE', 1);
        $this->insertItem(1002, 'MLM-ACTIVE', 'MLM-ACTIVE:2', 'SKU-ACTIVE', 1);

        // 3) reconstructible_candidates (order 1003: paid pending reservation)
        $this->insertOrder(1003, 1, 'O-1003', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(1003, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 2);

        // 4) consistent_no_action_needed (order 1004: paid with exact active allocation + reservation)
        $this->insertOrder(1004, 1, 'O-1004', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(1004, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 2);
        $resId = $this->insertReservation(1004, 10, 2, 'ACTIVE');
        $this->insertAllocation(1, 'O-1004', 'MLM-ACTIVE:', 10, 'SIMPLE', $resId, 2, 'ACTIVE');

        // 5) out_of_scope_no_reservation_link (order 1005: unmatched listing)
        $this->insertOrder(1005, 1, 'O-1005', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(1005, 'MLM-UNMATCHED', 'MLM-UNMATCHED:', 'SKU-NONE', 1);

        // 6) out_of_scope_pre_cutover_or_ignored_status (order 1006: valid pre-cutover)
        $this->insertOrder(1006, 1, 'O-1006', 'paid', 'ready_to_ship', ['date_created' => '2026-09-01T10:00:00-06:00']);
        $this->insertItem(1006, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);

        // 7) insufficient_evidence (order 1007: paid order with RELEASED allocation and no active reservation)
        $this->insertOrder(1007, 1, 'O-1007', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(1007, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        $this->insertAllocation(1, 'O-1007', 'MLM-ACTIVE:', 10, 'SIMPLE', null, 0, 'RELEASED');

        $manifestPath = $this->writeV2Manifest('m01.json', [
            ['id' => 1, 'entity_id' => 1001],
            ['id' => 2, 'entity_id' => 1002],
            ['id' => 3, 'entity_id' => 1003],
            ['id' => 4, 'entity_id' => 1004],
            ['id' => 5, 'entity_id' => 1005],
            ['id' => 6, 'entity_id' => 1006],
            ['id' => 7, 'entity_id' => 1007],
        ]);

        $beforeHashes = $this->hashAllTables();
        $ndjsonPath = $this->tmp('out01.ndjson');
        $jsonPath = $this->tmp('out01.json');

        $exitCode = Artisan::call('ops:queues-diagnose-reconstruction', [
            '--manifest' => $manifestPath,
            '--cutover' => '2026-10-01T00:00:00-06:00',
            '--ndjson-output' => $ndjsonPath,
            '--output' => $jsonPath,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertFileExists($ndjsonPath);
        $this->assertFileExists($jsonPath);

        $rawJson = (string) file_get_contents($jsonPath);
        $rawNdjson = (string) file_get_contents($ndjsonPath);

        // Zero buyer PII or credentials leaked!
        $this->assertStringNotContainsString('secret@example.test', $rawJson);
        $this->assertStringNotContainsString('APP_USR-SECRET', $rawJson);
        $this->assertStringNotContainsString('secret@example.test', $rawNdjson);
        $this->assertStringNotContainsString('APP_USR-SECRET', $rawNdjson);

        $report = json_decode($rawJson, true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('2.0', $report['format_version']);
        $this->assertTrue($report['invariants']['all_passed']);
        $this->assertSame(7, $report['invariants']['accounted_orders']);
        $this->assertSame(0, $report['invariants']['unclassified_orders']);
        $this->assertSame(7, $report['invariants']['sum_primary_partition_counts']);
        $this->assertSame(0, $report['invariants']['duplicate_primary_classifications']);

        $partition = $report['exhaustive_classification']['primary_partition_counts'];
        foreach (QueueOrderExhaustiveClassifier::PRIMARY_CATEGORIES as $cat) {
            $this->assertSame(1, $partition[$cat], "Expected exactly 1 order in category {$cat}");
        }

        $byId = $this->indexEntitiesById($report);
        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[1001]['primary_classification']);
        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_AMBIGUOUS_ENTITIES, $byId[1002]['primary_classification']);
        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_RECONSTRUCTIBLE_CANDIDATES, $byId[1003]['primary_classification']);
        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_CONSISTENT_NO_ACTION_NEEDED, $byId[1004]['primary_classification']);
        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_OUT_OF_SCOPE_NO_LINK, $byId[1005]['primary_classification']);
        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_OUT_OF_SCOPE_PRE_CUTOVER_OR_IGNORED, $byId[1006]['primary_classification']);
        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_INSUFFICIENT_EVIDENCE, $byId[1007]['primary_classification']);

        // Verify ALL required per-entity fields on every record
        foreach ($byId as $entity) {
            foreach ([
                'local_order_id', 'remote_order_id', 'meli_account_id',
                'associated_job_ids', 'associated_jobs_count', 'order_status',
                'shipping_status', 'allocation_status', 'reservation_status',
                'primary_classification', 'reason_codes', 'secondary_anomalies',
                'available_evidence', 'missing_evidence', 'operational_recommendation',
            ] as $requiredKey) {
                $this->assertArrayHasKey($requiredKey, $entity);
            }
            $this->assertFalse($entity['operational_recommendation']['automatic_execution_allowed']);
            $this->assertFalse($entity['operational_recommendation']['automatic_job_deletion_allowed']);
        }

        // Verify NDJSON SHA-256 and line count
        $ndjsonMeta = $report['exhaustive_classification']['ndjson_output'];
        $this->assertSame(7, $ndjsonMeta['records']);
        $this->assertSame(hash_file('sha256', $ndjsonPath), $ndjsonMeta['sha256']);

        // Strictly read-only: zero mutations across ALL tables!
        $this->assertSame($beforeHashes, $this->hashAllTables());
        Http::assertNothingSent();
    }

    // =========================================================================
    // 2. Missing order locally
    // =========================================================================
    public function test_02_missing_order_accounted_in_pending_human_review(): void
    {
        $this->seedBaseCatalog();
        $manifestPath = $this->writeV2Manifest('m02.json', [
            ['id' => 10, 'entity_id' => 999991],
            ['id' => 11, 'entity_id' => 999991],
        ]);

        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $byId = $this->indexEntitiesById($report);

        $this->assertSame(1, $report['invariants']['accounted_orders']);
        $this->assertSame(1, $report['invariants']['missing_orders_accounted']);
        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[999991]['primary_classification']);
        $this->assertContains('order_missing_from_db', $byId[999991]['reason_codes']);
        $this->assertSame([10, 11], $byId[999991]['associated_job_ids']);
        $this->assertSame(2, $byId[999991]['associated_jobs_count']);
        $this->assertSame(2, $report['historical_recovery_security']['jobs_for_missing_orders']);
    }

    // =========================================================================
    // 3. Order without items
    // =========================================================================
    public function test_03_order_without_items_routed_to_pending_human_review(): void
    {
        $this->seedBaseCatalog();
        $this->insertOrder(2001, 1, 'O-2001', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $manifestPath = $this->writeV2Manifest('m03.json', [['id' => 1, 'entity_id' => 2001]]);

        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $entity = $this->indexEntitiesById($report)[2001];

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $entity['primary_classification']);
        $this->assertContains('order_without_items', $entity['reason_codes']);
        $this->assertContains('meli_order_items_empty', $entity['missing_evidence']);
    }

    // =========================================================================
    // 4. Order with invalid dates (NEVER treated as pre-cutover!)
    // =========================================================================
    public function test_04_invalid_dates_routed_to_pending_human_review_not_pre_cutover(): void
    {
        $this->seedBaseCatalog();
        // Impossible calendar date
        $this->insertOrder(3001, 1, 'O-3001', 'paid', 'ready_to_ship', ['date_created' => '2026-02-30T10:00:00-06:00']);
        $this->insertItem(3001, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        // Missing explicit timezone
        $this->insertOrder(3002, 1, 'O-3002', 'paid', 'ready_to_ship', ['date_created' => '2026-08-01 10:00:00']);
        $this->insertItem(3002, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        // Garbage date string
        $this->insertOrder(3003, 1, 'O-3003', 'paid', 'ready_to_ship', ['date_created' => 'not-a-date']);
        $this->insertItem(3003, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        // Missing both raw.date_created and created_at
        DB::table('meli_orders')->insert([
            'id' => 3004, 'meli_account_id' => 1, 'order_id' => 'O-3004', 'status' => 'paid',
            'shipping_status' => 'ready_to_ship', 'raw' => json_encode(['status' => 'paid']),
            'created_at' => null, 'updated_at' => null,
        ]);
        $this->insertItem(3004, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);

        $manifestPath = $this->writeV2Manifest('m04.json', [
            ['id' => 1, 'entity_id' => 3001],
            ['id' => 2, 'entity_id' => 3002],
            ['id' => 3, 'entity_id' => 3003],
            ['id' => 4, 'entity_id' => 3004],
        ]);

        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $byId = $this->indexEntitiesById($report);

        $this->assertSame(0, $report['exhaustive_classification']['primary_partition_counts'][QueueOrderExhaustiveClassifier::PRIMARY_OUT_OF_SCOPE_PRE_CUTOVER_OR_IGNORED]);
        foreach ([3001, 3002, 3003, 3004] as $oid) {
            $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[$oid]['primary_classification']);
            $this->assertContains('invalid_order_date', $byId[$oid]['reason_codes']);
        }
    }

    // =========================================================================
    // 5. Cancelled order with active reservation (+ cancelled after fulfillment)
    // =========================================================================
    public function test_05_cancelled_order_with_active_reservation_and_cancelled_after_fulfillment(): void
    {
        $this->seedBaseCatalog();
        // Clean cancelled + active reservation -> reconstructible_candidates
        $this->insertOrder(4001, 1, 'O-4001', 'cancelled', 'cancelled', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(4001, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        $r1 = $this->insertReservation(4001, 10, 1, 'ACTIVE');
        $this->insertAllocation(1, 'O-4001', 'MLM-ACTIVE:', 10, 'SIMPLE', $r1, 1, 'ACTIVE');

        // Cancelled AFTER fulfillment -> pending_human_review (physical return check needed!)
        $this->insertOrder(4002, 1, 'O-4002', 'cancelled', 'delivered', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(4002, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        $this->insertAllocation(1, 'O-4002', 'MLM-ACTIVE:', 10, 'SIMPLE', null, 0, 'FULFILLED');

        $manifestPath = $this->writeV2Manifest('m05.json', [
            ['id' => 1, 'entity_id' => 4001],
            ['id' => 2, 'entity_id' => 4002],
        ]);

        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $byId = $this->indexEntitiesById($report);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_RECONSTRUCTIBLE_CANDIDATES, $byId[4001]['primary_classification']);
        $this->assertContains('cancelled_with_active_reservation', $byId[4001]['reason_codes']);
        $this->assertSame('release_active_reservation_for_cancelled_order', $byId[4001]['operational_recommendation']['candidate_action']);
        $this->assertFalse($byId[4001]['operational_recommendation']['automatic_execution_allowed']);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[4002]['primary_classification']);
        $this->assertContains('cancelled_after_fulfillment', $byId[4002]['reason_codes']);
    }

    // =========================================================================
    // 6. Shipped order with active reservation
    // =========================================================================
    public function test_06_shipped_order_with_active_reservation(): void
    {
        $this->seedBaseCatalog();
        $this->insertOrder(5001, 1, 'O-5001', 'paid', 'shipped', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(5001, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        $r1 = $this->insertReservation(5001, 10, 1, 'ACTIVE');
        $this->insertAllocation(1, 'O-5001', 'MLM-ACTIVE:', 10, 'SIMPLE', $r1, 1, 'ACTIVE');

        $manifestPath = $this->writeV2Manifest('m06.json', [['id' => 1, 'entity_id' => 5001]]);
        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $entity = $this->indexEntitiesById($report)[5001];

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_RECONSTRUCTIBLE_CANDIDATES, $entity['primary_classification']);
        $this->assertContains('shipped_with_active_reservation', $entity['reason_codes']);
        $this->assertSame('fulfill_active_reservation_for_shipped_order', $entity['operational_recommendation']['candidate_action']);
    }

    // =========================================================================
    // 7. Shipped during outage without prior reservation
    // =========================================================================
    public function test_07_shipped_without_prior_reservation_routed_to_pending_human_review(): void
    {
        $this->seedBaseCatalog();
        $this->insertOrder(6001, 1, 'O-6001', 'paid', 'delivered', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(6001, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);

        $manifestPath = $this->writeV2Manifest('m07.json', [['id' => 1, 'entity_id' => 6001]]);
        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $entity = $this->indexEntitiesById($report)[6001];

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $entity['primary_classification']);
        $this->assertContains('shipped_during_outage_never_reserved', $entity['reason_codes']);
        $this->assertSame(1, $report['counts']['shipped_during_outage_never_reserved']);
    }

    // =========================================================================
    // 8. Paid with exact active reservation, shipped fulfilled, cancelled clean
    // =========================================================================
    public function test_08_positively_verified_consistent_orders_paid_shipped_and_cancelled(): void
    {
        $this->seedBaseCatalog();

        // Paid + exact ACTIVE allocation + ACTIVE reservation
        $this->insertOrder(7001, 1, 'O-7001', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(7001, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 3);
        $r1 = $this->insertReservation(7001, 10, 3, 'ACTIVE');
        $this->insertAllocation(1, 'O-7001', 'MLM-ACTIVE:', 10, 'SIMPLE', $r1, 3, 'ACTIVE');

        // Shipped + verified FULFILLED allocation
        $this->insertOrder(7002, 1, 'O-7002', 'paid', 'delivered', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(7002, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        $this->insertAllocation(1, 'O-7002', 'MLM-ACTIVE:', 10, 'SIMPLE', null, 0, 'FULFILLED');

        // Cancelled + RELEASED allocation, 0 active reservations
        $this->insertOrder(7003, 1, 'O-7003', 'cancelled', 'cancelled', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(7003, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        $this->insertAllocation(1, 'O-7003', 'MLM-ACTIVE:', 10, 'SIMPLE', null, 0, 'RELEASED');

        $manifestPath = $this->writeV2Manifest('m08.json', [
            ['id' => 1, 'entity_id' => 7001],
            ['id' => 2, 'entity_id' => 7002],
            ['id' => 3, 'entity_id' => 7003],
        ]);
        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $byId = $this->indexEntitiesById($report);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_CONSISTENT_NO_ACTION_NEEDED, $byId[7001]['primary_classification']);
        $this->assertContains('paid_consistent_active_reservation', $byId[7001]['reason_codes']);
        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_CONSISTENT_NO_ACTION_NEEDED, $byId[7002]['primary_classification']);
        $this->assertContains('shipped_consistent_fulfilled', $byId[7002]['reason_codes']);
        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_CONSISTENT_NO_ACTION_NEEDED, $byId[7003]['primary_classification']);
        $this->assertContains('cancelled_consistent_no_obligations', $byId[7003]['reason_codes']);
    }

    // =========================================================================
    // 9. Paid order with quantity inconsistency
    // =========================================================================
    public function test_09_paid_order_with_quantity_inconsistency_routed_to_pending_human_review(): void
    {
        $this->seedBaseCatalog();
        $this->insertOrder(8001, 1, 'O-8001', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(8001, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 4);
        $r1 = $this->insertReservation(8001, 10, 2, 'ACTIVE');
        $this->insertAllocation(1, 'O-8001', 'MLM-ACTIVE:', 10, 'SIMPLE', $r1, 2, 'ACTIVE');

        $manifestPath = $this->writeV2Manifest('m09.json', [['id' => 1, 'entity_id' => 8001]]);
        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $entity = $this->indexEntitiesById($report)[8001];

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $entity['primary_classification']);
        $this->assertContains('quantity_inconsistency', $entity['reason_codes']);
    }

    // =========================================================================
    // 10. Order with DIAGNOSTIC allocation
    // =========================================================================
    public function test_10_diagnostic_allocation_routed_to_pending_human_review(): void
    {
        $this->seedBaseCatalog();
        $this->insertOrder(9001, 1, 'O-9001', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(9001, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 5);
        $this->insertAllocation(1, 'O-9001', 'MLM-ACTIVE:', 10, null, null, 0, 'DIAGNOSTIC', 'INSUFFICIENT_INVENTORY');

        $manifestPath = $this->writeV2Manifest('m10.json', [['id' => 1, 'entity_id' => 9001]]);
        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $entity = $this->indexEntitiesById($report)[9001];

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $entity['primary_classification']);
        $this->assertContains('diagnostic_allocation_present', $entity['reason_codes']);
        $this->assertContains('diagnostic_code:insufficient_inventory', $entity['secondary_anomalies']);
    }

    // =========================================================================
    // 11. STALE_ALLOCATION
    // =========================================================================
    public function test_11_stale_allocation_routed_to_pending_human_review(): void
    {
        $this->seedBaseCatalog();
        // Allocation says ACTIVE, but underlying reservation is RELEASED!
        $this->insertOrder(10001, 1, 'O-10001', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(10001, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        $rReleased = $this->insertReservation(10001, 10, 1, 'RELEASED');
        $this->insertAllocation(1, 'O-10001', 'MLM-ACTIVE:', 10, 'SIMPLE', $rReleased, 1, 'ACTIVE');

        // Allocation says ACTIVE, but reservation_id points to non-existent reservation!
        $this->insertOrder(10002, 1, 'O-10002', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(10002, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        $this->insertAllocation(1, 'O-10002', 'MLM-ACTIVE:', 10, 'SIMPLE', 999999, 1, 'ACTIVE');

        $manifestPath = $this->writeV2Manifest('m11.json', [
            ['id' => 1, 'entity_id' => 10001],
            ['id' => 2, 'entity_id' => 10002],
        ]);
        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $byId = $this->indexEntitiesById($report);

        foreach ([10001, 10002] as $oid) {
            $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[$oid]['primary_classification']);
            $this->assertContains('stale_allocation', $byId[$oid]['reason_codes']);
            $this->assertStringContainsString('STALE', $byId[$oid]['allocation_status']);
        }
    }

    // =========================================================================
    // 12. Orders with manual movements (direct/blocking vs generic product adjustment)
    // =========================================================================
    public function test_12_manual_movements_blocking_and_ambiguous_detected_via_occurred_at(): void
    {
        $this->seedBaseCatalog();

        // Blocking ADJUSTMENT_OUT after order creation -> pending_human_review
        $this->insertOrder(11001, 1, 'O-11001', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(11001, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        DB::table('inventory_movements')->insert([
            'inventory_product_id' => 10, 'type' => 'ADJUSTMENT_OUT', 'quantity' => -1,
            'reference_type' => 'manual', 'occurred_at' => '2026-11-11 10:00:00', 'created_at' => '2026-11-11 10:00:00',
        ]);

        // Generic adjustment on another product -> ambiguous_entities
        DB::table('inventory_products')->insert(['id' => 19, 'sku' => 'SKU-MAN2', 'name' => 'Man2', 'product_type' => 'simple', 'is_active' => 1]);
        DB::table('inventory_channel_links')->insert([
            'id' => 19, 'channel' => 'mercado_libre', 'account_key' => '1', 'external_listing_id' => 'MLM-MAN2',
            'external_variant_id' => null, 'inventory_product_id' => 19, 'is_active' => 1, 'order_reservation_enabled' => 1, 'stock_sync_enabled' => 1,
        ]);
        $this->insertOrder(11002, 1, 'O-11002', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(11002, 'MLM-MAN2', 'MLM-MAN2:', 'SKU-MAN2', 1);
        DB::table('inventory_movements')->insert([
            'inventory_product_id' => 19, 'type' => 'adjustment', 'quantity' => -1,
            'reference_type' => 'manual', 'occurred_at' => '2026-11-12 09:00:00', 'created_at' => '2026-10-01 09:00:00',
        ]);

        $manifestPath = $this->writeV2Manifest('m12.json', [
            ['id' => 1, 'entity_id' => 11001],
            ['id' => 2, 'entity_id' => 11002],
        ]);
        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $byId = $this->indexEntitiesById($report);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[11001]['primary_classification']);
        $this->assertContains('manual_movement_blocking_human_review', $byId[11001]['reason_codes']);
        $this->assertContains('orders_with_manual_movements', $byId[11001]['secondary_anomalies']);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_AMBIGUOUS_ENTITIES, $byId[11002]['primary_classification']);
        $this->assertContains('manual_movements_detected', $byId[11002]['reason_codes']);
        $this->assertContains('orders_with_manual_movements', $byId[11002]['secondary_anomalies']);
    }

    // =========================================================================
    // 13. Kits and components
    // =========================================================================
    public function test_13_kits_and_components_classified_as_ambiguous(): void
    {
        $this->seedBaseCatalog();
        // Kit product
        $this->insertOrder(12001, 1, 'O-12001', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(12001, 'MLM-KIT', 'MLM-KIT:', 'SKU-KIT', 1);
        // Component product of a kit
        $this->insertOrder(12002, 1, 'O-12002', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(12002, 'MLM-COMP', 'MLM-COMP:', 'SKU-COMP', 1);

        $manifestPath = $this->writeV2Manifest('m13.json', [
            ['id' => 1, 'entity_id' => 12001],
            ['id' => 2, 'entity_id' => 12002],
        ]);
        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $byId = $this->indexEntitiesById($report);

        foreach ([12001, 12002] as $oid) {
            $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_AMBIGUOUS_ENTITIES, $byId[$oid]['primary_classification']);
            $this->assertContains('kit_or_component_involved', $byId[$oid]['reason_codes']);
            $this->assertContains('kits_and_components', $byId[$oid]['secondary_anomalies']);
        }
    }

    // =========================================================================
    // 14. Multiline order + shared reservation across lines
    // =========================================================================
    public function test_14_multiline_order_and_shared_reservation_across_lines(): void
    {
        $this->seedBaseCatalog();
        $this->insertOrder(13001, 1, 'O-13001', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(13001, 'MLM-ACTIVE', 'MLM-ACTIVE:L1', 'SKU-ACTIVE', 1);
        $this->insertItem(13001, 'MLM-ACTIVE', 'MLM-ACTIVE:L2', 'SKU-ACTIVE', 1);
        $sharedRes = $this->insertReservation(13001, 10, 1, 'ACTIVE');
        $this->insertAllocation(1, 'O-13001', 'MLM-ACTIVE:L1', 10, 'SIMPLE', $sharedRes, 1, 'ACTIVE');
        $this->insertAllocation(1, 'O-13001', 'MLM-ACTIVE:L2', 10, 'SIMPLE', $sharedRes, 1, 'ACTIVE');

        $manifestPath = $this->writeV2Manifest('m14.json', [['id' => 1, 'entity_id' => 13001]]);
        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $entity = $this->indexEntitiesById($report)[13001];

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_AMBIGUOUS_ENTITIES, $entity['primary_classification']);
        $this->assertContains('multiline_order', $entity['reason_codes']);
        $this->assertContains('shared_reservation_across_lines', $entity['reason_codes']);
    }

    // =========================================================================
    // 15. Missing remote_line_key
    // =========================================================================
    public function test_15_missing_remote_line_key_detected(): void
    {
        $this->seedBaseCatalog();
        $this->insertOrder(14001, 1, 'O-14001', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(14001, 'MLM-ACTIVE', null, 'SKU-ACTIVE', 1);

        $manifestPath = $this->writeV2Manifest('m15.json', [['id' => 1, 'entity_id' => 14001]]);
        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $entity = $this->indexEntitiesById($report)[14001];

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_AMBIGUOUS_ENTITIES, $entity['primary_classification']);
        $this->assertContains('missing_remote_line_key', $entity['reason_codes']);
    }

    // =========================================================================
    // 16. Anomalies WITHOUT enabled channel links + distinct no-link sub-reasons
    // =========================================================================
    public function test_16_anomalies_without_enabled_links_are_not_hidden_and_clean_no_link_subreasons(): void
    {
        $this->seedBaseCatalog();

        // Unlinked order with missing remote_line_key -> MUST be ambiguous_entities, NOT out_of_scope_no_reservation_link!
        $this->insertOrder(15001, 1, 'O-15001', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(15001, 'MLM-UNMATCHED', null, 'SKU-NONE', 1);

        // Disabled-link order that STILL has an active reservation -> MUST be pending_human_review!
        $this->insertOrder(15002, 1, 'O-15002', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(15002, 'MLM-DISABLED', 'MLM-DISABLED:', 'SKU-ACTIVE', 1);
        $this->insertReservation(15002, 10, 1, 'ACTIVE');

        // Clean no-link sub-reasons (all 0 active reservations, 0 anomalies) -> out_of_scope_no_reservation_link
        $this->insertOrder(15003, 1, 'O-15003', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(15003, 'MLM-UNMATCHED', 'MLM-UNMATCHED:', 'SKU-NONE', 1);

        $this->insertOrder(15004, 1, 'O-15004', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(15004, 'MLM-INACTIVE', 'MLM-INACTIVE:', 'SKU-ACTIVE', 1);

        $this->insertOrder(15005, 1, 'O-15005', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(15005, 'MLM-DISABLED', 'MLM-DISABLED:', 'SKU-ACTIVE', 1);

        $this->insertOrder(15006, 1, 'O-15006', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(15006, 'MLM-INACTIVE-PROD', 'MLM-INACTIVE-PROD:', 'SKU-INACT', 1);

        $manifestPath = $this->writeV2Manifest('m16.json', [
            ['id' => 1, 'entity_id' => 15001],
            ['id' => 2, 'entity_id' => 15002],
            ['id' => 3, 'entity_id' => 15003],
            ['id' => 4, 'entity_id' => 15004],
            ['id' => 5, 'entity_id' => 15005],
            ['id' => 6, 'entity_id' => 15006],
        ]);
        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $byId = $this->indexEntitiesById($report);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_AMBIGUOUS_ENTITIES, $byId[15001]['primary_classification']);
        $this->assertContains('missing_remote_line_key', $byId[15001]['reason_codes']);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[15002]['primary_classification']);
        $this->assertContains('active_reservation_without_valid_link', $byId[15002]['reason_codes']);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_OUT_OF_SCOPE_NO_LINK, $byId[15003]['primary_classification']);
        $this->assertContains('unmatched_no_link', $byId[15003]['reason_codes']);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_OUT_OF_SCOPE_NO_LINK, $byId[15004]['primary_classification']);
        $this->assertContains('inactive_link', $byId[15004]['reason_codes']);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_OUT_OF_SCOPE_NO_LINK, $byId[15005]['primary_classification']);
        $this->assertContains('order_reservation_disabled', $byId[15005]['reason_codes']);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_OUT_OF_SCOPE_NO_LINK, $byId[15006]['primary_classification']);
        $this->assertContains('inactive_or_missing_product', $byId[15006]['reason_codes']);
    }

    // =========================================================================
    // 17. Cross-account conflict & REMOTE_USER_PRODUCT_CONFLICT
    // =========================================================================
    public function test_17_account_conflicts_and_remote_user_product_conflict(): void
    {
        $this->seedBaseCatalog();
        DB::table('meli_accounts')->insert(['id' => 2, 'nickname' => 'ACC-2']);

        // Invalid non-existent meli_account_id -> pending_human_review
        $this->insertOrder(16001, 999, 'O-16001', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(16001, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);

        // Cross-account link conflict (order on account 2, listing MLM-ACTIVE actively linked on account 1!)
        $this->insertOrder(16002, 2, 'O-16002', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(16002, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);

        // Cross-account allocation conflict
        $this->insertOrder(16003, 2, 'O-16003', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(16003, 'MLM-UNMATCHED', 'MLM-UNMATCHED:', 'SKU-NONE', 1);
        $this->insertAllocation(1, 'O-16003', 'MLM-UNMATCHED:', 10, 'SIMPLE', null, 0, 'RELEASED');

        // REMOTE_USER_PRODUCT_CONFLICT (two active stock-sync links share remote_user_product_id with different products)
        DB::table('inventory_products')->insert(['id' => 25, 'sku' => 'SKU-UP2', 'name' => 'UP2', 'product_type' => 'simple', 'is_active' => 1]);
        DB::table('inventory_channel_links')->insert([
            ['id' => 30, 'channel' => 'mercado_libre', 'account_key' => '1', 'external_listing_id' => 'MLM-UP-A', 'external_variant_id' => null, 'inventory_product_id' => 10, 'is_active' => 1, 'order_reservation_enabled' => 1, 'stock_sync_enabled' => 1, 'remote_user_product_id' => 'MLMU-CONFLICT'],
            ['id' => 31, 'channel' => 'mercado_libre', 'account_key' => '1', 'external_listing_id' => 'MLM-UP-B', 'external_variant_id' => null, 'inventory_product_id' => 25, 'is_active' => 1, 'order_reservation_enabled' => 1, 'stock_sync_enabled' => 1, 'remote_user_product_id' => 'MLMU-CONFLICT'],
        ]);
        $this->insertOrder(16004, 1, 'O-16004', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(16004, 'MLM-UP-A', 'MLM-UP-A:', 'SKU-ACTIVE', 1);

        $manifestPath = $this->writeV2Manifest('m17.json', [
            ['id' => 1, 'entity_id' => 16001],
            ['id' => 2, 'entity_id' => 16002],
            ['id' => 3, 'entity_id' => 16003],
            ['id' => 4, 'entity_id' => 16004],
        ]);
        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $byId = $this->indexEntitiesById($report);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[16001]['primary_classification']);
        $this->assertContains('account_mismatch', $byId[16001]['reason_codes']);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_AMBIGUOUS_ENTITIES, $byId[16002]['primary_classification']);
        $this->assertContains('cross_account_link_conflict', $byId[16002]['reason_codes']);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_AMBIGUOUS_ENTITIES, $byId[16003]['primary_classification']);
        $this->assertContains('cross_account_allocation_conflict', $byId[16003]['reason_codes']);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_AMBIGUOUS_ENTITIES, $byId[16004]['primary_classification']);
        $this->assertContains('remote_user_product_conflict', $byId[16004]['reason_codes']);
    }

    // =========================================================================
    // 18. Shipping inconsistencies (shipping_id vs shipping_raw, raw status mismatch)
    // =========================================================================
    public function test_18_shipping_id_and_status_inconsistencies_routed_to_pending_human_review(): void
    {
        $this->seedBaseCatalog();

        // shipping_id != shipping_raw.id
        $this->insertOrder(
            17001,
            1,
            'O-17001',
            'paid',
            'ready_to_ship',
            ['date_created' => '2026-11-10T10:00:00-06:00', 'status' => 'paid'],
            shippingId: 'SHIP-111',
            shippingRaw: ['id' => 'SHIP-222', 'status' => 'ready_to_ship'],
        );
        $this->insertItem(17001, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);

        // shipping_raw.status != materialized shipping_status
        $this->insertOrder(
            17002,
            1,
            'O-17002',
            'paid',
            'ready_to_ship',
            ['date_created' => '2026-11-10T10:00:00-06:00', 'status' => 'paid'],
            shippingId: 'SHIP-333',
            shippingRaw: ['id' => 'SHIP-333', 'status' => 'shipped'],
        );
        $this->insertItem(17002, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);

        // raw.status != materialized status
        $this->insertOrder(
            17003,
            1,
            'O-17003',
            'paid',
            'ready_to_ship',
            ['date_created' => '2026-11-10T10:00:00-06:00', 'status' => 'cancelled'],
        );
        $this->insertItem(17003, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);

        $manifestPath = $this->writeV2Manifest('m18.json', [
            ['id' => 1, 'entity_id' => 17001],
            ['id' => 2, 'entity_id' => 17002],
            ['id' => 3, 'entity_id' => 17003],
        ]);
        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $byId = $this->indexEntitiesById($report);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[17001]['primary_classification']);
        $this->assertContains('shipping_id_mismatch', $byId[17001]['reason_codes']);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[17002]['primary_classification']);
        $this->assertContains('raw_shipping_status_mismatch', $byId[17002]['reason_codes']);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[17003]['primary_classification']);
        $this->assertContains('raw_status_mismatch', $byId[17003]['reason_codes']);
    }

    // =========================================================================
    // 19. Partial payload pending materialization
    // =========================================================================
    public function test_19_partial_payload_pending_materialization_and_raw_items_diff(): void
    {
        $this->seedBaseCatalog();

        // _partial_status_last_updated > last_updated
        $this->insertOrder(18001, 1, 'O-18001', 'paid', 'ready_to_ship', [
            'date_created' => '2026-11-10T10:00:00-06:00',
            'last_updated' => '2026-11-10T10:05:00-06:00',
            '_partial_status_last_updated' => '2026-11-10T10:15:00-06:00',
            'order_items' => [['item' => ['id' => 'MLM-ACTIVE'], 'quantity' => 1]],
        ]);
        $this->insertItem(18001, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);

        // raw.order_items quantity (4) disagrees with materialized meli_order_items quantity (2)
        $this->insertOrder(18002, 1, 'O-18002', 'paid', 'ready_to_ship', [
            'date_created' => '2026-11-10T10:00:00-06:00',
            'last_updated' => '2026-11-10T10:20:00-06:00',
            'order_items' => [['item' => ['id' => 'MLM-ACTIVE'], 'quantity' => 4]],
        ]);
        $this->insertItem(18002, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 2);

        $manifestPath = $this->writeV2Manifest('m19.json', [
            ['id' => 1, 'entity_id' => 18001],
            ['id' => 2, 'entity_id' => 18002],
        ]);
        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $byId = $this->indexEntitiesById($report);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[18001]['primary_classification']);
        $this->assertContains('partial_payload_pending_materialization', $byId[18001]['reason_codes']);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[18002]['primary_classification']);
        $this->assertContains('raw_items_materialization_mismatch', $byId[18002]['reason_codes']);
    }

    // =========================================================================
    // 20. R3 durable state (pending revision, expired lease, FAILED, stock_sync_required)
    // =========================================================================
    public function test_20_r3_durable_state_blocks_consistency_and_surfaces_anomalies(): void
    {
        $this->seedBaseCatalog();

        // Order 19001: locally consistent active reservation, YET R3 has PENDING revision 3 > processed 2!
        $this->insertOrder(19001, 1, 'O-19001', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(19001, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        $r1 = $this->insertReservation(19001, 10, 1, 'ACTIVE');
        $this->insertAllocation(1, 'O-19001', 'MLM-ACTIVE:', 10, 'SIMPLE', $r1, 1, 'ACTIVE');
        DB::table('inventory_meli_order_reconciliations')->insert([
            'meli_order_id' => 19001, 'meli_account_id' => 1, 'remote_order_id' => 'O-19001',
            'state' => 'PENDING', 'requested_revision' => 3, 'processed_revision' => 2,
            'last_enqueued_revision' => 3, 'fencing_token' => 2, 'pending_job_id' => 888,
            'dispatch_confirmed_at' => now()->utc()->toDateTimeString(),
            'stock_sync_required' => false, 'stock_sync_completed_revision' => 2,
        ]);

        // Order 19002: PROCESSING with expired lease
        $this->insertOrder(19002, 1, 'O-19002', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(19002, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        DB::table('inventory_meli_order_reconciliations')->insert([
            'meli_order_id' => 19002, 'meli_account_id' => 1, 'remote_order_id' => 'O-19002',
            'state' => 'PROCESSING', 'requested_revision' => 2, 'processed_revision' => 1,
            'fencing_token' => 5, 'lease_expires_at' => now()->utc()->subMinutes(10)->toDateTimeString(),
        ]);

        // Order 19003: FAILED state
        $this->insertOrder(19003, 1, 'O-19003', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(19003, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        DB::table('inventory_meli_order_reconciliations')->insert([
            'meli_order_id' => 19003, 'meli_account_id' => 1, 'remote_order_id' => 'O-19003',
            'state' => 'FAILED', 'requested_revision' => 2, 'processed_revision' => 1,
            'fencing_token' => 3, 'attempts' => 4,
        ]);

        // Order 19004: IDLE & matching active reservation, YET stock_sync_required=true with completed_rev < processed_rev
        $this->insertOrder(19004, 1, 'O-19004', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(19004, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        $r4 = $this->insertReservation(19004, 10, 1, 'ACTIVE');
        $this->insertAllocation(1, 'O-19004', 'MLM-ACTIVE:', 10, 'SIMPLE', $r4, 1, 'ACTIVE');
        DB::table('inventory_meli_order_reconciliations')->insert([
            'meli_order_id' => 19004, 'meli_account_id' => 1, 'remote_order_id' => 'O-19004',
            'state' => 'IDLE', 'requested_revision' => 2, 'processed_revision' => 2,
            'fencing_token' => 2, 'stock_sync_required' => true, 'stock_sync_completed_revision' => 1,
        ]);

        $manifestPath = $this->writeV2Manifest('m20.json', [
            ['id' => 1, 'entity_id' => 19001],
            ['id' => 2, 'entity_id' => 19002],
            ['id' => 3, 'entity_id' => 19003],
            ['id' => 4, 'entity_id' => 19004],
        ]);
        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $byId = $this->indexEntitiesById($report);

        $this->assertSame('r3_durable_enabled', $report['r3_compatibility']['schema_mode']);
        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[19001]['primary_classification']);
        $this->assertContains('r3_pending_revision_unprocessed', $byId[19001]['reason_codes']);
        $this->assertSame(888, $byId[19001]['available_evidence']['r3_reconciliation']['pending_job_id']);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[19002]['primary_classification']);
        $this->assertContains('r3_processing_lease_expired', $byId[19002]['reason_codes']);
        $this->assertTrue($byId[19002]['available_evidence']['r3_reconciliation']['lease_expired']);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[19003]['primary_classification']);
        $this->assertContains('r3_reconciliation_failed', $byId[19003]['reason_codes']);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[19004]['primary_classification']);
        $this->assertContains('r3_stock_sync_required_pending', $byId[19004]['reason_codes']);
    }

    // =========================================================================
    // 21. Pre-R3 schema without `inventory_meli_order_reconciliations`
    // =========================================================================
    public function test_21_pre_r3_schema_without_durable_table_works_and_marks_missing_evidence(): void
    {
        Schema::connection('sqlite')->dropIfExists('inventory_meli_order_reconciliations');
        $this->seedBaseCatalog();

        $this->insertOrder(20001, 1, 'O-20001', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(20001, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        $r1 = $this->insertReservation(20001, 10, 1, 'ACTIVE');
        $this->insertAllocation(1, 'O-20001', 'MLM-ACTIVE:', 10, 'SIMPLE', $r1, 1, 'ACTIVE');

        $manifestPath = $this->writeV2Manifest('m21.json', [['id' => 1, 'entity_id' => 20001]]);
        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $entity = $this->indexEntitiesById($report)[20001];

        $this->assertFalse($report['r3_compatibility']['r3_durable_state_table_present']);
        $this->assertSame('pre_r3_legacy', $report['r3_compatibility']['schema_mode']);
        $this->assertFalse($report['r3_compatibility']['absence_treated_as_reconciled']);
        $this->assertContains('r3_durable_state_table_absent', $entity['missing_evidence']);
        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_CONSISTENT_NO_ACTION_NEEDED, $entity['primary_classification']);
    }

    // =========================================================================
    // 22. Multi-category priority precedence (A > B > C > D > E > F > G)
    // =========================================================================
    public function test_22_multi_category_entities_obey_strict_risk_priority_and_preserve_secondary_anomalies(): void
    {
        $this->seedBaseCatalog();

        // Matches A (stale_allocation) + B (multiline + kit) + C (cancelled_with_active_reservation) -> MUST be A!
        $this->insertOrder(21001, 1, 'O-21001', 'cancelled', 'cancelled', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(21001, 'MLM-KIT', 'MLM-KIT:', 'SKU-KIT', 1);
        $this->insertItem(21001, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);
        $this->insertAllocation(1, 'O-21001', 'MLM-ACTIVE:', 10, 'SIMPLE', 999998, 1, 'ACTIVE');

        // Matches B (kit) + C (shipped_with_active_reservation) -> MUST be B!
        $this->insertOrder(21002, 1, 'O-21002', 'paid', 'shipped', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(21002, 'MLM-KIT', 'MLM-KIT:', 'SKU-KIT', 1);
        $kResId = DB::table('inventory_kit_reservations')->insertGetId([
            'source_type' => 'meli_order', 'source_id' => 21002, 'kit_product_id' => 11, 'quantity' => 1, 'status' => 'ACTIVE',
        ]);
        $this->insertAllocation(1, 'O-21002', 'MLM-KIT:', 11, 'KIT', $kResId, 1, 'ACTIVE');

        // Ignored status cleanly -> F
        $this->insertOrder(21003, 1, 'O-21003', 'payment_required', null, ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(21003, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);

        $manifestPath = $this->writeV2Manifest('m22.json', [
            ['id' => 1, 'entity_id' => 21001],
            ['id' => 2, 'entity_id' => 21002],
            ['id' => 3, 'entity_id' => 21003],
        ]);
        $report = $this->runDiagnose(['manifest_path' => $manifestPath]);
        $byId = $this->indexEntitiesById($report);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $byId[21001]['primary_classification']);
        $this->assertContains('stale_allocation', $byId[21001]['secondary_anomalies']);
        $this->assertContains('multiline_orders', $byId[21001]['secondary_anomalies']);
        $this->assertContains('kits_and_components', $byId[21001]['secondary_anomalies']);
        $this->assertContains('cancelled_orders_with_active_reservations', $byId[21001]['secondary_anomalies']);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_AMBIGUOUS_ENTITIES, $byId[21002]['primary_classification']);
        $this->assertContains('kits_and_components', $byId[21002]['secondary_anomalies']);
        $this->assertContains('shipped_orders_with_active_reservations', $byId[21002]['secondary_anomalies']);

        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_OUT_OF_SCOPE_PRE_CUTOVER_OR_IGNORED, $byId[21003]['primary_classification']);
        $this->assertContains('ignored_order_status', $byId[21003]['reason_codes']);
    }

    // =========================================================================
    // 23. Backwards-compatible manifests (legacy R2 v1, R2.1 v2 with HMAC, NDJSON)
    // =========================================================================
    public function test_23_legacy_v1_hmac_v2_and_ndjson_manifests_all_supported(): void
    {
        $this->seedBaseCatalog();
        $this->insertOrder(22001, 1, 'O-22001', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(22001, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);

        // 1) Legacy R2 v1 JSON manifest (no authorized_upper_bound / no row_sha256 / schema_version=1)
        $legacyPath = $this->tmp('legacy_v1.json');
        file_put_contents($legacyPath, json_encode([
            'schema_version' => 1,
            'incident_id' => 'INC-LEGACY-1',
            'allowed_jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'end_id' => 100,
            'entries' => [
                ['id' => 50, 'job_class' => QueuePayloadInspector::JOB_RESERVATIONS, 'entity_type' => 'meli_order', 'entity_id' => 22001],
            ],
        ], JSON_THROW_ON_ERROR));

        $repV1 = $this->runDiagnose(['manifest_path' => $legacyPath]);
        $this->assertSame('json_legacy_v1', $repV1['manifest_scope']['format']);
        $this->assertSame(1, $repV1['invariants']['accounted_orders']);

        // 2) R2.1 v2 JSON manifest with HMAC verification
        $hmacSecret = 'test-hmac-secret-r2-diag';
        $v2Path = $this->writeV2Manifest('v2_hmac.json', [['id' => 51, 'entity_id' => 22001]], $hmacSecret);
        $repV2 = $this->runDiagnose(['manifest_path' => $v2Path, 'hmac_key' => $hmacSecret]);
        $this->assertSame('json_r2_v2', $repV2['manifest_scope']['format']);
        $this->assertTrue($repV2['manifest_scope']['hmac_verified']);
        $this->assertSame(1, $repV2['invariants']['accounted_orders']);

        // 3) Streaming NDJSON manifest
        $ndjsonManifest = $this->tmp('manifest.ndjson');
        file_put_contents($ndjsonManifest, implode("\n", [
            json_encode(['record_type' => 'manifest_header', 'schema_version' => 2, 'incident_id' => 'INC-NDJSON-1', 'allowed_jobs' => [QueuePayloadInspector::JOB_RESERVATIONS]]),
            json_encode(['id' => 52, 'job_class' => QueuePayloadInspector::JOB_RESERVATIONS, 'entity_type' => 'meli_order', 'entity_id' => 22001, 'reserved_at' => null]),
        ])."\n");
        $repNd = $this->runDiagnose(['manifest_path' => $ndjsonManifest]);
        $this->assertSame('ndjson_streaming_v2', $repNd['manifest_scope']['format']);
        $this->assertSame(1, $repNd['invariants']['accounted_orders']);
    }

    // =========================================================================
    // 24. Invalid, reserved, and duplicate manifest job IDs reported separately
    // =========================================================================
    public function test_24_invalid_reserved_and_duplicate_job_ids_reported_separately(): void
    {
        $this->seedBaseCatalog();
        $this->insertOrder(23001, 1, 'O-23001', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(23001, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);

        $ndjsonManifest = $this->tmp('mixed_invalid_reserved.ndjson');
        file_put_contents($ndjsonManifest, implode("\n", [
            json_encode([
                'record_type' => 'manifest_header',
                'schema_version' => 2,
                'incident_id' => 'INC-MIXED',
                'allowed_jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
                'totals' => ['preserved_reserved' => 2, 'preserved_invalid_json' => 1, 'preserved_inconsistent' => 1, 'preserved_other_modules' => 5],
            ]),
            // Valid job for order 23001
            json_encode(['id' => 101, 'job_class' => QueuePayloadInspector::JOB_RESERVATIONS, 'entity_type' => 'meli_order', 'entity_id' => 23001, 'reserved_at' => null]),
            // Duplicate job_id 101 (must NOT produce duplicate primary classification!)
            json_encode(['id' => 101, 'job_class' => QueuePayloadInspector::JOB_RESERVATIONS, 'entity_type' => 'meli_order', 'entity_id' => 23001, 'reserved_at' => null]),
            // Reserved entry in manifest for order 23001
            json_encode(['id' => 102, 'job_class' => QueuePayloadInspector::JOB_RESERVATIONS, 'entity_type' => 'meli_order', 'entity_id' => 23001, 'reserved_at' => 1732550000]),
            // Invalid payload entry (no verifiable entity_id)
            json_encode(['id' => 103, 'job_class' => QueuePayloadInspector::JOB_RESERVATIONS, 'entity_type' => 'meli_order', 'entity_id' => null, 'reserved_at' => null]),
            // Explicit invalid classification entry
            json_encode(['id' => 104, 'classification' => QueuePayloadInspector::CLASSIFICATION_INVALID_JSON, 'reason' => 'corrupt_json']),
        ])."\n");

        $report = $this->runDiagnose(['manifest_path' => $ndjsonManifest]);
        $ja = $report['manifest_job_accounting'];
        $sec = $report['historical_recovery_security'];

        $this->assertTrue($report['invariants']['all_passed']);
        $this->assertSame(1, $report['invariants']['accounted_orders']);
        $this->assertSame(0, $report['invariants']['duplicate_primary_classifications']);
        $this->assertSame(5, $ja['total_manifest_entries']);
        $this->assertSame(1, $ja['reservation_job_entries']);
        $this->assertSame(1, $ja['duplicate_manifest_job_id_entries']);
        $this->assertSame(1, $ja['reserved_job_entries']);
        $this->assertSame(2, $ja['invalid_or_unverifiable_job_entries']);
        $this->assertSame(3, $sec['reserved_jobs_count']); // 1 in entries + 2 declared
        $this->assertSame(4, $sec['invalid_payload_jobs_count']); // 2 in entries + 1 invalid_json + 1 inconsistent
        $this->assertSame(5, $sec['other_module_jobs_preserved']);

        // Because order 23001 had a reserved job, it is flagged for pending_human_review!
        $entity = $this->indexEntitiesById($report)[23001];
        $this->assertSame(QueueOrderExhaustiveClassifier::PRIMARY_PENDING_HUMAN_REVIEW, $entity['primary_classification']);
        $this->assertContains('reserved_jobs_detected_for_order', $entity['reason_codes']);
    }

    // =========================================================================
    // 25. Explicit fail-closed invariant violation test
    // =========================================================================
    public function test_25_invariant_violation_fails_closed_with_error_exit_code_and_no_partial_artifacts(): void
    {
        $this->seedBaseCatalog();
        $this->insertOrder(24001, 1, 'O-24001', 'paid', 'ready_to_ship', ['date_created' => '2026-11-10T10:00:00-06:00']);
        $this->insertItem(24001, 'MLM-ACTIVE', 'MLM-ACTIVE:', 'SKU-ACTIVE', 1);

        $manifestPath = $this->writeV2Manifest('m25.json', [['id' => 1, 'entity_id' => 24001]]);
        $ndjsonPath = $this->tmp('should_not_exist.ndjson');
        $summaryPath = $this->tmp('should_not_exist.json');

        /** @var QueueReconstructionDiagnosticsService $service */
        $service = $this->app->make(QueueReconstructionDiagnosticsService::class);
        $service->setInvariantCheckMutator(static function (array $inv): array {
            $inv['unclassified_orders'] = 1;
            $inv['duplicate_primary_classifications'] = 1;

            return $inv;
        });
        $this->app->instance(QueueReconstructionDiagnosticsService::class, $service);

        try {
            $service->diagnose(['manifest_path' => $manifestPath, 'ndjson_output_path' => $ndjsonPath]);
            $this->fail('Expected QueueRecoveryException on invariant violation.');
        } catch (QueueRecoveryException $e) {
            $this->assertSame('diagnostic_invariant_violation', $e->reasonCode);
        }

        $code = Artisan::call('ops:queues-diagnose-reconstruction', [
            '--manifest' => $manifestPath,
            '--ndjson-output' => $ndjsonPath,
            '--output' => $summaryPath,
        ]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('diagnostic_invariant_violation', Artisan::output());
        $this->assertFileDoesNotExist($ndjsonPath);
        $this->assertFileDoesNotExist($summaryPath);
    }

    // =========================================================================
    // 26. Pilot-equivalent synthetic manifest: 1,000 jobs / 736 unique orders
    // Exact repetition distribution: 645x1, 33x2, 2x3, 53x5, 3x6
    // =========================================================================
    public function test_26_pilot_equivalent_synthetic_1000_jobs_736_unique_orders(): void
    {
        $this->seedBaseCatalog();

        // Build exact pilot repetition profile:
        // 645*1 + 33*2 + 2*3 + 53*5 + 3*6 = 645 + 66 + 6 + 265 + 18 = 1,000 jobs across 736 unique orders!
        $repetitionPlan = [
            ...array_fill(0, 645, 1),
            ...array_fill(0, 33, 2),
            ...array_fill(0, 2, 3),
            ...array_fill(0, 53, 5),
            ...array_fill(0, 3, 6),
        ];
        $this->assertCount(736, $repetitionPlan);
        $this->assertSame(1000, array_sum($repetitionPlan));

        $orderRows = [];
        $itemRows = [];
        $allocRows = [];
        $resRows = [];
        $manifestEntries = [];
        $jobId = 5000001;
        $resId = 1;

        for ($i = 0; $i < 736; $i++) {
            $oid = 300001 + $i;
            $remote = 'SYN-PILOT-'.$oid;
            $bucket = $i % 10;

            if ($i < 3) {
                // Missing orders locally (3) -> pending_human_review
            } elseif ($bucket === 0) {
                // Shipped during outage never reserved -> pending_human_review
                $orderRows[] = $this->makeOrderRow($oid, 1, $remote, 'paid', 'delivered', '2026-11-10T10:00:00-06:00');
                $itemRows[] = ['meli_order_id' => $oid, 'item_id' => 'MLM-ACTIVE', 'variation_id' => null, 'remote_line_key' => 'MLM-ACTIVE:', 'sku' => 'SKU-ACTIVE', 'quantity' => 1];
            } elseif ($bucket === 1) {
                // Kit order -> ambiguous_entities
                $orderRows[] = $this->makeOrderRow($oid, 1, $remote, 'paid', 'ready_to_ship', '2026-11-10T10:00:00-06:00');
                $itemRows[] = ['meli_order_id' => $oid, 'item_id' => 'MLM-KIT', 'variation_id' => null, 'remote_line_key' => 'MLM-KIT:', 'sku' => 'SKU-KIT', 'quantity' => 1];
            } elseif ($bucket === 2) {
                // Shipped with active reservation -> reconstructible_candidates
                $orderRows[] = $this->makeOrderRow($oid, 1, $remote, 'paid', 'shipped', '2026-11-10T10:00:00-06:00');
                $itemRows[] = ['meli_order_id' => $oid, 'item_id' => 'MLM-ACTIVE', 'variation_id' => null, 'remote_line_key' => 'MLM-ACTIVE:', 'sku' => 'SKU-ACTIVE', 'quantity' => 1];
                $resRows[] = ['id' => $resId, 'source_type' => 'meli_order', 'source_id' => $oid, 'inventory_product_id' => 10, 'quantity' => 1, 'status' => 'ACTIVE'];
                $allocRows[] = $this->makeAllocRow(1, $remote, 'MLM-ACTIVE:', 10, 'SIMPLE', $resId, 1, 'ACTIVE');
                $resId++;
            } elseif ($bucket === 3 || $bucket === 4) {
                // Paid with exact active reservation -> consistent_no_action_needed
                $orderRows[] = $this->makeOrderRow($oid, 1, $remote, 'paid', 'ready_to_ship', '2026-11-10T10:00:00-06:00');
                $itemRows[] = ['meli_order_id' => $oid, 'item_id' => 'MLM-ACTIVE', 'variation_id' => null, 'remote_line_key' => 'MLM-ACTIVE:', 'sku' => 'SKU-ACTIVE', 'quantity' => 1];
                $resRows[] = ['id' => $resId, 'source_type' => 'meli_order', 'source_id' => $oid, 'inventory_product_id' => 10, 'quantity' => 1, 'status' => 'ACTIVE'];
                $allocRows[] = $this->makeAllocRow(1, $remote, 'MLM-ACTIVE:', 10, 'SIMPLE', $resId, 1, 'ACTIVE');
                $resId++;
            } elseif ($bucket === 5 || $bucket === 6) {
                // Unlinked -> out_of_scope_no_reservation_link
                $orderRows[] = $this->makeOrderRow($oid, 1, $remote, 'paid', 'ready_to_ship', '2026-11-10T10:00:00-06:00');
                $itemRows[] = ['meli_order_id' => $oid, 'item_id' => 'MLM-UNMATCHED', 'variation_id' => null, 'remote_line_key' => 'MLM-UNMATCHED:', 'sku' => 'SKU-NONE', 'quantity' => 1];
            } elseif ($bucket === 7 || $bucket === 8) {
                // Valid pre-cutover -> out_of_scope_pre_cutover_or_ignored_status
                $orderRows[] = $this->makeOrderRow($oid, 1, $remote, 'paid', 'delivered', '2026-08-15T10:00:00-06:00');
                $itemRows[] = ['meli_order_id' => $oid, 'item_id' => 'MLM-ACTIVE', 'variation_id' => null, 'remote_line_key' => 'MLM-ACTIVE:', 'sku' => 'SKU-ACTIVE', 'quantity' => 1];
            } else {
                // Paid with released allocation -> insufficient_evidence
                $orderRows[] = $this->makeOrderRow($oid, 1, $remote, 'paid', 'ready_to_ship', '2026-11-10T10:00:00-06:00');
                $itemRows[] = ['meli_order_id' => $oid, 'item_id' => 'MLM-ACTIVE', 'variation_id' => null, 'remote_line_key' => 'MLM-ACTIVE:', 'sku' => 'SKU-ACTIVE', 'quantity' => 1];
                $allocRows[] = $this->makeAllocRow(1, $remote, 'MLM-ACTIVE:', 10, 'SIMPLE', null, 0, 'RELEASED');
            }

            for ($r = 0; $r < $repetitionPlan[$i]; $r++) {
                $manifestEntries[] = ['id' => $jobId++, 'entity_id' => $oid];
            }
        }

        foreach (array_chunk($orderRows, 200) as $ch) {
            DB::table('meli_orders')->insert($ch);
        }
        foreach (array_chunk($itemRows, 200) as $ch) {
            DB::table('meli_order_items')->insert($ch);
        }
        foreach (array_chunk($resRows, 200) as $ch) {
            DB::table('inventory_reservations')->insert($ch);
        }
        foreach (array_chunk($allocRows, 200) as $ch) {
            DB::table('inventory_channel_order_allocations')->insert($ch);
        }

        $manifestPath = $this->writeV2Manifest('pilot_1000_736.json', $manifestEntries);
        $ndjsonPath = $this->tmp('pilot_1000_736.ndjson');
        $beforeHashes = $this->hashAllTables();

        $report = $this->runDiagnose([
            'manifest_path' => $manifestPath,
            'ndjson_output_path' => $ndjsonPath,
            'cutover' => '2026-10-01T00:00:00-06:00',
        ]);

        // Exact mandatory pilot assertions:
        $this->assertTrue($report['invariants']['all_passed']);
        $this->assertSame(736, $report['invariants']['accounted_orders']);
        $this->assertSame(736, $report['invariants']['distinct_orders_in_manifest']);
        $this->assertSame(0, $report['invariants']['unclassified_orders']);
        $this->assertSame(736, $report['invariants']['sum_primary_partition_counts']);
        $this->assertSame(0, $report['invariants']['duplicate_primary_classifications']);
        $this->assertSame(1000, $report['invariants']['accounted_order_jobs']);
        $this->assertSame(1000, $report['invariants']['total_manifest_entries']);

        $sec = $report['historical_recovery_security'];
        $this->assertSame(1000, $sec['total_jobs_in_manifest']);
        $this->assertSame(736, $sec['distinct_orders_in_manifest']);
        $this->assertSame(645, $sec['orders_with_single_job']);
        $this->assertSame(91, $sec['orders_with_multiple_jobs']);
        $this->assertSame(264, $sec['extra_duplicate_jobs_for_same_order']);
        $this->assertSame(
            ['1' => 645, '2' => 33, '3' => 2, '5' => 53, '6' => 3],
            $sec['jobs_per_order_distribution'],
        );
        $this->assertSame(1000, array_sum($sec['jobs_by_primary_classification']));

        // Every one of the 7 categories populated and summing to 736
        $partition = $report['exhaustive_classification']['primary_partition_counts'];
        $this->assertSame(736, array_sum($partition));
        foreach (QueueOrderExhaustiveClassifier::PRIMARY_CATEGORIES as $cat) {
            $this->assertGreaterThan(0, $partition[$cat], "Category {$cat} should be > 0 in synthetic pilot");
        }

        // NDJSON has exact 736 lines and matches SHA-256
        $lines = file($ndjsonPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(736, $lines);
        $this->assertSame(hash_file('sha256', $ndjsonPath), $report['exhaustive_classification']['ndjson_output']['sha256']);

        // 100% read-only
        $this->assertSame($beforeHashes, $this->hashAllTables());
        Http::assertNothingSent();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @param array<string, mixed> $options @return array<string, mixed> */
    private function runDiagnose(array $options): array
    {
        /** @var QueueReconstructionDiagnosticsService $service */
        $service = $this->app->make(QueueReconstructionDiagnosticsService::class);

        return $service->diagnose([
            'cutover' => '2026-10-01T00:00:00-06:00',
            ...$options,
        ]);
    }

    /** @param array<string, mixed> $report @return array<int, array<string, mixed>> */
    private function indexEntitiesById(array $report): array
    {
        $map = [];
        foreach ($report['exhaustive_classification']['entities'] as $e) {
            $map[(int) $e['local_order_id']] = $e;
        }

        return $map;
    }

    private function seedBaseCatalog(): void
    {
        DB::table('meli_accounts')->insert(['id' => 1, 'nickname' => 'SYN-ACC-1']);
        DB::table('inventory_products')->insert([
            ['id' => 10, 'sku' => 'SKU-ACTIVE', 'name' => 'Active Simple', 'product_type' => 'simple', 'is_active' => 1],
            ['id' => 11, 'sku' => 'SKU-KIT', 'name' => 'Kit Prod', 'product_type' => 'kit', 'is_active' => 1],
            ['id' => 12, 'sku' => 'SKU-COMP', 'name' => 'Kit Component', 'product_type' => 'simple', 'is_active' => 1],
            ['id' => 13, 'sku' => 'SKU-INACT', 'name' => 'Inactive Simple', 'product_type' => 'simple', 'is_active' => 0],
        ]);
        DB::table('inventory_kit_components')->insert([
            ['kit_product_id' => 11, 'component_product_id' => 12, 'quantity' => 2],
        ]);
        DB::table('inventory_channel_links')->insert([
            ['id' => 1, 'channel' => 'mercado_libre', 'account_key' => '1', 'external_listing_id' => 'MLM-ACTIVE', 'external_variant_id' => null, 'inventory_product_id' => 10, 'is_active' => 1, 'order_reservation_enabled' => 1, 'stock_sync_enabled' => 1],
            ['id' => 2, 'channel' => 'mercado_libre', 'account_key' => '1', 'external_listing_id' => 'MLM-KIT', 'external_variant_id' => null, 'inventory_product_id' => 11, 'is_active' => 1, 'order_reservation_enabled' => 1, 'stock_sync_enabled' => 1],
            ['id' => 3, 'channel' => 'mercado_libre', 'account_key' => '1', 'external_listing_id' => 'MLM-COMP', 'external_variant_id' => null, 'inventory_product_id' => 12, 'is_active' => 1, 'order_reservation_enabled' => 1, 'stock_sync_enabled' => 1],
            ['id' => 4, 'channel' => 'mercado_libre', 'account_key' => '1', 'external_listing_id' => 'MLM-INACTIVE', 'external_variant_id' => null, 'inventory_product_id' => 10, 'is_active' => 0, 'order_reservation_enabled' => 1, 'stock_sync_enabled' => 0],
            ['id' => 5, 'channel' => 'mercado_libre', 'account_key' => '1', 'external_listing_id' => 'MLM-DISABLED', 'external_variant_id' => null, 'inventory_product_id' => 10, 'is_active' => 1, 'order_reservation_enabled' => 0, 'stock_sync_enabled' => 1],
            ['id' => 6, 'channel' => 'mercado_libre', 'account_key' => '1', 'external_listing_id' => 'MLM-INACTIVE-PROD', 'external_variant_id' => null, 'inventory_product_id' => 13, 'is_active' => 1, 'order_reservation_enabled' => 1, 'stock_sync_enabled' => 1],
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @param  array<string, mixed>|null  $shippingRaw
     */
    private function insertOrder(
        int $id,
        ?int $accountId,
        string $remoteOrderId,
        ?string $status,
        ?string $shippingStatus,
        ?array $raw = null,
        ?string $shippingId = null,
        ?array $shippingRaw = null,
    ): void {
        DB::table('meli_orders')->insert([
            'id' => $id,
            'meli_account_id' => $accountId,
            'order_id' => $remoteOrderId,
            'status' => $status,
            'shipping_id' => $shippingId,
            'shipping_status' => $shippingStatus,
            'shipping_raw' => $shippingRaw !== null ? json_encode($shippingRaw, JSON_THROW_ON_ERROR) : null,
            'raw' => $raw !== null ? json_encode($raw, JSON_THROW_ON_ERROR) : null,
            'created_at' => '2026-11-10 10:00:00',
            'updated_at' => '2026-11-10 10:00:00',
        ]);
    }

    /** @return array<string, mixed> */
    private function makeOrderRow(int $id, int $accountId, string $remote, string $status, string $shippingStatus, string $dateCreated): array
    {
        return [
            'id' => $id,
            'meli_account_id' => $accountId,
            'order_id' => $remote,
            'status' => $status,
            'shipping_id' => null,
            'shipping_status' => $shippingStatus,
            'shipping_raw' => null,
            'raw' => json_encode(['date_created' => $dateCreated, 'status' => $status], JSON_THROW_ON_ERROR),
            'created_at' => '2026-11-10 10:00:00',
            'updated_at' => '2026-11-10 10:00:00',
        ];
    }

    private function insertItem(int $orderId, string $itemId, ?string $lineKey, ?string $sku, int $qty, ?string $variationId = null): void
    {
        DB::table('meli_order_items')->insert([
            'meli_order_id' => $orderId,
            'item_id' => $itemId,
            'variation_id' => $variationId,
            'remote_line_key' => $lineKey,
            'sku' => $sku,
            'quantity' => $qty,
        ]);
    }

    private function insertReservation(int $orderId, int $productId, int $qty, string $status): int
    {
        return (int) DB::table('inventory_reservations')->insertGetId([
            'source_type' => 'meli_order',
            'source_id' => $orderId,
            'inventory_product_id' => $productId,
            'quantity' => $qty,
            'status' => $status,
        ]);
    }

    private function insertAllocation(
        int $accountId,
        string $remoteOrderId,
        string $lineKey,
        ?int $productId,
        ?string $kind,
        ?int $reservationId,
        int $qty,
        string $status,
        ?string $diagnosticCode = null,
    ): void {
        DB::table('inventory_channel_order_allocations')->insert(
            $this->makeAllocRow($accountId, $remoteOrderId, $lineKey, $productId, $kind, $reservationId, $qty, $status, $diagnosticCode),
        );
    }

    /** @return array<string, mixed> */
    private function makeAllocRow(
        int $accountId,
        string $remoteOrderId,
        string $lineKey,
        ?int $productId,
        ?string $kind,
        ?int $reservationId,
        int $qty,
        string $status,
        ?string $diagnosticCode = null,
    ): array {
        return [
            'channel' => 'mercado_libre',
            'account_key' => (string) $accountId,
            'remote_order_id' => $remoteOrderId,
            'remote_line_key' => $lineKey,
            'identity_hash' => hash('sha256', implode("\0", ['mercado_libre', (string) $accountId, $remoteOrderId, $lineKey])),
            'inventory_product_id' => $productId,
            'reservation_kind' => $kind,
            'reservation_id' => $reservationId,
            'quantity' => $qty,
            'status' => $status,
            'diagnostic_code' => $diagnosticCode,
        ];
    }

    /** @param list<array{id: int, entity_id: int}> $items */
    private function writeV2Manifest(string $name, array $items, ?string $hmacKey = null): string
    {
        $entries = [];
        $maxId = 0;
        foreach ($items as $it) {
            $jid = (int) $it['id'];
            $maxId = max($maxId, $jid);
            $entries[] = [
                'id' => $jid,
                'job_class' => QueuePayloadInspector::JOB_RESERVATIONS,
                'queue' => 'meli',
                'attempts' => 0,
                'available_at' => 1732550000,
                'entity_type' => 'meli_order',
                'entity_id' => (int) $it['entity_id'],
                'meli_item_id' => null,
                'created_at' => 1732550000,
                'created_at_iso' => '2026-11-10T10:00:00Z',
                'reserved_at' => null,
                'payload_hash' => hash('sha256', 'payload-'.$jid),
                'row_sha256' => hash('sha256', 'row-'.$jid),
            ];
        }

        $manifestManager = new QueueRecoveryManifest;
        $path = $this->tmp($name);
        $manifestManager->writeAtomic($path, [
            'incident_id' => 'INC-R2-DIAG-SYN',
            'generated_at' => '2026-11-26T00:00:00Z',
            'mode' => 'dry_run',
            'queue_connection' => 'database',
            'connection' => 'sqlite',
            'database' => ':memory:',
            'table' => 'jobs',
            'allowed_jobs' => [QueuePayloadInspector::JOB_RESERVATIONS],
            'start_id' => 0,
            'authorized_upper_bound' => $maxId,
            'last_scanned_id' => $maxId,
            'last_candidate_id' => $maxId > 0 ? $maxId : null,
            'next_start_id' => $maxId,
            'is_partial' => false,
            'stop_reason' => 'completed_upper_bound',
            'totals' => ['scanned' => count($entries), 'candidates' => count($entries)],
            'anomalies' => [],
            'entries' => $entries,
        ], $hmacKey);

        return $path;
    }

    /** @return array<string, string> */
    private function hashAllTables(): array
    {
        $tables = [
            'jobs',
            'meli_orders',
            'meli_order_items',
            'inventory_channel_order_allocations',
            'inventory_reservations',
            'inventory_kit_reservations',
            'inventory_movements',
        ];
        if (Schema::connection('sqlite')->hasTable('inventory_meli_order_reconciliations')) {
            $tables[] = 'inventory_meli_order_reconciliations';
        }
        $hashes = [];
        foreach ($tables as $t) {
            $rows = DB::table($t)->get()->all();
            $hashes[$t] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }

        return $hashes;
    }

    private function createFullTestSchema(string $connection, bool $includeR3Table): void
    {
        $schema = Schema::connection($connection);

        $schema->create('jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $schema->create('meli_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('nickname')->nullable();
        });
        $schema->create('meli_orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_account_id')->nullable();
            $table->string('order_id')->nullable();
            $table->string('status')->nullable();
            $table->string('shipping_id')->nullable();
            $table->string('shipping_status')->nullable();
            $table->text('shipping_raw')->nullable();
            $table->text('raw')->nullable();
            $table->timestamps();
        });
        $schema->create('meli_order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('meli_order_id');
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
            $table->string('remote_order_id');
            $table->string('remote_line_key');
            $table->string('identity_hash')->unique();
            $table->unsignedBigInteger('inventory_product_id')->nullable();
            $table->string('reservation_kind')->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->integer('quantity')->default(0);
            $table->string('status')->default('PENDING');
            $table->string('diagnostic_code')->nullable();
            $table->text('diagnostic_metadata')->nullable();
        });
        $schema->create('inventory_reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('inventory_product_id');
            $table->integer('quantity')->default(1);
            $table->string('status')->default('ACTIVE');
            $table->text('metadata')->nullable();
        });
        $schema->create('inventory_kit_reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('kit_product_id');
            $table->integer('quantity')->default(1);
            $table->string('status')->default('ACTIVE');
            $table->text('metadata')->nullable();
        });
        $schema->create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('inventory_product_id');
            $table->string('type');
            $table->integer('quantity');
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('external_key')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        if ($includeR3Table) {
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

    private function tmp(string $file): string
    {
        return $this->tempDir.DIRECTORY_SEPARATOR.$file;
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
