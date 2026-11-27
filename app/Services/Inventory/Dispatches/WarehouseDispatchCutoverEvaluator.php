<?php

namespace App\Services\Inventory\Dispatches;

use App\Models\InventoryDispatchItem;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryShelfAudit;
use App\Services\Inventory\ShelfAuditService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class WarehouseDispatchCutoverEvaluator
{
    public const DECISION_CLEAR = 'CLEAR';

    public const DECISION_SKIPPED_PRE_COUNT = InventoryDispatchItem::APPLY_SKIPPED_PRE_PHYSICAL_COUNT;

    public const DECISION_NEEDS_RECONCILIATION = InventoryDispatchItem::APPLY_NEEDS_RECONCILIATION;

    /**
     * Evaluate whether a registered item delivered at $effectiveAtUtc on ($productId, $locationId)
     * can safely be deducted from the ledger without risking double-deduction against a physical count.
     *
     * Rules (H01 / Section 4.3):
     * - Independent of the 24h retroactive permission tolerance (evaluated for EVERY registered item).
     * - Distinguishes delivery effective_at, audit start (created_at), verified count timestamp (when present),
     *   and adjustment apply timestamp (applied_at).
     * - Shelf audits apply only to the locations actually covered by that shelf/location.
     * - Brand audits are NOT assumed to be complete location counts; overlapping/subsequent brand audits
     *   yield NEEDS_RECONCILIATION rather than automatic deduction or blind skip.
     * - Unverified INITIAL movements (without verified physical-count metadata) at or after effective_at
     *   yield NEEDS_RECONCILIATION (never auto-deduct, never assume complete physical count without evidence).
     * - Exact timestamp equality (effective_at == cutover) always yields NEEDS_RECONCILIATION.
     *
     * @return array{
     *     decision: string,
     *     reference_type: string,
     *     reference_id: ?int,
     *     boundary_at: ?Carbon,
     *     reason: string
     * }
     */
    public function evaluate(int $productId, ?int $locationId, Carbon $effectiveAtUtc): array
    {
        $effectiveUtc = $effectiveAtUtc->copy()->utc();

        if ($locationId === null || $locationId <= 0) {
            return [
                'decision' => self::DECISION_NEEDS_RECONCILIATION,
                'reference_type' => 'NONE',
                'reference_id' => null,
                'boundary_at' => null,
                'reason' => 'No se especificó ubicación física de salida; requiere conciliación manual.',
            ];
        }

        $location = InventoryLocation::query()->find($locationId, ['id', 'code']);
        $locationShelfCode = $location ? ShelfAuditService::extractShelfCode((string) $location->code) : null;

        // 1. Evaluate physical audits (inventory_shelf_audits) covering this product & location
        if (Schema::hasTable('inventory_shelf_audits') && Schema::hasTable('inventory_shelf_audit_items')) {
            $auditDecision = $this->evaluateAudits($productId, $locationId, $locationShelfCode, $effectiveUtc);
            if ($auditDecision !== null) {
                return $auditDecision;
            }
        }

        // 2. Evaluate INITIAL movements for this (product, location)
        if (Schema::hasTable('inventory_movements')) {
            $initialDecision = $this->evaluateInitialMovements($productId, $locationId, $effectiveUtc);
            if ($initialDecision !== null) {
                return $initialDecision;
            }
        }

        return [
            'decision' => self::DECISION_CLEAR,
            'reference_type' => 'NONE',
            'reference_id' => null,
            'boundary_at' => null,
            'reason' => 'Sin conteos físicos ni movimientos INITIAL posteriores o superpuestos para producto y ubicación.',
        ];
    }

    /**
     * @return ?array{decision: string, reference_type: string, reference_id: ?int, boundary_at: ?Carbon, reason: string}
     */
    private function evaluateAudits(
        int $productId,
        int $locationId,
        ?string $locationShelfCode,
        Carbon $effectiveUtc,
    ): ?array {
        $hasAuditType = Schema::hasColumn('inventory_shelf_audits', 'audit_type');
        $hasShelfCode = Schema::hasColumn('inventory_shelf_audits', 'shelf_code');
        $hasCountedAt = Schema::hasColumn('inventory_shelf_audits', 'counted_at');

        $columns = [
            'inventory_shelf_audits.id',
            'inventory_shelf_audits.inventory_location_id',
            'inventory_shelf_audits.status',
            'inventory_shelf_audits.created_at',
            'inventory_shelf_audits.applied_at',
        ];
        if ($hasAuditType) {
            $columns[] = 'inventory_shelf_audits.audit_type';
        }
        if ($hasShelfCode) {
            $columns[] = 'inventory_shelf_audits.shelf_code';
        }
        if ($hasCountedAt) {
            $columns[] = 'inventory_shelf_audits.counted_at';
        }

        $audits = InventoryShelfAudit::query()
            ->join('inventory_shelf_audit_items as items', 'items.inventory_shelf_audit_id', '=', 'inventory_shelf_audits.id')
            ->where('items.inventory_product_id', $productId)
            ->whereIn('inventory_shelf_audits.status', [
                InventoryShelfAudit::STATUS_IN_PROGRESS,
                InventoryShelfAudit::STATUS_APPLIED,
            ])
            ->select($columns)
            ->orderByDesc('inventory_shelf_audits.applied_at')
            ->orderByDesc('inventory_shelf_audits.created_at')
            ->get();

        $needsReconciliationMatch = null;
        $preCountMatch = null;

        foreach ($audits as $audit) {
            $auditType = $hasAuditType ? (string) ($audit->audit_type ?? 'shelf') : 'shelf';
            $auditShelfCode = $hasShelfCode ? ($audit->shelf_code !== null ? (string) $audit->shelf_code : null) : null;

            $coversLocation = false;
            if ($auditType === 'brand') {
                $coversLocation = true;
            } elseif ((int) $audit->inventory_location_id === $locationId) {
                $coversLocation = true;
            } elseif ($auditShelfCode !== null && $locationShelfCode !== null && strtoupper($auditShelfCode) === strtoupper($locationShelfCode)) {
                $coversLocation = true;
            }

            if (! $coversLocation) {
                continue;
            }

            $startUtc = $audit->created_at ? Carbon::parse($audit->created_at)->utc() : null;
            $appliedUtc = $audit->applied_at ? Carbon::parse($audit->applied_at)->utc() : null;
            $countedUtc = ($hasCountedAt && ! empty($audit->counted_at))
                ? Carbon::parse($audit->counted_at)->utc()
                : null;

            if ($audit->status === InventoryShelfAudit::STATUS_IN_PROGRESS) {
                $needsReconciliationMatch = [
                    'decision' => self::DECISION_NEEDS_RECONCILIATION,
                    'reference_type' => 'SHELF_AUDIT',
                    'reference_id' => (int) $audit->id,
                    'boundary_at' => $countedUtc ?? $startUtc,
                    'reason' => "Existe una auditoría física en progreso (#{$audit->id}) para este producto/ubicación; requiere conciliación manual.",
                ];

                continue;
            }

            // APPLIED audit
            if ($auditType === 'brand') {
                // Brand audits are NOT guaranteed to be a complete count of every single location.
                // Never auto-deduct if effective_at <= applied_at (or <= created_at).
                $latestBrandTs = $appliedUtc ?? $startUtc;
                if ($latestBrandTs !== null && $effectiveUtc->lte($latestBrandTs)) {
                    $needsReconciliationMatch = [
                        'decision' => self::DECISION_NEEDS_RECONCILIATION,
                        'reference_type' => 'BRAND_AUDIT',
                        'reference_id' => (int) $audit->id,
                        'boundary_at' => $countedUtc ?? $startUtc ?? $appliedUtc,
                        'reason' => "Existe una auditoría por marca aplicada (#{$audit->id}) posterior o contemporánea a la entrega; no se asume cobertura total de ubicaciones y requiere conciliación.",
                    ];
                }

                continue;
            }

            // Shelf audit covering this exact location/shelf
            $windowStart = $countedUtc ?? $startUtc;
            $windowEnd = $appliedUtc ?? $countedUtc ?? $startUtc;

            if ($windowStart !== null && $effectiveUtc->lt($windowStart)) {
                // Delivery occurred strictly BEFORE the audit/count started: already absorbed by the physical count.
                if ($preCountMatch === null) {
                    $preCountMatch = [
                        'decision' => self::DECISION_SKIPPED_PRE_COUNT,
                        'reference_type' => 'SHELF_AUDIT',
                        'reference_id' => (int) $audit->id,
                        'boundary_at' => $windowStart,
                        'reason' => "Entrega anterior al inicio del conteo físico aplicado (#{$audit->id}); omitida para evitar doble descuento.",
                    ];
                }

                continue;
            }

            if ($windowEnd !== null && $effectiveUtc->lte($windowEnd)) {
                // Ambiguous window: audit started at/before effective_at AND applied at/after effective_at
                // (or exact equality with counted_at / applied_at).
                $needsReconciliationMatch = [
                    'decision' => self::DECISION_NEEDS_RECONCILIATION,
                    'reference_type' => 'SHELF_AUDIT',
                    'reference_id' => (int) $audit->id,
                    'boundary_at' => $windowStart ?? $windowEnd,
                    'reason' => "La fecha efectiva coincide o cae dentro de la ventana ambigua de auditoría física (#{$audit->id}); requiere conciliación manual.",
                ];
            }
        }

        // If any audit indicates an ambiguous/in-progress overlap, fail-safe to NEEDS_RECONCILIATION.
        if ($needsReconciliationMatch !== null) {
            return $needsReconciliationMatch;
        }

        return $preCountMatch;
    }

    /**
     * @return ?array{decision: string, reference_type: string, reference_id: ?int, boundary_at: ?Carbon, reason: string}
     */
    private function evaluateInitialMovements(int $productId, int $locationId, Carbon $effectiveUtc): ?array
    {
        $initials = InventoryMovement::query()
            ->where('inventory_product_id', $productId)
            ->where('inventory_location_id', $locationId)
            ->where('type', InventoryMovement::INITIAL)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get(['id', 'occurred_at', 'metadata']);

        foreach ($initials as $movement) {
            $occurredUtc = $movement->occurred_at ? Carbon::parse($movement->occurred_at)->utc() : null;
            $metadata = is_array($movement->metadata) ? $movement->metadata : [];

            $isVerifiedCount = ($metadata['is_verified_physical_count'] ?? false) === true
                && ! empty($metadata['physical_count_at']);

            if ($isVerifiedCount) {
                $verifiedBoundaryUtc = Carbon::parse((string) $metadata['physical_count_at'])->utc();

                if ($effectiveUtc->lt($verifiedBoundaryUtc)) {
                    return [
                        'decision' => self::DECISION_SKIPPED_PRE_COUNT,
                        'reference_type' => 'INITIAL_VERIFIED',
                        'reference_id' => (int) $movement->id,
                        'boundary_at' => $verifiedBoundaryUtc,
                        'reason' => "Entrega anterior a conteo físico inicial verificado (movimiento #{$movement->id}); omitida para evitar doble descuento.",
                    ];
                }

                if ($effectiveUtc->equalTo($verifiedBoundaryUtc)) {
                    return [
                        'decision' => self::DECISION_NEEDS_RECONCILIATION,
                        'reference_type' => 'INITIAL_VERIFIED',
                        'reference_id' => (int) $movement->id,
                        'boundary_at' => $verifiedBoundaryUtc,
                        'reason' => "La fecha efectiva coincide exactamente con el conteo físico inicial (#{$movement->id}); requiere conciliación manual.",
                    ];
                }

                continue;
            }

            // Unverified INITIAL movement: never assume it was a complete physical count,
            // and never auto-deduct deliveries at or before its occurred_at.
            if ($occurredUtc !== null && $effectiveUtc->lte($occurredUtc)) {
                return [
                    'decision' => self::DECISION_NEEDS_RECONCILIATION,
                    'reference_type' => 'INITIAL_UNVERIFIED',
                    'reference_id' => (int) $movement->id,
                    'boundary_at' => $occurredUtc,
                    'reason' => "Existe un movimiento INITIAL sin metadatos de conteo verificado (#{$movement->id}) en fecha igual o posterior a la entrega; requiere conciliación manual.",
                ];
            }
        }

        return null;
    }
}
