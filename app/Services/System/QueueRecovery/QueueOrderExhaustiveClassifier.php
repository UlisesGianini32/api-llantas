<?php

declare(strict_types=1);

namespace App\Services\System\QueueRecovery;

use App\Services\InventoryMeliOrderReservationPolicy;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Throwable;

/**
 * Pure, strictly read-only exhaustive classifier for Mercado Libre orders during R2-DIAG.
 *
 * Guarantees:
 * - Zero database queries (operates strictly on batch-preloaded context — zero N+1).
 * - Assigns EXACTLY ONE mutually exclusive primary classification from the 7 canonical categories
 *   using strict risk-first priority (1 > 2 > 3 > 4 > 5 > 6 > 7).
 * - Evaluates ALL anomaly checks unconditionally (never gated on `$hasReservationEnabledLink`).
 * - Never treats absence of anomalies as proof of consistency (`consistent_no_action_needed`
 *   requires explicit positive evidence; anything unprovable falls back to `insufficient_evidence`).
 * - Never includes credentials, tokens, or buyer PII in output records.
 * - Never authorizes automatic reconciliation execution or job deletion.
 */
class QueueOrderExhaustiveClassifier
{
    public const PRIMARY_PENDING_HUMAN_REVIEW = 'pending_human_review';

    public const PRIMARY_AMBIGUOUS_ENTITIES = 'ambiguous_entities';

    public const PRIMARY_RECONSTRUCTIBLE_CANDIDATES = 'reconstructible_candidates';

    public const PRIMARY_CONSISTENT_NO_ACTION_NEEDED = 'consistent_no_action_needed';

    public const PRIMARY_OUT_OF_SCOPE_NO_LINK = 'out_of_scope_no_reservation_link';

    public const PRIMARY_OUT_OF_SCOPE_PRE_CUTOVER_OR_IGNORED = 'out_of_scope_pre_cutover_or_ignored_status';

    public const PRIMARY_INSUFFICIENT_EVIDENCE = 'insufficient_evidence';

    /** @var list<string> Strict priority order (highest risk first). */
    public const PRIMARY_CATEGORIES = [
        self::PRIMARY_PENDING_HUMAN_REVIEW,
        self::PRIMARY_AMBIGUOUS_ENTITIES,
        self::PRIMARY_RECONSTRUCTIBLE_CANDIDATES,
        self::PRIMARY_CONSISTENT_NO_ACTION_NEEDED,
        self::PRIMARY_OUT_OF_SCOPE_NO_LINK,
        self::PRIMARY_OUT_OF_SCOPE_PRE_CUTOVER_OR_IGNORED,
        self::PRIMARY_INSUFFICIENT_EVIDENCE,
    ];

    /** @var list<string> */
    public const FULFILLED_SHIPPING_STATUSES = ['shipped', 'delivered', 'in_transit'];

    /** @var list<string> */
    private const BLOCKING_MANUAL_MOVEMENT_TYPES = ['ADJUSTMENT_OUT', 'DAMAGE'];

    public function __construct(
        private readonly InventoryMeliOrderReservationPolicy $policy = new InventoryMeliOrderReservationPolicy,
    ) {}

    /**
     * Classifies an order referenced by a manifest that does not exist in `meli_orders`.
     *
     * @param  list<int>  $associatedJobIds
     * @param  list<int>  $associatedUnreservedJobIds
     * @param  list<int>  $associatedReservedJobIds
     * @param  array<string, mixed>|null  $liveQueueVerification
     * @return array<string, mixed>
     */
    public function classifyMissingOrder(
        int $localOrderId,
        array $associatedJobIds,
        int $associatedJobsCount,
        bool $associatedJobIdsTruncated,
        int $reservedJobsCount,
        bool $r3TablePresent,
        array $associatedUnreservedJobIds = [],
        int $associatedUnreservedJobsCount = 0,
        bool $associatedUnreservedJobIdsTruncated = false,
        array $associatedReservedJobIds = [],
        bool $associatedReservedJobIdsTruncated = false,
        bool $manifestMetadataConflict = false,
        ?array $liveQueueVerification = null,
    ): array {
        $reasonCodes = ['order_missing_from_db'];
        $secondaryAnomalies = ['missing_orders_in_manifest'];
        if ($reservedJobsCount > 0) {
            $reasonCodes[] = 'reserved_jobs_detected_for_order';
            $reasonCodes[] = $associatedUnreservedJobsCount === 0
                ? 'manifest_reserved_jobs_only'
                : 'manifest_reserved_jobs_detected';
            $secondaryAnomalies[] = 'reserved_jobs_detected_for_order';
        }
        if ($manifestMetadataConflict) {
            $reasonCodes[] = 'manifest_metadata_conflict';
            $secondaryAnomalies[] = 'manifest_metadata_conflict';
        }

        $missingEvidence = [
            'meli_orders_row_missing',
            'meli_order_items_unavailable',
            'allocations_and_reservations_unverifiable_without_order',
        ];
        if (! $r3TablePresent) {
            $missingEvidence[] = 'r3_durable_state_table_absent';
        }

        return [
            'entity' => [
                'entity_type' => 'meli_order',
                'local_order_id' => $localOrderId,
                'remote_order_id' => null,
                'meli_account_id' => null,
                'associated_job_ids' => $associatedJobIds,
                'associated_job_ids_truncated' => $associatedJobIdsTruncated,
                'associated_job_ids_sample_limit' => QueueManifestStreamReader::MAX_JOB_ID_SAMPLE_PER_ORDER,
                'associated_jobs_count' => $associatedJobsCount,
                'associated_unreserved_job_ids' => $associatedUnreservedJobIds,
                'associated_unreserved_job_ids_truncated' => $associatedUnreservedJobIdsTruncated,
                'associated_unreserved_jobs_count' => $associatedUnreservedJobsCount,
                'associated_reserved_job_ids' => $associatedReservedJobIds,
                'associated_reserved_job_ids_truncated' => $associatedReservedJobIdsTruncated,
                'associated_reserved_jobs_count' => $reservedJobsCount,
                'order_status' => null,
                'shipping_status' => null,
                'allocation_status' => 'UNKNOWN_ORDER_MISSING',
                'reservation_status' => 'UNKNOWN_ORDER_MISSING',
                'primary_classification' => self::PRIMARY_PENDING_HUMAN_REVIEW,
                'reason_codes' => array_values(array_unique($reasonCodes)),
                'secondary_anomalies' => array_values(array_unique($secondaryAnomalies)),
                'available_evidence' => [
                    'exists_in_meli_orders' => false,
                    'reserved_jobs_count' => $reservedJobsCount,
                    'manifest_queue_state' => [
                        'manifest_unreserved_jobs_count' => $associatedUnreservedJobsCount,
                        'manifest_reserved_jobs_count' => $reservedJobsCount,
                        'reserved_at_reflects_manifest_snapshot_only' => true,
                        'live_queue_state_verified' => $liveQueueVerification !== null,
                        'live_queue_verification' => $liveQueueVerification,
                    ],
                    'r3_durable_state_table_present' => $r3TablePresent,
                ],
                'missing_evidence' => $missingEvidence,
                'operational_recommendation' => [
                    'action' => 'human_review_required',
                    'candidate_action' => null,
                    'automatic_execution_allowed' => false,
                    'automatic_job_deletion_allowed' => false,
                    'requires_remote_verification' => true,
                ],
            ],
            'legacy_flags' => [
                'missing_order' => true,
            ],
            'summary_meta' => [
                'has_pending_obligation' => false,
            ],
        ];
    }

