<?php

namespace App\Services\Purchasing;

use App\Models\MeliPriceManagerItem;
use App\Models\MeliPublication;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Maintains the generation-versioned auxiliary SKU/variation index (`meli_listing_sku_index`)
 * and its persistent state table (`meli_listing_sku_index_state`).
 *
 * Integrity guarantees:
 * - Verifiable completeness: the resolver NEVER treats "at least one row exists" as ready;
 *   only an atomically published `active_generation > 0` in `meli_listing_sku_index_state`
 *   makes the index eligible for reads.
 * - Non-destructive rebuilds: `rebuildIndex()` builds into a brand-new `generation`, preserving
 *   the previous `active_generation` untouched until the entire scan, catch-up pass, and atomic
 *   state switch commit cleanly. Obsolete generations are purged only AFTER commit.
 * - Concurrency safety: during a rebuild, Eloquent hooks dual-write to both `active_generation`
 *   and `building_generation`, and a pre-publish catch-up pass captures Query Builder / mass-update
 *   modifications and deletions before switching `active_generation`.
 * - Observable incremental errors: hook failures never break ML syncs, but are logged and
 *   recorded in `meli_listing_sku_index_state` so drift is never silent.
 */
class MeliListingSkuIndexService
{
    public const TABLE = 'meli_listing_sku_index';

    public const STATE_TABLE = 'meli_listing_sku_index_state';

    public const STATE_ROW_ID = 1;

    public const SOURCE_PUBLICATIONS = 'meli_publications';

    public const SOURCE_PRICE_MANAGER = 'meli_price_manager_items';

    public const STATUS_UNINITIALIZED = 'uninitialized';

    public const STATUS_BUILDING = 'building';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const REBUILD_STATUS_RUNNING = 'running';

    public const REBUILD_STATUS_SUCCEEDED = 'succeeded';

    public const REBUILD_STATUS_FAILED = 'failed';

    public const STALE_BUILD_MINUTES = 30;

    private const DELETE_BATCH_SIZE = 1000;

    /** @var array<string, true> */
    private array $seenJsonErrors = [];

    private function databaseSessionNow(): Carbon
    {
        return Carbon::parse(
            (string) DB::selectOne('SELECT CURRENT_TIMESTAMP AS db_now')->db_now
        );
    }

    public function isTableAvailable(): bool
    {
        return Schema::hasTable(self::TABLE) && Schema::hasTable(self::STATE_TABLE);
    }

    /**
     * Return the active verified generation number, or null if the index is not yet
     * initialized/complete. Never infers readiness from row existence alone.
     */
    public function getActiveReadyGeneration(): ?int
    {
        if (! Schema::hasTable(self::STATE_TABLE)) {
            return null;
        }

        $state = DB::table(self::STATE_TABLE)
            ->where('id', self::STATE_ROW_ID)
            ->first(['active_generation', 'status']);

        if ($state === null) {
            return null;
        }

        $activeGeneration = (int) $state->active_generation;

        return $activeGeneration > 0 ? $activeGeneration : null;
    }

    public function isReady(): bool
    {
        return $this->getActiveReadyGeneration() !== null;
    }

    /**
     * Synchronize index entries for a single MeliPublication row into all active/building generations.
     * No-op when the index is uninitialized (active_generation === 0 and no build running).
     */
    public function syncFromPublication(MeliPublication $publication): int
    {
        if (! $this->isTableAvailable()) {
            return 0;
        }

        $generations = $this->targetGenerations();
        if ($generations === []) {
            return 0;
        }

        $row = (object) [
            'id' => (int) $publication->id,
            'meli_account_id' => $publication->meli_account_id,
            'sku' => $publication->sku,
            'mlm' => $publication->mlm,
            'status' => $publication->status,
            'is_current' => array_key_exists('is_current', $publication->getAttributes())
                ? (bool) $publication->is_current
                : true,
            'last_sync_at' => $publication->last_sync_at ? $publication->last_sync_at->toDateTimeString() : null,
            'raw' => $publication->raw,
        ];

        $totalWritten = 0;
        foreach ($generations as $generation) {
            $jsonErrors = 0;
            $entries = $this->extractEntriesFromPublicationRow($row, $generation, $jsonErrors);
            $totalWritten += $this->replaceSourceRowsForGeneration(
                $generation,
                self::SOURCE_PUBLICATIONS,
                (int) $publication->id,
                $entries,
            );
        }

        return $totalWritten;
    }

    /**
     * Synchronize index entries for a single MeliPriceManagerItem row into all active/building generations.
     */
    public function syncFromPriceManagerItem(MeliPriceManagerItem $priceItem): int
    {
        if (! $this->isTableAvailable()) {
            return 0;
        }

        $generations = $this->targetGenerations();
        if ($generations === []) {
            return 0;
        }

        $row = (object) [
            'id' => (int) $priceItem->id,
            'meli_account_id' => $priceItem->meli_account_id,
            'meli_item_id' => $priceItem->meli_item_id,
            'sku' => $priceItem->sku,
            'status' => $priceItem->status,
            'raw_item' => $priceItem->raw_item,
            'raw_attributes' => $priceItem->raw_attributes,
            'last_synced_at' => $priceItem->last_synced_at ? $priceItem->last_synced_at->toDateTimeString() : null,
        ];

        $totalWritten = 0;
        foreach ($generations as $generation) {
            $jsonErrors = 0;
            $entries = $this->extractEntriesFromPriceManagerRow($row, $generation, $jsonErrors);
            $totalWritten += $this->replaceSourceRowsForGeneration(
                $generation,
                self::SOURCE_PRICE_MANAGER,
                (int) $priceItem->id,
                $entries,
            );
        }

        return $totalWritten;
    }

    public function deleteForSource(string $sourceTable, int $sourceRowId): void
    {
        if (! $this->isTableAvailable()) {
            return;
        }

        $generations = $this->targetGenerations();
        if ($generations === []) {
            return;
        }

        DB::table(self::TABLE)
            ->whereIn('generation', $generations)
            ->where('source_table', $sourceTable)
            ->where('source_row_id', $sourceRowId)
            ->delete();
    }

