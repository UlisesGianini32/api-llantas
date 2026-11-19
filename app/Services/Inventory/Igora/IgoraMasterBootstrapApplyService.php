<?php

namespace App\Services\Inventory\Igora;

use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventoryReservation;
use App\Models\User;
use App\Services\InventoryMovementService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Servicio transaccional e idempotente (PASO 33) para el alta controlada de los seis
 * productos maestros SIMPLE de Schwarzkopf Igora Royal y sus movimientos INITIAL (89 unidades).
 *
 * Principios de seguridad:
 * - Predeterminado en modo DRY-RUN sin escrituras.
 * - Reutiliza IgoraMasterBootstrapPreviewService como única fuente de verdad del catálogo y validaciones.
 * - Exige proposal_id exacto, confirmación explícita y fecha de reconfirmación de conteos (>= 2026-11-19).
 * - Bloquea si existen duplicados, ubicaciones inválidas, vínculos ML, movimientos previos,
 *   reservas relevantes, pedidos ML o ventas POS posteriores al conteo.
 * - Protegido contra concurrencia (Cache::lock + lockForUpdate) y todo-o-nada (DB::transaction).
 * - Clave de operación única en inventory_movements.external_key para idempotencia estricta.
 * - Crea los productos con is_active = false (sin habilitación comercial automática en POS/Compras/Kits/ML)
 *   y sin inventar costos, precios ni proveedor.
 * - Jamás crea vínculos de Mercado Libre, BOM, reservas automáticas ni dispara sincronización HTTP.
 */
class IgoraMasterBootstrapApplyService
{
    public const LOCK_KEY = 'igora:master-bootstrap:apply:'.IgoraMasterBootstrapPreviewService::PROPOSAL_ID;

    public const LOCK_TTL_SECONDS = 60;

    public const OUTCOME_DRY_RUN_READY = 'DRY_RUN_READY';

    public const OUTCOME_DRY_RUN_ALREADY_APPLIED = 'DRY_RUN_ALREADY_APPLIED';

    public const OUTCOME_DRY_RUN_BLOCKED = 'DRY_RUN_BLOCKED';

    public const OUTCOME_APPLIED = 'APPLIED';

    public const OUTCOME_ALREADY_APPLIED = 'ALREADY_APPLIED';

    public const OUTCOME_BLOCKED = 'BLOCKED';

    public const OUTCOME_LOCK_CONFLICT = 'CONCURRENT_EXECUTION_BLOCKED';

    public function __construct(
        private readonly IgoraMasterBootstrapPreviewService $previewService,
        private readonly InventoryMovementService $movementService,
    ) {}