    /**
     * Exhaustively classifies one existing `meli_orders` row using preloaded batch context.
     *
     * @param  array<string, mixed>  $ctx
     * @return array{
     *     entity: array<string, mixed>,
     *     legacy_flags: array<string, mixed>,
     *     summary_meta: array<string, mixed>
     * }
     */
    public function classifyExistingOrder(object $order, array $ctx): array
    {
        $oid = (int) $order->id;
        $accountId = $order->meli_account_id !== null ? (int) $order->meli_account_id : null;
        $remoteOrderId = isset($order->order_id) && trim((string) $order->order_id) !== ''
            ? trim((string) $order->order_id)
            : null;
        $status = strtolower(trim((string) ($order->status ?? '')));
        $shippingStatus = strtolower(trim((string) ($order->shipping_status ?? '')));

        /** @var list<int> $associatedJobIds */
        $associatedJobIds = $ctx['associated_job_ids'];
        $associatedJobsCount = (int) $ctx['associated_jobs_count'];
        $associatedJobIdsTruncated = (bool) $ctx['associated_job_ids_truncated'];
        $reservedJobsCount = (int) $ctx['reserved_jobs_count'];
        /** @var list<int> $associatedUnreservedJobIds */
        $associatedUnreservedJobIds = $ctx['associated_unreserved_job_ids'] ?? $associatedJobIds;
        $associatedUnreservedJobsCount = (int) ($ctx['associated_unreserved_jobs_count'] ?? max(0, $associatedJobsCount - $reservedJobsCount));
        $associatedUnreservedJobIdsTruncated = (bool) ($ctx['associated_unreserved_job_ids_truncated'] ?? false);
        /** @var list<int> $associatedReservedJobIds */
        $associatedReservedJobIds = $ctx['associated_reserved_job_ids'] ?? [];
        $associatedReservedJobIdsTruncated = (bool) ($ctx['associated_reserved_job_ids_truncated'] ?? false);
        $manifestAccountHint = $ctx['manifest_account_hint'] ?? null;
        $manifestRemoteOrderHint = $ctx['manifest_remote_order_hint'] ?? null;
        $manifestMetadataConflict = (bool) ($ctx['manifest_metadata_conflict'] ?? false);
        /** @var array<string, mixed>|null $liveQueueVerification */
        $liveQueueVerification = $ctx['live_queue_verification'] ?? null;

        /** @var list<object> $orderItems */
        $orderItems = $ctx['order_items'];
        /** @var list<object> $allocs */
        $allocs = $ctx['allocations'];
        /** @var list<object> $crossAccountAllocs */
        $crossAccountAllocs = $ctx['cross_account_allocations'];
        /** @var array<int, object> $reservationsById */
        $reservationsById = $ctx['reservations_by_id'];
        /** @var array<int, object> $kitReservationsById */
        $kitReservationsById = $ctx['kit_reservations_by_id'];
        /** @var list<object> $directResAll */
        $directResAll = $ctx['direct_reservations'];
        /** @var list<object> $directKitResAll */
        $directKitResAll = $ctx['direct_kit_reservations'];
        /** @var array<string, bool> $existingFulfillmentKeys */
        $existingFulfillmentKeys = $ctx['existing_fulfillment_keys'];
        /** @var array<int, list<object>> $manualMovementsByProduct */
        $manualMovementsByProduct = $ctx['manual_movements_by_product'];
        /** @var array<string, list<array<string, mixed>>> $allLinksByKey */
        $allLinksByKey = $ctx['all_links_by_key'];
        /** @var array<string, array<string, bool>> $activeLinkAccountsByListing */
        $activeLinkAccountsByListing = $ctx['active_link_accounts_by_listing'];
        /** @var array<string, bool> $sharedStockConflictGroups */
        $sharedStockConflictGroups = $ctx['shared_stock_conflict_groups'];
        /** @var array<int, array{sku: ?string, is_active: bool, product_type: string}> $productsById */
        $productsById = $ctx['products_by_id'];
        /** @var array<int, bool> $kitProductIds */
        $kitProductIds = $ctx['kit_product_ids'];
        /** @var array<int, bool> $componentProductIds */
        $componentProductIds = $ctx['component_product_ids'];
        /** @var array<int, list<int>> $kitComponentsByKitId */
        $kitComponentsByKitId = $ctx['kit_components_by_kit_id'];
        /** @var array<int, bool> $validAccountIds */
        $validAccountIds = $ctx['valid_account_ids'];
        /** @var array<string, bool> $tables */
        $tables = $ctx['tables'];
        /** @var array<string, bool> $columns */
        $columns = $ctx['columns'];
        /** @var ?CarbonImmutable $cutoverAt */
        $cutoverAt = $ctx['cutover_at'];
        /** @var CarbonImmutable $nowUtc */
        $nowUtc = $ctx['now_utc'];
        /** @var ?object $r3Row */
        $r3Row = $ctx['r3_reconciliation'];

        $humanReviewReasons = [];
        $ambiguityReasons = [];
        $secondaryAnomalies = [];
        $missingEvidence = [];

        if (! $tables['inventory_meli_order_reconciliations']) {
            $missingEvidence[] = 'r3_durable_state_table_absent';
        }
        if (! $tables['inventory_channel_order_allocations']) {
            $missingEvidence[] = 'allocations_table_absent';
        }
        if (! $tables['inventory_reservations']) {
            $missingEvidence[] = 'reservations_table_absent';
        }
        if (! $tables['inventory_channel_links']) {
            $missingEvidence[] = 'channel_links_table_absent';
        }

        if ($reservedJobsCount > 0) {
            $humanReviewReasons[] = 'reserved_jobs_detected_for_order';
            $humanReviewReasons[] = $associatedUnreservedJobsCount === 0
                ? 'manifest_reserved_jobs_only'
                : 'manifest_reserved_jobs_detected';
            $secondaryAnomalies[] = 'reserved_jobs_detected_for_order';
        }

        if ($manifestMetadataConflict
            || ($manifestAccountHint !== null && $accountId !== null && (int) $manifestAccountHint !== $accountId)
            || ($manifestRemoteOrderHint !== null && $remoteOrderId !== null && (string) $manifestRemoteOrderHint !== $remoteOrderId)) {
            $humanReviewReasons[] = 'manifest_metadata_conflict';
            $secondaryAnomalies[] = 'manifest_metadata_conflict';
        }

        // 1. Account validity check
        $accountMismatch = $accountId === null || ($tables['meli_accounts'] && ! isset($validAccountIds[$accountId]));
        if ($accountMismatch) {
            $humanReviewReasons[] = 'account_mismatch';
            $secondaryAnomalies[] = 'account_mismatches';
        }

        if ($crossAccountAllocs !== []) {
            $ambiguityReasons[] = 'cross_account_allocation_conflict';
            $secondaryAnomalies[] = 'account_mismatches';
            $secondaryAnomalies[] = 'cross_account_allocation_conflict';
        }

        // 2. Parse `raw` and `shipping_raw` safely (zero PII extracted!)
        $rawParse = $this->decodeJsonObject($order->raw ?? null);
        $rawDecoded = $rawParse['data'];
        if ($rawParse['invalid']) {
            $humanReviewReasons[] = 'invalid_raw_json';
            $secondaryAnomalies[] = 'invalid_raw_json';
        }

        $shippingRawParse = $columns['meli_orders_shipping_raw']
            ? $this->decodeJsonObject($order->shipping_raw ?? null)
            : ['data' => null, 'invalid' => false];
        $shippingRawDecoded = $shippingRawParse['data'];
        if ($shippingRawParse['invalid']) {
            $humanReviewReasons[] = 'invalid_shipping_raw_json';
            $secondaryAnomalies[] = 'invalid_shipping_raw_json';
        }

        // 3. Strict date validation (never silently treat invalid date as pre-cutover!)
        $dateAudit = $this->auditOrderDates($order, $rawDecoded);
        if ($dateAudit['invalid']) {
            $humanReviewReasons[] = 'invalid_order_date';
            $secondaryAnomalies[] = 'invalid_order_date';
        }
        if ($dateAudit['missing_raw_date_created']) {
            $missingEvidence[] = 'raw_date_created_missing';
        }

        $orderCreatedAt = $dateAudit['effective_timestamp'];
        $cutoverVerifiable = $cutoverAt === null || ($dateAudit['raw_date_created_valid'] && $dateAudit['raw_date_created'] !== null);
        $afterCutover = match (true) {
            $cutoverAt === null => true,
            $dateAudit['raw_date_created_valid'] && $dateAudit['raw_date_created'] !== null => $dateAudit['raw_date_created']->greaterThanOrEqualTo($cutoverAt),
            default => false,
        };
        $isValidPreCutover = $cutoverAt !== null
            && $dateAudit['raw_date_created_valid']
            && $dateAudit['raw_date_created'] !== null
            && $dateAudit['raw_date_created']->lt($cutoverAt);

        // 4. Raw vs materialized consistency & shipping consistency & partial payload detection
        if (is_array($rawDecoded)) {
            $rawStatus = is_string($rawDecoded['status'] ?? null) ? strtolower(trim((string) $rawDecoded['status'])) : null;
            if ($rawStatus !== null && $rawStatus !== '' && $status !== '' && $rawStatus !== $status) {
                $humanReviewReasons[] = 'raw_status_mismatch';
                $secondaryAnomalies[] = 'raw_status_mismatch';
            }

            $rawShipping = is_array($rawDecoded['shipping'] ?? null) ? $rawDecoded['shipping'] : null;
            $rawShippingStatus = is_string($rawShipping['status'] ?? null)
                ? strtolower(trim((string) $rawShipping['status']))
                : null;
            if ($rawShippingStatus !== null && $rawShippingStatus !== '' && $shippingStatus !== '' && $rawShippingStatus !== $shippingStatus) {
                $humanReviewReasons[] = 'raw_shipping_status_mismatch';
                $secondaryAnomalies[] = 'raw_shipping_status_mismatch';
            }

            $matShippingId = $columns['meli_orders_shipping_id'] && isset($order->shipping_id) && trim((string) $order->shipping_id) !== ''
                ? trim((string) $order->shipping_id)
                : null;
            $rawShippingId = isset($rawShipping['id']) && is_scalar($rawShipping['id']) && trim((string) $rawShipping['id']) !== ''
                ? trim((string) $rawShipping['id'])
                : null;
            if ($columns['meli_orders_shipping_id'] && $matShippingId !== null && $rawShippingId !== null && $matShippingId !== $rawShippingId) {
                $humanReviewReasons[] = 'shipping_id_mismatch';
                $secondaryAnomalies[] = 'shipping_id_mismatch';
            }

            // Partial payload pending materialization (`_partial_status_last_updated`)
            if (array_key_exists('_partial_status_last_updated', $rawDecoded) && $rawDecoded['_partial_status_last_updated'] !== null) {
                $partialTs = $this->parseStrictIso8601WithTz($rawDecoded['_partial_status_last_updated']);
                $fullTs = $this->parseStrictIso8601WithTz($rawDecoded['last_updated'] ?? ($rawDecoded['date_last_updated'] ?? null));
                $hasRawOrderItems = is_array($rawDecoded['order_items'] ?? null) && $rawDecoded['order_items'] !== [];

                if ($partialTs === null) {
                    $humanReviewReasons[] = 'invalid_order_date';
                    $secondaryAnomalies[] = 'invalid_order_date';
                } elseif ($fullTs === null || $partialTs->gt($fullTs) || ! $hasRawOrderItems) {
                    $humanReviewReasons[] = 'partial_payload_pending_materialization';
                    $secondaryAnomalies[] = 'partial_payload_pending_materialization';
                }
            }

            // Raw order_items vs materialized `meli_order_items` mismatch
            if (is_array($rawDecoded['order_items'] ?? null) && $rawDecoded['order_items'] !== [] && $orderItems !== []) {
                if ($this->rawOrderItemsDifferFromMaterialized($rawDecoded['order_items'], $orderItems, $columns['item_variation_id'])) {
                    $humanReviewReasons[] = 'raw_items_materialization_mismatch';
                    $secondaryAnomalies[] = 'raw_items_materialization_mismatch';
                }
            }
        }

        if (is_array($shippingRawDecoded)) {
            $matShippingId = $columns['meli_orders_shipping_id'] && isset($order->shipping_id) && trim((string) $order->shipping_id) !== ''
                ? trim((string) $order->shipping_id)
                : null;
            $shipRawId = isset($shippingRawDecoded['id']) && is_scalar($shippingRawDecoded['id']) && trim((string) $shippingRawDecoded['id']) !== ''
                ? trim((string) $shippingRawDecoded['id'])
                : null;
            if ($columns['meli_orders_shipping_id'] && $shipRawId !== null && ($matShippingId === null || $matShippingId !== $shipRawId)) {
                $humanReviewReasons[] = 'shipping_id_mismatch';
                $secondaryAnomalies[] = 'shipping_id_mismatch';
            }

            $shipRawStatus = is_string($shippingRawDecoded['status'] ?? null)
                ? strtolower(trim((string) $shippingRawDecoded['status']))
                : null;
            if ($shipRawStatus !== null && $shipRawStatus !== '' && $shippingStatus !== '' && $shipRawStatus !== $shippingStatus) {
                $humanReviewReasons[] = 'raw_shipping_status_mismatch';
                $secondaryAnomalies[] = 'raw_shipping_status_mismatch';
            }
        }

        // 5. Items check (0 items -> human review!)
        if ($orderItems === []) {
            $humanReviewReasons[] = 'order_without_items';
            $secondaryAnomalies[] = 'order_without_items';
            $missingEvidence[] = 'meli_order_items_empty';
        }

        // 6. Evaluate allocations, reservations, stale allocations, diagnostic allocations, shared reservations
        $allocsByLineKey = [];
        $activeAllocs = [];
        $fulfilledAllocs = [];
        $releasedAllocs = [];
        $diagnosticAllocs = [];
        $pendingAllocs = [];
        $staleAllocations = [];
        $sharedReservationRefCounts = [];
        $hasKitAllocation = false;

        foreach ($allocs as $a) {
            $aStatus = strtoupper(trim((string) ($a->status ?? '')));
            $lineKey = (string) ($a->remote_line_key ?? '');
            if ($lineKey !== '') {
                $allocsByLineKey[$lineKey][] = $a;
            }
            if ((string) ($a->reservation_kind ?? '') === 'KIT') {
                $hasKitAllocation = true;
            }
            if ($a->reservation_id !== null) {
                $refKey = ((string) ($a->reservation_kind ?? 'SIMPLE')).':'.(int) $a->reservation_id;
                $sharedReservationRefCounts[$refKey] = ($sharedReservationRefCounts[$refKey] ?? 0) + 1;
            }

            if ($aStatus === 'ACTIVE') {
                $activeAllocs[] = $a;
                $resObj = $this->resolveAllocationReservation($a, $reservationsById, $kitReservationsById);
                if ($resObj === null || strtoupper((string) ($resObj->status ?? '')) !== 'ACTIVE') {
                    $staleAllocations[] = [
                        'allocation_id' => (int) $a->id,
                        'reservation_kind' => $a->reservation_kind ?? null,
                        'reservation_id' => $a->reservation_id !== null ? (int) $a->reservation_id : null,
                        'underlying_status' => $resObj !== null ? (string) $resObj->status : 'MISSING',
                    ];
                }
            } elseif ($aStatus === 'FULFILLED') {
                $fulfilledAllocs[] = $a;
            } elseif ($aStatus === 'RELEASED') {
                $releasedAllocs[] = $a;
            } elseif ($aStatus === 'DIAGNOSTIC') {
                $diagnosticAllocs[] = $a;
            } elseif ($aStatus === 'PENDING') {
                $pendingAllocs[] = $a;
            }
        }

        foreach ($sharedReservationRefCounts as $refCount) {
            if ($refCount > 1) {
                $ambiguityReasons[] = 'shared_reservation_across_lines';
                $secondaryAnomalies[] = 'shared_reservation_across_lines';
                break;
            }
        }

        if ($staleAllocations !== []) {
            $humanReviewReasons[] = 'stale_allocation';
            $secondaryAnomalies[] = 'stale_allocation';
        }
        if ($pendingAllocs !== []) {
            $humanReviewReasons[] = 'pending_allocation_stuck';
            $secondaryAnomalies[] = 'pending_allocation_stuck';
        }
        if ($diagnosticAllocs !== []) {
            $humanReviewReasons[] = 'diagnostic_allocation_present';
            $secondaryAnomalies[] = 'diagnostic_allocation_present';
            foreach ($diagnosticAllocs as $da) {
                $dCode = $columns['alloc_diagnostic_code'] && isset($da->diagnostic_code) && trim((string) $da->diagnostic_code) !== ''
                    ? strtolower(trim((string) $da->diagnostic_code))
                    : 'unknown';
                $secondaryAnomalies[] = 'diagnostic_code:'.$dCode;
            }
        }

        $activeDirectRes = array_values(array_filter($directResAll, static fn (object $r): bool => strtoupper((string) ($r->status ?? '')) === 'ACTIVE'));
        $activeDirectKitRes = array_values(array_filter($directKitResAll, static fn (object $r): bool => strtoupper((string) ($r->status ?? '')) === 'ACTIVE'));
        $fulfilledDirectRes = array_values(array_filter($directResAll, static fn (object $r): bool => strtoupper((string) ($r->status ?? '')) === 'FULFILLED'));
        $fulfilledDirectKitRes = array_values(array_filter($directKitResAll, static fn (object $r): bool => strtoupper((string) ($r->status ?? '')) === 'FULFILLED'));

        // Live active reservations (excluding stale allocations whose underlying reservation is dead)
        $liveActiveAllocs = array_values(array_filter($activeAllocs, function (object $a) use ($reservationsById, $kitReservationsById): bool {
            $res = $this->resolveAllocationReservation($a, $reservationsById, $kitReservationsById);

            return $res !== null && strtoupper((string) ($res->status ?? '')) === 'ACTIVE';
        }));

        $hasActiveReservation = $liveActiveAllocs !== [] || $activeDirectRes !== [] || $activeDirectKitRes !== [];
        $hasAnyActiveOrStaleAlloc = $activeAllocs !== [] || $activeDirectRes !== [] || $activeDirectKitRes !== [];

        // Check unclaimed direct reservations on multiline orders -> shared/ambiguous reservation across lines!
        if (count($orderItems) > 1 && ($activeDirectRes !== [] || $activeDirectKitRes !== [])) {
            $claimedSimple = [];
            $claimedKit = [];
            foreach ($allocs as $a) {
                if ($a->reservation_id !== null) {
                    if ((string) ($a->reservation_kind ?? '') === 'KIT') {
                        $claimedKit[(int) $a->reservation_id] = true;
                    } else {
                        $claimedSimple[(int) $a->reservation_id] = true;
                    }
                }
            }
            foreach ($activeDirectRes as $dr) {
                if (! isset($claimedSimple[(int) $dr->id])) {
                    $meta = $this->decodeJsonObject($dr->metadata ?? null)['data'];
                    if (! is_array($meta) || empty($meta['remote_line_key'])) {
                        $ambiguityReasons[] = 'shared_reservation_across_lines';
                        $secondaryAnomalies[] = 'shared_reservation_across_lines';
                        break;
                    }
                }
            }
            foreach ($activeDirectKitRes as $dkr) {
                if (! isset($claimedKit[(int) $dkr->id])) {
                    $meta = $this->decodeJsonObject($dkr->metadata ?? null)['data'];
                    if (! is_array($meta) || empty($meta['remote_line_key'])) {
                        $ambiguityReasons[] = 'shared_reservation_across_lines';
                        $secondaryAnomalies[] = 'shared_reservation_across_lines';
                        break;
                    }
                }
            }
        }

        // 7. Evaluate items, channel links (ALL links — active, inactive, disabled, missing!),
        //    shared stock conflicts, cross-account links, line keys, quantities, kits/components.
        $hasReservationEnabledLink = false;
        $ambiguousLinkKeys = [];
        $missingLineKeyCount = 0;
        $noLinkReasons = [];
        $allItemsHaveValidEnabledSimpleLink = $orderItems !== [];
        $allItemsVerifiedActiveMatch = $orderItems !== [];
        $allItemsVerifiedFulfilled = $orderItems !== [];
        $anyItemFulfilled = $fulfilledAllocs !== [] || $fulfilledDirectRes !== [] || $fulfilledDirectKitRes !== [];
        $involvedProductIds = [];
        $kitPids = [];
        $componentPids = [];
        $quantityInconsistency = false;
        $hasReleasedAllocOnPaid = false;

        foreach ($allocs as $a) {
            if ($a->inventory_product_id !== null) {
                $involvedProductIds[(int) $a->inventory_product_id] = true;
            }
        }
        foreach ($directResAll as $dr) {
            if (isset($dr->inventory_product_id) && $dr->inventory_product_id !== null) {
                $involvedProductIds[(int) $dr->inventory_product_id] = true;
            }
        }
        foreach ($directKitResAll as $dkr) {
            if (isset($dkr->kit_product_id) && $dkr->kit_product_id !== null) {
                $involvedProductIds[(int) $dkr->kit_product_id] = true;
                $kitPids[(int) $dkr->kit_product_id] = true;
            }
        }

        foreach ($orderItems as $oi) {
            $itemId = isset($oi->item_id) ? trim((string) $oi->item_id) : '';
            $var = $columns['item_variation_id'] && $oi->variation_id !== null && trim((string) $oi->variation_id) !== ''
                ? trim((string) $oi->variation_id)
                : '';
            $lineKey = $columns['item_remote_line_key'] && $oi->remote_line_key !== null
                ? trim((string) $oi->remote_line_key)
                : '';
            $qty = (int) ($oi->quantity ?? 0);

            if ($qty <= 0) {
                $quantityInconsistency = true;
            }

            if ($columns['item_remote_line_key'] && $lineKey === '') {
                $missingLineKeyCount++;
            }

            if ($itemId === '') {
                $ambiguityReasons[] = 'missing_listing_id';
                $secondaryAnomalies[] = 'missing_listing_id';
                $allItemsHaveValidEnabledSimpleLink = false;
                $allItemsVerifiedActiveMatch = false;
                $allItemsVerifiedFulfilled = false;

                continue;
            }

            $lk = ((string) ($accountId ?? '')).'|'.$itemId.'|'.$var;
            $matchedAllLinks = $allLinksByKey[$lk] ?? [];
            $matchedActiveLinks = array_values(array_filter($matchedAllLinks, static fn (array $l): bool => (bool) $l['is_active']));
            $matchedEnabledLinks = array_values(array_filter($matchedActiveLinks, static fn (array $l): bool => (bool) $l['order_reservation_enabled']));

            foreach ($matchedAllLinks as $ml) {
                $involvedProductIds[(int) $ml['inventory_product_id']] = true;
            }

            // Ambiguous channel links (>1 link for same listing/variation on account)
            if (count($matchedAllLinks) > 1) {
                $ambiguousLinkKeys[] = [
                    'item_id' => $itemId,
                    'variation_id' => $var !== '' ? $var : null,
                    'link_count' => count($matchedAllLinks),
                    'active_link_count' => count($matchedActiveLinks),
                    'link_ids' => array_column($matchedAllLinks, 'link_id'),
                ];
            }

            // Cross-account link conflict: no link on this order's account, yet another account has an active link for this listing!
            if ($matchedAllLinks === [] && $accountId !== null && isset($activeLinkAccountsByListing[$itemId])) {
                $otherAccounts = array_filter(
                    array_keys($activeLinkAccountsByListing[$itemId]),
                    static fn (string $accKey): bool => $accKey !== (string) $accountId,
                );
                if ($otherAccounts !== []) {
                    $ambiguityReasons[] = 'cross_account_link_conflict';
                    $secondaryAnomalies[] = 'cross_account_link_conflict';
                }
            }

            $resolvedLink = count($matchedAllLinks) === 1 ? $matchedAllLinks[0] : (count($matchedEnabledLinks) === 1 ? $matchedEnabledLinks[0] : null);
            $resolvedProduct = $resolvedLink !== null ? ($productsById[(int) $resolvedLink['inventory_product_id']] ?? null) : null;

            if ($resolvedLink !== null && $resolvedLink['is_active'] && $resolvedLink['order_reservation_enabled']) {
                // Check shared stock conflict (`REMOTE_USER_PRODUCT_CONFLICT`)
                if (! empty($resolvedLink['remote_user_product_id']) && $var === '') {
                    $groupKey = ((string) $resolvedLink['account_key']).'|'.((string) $resolvedLink['remote_user_product_id']);
                    if (isset($sharedStockConflictGroups[$groupKey])) {
                        $ambiguityReasons[] = 'remote_user_product_conflict';
                        $secondaryAnomalies[] = 'remote_user_product_conflict';
                    }
                }

                if ($resolvedProduct === null || ! $resolvedProduct['is_active']) {
                    $noLinkReasons[] = 'inactive_or_missing_product';
                    $allItemsHaveValidEnabledSimpleLink = false;
                } else {
                    $hasReservationEnabledLink = true;
                    if ($resolvedProduct['product_type'] === 'kit') {
                        $allItemsHaveValidEnabledSimpleLink = false;
                    }
                    // Optional non-blocking SKU mismatch visibility
                    $itemSku = isset($oi->sku) ? trim((string) $oi->sku) : '';
                    $prodSku = isset($resolvedProduct['sku']) ? trim((string) $resolvedProduct['sku']) : '';
                    if ($itemSku !== '' && $prodSku !== '' && strcasecmp($itemSku, $prodSku) !== 0) {
                        $secondaryAnomalies[] = 'sku_mismatch_with_linked_product';
                    }
                }
            } else {
                $allItemsHaveValidEnabledSimpleLink = false;
                if ($matchedAllLinks === []) {
                    $noLinkReasons[] = 'unmatched_no_link';
                } elseif ($matchedActiveLinks === []) {
                    $noLinkReasons[] = 'inactive_link';
                } elseif ($matchedEnabledLinks === []) {
                    $noLinkReasons[] = 'order_reservation_disabled';
                }
            }

            // Per-line allocation / reservation / fulfillment verification
            $lineAllocs = $lineKey !== '' ? ($allocsByLineKey[$lineKey] ?? []) : [];
            $lineActiveAlloc = null;
            $lineFulfilledAlloc = null;
            foreach ($lineAllocs as $la) {
                $las = strtoupper(trim((string) ($la->status ?? '')));
                if ($las === 'ACTIVE') {
                    $lineActiveAlloc = $la;
                } elseif ($las === 'FULFILLED') {
                    $lineFulfilledAlloc = $la;
                } elseif ($las === 'RELEASED' && $status === 'paid') {
                    $hasReleasedAllocOnPaid = true;
                }
            }

            // Verify active allocation & underlying reservation quantity/product consistency
            if ($lineActiveAlloc !== null) {
                $resObj = $this->resolveAllocationReservation($lineActiveAlloc, $reservationsById, $kitReservationsById);
                $resActive = $resObj !== null && strtoupper((string) ($resObj->status ?? '')) === 'ACTIVE';
                $allocQtyMatches = (int) ($lineActiveAlloc->quantity ?? 0) === $qty;
                $resQtyMatches = $resActive && (int) ($resObj->quantity ?? 0) === $qty;
                $resProductId = $resObj !== null
                    ? ((string) ($lineActiveAlloc->reservation_kind ?? '') === 'KIT'
                        ? (int) ($resObj->kit_product_id ?? 0)
                        : (int) ($resObj->inventory_product_id ?? 0))
                    : 0;
                $expectedPid = $resolvedLink !== null ? (int) $resolvedLink['inventory_product_id'] : null;

                if (! $allocQtyMatches || ($resActive && ! $resQtyMatches)) {
                    $quantityInconsistency = true;
                }
                if ($expectedPid !== null
                    && $lineActiveAlloc->inventory_product_id !== null
                    && (int) $lineActiveAlloc->inventory_product_id !== $expectedPid) {
                    $quantityInconsistency = true;
                }
                if ($expectedPid !== null && $resActive && $resProductId > 0 && $resProductId !== $expectedPid) {
                    $quantityInconsistency = true;
                }

                if (! $resActive || ! $allocQtyMatches || ! $resQtyMatches || $expectedPid === null || (int) $lineActiveAlloc->inventory_product_id !== $expectedPid) {
                    $allItemsVerifiedActiveMatch = false;
                }
            } else {
                $allItemsVerifiedActiveMatch = false;
            }

            // Check direct active reservations quantity mismatch when single-line without allocation
            if ($lineActiveAlloc === null && count($orderItems) === 1 && count($activeDirectRes) === 1) {
                $dr = $activeDirectRes[0];
                if ((int) ($dr->quantity ?? 0) !== $qty) {
                    $quantityInconsistency = true;
                }
            }

            // Check fulfillment movement proof
            $identityLine = $lineKey !== '' ? $lineKey : 'legacy-row:'.(int) $oi->id;
            $identityHash = hash('sha256', implode("\0", ['mercado_libre', (string) ($accountId ?? ''), (string) ($remoteOrderId ?? ''), $identityLine]));
            $fulfillKey = 'meli-order:'.$identityHash.':fulfill';
            $legacyFulfillKey = 'meli-order:'.($remoteOrderId ?? '').':line:'.($lineKey !== '' ? $lineKey : (string) $oi->id).':fulfill';
            $hasLineFulfillMovement = isset($existingFulfillmentKeys[$fulfillKey]) || isset($existingFulfillmentKeys[$legacyFulfillKey]);

            if ($lineFulfilledAlloc !== null || $hasLineFulfillMovement) {
                $anyItemFulfilled = true;
            } else {
                $allItemsVerifiedFulfilled = false;
            }
        }

        // Extra unclaimed active reservations on a paid order -> inconsistency!
        if ($status === 'paid' && $allItemsVerifiedActiveMatch && count($activeDirectRes) > count($liveActiveAllocs)) {
            $quantityInconsistency = true;
            $allItemsVerifiedActiveMatch = false;
        }

        if ($quantityInconsistency) {
            $humanReviewReasons[] = 'quantity_inconsistency';
            $secondaryAnomalies[] = 'quantity_inconsistency';
        }

        if ($ambiguousLinkKeys !== []) {
            $ambiguityReasons[] = 'ambiguous_channel_link';
            $secondaryAnomalies[] = 'ambiguous_channel_links';
        }

        // UNCONDITIONAL missing_remote_line_key check (NOT gated by $hasReservationEnabledLink!)
        if ($missingLineKeyCount > 0) {
            $ambiguityReasons[] = 'missing_remote_line_key';
            $secondaryAnomalies[] = 'missing_remote_line_key_orders';
        }

        // UNCONDITIONAL multiline check (NOT gated by $hasReservationEnabledLink!)
        $isMultiline = count($orderItems) > 1;
        if ($isMultiline) {
            $ambiguityReasons[] = 'multiline_order';
            $secondaryAnomalies[] = 'multiline_orders';
        }

        // 8. Kits and components check (UNCONDITIONAL)
        foreach (array_keys($involvedProductIds) as $pid) {
            if (isset($kitProductIds[$pid])) {
                $kitPids[$pid] = true;
            }
            if (isset($componentProductIds[$pid])) {
                $componentPids[$pid] = true;
            }
        }
        $involvesKitOrComponent = $kitPids !== [] || $componentPids !== [] || $hasKitAllocation || $directKitResAll !== [];
        if ($involvesKitOrComponent) {
            $ambiguityReasons[] = 'kit_or_component_involved';
            $secondaryAnomalies[] = 'kits_and_components';
        }

        // 9. Manual movements check (checks product AND kit components, using `occurred_at` with fallback)
        $movementTargetProductIds = $involvedProductIds;
        foreach (array_keys($kitPids) as $kpid) {
            foreach ($kitComponentsByKitId[$kpid] ?? [] as $compPid) {
                $movementTargetProductIds[$compPid] = true;
            }
        }

        $matchedManualMovements = [];
        $hasBlockingManualMovement = false;

        foreach (array_keys($movementTargetProductIds) as $pid) {
            foreach ($manualMovementsByProduct[$pid] ?? [] as $mov) {
                $rawTs = ($columns['mov_occurred_at'] && ! empty($mov->occurred_at))
                    ? (string) $mov->occurred_at
                    : (! empty($mov->created_at) ? (string) $mov->created_at : null);

                $movAt = null;
                if ($rawTs !== null) {
                    try {
                        $movAt = CarbonImmutable::parse($rawTs);
                    } catch (Throwable) {
                        $movAt = null;
                    }
                }

                $isDirectOrderRef = false;
                if ($columns['mov_reference_id']
                    && (string) ($mov->reference_type ?? '') === 'meli_order'
                    && (int) ($mov->reference_id ?? 0) === $oid) {
                    $isDirectOrderRef = true;
                }
                if (! $isDirectOrderRef && $remoteOrderId !== null) {
                    if ($columns['mov_reference'] && isset($mov->reference) && str_contains((string) $mov->reference, $remoteOrderId)) {
                        $isDirectOrderRef = true;
                    } elseif ($columns['mov_notes'] && isset($mov->notes) && str_contains((string) $mov->notes, $remoteOrderId)) {
                        $isDirectOrderRef = true;
                    }
                }

                $isAfterOrAtOrder = $orderCreatedAt === null || $movAt === null || $movAt->greaterThanOrEqualTo($orderCreatedAt);
                if (! $isDirectOrderRef && ! $isAfterOrAtOrder) {
                    continue;
                }

                $movType = strtoupper(trim((string) ($mov->type ?? '')));
                $isBlockingType = in_array($movType, self::BLOCKING_MANUAL_MOVEMENT_TYPES, true);
                if ($isDirectOrderRef || $isBlockingType) {
                    $hasBlockingManualMovement = true;
                }

                $matchedManualMovements[] = [
                    'movement_id' => (int) $mov->id,
                    'inventory_product_id' => $pid,
                    'type' => (string) $mov->type,
                    'quantity' => (int) $mov->quantity,
                    'occurred_at' => $rawTs,
                    'direct_order_reference' => $isDirectOrderRef,
                    'blocking' => $isDirectOrderRef || $isBlockingType,
                ];
            }
        }

        if ($matchedManualMovements !== []) {
            $secondaryAnomalies[] = 'orders_with_manual_movements';
            if ($hasBlockingManualMovement) {
                $humanReviewReasons[] = 'manual_movement_blocking_human_review';
            } else {
                $ambiguityReasons[] = 'manual_movements_detected';
            }
        }

        // 10. R3 durable reconciliation state audit (when table exists)
        $r3Evidence = null;
        $hasUnprocessedR3Revision = false;
        if ($tables['inventory_meli_order_reconciliations'] && $r3Row !== null) {
            $r3State = strtoupper(trim((string) ($r3Row->state ?? 'IDLE')));
            $reqRev = (int) ($r3Row->requested_revision ?? 0);
            $procRev = (int) ($r3Row->processed_revision ?? 0);
            $lastEnqRev = (int) ($r3Row->last_enqueued_revision ?? 0);
            $fencingToken = (int) ($r3Row->fencing_token ?? 0);
            $pendingJobId = $r3Row->pending_job_id !== null ? (int) $r3Row->pending_job_id : null;
            $stockSyncReq = (bool) ($r3Row->stock_sync_required ?? false);
            $stockSyncDoneRev = (int) ($r3Row->stock_sync_completed_revision ?? 0);
            $dispatchConfirmed = ! empty($r3Row->dispatch_confirmed_at);
            $leaseExpiresAt = null;
            if (! empty($r3Row->lease_expires_at)) {
                try {
                    $leaseExpiresAt = CarbonImmutable::parse((string) $r3Row->lease_expires_at);
                } catch (Throwable) {
                    $leaseExpiresAt = null;
                }
            }
            $leaseExpired = $r3State === 'PROCESSING' && ($leaseExpiresAt === null || $leaseExpiresAt->lte($nowUtc));

            $r3Evidence = [
                'state' => $r3State,
                'requested_revision' => $reqRev,
                'processed_revision' => $procRev,
                'last_enqueued_revision' => $lastEnqRev,
                'pending_job_id' => $pendingJobId,
                'fencing_token' => $fencingToken,
                'stock_sync_required' => $stockSyncReq,
                'stock_sync_completed_revision' => $stockSyncDoneRev,
                'dispatch_confirmed' => $dispatchConfirmed,
                'lease_expired' => $leaseExpired,
                'attempts' => (int) ($r3Row->attempts ?? 0),
                'last_reconciled_at' => ! empty($r3Row->last_reconciled_at) ? (string) $r3Row->last_reconciled_at : null,
            ];

            if (($r3Row->meli_account_id !== null && $accountId !== null && (int) $r3Row->meli_account_id !== $accountId)
                || ($r3Row->remote_order_id !== null && $remoteOrderId !== null && (string) $r3Row->remote_order_id !== $remoteOrderId)) {
                $humanReviewReasons[] = 'r3_reconciliation_identity_mismatch';
                $secondaryAnomalies[] = 'r3_reconciliation_identity_mismatch';
            }

            if ($r3State === 'FAILED') {
                $humanReviewReasons[] = 'r3_reconciliation_failed';
                $secondaryAnomalies[] = 'r3_reconciliation_failed';
                $hasUnprocessedR3Revision = true;
            }
            if ($leaseExpired) {
                $humanReviewReasons[] = 'r3_processing_lease_expired';
                $secondaryAnomalies[] = 'r3_processing_lease_expired';
                $hasUnprocessedR3Revision = true;
            }
            if ($r3State === 'PENDING' && (! $dispatchConfirmed || $pendingJobId === null)) {
                $humanReviewReasons[] = 'r3_pending_unconfirmed_outbox';
                $secondaryAnomalies[] = 'r3_pending_unconfirmed_outbox';
                $hasUnprocessedR3Revision = true;
            }
            if ($stockSyncReq && $stockSyncDoneRev < max($reqRev, $procRev)) {
                $humanReviewReasons[] = 'r3_stock_sync_required_pending';
                $secondaryAnomalies[] = 'r3_stock_sync_required_pending';
            }
            if ($reqRev > $procRev || in_array($r3State, ['PENDING', 'PROCESSING'], true)) {
                $hasUnprocessedR3Revision = true;
                $secondaryAnomalies[] = 'r3_pending_revision_unprocessed';
                if ($r3State === 'IDLE' && $reqRev > $procRev) {
                    $humanReviewReasons[] = 'r3_idle_with_unprocessed_revision';
                }
            }
        }

        // 11. Policy & lifecycle evaluation
        $policyCategory = $this->policy->classify($status, $shippingStatus);
        $isFulfilledShipping = in_array($shippingStatus, self::FULFILLED_SHIPPING_STATUSES, true);

        $isCancelledWithActive = $status === 'cancelled' && $hasAnyActiveOrStaleAlloc;
        $isShippedWithActive = $status !== 'cancelled' && $isFulfilledShipping && $hasAnyActiveOrStaleAlloc;
        $isPaidPending = $status === 'paid'
            && ! $isFulfilledShipping
            && $afterCutover
            && $hasReservationEnabledLink
            && ! $hasAnyActiveOrStaleAlloc
            && ! $anyItemFulfilled
            && ! $hasReleasedAllocOnPaid;

        $isShippedNeverReserved = $status !== 'cancelled'
            && $isFulfilledShipping
            && $afterCutover
            && $hasReservationEnabledLink
            && ! $hasAnyActiveOrStaleAlloc
            && ! $anyItemFulfilled;

        if ($isShippedNeverReserved) {
            $humanReviewReasons[] = 'shipped_during_outage_never_reserved';
            $secondaryAnomalies[] = 'shipped_during_outage_never_reserved';
        }

        // Cancelled order that was already fulfilled physically -> manual return review required!
        if ($status === 'cancelled' && $anyItemFulfilled) {
            $humanReviewReasons[] = 'cancelled_after_fulfillment';
            $secondaryAnomalies[] = 'cancelled_after_fulfillment';
        }

        // Active reservation on an ignored status or on a paid/shipped order without a valid enabled link
        if ($hasActiveReservation && $policyCategory === InventoryMeliOrderReservationPolicy::IGNORE) {
            $humanReviewReasons[] = 'active_reservation_on_ignored_status';
            $secondaryAnomalies[] = 'active_reservation_on_ignored_status';
        } elseif ($hasActiveReservation && $status === 'paid' && ! $isFulfilledShipping && ! $hasReservationEnabledLink) {
            $humanReviewReasons[] = 'active_reservation_without_valid_link';
            $secondaryAnomalies[] = 'active_reservation_without_valid_link';
        }

        if ($isCancelledWithActive) {
            $secondaryAnomalies[] = 'cancelled_orders_with_active_reservations';
        }
        if ($isShippedWithActive) {
            $secondaryAnomalies[] = 'shipped_orders_with_active_reservations';
        }
        if ($isPaidPending) {
            $secondaryAnomalies[] = 'paid_orders_pending_reservation';
        }

        // 12. Assign EXACTLY ONE primary classification using strict risk-first priority (1..7)!
        $humanReviewReasons = array_values(array_unique($humanReviewReasons));
        $ambiguityReasons = array_values(array_unique($ambiguityReasons));
        $secondaryAnomalies = array_values(array_unique($secondaryAnomalies));
        $noLinkReasons = array_values(array_unique($noLinkReasons));

        $primaryClassification = null;
        $reasonCodes = [];
        $candidateAction = null;
        $opAction = 'collect_missing_evidence';
        $requiresRemoteVerification = false;
        $hasPendingObligation = false;

        if ($humanReviewReasons !== []) {
            // Priority 1: pending_human_review
            $primaryClassification = self::PRIMARY_PENDING_HUMAN_REVIEW;
            $reasonCodes = array_values(array_unique(array_merge($humanReviewReasons, $ambiguityReasons)));
            $opAction = 'human_review_required';
            $requiresRemoteVerification = true;
            $hasPendingObligation = $isCancelledWithActive || $isShippedWithActive || $isPaidPending || $hasUnprocessedR3Revision;
        } elseif ($ambiguityReasons !== []) {
            // Priority 2: ambiguous_entities
            $primaryClassification = self::PRIMARY_AMBIGUOUS_ENTITIES;
            $reasonCodes = $ambiguityReasons;
            $opAction = 'resolve_ambiguity_before_action';
            $requiresRemoteVerification = true;
            $hasPendingObligation = $isCancelledWithActive || $isShippedWithActive || $isPaidPending || $hasUnprocessedR3Revision;
        } elseif ($isCancelledWithActive || $isShippedWithActive || $isPaidPending) {
            // Priority 3: reconstructible_candidates (only clean, non-ambiguous local obligations!)
            $primaryClassification = self::PRIMARY_RECONSTRUCTIBLE_CANDIDATES;
            $hasPendingObligation = true;
            if ($isCancelledWithActive) {
                $reasonCodes[] = 'cancelled_with_active_reservation';
                $candidateAction = 'release_active_reservation_for_cancelled_order';
            } elseif ($isShippedWithActive) {
                $reasonCodes[] = 'shipped_with_active_reservation';
                $candidateAction = 'fulfill_active_reservation_for_shipped_order';
            } else {
                $reasonCodes[] = 'paid_pending_reservation';
                $candidateAction = 'evaluate_reservation_for_paid_order';
            }
            if ($hasUnprocessedR3Revision) {
                $reasonCodes[] = 'r3_pending_revision_unprocessed';
            }
            $opAction = 'candidate_for_future_reconciliation_review';
            $requiresRemoteVerification = false;
        } elseif ($hasUnprocessedR3Revision) {
            // Unprocessed R3 revision with no clean local reconstructible action -> NEVER declare consistent!
            $primaryClassification = self::PRIMARY_PENDING_HUMAN_REVIEW;
            $reasonCodes = ['r3_pending_revision_unprocessed'];
            $opAction = 'human_review_required';
            $requiresRemoteVerification = true;
            $hasPendingObligation = true;
        } else {
            // Positive proofs for Priorities 4, 5, 6 — NEVER a blind `else`!
            $isPositivePaidConsistent = $status === 'paid'
                && ! $isFulfilledShipping
                && $cutoverVerifiable
                && $afterCutover
                && $allItemsHaveValidEnabledSimpleLink
                && $allItemsVerifiedActiveMatch
                && $hasActiveReservation;

            $isPositiveShippedFulfilled = $status !== 'cancelled'
                && $isFulfilledShipping
                && ! $hasAnyActiveOrStaleAlloc
                && $allItemsVerifiedFulfilled
                && $anyItemFulfilled;

            $isPositiveCancelledConsistent = $status === 'cancelled'
                && ! $hasAnyActiveOrStaleAlloc
                && ! $anyItemFulfilled
                && $orderItems !== [];

            if ($isPositivePaidConsistent || $isPositiveShippedFulfilled || $isPositiveCancelledConsistent) {
                // Priority 4: consistent_no_action_needed (positively demonstrated!)
                $primaryClassification = self::PRIMARY_CONSISTENT_NO_ACTION_NEEDED;
                if ($isPositivePaidConsistent) {
                    $reasonCodes[] = 'paid_consistent_active_reservation';
                } elseif ($isPositiveShippedFulfilled) {
                    $reasonCodes[] = 'shipped_consistent_fulfilled';
                } else {
                    $reasonCodes[] = 'cancelled_consistent_no_obligations';
                }
                $opAction = 'no_inventory_action_needed_verified';
                $requiresRemoteVerification = false;
            } elseif (! $hasAnyActiveOrStaleAlloc
                && ! $hasReservationEnabledLink
                && $noLinkReasons !== []
                && $policyCategory !== InventoryMeliOrderReservationPolicy::IGNORE
                && ($cutoverAt === null || $afterCutover)) {
                // Priority 5: out_of_scope_no_reservation_link (verified 0 active reservations, 0 anomalies)
                $primaryClassification = self::PRIMARY_OUT_OF_SCOPE_NO_LINK;
                $reasonCodes = $noLinkReasons;
                $opAction = 'out_of_scope_verify_before_queue_policy';
                $requiresRemoteVerification = false;
            } elseif (! $hasAnyActiveOrStaleAlloc
                && ($isValidPreCutover || $policyCategory === InventoryMeliOrderReservationPolicy::IGNORE)) {
                // Priority 6: out_of_scope_pre_cutover_or_ignored_status (verified valid date or ignored status)
                $primaryClassification = self::PRIMARY_OUT_OF_SCOPE_PRE_CUTOVER_OR_IGNORED;
                if ($isValidPreCutover) {
                    $reasonCodes[] = 'pre_cutover_valid';
                }
                if ($policyCategory === InventoryMeliOrderReservationPolicy::IGNORE) {
                    $reasonCodes[] = 'ignored_order_status';
                }
                $opAction = 'out_of_scope_verify_before_queue_policy';
                $requiresRemoteVerification = false;
            } else {
                // Priority 7: insufficient_evidence (safe explicit fallback!)
                $primaryClassification = self::PRIMARY_INSUFFICIENT_EVIDENCE;
                if ($hasReleasedAllocOnPaid) {
                    $reasonCodes[] = 'paid_order_with_released_allocation';
                    $missingEvidence[] = 'reason_for_prior_release_on_paid_order';
                }
                if (! $cutoverVerifiable) {
                    $reasonCodes[] = 'unverifiable_cutover_without_raw_date_created';
                }
                if ($isFulfilledShipping && ! $anyItemFulfilled) {
                    $reasonCodes[] = 'shipped_without_verifiable_fulfillment_evidence';
                    $missingEvidence[] = 'fulfillment_allocation_or_movement_missing';
                }
                if ($reasonCodes === []) {
                    $reasonCodes[] = 'cannot_positively_verify_consistency_or_scope';
                    $missingEvidence[] = 'positive_consistency_proof_incomplete';
                }
                $opAction = 'collect_missing_evidence';
                $requiresRemoteVerification = true;
            }
        }

        $allocationStatus = $this->summarizeAllocationStatus($allocs, $staleAllocations !== []);
        $reservationStatus = $this->summarizeReservationStatus(
            $liveActiveAllocs,
            $activeDirectRes,
            $activeDirectKitRes,
            $fulfilledDirectRes,
            $fulfilledDirectKitRes,
            $staleAllocations !== [],
            $allocs,
            $reservationsById,
            $kitReservationsById,
        );

        $entity = [
            'entity_type' => 'meli_order',
            'local_order_id' => $oid,
            'remote_order_id' => $remoteOrderId,
            'meli_account_id' => $accountId,
            'associated_job_ids' => $associatedJobIds,
            'associated_job_ids_truncated' => $associatedJobIdsTruncated,
            'associated_job_ids_sample_limit' => QueueManifestStreamReader::MAX_JOB_ID_SAMPLE_PER_ORDER,
            'associated_jobs_count' => $associatedJobsCount,
            'associated_unreserved_job_ids' => $associatedUnreservedJobIds,
            'associated_unreserved_job_ids_truncated' => $associatedUnreservedJobIdsTruncated,
            'associated_unreserved_jobs_count' => $associatedUnreservedJobsCount,
            'associated_reserved_job_ids' => $associatedReservedJobIds,
            'associated_reserved_job_ids_truncated' => $associatedReservedJobIdsTruncated,
            'associated_reserved_jobs_count' => $reservedJobsCount,
            'order_status' => $status !== '' ? $status : null,
            'shipping_status' => $shippingStatus !== '' ? $shippingStatus : null,
            'allocation_status' => $allocationStatus,
            'reservation_status' => $reservationStatus,
            'primary_classification' => $primaryClassification,
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'secondary_anomalies' => $secondaryAnomalies,
            'available_evidence' => [
                'exists_in_meli_orders' => true,
                'items_count' => count($orderItems),
                'has_reservation_enabled_link' => $hasReservationEnabledLink,
                'active_allocations_count' => count($activeAllocs),
                'live_active_allocations_count' => count($liveActiveAllocs),
                'fulfilled_allocations_count' => count($fulfilledAllocs),
                'released_allocations_count' => count($releasedAllocs),
                'diagnostic_allocations_count' => count($diagnosticAllocs),
                'stale_allocations_count' => count($staleAllocations),
                'active_direct_reservations_count' => count($activeDirectRes),
                'active_direct_kit_reservations_count' => count($activeDirectKitRes),
                'manual_movements_count' => count($matchedManualMovements),
                'after_cutover' => $afterCutover,
                'is_valid_pre_cutover' => $isValidPreCutover,
                'policy_category' => $policyCategory,
                'reserved_jobs_count' => $reservedJobsCount,
                'manifest_queue_state' => [
                    'manifest_unreserved_jobs_count' => $associatedUnreservedJobsCount,
                    'manifest_reserved_jobs_count' => $reservedJobsCount,
                    'reserved_at_reflects_manifest_snapshot_only' => true,
                    'live_queue_state_verified' => $liveQueueVerification !== null,
                    'live_queue_verification' => $liveQueueVerification,
                ],
                'r3_reconciliation' => $r3Evidence,
            ],
            'missing_evidence' => array_values(array_unique($missingEvidence)),
            'operational_recommendation' => [
                'action' => $opAction,
                'candidate_action' => $candidateAction,
                'automatic_execution_allowed' => false,
                'automatic_job_deletion_allowed' => false,
                'requires_remote_verification' => $requiresRemoteVerification,
            ],
        ];

        $legacySummary = [
            'meli_order_id' => $oid,
            'meli_account_id' => $accountId,
            'remote_order_id' => $remoteOrderId ?? '',
            'status' => $status,
            'shipping_status' => $shippingStatus !== '' ? $shippingStatus : null,
            'items_count' => count($orderItems),
            'active_allocations' => count($activeAllocs),
        ];

        return [
            'entity' => $entity,
            'legacy_flags' => [
                'missing_order' => false,
                'summary' => $legacySummary,
                'account_mismatch' => $accountMismatch,
                'ambiguous_link_keys' => $ambiguousLinkKeys,
                'missing_line_key_count' => $missingLineKeyCount,
                'is_cancelled_with_active' => $isCancelledWithActive,
                'is_shipped_with_active' => $isShippedWithActive,
                'is_paid_pending' => $isPaidPending,
                'is_shipped_never_reserved' => $isShippedNeverReserved,
                'matched_manual_movements' => $matchedManualMovements,
                'is_multiline' => $isMultiline,
                'involves_kit_or_component' => $involvesKitOrComponent,
                'kit_pids' => array_keys($kitPids),
                'component_pids' => array_keys($componentPids),
                'has_active_kit_reservation' => $activeDirectKitRes !== [],
            ],
            'summary_meta' => [
                'has_pending_obligation' => $hasPendingObligation,
            ],
        ];
    }

