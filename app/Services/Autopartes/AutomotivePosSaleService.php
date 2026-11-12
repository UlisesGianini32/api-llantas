<?php

namespace App\Services\Autopartes;

use App\Exceptions\InventoryInsufficientStockException;
use App\Models\AutomotiveBranch;
use App\Models\AutomotiveDiscountApproval;
use App\Models\AutomotiveInventoryBalance;
use App\Models\AutomotiveInventoryMovement;
use App\Models\AutomotiveLocation;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartBranchSetting;
use App\Models\AutomotivePosPayment;
use App\Models\AutomotivePosReturn;
use App\Models\AutomotivePosReturnItem;
use App\Models\AutomotivePosSale;
use App\Models\AutomotivePosSaleItem;
use App\Models\AutomotivePosShift;
use App\Models\AutomotiveSeller;
use App\Models\AutopartesModel;
use App\Models\User;
use App\Support\AutopartesUserDirectory;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AutomotivePosSaleService
{
    public function __construct(
        private readonly BranchCatalogAccess $access,
        private readonly AutomotivePriceService $priceService,
        private readonly AutomotiveDiscountService $discountService,
        private readonly AutomotiveSellerService $sellerService,
        private readonly AutomotiveShiftService $shiftService,
        private readonly AutomotiveBarcodeLookupService $barcodeLookupService,
        private readonly AutomotiveStockService $stockService,
        private readonly AutomotiveMovementService $movementService,
    ) {}

    /**
     * Search autoparts for the branch POS using strictly the isolated 'autopartes' database.
     *
     * @return list<array<string, mixed>>
     */
    public function searchProductsForPos(
        AutomotiveBranch $branch,
        string $query,
        ?int $locationId,
        User $actor,
        int $limit = 30,
    ): array {
        if (
            ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::POS_VIEW)
            && ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::POS_SELL)
        ) {
            $this->access->authorize($actor, $branch, BranchCatalogAccess::POS_VIEW);
        }

        $location = $this->resolveBranchLocation($branch, $locationId);
        $trimmed = trim($query);

        $partsQuery = AutomotivePart::query()->with(['identifiers']);

        if ($trimmed === '') {
            $partsQuery->whereHas('branchSettings', function ($sq) use ($branch): void {
                $sq->where('branch_id', $branch->getKey())
                    ->where('is_sellable', true)
                    ->whereNotNull('sale_price_mxn')
                    ->where('sale_price_mxn', '>', 0);
            });
        } else {
            $like = '%'.addcslashes($trimmed, '%_\\').'%';
            $upper = mb_strtoupper($trimmed);
            $partsQuery->where(function ($sub) use ($like, $upper): void {
                $sub->where('code', 'like', $like)
                    ->orWhere('item_number', 'like', $like)
                    ->orWhere('manufacturer_part_number', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('category', 'like', $like)
                    ->orWhere('verified_barcode', $upper)
                    ->orWhere('verified_barcode', 'like', $like)
                    ->orWhereHas('identifiers', fn ($iq) => $iq->where('is_active', true)->where('identifier_value', 'like', $like));
            });
        }

        $parts = $partsQuery->orderBy('id')->limit($limit)->get();

        return $this->formatPartsForPos($branch, $parts->all(), $location);
    }

    /**
     * Barcode / exact code lookup for USB scanner in POS.
     * Never auto-selects when multiple candidates match, and blocks ambiguous parts.
     *
     * @return array<string, mixed>
     */
    public function lookupCodeForPos(
        AutomotiveBranch $branch,
        string $code,
        ?int $locationId,
        User $actor,
    ): array {
        if (
            ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::POS_VIEW)
            && ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::POS_SELL)
        ) {
            $this->access->authorize($actor, $branch, BranchCatalogAccess::POS_VIEW);
        }

        $location = $this->resolveBranchLocation($branch, $locationId);
        $lookup = $this->barcodeLookupService->lookup($actor, $branch, $code);

        if (($lookup['match_status'] ?? '') === AutomotiveBarcodeLookupService::MATCH_NOT_FOUND || empty($lookup['candidates'])) {
            return [
                'status' => 'not_found',
                'code' => trim($code),
                'can_add_to_cart' => false,
                'message' => $lookup['warning'] ?? 'No se encontró ninguna autoparte con el código escaneado.',
                'candidates' => [],
            ];
        }

        $partIds = array_values(array_filter(array_map(
            fn (array $c): int => (int) ($c['automotive_part_id'] ?? 0),
            $lookup['candidates']
        )));

        $parts = AutomotivePart::query()
            ->whereIn('id', $partIds)
            ->orderBy('id')
            ->get();

        $formatted = $this->formatPartsForPos($branch, $parts->all(), $location);

        if (count($formatted) > 1 || ($lookup['match_status'] ?? '') === AutomotiveBarcodeLookupService::MATCH_AMBIGUOUS || ($lookup['requires_disambiguation'] ?? false)) {
            $hasAmbiguous = collect($formatted)->contains(fn (array $p): bool => $p['identity_status'] === AutomotivePart::STATUS_AMBIGUOUS);

            return [
                'status' => $hasAmbiguous ? 'ambiguous' : 'multiple',
                'code' => trim($code),
                'can_add_to_cart' => false,
                'message' => $hasAmbiguous
                    ? 'El código corresponde a una identidad ambigua pendiente de verificación física. No puede venderse sin desambiguación.'
                    : 'Se encontraron múltiples autopartes candidatas. Seleccione manualmente la pieza verificada.',
                'candidates' => $formatted,
            ];
        }

        $single = $formatted[0];

        if (! $single['eligible']) {
            return [
                'status' => 'ineligible',
                'code' => trim($code),
                'can_add_to_cart' => false,
                'message' => implode(' ', $single['reasons']),
                'candidates' => [$single],
                'part' => $single,
            ];
        }

        return [
            'status' => 'exact_eligible',
            'code' => trim($code),
            'can_add_to_cart' => true,
            'message' => 'Autoparte verificada y disponible para venta.',
            'candidates' => [$single],
            'part' => $single,
        ];
    }

    /**
     * @param  list<AutomotivePart>  $parts
     * @return list<array<string, mixed>>
     */
    private function formatPartsForPos(AutomotiveBranch $branch, array $parts, AutomotiveLocation $location): array
    {
        if (empty($parts)) {
            return [];
        }

        $partIds = array_map(fn (AutomotivePart $p): int => (int) $p->getKey(), $parts);
        $settingsByPart = AutomotivePartBranchSetting::query()
            ->where('branch_id', $branch->getKey())
            ->whereIn('automotive_part_id', $partIds)
            ->get()
            ->keyBy('automotive_part_id');

        $stockByPart = $this->stockService->stockSummaryForPartIds($branch, $partIds, (int) $location->getKey());

        $result = [];
        foreach ($parts as $part) {
            $pid = (int) $part->getKey();
            $setting = $settingsByPart->get($pid);
            $eval = $this->priceService->evaluateSellability(
                $branch,
                $part,
                $location,
                $setting,
                $stockByPart[$pid] ?? ['physical' => 0, 'reserved' => 0, 'available' => 0]
            );

            $result[] = [
                'automotive_part_id' => $pid,
                'code' => (string) $part->code,
                'item_number' => $part->item_number,
                'manufacturer_part_number' => $part->manufacturer_part_number,
                'description' => (string) ($part->description ?? $part->code),
                'category' => $part->category,
                'subcategory' => $part->subcategory,
                'vendor_name' => $part->vendor_name,
                'applicable_models' => $part->applicable_models,
                'verified_barcode' => $part->verified_barcode,
                'identity_status' => $eval['identity_status'],
                'sale_price_mxn' => $eval['sale_price_mxn'],
                'is_sellable' => $eval['is_sellable'],
                'sale_blocked' => $eval['sale_blocked'],
                'available_stock' => $eval['available_stock'],
                'physical_stock' => $eval['physical_stock'],
                'reserved_stock' => $eval['reserved_stock'],
                'automotive_location_id' => $location->getKey(),
                'location_id' => $location->getKey(),
                'location_code' => $location->code,
                'eligible' => $eval['eligible'],
                'reasons' => $eval['reasons'],
            ];
        }

        return $result;
    }

    /**
     * Calculate server-side cart totals, wholesale discounts, manual discounts, and taxes.
     * Browser-sent prices and discount amounts are completely ignored.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, array{physical: int, reserved: int, available: int}>  $lockedStocksByKey
     * @return array<string, mixed>
     */
    public function calculateCart(
        AutomotiveBranch $branch,
        array $payload,
        User $actor,
        bool $enforceManualApproval = false,
        ?AutomotiveLocation $defaultLocation = null,
        array $lockedStocksByKey = [],
    ): array {
        if (
            ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::POS_VIEW)
            && ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::POS_SELL)
            && ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::DISCOUNTS_APPROVE)
        ) {
            $this->access->authorize($actor, $branch, BranchCatalogAccess::POS_VIEW);
        }

        $fallbackLocation = $defaultLocation ?? $this->resolveBranchLocation(
            $branch,
            isset($payload['automotive_location_id']) ? (int) $payload['automotive_location_id'] : null
        );
        $consolidated = $this->consolidateCartItems($branch, $payload['items'] ?? [], $fallbackLocation);

        $normalizedLines = [];
        foreach ($consolidated as $entry) {
            $partId = $entry['automotive_part_id'];
            $location = $entry['location'];
            $quantity = $entry['quantity'];

            $part = AutomotivePart::query()->find($partId);
            if (! $part) {
                throw ValidationException::withMessages([
                    'items' => "La autoparte #{$partId} no pertenece al catálogo exclusivo de Autopartes.",
                ]);
            }

            $setting = AutomotivePartBranchSetting::query()
                ->where('automotive_part_id', $partId)
                ->where('branch_id', $branch->getKey())
                ->first();

            $pairKey = "{$partId}:{$location->getKey()}";
            $precomputed = $lockedStocksByKey[$pairKey] ?? null;

            $eval = $this->priceService->evaluateSellability($branch, $part, $location, $setting, $precomputed);
            if (! $eval['eligible'] || $eval['sale_price_mxn'] === null) {
                throw ValidationException::withMessages([
                    'items' => sprintf(
                        'La autoparte [%s] no es elegible para venta en %s: %s',
                        $part->item_number ?: $part->code,
                        $branch->code,
                        implode(' ', $eval['reasons'])
                    ),
                ]);
            }

            if ($quantity > $eval['available_stock']) {
                throw ValidationException::withMessages([
                    'stock' => sprintf(
                        'Stock insuficiente en %s (%s) para [%s]. Disponible: %d, solicitado: %d.',
                        $branch->code,
                        $location->code,
                        $part->item_number ?: $part->code,
                        $eval['available_stock'],
                        $quantity,
                    ),
                ]);
            }

            $unitPriceCents = AutomotiveDiscountService::toCents($eval['sale_price_mxn']);
            $lineGrossCents = $unitPriceCents * $quantity;

            $normalizedLines[] = [
                'automotive_part_id' => (int) $part->getKey(),
                'automotive_location_id' => (int) $location->getKey(),
                'location_code' => (string) $location->code,
                'code' => (string) $part->code,
                'item_number' => $part->item_number,
                'manufacturer_part_number' => $part->manufacturer_part_number,
                'description' => (string) ($part->description ?: $part->code),
                'category' => $part->category,
                'quantity' => $quantity,
                'available_stock' => $eval['available_stock'],
                'unit_price_cents' => $unitPriceCents,
                'line_gross_cents' => $lineGrossCents,
                'wholesale_discount_cents' => 0,
                'manual_discount_cents' => 0,
            ];
        }

        $wholesaleEval = $this->discountService->evaluateWholesaleDiscounts($branch, $normalizedLines);
        foreach ($normalizedLines as $idx => &$line) {
            $line['wholesale_discount_cents'] = $wholesaleEval['line_discounts_cents'][$idx] ?? 0;
        }
        unset($line);

        $manualEval = $this->discountService->evaluateManualDiscount(
            $branch,
            $normalizedLines,
            is_array($payload['manual_discount'] ?? null) ? $payload['manual_discount'] : null,
            $wholesaleEval['allows_manual_combination'],
            $actor,
            $enforceManualApproval,
        );

        $policy = $branch->ensureDefaultCommercialPolicy();
        $taxEnabled = (bool) $policy->tax_enabled || (bool) config('autopartes.pos.tax_enabled', false);
        $taxRate = $taxEnabled
            ? max(0.0, (float) ($policy->tax_rate > 0 ? $policy->tax_rate : config('autopartes.pos.tax_rate', 0.0)))
            : 0.0;

        $subtotalCents = 0;
        $totalWholesaleCents = 0;
        $totalManualCents = 0;
        $totalDiscountCents = 0;
        $totalTaxCents = 0;
        $totalCents = 0;

        $calculatedItems = [];
        foreach ($normalizedLines as $idx => $line) {
            $wDisc = (int) $line['wholesale_discount_cents'];
            $mDisc = (int) ($manualEval['line_manual_discounts_cents'][$idx] ?? 0);
            $lineDisc = $wDisc + $mDisc;
            $lineNetBeforeTax = max(0, $line['line_gross_cents'] - $lineDisc);
            $lineTax = $taxRate > 0 ? (int) round($lineNetBeforeTax * $taxRate) : 0;
            $lineSubtotal = $lineNetBeforeTax + $lineTax;

            $subtotalCents += $line['line_gross_cents'];
            $totalWholesaleCents += $wDisc;
            $totalManualCents += $mDisc;
            $totalDiscountCents += $lineDisc;
            $totalTaxCents += $lineTax;
            $totalCents += $lineSubtotal;

            $calculatedItems[] = [
                'automotive_part_id' => $line['automotive_part_id'],
                'automotive_location_id' => $line['automotive_location_id'],
                'location_code' => $line['location_code'],
                'code' => $line['code'],
                'item_number' => $line['item_number'],
                'manufacturer_part_number' => $line['manufacturer_part_number'],
                'description' => $line['description'],
                'category' => $line['category'],
                'quantity' => $line['quantity'],
                'available_stock' => $line['available_stock'],
                'unit_price_mxn' => AutomotiveDiscountService::fromCents($line['unit_price_cents']),
                'line_gross_amount' => AutomotiveDiscountService::fromCents($line['line_gross_cents']),
                'wholesale_discount_amount' => AutomotiveDiscountService::fromCents($wDisc),
                'manual_discount_amount' => AutomotiveDiscountService::fromCents($mDisc),
                'discount_amount' => AutomotiveDiscountService::fromCents($lineDisc),
                'tax_amount' => AutomotiveDiscountService::fromCents($lineTax),
                'subtotal' => AutomotiveDiscountService::fromCents($lineSubtotal),
            ];
        }

        if ($totalCents <= 0) {
            throw ValidationException::withMessages([
                'total' => 'El total de la venta debe ser mayor a $0.00 MXN.',
            ]);
        }

        $cartHash = $this->discountService->computeCartHash($branch, $normalizedLines);

        return [
            'subtotal_cents' => $subtotalCents,
            'wholesale_discount_cents' => $totalWholesaleCents,
            'manual_discount_cents' => $totalManualCents,
            'discount_cents' => $totalDiscountCents,
            'tax_cents' => $totalTaxCents,
            'total_cents' => $totalCents,
            'subtotal' => AutomotiveDiscountService::fromCents($subtotalCents),
            'wholesale_discount_amount' => AutomotiveDiscountService::fromCents($totalWholesaleCents),
            'manual_discount_amount' => AutomotiveDiscountService::fromCents($totalManualCents),
            'discount_amount' => AutomotiveDiscountService::fromCents($totalDiscountCents),
            'tax_rate' => number_format($taxRate, 4, '.', ''),
            'tax_amount' => AutomotiveDiscountService::fromCents($totalTaxCents),
            'total' => AutomotiveDiscountService::fromCents($totalCents),
            'cart_hash' => $cartHash,
            'applied_wholesale_rules' => $wholesaleEval['applied_rules'],
            'wholesale_allows_manual_combination' => $wholesaleEval['allows_manual_combination'],
            'manual_discount_requires_approval' => $manualEval['requires_approval'],
            'manual_discount_approval_id' => $manualEval['approval_id'],
            'manual_discount_snapshot' => $manualEval['snapshot'],
            'items' => $calculatedItems,
            'normalized_lines' => $normalizedLines,
        ];
    }

    /**
     * Request administrative approval for a manual discount on the current cart.
     *
     * @param  array<string, mixed>  $payload
     */
    public function requestCartManualDiscountApproval(
        AutomotiveBranch $branch,
        array $payload,
        User $requester,
        ?User $approver = null,
    ): AutomotiveDiscountApproval {
        $calc = $this->calculateCart($branch, $payload, $requester, false);

        return $this->discountService->requestApproval(
            $branch,
            $calc['normalized_lines'],
            is_array($payload['manual_discount'] ?? null) ? $payload['manual_discount'] : [],
            $calc['wholesale_allows_manual_combination'],
            $requester,
            $approver,
        );
    }

    /**
     * Transactional, concurrency-safe and idempotent POS sale confirmation on 'autopartes' connection.
     *
     * @param  array<string, mixed>  $payload
     */
    public function confirmSale(AutomotiveBranch $branch, array $payload, User $cashier): AutomotivePosSale
    {
        $this->access->authorize($cashier, $branch, BranchCatalogAccess::POS_SELL);

        $policy = $branch->ensureDefaultCommercialPolicy();
        $salesEnabled = (bool) $policy->pos_sales_enabled || (bool) config('autopartes.pos.sales_enabled_by_default', false);
        $fiscalValidated = (bool) $policy->fiscal_policy_validated || (bool) config('autopartes.pos.fiscal_policy_validated_by_default', false);
        if (! $salesEnabled || ! $fiscalValidated) {
            throw ValidationException::withMessages([
                'commercial_policy' => 'Las ventas reales en '.$branch->code.' permanecen bloqueadas hasta validar la configuración fiscal aplicable y habilitar la operación comercial autorizada.',
            ]);
        }

        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) < 8 || mb_strlen($idempotencyKey) > 191) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Se requiere una clave de idempotencia única (8 a 191 caracteres) para confirmar la venta.',
            ]);
        }

        $sellerId = (int) ($payload['seller_id'] ?? 0);
        if ($sellerId <= 0) {
            throw ValidationException::withMessages([
                'seller_id' => 'Debe seleccionar al vendedor autorizado de '.$branch->code.' que atendió la venta.',
            ]);
        }

        $paymentMethod = strtolower(trim((string) ($payload['payment_method'] ?? '')));
        if ($paymentMethod === 'credit') {
            throw ValidationException::withMessages([
                'payment_method' => 'Las ventas a crédito no están habilitadas en el punto de venta de Autopartes.',
            ]);
        }
        if (! in_array($paymentMethod, AutomotivePosSale::PAYMENT_METHODS, true)) {
            throw ValidationException::withMessages([
                'payment_method' => 'Método de pago inválido. Utilice cash, card, transfer o mixed.',
            ]);
        }

        $payloadHash = $this->computeSalePayloadHash($branch, $payload);

        $existingBeforeTx = AutomotivePosSale::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingBeforeTx) {
            return $this->resolveExistingIdempotentSale($existingBeforeTx, $payloadHash);
        }

        $normalizedEntries = $this->normalizeRawCartEntries($payload['items'] ?? []);

        try {
            return AutopartesModel::transaction(function () use ($branch, $payload, $cashier, $idempotencyKey, $payloadHash, $sellerId, $paymentMethod, $normalizedEntries): AutomotivePosSale {
                // 1. Acquire locking reads FIRST in deterministic order BEFORE any non-locking SELECT
                // so MariaDB InnoDB REPEATABLE READ never evaluates a stale consistent snapshot.
                $shiftId = ! empty($payload['automotive_pos_shift_id']) ? (int) $payload['automotive_pos_shift_id'] : null;
                $shiftQuery = AutomotivePosShift::query()
                    ->where('branch_id', $branch->getKey())
                    ->lockForUpdate();

                if ($shiftId !== null) {
                    $shift = $shiftQuery->whereKey($shiftId)->first();
                } else {
                    $shift = $shiftQuery
                        ->where('cashier_id', $cashier->getKey())
                        ->where('status', AutomotivePosShift::STATUS_OPEN)
                        ->latest('opened_at')
                        ->first();
                }

                if (! $shift || ! $shift->isOpen()) {
                    throw ValidationException::withMessages([
                        'shift' => 'No existe un turno de caja abierto y válido en '.$branch->code.' para registrar la venta.',
                    ]);
                }

                $register = $shift->cashRegister()->lockForUpdate()->first();
                if (! $register || ! $register->is_active || (int) $register->branch_id !== (int) $branch->getKey()) {
                    throw ValidationException::withMessages([
                        'cash_register_id' => 'La caja asociada al turno no es válida o está inactiva.',
                    ]);
                }

                $seller = AutomotiveSeller::query()
                    ->whereKey($sellerId)
                    ->lockForUpdate()
                    ->first();

                if (! $seller || (int) $seller->branch_id !== (int) $branch->getKey()) {
                    throw ValidationException::withMessages([
                        'seller_id' => 'El vendedor seleccionado no pertenece a la sucursal '.$branch->code.'.',
                    ]);
                }
                if (! $seller->is_active) {
                    throw ValidationException::withMessages([
                        'seller_id' => "El vendedor [{$seller->code} — {$seller->full_name}] está inactivo.",
                    ]);
                }

                $shiftLocation = AutomotiveLocation::query()
                    ->whereKey($shift->automotive_location_id)
                    ->where('branch_id', $branch->getKey())
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (! $shiftLocation) {
                    throw ValidationException::withMessages([
                        'automotive_location_id' => 'La ubicación de inventario de la caja no pertenece a '.$branch->code.'.',
                    ]);
                }

                $sortedPartIds = array_values(array_unique(array_column($normalizedEntries, 'part_id')));
                sort($sortedPartIds, SORT_NUMERIC);

                $pairsToLock = [];
                foreach ($normalizedEntries as $entry) {
                    $locId = $entry['location_id'] ?? (int) $shiftLocation->getKey();
                    $pairsToLock["{$entry['part_id']}:{$locId}"] = [
                        'part_id' => (int) $entry['part_id'],
                        'location_id' => (int) $locId,
                    ];
                }
                $sortedPairs = array_values($pairsToLock);
                usort($sortedPairs, static fn (array $a, array $b): int => [$a['part_id'], $a['location_id']] <=> [$b['part_id'], $b['location_id']]);

                foreach ($sortedPartIds as $pid) {
                    AutomotivePart::query()->whereKey($pid)->lockForUpdate()->first();
                    AutomotivePartBranchSetting::query()
                        ->where('automotive_part_id', $pid)
                        ->where('branch_id', $branch->getKey())
                        ->lockForUpdate()
                        ->first();
                }

                // Lock target automotive_inventory_balances rows directly with FOR UPDATE before stock checks.
                $lockedStocksByKey = [];
                foreach ($sortedPairs as $pair) {
                    $balance = AutomotiveInventoryBalance::query()
                        ->where('branch_id', $branch->getKey())
                        ->where('automotive_part_id', $pair['part_id'])
                        ->where('automotive_location_id', $pair['location_id'])
                        ->lockForUpdate()
                        ->first();

                    $physical = $balance ? (int) $balance->physical_quantity : 0;
                    $reserved = $balance ? (int) $balance->reserved_quantity : 0;
                    $lockedStocksByKey["{$pair['part_id']}:{$pair['location_id']}"] = [
                        'physical' => $physical,
                        'reserved' => $reserved,
                        'available' => max(0, $physical - $reserved),
                    ];
                }

                // 2. Check idempotency without FOR UPDATE supremum gap locks across distinct keys.
                $existingInTx = AutomotivePosSale::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingInTx) {
                    return $this->resolveExistingIdempotentSale($existingInTx, $payloadHash);
                }

                // 3. Evaluate totals & verify locked stock under lock.
                $calc = $this->calculateCart($branch, $payload, $cashier, true, $shiftLocation, $lockedStocksByKey);
                $totalCents = (int) $calc['total_cents'];

                // 4. Validate payments in cents.
                $paymentPlan = $this->validateAndBuildPayments($paymentMethod, $totalCents, $payload);

                // 5. Generate sale folio and create header.
                $folio = $this->generateUniqueSaleFolio($branch);

                $sale = AutomotivePosSale::create([
                    'folio' => $folio,
                    'branch_id' => $branch->getKey(),
                    'cash_register_id' => $register->getKey(),
                    'automotive_pos_shift_id' => $shift->getKey(),
                    'automotive_location_id' => $shiftLocation->getKey(),
                    'cashier_id' => $cashier->getKey(),
                    'cashier_name_snapshot' => $cashier->name,
                    'seller_id' => $seller->getKey(),
                    'seller_code_snapshot' => $seller->code,
                    'seller_name_snapshot' => $seller->full_name,
                    'customer_name' => isset($payload['customer_name']) && trim((string) $payload['customer_name']) !== ''
                        ? trim((string) $payload['customer_name'])
                        : null,
                    'status' => AutomotivePosSale::STATUS_COMPLETED,
                    'subtotal' => $calc['subtotal'],
                    'wholesale_discount_amount' => $calc['wholesale_discount_amount'],
                    'manual_discount_amount' => $calc['manual_discount_amount'],
                    'discount_amount' => $calc['discount_amount'],
                    'tax_rate' => $calc['tax_rate'],
                    'tax_amount' => $calc['tax_amount'],
                    'total' => $calc['total'],
                    'refunded_amount' => '0.00',
                    'payment_method' => $paymentMethod,
                    'payment_status' => $paymentPlan['payment_status'],
                    'amount_tendered' => $paymentPlan['amount_tendered'],
                    'change_due' => $paymentPlan['change_due'],
                    'payment_reference' => $paymentPlan['primary_reference'],
                    'wholesale_rule_snapshot' => ! empty($calc['applied_wholesale_rules']) ? $calc['applied_wholesale_rules'] : null,
                    'manual_discount_snapshot' => $calc['manual_discount_snapshot'],
                    'discount_approval_id' => $calc['manual_discount_approval_id'],
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'notes' => isset($payload['notes']) && trim((string) $payload['notes']) !== ''
                        ? trim((string) $payload['notes'])
                        : null,
                ]);

                // 6. Deduct physical stock atomically via AutomotiveMovementService (sorted deterministically).
                $movementEntries = [];
                foreach ($calc['items'] as $itemData) {
                    $movementEntries[] = [
                        'branch_id' => (int) $branch->getKey(),
                        'automotive_part_id' => (int) $itemData['automotive_part_id'],
                        'automotive_location_id' => (int) $itemData['automotive_location_id'],
                        'type' => AutomotiveInventoryMovement::SALE,
                        'quantity' => -((int) $itemData['quantity']),
                        'reference_type' => 'automotive_pos_sale',
                        'reference_id' => (int) $sale->getKey(),
                        'reference' => $sale->folio,
                        'notes' => "Venta POS Autopartes {$branch->code} ({$sale->folio}) — Vendedor: {$seller->code}",
                        'metadata' => [
                            'automotive_branch_id' => (int) $branch->getKey(),
                            'sale_id' => (int) $sale->getKey(),
                            'sale_folio' => $sale->folio,
                            'seller_id' => (int) $seller->getKey(),
                            'seller_code' => $seller->code,
                            'cashier_id' => (int) $cashier->getKey(),
                            'unit_price_mxn' => $itemData['unit_price_mxn'],
                        ],
                        'external_key' => "autopartes-pos-sale:{$sale->getKey()}:part:{$itemData['automotive_part_id']}:loc:{$itemData['automotive_location_id']}",
                    ];
                }

                try {
                    $movements = $this->movementService->recordBatch($movementEntries, $cashier)->values();
                } catch (InventoryInsufficientStockException $e) {
                    throw ValidationException::withMessages([
                        'stock' => $e->getMessage(),
                    ]);
                }

                foreach (array_values($calc['items']) as $idx => $itemData) {
                    /** @var AutomotiveInventoryMovement $movement */
                    $movement = $movements[$idx];

                    AutomotivePosSaleItem::create([
                        'automotive_pos_sale_id' => $sale->getKey(),
                        'automotive_part_id' => $itemData['automotive_part_id'],
                        'automotive_location_id' => $itemData['automotive_location_id'],
                        'automotive_movement_id' => $movement->getKey(),
                        'part_code_snapshot' => $itemData['code'],
                        'item_number_snapshot' => $itemData['item_number'],
                        'mfg_part_number_snapshot' => $itemData['manufacturer_part_number'],
                        'description_snapshot' => $itemData['description'],
                        'category_snapshot' => $itemData['category'],
                        'quantity' => $itemData['quantity'],
                        'returned_quantity' => 0,
                        'unit_price_mxn' => $itemData['unit_price_mxn'],
                        'line_gross_amount' => $itemData['line_gross_amount'],
                        'wholesale_discount_amount' => $itemData['wholesale_discount_amount'],
                        'manual_discount_amount' => $itemData['manual_discount_amount'],
                        'discount_amount' => $itemData['discount_amount'],
                        'tax_amount' => $itemData['tax_amount'],
                        'subtotal' => $itemData['subtotal'],
                    ]);
                }

                foreach ($paymentPlan['payments'] as $pRow) {
                    AutomotivePosPayment::create([
                        'automotive_pos_sale_id' => $sale->getKey(),
                        'method' => $pRow['method'],
                        'amount' => $pRow['amount'],
                        'amount_tendered' => $pRow['amount_tendered'],
                        'change_due' => $pRow['change_due'],
                        'reference' => $pRow['reference'],
                        'verification_status' => $pRow['verification_status'],
                    ]);
                }

                if ($calc['manual_discount_approval_id'] !== null) {
                    AutomotiveDiscountApproval::query()
                        ->whereKey($calc['manual_discount_approval_id'])
                        ->update([
                            'status' => AutomotiveDiscountApproval::STATUS_CONSUMED,
                            'consumed_sale_id' => $sale->getKey(),
                        ]);
                }

                $summary = $this->shiftService->calculateShiftSummary($shift);
                $shift->update([
                    'total_sales_cash' => $summary['sales_cash'],
                    'total_sales_card' => $summary['sales_card'],
                    'total_sales_transfer' => $summary['sales_transfer'],
                    'total_sales_amount' => $summary['sales_total'],
                    'total_sales_count' => $summary['sales_count'],
                ]);

                $freshSale = $sale->fresh([
                    'branch:id,code,name',
                    'cashRegister:id,code,name',
                    'shift:id,folio,status',
                    'location:id,code,name',
                    'seller:id,code,full_name',
                    'items',
                    'payments',
                ]);
                $freshSale->setAttribute('cashier_name', $cashier->name);

                return $freshSale;
            });
        } catch (ValidationException $e) {
            $this->discountService->flushPendingInvalidations();
            throw $e;
        } catch (QueryException $e) {
            $concurrent = AutomotivePosSale::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($concurrent) {
                return $this->resolveExistingIdempotentSale($concurrent, $payloadHash);
            }
            throw $e;
        }
    }

    private function resolveExistingIdempotentSale(AutomotivePosSale $existing, string $payloadHash): AutomotivePosSale
    {
        if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'La clave de idempotencia ya fue utilizada con un carrito o datos de cobro distintos.',
            ]);
        }

        $loaded = $existing->load([
            'branch:id,code,name',
            'cashRegister:id,code,name',
            'shift:id,folio,status',
            'location:id,code,name',
            'seller:id,code,full_name',
            'items',
            'payments',
        ]);
        $loaded->setAttribute(
            'cashier_name',
            $loaded->cashier_name_snapshot ?: AutopartesUserDirectory::nameFor((int) $loaded->cashier_id)
        );

        return $loaded;
    }

    /**
     * Validate payment breakdown and calculate exact change in cents.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     payment_status: string,
     *     amount_tendered: string,
     *     change_due: string,
     *     primary_reference: ?string,
     *     payments: list<array<string, mixed>>
     * }
     */
    private function validateAndBuildPayments(string $method, int $totalCents, array $payload): array
    {
        if ($method === AutomotivePosSale::PAYMENT_CASH) {
            $rawTendered = $payload['amount_tendered'] ?? null;
            if ($rawTendered === null || ! is_numeric($rawTendered)) {
                throw ValidationException::withMessages([
                    'amount_tendered' => 'Debe capturar el efectivo entregado por el cliente.',
                ]);
            }
            $tenderedCents = AutomotiveDiscountService::toCents((float) $rawTendered);
            if ($tenderedCents < $totalCents) {
                throw ValidationException::withMessages([
                    'amount_tendered' => sprintf(
                        'El efectivo recibido ($%s MXN) es insuficiente para cubrir el total ($%s MXN).',
                        AutomotiveDiscountService::fromCents($tenderedCents),
                        AutomotiveDiscountService::fromCents($totalCents),
                    ),
                ]);
            }
            $changeCents = $tenderedCents - $totalCents;

            return [
                'payment_status' => 'paid',
                'amount_tendered' => AutomotiveDiscountService::fromCents($tenderedCents),
                'change_due' => AutomotiveDiscountService::fromCents($changeCents),
                'primary_reference' => null,
                'payments' => [[
                    'method' => AutomotivePosSale::PAYMENT_CASH,
                    'amount' => AutomotiveDiscountService::fromCents($totalCents),
                    'amount_tendered' => AutomotiveDiscountService::fromCents($tenderedCents),
                    'change_due' => AutomotiveDiscountService::fromCents($changeCents),
                    'reference' => null,
                    'verification_status' => 'cash_counted',
                ]],
            ];
        }

        if (in_array($method, [AutomotivePosSale::PAYMENT_CARD, AutomotivePosSale::PAYMENT_TRANSFER], true)) {
            $reference = trim((string) ($payload['payment_reference'] ?? ''));
            if (mb_strlen($reference) < 3) {
                throw ValidationException::withMessages([
                    'payment_reference' => $method === AutomotivePosSale::PAYMENT_CARD
                        ? 'Debe registrar la referencia o número de autorización de la terminal externa (mínimo 3 caracteres).'
                        : 'Debe registrar la clave de rastreo o referencia de la transferencia bancaria (mínimo 3 caracteres).',
                ]);
            }

            return [
                'payment_status' => 'recorded_external',
                'amount_tendered' => AutomotiveDiscountService::fromCents($totalCents),
                'change_due' => '0.00',
                'primary_reference' => $reference,
                'payments' => [[
                    'method' => $method,
                    'amount' => AutomotiveDiscountService::fromCents($totalCents),
                    'amount_tendered' => AutomotiveDiscountService::fromCents($totalCents),
                    'change_due' => '0.00',
                    'reference' => $reference,
                    'verification_status' => 'manual_reference_only',
                ]],
            ];
        }

        $rawPayments = is_array($payload['payments'] ?? null) ? $payload['payments'] : [];
        if (count($rawPayments) < 2) {
            throw ValidationException::withMessages([
                'payments' => 'Un pago mixto requiere al menos dos desgloses de pago.',
            ]);
        }

        $nonCashSumCents = 0;
        $cashAppliedCents = 0;
        $cashTenderedCents = 0;
        $builtPayments = [];
        $refs = [];

        foreach ($rawPayments as $idx => $p) {
            $subMethod = strtolower(trim((string) ($p['method'] ?? '')));
            if (! in_array($subMethod, [AutomotivePosSale::PAYMENT_CASH, AutomotivePosSale::PAYMENT_CARD, AutomotivePosSale::PAYMENT_TRANSFER], true)) {
                throw ValidationException::withMessages([
                    "payments.{$idx}.method" => 'Método inválido en desglose de pago mixto.',
                ]);
            }
            $amt = isset($p['amount']) && is_numeric($p['amount']) ? (float) $p['amount'] : 0.0;
            $amtCents = AutomotiveDiscountService::toCents($amt);
            if ($amtCents <= 0) {
                throw ValidationException::withMessages([
                    "payments.{$idx}.amount" => 'Cada método del pago mixto debe tener un importe mayor a $0.00.',
                ]);
            }

            if ($subMethod === AutomotivePosSale::PAYMENT_CASH) {
                $tendered = isset($p['amount_tendered']) && is_numeric($p['amount_tendered'])
                    ? AutomotiveDiscountService::toCents((float) $p['amount_tendered'])
                    : $amtCents;
                if ($tendered < $amtCents) {
                    throw ValidationException::withMessages([
                        "payments.{$idx}.amount_tendered" => 'El efectivo entregado en el pago mixto es menor al importe en efectivo a cubrir.',
                    ]);
                }
                $change = $tendered - $amtCents;
                $cashAppliedCents += $amtCents;
                $cashTenderedCents += $tendered;

                $builtPayments[] = [
                    'method' => AutomotivePosSale::PAYMENT_CASH,
                    'amount' => AutomotiveDiscountService::fromCents($amtCents),
                    'amount_tendered' => AutomotiveDiscountService::fromCents($tendered),
                    'change_due' => AutomotiveDiscountService::fromCents($change),
                    'reference' => null,
                    'verification_status' => 'cash_counted',
                ];
            } else {
                $ref = trim((string) ($p['reference'] ?? ''));
                if (mb_strlen($ref) < 3) {
                    throw ValidationException::withMessages([
                        "payments.{$idx}.reference" => 'Los pagos con tarjeta o transferencia en pago mixto requieren referencia (mínimo 3 caracteres).',
                    ]);
                }
                $nonCashSumCents += $amtCents;
                $refs[] = strtoupper($subMethod).':'.$ref;
                $builtPayments[] = [
                    'method' => $subMethod,
                    'amount' => AutomotiveDiscountService::fromCents($amtCents),
                    'amount_tendered' => AutomotiveDiscountService::fromCents($amtCents),
                    'change_due' => '0.00',
                    'reference' => $ref,
                    'verification_status' => 'manual_reference_only',
                ];
            }
        }

        if ($nonCashSumCents + $cashAppliedCents !== $totalCents) {
            throw ValidationException::withMessages([
                'payments' => sprintf(
                    'La suma de los pagos mixtos ($%s MXN) debe coincidir exactamente con el total de la venta ($%s MXN).',
                    AutomotiveDiscountService::fromCents($nonCashSumCents + $cashAppliedCents),
                    AutomotiveDiscountService::fromCents($totalCents),
                ),
            ]);
        }

        $totalChangeCents = max(0, $cashTenderedCents - $cashAppliedCents);

        return [
            'payment_status' => $nonCashSumCents > 0 ? 'recorded_external' : 'paid',
            'amount_tendered' => AutomotiveDiscountService::fromCents($nonCashSumCents + $cashTenderedCents),
            'change_due' => AutomotiveDiscountService::fromCents($totalChangeCents),
            'primary_reference' => ! empty($refs) ? implode(' | ', $refs) : null,
            'payments' => $builtPayments,
        ];
    }

    /**
     * Cancel an entire completed sale idempotently, restoring stock to exact original automotive locations.
     *
     * @param  array<string, mixed>  $data
     */
    public function cancelSale(
        AutomotiveBranch $branch,
        AutomotivePosSale $sale,
        array $data,
        User $actor,
    ): AutomotivePosReturn {
        $this->access->authorize($actor, $branch, BranchCatalogAccess::SALES_CANCEL);

        if ((int) $sale->branch_id !== (int) $branch->getKey()) {
            throw ValidationException::withMessages([
                'sale' => 'La venta no pertenece a esta sucursal.',
            ]);
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Debe indicar el motivo de la cancelación (mínimo 5 caracteres).',
            ]);
        }

        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? 'cancel-sale-'.$sale->getKey()));
        $refundMethod = (string) ($data['refund_method'] ?? ($sale->payment_method === AutomotivePosSale::PAYMENT_CASH
            ? AutomotivePosReturn::REFUND_CASH
            : AutomotivePosReturn::REFUND_ORIGINAL_METHOD));
        if (! in_array($refundMethod, AutomotivePosReturn::REFUND_METHODS, true)) {
            throw ValidationException::withMessages([
                'refund_method' => 'Método de reembolso inválido.',
            ]);
        }

        $payloadHash = hash('sha256', json_encode([
            'action' => 'cancel',
            'sale_id' => (int) $sale->getKey(),
            'refund_method' => $refundMethod,
        ], JSON_THROW_ON_ERROR));

        $existing = AutomotivePosReturn::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'La clave de idempotencia de cancelación ya fue usada con parámetros distintos.',
                ]);
            }

            return $existing->load(['items', 'sale.items']);
        }

        return AutopartesModel::transaction(function () use ($branch, $sale, $reason, $refundMethod, $data, $idempotencyKey, $payloadHash, $actor): AutomotivePosReturn {
            $lockedSale = AutomotivePosSale::query()
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($sale->getKey());

            $existingInTx = AutomotivePosReturn::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingInTx) {
                if (! hash_equals((string) $existingInTx->payload_hash, $payloadHash)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'La clave de idempotencia de cancelación ya fue usada con parámetros distintos.',
                    ]);
                }

                return $existingInTx->load(['items', 'sale.items']);
            }

            if ($lockedSale->status === AutomotivePosSale::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'sale' => "La venta [{$lockedSale->folio}] ya fue cancelada previamente.",
                ]);
            }

            if ($lockedSale->status !== AutomotivePosSale::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'sale' => "La venta [{$lockedSale->folio}] ya tiene devoluciones registradas; procese una devolución por las unidades restantes.",
                ]);
            }

            foreach ($lockedSale->items as $sItem) {
                if ((int) $sItem->returned_quantity > 0) {
                    throw ValidationException::withMessages([
                        'sale' => 'No se puede cancelar globalmente una venta que ya cuenta con devoluciones parciales.',
                    ]);
                }
            }

            $activeShift = $this->shiftService->getActiveShiftForBranch($branch, $actor);
            $returnFolio = 'CAN-'.strtoupper($branch->code).'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));

            $returnRecord = AutomotivePosReturn::create([
                'folio' => $returnFolio,
                'automotive_pos_sale_id' => $lockedSale->getKey(),
                'branch_id' => $branch->getKey(),
                'automotive_pos_shift_id' => $activeShift?->getKey() ?? $lockedSale->automotive_pos_shift_id,
                'processed_by' => $actor->getKey(),
                'type' => AutomotivePosReturn::TYPE_CANCELLATION,
                'reason' => $reason,
                'refund_method' => $refundMethod,
                'refund_reference' => isset($data['refund_reference']) && trim((string) $data['refund_reference']) !== ''
                    ? trim((string) $data['refund_reference'])
                    : null,
                'refund_amount' => $lockedSale->total,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
            ]);

            $movementEntries = [];
            $saleItemsList = $lockedSale->items->values();
            foreach ($saleItemsList as $saleItem) {
                $qty = (int) $saleItem->quantity;
                $movementEntries[] = [
                    'branch_id' => (int) $branch->getKey(),
                    'automotive_part_id' => (int) $saleItem->automotive_part_id,
                    'automotive_location_id' => (int) $saleItem->automotive_location_id,
                    'type' => AutomotiveInventoryMovement::CANCEL,
                    'quantity' => $qty,
                    'reference_type' => 'automotive_pos_cancel',
                    'reference_id' => (int) $returnRecord->getKey(),
                    'reference' => $returnRecord->folio,
                    'notes' => "Cancelación de venta POS {$lockedSale->folio} ({$returnRecord->folio}): {$reason}",
                    'metadata' => [
                        'automotive_branch_id' => (int) $branch->getKey(),
                        'sale_id' => (int) $lockedSale->getKey(),
                        'sale_folio' => $lockedSale->folio,
                        'original_movement_id' => (int) $saleItem->automotive_movement_id,
                        'original_location_id' => (int) $saleItem->automotive_location_id,
                    ],
                    'external_key' => "autopartes-pos-cancel:{$lockedSale->getKey()}:item:{$saleItem->getKey()}",
                ];
            }

            $movements = $this->movementService->recordBatch($movementEntries, $actor)->values();

            foreach ($saleItemsList as $idx => $saleItem) {
                /** @var AutomotiveInventoryMovement $movement */
                $movement = $movements[$idx];
                $qty = (int) $saleItem->quantity;

                $saleItem->update([
                    'returned_quantity' => $qty,
                ]);

                $unitRefundCents = (int) round(AutomotiveDiscountService::toCents((float) $saleItem->subtotal) / max(1, $qty));

                AutomotivePosReturnItem::create([
                    'automotive_pos_return_id' => $returnRecord->getKey(),
                    'automotive_pos_sale_item_id' => $saleItem->getKey(),
                    'automotive_part_id' => $saleItem->automotive_part_id,
                    'automotive_location_id' => $saleItem->automotive_location_id,
                    'automotive_movement_id' => $movement->getKey(),
                    'quantity' => $qty,
                    'unit_refund_amount' => AutomotiveDiscountService::fromCents($unitRefundCents),
                    'refund_subtotal' => $saleItem->subtotal,
                ]);
            }

            $lockedSale->update([
                'status' => AutomotivePosSale::STATUS_CANCELLED,
                'payment_status' => 'cancelled',
                'refunded_amount' => $lockedSale->total,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->getKey(),
                'cancel_reason' => $reason,
            ]);

            $shiftToRefresh = AutomotivePosShift::query()->lockForUpdate()->find($lockedSale->automotive_pos_shift_id);
            if ($shiftToRefresh && $shiftToRefresh->isOpen()) {
                $summary = $this->shiftService->calculateShiftSummary($shiftToRefresh);
                $shiftToRefresh->update([
                    'total_sales_cash' => $summary['sales_cash'],
                    'total_sales_card' => $summary['sales_card'],
                    'total_sales_transfer' => $summary['sales_transfer'],
                    'total_sales_amount' => $summary['sales_total'],
                    'total_sales_count' => $summary['sales_count'],
                    'total_refunds_cash' => $summary['refunds_cash'],
                ]);
            }

            $fresh = $returnRecord->load(['items', 'sale.items']);
            $fresh->setAttribute('processed_by_name', $actor->name);

            return $fresh;
        });
    }

    /**
     * Process a partial or full return of specific sale items, restoring stock to exact original location.
     *
     * @param  array<string, mixed>  $data
     */
    public function returnSaleItems(
        AutomotiveBranch $branch,
        AutomotivePosSale $sale,
        array $data,
        User $actor,
    ): AutomotivePosReturn {
        $this->access->authorize($actor, $branch, BranchCatalogAccess::SALES_RETURN);

        if ((int) $sale->branch_id !== (int) $branch->getKey()) {
            throw ValidationException::withMessages([
                'sale' => 'La venta no pertenece a esta sucursal.',
            ]);
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Debe indicar el motivo de la devolución (mínimo 5 caracteres).',
            ]);
        }

        $idempotencyKey = trim((string) ($data['idempotency_key'] ?? ''));
        if ($idempotencyKey === '' || mb_strlen($idempotencyKey) < 8) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'Se requiere una clave de idempotencia para registrar la devolución.',
            ]);
        }

        $refundMethod = (string) ($data['refund_method'] ?? AutomotivePosReturn::REFUND_CASH);
        if (! in_array($refundMethod, AutomotivePosReturn::REFUND_METHODS, true)) {
            throw ValidationException::withMessages([
                'refund_method' => 'Método de reembolso inválido.',
            ]);
        }

        $rawItems = is_array($data['items'] ?? null) ? $data['items'] : [];
        if (empty($rawItems)) {
            throw ValidationException::withMessages([
                'items' => 'Debe indicar al menos una partida y cantidad a devolver.',
            ]);
        }

        $requestedBySaleItem = [];
        foreach ($rawItems as $row) {
            $saleItemId = (int) ($row['automotive_pos_sale_item_id'] ?? 0);
            $qty = (int) ($row['quantity'] ?? 0);
            if ($saleItemId <= 0 || $qty <= 0) {
                throw ValidationException::withMessages([
                    'items' => 'Cada partida a devolver debe tener un identificador válido y cantidad mayor a cero.',
                ]);
            }
            $requestedBySaleItem[$saleItemId] = ($requestedBySaleItem[$saleItemId] ?? 0) + $qty;
        }
        ksort($requestedBySaleItem, SORT_NUMERIC);

        $payloadHash = hash('sha256', json_encode([
            'action' => 'return',
            'sale_id' => (int) $sale->getKey(),
            'refund_method' => $refundMethod,
            'items' => $requestedBySaleItem,
        ], JSON_THROW_ON_ERROR));

        $existing = AutomotivePosReturn::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'La clave de idempotencia de devolución ya fue usada con cantidades distintas.',
                ]);
            }

            return $existing->load(['items', 'sale.items']);
        }

        return AutopartesModel::transaction(function () use ($branch, $sale, $reason, $refundMethod, $data, $idempotencyKey, $payloadHash, $requestedBySaleItem, $actor): AutomotivePosReturn {
            $lockedSale = AutomotivePosSale::query()
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($sale->getKey());

            $existingInTx = AutomotivePosReturn::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingInTx) {
                if (! hash_equals((string) $existingInTx->payload_hash, $payloadHash)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'La clave de idempotencia de devolución ya fue usada con cantidades distintas.',
                    ]);
                }

                return $existingInTx->load(['items', 'sale.items']);
            }

            if ($lockedSale->status === AutomotivePosSale::STATUS_CANCELLED) {
                throw ValidationException::withMessages([
                    'sale' => 'No se pueden registrar devoluciones sobre una venta cancelada.',
                ]);
            }

            $itemsById = $lockedSale->items->keyBy('id');
            $totalRefundCents = 0;
            $returnPlan = [];

            foreach ($requestedBySaleItem as $saleItemId => $returnQty) {
                /** @var ?AutomotivePosSaleItem $saleItem */
                $saleItem = $itemsById->get($saleItemId);
                if (! $saleItem) {
                    throw ValidationException::withMessages([
                        'items' => "La partida #{$saleItemId} no pertenece a la venta {$lockedSale->folio}.",
                    ]);
                }

                $remaining = $saleItem->remainingReturnableQuantity();
                if ($returnQty > $remaining) {
                    throw ValidationException::withMessages([
                        'items' => sprintf(
                            'No puede devolver %d unidades de [%s]; solo quedan %d unidades vendidas susceptibles de devolución.',
                            $returnQty,
                            $saleItem->item_number_snapshot ?: $saleItem->part_code_snapshot,
                            $remaining,
                        ),
                    ]);
                }

                $lineSubtotalCents = AutomotiveDiscountService::toCents((float) $saleItem->subtotal);
                $soldQty = (int) $saleItem->quantity;
                $alreadyReturned = (int) $saleItem->returned_quantity;

                $refundBeforeCents = (int) round($lineSubtotalCents * ($alreadyReturned / $soldQty));
                $refundAfterCents = (int) round($lineSubtotalCents * (($alreadyReturned + $returnQty) / $soldQty));
                $thisReturnSubtotalCents = max(0, $refundAfterCents - $refundBeforeCents);
                $unitRefundCents = (int) round($thisReturnSubtotalCents / $returnQty);

                $totalRefundCents += $thisReturnSubtotalCents;
                $returnPlan[] = [
                    'sale_item' => $saleItem,
                    'return_qty' => $returnQty,
                    'unit_refund_cents' => $unitRefundCents,
                    'refund_subtotal_cents' => $thisReturnSubtotalCents,
                ];
            }

            $activeShift = $this->shiftService->getActiveShiftForBranch($branch, $actor);
            $returnFolio = 'DEV-'.strtoupper($branch->code).'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));

            $returnRecord = AutomotivePosReturn::create([
                'folio' => $returnFolio,
                'automotive_pos_sale_id' => $lockedSale->getKey(),
                'branch_id' => $branch->getKey(),
                'automotive_pos_shift_id' => $activeShift?->getKey() ?? $lockedSale->automotive_pos_shift_id,
                'processed_by' => $actor->getKey(),
                'type' => AutomotivePosReturn::TYPE_RETURN,
                'reason' => $reason,
                'refund_method' => $refundMethod,
                'refund_reference' => isset($data['refund_reference']) && trim((string) $data['refund_reference']) !== ''
                    ? trim((string) $data['refund_reference'])
                    : null,
                'refund_amount' => AutomotiveDiscountService::fromCents($totalRefundCents),
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
            ]);

            $movementEntries = [];
            foreach ($returnPlan as $entry) {
                /** @var AutomotivePosSaleItem $saleItem */
                $saleItem = $entry['sale_item'];
                $returnQty = (int) $entry['return_qty'];

                $movementEntries[] = [
                    'branch_id' => (int) $branch->getKey(),
                    'automotive_part_id' => (int) $saleItem->automotive_part_id,
                    'automotive_location_id' => (int) $saleItem->automotive_location_id,
                    'type' => AutomotiveInventoryMovement::RETURN,
                    'quantity' => $returnQty,
                    'reference_type' => 'automotive_pos_return',
                    'reference_id' => (int) $returnRecord->getKey(),
                    'reference' => $returnRecord->folio,
                    'notes' => "Devolución POS {$lockedSale->folio} ({$returnRecord->folio}): {$reason}",
                    'metadata' => [
                        'automotive_branch_id' => (int) $branch->getKey(),
                        'sale_id' => (int) $lockedSale->getKey(),
                        'sale_folio' => $lockedSale->folio,
                        'return_id' => (int) $returnRecord->getKey(),
                        'original_movement_id' => (int) $saleItem->automotive_movement_id,
                        'original_location_id' => (int) $saleItem->automotive_location_id,
                    ],
                    'external_key' => "autopartes-pos-return:{$returnRecord->getKey()}:item:{$saleItem->getKey()}",
                ];
            }

            $movements = $this->movementService->recordBatch($movementEntries, $actor)->values();

            foreach ($returnPlan as $idx => $entry) {
                /** @var AutomotivePosSaleItem $saleItem */
                $saleItem = $entry['sale_item'];
                $returnQty = (int) $entry['return_qty'];
                /** @var AutomotiveInventoryMovement $movement */
                $movement = $movements[$idx];

                $saleItem->update([
                    'returned_quantity' => ((int) $saleItem->returned_quantity) + $returnQty,
                ]);

                AutomotivePosReturnItem::create([
                    'automotive_pos_return_id' => $returnRecord->getKey(),
                    'automotive_pos_sale_item_id' => $saleItem->getKey(),
                    'automotive_part_id' => $saleItem->automotive_part_id,
                    'automotive_location_id' => $saleItem->automotive_location_id,
                    'automotive_movement_id' => $movement->getKey(),
                    'quantity' => $returnQty,
                    'unit_refund_amount' => AutomotiveDiscountService::fromCents($entry['unit_refund_cents']),
                    'refund_subtotal' => AutomotiveDiscountService::fromCents($entry['refund_subtotal_cents']),
                ]);
            }

            $freshItems = AutomotivePosSaleItem::query()
                ->where('automotive_pos_sale_id', $lockedSale->getKey())
                ->get();

            $allReturned = $freshItems->every(fn (AutomotivePosSaleItem $i): bool => (int) $i->returned_quantity >= (int) $i->quantity);
            $newRefundedCents = AutomotiveDiscountService::toCents((float) $lockedSale->refunded_amount) + $totalRefundCents;

            $lockedSale->update([
                'status' => $allReturned ? AutomotivePosSale::STATUS_RETURNED : AutomotivePosSale::STATUS_PARTIALLY_RETURNED,
                'payment_status' => $allReturned ? 'refunded' : 'partially_refunded',
                'refunded_amount' => AutomotiveDiscountService::fromCents($newRefundedCents),
            ]);

            if ($activeShift) {
                $lockedActiveShift = AutomotivePosShift::query()->lockForUpdate()->find($activeShift->getKey());
                if ($lockedActiveShift && $lockedActiveShift->isOpen()) {
                    $summary = $this->shiftService->calculateShiftSummary($lockedActiveShift);
                    $lockedActiveShift->update([
                        'total_refunds_cash' => $summary['refunds_cash'],
                    ]);
                }
            }

            $fresh = $returnRecord->load(['items', 'sale.items']);
            $fresh->setAttribute('processed_by_name', $actor->name);

            return $fresh;
        });
    }

    public function paginateSalesHistory(AutomotiveBranch $branch, array $filters, User $actor, int $perPage = 25): LengthAwarePaginator
    {
        $this->access->authorize($actor, $branch, BranchCatalogAccess::SALES_VIEW);

        $query = AutomotivePosSale::query()
            ->with([
                'cashRegister:id,code,name',
                'shift:id,folio,status',
                'location:id,code,name',
                'seller:id,code,full_name',
                'items',
                'payments',
                'returns.items',
            ])
            ->where('branch_id', $branch->getKey());

        if (($folio = trim((string) ($filters['folio'] ?? ''))) !== '') {
            $like = '%'.addcslashes($folio, '%_\\').'%';
            $query->where('folio', 'like', $like);
        }

        if (! empty($filters['seller_id'])) {
            $query->where('seller_id', (int) $filters['seller_id']);
        }

        if (($paymentMethod = trim((string) ($filters['payment_method'] ?? ''))) !== '') {
            $query->where('payment_method', $paymentMethod);
        }

        if (($status = trim((string) ($filters['status'] ?? ''))) !== '') {
            $query->where('status', $status);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', (string) $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', (string) $filters['date_to']);
        }

        $paginator = $query->orderByDesc('id')->paginate($perPage)->withQueryString();

        $userIds = [];
        foreach ($paginator->getCollection() as $sale) {
            $userIds[] = $sale->cashier_id;
            $userIds[] = $sale->cancelled_by;
            foreach ($sale->returns as $ret) {
                $userIds[] = $ret->processed_by;
            }
        }
        $users = AutopartesUserDirectory::mapByIds($userIds);

        foreach ($paginator->getCollection() as $sale) {
            $sale->setAttribute('cashier_name', $sale->cashier_name_snapshot ?: $users->get((int) $sale->cashier_id)?->name);
            $sale->setAttribute('cancelled_by_name', $users->get((int) $sale->cancelled_by)?->name);
            foreach ($sale->returns as $ret) {
                $ret->setAttribute('processed_by_name', $users->get((int) $ret->processed_by)?->name);
            }
        }

        return $paginator;
    }

    /**
     * Build branch-isolated seller & POS reports from the 'autopartes' database.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function buildSellerReport(AutomotiveBranch $branch, array $filters, User $actor): array
    {
        $this->access->authorize($actor, $branch, BranchCatalogAccess::REPORTS_VIEW);

        $dateFrom = ! empty($filters['date_from'])
            ? Carbon::parse((string) $filters['date_from'])->startOfDay()
            : Carbon::today()->startOfDay();

        $dateTo = ! empty($filters['date_to'])
            ? Carbon::parse((string) $filters['date_to'])->endOfDay()
            : Carbon::today()->endOfDay();

        $sellerFilterId = ! empty($filters['seller_id']) ? (int) $filters['seller_id'] : null;

        $salesQuery = AutomotivePosSale::query()
            ->with(['items', 'payments'])
            ->where('branch_id', $branch->getKey())
            ->whereBetween('created_at', [$dateFrom, $dateTo]);

        if ($sellerFilterId !== null) {
            $salesQuery->where('seller_id', $sellerFilterId);
        }

        $sales = $salesQuery->orderBy('id')->get();

        $summary = [
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'sales_count' => 0,
            'cancelled_count' => 0,
            'returned_count' => 0,
            'units_sold' => 0,
            'units_returned' => 0,
            'net_units' => 0,
            'gross_sales_cents' => 0,
            'wholesale_discounts_cents' => 0,
            'manual_discounts_cents' => 0,
            'total_discounts_cents' => 0,
            'tax_cents' => 0,
            'total_billed_cents' => 0,
            'refunded_cents' => 0,
            'net_sales_cents' => 0,
        ];

        $bySeller = [];
        $byPaymentMethod = [
            AutomotivePosSale::PAYMENT_CASH => ['method' => 'cash', 'label' => 'Efectivo', 'amount_cents' => 0, 'count' => 0],
            AutomotivePosSale::PAYMENT_CARD => ['method' => 'card', 'label' => 'Tarjeta', 'amount_cents' => 0, 'count' => 0],
            AutomotivePosSale::PAYMENT_TRANSFER => ['method' => 'transfer', 'label' => 'Transferencia', 'amount_cents' => 0, 'count' => 0],
        ];
        $byDay = [];

        foreach ($sales as $sale) {
            $sellerKey = (int) $sale->seller_id;
            if (! isset($bySeller[$sellerKey])) {
                $bySeller[$sellerKey] = [
                    'seller_id' => $sellerKey,
                    'seller_code' => $sale->seller_code_snapshot,
                    'seller_name' => $sale->seller_name_snapshot,
                    'sales_count' => 0,
                    'cancelled_count' => 0,
                    'returned_count' => 0,
                    'units_sold' => 0,
                    'units_returned' => 0,
                    'net_units' => 0,
                    'gross_sales_cents' => 0,
                    'wholesale_discounts_cents' => 0,
                    'manual_discounts_cents' => 0,
                    'total_discounts_cents' => 0,
                    'total_billed_cents' => 0,
                    'refunded_cents' => 0,
                    'net_sales_cents' => 0,
                ];
            }

            $dayKey = $sale->created_at ? $sale->created_at->format('Y-m-d') : $dateFrom->toDateString();
            if (! isset($byDay[$dayKey])) {
                $byDay[$dayKey] = [
                    'date' => $dayKey,
                    'sales_count' => 0,
                    'cancelled_count' => 0,
                    'units_sold' => 0,
                    'units_returned' => 0,
                    'gross_sales_cents' => 0,
                    'total_discounts_cents' => 0,
                    'refunded_cents' => 0,
                    'net_sales_cents' => 0,
                ];
            }

            if ($sale->status === AutomotivePosSale::STATUS_CANCELLED) {
                $summary['cancelled_count']++;
                $bySeller[$sellerKey]['cancelled_count']++;
                $byDay[$dayKey]['cancelled_count']++;

                continue;
            }

            if (in_array($sale->status, [AutomotivePosSale::STATUS_PARTIALLY_RETURNED, AutomotivePosSale::STATUS_RETURNED], true)) {
                $summary['returned_count']++;
                $bySeller[$sellerKey]['returned_count']++;
            }

            $grossCents = AutomotiveDiscountService::toCents((float) $sale->subtotal);
            $wDiscCents = AutomotiveDiscountService::toCents((float) $sale->wholesale_discount_amount);
            $mDiscCents = AutomotiveDiscountService::toCents((float) $sale->manual_discount_amount);
            $tDiscCents = AutomotiveDiscountService::toCents((float) $sale->discount_amount);
            $taxCents = AutomotiveDiscountService::toCents((float) $sale->tax_amount);
            $totalCents = AutomotiveDiscountService::toCents((float) $sale->total);
            $refundedCents = AutomotiveDiscountService::toCents((float) $sale->refunded_amount);
            $netCents = max(0, $totalCents - $refundedCents);

            $saleUnits = (int) $sale->items->sum('quantity');
            $returnedUnits = (int) $sale->items->sum('returned_quantity');
            $netUnits = max(0, $saleUnits - $returnedUnits);

            $summary['sales_count']++;
            $summary['units_sold'] += $saleUnits;
            $summary['units_returned'] += $returnedUnits;
            $summary['net_units'] += $netUnits;
            $summary['gross_sales_cents'] += $grossCents;
            $summary['wholesale_discounts_cents'] += $wDiscCents;
            $summary['manual_discounts_cents'] += $mDiscCents;
            $summary['total_discounts_cents'] += $tDiscCents;
            $summary['tax_cents'] += $taxCents;
            $summary['total_billed_cents'] += $totalCents;
            $summary['refunded_cents'] += $refundedCents;
            $summary['net_sales_cents'] += $netCents;

            $bySeller[$sellerKey]['sales_count']++;
            $bySeller[$sellerKey]['units_sold'] += $saleUnits;
            $bySeller[$sellerKey]['units_returned'] += $returnedUnits;
            $bySeller[$sellerKey]['net_units'] += $netUnits;
            $bySeller[$sellerKey]['gross_sales_cents'] += $grossCents;
            $bySeller[$sellerKey]['wholesale_discounts_cents'] += $wDiscCents;
            $bySeller[$sellerKey]['manual_discounts_cents'] += $mDiscCents;
            $bySeller[$sellerKey]['total_discounts_cents'] += $tDiscCents;
            $bySeller[$sellerKey]['total_billed_cents'] += $totalCents;
            $bySeller[$sellerKey]['refunded_cents'] += $refundedCents;
            $bySeller[$sellerKey]['net_sales_cents'] += $netCents;

            $byDay[$dayKey]['sales_count']++;
            $byDay[$dayKey]['units_sold'] += $saleUnits;
            $byDay[$dayKey]['units_returned'] += $returnedUnits;
            $byDay[$dayKey]['gross_sales_cents'] += $grossCents;
            $byDay[$dayKey]['total_discounts_cents'] += $tDiscCents;
            $byDay[$dayKey]['refunded_cents'] += $refundedCents;
            $byDay[$dayKey]['net_sales_cents'] += $netCents;

            foreach ($sale->payments as $payment) {
                $m = $payment->method;
                if (isset($byPaymentMethod[$m])) {
                    $byPaymentMethod[$m]['amount_cents'] += AutomotiveDiscountService::toCents((float) $payment->amount);
                    $byPaymentMethod[$m]['count']++;
                }
            }
        }

        $formattedSellerRows = array_values(array_map(fn (array $r): array => [
            'seller_id' => $r['seller_id'],
            'seller_code' => $r['seller_code'],
            'seller_name' => $r['seller_name'],
            'sales_count' => $r['sales_count'],
            'cancelled_count' => $r['cancelled_count'],
            'returned_count' => $r['returned_count'],
            'units_sold' => $r['units_sold'],
            'units_returned' => $r['units_returned'],
            'net_units' => $r['net_units'],
            'gross_sales' => AutomotiveDiscountService::fromCents($r['gross_sales_cents']),
            'wholesale_discounts' => AutomotiveDiscountService::fromCents($r['wholesale_discounts_cents']),
            'manual_discounts' => AutomotiveDiscountService::fromCents($r['manual_discounts_cents']),
            'total_discounts' => AutomotiveDiscountService::fromCents($r['total_discounts_cents']),
            'total_billed' => AutomotiveDiscountService::fromCents($r['total_billed_cents']),
            'refunded_amount' => AutomotiveDiscountService::fromCents($r['refunded_cents']),
            'net_sales' => AutomotiveDiscountService::fromCents($r['net_sales_cents']),
        ], $bySeller));

        $formattedPaymentMethods = array_values(array_map(fn (array $m): array => [
            'method' => $m['method'],
            'label' => $m['label'],
            'count' => $m['count'],
            'amount' => AutomotiveDiscountService::fromCents($m['amount_cents']),
        ], $byPaymentMethod));

        ksort($byDay);
        $formattedDays = array_values(array_map(fn (array $d): array => [
            'date' => $d['date'],
            'sales_count' => $d['sales_count'],
            'cancelled_count' => $d['cancelled_count'],
            'units_sold' => $d['units_sold'],
            'units_returned' => $d['units_returned'],
            'gross_sales' => AutomotiveDiscountService::fromCents($d['gross_sales_cents']),
            'total_discounts' => AutomotiveDiscountService::fromCents($d['total_discounts_cents']),
            'refunded_amount' => AutomotiveDiscountService::fromCents($d['refunded_cents']),
            'net_sales' => AutomotiveDiscountService::fromCents($d['net_sales_cents']),
        ], $byDay));

        return [
            'branch' => [
                'id' => $branch->getKey(),
                'code' => $branch->code,
                'name' => $branch->name,
            ],
            'summary' => [
                'date_from' => $summary['date_from'],
                'date_to' => $summary['date_to'],
                'sales_count' => $summary['sales_count'],
                'cancelled_count' => $summary['cancelled_count'],
                'returned_count' => $summary['returned_count'],
                'units_sold' => $summary['units_sold'],
                'units_returned' => $summary['units_returned'],
                'net_units' => $summary['net_units'],
                'gross_sales' => AutomotiveDiscountService::fromCents($summary['gross_sales_cents']),
                'wholesale_discounts' => AutomotiveDiscountService::fromCents($summary['wholesale_discounts_cents']),
                'manual_discounts' => AutomotiveDiscountService::fromCents($summary['manual_discounts_cents']),
                'total_discounts' => AutomotiveDiscountService::fromCents($summary['total_discounts_cents']),
                'tax_amount' => AutomotiveDiscountService::fromCents($summary['tax_cents']),
                'total_billed' => AutomotiveDiscountService::fromCents($summary['total_billed_cents']),
                'refunded_amount' => AutomotiveDiscountService::fromCents($summary['refunded_cents']),
                'net_sales' => AutomotiveDiscountService::fromCents($summary['net_sales_cents']),
            ],
            'by_seller' => $formattedSellerRows,
            'by_payment_method' => $formattedPaymentMethods,
            'by_day' => $formattedDays,
        ];
    }

    /**
     * Validate and normalize raw cart line entries in pure PHP (no DB queries).
     *
     * @return list<array{index: int, part_id: int, quantity: int, location_id: ?int}>
     */
    private function normalizeRawCartEntries(mixed $rawItems): array
    {
        if (! is_array($rawItems) || empty($rawItems)) {
            throw ValidationException::withMessages([
                'items' => 'El carrito de compra no puede estar vacío.',
            ]);
        }

        $entries = [];
        foreach ($rawItems as $idx => $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages([
                    "items.{$idx}" => 'Formato de partida inválido.',
                ]);
            }

            $partId = (int) ($item['automotive_part_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 0);
            $locId = ! empty($item['automotive_location_id']) ? (int) $item['automotive_location_id'] : null;

            if ($partId <= 0 || $qty <= 0) {
                throw ValidationException::withMessages([
                    "items.{$idx}" => 'Cada partida del carrito debe especificar una autoparte válida y una cantidad entera mayor a cero.',
                ]);
            }

            $entries[] = [
                'index' => (int) $idx,
                'part_id' => $partId,
                'quantity' => $qty,
                'location_id' => $locId,
            ];
        }

        return $entries;
    }

    /**
     * Consolidate cart items by (automotive_part_id, automotive_location_id).
     *
     * @param  array<int, mixed>  $rawItems
     * @return list<array{automotive_part_id: int, location: AutomotiveLocation, quantity: int}>
     */
    private function consolidateCartItems(AutomotiveBranch $branch, array $rawItems, AutomotiveLocation $defaultLocation): array
    {
        $entries = $this->normalizeRawCartEntries($rawItems);
        $maxItemQty = max(1, (int) config('autopartes.pos.max_sale_item_quantity', 500));
        $grouped = [];

        foreach ($entries as $entry) {
            $idx = $entry['index'];
            $partId = $entry['part_id'];
            $qty = $entry['quantity'];
            $locId = $entry['location_id'] ?? (int) $defaultLocation->getKey();

            $key = $partId.':'.$locId;
            if (! isset($grouped[$key])) {
                $loc = $locId === (int) $defaultLocation->getKey()
                    ? $defaultLocation
                    : $this->resolveBranchLocation($branch, $locId);

                $grouped[$key] = [
                    'automotive_part_id' => $partId,
                    'location' => $loc,
                    'quantity' => 0,
                ];
            }

            $grouped[$key]['quantity'] += $qty;
            if ($grouped[$key]['quantity'] > $maxItemQty) {
                throw ValidationException::withMessages([
                    "items.{$idx}.quantity" => "La cantidad por partida no puede exceder {$maxItemQty} unidades.",
                ]);
            }
        }

        ksort($grouped, SORT_STRING);

        return array_values($grouped);
    }

    private function resolveBranchLocation(AutomotiveBranch $branch, ?int $locationId): AutomotiveLocation
    {
        if ($locationId === null || $locationId <= 0) {
            return $branch->ensureDefaultReceptionLocation();
        }

        $location = AutomotiveLocation::query()
            ->whereKey($locationId)
            ->where('branch_id', $branch->getKey())
            ->where('is_active', true)
            ->first();

        if (! $location) {
            throw ValidationException::withMessages([
                'automotive_location_id' => 'La ubicación seleccionada no pertenece a la sucursal '.$branch->code.' o no está activa.',
            ]);
        }

        return $location;
    }

    /**
     * Compute a deterministic hash of the sale request payload to detect idempotency key reuse with different data.
     *
     * @param  array<string, mixed>  $payload
     */
    private function computeSalePayloadHash(AutomotiveBranch $branch, array $payload): string
    {
        $rawItems = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $canonicalItems = [];
        foreach ($rawItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $pid = (int) ($item['automotive_part_id'] ?? 0);
            $qty = (int) ($item['quantity'] ?? 0);
            $loc = (int) ($item['automotive_location_id'] ?? 0);
            $key = $pid.':'.$loc;
            $canonicalItems[$key] = ($canonicalItems[$key] ?? 0) + $qty;
        }
        ksort($canonicalItems, SORT_STRING);

        $manual = is_array($payload['manual_discount'] ?? null) ? [
            't' => (string) ($payload['manual_discount']['discount_type'] ?? ''),
            'v' => is_numeric($payload['manual_discount']['discount_value'] ?? null)
                ? number_format(round((float) $payload['manual_discount']['discount_value'], 2), 2, '.', '')
                : '',
            'r' => trim((string) ($payload['manual_discount']['reason'] ?? '')),
            'a' => trim((string) ($payload['manual_discount']['approval_token'] ?? '')),
        ] : null;

        return hash('sha256', json_encode([
            'branch_id' => (int) $branch->getKey(),
            'seller_id' => (int) ($payload['seller_id'] ?? 0),
            'shift_id' => (int) ($payload['automotive_pos_shift_id'] ?? 0),
            'payment_method' => strtolower(trim((string) ($payload['payment_method'] ?? ''))),
            'amount_tendered' => is_numeric($payload['amount_tendered'] ?? null)
                ? number_format(round((float) $payload['amount_tendered'], 2), 2, '.', '')
                : '',
            'payment_reference' => trim((string) ($payload['payment_reference'] ?? '')),
            'items' => $canonicalItems,
            'manual' => $manual,
        ], JSON_THROW_ON_ERROR));
    }

    private function generateUniqueSaleFolio(AutomotiveBranch $branch): string
    {
        $prefix = 'VTA-'.strtoupper($branch->code).'-'.now()->format('Ymd').'-';

        do {
            $folio = $prefix.Str::upper(Str::random(6));
        } while (AutomotivePosSale::query()->where('folio', $folio)->exists());

        return $folio;
    }
}
