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
use RuntimeException;
use Throwable;

/**
 * Servicio transaccional e idempotente (PASO 33) para el alta controlada de los seis
 * productos maestros SIMPLE de Schwarzkopf Igora Royal y sus movimientos INITIAL (89 unidades).
 *
 * Principios de seguridad:
 * - Predeterminado en modo DRY-RUN sin escrituras.
 * - Reutiliza IgoraMasterBootstrapPreviewService como única fuente de verdad del catálogo y validaciones.
 * - Exige proposal_id exacto, confirmación explícita y fecha de reconfirmación de conteos válida,
 *   >= 2026-11-19 y nunca futura.
 * - Registra occurred_at con el timestamp efectivo real de ejecución (sin inventar una hora histórica)
 *   y conserva la fecha del conteo físico (2026-11-19) en metadata y notes.
 * - Bloquea si existen duplicados, ubicaciones inválidas, vínculos ML, movimientos previos,
 *   reservas relevantes, pedidos ML o ventas POS posteriores al conteo.
 * - Valida relación biyectiva 1-a-1 external_key <-> SKU para idempotencia estricta.
 * - Protegido contra concurrencia con doble cerrojo (Cache::lock TTL 120s con validación de driver
 *   compartido + lock advisory MySQL/MariaDB GET_LOCK cuando aplica + lockForUpdate en BD)
 *   y transacción atómica todo-o-nada (DB::transaction).
 * - Crea los productos con is_active = false (sin habilitación comercial automática en POS/Compras/Kits/ML)
 *   y sin inventar costos, precios ni proveedor.
 * - Define rollback 100% auditable mediante operaciones compensatorias ADJUSTMENT_OUT (append-only,
 *   sin DELETE sobre inventory_movements ni inventory_products).
 * - Jamás crea vínculos de Mercado Libre, BOM, reservas automáticas ni dispara sincronización HTTP.
 */
class IgoraMasterBootstrapApplyService
{
    public const LOCK_KEY = 'igora:master-bootstrap:apply:'.IgoraMasterBootstrapPreviewService::PROPOSAL_ID;

    public const DB_ADVISORY_LOCK_NAME = 'igora_master_bootstrap_2026_11_19';

    public const LOCK_TTL_SECONDS = 120;