    /**
     * Rebuild the full auxiliary SKU/variation index into a brand-new generation without
     * modifying or deleting the currently active generation until the new generation is
     * 100% complete, caught up with concurrent changes, and atomically published.
     *
     * @param  ?callable(string, int, int): void  $afterStageCallback  Optional hook for tests (`$stage, $generation, $chunkIndex`)
     * @return array{
     *   generation: int,
     *   previous_generation: int,
     *   publications_scanned: int,
     *   price_manager_scanned: int,
     *   rows_indexed: int,
     *   active_rows_count: int,
     *   json_errors: int,
     *   catchup_publications: int,
     *   catchup_price_manager: int,
     *   pruned_deleted_rows: int,
     *   cleaned_obsolete_rows: int
     * }
     */
    public function rebuildIndex(
        int $chunkSize = 200,
        ?callable $afterStageCallback = null,
        bool $forceTakeover = false,
    ): array {
        if (! $this->isTableAvailable()) {
            throw new RuntimeException('Las tablas meli_listing_sku_index / meli_listing_sku_index_state no existen.');
        }

        $chunkSize = max(25, min(1000, $chunkSize));
        $rebuildStartedAt = $this->databaseSessionNow();

        // Step 1: Atomically claim a new candidate generation without altering active_generation.
        [$newGeneration, $previousGeneration] = $this->claimCandidateGeneration($rebuildStartedAt, $forceTakeover);

        $publicationsScanned = 0;
        $priceManagerScanned = 0;
        $rowsIndexed = 0;
        $jsonErrors = 0;
        $this->seenJsonErrors = [];

        try {
            // Step 2a: Stream meli_publications via Query Builder (safe against malformed JSON rows).
            if (Schema::hasTable(self::SOURCE_PUBLICATIONS)) {
                $pubColumns = Schema::getColumnListing(self::SOURCE_PUBLICATIONS);
                $pubSelect = array_values(array_filter([
                    'id',
                    'meli_account_id',
                    'sku',
                    'mlm',
                    'status',
                    'last_sync_at',
                    'raw',
                    in_array('is_current', $pubColumns, true) ? 'is_current' : null,
                ]));

                $pubChunkIndex = 0;
                DB::table(self::SOURCE_PUBLICATIONS)
                    ->select($pubSelect)
                    ->orderBy('id')
                    ->chunkById($chunkSize, function ($rows) use (
                        $newGeneration,
                        &$publicationsScanned,
                        &$rowsIndexed,
                        &$jsonErrors,
                        &$pubChunkIndex,
                        $afterStageCallback
                    ): void {
                        $pubChunkIndex++;
                        $batch = [];
                        foreach ($rows as $row) {
                            $publicationsScanned++;
                            foreach ($this->extractEntriesFromPublicationRow($row, $newGeneration, $jsonErrors) as $entry) {
                                $batch[] = $entry;
                            }
                        }

                        if ($batch !== []) {
                            $this->insertBatch($batch);
                            $rowsIndexed += count($batch);
                        }

                        $this->touchBuildHeartbeat($newGeneration);

                        if ($afterStageCallback !== null) {
                            $afterStageCallback('publications_chunk', $newGeneration, $pubChunkIndex);
                        }
                    });
            }

            // Step 2b: Stream meli_price_manager_items via Query Builder.
            if (Schema::hasTable(self::SOURCE_PRICE_MANAGER)) {
                $pmColumns = Schema::getColumnListing(self::SOURCE_PRICE_MANAGER);
                $pmSelect = array_values(array_filter([
                    'id',
                    'meli_account_id',
                    'meli_item_id',
                    'sku',
                    'status',
                    'raw_item',
                    in_array('raw_attributes', $pmColumns, true) ? 'raw_attributes' : null,
                    'last_synced_at',
                ]));

                $pmChunkIndex = 0;
                DB::table(self::SOURCE_PRICE_MANAGER)
                    ->whereNotNull('raw_item')
                    ->select($pmSelect)
                    ->orderBy('id')
                    ->chunkById($chunkSize, function ($rows) use (
                        $newGeneration,
                        &$priceManagerScanned,
                        &$rowsIndexed,
                        &$jsonErrors,
                        &$pmChunkIndex,
                        $afterStageCallback
                    ): void {
                        $pmChunkIndex++;
                        $batch = [];
                        foreach ($rows as $row) {
                            $priceManagerScanned++;
                            foreach ($this->extractEntriesFromPriceManagerRow($row, $newGeneration, $jsonErrors) as $entry) {
                                $batch[] = $entry;
                            }
                        }

                        if ($batch !== []) {
                            $this->insertBatch($batch);
                            $rowsIndexed += count($batch);
                        }

                        $this->touchBuildHeartbeat($newGeneration);

                        if ($afterStageCallback !== null) {
                            $afterStageCallback('price_manager_chunk', $newGeneration, $pmChunkIndex);
                        }
                    });
            }

            // Step 2c: Pre-publish catch-up pass to absorb concurrent writes (including Query Builder
            // / mass updates that bypass Eloquent hooks) and prune any concurrently deleted source rows.
            $catchupCutoff = $rebuildStartedAt->copy()->subSeconds(2);
            $catchupStats = $this->syncModifiedSinceForGenerations([$newGeneration], $catchupCutoff, $chunkSize, $jsonErrors);
            $prunedDeleted = $this->pruneDeletedSourceRowsForGeneration($newGeneration, $chunkSize);

            if ($afterStageCallback !== null) {
                $afterStageCallback('before_publish', $newGeneration, 0);
            }

            $activeRowsCount = (int) DB::table(self::TABLE)
                ->where('generation', $newGeneration)
                ->count();

            // Step 3: Atomic generation publish!
            $publishedAt = $this->databaseSessionNow();
            DB::transaction(function () use (
                $newGeneration,
                $activeRowsCount,
                $publicationsScanned,
                $priceManagerScanned,
                $rebuildStartedAt,
                $publishedAt
            ): void {
                $state = DB::table(self::STATE_TABLE)
                    ->where('id', self::STATE_ROW_ID)
                    ->lockForUpdate()
                    ->first();

                if ($state === null || (int) ($state->building_generation ?? 0) !== $newGeneration) {
                    throw new RuntimeException("La publicación de la generación #{$newGeneration} fue abortada porque otro proceso tomó el control del rebuild.");
                }

                DB::table(self::STATE_TABLE)
                    ->where('id', self::STATE_ROW_ID)
                    ->update([
                        'status' => self::STATUS_READY,
                        'active_generation' => $newGeneration,
                        'building_generation' => null,
                        'last_rebuild_status' => self::REBUILD_STATUS_SUCCEEDED,
                        'build_heartbeat_at' => $publishedAt,
                        'last_completed_at' => $publishedAt,
                        'last_reconciled_at' => $rebuildStartedAt,
                        'active_rows_count' => $activeRowsCount,
                        'publications_scanned' => $publicationsScanned,
                        'price_manager_scanned' => $priceManagerScanned,
                        'last_error' => null,
                        'incremental_sync_errors_count' => 0,
                        'last_incremental_error' => null,
                        'last_incremental_error_at' => null,
                        'updated_at' => $publishedAt,
                    ]);
            });

            // Step 4: Clean up obsolete generations ONLY AFTER atomic publish succeeded.
            $cleanedObsolete = $this->deleteRowsNotInGeneration($newGeneration);

            return [
                'generation' => $newGeneration,
                'previous_generation' => $previousGeneration,
                'publications_scanned' => $publicationsScanned,
                'price_manager_scanned' => $priceManagerScanned,
                'rows_indexed' => $rowsIndexed,
                'active_rows_count' => $activeRowsCount,
                'json_errors' => $jsonErrors,
                'catchup_publications' => $catchupStats['publications_synced'],
                'catchup_price_manager' => $catchupStats['price_manager_synced'],
                'pruned_deleted_rows' => $prunedDeleted,
                'cleaned_obsolete_rows' => $cleanedObsolete,
            ];
        } catch (Throwable $e) {
            $this->handleRebuildFailure($newGeneration, $e);

            throw $e;
        }
    }