    /**
     * Ejecuta el flujo en dry-run (por defecto) o en modo transaccional controlado (--apply).
     *
     * @param  array{
     *     apply?: bool,
     *     proposal_id?: ?string,
     *     confirmed?: bool,
     *     reconfirmed_counts_at?: ?string,
     *     actor_user_id?: ?int,
     *     after_item_persisted?: ?callable
     * }  $options
     * @return array<string, mixed>
     */
    public function execute(array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $proposalId = isset($options['proposal_id']) ? trim((string) $options['proposal_id']) : null;
        $confirmed = (bool) ($options['confirmed'] ?? false);
        $reconfirmedRaw = isset($options['reconfirmed_counts_at']) ? trim((string) $options['reconfirmed_counts_at']) : null;
        $actorUserId = isset($options['actor_user_id']) && $options['actor_user_id'] !== ''
            ? (int) $options['actor_user_id']
            : null;
        $afterItemPersisted = isset($options['after_item_persisted']) && is_callable($options['after_item_persisted'])
            ? $options['after_item_persisted']
            : null;

        $parsedReconfirmedAt = $this->parseReconfirmedTimestamp($reconfirmedRaw);
        $gateErrors = $this->evaluateApplyGate($proposalId, $confirmed, $reconfirmedRaw, $parsedReconfirmedAt, $actorUserId);

        if (! $apply) {
            return $this->runDryRun($proposalId, $parsedReconfirmedAt, $gateErrors);
        }

        // En modo --apply, validar puertas de autorización antes incluso de intentar adquirir lock
        if ($gateErrors !== []) {
            $preview = $this->previewService->preview();
            $idempotency = $this->inspectIdempotencyState();
            $postCount = $this->auditPostCountConflicts($preview, $idempotency);

            Log::warning('igora.master_bootstrap.blocked_gate', [
                'proposal_id' => $proposalId,
                'actor_user_id' => $actorUserId,
                'gate_errors' => $gateErrors,
            ]);

            return $this->buildReport(
                mode: 'APPLY',
                outcome: self::OUTCOME_BLOCKED,
                writesExecuted: false,
                blockingReasons: $gateErrors,
                applyGate: $this->formatApplyGate($proposalId, $confirmed, $parsedReconfirmedAt, $gateErrors),
                preview: $preview,
                idempotency: $idempotency,
                postCountAudit: $postCount,
            );
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            Log::warning('igora.master_bootstrap.lock_conflict', [
                'proposal_id' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
                'actor_user_id' => $actorUserId,
                'lock_key' => self::LOCK_KEY,
            ]);

            return [
                'mode' => 'APPLY',
                'outcome' => self::OUTCOME_LOCK_CONFLICT,
                'proposal_id' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
                'physical_count_date' => IgoraMasterBootstrapPreviewService::PHYSICAL_COUNT_DATE,
                'writes_executed' => false,
                'products_created' => 0,
                'movements_created' => 0,
                'total_units_written' => 0,
                'blocking_reasons' => ['CONCURRENT_EXECUTION_LOCK_ACTIVE'],
                'apply_gate' => $this->formatApplyGate($proposalId, $confirmed, $parsedReconfirmedAt, []),
                'created_records' => [],
            ];
        }

        try {
            return $this->runTransactionalApply(
                proposalId: (string) $proposalId,
                reconfirmedAt: $parsedReconfirmedAt,
                actorUserId: $actorUserId,
                afterItemPersisted: $afterItemPersisted,
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  list<string>  $gateErrors
     * @return array<string, mixed>
     */
    private function runDryRun(?string $proposalId, ?Carbon $parsedReconfirmedAt, array $gateErrors): array
    {
        $idempotency = $this->inspectIdempotencyState();
        $preview = $this->previewService->preview();
        $postCount = $this->auditPostCountConflicts($preview, $idempotency);

        if ($idempotency['state'] === 'COMPLETE') {
            return $this->buildReport(
                mode: 'DRY_RUN',
                outcome: self::OUTCOME_DRY_RUN_ALREADY_APPLIED,
                writesExecuted: false,
                blockingReasons: [],
                applyGate: $this->formatApplyGate($proposalId, false, $parsedReconfirmedAt, $gateErrors),
                preview: $preview,
                idempotency: $idempotency,
                postCountAudit: $postCount,
            );
        }

        $blockingReasons = $this->collectDataBlockingReasons($preview, $idempotency, $postCount);
        $outcome = $blockingReasons === []
            ? self::OUTCOME_DRY_RUN_READY
            : self::OUTCOME_DRY_RUN_BLOCKED;

        return $this->buildReport(
            mode: 'DRY_RUN',
            outcome: $outcome,
            writesExecuted: false,
            blockingReasons: $blockingReasons,
            applyGate: $this->formatApplyGate($proposalId, false, $parsedReconfirmedAt, $gateErrors),
            preview: $preview,
            idempotency: $idempotency,
            postCountAudit: $postCount,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function runTransactionalApply(
        string $proposalId,
        ?Carbon $reconfirmedAt,
        ?int $actorUserId,
        ?callable $afterItemPersisted,
    ): array {
        $actor = $actorUserId !== null ? User::query()->find($actorUserId) : null;
        $executedAt = now();

        try {
            $result = DB::transaction(function () use ($proposalId, $reconfirmedAt, $actorUserId, $actor, $executedAt, $afterItemPersisted): array {
                // 1. Bloquear las 4 ubicaciones en la BD durante toda la transacción
                $lockedLocations = InventoryLocation::query()
                    ->whereIn('id', array_keys(IgoraMasterBootstrapPreviewService::EXPECTED_LOCATIONS))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // 2. Verificar idempotencia estricta dentro de la transacción
                $idempotency = $this->inspectIdempotencyState(lockForUpdate: true);
                $preview = $this->previewService->preview();
                $postCount = $this->auditPostCountConflicts($preview, $idempotency);

                if ($idempotency['state'] === 'COMPLETE') {
                    return $this->buildReport(
                        mode: 'APPLY',
                        outcome: self::OUTCOME_ALREADY_APPLIED,
                        writesExecuted: false,
                        blockingReasons: [],
                        applyGate: $this->formatApplyGate($proposalId, true, $reconfirmedAt, []),
                        preview: $preview,
                        idempotency: $idempotency,
                        postCountAudit: $postCount,
                    );
                }

                // 3. Revalidación estricta dentro de la transacción (elimina ventanas TOCTOU)
                $blockingReasons = $this->collectDataBlockingReasons($preview, $idempotency, $postCount);

                foreach (IgoraMasterBootstrapPreviewService::EXPECTED_LOCATIONS as $expectedId => $expectedCode) {
                    /** @var ?InventoryLocation $loc */
                    $loc = $lockedLocations->get($expectedId);
                    if (! $loc) {
                        $blockingReasons[] = sprintf('LOCKED_LOCATION_MISSING:#%d', $expectedId);
                    } elseif (mb_strtoupper(trim((string) $loc->code)) !== mb_strtoupper($expectedCode)) {
                        $blockingReasons[] = sprintf('LOCKED_LOCATION_CODE_MISMATCH:#%d', $expectedId);
                    } elseif (! (bool) $loc->is_active) {
                        $blockingReasons[] = sprintf('LOCKED_LOCATION_INACTIVE:#%d', $expectedId);
                    }
                }

                $blockingReasons = array_values(array_unique($blockingReasons));
                if ($blockingReasons !== []) {
                    return $this->buildReport(
                        mode: 'APPLY',
                        outcome: self::OUTCOME_BLOCKED,
                        writesExecuted: false,
                        blockingReasons: $blockingReasons,
                        applyGate: $this->formatApplyGate($proposalId, true, $reconfirmedAt, []),
                        preview: $preview,
                        idempotency: $idempotency,
                        postCountAudit: $postCount,
                    );
                }

                // 4. Alta atómica todo-o-nada de los 6 productos SIMPLE (is_active = false) y sus 6 movimientos INITIAL
                $createdRecords = [];
                $totalUnitsWritten = 0;
                $proposalItems = $this->previewService->auditedProposalItems();

                foreach ($proposalItems as $index => $item) {
                    $productPayload = $item['proposed_product_payload'];

                    // Asegurar que nacen inactivos comercialmente y sin datos comerciales inventados
                    $product = InventoryProduct::create([
                        'sku' => $productPayload['sku'],
                        'barcode' => $productPayload['barcode'],
                        'barcode_secondary' => null,
                        'name' => $productPayload['name'],
                        'brand' => $productPayload['brand'],
                        'description' => $productPayload['description'],
                        'product_type' => InventoryProduct::SIMPLE,
                        'primary_location_id' => $productPayload['primary_location_id'],
                        'is_active' => false,
                        'cost' => null,
                        'supplier' => null,
                        'price_mercado_libre' => null,
                        'price_amazon' => null,
                        'price_stylist' => null,
                        'price_public' => null,
                    ]);

                    $operationKey = $this->previewService->operationKeyForSku((string) $item['sku']);
                    $movementPayload = $item['proposed_initial_movement_payload'];

                    $movement = $this->movementService->record([
                        'inventory_product_id' => (int) $product->getKey(),
                        'inventory_location_id' => (int) $item['location_id'],
                        'type' => InventoryMovement::INITIAL,
                        'quantity' => (int) $item['physical_count'],
                        'reference_type' => (string) ($movementPayload['reference_type'] ?? 'igora_master_bootstrap'),
                        'reference_id' => (int) $product->getKey(),
                        'reference' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
                        'notes' => sprintf(
                            'Alta controlada PASO 33 (%s). Conteo físico: %s; reconfirmado: %s. Producto creado con is_active=false sin habilitación comercial automática.',
                            IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
                            IgoraMasterBootstrapPreviewService::PHYSICAL_COUNT_DATE,
                            $reconfirmedAt?->toIso8601String() ?? 'N/D'
                        ),
                        'metadata' => [
                            'step' => 'PASO_33',
                            'proposal_id' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
                            'operation_key' => $operationKey,
                            'physical_count_date' => IgoraMasterBootstrapPreviewService::PHYSICAL_COUNT_DATE,
                            'reconfirmed_counts_at' => $reconfirmedAt?->toIso8601String(),
                            'executed_at' => $executedAt->toIso8601String(),
                            'actor_user_id' => $actorUserId,
                            'tone' => $item['tone'],
                            'sku' => $item['sku'],
                            'barcode' => $item['barcode'],
                            'location_id' => (int) $item['location_id'],
                            'location_code' => $item['location_code'],
                            'audit_mlm' => $item['audit_mlm'],
                            'initial_is_active' => false,
                            'channel_links_created' => false,
                            'stock_sync_dispatched' => false,
                        ],
                        'created_by' => $actor?->getKey(),
                        'occurred_at' => IgoraMasterBootstrapPreviewService::PHYSICAL_COUNT_DATE.' 00:00:00',
                        'external_key' => $operationKey,
                    ], $actor);

                    $totalUnitsWritten += (int) $movement->quantity;
                    $createdRecords[] = [
                        'tone' => $item['tone'],
                        'sku' => (string) $product->sku,
                        'barcode' => (string) $product->barcode,
                        'inventory_product_id' => (int) $product->getKey(),
                        'is_active' => (bool) $product->is_active,
                        'inventory_movement_id' => (int) $movement->getKey(),
                        'movement_type' => (string) $movement->type,
                        'quantity' => (int) $movement->quantity,
                        'inventory_location_id' => (int) $movement->inventory_location_id,
                        'location_code' => $item['location_code'],
                        'occurred_at' => $movement->occurred_at?->toIso8601String(),
                        'external_key' => (string) $movement->external_key,
                    ];

                    if ($afterItemPersisted !== null) {
                        $afterItemPersisted($index, $product, $movement);
                    }
                }

                // 5. Invariante todo-o-nada antes de confirmar la transacción
                if (count($createdRecords) !== IgoraMasterBootstrapPreviewService::EXPECTED_PRODUCT_COUNT
                    || $totalUnitsWritten !== IgoraMasterBootstrapPreviewService::EXPECTED_PHYSICAL_UNITS) {
                    throw new RuntimeException(sprintf(
                        'Invariante incumplida en alta Igora Royal: productos=%d (esperado %d), unidades=%d (esperado %d).',
                        count($createdRecords),
                        IgoraMasterBootstrapPreviewService::EXPECTED_PRODUCT_COUNT,
                        $totalUnitsWritten,
                        IgoraMasterBootstrapPreviewService::EXPECTED_PHYSICAL_UNITS
                    ));
                }

                return $this->buildReport(
                    mode: 'APPLY',
                    outcome: self::OUTCOME_APPLIED,
                    writesExecuted: true,
                    blockingReasons: [],
                    applyGate: $this->formatApplyGate($proposalId, true, $reconfirmedAt, []),
                    preview: $preview,
                    idempotency: $this->inspectIdempotencyState(),
                    postCountAudit: $postCount,
                    createdRecords: $createdRecords,
                );
            });

            Log::info('igora.master_bootstrap.completed', [
                'outcome' => $result['outcome'],
                'proposal_id' => $proposalId,
                'actor_user_id' => $actorUserId,
                'writes_executed' => $result['writes_executed'],
                'products_created' => $result['products_created'],
                'movements_created' => $result['movements_created'],
                'total_units_written' => $result['total_units_written'],
            ]);

            return $result;
        } catch (Throwable $e) {
            Log::error('igora.master_bootstrap.rolled_back', [
                'proposal_id' => $proposalId,
                'actor_user_id' => $actorUserId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Evalúa el estado de idempotencia revisando los 6 external_key únicos en inventory_movements.
     *
     * @return array<string, mixed>
     */
    private function inspectIdempotencyState(bool $lockForUpdate = false): array
    {
        $proposalItems = collect($this->previewService->auditedProposalItems())->keyBy('sku');
        $operationKeys = $this->previewService->operationKeys();

        $query = InventoryMovement::query()
            ->with('product:id,sku,barcode,name,product_type,primary_location_id,is_active')
            ->whereIn('external_key', $operationKeys)
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $foundMovements = $query->get();
        $foundCount = $foundMovements->count();

        if ($foundCount === 0) {
            return [
                'state' => 'NOT_APPLIED',
                'expected_keys_count' => count($operationKeys),
                'found_keys_count' => 0,
                'total_units_recorded' => 0,
                'consistent' => true,
                'inconsistencies' => [],
                'records' => [],
            ];
        }

        $inconsistencies = [];
        $records = [];
        $totalUnits = 0;

        foreach ($foundMovements as $movement) {
            $product = $movement->product;
            $sku = $product ? (string) $product->sku : '';
            $expected = $proposalItems->get($sku);

            if (! $product || $expected === null) {
                $inconsistencies[] = sprintf('UNEXPECTED_PRODUCT_FOR_KEY:%s', (string) $movement->external_key);
            } else {
                if ((string) $movement->type !== InventoryMovement::INITIAL) {
                    $inconsistencies[] = sprintf('UNEXPECTED_MOVEMENT_TYPE:%s=%s', $sku, (string) $movement->type);
                }
                if ((int) $movement->quantity !== (int) $expected['physical_count']) {
                    $inconsistencies[] = sprintf(
                        'UNEXPECTED_QUANTITY:%s=actual:%d,expected:%d',
                        $sku,
                        (int) $movement->quantity,
                        (int) $expected['physical_count']
                    );
                }
                if ((int) $movement->inventory_location_id !== (int) $expected['location_id']) {
                    $inconsistencies[] = sprintf(
                        'UNEXPECTED_LOCATION:%s=actual:%d,expected:%d',
                        $sku,
                        (int) $movement->inventory_location_id,
                        (int) $expected['location_id']
                    );
                }
            }

            $totalUnits += (int) $movement->quantity;
            $records[] = [
                'inventory_product_id' => (int) $movement->inventory_product_id,
                'sku' => $sku,
                'is_active' => $product ? (bool) $product->is_active : null,
                'inventory_movement_id' => (int) $movement->getKey(),
                'quantity' => (int) $movement->quantity,
                'inventory_location_id' => (int) $movement->inventory_location_id,
                'external_key' => (string) $movement->external_key,
                'occurred_at' => $movement->occurred_at?->toIso8601String(),
            ];
        }

        $isCompleteAndConsistent = $foundCount === IgoraMasterBootstrapPreviewService::EXPECTED_PRODUCT_COUNT
            && $totalUnits === IgoraMasterBootstrapPreviewService::EXPECTED_PHYSICAL_UNITS
            && $inconsistencies === [];

        return [
            'state' => $isCompleteAndConsistent ? 'COMPLETE' : 'PARTIAL_OR_INCONSISTENT',
            'expected_keys_count' => count($operationKeys),
            'found_keys_count' => $foundCount,
            'total_units_recorded' => $totalUnits,
            'consistent' => $inconsistencies === [],
            'inconsistencies' => $inconsistencies,
            'records' => $records,
        ];
    }

    /**
     * Audita reservas/apartados, movimientos posteriores al conteo, pedidos de Mercado Libre
     * y ventas POS que puedan invalidar los conteos físicos del 2026-11-19.
     *
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>  $idempotency
     * @return array<string, mixed>
     */
    private function auditPostCountConflicts(array $preview, array $idempotency): array
    {
        $cutoff = Carbon::parse(IgoraMasterBootstrapPreviewService::PHYSICAL_COUNT_DATE.' 00:00:00');
        $proposalItems = $this->previewService->auditedProposalItems();
        $skus = collect($proposalItems)->pluck('sku')->unique()->values()->all();
        $mlms = collect($proposalItems)->pluck('audit_mlm')->unique()->values()->all();
        $terms = array_values(array_unique(array_merge($skus, $mlms, [IgoraMasterBootstrapPreviewService::PROPOSAL_ID])));
        $bootstrapKeys = $this->previewService->operationKeys();

        // Productos candidatos detectados por el preview (duplicados exactos, identidad o vínculos)
        $relatedProductIds = collect($preview['items'] ?? [])
            ->flatMap(function (array $item): array {
                return array_merge(
                    array_map(fn (array $m): int => (int) $m['id'], $item['exact_code_matches'] ?? []),
                    array_map(fn (array $m): int => (int) $m['id'], $item['identity_review_matches'] ?? []),
                    array_map(fn (array $l): int => (int) $l['inventory_product_id'], $item['existing_channel_links'] ?? []),
                );
            })
            ->unique()
            ->values()
            ->all();

        // 1. Reservas / apartados relevantes en inventory_reservations
        $reservations = [];
        if (Schema::hasTable('inventory_reservations')) {
            $reservations = InventoryReservation::query()
                ->where(function ($query) use ($relatedProductIds, $terms): void {
                    if ($relatedProductIds !== []) {
                        $query->whereIn('inventory_product_id', $relatedProductIds);
                    }
                    foreach ($terms as $term) {
                        $query->orWhere('reference', 'like', '%'.$term.'%')
                            ->orWhere('external_key', 'like', '%'.$term.'%');
                    }
                })
                ->where(function ($query) use ($cutoff): void {
                    $query->where('status', InventoryReservation::ACTIVE)
                        ->orWhere('created_at', '>=', $cutoff);
                })
                ->orderBy('id')
                ->get(['id', 'inventory_product_id', 'inventory_location_id', 'quantity', 'status', 'reference', 'external_key', 'created_at'])
                ->map(fn (InventoryReservation $r): array => [
                    'id' => (int) $r->id,
                    'inventory_product_id' => (int) $r->inventory_product_id,
                    'inventory_location_id' => $r->inventory_location_id ? (int) $r->inventory_location_id : null,
                    'quantity' => (int) $r->quantity,
                    'status' => (string) $r->status,
                    'reference' => $r->reference,
                    'external_key' => $r->external_key,
                    'created_at' => $r->created_at?->toIso8601String(),
                ])
                ->all();
        }

        // 2. Movimientos (ventas, devoluciones, ajustes, recepciones, etc.) posteriores al conteo
        //    (excluyendo los 6 external_key propios cuando la propuesta ya está aplicada completa)
        $postCountMovements = [];
        if (Schema::hasTable('inventory_movements')) {
            $movQuery = InventoryMovement::query()
                ->where('occurred_at', '>=', $cutoff)
                ->where(function ($query) use ($relatedProductIds, $terms): void {
                    if ($relatedProductIds !== []) {
                        $query->whereIn('inventory_product_id', $relatedProductIds);
                    }
                    foreach ($terms as $term) {
                        $query->orWhere('reference', 'like', '%'.$term.'%')
                            ->orWhere('notes', 'like', '%'.$term.'%')
                            ->orWhere('external_key', 'like', '%'.$term.'%');
                    }
                })
                ->orderBy('id');

            if ($idempotency['state'] === 'COMPLETE') {
                $movQuery->where(function ($q) use ($bootstrapKeys): void {
                    $q->whereNull('external_key')
                        ->orWhereNotIn('external_key', $bootstrapKeys);
                });
            }

            $postCountMovements = $movQuery
                ->get(['id', 'inventory_product_id', 'inventory_location_id', 'type', 'quantity', 'reference', 'external_key', 'occurred_at'])
                ->map(fn (InventoryMovement $m): array => [
                    'id' => (int) $m->id,
                    'inventory_product_id' => (int) $m->inventory_product_id,
                    'inventory_location_id' => (int) $m->inventory_location_id,
                    'type' => (string) $m->type,
                    'quantity' => (int) $m->quantity,
                    'reference' => $m->reference,
                    'external_key' => $m->external_key,
                    'occurred_at' => $m->occurred_at?->toIso8601String(),
                ])
                ->all();
        }

        // 3. Pedidos de Mercado Libre posteriores al conteo para los 6 MLM o 6 SKUs (solo lectura si existe tabla)
        $meliOrders = [];
        if (Schema::hasTable('meli_orders') && Schema::hasTable('meli_order_items')) {
            $meliOrders = DB::table('meli_order_items')
                ->join('meli_orders', 'meli_orders.id', '=', 'meli_order_items.meli_order_id')
                ->where('meli_orders.created_at', '>=', $cutoff)
                ->where(function ($query) use ($mlms, $skus): void {
                    $query->whereIn('meli_order_items.item_id', $mlms)
                        ->orWhereIn('meli_order_items.sku', $skus);
                })
                ->orderBy('meli_orders.id')
                ->get([
                    'meli_orders.id as meli_order_db_id',
                    'meli_orders.order_id as remote_order_id',
                    'meli_orders.status',
                    'meli_orders.created_at',
                    'meli_order_items.item_id',
                    'meli_order_items.sku',
                    'meli_order_items.quantity',
                ])
                ->map(fn (object $row): array => [
                    'meli_order_db_id' => (int) $row->meli_order_db_id,
                    'remote_order_id' => (string) $row->remote_order_id,
                    'status' => (string) ($row->status ?? ''),
                    'item_id' => (string) $row->item_id,
                    'sku' => $row->sku !== null ? (string) $row->sku : null,
                    'quantity' => (int) $row->quantity,
                    'created_at' => (string) $row->created_at,
                ])
                ->all();
        }

        // 4. Ventas POS posteriores al conteo para los 6 SKUs/barcodes (solo lectura si existe tabla)
        $posSales = [];
        if (Schema::hasTable('pos_sales') && Schema::hasTable('pos_sale_items')) {
            $posSales = DB::table('pos_sale_items')
                ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_items.pos_sale_id')
                ->where('pos_sales.created_at', '>=', $cutoff)
                ->where(function ($query) use ($skus): void {
                    $query->whereIn('pos_sale_items.sku', $skus)
                        ->orWhereIn('pos_sale_items.barcode', $skus);
                })
                ->orderBy('pos_sales.id')
                ->get([
                    'pos_sales.id as pos_sale_id',
                    'pos_sales.sale_number',
                    'pos_sales.status',
                    'pos_sales.created_at',
                    'pos_sale_items.sku',
                    'pos_sale_items.barcode',
                    'pos_sale_items.quantity',
                ])
                ->map(fn (object $row): array => [
                    'pos_sale_id' => (int) $row->pos_sale_id,
                    'sale_number' => (string) $row->sale_number,
                    'status' => (string) ($row->status ?? ''),
                    'sku' => (string) $row->sku,
                    'barcode' => $row->barcode !== null ? (string) $row->barcode : null,
                    'quantity' => (int) $row->quantity,
                    'created_at' => (string) $row->created_at,
                ])
                ->all();
        }

        return [
            'cutoff_date' => $cutoff->toIso8601String(),
            'reservations' => $reservations,
            'post_count_movements' => $postCountMovements,
            'meli_orders' => $meliOrders,
            'pos_sales' => $posSales,
            'has_conflicts' => $reservations !== []
                || $postCountMovements !== []
                || $meliOrders !== []
                || $posSales !== [],
        ];
    }

    /**
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>  $idempotency
     * @param  array<string, mixed>  $postCount
     * @return list<string>
     */
    private function collectDataBlockingReasons(array $preview, array $idempotency, array $postCount): array
    {
        $reasons = [];

        if ($idempotency['state'] === 'PARTIAL_OR_INCONSISTENT') {
            $reasons[] = 'PARTIAL_OR_INCONSISTENT_BOOTSTRAP_STATE';
            foreach ($idempotency['inconsistencies'] as $inc) {
                $reasons[] = $inc;
            }
        }

        if (! ($preview['summary']['totals_match_expected'] ?? false)) {
            $reasons[] = 'PROPOSAL_TOTALS_MISMATCH';
        }

        if (($preview['summary']['locations_invalid'] ?? 0) > 0) {
            $reasons[] = 'INVALID_OR_INACTIVE_LOCATIONS';
        }

        foreach ($preview['items'] ?? [] as $item) {
            if (($item['status'] ?? null) !== IgoraMasterBootstrapPreviewService::STATUS_READY) {
                $reasons[] = sprintf(
                    'ITEM_NOT_READY:%s=%s(%s)',
                    (string) $item['tone'],
                    (string) $item['status'],
                    implode('|', $item['reasons'] ?? [])
                );
            }
        }

        if (($postCount['reservations'] ?? []) !== []) {
            $reasons[] = 'POST_COUNT_RESERVATION_CONFLICT';
        }
        if (($postCount['post_count_movements'] ?? []) !== []) {
            $reasons[] = 'POST_COUNT_MOVEMENT_CONFLICT';
        }
        if (($postCount['meli_orders'] ?? []) !== []) {
            $reasons[] = 'POST_COUNT_MELI_ORDER_CONFLICT';
        }
        if (($postCount['pos_sales'] ?? []) !== []) {
            $reasons[] = 'POST_COUNT_POS_SALE_CONFLICT';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @return list<string>
     */
    private function evaluateApplyGate(
        ?string $proposalId,
        bool $confirmed,
        ?string $reconfirmedRaw,
        ?Carbon $parsedReconfirmedAt,
        ?int $actorUserId = null,
    ): array {
        $errors = [];

        if ($proposalId === null || $proposalId === '' || $proposalId !== IgoraMasterBootstrapPreviewService::PROPOSAL_ID) {
            $errors[] = 'INVALID_OR_MISSING_PROPOSAL_ID';
        }

        if (! $confirmed) {
            $errors[] = 'EXPLICIT_CONFIRMATION_REQUIRED';
        }

        if ($reconfirmedRaw === null || $reconfirmedRaw === '') {
            $errors[] = 'COUNTS_RECONFIRMATION_REQUIRED';
        } elseif ($parsedReconfirmedAt === null) {
            $errors[] = 'COUNTS_RECONFIRMATION_INVALID_TIMESTAMP';
        } else {
            $countFloor = Carbon::parse(IgoraMasterBootstrapPreviewService::PHYSICAL_COUNT_DATE.' 00:00:00');
            if ($parsedReconfirmedAt->lt($countFloor)) {
                $errors[] = 'COUNTS_RECONFIRMATION_BEFORE_COUNT_DATE';
            }
        }

        if ($actorUserId !== null && ! User::query()->whereKey($actorUserId)->exists()) {
            $errors[] = 'ACTOR_USER_NOT_FOUND';
        }

        return $errors;
    }

    private function parseReconfirmedTimestamp(?string $raw): ?Carbon
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $trimmed = trim($raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $trimmed) !== 1) {
            return null;
        }

        try {
            return Carbon::parse($trimmed);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $gateErrors
     * @return array<string, mixed>
     */
    private function formatApplyGate(?string $proposalId, bool $confirmed, ?Carbon $reconfirmedAt, array $gateErrors): array
    {
        return [
            'expected_proposal_id' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
            'provided_proposal_id' => $proposalId !== '' ? $proposalId : null,
            'proposal_id_matches' => $proposalId === IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
            'explicit_confirmation_received' => $confirmed,
            'reconfirmed_counts_at' => $reconfirmedAt?->toIso8601String(),
            'gate_passed' => $gateErrors === [],
            'gate_errors' => $gateErrors,
        ];
    }

    /**
     * @param  list<string>  $blockingReasons
     * @param  array<string, mixed>  $applyGate
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>  $idempotency
     * @param  array<string, mixed>  $postCountAudit
     * @param  list<array<string, mixed>>  $createdRecords
     * @return array<string, mixed>
     */
    private function buildReport(
        string $mode,
        string $outcome,
        bool $writesExecuted,
        array $blockingReasons,
        array $applyGate,
        array $preview,
        array $idempotency,
        array $postCountAudit,
        array $createdRecords = [],
    ): array {
        return [
            'mode' => $mode,
            'outcome' => $outcome,
            'proposal_id' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
            'physical_count_date' => IgoraMasterBootstrapPreviewService::PHYSICAL_COUNT_DATE,
            'product_initial_is_active' => false,
            'commercial_activation_policy' => 'is_active=false hasta aprobación comercial (bloquea POS, Compras, Kits, Reservas automáticas y ML Stock Sync; visible en InventoryProductController e IgoraGroupingAssistantService).',
            'writes_executed' => $writesExecuted,
            'products_created' => count($createdRecords),
            'movements_created' => count($createdRecords),
            'total_units_written' => (int) collect($createdRecords)->sum('quantity'),
            'external_http_calls' => false,
            'channel_links_created' => 0,
            'bom_components_created' => 0,
            'reservations_created' => 0,
            'sync_jobs_dispatched' => 0,
            'blocking_reasons' => $blockingReasons,
            'apply_gate' => $applyGate,
            'idempotency' => $idempotency,
            'post_count_audit' => $postCountAudit,
            'summary' => $preview['summary'],
            'locations' => $preview['locations'],
            'items' => $preview['items'],
            'created_records' => $createdRecords,
        ];
    }
}