    /**
     * Drivers de caché no compartidos entre procesos CLI (inseguros si no existe advisory lock de BD).
     *
     * @var list<string>
     */
    public const NON_SHARED_CACHE_DRIVERS = ['array', 'null'];

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
     *     allow_non_shared_cache_lock?: ?bool,
     *     after_item_persisted?: ?callable
     * }  $options
     * @return array<string, mixed>
     */
    public function execute(array $options = []): array
    {
        $now = now()->copy();
        $apply = (bool) ($options['apply'] ?? false);
        $proposalId = isset($options['proposal_id']) ? trim((string) $options['proposal_id']) : null;
        $confirmed = (bool) ($options['confirmed'] ?? false);
        $reconfirmedRaw = isset($options['reconfirmed_counts_at']) ? trim((string) $options['reconfirmed_counts_at']) : null;
        $actorUserId = isset($options['actor_user_id']) && $options['actor_user_id'] !== ''
            ? (int) $options['actor_user_id']
            : null;
        $allowNonSharedCacheLock = array_key_exists('allow_non_shared_cache_lock', $options) && $options['allow_non_shared_cache_lock'] !== null
            ? (bool) $options['allow_non_shared_cache_lock']
            : app()->runningUnitTests();
        $afterItemPersisted = isset($options['after_item_persisted']) && is_callable($options['after_item_persisted'])
            ? $options['after_item_persisted']
            : null;

        $parsedReconfirmedAt = $this->parseReconfirmedTimestamp($reconfirmedRaw);
        $gateErrors = $this->evaluateApplyGate($proposalId, $confirmed, $reconfirmedRaw, $parsedReconfirmedAt, $now, $actorUserId);
        $concurrencyMeta = $this->inspectConcurrencySafety($allowNonSharedCacheLock);

        if (! $apply) {
            return $this->runDryRun($proposalId, $parsedReconfirmedAt, $gateErrors, $concurrencyMeta);
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
                concurrencyLock: $concurrencyMeta,
            );
        }

        // Rechazar ejecución concurrente insegura si el caché no es compartido y el motor de BD no soporta advisory lock
        if (! $concurrencyMeta['is_safe_for_apply']) {
            $preview = $this->previewService->preview();
            $idempotency = $this->inspectIdempotencyState();
            $postCount = $this->auditPostCountConflicts($preview, $idempotency);

            Log::warning('igora.master_bootstrap.unsafe_lock_store', [
                'proposal_id' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
                'actor_user_id' => $actorUserId,
                'concurrency_lock' => $concurrencyMeta,
            ]);

            return $this->buildReport(
                mode: 'APPLY',
                outcome: self::OUTCOME_BLOCKED,
                writesExecuted: false,
                blockingReasons: ['UNSAFE_LOCK_STORE_FOR_CONCURRENCY'],
                applyGate: $this->formatApplyGate($proposalId, $confirmed, $parsedReconfirmedAt, []),
                preview: $preview,
                idempotency: $idempotency,
                postCountAudit: $postCount,
                concurrencyLock: $concurrencyMeta,
            );
        }

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            Log::warning('igora.master_bootstrap.lock_conflict', [
                'proposal_id' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
                'actor_user_id' => $actorUserId,
                'lock_key' => self::LOCK_KEY,
                'lock_ttl_seconds' => self::LOCK_TTL_SECONDS,
            ]);

            return $this->buildLockConflictReport($proposalId, $confirmed, $parsedReconfirmedAt, $concurrencyMeta, 'CONCURRENT_EXECUTION_LOCK_ACTIVE');
        }

        $dbAdvisoryAcquired = false;
        try {
            if ($concurrencyMeta['db_advisory_lock_supported']) {
                $dbAdvisoryAcquired = $this->acquireDbAdvisoryLock();
                if (! $dbAdvisoryAcquired) {
                    Log::warning('igora.master_bootstrap.db_advisory_lock_conflict', [
                        'proposal_id' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
                        'actor_user_id' => $actorUserId,
                        'db_advisory_lock_name' => self::DB_ADVISORY_LOCK_NAME,
                    ]);

                    return $this->buildLockConflictReport($proposalId, $confirmed, $parsedReconfirmedAt, $concurrencyMeta, 'CONCURRENT_DB_ADVISORY_LOCK_ACTIVE');
                }
            }

            $concurrencyMeta['cache_lock_acquired'] = true;
            $concurrencyMeta['db_advisory_lock_acquired'] = $dbAdvisoryAcquired;

            return $this->runTransactionalApply(
                proposalId: (string) $proposalId,
                reconfirmedAt: $parsedReconfirmedAt,
                actorUserId: $actorUserId,
                concurrencyMeta: $concurrencyMeta,
                afterItemPersisted: $afterItemPersisted,
            );
        } finally {
            if ($dbAdvisoryAcquired) {
                try {
                    $this->releaseDbAdvisoryLock();
                } catch (Throwable $releaseError) {
                    Log::warning('igora.master_bootstrap.db_advisory_lock_release_failed', [
                        'db_advisory_lock_name' => self::DB_ADVISORY_LOCK_NAME,
                        'error' => $releaseError->getMessage(),
                    ]);
                }
            }

            try {
                $lock->release();
            } catch (Throwable $releaseError) {
                Log::warning('igora.master_bootstrap.cache_lock_release_failed', [
                    'lock_key' => self::LOCK_KEY,
                    'error' => $releaseError->getMessage(),
                ]);
            }
        }
    }

    /**
     * Define el plan determinista de rollback auditable mediante operaciones compensatorias
     * (append-only: jamás ejecuta DELETE sobre inventory_movements ni inventory_products).
     *
     * @return array<string, mixed>
     */
    public function compensatoryRollbackPlan(): array
    {
        $operations = array_map(function (array $item): array {
            $sku = (string) $item['sku'];

            return [
                'tone' => $item['tone'],
                'sku' => $sku,
                'location_id' => (int) $item['location_id'],
                'location_code' => (string) $item['location_code'],
                'movement_type' => InventoryMovement::ADJUSTMENT_OUT,
                'quantity' => -1 * (int) $item['physical_count'],
                'initial_external_key' => $this->previewService->operationKeyForSku($sku),
                'compensation_external_key' => $this->previewService->compensationKeyForSku($sku),
                'reference_type' => 'igora_master_bootstrap_rollback',
                'reference' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID.':ROLLBACK',
                'occurred_at_policy' => 'EXECUTION_TIMESTAMP',
            ];
        }, $this->previewService->auditedProposalItems());

        return [
            'policy' => 'COMPENSATORY_LEDGER_ONLY',
            'delete_ledger_rows' => false,
            'delete_products' => false,
            'keep_products_inactive' => true,
            'movement_type' => InventoryMovement::ADJUSTMENT_OUT,
            'reference_type' => 'igora_master_bootstrap_rollback',
            'reference' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID.':ROLLBACK',
            'expected_operations_count' => count($operations),
            'expected_total_compensation_units' => (int) collect($operations)->sum('quantity'),
            'mandatory_preconditions' => [
                'Nunca ejecutar DELETE sobre inventory_movements ni inventory_products; el ledger es estrictamente append-only.',
                'Mantener inventory_products.is_active = false para los 6 productos mientras se ejecuta y audita la reversión.',
                'Verificar que existan los 6 movimientos INITIAL originales y que no exista ninguna clave de compensación previa (...:COMPENSATE:<SKU>).',
                'Verificar que no existan inventory_reservations activas ni inventory_channel_links activos para los 6 productos.',
                'Registrar los 6 movimientos ADJUSTMENT_OUT dentro de una única DB::transaction vía InventoryMovementService::record() usando external_key único (...:COMPENSATE:<SKU>) y occurred_at = now().',
                'Si ya hubo ventas/consumos posteriores y el stock disponible es menor a la cantidad inicial, no forzar saldo negativo: conciliar por conteo físico real y compensar únicamente el delta verificado.',
                'Revertir el código de despliegue únicamente con git revert -m 1 <merge_sha>, sin alterar registros históricos del ledger.',
            ],
            'operations' => $operations,
        ];
    }

    /**
     * @param  list<string>  $gateErrors
     * @param  array<string, mixed>  $concurrencyMeta
     * @return array<string, mixed>
     */
    private function runDryRun(?string $proposalId, ?Carbon $parsedReconfirmedAt, array $gateErrors, array $concurrencyMeta): array
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
                concurrencyLock: $concurrencyMeta,
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
            concurrencyLock: $concurrencyMeta,
        );
    }

    /**
     * @param  array<string, mixed>  $concurrencyMeta
     * @return array<string, mixed>
     */
    private function runTransactionalApply(
        string $proposalId,
        ?Carbon $reconfirmedAt,
        ?int $actorUserId,
        array $concurrencyMeta,
        ?callable $afterItemPersisted,
    ): array {
        $actor = $actorUserId !== null ? User::query()->find($actorUserId) : null;
        $executedAt = now()->copy();

        try {
            $result = DB::transaction(function () use ($proposalId, $reconfirmedAt, $actorUserId, $actor, $executedAt, $concurrencyMeta, $afterItemPersisted): array {
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
                        concurrencyLock: $concurrencyMeta,
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
                        concurrencyLock: $concurrencyMeta,
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
                            'Alta controlada PASO 33 (%s). Conteo físico: %s; reconfirmado: %s; aplicado: %s. Producto creado con is_active=false sin habilitación comercial automática.',
                            IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
                            IgoraMasterBootstrapPreviewService::PHYSICAL_COUNT_DATE,
                            $reconfirmedAt?->toIso8601String() ?? 'N/D',
                            $executedAt->toIso8601String()
                        ),
                        'metadata' => [
                            'step' => 'PASO_33',
                            'proposal_id' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
                            'operation_key' => $operationKey,
                            'compensation_external_key' => $this->previewService->compensationKeyForSku((string) $item['sku']),
                            'physical_count_date' => IgoraMasterBootstrapPreviewService::PHYSICAL_COUNT_DATE,
                            'occurred_at_policy' => 'EXECUTION_TIMESTAMP',
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
                        // Fecha efectiva real de registro en el ledger (sin inventar una hora histórica)
                        'occurred_at' => $executedAt,
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
                    concurrencyLock: $concurrencyMeta,
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
     * Evalúa exhaustivamente la relación biyectiva 1-a-1 external_key <-> SKU
     * sobre los 6 external_key únicos en inventory_movements.
     *
     * @return array<string, mixed>
     */
    private function inspectIdempotencyState(bool $lockForUpdate = false): array
    {
        $proposalItems = $this->previewService->auditedProposalItems();
        $expectedByKey = [];
        foreach ($proposalItems as $item) {
            $key = $this->previewService->operationKeyForSku((string) $item['sku']);
            $expectedByKey[$key] = $item;
        }
        $operationKeys = array_keys($expectedByKey);

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
        $seenKeys = [];
        $seenProductIds = [];
        $seenSkus = [];

        foreach ($foundMovements as $movement) {
            $movementKey = (string) $movement->external_key;
            $expected = $expectedByKey[$movementKey] ?? null;
            $product = $movement->product;
            $actualSku = $product ? (string) $product->sku : '';
            $seenKeys[$movementKey] = true;

            if ($expected === null || ! $product) {
                $inconsistencies[] = sprintf('UNEXPECTED_PRODUCT_FOR_KEY:%s', $movementKey);
            } else {
                $expectedSku = (string) $expected['sku'];
                $expectedBarcode = (string) $expected['barcode'];

                // Verificación estricta 1-a-1: la external_key debe corresponder exactamente al SKU del producto
                if ($this->normalizeCode($actualSku) !== $this->normalizeCode($expectedSku)) {
                    $inconsistencies[] = sprintf(
                        'EXTERNAL_KEY_SKU_MISMATCH:key=%s,expected_sku=%s,actual_sku=%s',
                        $movementKey,
                        $expectedSku,
                        $actualSku
                    );
                }

                $actualBarcode = $product->barcode !== null ? trim((string) $product->barcode) : '';
                if ($actualBarcode === '' || $this->normalizeCode($actualBarcode) !== $this->normalizeCode($expectedBarcode)) {
                    $inconsistencies[] = sprintf(
                        'UNEXPECTED_BARCODE_FOR_KEY:key=%s,expected=%s,actual=%s',
                        $movementKey,
                        $expectedBarcode,
                        $actualBarcode === '' ? 'NULL' : $actualBarcode
                    );
                }

                if ((string) ($product->product_type ?? '') !== InventoryProduct::SIMPLE) {
                    $inconsistencies[] = sprintf(
                        'UNEXPECTED_PRODUCT_TYPE:key=%s,actual=%s',
                        $movementKey,
                        (string) ($product->product_type ?? '')
                    );
                }

                if ((string) $movement->type !== InventoryMovement::INITIAL) {
                    $inconsistencies[] = sprintf('UNEXPECTED_MOVEMENT_TYPE:%s=%s', $movementKey, (string) $movement->type);
                }

                if ((int) $movement->quantity !== (int) $expected['physical_count']) {
                    $inconsistencies[] = sprintf(
                        'UNEXPECTED_QUANTITY:%s=actual:%d,expected:%d',
                        $movementKey,
                        (int) $movement->quantity,
                        (int) $expected['physical_count']
                    );
                }

                if ((int) $movement->inventory_location_id !== (int) $expected['location_id']) {
                    $inconsistencies[] = sprintf(
                        'UNEXPECTED_LOCATION:%s=actual:%d,expected:%d',
                        $movementKey,
                        (int) $movement->inventory_location_id,
                        (int) $expected['location_id']
                    );
                }

                if ((string) ($movement->reference ?? '') !== IgoraMasterBootstrapPreviewService::PROPOSAL_ID) {
                    $inconsistencies[] = sprintf(
                        'UNEXPECTED_REFERENCE:%s=actual:%s',
                        $movementKey,
                        (string) ($movement->reference ?? '')
                    );
                }

                $productId = (int) $product->getKey();
                if (isset($seenProductIds[$productId])) {
                    $inconsistencies[] = sprintf('DUPLICATE_PRODUCT_ACROSS_OPERATION_KEYS:#%d', $productId);
                }
                $seenProductIds[$productId] = true;
                $seenSkus[$this->normalizeCode($actualSku)] = true;
            }

            $totalUnits += (int) $movement->quantity;
            $records[] = [
                'inventory_product_id' => (int) $movement->inventory_product_id,
                'sku' => $actualSku,
                'is_active' => $product ? (bool) $product->is_active : null,
                'inventory_movement_id' => (int) $movement->getKey(),
                'quantity' => (int) $movement->quantity,
                'inventory_location_id' => (int) $movement->inventory_location_id,
                'external_key' => $movementKey,
                'occurred_at' => $movement->occurred_at?->toIso8601String(),
            ];
        }

        foreach ($operationKeys as $expectedKey) {
            if (! isset($seenKeys[$expectedKey])) {
                $inconsistencies[] = sprintf('MISSING_OPERATION_KEY:%s', $expectedKey);
            }
        }

        $inconsistencies = array_values(array_unique($inconsistencies));

        $isCompleteAndConsistent = $foundCount === IgoraMasterBootstrapPreviewService::EXPECTED_PRODUCT_COUNT
            && count($seenKeys) === IgoraMasterBootstrapPreviewService::EXPECTED_PRODUCT_COUNT
            && count($seenProductIds) === IgoraMasterBootstrapPreviewService::EXPECTED_PRODUCT_COUNT
            && count($seenSkus) === IgoraMasterBootstrapPreviewService::EXPECTED_PRODUCT_COUNT
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
     * Evalúa seguridad del mecanismo de concurrencia (driver de caché + soporte de advisory lock en BD).
     *
     * @return array<string, mixed>
     */
    private function inspectConcurrencySafety(bool $allowNonSharedCacheLock): array
    {
        $cacheStoreName = (string) config('cache.default', 'file');
        $cacheDriver = (string) config("cache.stores.{$cacheStoreName}.driver", $cacheStoreName);
        $cacheStoreIsShared = ! in_array($cacheDriver, self::NON_SHARED_CACHE_DRIVERS, true);

        $dbDriver = (string) DB::connection()->getDriverName();
        $dbAdvisorySupported = in_array($dbDriver, ['mysql', 'mariadb'], true);

        $isSafeForApply = $cacheStoreIsShared || $dbAdvisorySupported || $allowNonSharedCacheLock;

        return [
            'lock_key' => self::LOCK_KEY,
            'lock_ttl_seconds' => self::LOCK_TTL_SECONDS,
            'cache_store' => $cacheStoreName,
            'cache_driver' => $cacheDriver,
            'cache_store_is_shared' => $cacheStoreIsShared,
            'db_driver' => $dbDriver,
            'db_advisory_lock_supported' => $dbAdvisorySupported,
            'db_advisory_lock_name' => $dbAdvisorySupported ? self::DB_ADVISORY_LOCK_NAME : null,
            'cache_lock_acquired' => false,
            'db_advisory_lock_acquired' => false,
            'is_safe_for_apply' => $isSafeForApply,
        ];
    }

    private function acquireDbAdvisoryLock(): bool
    {
        $row = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [self::DB_ADVISORY_LOCK_NAME]);

        return (int) ($row->acquired ?? 0) === 1;
    }

    private function releaseDbAdvisoryLock(): void
    {
        DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [self::DB_ADVISORY_LOCK_NAME]);
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
        $cutoff = Carbon::parse(IgoraMasterBootstrapPreviewService::PHYSICAL_COUNT_DATE)->startOfDay();
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
        Carbon $now,
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
            $countFloor = Carbon::parse(IgoraMasterBootstrapPreviewService::PHYSICAL_COUNT_DATE)->startOfDay();
            if ($parsedReconfirmedAt->lt($countFloor)) {
                $errors[] = 'COUNTS_RECONFIRMATION_BEFORE_COUNT_DATE';
            }
            if ($parsedReconfirmedAt->gt($now)) {
                $errors[] = 'COUNTS_RECONFIRMATION_IN_FUTURE';
            }
        }

        if ($actorUserId !== null && ! User::query()->whereKey($actorUserId)->exists()) {
            $errors[] = 'ACTOR_USER_NOT_FOUND';
        }

        return $errors;
    }

    /**
     * Parsea estrictamente una fecha/hora sin permitir overflow de calendario
     * (rechaza 2026-02-30, 2026-11-31, 2026-13-01, 25:00:00, etc.).
     */
    private function parseReconfirmedTimestamp(?string $raw): ?Carbon
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $trimmed = trim($raw);
        $pattern = '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})(?:(?<sep>[ T])(?<hour>\d{2}):(?<minute>\d{2})(?::(?<second>\d{2}))?(?<tz>Z|[+-](?<tzh>\d{2}):?(?<tzm>\d{2}))?)?$/';

        if (preg_match($pattern, $trimmed, $m) !== 1) {
            return null;
        }

        $year = (int) $m['year'];
        $month = (int) $m['month'];
        $day = (int) $m['day'];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $hasTime = isset($m['hour']) && $m['hour'] !== '';
        $hour = $hasTime ? (int) $m['hour'] : 0;
        $minute = $hasTime ? (int) $m['minute'] : 0;
        $hasSecond = isset($m['second']) && $m['second'] !== '';
        $second = $hasSecond ? (int) $m['second'] : 0;

        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
            return null;
        }

        $tz = $m['tz'] ?? '';
        if ($tz !== '' && $tz !== 'Z') {
            $tzHour = (int) ($m['tzh'] ?? 99);
            $tzMin = (int) ($m['tzm'] ?? 99);
            if ($tzHour > 14 || $tzMin > 59) {
                return null;
            }
        }

        try {
            if (! $hasTime) {
                $parsed = Carbon::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $day));
            } else {
                $sep = $m['sep'] === 'T' ? '\T' : ' ';
                $format = $hasSecond ? "!Y-m-d{$sep}H:i:s" : "!Y-m-d{$sep}H:i";
                if ($tz !== '') {
                    $format .= 'P';
                    $valueToParse = preg_replace('/([+-]\d{2})(\d{2})$/', '$1:$2', $trimmed) ?? $trimmed;
                } else {
                    $valueToParse = $trimmed;
                }
                $parsed = Carbon::createFromFormat($format, $valueToParse);
            }

            if ($parsed === false) {
                return null;
            }

            $lastErrors = Carbon::getLastErrors();
            if (is_array($lastErrors) && (($lastErrors['warning_count'] ?? 0) > 0 || ($lastErrors['error_count'] ?? 0) > 0)) {
                return null;
            }

            // Doble chequeo contra overflow silencioso
            if ((int) $parsed->format('Y') !== $year
                || (int) $parsed->format('m') !== $month
                || (int) $parsed->format('d') !== $day) {
                return null;
            }

            return $parsed;
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeCode(string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '', 'UTF-8');
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
     * @param  array<string, mixed>  $concurrencyMeta
     * @return array<string, mixed>
     */
    private function buildLockConflictReport(
        ?string $proposalId,
        bool $confirmed,
        ?Carbon $parsedReconfirmedAt,
        array $concurrencyMeta,
        string $reason,
    ): array {
        return [
            'mode' => 'APPLY',
            'outcome' => self::OUTCOME_LOCK_CONFLICT,
            'proposal_id' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
            'physical_count_date' => IgoraMasterBootstrapPreviewService::PHYSICAL_COUNT_DATE,
            'writes_executed' => false,
            'products_created' => 0,
            'movements_created' => 0,
            'total_units_written' => 0,
            'blocking_reasons' => [$reason],
            'apply_gate' => $this->formatApplyGate($proposalId, $confirmed, $parsedReconfirmedAt, []),
            'concurrency_lock' => $concurrencyMeta,
            'compensatory_rollback_plan' => $this->compensatoryRollbackPlan(),
            'created_records' => [],
        ];
    }

    /**
     * @param  list<string>  $blockingReasons
     * @param  array<string, mixed>  $applyGate
     * @param  array<string, mixed>  $preview
     * @param  array<string, mixed>  $idempotency
     * @param  array<string, mixed>  $postCountAudit
     * @param  array<string, mixed>  $concurrencyLock
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
        array $concurrencyLock,
        array $createdRecords = [],
    ): array {
        return [
            'mode' => $mode,
            'outcome' => $outcome,
            'proposal_id' => IgoraMasterBootstrapPreviewService::PROPOSAL_ID,
            'physical_count_date' => IgoraMasterBootstrapPreviewService::PHYSICAL_COUNT_DATE,
            'occurred_at_policy' => 'EXECUTION_TIMESTAMP',
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
            'concurrency_lock' => $concurrencyLock,
            'idempotency' => $idempotency,
            'post_count_audit' => $postCountAudit,
            'compensatory_rollback_plan' => $this->compensatoryRollbackPlan(),
            'summary' => $preview['summary'],
            'locations' => $preview['locations'],
            'items' => $preview['items'],
            'created_records' => $createdRecords,
        ];
    }
}
