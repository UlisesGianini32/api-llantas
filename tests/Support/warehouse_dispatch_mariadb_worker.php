<?php

declare(strict_types=1);

/**
 * Isolated MariaDB 10.5.29 / InnoDB worker script invoked by WarehouseDispatchMariaDbConcurrencyTest.
 * Usage: php tests/Support/warehouse_dispatch_mariadb_worker.php <base64-json-payload>
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\InventoryDispatch;
use App\Models\InventoryProduct;
use App\Models\InventoryProvisionalItem;
use App\Models\InventoryShelfAudit;
use App\Models\MeliFullShipment;
use App\Models\PurchaseOrderReceipt;
use App\Models\User;
use App\Services\Inventory\Dispatches\ProvisionalItemLinkService;
use App\Services\Inventory\Dispatches\WarehouseDispatchService;
use App\Services\Inventory\ShelfAuditService;
use App\Services\InventoryMovementService;
use App\Services\InventoryReservationService;
use App\Services\MeliFullShipmentService;
use App\Services\Pos\PosSaleService;
use App\Services\Purchasing\PurchaseOrderService;
use Illuminate\Support\Facades\DB;
use Tests\Support\StagingDatabaseGuard;

$raw = $argv[1] ?? '';
$payload = json_decode((string) base64_decode($raw, true), true);
if (! is_array($payload)) {
    fwrite(STDERR, "Invalid worker payload\n");
    exit(2);
}

$conn = $payload['connection'];
StagingDatabaseGuard::assertAuthorized(
    (string) $conn['driver'],
    (string) $conn['host'],
    (string) $conn['port'],
    (string) $conn['database'],
    'testing',
);

config()->set('database.default', 'wd_mariadb');
config()->set('database.connections.wd_mariadb', $conn);
config()->set('inventory.warehouse_dispatches.enabled', true);
config()->set('inventory.warehouse_dispatches.read_only', false);
config()->set('inventory.warehouse_dispatches.apply_ledger', (bool) ($payload['apply_ledger'] ?? true));
DB::purge('wd_mariadb');

// Wait until synchronized start timestamp if provided
if (! empty($payload['start_at_ms'])) {
    $targetMs = (int) $payload['start_at_ms'];
    while ((int) floor(microtime(true) * 1000) < $targetMs) {
        usleep(5000);
    }
}

$action = (string) ($payload['action'] ?? '');
$actor = User::findOrFail((int) $payload['user_id']);

try {
    $result = match ($action) {
        'confirm_dispatch' => (function () use ($payload, $actor): array {
            $dispatch = InventoryDispatch::findOrFail((int) $payload['dispatch_id']);
            $confirmed = app(WarehouseDispatchService::class)->confirm(
                $dispatch,
                $actor,
                (string) $payload['confirm_key'],
                isset($payload['expected_draft_version']) ? (int) $payload['expected_draft_version'] : null,
            );

            return ['ok' => true, 'status' => $confirmed->status, 'apply_statuses' => $confirmed->items->pluck('inventory_apply_status')->all()];
        })(),
        'edit_draft' => (function () use ($payload, $actor): array {
            $dispatch = InventoryDispatch::findOrFail((int) $payload['dispatch_id']);
            $updated = app(WarehouseDispatchService::class)->updateDraft($dispatch, $payload['data'], $actor);

            return ['ok' => true, 'draft_version' => $updated->draft_version];
        })(),
        'cancel_draft' => (function () use ($payload, $actor): array {
            $dispatch = InventoryDispatch::findOrFail((int) $payload['dispatch_id']);
            $cancelled = app(WarehouseDispatchService::class)->cancelDraft($dispatch, $actor, 'Concurrent cancel');

            return ['ok' => true, 'status' => $cancelled->status];
        })(),
        'create_draft' => (function () use ($payload, $actor): array {
            $draft = app(WarehouseDispatchService::class)->createDraft($payload['data'], $actor);

            return ['ok' => true, 'id' => $draft->id, 'dispatch_number' => $draft->dispatch_number];
        })(),
        'meli_reserve' => (function () use ($payload, $actor): array {
            $res = app(InventoryReservationService::class)->create($payload['data'], $actor);

            return ['ok' => true, 'reservation_id' => $res->id];
        })(),
        'manual_out' => (function () use ($payload, $actor): array {
            $mov = app(InventoryMovementService::class)->recordManual($payload['data'], $actor);

            return ['ok' => true, 'movement_id' => $mov->id];
        })(),
        'pos_sale' => (function () use ($payload, $actor): array {
            $sale = app(PosSaleService::class)->createSale($payload['data'], $actor);

            return ['ok' => true, 'sale_id' => $sale->id];
        })(),
        'shelf_audit_apply' => (function () use ($payload, $actor): array {
            $audit = InventoryShelfAudit::findOrFail((int) $payload['audit_id']);
            $applied = app(ShelfAuditService::class)->applyAuditAdjustment($audit, $actor, 'Concurrent M08');

            return ['ok' => true, 'audit_id' => $applied->id, 'status' => $applied->status];
        })(),
        'po_void_receipt' => (function () use ($payload, $actor): array {
            $receipt = PurchaseOrderReceipt::findOrFail((int) $payload['receipt_id']);
            $po = app(PurchaseOrderService::class)->voidReceipt($receipt, $actor, 'Concurrent M09');

            return ['ok' => true, 'purchase_order_id' => $po->id];
        })(),
        'full_dispatch' => (function () use ($payload, $actor): array {
            $shipment = MeliFullShipment::findOrFail((int) $payload['shipment_id']);
            $dispatched = app(MeliFullShipmentService::class)->dispatchShipment($shipment, $actor);

            return ['ok' => true, 'shipment_id' => $dispatched->id, 'status' => $dispatched->status];
        })(),
        'merge_provisional' => (function () use ($payload, $actor): array {
            $src = InventoryProvisionalItem::findOrFail((int) $payload['source_id']);
            $dst = InventoryProvisionalItem::findOrFail((int) $payload['target_id']);
            $res = app(ProvisionalItemLinkService::class)->mergeProvisionalItems($src, $dst, $actor, 'Concurrent merge');

            return ['ok' => true, 'canonical_id' => $res->id];
        })(),
        'link_provisional' => (function () use ($payload, $actor): array {
            $prov = InventoryProvisionalItem::findOrFail((int) $payload['provisional_id']);
            $prod = InventoryProduct::findOrFail((int) $payload['product_id']);
            $res = app(ProvisionalItemLinkService::class)->linkToMasterProduct($prov, $prod, $actor, 'Concurrent link');

            return ['ok' => true, 'status' => $res->status];
        })(),
        default => ['ok' => false, 'error' => "Unknown action: {$action}"],
    };

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit(0);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'exception' => $e::class,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit(0);
}
