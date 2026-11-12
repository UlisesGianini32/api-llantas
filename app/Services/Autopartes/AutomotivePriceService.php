<?php

namespace App\Services\Autopartes;

use App\Models\AutomotiveBranch;
use App\Models\AutomotiveLocation;
use App\Models\AutomotivePart;
use App\Models\AutomotivePartBranchSetting;
use App\Models\AutomotivePriceHistory;
use App\Models\AutopartesModel;
use App\Models\User;
use App\Support\AutopartesUserDirectory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AutomotivePriceService
{
    public function __construct(
        private readonly BranchCatalogAccess $access,
        private readonly AutomotiveStockService $stockService,
    ) {}

    /**
     * Evaluate all mandatory requirements for controlled local sale in the branch:
     * 1. Identity verified/authorized (not ambiguous, allow_receipt = true).
     * 2. Autopart exists in isolated automotive_parts catalog.
     * 3. Has an approved MXN price > 0 in the branch.
     * 4. Has available physical stock in the branch.
     * 5. Explicitly authorized for sale in the branch (is_sellable = true).
     * 6. No administrative block pending (sale_blocked = false).
     *
     * @return array{
     *     eligible: bool,
     *     sale_price_mxn: ?string,
     *     price_status: string,
     *     is_sellable: bool,
     *     sale_blocked: bool,
     *     sale_block_reason: ?string,
     *     available_stock: int,
     *     physical_stock: int,
     *     reserved_stock: int,
     *     identity_status: string,
     *     allow_receipt: bool,
     *     reasons: list<string>
     * }
     */
    public function evaluateSellability(
        AutomotiveBranch $branch,
        AutomotivePart $part,
        ?AutomotiveLocation $location = null,
        ?AutomotivePartBranchSetting $setting = null,
        ?array $precomputedStock = null,
    ): array {
        $setting ??= AutomotivePartBranchSetting::query()
            ->where('automotive_part_id', $part->getKey())
            ->where('branch_id', $branch->getKey())
            ->first();

        if ($precomputedStock !== null) {
            $physicalStock = (int) ($precomputedStock['physical'] ?? 0);
            $reservedStock = (int) ($precomputedStock['reserved'] ?? 0);
            $availableStock = (int) ($precomputedStock['available'] ?? max(0, $physicalStock - $reservedStock));
        } else {
            $physicalStock = $location
                ? $this->stockService->branchPhysicalStockByLocation($part, $branch, $location)
                : $this->stockService->branchPhysicalStock($part, $branch);

            $reservedStock = $location
                ? $this->stockService->branchReservedStockByLocation($part, $branch, $location)
                : $this->stockService->branchReservedStock($part, $branch);

            $availableStock = max(0, $physicalStock - $reservedStock);
        }

        $priceMxn = $setting && $setting->sale_price_mxn !== null && (float) $setting->sale_price_mxn > 0
            ? number_format((float) $setting->sale_price_mxn, 2, '.', '')
            : null;

        $priceStatus = $priceMxn === null
            ? AutomotivePartBranchSetting::PRICE_STATUS_UNPRICED
            : (string) ($setting->price_status ?? AutomotivePartBranchSetting::PRICE_STATUS_APPROVED);

        $isSellable = (bool) ($setting?->is_sellable ?? false);
        $saleBlocked = (bool) ($setting?->sale_blocked ?? false);
        $allowReceipt = (bool) ($part->allow_receipt ?? true);

        $reasons = [];

        if ($part->identity_status === AutomotivePart::STATUS_AMBIGUOUS || ! $allowReceipt) {
            $reasons[] = 'La identidad de la autoparte es ambigua o no está autorizada.';
        }

        if ($saleBlocked) {
            $reasons[] = 'La autoparte tiene un bloqueo administrativo activo'
                .($setting?->sale_block_reason ? ": {$setting->sale_block_reason}" : '.');
        }

        if ($priceMxn === null) {
            $reasons[] = 'No tiene precio de venta autorizado en MXN.';
        } elseif ($priceStatus !== AutomotivePartBranchSetting::PRICE_STATUS_APPROVED) {
            $reasons[] = 'El precio comercial en MXN se encuentra pendiente de aprobación administrativa.';
        }

        if (! $isSellable) {
            $reasons[] = 'No está habilitada comercialmente para venta en '.$branch->code.'.';
        }

        if ($availableStock <= 0) {
            $reasons[] = 'Sin existencias físicas disponibles en '.$branch->code.'.';
        }

        return [
            'eligible' => empty($reasons),
            'sale_price_mxn' => $priceMxn,
            'price_status' => $priceStatus,
            'is_sellable' => $isSellable,
            'sale_blocked' => $saleBlocked,
            'sale_block_reason' => $setting?->sale_block_reason,
            'available_stock' => $availableStock,
            'physical_stock' => $physicalStock,
            'reserved_stock' => $reservedStock,
            'identity_status' => (string) $part->identity_status,
            'allow_receipt' => $allowReceipt,
            'reasons' => $reasons,
        ];
    }

    /**
     * Extract immutable documental Retail and Ext. Retail from the original Excel row.
     * These values are strictly informational and are NEVER used as fallback sale prices.
     *
     * @return array{retail_raw: ?string, ext_retail_raw: ?string, qty_raw: ?string}
     */
    public function extractDocumentalReferences(?array $cells): array
    {
        if (! is_array($cells)) {
            return ['retail_raw' => null, 'ext_retail_raw' => null, 'qty_raw' => null];
        }

        return [
            'qty_raw' => isset($cells[6]['value']) && $cells[6]['value'] !== null ? (string) $cells[6]['value'] : null,
            'retail_raw' => isset($cells[7]['value']) && $cells[7]['value'] !== null ? (string) $cells[7]['value'] : null,
            'ext_retail_raw' => isset($cells[8]['value']) && $cells[8]['value'] !== null ? (string) $cells[8]['value'] : null,
        ];
    }

    public function paginateCatalogPrices(AutomotiveBranch $branch, array $filters, User $actor, int $perPage = 25): LengthAwarePaginator
    {
        if (
            ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::PRICES_VIEW)
            && ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::PRICES_MANAGE)
        ) {
            $this->access->authorize($actor, $branch, BranchCatalogAccess::PRICES_VIEW);
        }

        $query = AutomotivePart::query()
            ->with(['sourceRow:id,row_number,cells']);

        if (($search = trim((string) ($filters['q'] ?? ''))) !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($sub) use ($like): void {
                $sub->where('code', 'like', $like)
                    ->orWhere('item_number', 'like', $like)
                    ->orWhere('manufacturer_part_number', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('category', 'like', $like)
                    ->orWhere('vendor_name', 'like', $like)
                    ->orWhere('verified_barcode', 'like', $like);
            });
        }

        if (($category = trim((string) ($filters['category'] ?? ''))) !== '') {
            $query->where('category', $category);
        }

        if (($priceStatus = trim((string) ($filters['price_status'] ?? ''))) !== '') {
            if (in_array($priceStatus, ['configured', 'approved'], true)) {
                $query->whereHas('branchSettings', function ($sq) use ($branch): void {
                    $sq->where('branch_id', $branch->getKey())
                        ->where('price_status', AutomotivePartBranchSetting::PRICE_STATUS_APPROVED)
                        ->whereNotNull('sale_price_mxn')
                        ->where('sale_price_mxn', '>', 0);
                });
            } elseif ($priceStatus === 'pending_approval') {
                $query->whereHas('branchSettings', function ($sq) use ($branch): void {
                    $sq->where('branch_id', $branch->getKey())
                        ->where('price_status', AutomotivePartBranchSetting::PRICE_STATUS_PENDING_APPROVAL)
                        ->whereNotNull('sale_price_mxn')
                        ->where('sale_price_mxn', '>', 0);
                });
            } elseif (in_array($priceStatus, ['missing', 'unpriced'], true)) {
                $query->whereDoesntHave('branchSettings', function ($sq) use ($branch): void {
                    $sq->where('branch_id', $branch->getKey())
                        ->whereNotNull('sale_price_mxn')
                        ->where('sale_price_mxn', '>', 0);
                });
            } elseif ($priceStatus === 'sellable') {
                $query->whereHas('branchSettings', function ($sq) use ($branch): void {
                    $sq->where('branch_id', $branch->getKey())
                        ->where('price_status', AutomotivePartBranchSetting::PRICE_STATUS_APPROVED)
                        ->where('is_sellable', true)
                        ->where('sale_blocked', false)
                        ->whereNotNull('sale_price_mxn')
                        ->where('sale_price_mxn', '>', 0);
                });
            }
        }

        $paginator = $query->orderBy('id')->paginate($perPage)->withQueryString();

        $partIds = $paginator->getCollection()->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();
        $settingsByPart = [];
        $userIds = [];

        if (! empty($partIds)) {
            $settings = AutomotivePartBranchSetting::query()
                ->where('branch_id', $branch->getKey())
                ->whereIn('automotive_part_id', $partIds)
                ->get();
            foreach ($settings as $setting) {
                $settingsByPart[(int) $setting->automotive_part_id] = $setting;
                $userIds[] = $setting->price_updated_by;
                $userIds[] = $setting->price_approved_by;
            }
        }

        $users = AutopartesUserDirectory::mapByIds($userIds);
        $stockSummary = $this->stockService->stockSummaryForPartIds($branch, $partIds);

        $paginator->getCollection()->transform(function (AutomotivePart $part) use ($branch, $settingsByPart, $users, $stockSummary): array {
            $setting = $settingsByPart[(int) $part->getKey()] ?? null;
            $doc = $this->extractDocumentalReferences($part->sourceRow?->cells);
            $eval = $this->evaluateSellability(
                $branch,
                $part,
                null,
                $setting,
                $stockSummary[(int) $part->getKey()] ?? ['physical' => 0, 'reserved' => 0, 'available' => 0]
            );

            $updatedBy = $setting?->price_updated_by ? $users->get((int) $setting->price_updated_by) : null;
            $approvedBy = $setting?->price_approved_by ? $users->get((int) $setting->price_approved_by) : null;

            return [
                'part_id' => $part->id,
                'automotive_part_id' => $part->id,
                'code' => $part->code,
                'item_number' => $part->item_number,
                'manufacturer_part_number' => $part->manufacturer_part_number,
                'description' => $part->description,
                'category' => $part->category,
                'subcategory' => $part->subcategory,
                'vendor_name' => $part->vendor_name,
                'identity_status' => $part->identity_status,
                'verified_barcode' => $part->verified_barcode,
                'documental_retail_usd' => $doc['retail_raw'],
                'documental_ext_retail_usd' => $doc['ext_retail_raw'],
                'documental_qty' => $doc['qty_raw'],
                'sale_price_mxn' => $eval['sale_price_mxn'],
                'price_status' => $eval['price_status'],
                'is_sellable' => $eval['is_sellable'],
                'sale_blocked' => $eval['sale_blocked'],
                'sale_block_reason' => $eval['sale_block_reason'],
                'price_reason' => $setting?->price_reason,
                'price_updated_at' => $setting?->price_updated_at?->toIso8601String(),
                'price_updated_by_name' => $updatedBy?->name,
                'price_approved_at' => $setting?->price_approved_at?->toIso8601String(),
                'price_approved_by_name' => $approvedBy?->name,
                'available_stock' => $eval['available_stock'],
                'physical_stock' => $eval['physical_stock'],
                'eligible_for_pos' => $eval['eligible'],
                'ineligibility_reasons' => $eval['reasons'],
            ];
        });

        return $paginator;
    }

    /**
     * Summary counts of parts by price state (unpriced, pending_approval, approved, sellable).
     *
     * @return array{total: int, unpriced: int, pending_approval: int, approved: int, sellable: int}
     */
    public function getPriceStatusCounts(AutomotiveBranch $branch): array
    {
        $total = AutomotivePart::query()->count();

        $approved = AutomotivePartBranchSetting::query()
            ->where('branch_id', $branch->getKey())
            ->where('price_status', AutomotivePartBranchSetting::PRICE_STATUS_APPROVED)
            ->whereNotNull('sale_price_mxn')
            ->where('sale_price_mxn', '>', 0)
            ->count();

        $pending = AutomotivePartBranchSetting::query()
            ->where('branch_id', $branch->getKey())
            ->where('price_status', AutomotivePartBranchSetting::PRICE_STATUS_PENDING_APPROVAL)
            ->whereNotNull('sale_price_mxn')
            ->where('sale_price_mxn', '>', 0)
            ->count();

        $sellable = AutomotivePartBranchSetting::query()
            ->where('branch_id', $branch->getKey())
            ->where('price_status', AutomotivePartBranchSetting::PRICE_STATUS_APPROVED)
            ->where('is_sellable', true)
            ->where('sale_blocked', false)
            ->whereNotNull('sale_price_mxn')
            ->where('sale_price_mxn', '>', 0)
            ->count();

        $unpriced = max(0, $total - $approved - $pending);

        return [
            'total' => $total,
            'unpriced' => $unpriced,
            'pending_approval' => $pending,
            'approved' => $approved,
            'sellable' => $sellable,
        ];
    }

    public function configurePrice(
        AutomotiveBranch $branch,
        AutomotivePart $part,
        array $data,
        User $actor,
        string $operationType = 'single',
        ?string $batchReference = null,
    ): AutomotivePartBranchSetting {
        $this->access->authorize($actor, $branch, BranchCatalogAccess::PRICES_MANAGE);

        $reason = trim((string) ($data['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Debe indicar un motivo comercial verificable (mínimo 5 caracteres).',
            ]);
        }

        $rawPrice = $data['sale_price_mxn'] ?? null;
        $newPriceMxn = null;
        if ($rawPrice !== null && $rawPrice !== '') {
            if (! is_numeric($rawPrice) || (float) $rawPrice <= 0) {
                throw ValidationException::withMessages([
                    'sale_price_mxn' => 'El precio de venta en MXN debe ser un importe numérico mayor a $0.00.',
                ]);
            }
            $newPriceMxn = number_format(round((float) $rawPrice, 2), 2, '.', '');
        }

        return AutopartesModel::transaction(function () use ($branch, $part, $data, $actor, $reason, $newPriceMxn, $operationType, $batchReference): AutomotivePartBranchSetting {
            $lockedPart = AutomotivePart::query()->lockForUpdate()->findOrFail($part->getKey());

            $setting = AutomotivePartBranchSetting::query()
                ->where('automotive_part_id', $lockedPart->getKey())
                ->where('branch_id', $branch->getKey())
                ->lockForUpdate()
                ->first();

            $prevPrice = $setting?->sale_price_mxn !== null ? number_format((float) $setting->sale_price_mxn, 2, '.', '') : null;
            $prevStatus = $prevPrice === null
                ? AutomotivePartBranchSetting::PRICE_STATUS_UNPRICED
                : (string) ($setting->price_status ?? AutomotivePartBranchSetting::PRICE_STATUS_APPROVED);
            $prevSellable = (bool) ($setting?->is_sellable ?? false);
            $prevBlocked = (bool) ($setting?->sale_blocked ?? false);

            $effectivePrice = array_key_exists('sale_price_mxn', $data) ? $newPriceMxn : $prevPrice;
            $effectiveBlocked = array_key_exists('sale_blocked', $data) ? (bool) $data['sale_blocked'] : $prevBlocked;

            $requestedStatus = isset($data['price_status']) ? trim((string) $data['price_status']) : null;
            if (isset($data['approve']) && $data['approve'] === false) {
                $requestedStatus = AutomotivePartBranchSetting::PRICE_STATUS_PENDING_APPROVAL;
            }

            if ($effectivePrice === null || (float) $effectivePrice <= 0) {
                $effectiveStatus = AutomotivePartBranchSetting::PRICE_STATUS_UNPRICED;
            } elseif ($requestedStatus !== null) {
                if (! in_array($requestedStatus, [
                    AutomotivePartBranchSetting::PRICE_STATUS_PENDING_APPROVAL,
                    AutomotivePartBranchSetting::PRICE_STATUS_APPROVED,
                ], true)) {
                    throw ValidationException::withMessages([
                        'price_status' => 'Estado de precio inválido. Use pending_approval o approved.',
                    ]);
                }
                $effectiveStatus = $requestedStatus;
            } elseif (array_key_exists('sale_price_mxn', $data)) {
                $effectiveStatus = AutomotivePartBranchSetting::PRICE_STATUS_APPROVED;
            } else {
                $effectiveStatus = $prevStatus;
            }

            $effectiveSellable = array_key_exists('is_sellable', $data) ? (bool) $data['is_sellable'] : $prevSellable;

            if ($effectiveStatus !== AutomotivePartBranchSetting::PRICE_STATUS_APPROVED) {
                if (array_key_exists('is_sellable', $data) && (bool) $data['is_sellable']) {
                    if ($effectivePrice === null || (float) $effectivePrice <= 0) {
                        throw ValidationException::withMessages([
                            'is_sellable' => 'No se puede habilitar para venta una autoparte sin precio autorizado mayor a $0.00 MXN.',
                        ]);
                    }
                    throw ValidationException::withMessages([
                        'is_sellable' => 'No se puede habilitar para venta una autoparte cuyo precio comercial está pendiente de aprobación.',
                    ]);
                }
                $effectiveSellable = false;
            }

            if ($effectiveSellable) {
                if ($effectivePrice === null || (float) $effectivePrice <= 0) {
                    throw ValidationException::withMessages([
                        'is_sellable' => 'No se puede habilitar para venta una autoparte sin precio autorizado mayor a $0.00 MXN.',
                    ]);
                }
                if (! $lockedPart->isReceivable()) {
                    throw ValidationException::withMessages([
                        'is_sellable' => 'No se puede habilitar para venta una autoparte con identidad ambigua o no verificada.',
                    ]);
                }
                if ($effectiveBlocked) {
                    throw ValidationException::withMessages([
                        'is_sellable' => 'No se puede habilitar para venta una autoparte con bloqueo administrativo activo.',
                    ]);
                }
            }

            if ($effectiveBlocked) {
                $effectiveSellable = false;
            }

            $isApprovedNow = $effectiveStatus === AutomotivePartBranchSetting::PRICE_STATUS_APPROVED;
            $approvedById = $isApprovedNow ? $actor->getKey() : null;
            $approvedAt = $isApprovedNow ? now() : null;

            $defaultLocation = $branch->ensureDefaultReceptionLocation();

            if (! $setting) {
                $setting = AutomotivePartBranchSetting::create([
                    'automotive_part_id' => $lockedPart->getKey(),
                    'branch_id' => $branch->getKey(),
                    'primary_location_id' => $defaultLocation->getKey(),
                    'is_sellable' => $effectiveSellable,
                    'sale_price_mxn' => $effectivePrice,
                    'price_status' => $effectiveStatus,
                    'price_updated_by' => $actor->getKey(),
                    'price_updated_at' => now(),
                    'price_approved_by' => $approvedById,
                    'price_approved_at' => $approvedAt,
                    'price_reason' => $reason,
                    'sale_blocked' => $effectiveBlocked,
                    'sale_block_reason' => $effectiveBlocked ? ($data['sale_block_reason'] ?? $reason) : null,
                ]);
            } else {
                $setting->update([
                    'primary_location_id' => $setting->primary_location_id ?? $defaultLocation->getKey(),
                    'is_sellable' => $effectiveSellable,
                    'sale_price_mxn' => $effectivePrice,
                    'price_status' => $effectiveStatus,
                    'price_updated_by' => $actor->getKey(),
                    'price_updated_at' => now(),
                    'price_approved_by' => $approvedById,
                    'price_approved_at' => $approvedAt,
                    'price_reason' => $reason,
                    'sale_blocked' => $effectiveBlocked,
                    'sale_block_reason' => $effectiveBlocked ? ($data['sale_block_reason'] ?? $setting->sale_block_reason ?? $reason) : null,
                ]);
            }

            AutomotivePriceHistory::create([
                'branch_id' => $branch->getKey(),
                'automotive_part_id' => $lockedPart->getKey(),
                'previous_price_mxn' => $prevPrice,
                'new_price_mxn' => $effectivePrice,
                'previous_price_status' => $prevStatus,
                'new_price_status' => $effectiveStatus,
                'previous_is_sellable' => $prevSellable,
                'new_is_sellable' => $effectiveSellable,
                'previous_sale_blocked' => $prevBlocked,
                'new_sale_blocked' => $effectiveBlocked,
                'operation_type' => $operationType,
                'batch_reference' => $batchReference,
                'reason' => $reason,
                'changed_by' => $actor->getKey(),
            ]);

            $fresh = $setting->fresh();
            $fresh->setAttribute('price_updated_by_name', $actor->name);
            $fresh->setAttribute('price_approved_by_name', $approvedById ? $actor->name : null);

            return $fresh;
        });
    }

    /**
     * Controlled batch price update preview without mutating database state.
     *
     * @param  array<int, array{part_id: int, sale_price_mxn: numeric|string, is_sellable?: bool}>  $items
     * @return array<string, mixed>
     */
    public function previewBatchUpdate(AutomotiveBranch $branch, array $items, string $reason, User $actor): array
    {
        $this->access->authorize($actor, $branch, BranchCatalogAccess::PRICES_MANAGE);

        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Debe proporcionar un motivo comercial para la actualización masiva (mínimo 5 caracteres).',
            ]);
        }

        if (empty($items)) {
            throw ValidationException::withMessages([
                'items' => 'Debe incluir al menos una autoparte para la vista previa de precios.',
            ]);
        }

        $rows = [];
        $validCount = 0;
        $errorCount = 0;

        foreach ($items as $idx => $item) {
            $partId = (int) ($item['part_id'] ?? 0);
            $part = AutomotivePart::query()->with(['sourceRow'])->find($partId);
            $rawPrice = $item['sale_price_mxn'] ?? null;
            $targetStatus = isset($item['price_status']) && in_array($item['price_status'], [
                AutomotivePartBranchSetting::PRICE_STATUS_PENDING_APPROVAL,
                AutomotivePartBranchSetting::PRICE_STATUS_APPROVED,
            ], true)
                ? (string) $item['price_status']
                : AutomotivePartBranchSetting::PRICE_STATUS_APPROVED;
            $targetSellable = $targetStatus === AutomotivePartBranchSetting::PRICE_STATUS_APPROVED
                ? (isset($item['is_sellable']) ? (bool) $item['is_sellable'] : true)
                : false;

            if (! $part) {
                $rows[] = [
                    'index' => $idx,
                    'part_id' => $partId,
                    'valid' => false,
                    'error' => 'Autoparte no encontrada.',
                ];
                $errorCount++;

                continue;
            }

            $setting = AutomotivePartBranchSetting::query()
                ->where('automotive_part_id', $part->getKey())
                ->where('branch_id', $branch->getKey())
                ->first();

            $doc = $this->extractDocumentalReferences($part->sourceRow?->cells);
            $errors = [];

            if ($rawPrice === null || ! is_numeric($rawPrice) || (float) $rawPrice <= 0) {
                $errors[] = 'El precio en MXN debe ser mayor a $0.00.';
            }

            if ($targetSellable && ! $part->isReceivable()) {
                $errors[] = 'Identidad ambigua; no puede habilitarse para venta sin verificación.';
            }

            if ($targetSellable && ($setting?->sale_blocked ?? false)) {
                $errors[] = 'Bloqueo administrativo activo.';
            }

            $isValid = empty($errors);
            if ($isValid) {
                $validCount++;
            } else {
                $errorCount++;
            }

            $rows[] = [
                'index' => $idx,
                'part_id' => $part->id,
                'code' => $part->code,
                'item_number' => $part->item_number,
                'manufacturer_part_number' => $part->manufacturer_part_number,
                'description' => $part->description,
                'documental_retail_usd' => $doc['retail_raw'],
                'previous_price_mxn' => $setting?->sale_price_mxn !== null ? number_format((float) $setting->sale_price_mxn, 2, '.', '') : null,
                'previous_price_status' => $setting?->price_status ?? AutomotivePartBranchSetting::PRICE_STATUS_UNPRICED,
                'new_price_mxn' => is_numeric($rawPrice) ? number_format(round((float) $rawPrice, 2), 2, '.', '') : null,
                'new_price_status' => $targetStatus,
                'previous_is_sellable' => (bool) ($setting?->is_sellable ?? false),
                'new_is_sellable' => $targetSellable,
                'valid' => $isValid,
                'errors' => $errors,
            ];
        }

        return [
            'reason' => $reason,
            'total_rows' => count($rows),
            'valid_rows' => $validCount,
            'error_rows' => $errorCount,
            'can_commit' => $validCount > 0 && $errorCount === 0,
            'rows' => $rows,
        ];
    }

    /**
     * Apply a validated batch price update atomically.
     *
     * @param  array<int, array{part_id: int, sale_price_mxn: numeric|string, price_status?: string, is_sellable?: bool}>  $items
     * @return array<string, mixed>
     */
    public function applyBatchUpdate(AutomotiveBranch $branch, array $items, string $reason, User $actor): array
    {
        $preview = $this->previewBatchUpdate($branch, $items, $reason, $actor);
        if (! $preview['can_commit']) {
            throw ValidationException::withMessages([
                'items' => 'El lote contiene partidas inválidas y no puede aplicarse.',
            ]);
        }

        $batchRef = 'PRC-'.strtoupper($branch->code).'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));

        return AutopartesModel::transaction(function () use ($branch, $items, $reason, $actor, $batchRef): array {
            $updated = 0;
            foreach ($items as $item) {
                $part = AutomotivePart::query()->findOrFail((int) $item['part_id']);
                $status = isset($item['price_status']) && in_array($item['price_status'], [
                    AutomotivePartBranchSetting::PRICE_STATUS_PENDING_APPROVAL,
                    AutomotivePartBranchSetting::PRICE_STATUS_APPROVED,
                ], true)
                    ? (string) $item['price_status']
                    : AutomotivePartBranchSetting::PRICE_STATUS_APPROVED;

                $this->configurePrice(
                    $branch,
                    $part,
                    [
                        'sale_price_mxn' => $item['sale_price_mxn'],
                        'price_status' => $status,
                        'is_sellable' => $status === AutomotivePartBranchSetting::PRICE_STATUS_APPROVED
                            ? (isset($item['is_sellable']) ? (bool) $item['is_sellable'] : true)
                            : false,
                        'reason' => $reason,
                    ],
                    $actor,
                    'batch',
                    $batchRef,
                );
                $updated++;
            }

            return [
                'batch_reference' => $batchRef,
                'updated_count' => $updated,
                'reason' => $reason,
            ];
        });
    }

    /**
     * Preview bulk price import from CSV/TSV text or structured rows (by Item # or internal code) without mutating state.
     *
     * @param  array{raw_text?: string, rows?: list<array<string, mixed>>, reason?: string, price_status?: string, is_sellable?: bool}  $payload
     * @return array<string, mixed>
     */
    public function previewBulkImport(AutomotiveBranch $branch, array $payload, User $actor): array
    {
        $this->access->authorize($actor, $branch, BranchCatalogAccess::PRICES_MANAGE);

        $reason = trim((string) ($payload['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Debe proporcionar un motivo comercial para la importación masiva de precios (mínimo 5 caracteres).',
            ]);
        }

        $defaultStatus = isset($payload['price_status']) && in_array($payload['price_status'], [
            AutomotivePartBranchSetting::PRICE_STATUS_PENDING_APPROVAL,
            AutomotivePartBranchSetting::PRICE_STATUS_APPROVED,
        ], true)
            ? (string) $payload['price_status']
            : AutomotivePartBranchSetting::PRICE_STATUS_APPROVED;

        $defaultSellable = $defaultStatus === AutomotivePartBranchSetting::PRICE_STATUS_APPROVED
            ? (isset($payload['is_sellable']) ? (bool) $payload['is_sellable'] : true)
            : false;

        $parsedInput = $this->parseBulkImportPayload($payload, $defaultStatus, $defaultSellable);
        if (empty($parsedInput)) {
            throw ValidationException::withMessages([
                'raw_text' => 'No se encontraron filas válidas para importar precios.',
            ]);
        }

        $rows = [];
        $validCount = 0;
        $errorCount = 0;

        foreach ($parsedInput as $idx => $entry) {
            $identifier = trim((string) ($entry['identifier'] ?? ''));
            $rawPrice = $entry['sale_price_mxn'] ?? null;
            $status = $entry['price_status'] ?? $defaultStatus;
            $sellable = $status === AutomotivePartBranchSetting::PRICE_STATUS_APPROVED
                ? (bool) ($entry['is_sellable'] ?? $defaultSellable)
                : false;

            $errors = [];
            $part = null;

            if ($identifier === '') {
                $errors[] = 'Identificador (Item # o código) vacío.';
            } else {
                $upper = mb_strtoupper($identifier);
                $byCode = AutomotivePart::query()
                    ->with(['sourceRow'])
                    ->whereRaw('UPPER(TRIM(code)) = ?', [$upper])
                    ->get();

                if ($byCode->count() === 1) {
                    $part = $byCode->first();
                } else {
                    $byItem = AutomotivePart::query()
                        ->with(['sourceRow'])
                        ->whereRaw('UPPER(TRIM(item_number)) = ?', [$upper])
                        ->get();

                    if ($byItem->count() === 1) {
                        $part = $byItem->first();
                    } elseif ($byItem->count() > 1) {
                        $errors[] = "El Item # [{$identifier}] coincide con {$byItem->count()} registros ambiguos; utilice el código interno exacto.";
                    } else {
                        $errors[] = "No existe ninguna autoparte con Item # o código [{$identifier}].";
                    }
                }
            }

            if ($rawPrice === null || $rawPrice === '' || ! is_numeric($rawPrice) || (float) $rawPrice <= 0) {
                $errors[] = 'El precio en MXN debe ser un número mayor a $0.00.';
            }

            $setting = null;
            $doc = ['retail_raw' => null, 'ext_retail_raw' => null, 'qty_raw' => null];
            if ($part) {
                $setting = AutomotivePartBranchSetting::query()
                    ->where('automotive_part_id', $part->getKey())
                    ->where('branch_id', $branch->getKey())
                    ->first();
                $doc = $this->extractDocumentalReferences($part->sourceRow?->cells);

                if ($sellable && ! $part->isReceivable()) {
                    $errors[] = 'Identidad ambigua; no puede habilitarse para venta sin verificación.';
                }
                if ($sellable && ($setting?->sale_blocked ?? false)) {
                    $errors[] = 'La autoparte tiene un bloqueo administrativo activo.';
                }
            }

            $isValid = empty($errors) && $part !== null;
            if ($isValid) {
                $validCount++;
            } else {
                $errorCount++;
            }

            $rows[] = [
                'index' => $idx,
                'identifier' => $identifier,
                'part_id' => $part?->id,
                'code' => $part?->code,
                'item_number' => $part?->item_number,
                'manufacturer_part_number' => $part?->manufacturer_part_number,
                'description' => $part?->description,
                'documental_retail_usd' => $doc['retail_raw'],
                'previous_price_mxn' => $setting?->sale_price_mxn !== null ? number_format((float) $setting->sale_price_mxn, 2, '.', '') : null,
                'previous_price_status' => $setting?->price_status ?? AutomotivePartBranchSetting::PRICE_STATUS_UNPRICED,
                'new_price_mxn' => is_numeric($rawPrice) && (float) $rawPrice > 0 ? number_format(round((float) $rawPrice, 2), 2, '.', '') : null,
                'new_price_status' => $status,
                'previous_is_sellable' => (bool) ($setting?->is_sellable ?? false),
                'new_is_sellable' => $sellable,
                'valid' => $isValid,
                'errors' => $errors,
            ];
        }

        return [
            'reason' => $reason,
            'total_rows' => count($rows),
            'valid_rows' => $validCount,
            'error_rows' => $errorCount,
            'can_commit' => $validCount > 0 && $errorCount === 0,
            'rows' => $rows,
        ];
    }

    /**
     * Confirm and apply a validated bulk price import atomically.
     *
     * @param  array{raw_text?: string, rows?: list<array<string, mixed>>, reason?: string, price_status?: string, is_sellable?: bool}  $payload
     * @return array<string, mixed>
     */
    public function applyBulkImport(AutomotiveBranch $branch, array $payload, User $actor): array
    {
        $preview = $this->previewBulkImport($branch, $payload, $actor);
        if (! $preview['can_commit']) {
            throw ValidationException::withMessages([
                'import' => 'La importación masiva contiene filas con errores y no puede confirmarse hasta corregirlas.',
            ]);
        }

        $reason = $preview['reason'];
        $batchRef = 'IMP-PRC-'.strtoupper($branch->code).'-'.now()->format('YmdHis').'-'.Str::upper(Str::random(6));

        return AutopartesModel::transaction(function () use ($branch, $preview, $reason, $actor, $batchRef): array {
            $updated = 0;
            foreach ($preview['rows'] as $row) {
                $part = AutomotivePart::query()->findOrFail((int) $row['part_id']);
                $this->configurePrice(
                    $branch,
                    $part,
                    [
                        'sale_price_mxn' => $row['new_price_mxn'],
                        'price_status' => $row['new_price_status'],
                        'is_sellable' => $row['new_is_sellable'],
                        'reason' => $reason,
                    ],
                    $actor,
                    'bulk_import',
                    $batchRef,
                );
                $updated++;
            }

            return [
                'batch_reference' => $batchRef,
                'updated_count' => $updated,
                'reason' => $reason,
                'rows' => $preview['rows'],
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{identifier: string, sale_price_mxn: mixed, price_status: string, is_sellable: bool}>
     */
    private function parseBulkImportPayload(array $payload, string $defaultStatus, bool $defaultSellable): array
    {
        if (! empty($payload['rows']) && is_array($payload['rows'])) {
            $out = [];
            foreach ($payload['rows'] as $r) {
                if (! is_array($r)) {
                    continue;
                }
                $status = isset($r['price_status']) && in_array($r['price_status'], [
                    AutomotivePartBranchSetting::PRICE_STATUS_PENDING_APPROVAL,
                    AutomotivePartBranchSetting::PRICE_STATUS_APPROVED,
                ], true)
                    ? (string) $r['price_status']
                    : $defaultStatus;

                $out[] = [
                    'identifier' => trim((string) ($r['identifier'] ?? $r['item_number'] ?? $r['code'] ?? '')),
                    'sale_price_mxn' => $r['sale_price_mxn'] ?? $r['price'] ?? null,
                    'price_status' => $status,
                    'is_sellable' => $status === AutomotivePartBranchSetting::PRICE_STATUS_APPROVED
                        ? (isset($r['is_sellable']) ? (bool) $r['is_sellable'] : $defaultSellable)
                        : false,
                ];
            }

            return $out;
        }

        $rawText = trim((string) ($payload['raw_text'] ?? ''));
        if ($rawText === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $rawText) ?: [];
        $out = [];
        foreach ($lines as $lineIdx => $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $delimiter = str_contains($trimmed, "\t") ? "\t" : (str_contains($trimmed, ';') ? ';' : ',');
            $cols = array_map('trim', str_getcsv($trimmed, $delimiter));
            $firstColUpper = mb_strtoupper((string) ($cols[0] ?? ''));
            if ($lineIdx === 0 && in_array($firstColUpper, ['ITEM #', 'ITEM', 'CODE', 'SKU', 'CODIGO', 'CÓDIGO', 'IDENTIFIER'], true)) {
                continue;
            }
            $id = (string) ($cols[0] ?? '');
            $priceStr = isset($cols[1]) ? str_replace(['$', ',', ' '], '', (string) $cols[1]) : null;
            $rowStatus = isset($cols[2]) && in_array(strtolower($cols[2]), ['pending_approval', 'approved'], true)
                ? strtolower($cols[2])
                : $defaultStatus;
            $rowSellable = isset($cols[3]) && $cols[3] !== ''
                ? in_array(strtolower($cols[3]), ['1', 'true', 'si', 'sí', 'yes'], true)
                : $defaultSellable;

            $out[] = [
                'identifier' => $id,
                'sale_price_mxn' => $priceStr,
                'price_status' => $rowStatus,
                'is_sellable' => $rowStatus === AutomotivePartBranchSetting::PRICE_STATUS_APPROVED ? $rowSellable : false,
            ];
        }

        return $out;
    }
}