    /**
     * Incrementally reconcile source rows modified or deleted since the last reconciliation
     * (capturing Query Builder / mass `update()` writes and recovering from incremental hook errors).
     *
     * @return array{
     *   active_generation: int,
     *   cutoff: ?string,
     *   publications_synced: int,
     *   price_manager_synced: int,
     *   pruned_deleted_rows: int,
     *   active_rows_count: int,
     *   json_errors: int
     * }
     */
    public function reconcileIncremental(int $chunkSize = 200, ?string $since = null): array
    {
        if (! $this->isTableAvailable()) {
            throw new RuntimeException('Las tablas meli_listing_sku_index / meli_listing_sku_index_state no existen.');
        }

        $state = DB::table(self::STATE_TABLE)->where('id', self::STATE_ROW_ID)->first();
        $activeGeneration = $state !== null ? (int) $state->active_generation : 0;

        if ($activeGeneration <= 0) {
            throw new RuntimeException('No existe una generación activa completa. Ejecuta primero una reconstrucción completa sin --incremental.');
        }

        $chunkSize = max(25, min(1000, $chunkSize));

        // Use the database session clock for TIMESTAMP watermarks.
        // This avoids writing PHP UTC wall-clock values into a
        // MariaDB connection configured with a non-UTC timezone.
        $reconcileStartedAt = $this->databaseSessionNow();

        $cutoff = null;

        if ($since !== null && trim($since) !== '') {
            $cutoff = Carbon::parse($since);
        } elseif (! empty($state->last_reconciled_at)) {
            $cutoff = Carbon::parse((string) $state->last_reconciled_at)->subSeconds(5);
        } elseif (! empty($state->last_completed_at)) {
            $cutoff = Carbon::parse((string) $state->last_completed_at)->subSeconds(5);
        }

        // Legacy watermarks may have been written several hours ahead
        // because PHP and MariaDB used different session timezones.
        // Recover by reconciling all source rows in bounded chunks.
        // Explicit --since values remain under operator control.
        if (
            ($since === null || trim($since) === '')
            && $cutoff !== null
            && $cutoff->gt($reconcileStartedAt->copy()->addSeconds(5))
        ) {
            Log::warning(
                '[Purchasing][MeliPhotos] Future reconciliation watermark detected; running full reconciliation',
                [
                    'stored_cutoff' => $cutoff->toDateTimeString(),
                    'database_now' => $reconcileStartedAt->toDateTimeString(),
                ]
            );

            $cutoff = null;
        }

        $generations = $this->targetGenerations();
        $jsonErrors = 0;
        $this->seenJsonErrors = [];

        $syncStats = $this->syncModifiedSinceForGenerations($generations, $cutoff, $chunkSize, $jsonErrors);
        $prunedDeleted = $this->pruneDeletedSourceRowsForGeneration($activeGeneration, $chunkSize);

        $activeRowsCount = (int) DB::table(self::TABLE)
            ->where('generation', $activeGeneration)
            ->count();

        DB::table(self::STATE_TABLE)
            ->where('id', self::STATE_ROW_ID)
            ->update([
                'active_rows_count' => $activeRowsCount,
                'last_reconciled_at' => $reconcileStartedAt,
                'incremental_sync_errors_count' => 0,
                'last_incremental_error' => null,
                'last_incremental_error_at' => null,
                'updated_at' => $reconcileStartedAt,
            ]);

        return [
            'active_generation' => $activeGeneration,
            'cutoff' => $cutoff?->toDateTimeString(),
            'publications_synced' => $syncStats['publications_synced'],
            'price_manager_synced' => $syncStats['price_manager_synced'],
            'pruned_deleted_rows' => $prunedDeleted,
            'active_rows_count' => $activeRowsCount,
            'json_errors' => $jsonErrors,
        ];
    }