    /** @return array{data: ?array<string, mixed>, invalid: bool} */
    private function decodeJsonObject(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return ['data' => null, 'invalid' => false];
        }
        if (is_array($raw)) {
            return ['data' => $raw, 'invalid' => false];
        }
        if (! is_string($raw)) {
            return ['data' => null, 'invalid' => true];
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return ['data' => null, 'invalid' => false];
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return ['data' => null, 'invalid' => true];
        }

        return ['data' => $decoded, 'invalid' => false];
    }

    /**
     * @param  ?array<string, mixed>  $rawDecoded
     * @return array{
     *     invalid: bool,
     *     raw_date_created_valid: bool,
     *     raw_date_created: ?CarbonImmutable,
     *     effective_timestamp: ?CarbonImmutable,
     *     missing_raw_date_created: bool
     * }
     */
    private function auditOrderDates(object $order, ?array $rawDecoded): array
    {
        $hasRawDateCreatedKey = is_array($rawDecoded) && array_key_exists('date_created', $rawDecoded);
        if ($hasRawDateCreatedKey) {
            $parsed = $this->parseStrictIso8601WithTz($rawDecoded['date_created']);
            if ($parsed === null) {
                return [
                    'invalid' => true,
                    'raw_date_created_valid' => false,
                    'raw_date_created' => null,
                    'effective_timestamp' => null,
                    'missing_raw_date_created' => false,
                ];
            }

            return [
                'invalid' => false,
                'raw_date_created_valid' => true,
                'raw_date_created' => $parsed,
                'effective_timestamp' => $parsed,
                'missing_raw_date_created' => false,
            ];
        }

        // `raw.date_created` absent — inspect `meli_orders.created_at`.
        $rawCol = $order->created_at ?? null;
        if ($rawCol === null || trim((string) $rawCol) === '') {
            return [
                'invalid' => true,
                'raw_date_created_valid' => false,
                'raw_date_created' => null,
                'effective_timestamp' => null,
                'missing_raw_date_created' => true,
            ];
        }

        $fallbackTs = $this->parseStrictTimestamp((string) $rawCol);
        if ($fallbackTs === null) {
            return [
                'invalid' => true,
                'raw_date_created_valid' => false,
                'raw_date_created' => null,
                'effective_timestamp' => null,
                'missing_raw_date_created' => true,
            ];
        }

        return [
            'invalid' => false,
            'raw_date_created_valid' => false,
            'raw_date_created' => null,
            'effective_timestamp' => $fallbackTs,
            'missing_raw_date_created' => true,
        ];
    }

    private function parseStrictIso8601WithTz(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(Z|[+\-]\d{2}:\d{2})\z/i', $trimmed, $m) !== 1) {
            return null;
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];
        $hour = (int) $m[4];
        $min = (int) $m[5];
        $sec = (int) $m[6];

        if (! checkdate($month, $day, $year) || $hour > 23 || $min > 59 || $sec > 59) {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($trimmed);
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                return null;
            }

            return CarbonImmutable::instance($dt)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function parseStrictTimestamp(string $value): ?CarbonImmutable
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/\A(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(?:Z|[+\-]\d{2}:\d{2})?\z/i', $trimmed, $m) !== 1) {
            return null;
        }

        if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59) {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($trimmed);
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                return null;
            }

            return CarbonImmutable::instance($dt)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, mixed>  $rawOrderItems
     * @param  list<object>  $materializedItems
     */
    private function rawOrderItemsDifferFromMaterialized(array $rawOrderItems, array $materializedItems, bool $hasVariationCol): bool
    {
        $rawNorm = [];
        foreach ($rawOrderItems as $ri) {
            if (! is_array($ri) || ! is_array($ri['item'] ?? null)) {
                return true;
            }
            $itemId = is_scalar($ri['item']['id'] ?? null) ? trim((string) $ri['item']['id']) : '';
            $varRaw = $ri['item']['variation_id'] ?? $ri['variation_id'] ?? null;
            $var = (is_scalar($varRaw) && trim((string) $varRaw) !== '') ? trim((string) $varRaw) : '';
            $qty = is_numeric($ri['quantity'] ?? null) ? (int) $ri['quantity'] : 0;
            if ($itemId === '' || $qty <= 0) {
                return true;
            }
            $rawNorm[] = $itemId.'|'.$var.'|'.$qty;
        }

        $matNorm = [];
        foreach ($materializedItems as $mi) {
            $itemId = trim((string) ($mi->item_id ?? ''));
            $var = $hasVariationCol && $mi->variation_id !== null ? trim((string) $mi->variation_id) : '';
            $qty = (int) ($mi->quantity ?? 0);
            $matNorm[] = $itemId.'|'.$var.'|'.$qty;
        }

        sort($rawNorm);
        sort($matNorm);

        return $rawNorm !== $matNorm;
    }

    /**
     * @param  array<int, object>  $reservationsById
     * @param  array<int, object>  $kitReservationsById
     */
    private function resolveAllocationReservation(object $alloc, array $reservationsById, array $kitReservationsById): ?object
    {
        if ($alloc->reservation_id === null) {
            return null;
        }

        $rid = (int) $alloc->reservation_id;

        return ((string) ($alloc->reservation_kind ?? '') === 'KIT')
            ? ($kitReservationsById[$rid] ?? null)
            : ($reservationsById[$rid] ?? null);
    }

    /** @param list<object> $allocs */
    private function summarizeAllocationStatus(array $allocs, bool $hasStale): string
    {
        if ($allocs === []) {
            return 'NONE';
        }

        $statuses = [];
        foreach ($allocs as $a) {
            $s = strtoupper(trim((string) ($a->status ?? 'UNKNOWN')));
            $statuses[$s] = true;
        }
        if ($hasStale) {
            $statuses['STALE'] = true;
        }

        $keys = array_keys($statuses);
        sort($keys);

        return count($keys) === 1 ? $keys[0] : 'MIXED:'.implode(',', $keys);
    }

    /**
     * @param  list<object>  $liveActiveAllocs
     * @param  list<object>  $activeDirectRes
     * @param  list<object>  $activeDirectKitRes
     * @param  list<object>  $fulfilledDirectRes
     * @param  list<object>  $fulfilledDirectKitRes
     * @param  list<object>  $allocs
     * @param  array<int, object>  $reservationsById
     * @param  array<int, object>  $kitReservationsById
     */
    private function summarizeReservationStatus(
        array $liveActiveAllocs,
        array $activeDirectRes,
        array $activeDirectKitRes,
        array $fulfilledDirectRes,
        array $fulfilledDirectKitRes,
        bool $hasStale,
        array $allocs,
        array $reservationsById,
        array $kitReservationsById,
    ): string {
        $statuses = [];

        if ($liveActiveAllocs !== [] || $activeDirectRes !== []) {
            $statuses['ACTIVE'] = true;
        }
        if ($activeDirectKitRes !== []) {
            $statuses['KIT_ACTIVE'] = true;
        }
        if ($fulfilledDirectRes !== [] || $fulfilledDirectKitRes !== []) {
            $statuses['FULFILLED'] = true;
        }
        if ($hasStale) {
            $statuses['STALE'] = true;
        }

        foreach ($allocs as $a) {
            $res = $this->resolveAllocationReservation($a, $reservationsById, $kitReservationsById);
            if ($res !== null) {
                $statuses[strtoupper(trim((string) ($res->status ?? 'UNKNOWN')))] = true;
            }
        }

        if ($statuses === []) {
            return 'NONE';
        }

        $keys = array_keys($statuses);
        sort($keys);

        return count($keys) === 1 ? $keys[0] : 'MIXED:'.implode(',', $keys);
    }
}