    /**
     * Strictly read-only diagnostic snapshot of index health, generations, row counts,
     * rebuild errors, and incremental sync freshness/drift.
     *
     * @return array<string, mixed>
     */
    public function getDiagnosticStatus(): array
    {
        $hasIndexTable = Schema::hasTable(self::TABLE);
        $hasStateTable = Schema::hasTable(self::STATE_TABLE);
        $hasPubTable = Schema::hasTable(self::SOURCE_PUBLICATIONS);
        $hasPmTable = Schema::hasTable(self::SOURCE_PRICE_MANAGER);

        $livePubTotal = $hasPubTable ? (int) DB::table(self::SOURCE_PUBLICATIONS)->count() : 0;
        $livePmTotal = $hasPmTable ? (int) DB::table(self::SOURCE_PRICE_MANAGER)->whereNotNull('raw_item')->count() : 0;

        if (! $hasIndexTable || ! $hasStateTable) {
            return [
                'tables_available' => false,
                'index_table_exists' => $hasIndexTable,
                'state_table_exists' => $hasStateTable,
                'status' => 'missing_tables',
                'effective_health' => 'missing_tables',
                'usable_by_resolver' => false,
                'active_generation' => 0,
                'building_generation' => null,
                'build_is_stale' => false,
                'last_rebuild_status' => null,
                'build_started_at' => null,
                'build_heartbeat_at' => null,
                'last_completed_at' => null,
                'last_reconciled_at' => null,
                'last_failed_at' => null,
                'failed_rebuilds_count' => 0,
                'last_error' => null,
                'counts' => [
                    'recorded_active_rows' => 0,
                    'live_active_rows' => 0,
                    'orphan_rows' => 0,
                    'publications_scanned' => 0,
                    'price_manager_scanned' => 0,
                    'live_publications_total' => $livePubTotal,
                    'live_price_manager_total' => $livePmTotal,
                ],
                'incremental_sync' => [
                    'errors_count' => 0,
                    'last_error' => null,
                    'last_error_at' => null,
                    'latest_index_updated_at' => null,
                    'publications_updated_since_reconcile' => null,
                    'price_manager_updated_since_reconcile' => null,
                    'drift_suspected' => false,
                ],
            ];
        }

        $state = DB::table(self::STATE_TABLE)->where('id', self::STATE_ROW_ID)->first();

        $status = $state !== null ? (string) $state->status : self::STATUS_UNINITIALIZED;
        $activeGeneration = $state !== null ? (int) $state->active_generation : 0;
        $buildingGeneration = ($state !== null && $state->building_generation !== null) ? (int) $state->building_generation : null;
        $lastRebuildStatus = $state !== null ? $state->last_rebuild_status : null;
        $recordedActiveRows = $state !== null ? (int) $state->active_rows_count : 0;
        $failedRebuildsCount = $state !== null ? (int) $state->failed_rebuilds_count : 0;
        $incrementalErrorsCount = $state !== null ? (int) $state->incremental_sync_errors_count : 0;

        $buildIsStale = false;
        if ($buildingGeneration !== null) {
            $heartbeatRaw = $state->build_heartbeat_at ?? $state->build_started_at;
            if ($heartbeatRaw !== null) {
                $buildIsStale = Carbon::parse((string) $heartbeatRaw)->lt($this->databaseSessionNow()->subMinutes(self::STALE_BUILD_MINUTES));
            }
        }

        $liveActiveRows = $activeGeneration > 0
            ? (int) DB::table(self::TABLE)->where('generation', $activeGeneration)->count()
            : 0;
        $orphanRows = (int) DB::table(self::TABLE)
            ->when($activeGeneration > 0, fn ($q) => $q->where('generation', '!=', $activeGeneration))
            ->count();

        $latestIndexUpdatedAt = $activeGeneration > 0
            ? DB::table(self::TABLE)->where('generation', $activeGeneration)->max('updated_at')
            : null;

        $reconcileAnchor = $state !== null ? ($state->last_reconciled_at ?? $state->last_completed_at) : null;
        $pubsUpdatedSinceReconcile = null;
        $pmUpdatedSinceReconcile = null;

        if ($reconcileAnchor !== null) {
            if ($hasPubTable && Schema::hasColumn(self::SOURCE_PUBLICATIONS, 'updated_at')) {
                $pubsUpdatedSinceReconcile = (int) DB::table(self::SOURCE_PUBLICATIONS)
                    ->where('updated_at', '>', $reconcileAnchor)
                    ->count();
            }
            if ($hasPmTable && Schema::hasColumn(self::SOURCE_PRICE_MANAGER, 'updated_at')) {
                $pmUpdatedSinceReconcile = (int) DB::table(self::SOURCE_PRICE_MANAGER)
                    ->whereNotNull('raw_item')
                    ->where('updated_at', '>', $reconcileAnchor)
                    ->count();
            }
        }

        $usableByResolver = $activeGeneration > 0;
        $integrityMismatch = $usableByResolver && $recordedActiveRows > 0 && $liveActiveRows === 0;

        $effectiveHealth = match (true) {
            $activeGeneration === 0 && $buildingGeneration !== null && $buildIsStale => 'interrupted_initial_build',
            $activeGeneration === 0 && $buildingGeneration !== null => 'building_initial',
            $activeGeneration === 0 && $status === self::STATUS_FAILED => 'failed',
            $activeGeneration === 0 => 'uninitialized',
            $integrityMismatch => 'ready_with_integrity_mismatch',
            $buildingGeneration !== null && $buildIsStale => 'ready_with_interrupted_rebuild',
            $buildingGeneration !== null => 'rebuilding_with_active',
            $lastRebuildStatus === self::REBUILD_STATUS_FAILED => 'ready_with_failed_rebuild',
            $incrementalErrorsCount > 0 => 'ready_with_incremental_errors',
            default => 'ready',
        };

        $driftSuspected = $incrementalErrorsCount > 0
            || ($orphanRows > 0 && $buildingGeneration === null)
            || $integrityMismatch;

        return [
            'tables_available' => true,
            'index_table_exists' => true,
            'state_table_exists' => true,
            'status' => $status,
            'effective_health' => $effectiveHealth,
            'usable_by_resolver' => $usableByResolver,
            'active_generation' => $activeGeneration,
            'building_generation' => $buildingGeneration,
            'build_is_stale' => $buildIsStale,
            'last_rebuild_status' => $lastRebuildStatus,
            'build_started_at' => $state?->build_started_at,
            'build_heartbeat_at' => $state?->build_heartbeat_at,
            'last_completed_at' => $state?->last_completed_at,
            'last_reconciled_at' => $state?->last_reconciled_at,
            'last_failed_at' => $state?->last_failed_at,
            'failed_rebuilds_count' => $failedRebuildsCount,
            'last_error' => $state?->last_error,
            'counts' => [
                'recorded_active_rows' => $recordedActiveRows,
                'live_active_rows' => $liveActiveRows,
                'orphan_rows' => $orphanRows,
                'publications_scanned' => $state !== null ? (int) $state->publications_scanned : 0,
                'price_manager_scanned' => $state !== null ? (int) $state->price_manager_scanned : 0,
                'live_publications_total' => $livePubTotal,
                'live_price_manager_total' => $livePmTotal,
            ],
            'incremental_sync' => [
                'errors_count' => $incrementalErrorsCount,
                'last_error' => $state?->last_incremental_error,
                'last_error_at' => $state?->last_incremental_error_at,
                'latest_index_updated_at' => $latestIndexUpdatedAt,
                'publications_updated_since_reconcile' => $pubsUpdatedSinceReconcile,
                'price_manager_updated_since_reconcile' => $pmUpdatedSinceReconcile,
                'drift_suspected' => $driftSuspected,
            ],
        ];
    }

    /**
     * Non-blocking hook helper called from Eloquent model events.
     * Never breaks ML syncs, but NEVER silently swallows errors when the index tables exist:
     * logs a structured warning and records the error in `meli_listing_sku_index_state`.
     */
    public static function syncSafelyFromModel(MeliPublication|MeliPriceManagerItem $model): void
    {
        try {
            $service = app(self::class);
            if ($model instanceof MeliPublication) {
                $service->syncFromPublication($model);
            } else {
                $service->syncFromPriceManagerItem($model);
            }
        } catch (Throwable $e) {
            self::reportHookError('sync', $model, $e);
        }
    }

    public static function deleteSafelyForModel(MeliPublication|MeliPriceManagerItem $model): void
    {
        try {
            $table = $model instanceof MeliPublication ? self::SOURCE_PUBLICATIONS : self::SOURCE_PRICE_MANAGER;
            app(self::class)->deleteForSource($table, (int) $model->getKey());
        } catch (Throwable $e) {
            self::reportHookError('delete', $model, $e);
        }
    }

    private static function reportHookError(string $operation, MeliPublication|MeliPriceManagerItem $model, Throwable $e): void
    {
        $sourceTable = $model instanceof MeliPublication ? self::SOURCE_PUBLICATIONS : self::SOURCE_PRICE_MANAGER;

        Log::warning('[Purchasing][MeliPhotos] Auxiliary SKU index incremental hook failed', [
            'operation' => $operation,
            'source_table' => $sourceTable,
            'source_row_id' => (int) $model->getKey(),
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);

        try {
            if (! Schema::hasTable(self::STATE_TABLE)) {
                return;
            }

            $now = now();
            $exists = DB::table(self::STATE_TABLE)->where('id', self::STATE_ROW_ID)->exists();
            if (! $exists) {
                return;
            }

            DB::table(self::STATE_TABLE)
                ->where('id', self::STATE_ROW_ID)
                ->update([
                    'incremental_sync_errors_count' => DB::raw('incremental_sync_errors_count + 1'),
                    'last_incremental_error' => mb_substr("[{$operation} {$sourceTable}#{$model->getKey()}] {$e->getMessage()}", 0, 1000),
                    'last_incremental_error_at' => $now,
                    'updated_at' => $now,
                ]);
        } catch (Throwable) {
            // Read-only DB user or unavailable state table; warning already logged above.
        }
    }

    /**
     * Atomically allocate and claim a new candidate generation without modifying `active_generation`.
     *
     * @return array{0: int, 1: int} [newGeneration, previousActiveGeneration]
     */
    private function claimCandidateGeneration(Carbon $startedAt, bool $forceTakeover): array
    {
        return DB::transaction(function () use ($startedAt, $forceTakeover): array {
            $now = $startedAt->toDateTimeString();

            DB::table(self::STATE_TABLE)->insertOrIgnore([
                'id' => self::STATE_ROW_ID,
                'status' => self::STATUS_UNINITIALIZED,
                'active_generation' => 0,
                'active_rows_count' => 0,
                'publications_scanned' => 0,
                'price_manager_scanned' => 0,
                'failed_rebuilds_count' => 0,
                'incremental_sync_errors_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $state = DB::table(self::STATE_TABLE)
                ->where('id', self::STATE_ROW_ID)
                ->lockForUpdate()
                ->first();

            $activeGeneration = (int) ($state->active_generation ?? 0);
            $buildingGeneration = $state->building_generation !== null ? (int) $state->building_generation : null;

            if ($buildingGeneration !== null) {
                $heartbeatRaw = $state->build_heartbeat_at ?? $state->build_started_at;
                $isStale = $heartbeatRaw === null
                    || Carbon::parse((string) $heartbeatRaw)->lt($this->databaseSessionNow()->subMinutes(self::STALE_BUILD_MINUTES));

                if (! $isStale && ! $forceTakeover) {
                    throw new RuntimeException(
                        "Ya existe una reconstrucción activa del índice (generación #{$buildingGeneration}). Espera a que termine o usa --force-takeover si el proceso anterior murió."
                    );
                }
            }

            $maxInTable = (int) (DB::table(self::TABLE)->max('generation') ?? 0);
            $newGeneration = max($activeGeneration, (int) ($buildingGeneration ?? 0), $maxInTable) + 1;

            DB::table(self::STATE_TABLE)
                ->where('id', self::STATE_ROW_ID)
                ->update([
                    'status' => self::STATUS_BUILDING,
                    'building_generation' => $newGeneration,
                    'last_rebuild_status' => self::REBUILD_STATUS_RUNNING,
                    'build_started_at' => $startedAt,
                    'build_heartbeat_at' => $startedAt,
                    'updated_at' => $startedAt,
                ]);

            return [$newGeneration, $activeGeneration];
        });
    }

    private function touchBuildHeartbeat(int $buildingGeneration): void
    {
        $heartbeatAt = $this->databaseSessionNow();

        DB::table(self::STATE_TABLE)
            ->where('id', self::STATE_ROW_ID)
            ->where('building_generation', $buildingGeneration)
            ->update([
                'build_heartbeat_at' => $heartbeatAt,
                'updated_at' => $heartbeatAt,
            ]);
    }

    private function handleRebuildFailure(int $failedGeneration, Throwable $e): void
    {
        try {
            $this->deleteGenerationRows($failedGeneration);
        } catch (Throwable) {
            // Best-effort cleanup of failed candidate rows; active_generation remains untouched.
        }

        $preservedGeneration = 0;

        try {
            $now = $this->databaseSessionNow();
            DB::transaction(function () use ($failedGeneration, $e, $now, &$preservedGeneration): void {
                $state = DB::table(self::STATE_TABLE)
                    ->where('id', self::STATE_ROW_ID)
                    ->lockForUpdate()
                    ->first();

                if ($state === null) {
                    return;
                }

                $preservedGeneration = (int) $state->active_generation;

                // Only clear building_generation if this failed build still owns it.
                if ($state->building_generation !== null && (int) $state->building_generation !== $failedGeneration) {
                    return;
                }

                DB::table(self::STATE_TABLE)
                    ->where('id', self::STATE_ROW_ID)
                    ->update([
                        'status' => $preservedGeneration > 0 ? self::STATUS_READY : self::STATUS_FAILED,
                        'building_generation' => null,
                        'last_rebuild_status' => self::REBUILD_STATUS_FAILED,
                        'last_failed_at' => $now,
                        'failed_rebuilds_count' => ((int) $state->failed_rebuilds_count) + 1,
                        'last_error' => mb_substr($e->getMessage(), 0, 1000),
                        'updated_at' => $now,
                    ]);
            });
        } catch (Throwable) {
            // If DB itself is unavailable, log below.
        }

        Log::error('[Purchasing][MeliPhotos] SKU index rebuild failed', [
            'failed_generation' => $failedGeneration,
            'preserved_active_generation' => $preservedGeneration,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Return active and/or building generations that incremental hooks should keep in sync.
     *
     * @return list<int>
     */
    private function targetGenerations(): array
    {
        $state = DB::table(self::STATE_TABLE)
            ->where('id', self::STATE_ROW_ID)
            ->first(['active_generation', 'building_generation']);

        if ($state === null) {
            return [];
        }

        $generations = [];
        $active = (int) ($state->active_generation ?? 0);
        if ($active > 0) {
            $generations[$active] = $active;
        }

        $building = $state->building_generation !== null ? (int) $state->building_generation : 0;
        if ($building > 0) {
            $generations[$building] = $building;
        }

        return array_values($generations);
    }

    /**
     * Re-index rows updated at or after `$cutoff` into all `$generations` (captures both Eloquent
     * and Query Builder / mass `update()` writes).
     *
     * @param  list<int>  $generations
     * @return array{publications_synced: int, price_manager_synced: int}
     */
    private function syncModifiedSinceForGenerations(
        array $generations,
        ?Carbon $cutoff,
        int $chunkSize,
        int &$jsonErrors,
    ): array {
        $publicationsSynced = 0;
        $priceManagerSynced = 0;

        if ($generations === []) {
            return ['publications_synced' => 0, 'price_manager_synced' => 0];
        }

        if (Schema::hasTable(self::SOURCE_PUBLICATIONS)) {
            $pubColumns = Schema::getColumnListing(self::SOURCE_PUBLICATIONS);
            $hasUpdatedAt = in_array('updated_at', $pubColumns, true);
            $pubSelect = array_values(array_filter([
                'id',
                'meli_account_id',
                'sku',
                'mlm',
                'status',
                'last_sync_at',
                'raw',
                in_array('is_current', $pubColumns, true) ? 'is_current' : null,
            ]));

            $query = DB::table(self::SOURCE_PUBLICATIONS)->select($pubSelect)->orderBy('id');
            if ($cutoff !== null && $hasUpdatedAt) {
                $query->where('updated_at', '>=', $cutoff->toDateTimeString());
            }

            $query->chunkById($chunkSize, function ($rows) use ($generations, &$publicationsSynced, &$jsonErrors): void {
                $rowIds = [];
                foreach ($rows as $row) {
                    $publicationsSynced++;
                    $rowIds[] = (int) $row->id;
                }

                foreach ($generations as $generation) {
                    $batch = [];
                    foreach ($rows as $row) {
                        foreach ($this->extractEntriesFromPublicationRow($row, $generation, $jsonErrors) as $entry) {
                            $batch[] = $entry;
                        }
                    }

                    $this->replaceSourceChunkForGeneration(
                        $generation,
                        self::SOURCE_PUBLICATIONS,
                        $rowIds,
                        $batch,
                    );
                }
            });
        }

        if (Schema::hasTable(self::SOURCE_PRICE_MANAGER)) {
            $pmColumns = Schema::getColumnListing(self::SOURCE_PRICE_MANAGER);
            $hasUpdatedAt = in_array('updated_at', $pmColumns, true);
            $pmSelect = array_values(array_filter([
                'id',
                'meli_account_id',
                'meli_item_id',
                'sku',
                'status',
                'raw_item',
                in_array('raw_attributes', $pmColumns, true) ? 'raw_attributes' : null,
                'last_synced_at',
            ]));

            $query = DB::table(self::SOURCE_PRICE_MANAGER)
                ->whereNotNull('raw_item')
                ->select($pmSelect)
                ->orderBy('id');
            if ($cutoff !== null && $hasUpdatedAt) {
                $query->where('updated_at', '>=', $cutoff->toDateTimeString());
            }

            $query->chunkById($chunkSize, function ($rows) use ($generations, &$priceManagerSynced, &$jsonErrors): void {
                $rowIds = [];
                foreach ($rows as $row) {
                    $priceManagerSynced++;
                    $rowIds[] = (int) $row->id;
                }

                foreach ($generations as $generation) {
                    $batch = [];
                    foreach ($rows as $row) {
                        foreach ($this->extractEntriesFromPriceManagerRow($row, $generation, $jsonErrors) as $entry) {
                            $batch[] = $entry;
                        }
                    }

                    $this->replaceSourceChunkForGeneration(
                        $generation,
                        self::SOURCE_PRICE_MANAGER,
                        $rowIds,
                        $batch,
                    );
                }
            });
        }

        return [
            'publications_synced' => $publicationsSynced,
            'price_manager_synced' => $priceManagerSynced,
        ];
    }

    /**
     * Remove index rows in `$generation` whose source row no longer exists in `meli_publications`
     * or `meli_price_manager_items` (handles raw SQL / Query Builder deletes).
     */
    private function pruneDeletedSourceRowsForGeneration(int $generation, int $chunkSize): int
    {
        $prunedTotal = 0;

        foreach ([self::SOURCE_PUBLICATIONS, self::SOURCE_PRICE_MANAGER] as $sourceTable) {
            if (! Schema::hasTable($sourceTable)) {
                continue;
            }

            $lastSourceId = 0;
            while (true) {
                $indexedSourceIds = DB::table(self::TABLE)
                    ->where('generation', $generation)
                    ->where('source_table', $sourceTable)
                    ->where('source_row_id', '>', $lastSourceId)
                    ->distinct()
                    ->orderBy('source_row_id')
                    ->limit($chunkSize)
                    ->pluck('source_row_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();

                if ($indexedSourceIds === []) {
                    break;
                }

                $lastSourceId = end($indexedSourceIds);

                $existingIds = DB::table($sourceTable)
                    ->whereIn('id', $indexedSourceIds)
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();

                $missingIds = array_values(array_diff($indexedSourceIds, $existingIds));
                if ($missingIds !== []) {
                    $prunedTotal += (int) DB::table(self::TABLE)
                        ->where('generation', $generation)
                        ->where('source_table', $sourceTable)
                        ->whereIn('source_row_id', $missingIds)
                        ->delete();
                }
            }
        }

        return $prunedTotal;
    }

    /**
     * Delete all rows belonging to a specific generation in bounded primary-key batches
     * (compatible with both MariaDB and SQLite, avoiding large undo logs / locks).
     */
    private function deleteGenerationRows(int $generation): int
    {
        $deleted = 0;

        while (true) {
            $ids = DB::table(self::TABLE)
                ->where('generation', $generation)
                ->orderBy('id')
                ->limit(self::DELETE_BATCH_SIZE)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted += (int) DB::table(self::TABLE)->whereIn('id', $ids)->delete();
        }

        return $deleted;
    }

    /**
     * Delete all rows NOT belonging to `$keepGeneration` in bounded primary-key batches.
     */
    private function deleteRowsNotInGeneration(int $keepGeneration): int
    {
        $deleted = 0;

        while (true) {
            $ids = DB::table(self::TABLE)
                ->where('generation', '!=', $keepGeneration)
                ->orderBy('id')
                ->limit(self::DELETE_BATCH_SIZE)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted += (int) DB::table(self::TABLE)->whereIn('id', $ids)->delete();
        }

        return $deleted;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractEntriesFromPublicationRow(object $row, int $generation, int &$jsonErrors): array
    {
        $mlm = strtoupper(trim((string) ($row->mlm ?? '')));
        if ($mlm === '') {
            return [];
        }

        $now = now()->toDateTimeString();
        $rawDecoded = $this->decodeJsonField($row->raw ?? null, self::SOURCE_PUBLICATIONS, (int) $row->id, $mlm, $jsonErrors);
        $item = MeliPublication::itemArrayFromRaw($rawDecoded);
        $isCurrent = property_exists($row, 'is_current') ? (bool) $row->is_current : true;
        $syncedAt = ! empty($row->last_sync_at) ? (string) $row->last_sync_at : null;
        $accountId = is_numeric($row->meli_account_id ?? null) ? (int) $row->meli_account_id : null;
        $status = isset($row->status) ? (string) $row->status : null;

        $dedup = [];

        $colSku = trim((string) ($row->sku ?? ''));
        if ($colSku !== '') {
            $this->addEntry(
                $dedup,
                $generation,
                self::SOURCE_PUBLICATIONS,
                (int) $row->id,
                $accountId,
                $mlm,
                null,
                $colSku,
                $status,
                $isCurrent,
                $syncedAt,
                $now,
            );
        }

        if ($item !== []) {
            $itemSku = $this->sellerSku($item);
            if ($itemSku !== '') {
                $this->addEntry(
                    $dedup,
                    $generation,
                    self::SOURCE_PUBLICATIONS,
                    (int) $row->id,
                    $accountId,
                    $mlm,
                    null,
                    $itemSku,
                    $status,
                    $isCurrent,
                    $syncedAt,
                    $now,
                );
            }

            foreach ((array) ($item['variations'] ?? []) as $variation) {
                if (! is_array($variation)) {
                    continue;
                }
                $varSku = $this->sellerSku($variation);
                if ($varSku === '') {
                    continue;
                }
                $varId = isset($variation['id']) ? trim((string) $variation['id']) : null;
                $varId = ($varId === '') ? null : $varId;

                $this->addEntry(
                    $dedup,
                    $generation,
                    self::SOURCE_PUBLICATIONS,
                    (int) $row->id,
                    $accountId,
                    $mlm,
                    $varId,
                    $varSku,
                    $status,
                    $isCurrent,
                    $syncedAt,
                    $now,
                );
            }
        }

        return array_values($dedup);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractEntriesFromPriceManagerRow(object $row, int $generation, int &$jsonErrors): array
    {
        $mlm = strtoupper(trim((string) ($row->meli_item_id ?? '')));
        if ($mlm === '') {
            return [];
        }

        $rawItem = $this->decodeJsonField($row->raw_item ?? null, self::SOURCE_PRICE_MANAGER, (int) $row->id, $mlm, $jsonErrors);
        if (! is_array($rawItem) || $rawItem === [] || ! empty($rawItem['truncated'])) {
            return [];
        }

        if (empty($rawItem['attributes']) && property_exists($row, 'raw_attributes') && $row->raw_attributes !== null) {
            $rawAttrs = $this->decodeJsonField($row->raw_attributes, self::SOURCE_PRICE_MANAGER, (int) $row->id, $mlm, $jsonErrors);
            if (is_array($rawAttrs) && $rawAttrs !== []) {
                $rawItem['attributes'] = $rawAttrs;
            }
        }

        $now = now()->toDateTimeString();
        $syncedAt = ! empty($row->last_synced_at) ? (string) $row->last_synced_at : null;
        $accountId = is_numeric($row->meli_account_id ?? null) ? (int) $row->meli_account_id : null;
        $status = isset($row->status) ? (string) $row->status : null;

        $dedup = [];

        $itemSku = $this->sellerSku($rawItem);
        if ($itemSku !== '') {
            $this->addEntry(
                $dedup,
                $generation,
                self::SOURCE_PRICE_MANAGER,
                (int) $row->id,
                $accountId,
                $mlm,
                null,
                $itemSku,
                $status,
                true,
                $syncedAt,
                $now,
            );
        }

        foreach ((array) ($rawItem['variations'] ?? []) as $variation) {
            if (! is_array($variation)) {
                continue;
            }
            $varSku = $this->sellerSku($variation);
            if ($varSku === '') {
                continue;
            }
            $varId = isset($variation['id']) ? trim((string) $variation['id']) : null;
            $varId = ($varId === '') ? null : $varId;

            $this->addEntry(
                $dedup,
                $generation,
                self::SOURCE_PRICE_MANAGER,
                (int) $row->id,
                $accountId,
                $mlm,
                $varId,
                $varSku,
                $status,
                true,
                $syncedAt,
                $now,
            );
        }

        return array_values($dedup);
    }

    /**
     * Safely decode a JSON string or pass through an already-decoded array, logging and counting
     * corrupted JSON payloads instead of crashing model hydration.
     *
     * @return ?array<string, mixed>
     */
    private function decodeJsonField(mixed $value, string $sourceTable, int $rowId, string $mlm, int &$jsonErrors): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errKey = "{$sourceTable}:{$rowId}";
            if (! isset($this->seenJsonErrors[$errKey])) {
                $this->seenJsonErrors[$errKey] = true;
                $jsonErrors++;
                Log::warning('[Purchasing][MeliPhotos] Malformed JSON payload encountered during SKU index extraction', [
                    'source_table' => $sourceTable,
                    'source_row_id' => $rowId,
                    'mlm' => $mlm,
                    'json_error' => json_last_error_msg(),
                ]);
            }

            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $dedup
     */
    private function addEntry(
        array &$dedup,
        int $generation,
        string $sourceTable,
        int $sourceRowId,
        ?int $meliAccountId,
        string $mlm,
        ?string $variationId,
        string $sku,
        ?string $listingStatus,
        bool $isCurrent,
        ?string $syncedAt,
        string $now,
    ): void {
        $skuTrimmed = mb_substr(trim($sku), 0, 191);
        $skuNormalized = strtoupper($skuTrimmed);
        if ($skuNormalized === '') {
            return;
        }

        $variationKey = $variationId !== null ? mb_substr($variationId, 0, 64) : '';
        $key = "{$generation}|{$sourceTable}|{$sourceRowId}|{$variationKey}|{$skuNormalized}";

        $dedup[$key] = [
            'generation' => $generation,
            'source_table' => $sourceTable,
            'source_row_id' => $sourceRowId,
            'meli_account_id' => $meliAccountId,
            'mlm' => mb_substr($mlm, 0, 40),
            'variation_id' => $variationId !== null ? mb_substr($variationId, 0, 64) : null,
            'variation_key' => $variationKey,
            'sku' => $skuTrimmed,
            'sku_normalized' => $skuNormalized,
            'listing_status' => $listingStatus !== null ? mb_substr(trim($listingStatus), 0, 32) : null,
            'is_current' => $isCurrent,
            'synced_at' => $syncedAt,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  list<int>  $sourceRowIds
     * @param  list<array<string, mixed>>  $entries
     */
    private function replaceSourceChunkForGeneration(int $generation, string $sourceTable, array $sourceRowIds, array $entries): int
    {
        if ($sourceRowIds === []) {
            return 0;
        }

        return DB::transaction(function () use ($generation, $sourceTable, $sourceRowIds, $entries): int {
            foreach (array_chunk($sourceRowIds, 500) as $idChunk) {
                DB::table(self::TABLE)
                    ->where('generation', $generation)
                    ->where('source_table', $sourceTable)
                    ->whereIn('source_row_id', $idChunk)
                    ->delete();
            }

            if ($entries === []) {
                return 0;
            }

            $this->insertBatch($entries);

            return count($entries);
        });
    }

    /** @param list<array<string, mixed>> $entries */
    private function replaceSourceRowsForGeneration(int $generation, string $sourceTable, int $sourceRowId, array $entries): int
    {
        return DB::transaction(function () use ($generation, $sourceTable, $sourceRowId, $entries): int {
            DB::table(self::TABLE)
                ->where('generation', $generation)
                ->where('source_table', $sourceTable)
                ->where('source_row_id', $sourceRowId)
                ->delete();

            if ($entries === []) {
                return 0;
            }

            $this->insertBatch($entries);

            return count($entries);
        });
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertBatch(array $rows): void
    {
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table(self::TABLE)->upsert(
                $chunk,
                ['generation', 'source_table', 'source_row_id', 'variation_key', 'sku_normalized'],
                ['meli_account_id', 'mlm', 'variation_id', 'sku', 'listing_status', 'is_current', 'synced_at', 'updated_at'],
            );
        }
    }

    /** @param array<string, mixed> $data */
    private function sellerSku(array $data): string
    {
        $direct = trim((string) ($data['seller_custom_field'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }

        foreach (['SELLER_SKU', 'SKU'] as $attributeId) {
            foreach ((array) ($data['attributes'] ?? []) as $attribute) {
                if (! is_array($attribute) || strcasecmp((string) ($attribute['id'] ?? ''), $attributeId) !== 0) {
                    continue;
                }

                $value = $attribute['value_name']
                    ?? $attribute['value_id']
                    ?? data_get($attribute, 'values.0.name')
                    ?? data_get($attribute, 'values.0.id');
                $trimmed = trim((string) $value);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }

        return '';
    }
}
