<?php

namespace App\Services\Autopartes;

use App\Models\AutomotiveBranch;
use App\Models\AutomotiveBranchCapability;
use App\Models\User;
use App\Support\AutopartesConnectionGuard;
use Illuminate\Auth\Access\AuthorizationException;

final class BranchCatalogAccess
{
    public const CATALOG_AUTOMOTIVE = 'automotive';

    public const CATALOG_VIEW = 'automotive.catalog.view';

    public const STOCK_VIEW = 'inventory.stock.view';

    public const SOURCE_REVIEW = 'automotive.sources.review';

    public const IMPORT = 'automotive.imports.manage';

    public const RECEIPT_DRAFT = 'automotive.receipts.draft';

    public const RECEIPT_CONFIRM = 'automotive.receipts.confirm';

    public const MOVEMENTS_VIEW = 'automotive.movements.view';

    public const ADJUSTMENT_CREATE = 'automotive.adjustments.create';

    public const ADJUSTMENT_APPROVE = 'automotive.adjustments.approve';

    public const IDENTITY_RESOLVE = 'automotive.identities.resolve';

    public const POS_VIEW = 'automotive.pos.view';

    public const POS_SELL = 'automotive.pos.sell';

    public const POS_SHIFT_MANAGE = 'automotive.pos.shifts.manage';

    public const SELLERS_MANAGE = 'automotive.sellers.manage';

    public const PRICES_VIEW = 'automotive.prices.view';

    public const PRICES_MANAGE = 'automotive.prices.manage';

    public const DISCOUNTS_VIEW = 'automotive.discounts.view';

    public const DISCOUNTS_MANAGE = 'automotive.discounts.manage';

    public const DISCOUNTS_APPROVE = 'automotive.discounts.approve';

    public const SALES_VIEW = 'automotive.sales.view';

    public const SALES_CANCEL = 'automotive.sales.cancel';

    public const SALES_RETURN = 'automotive.sales.return';

    public const REPORTS_VIEW = 'automotive.reports.view';

    public const REPORTS_EXPORT = 'automotive.reports.export';

    public const ALL_CAPABILITIES = [
        self::CATALOG_VIEW,
        self::STOCK_VIEW,
        self::SOURCE_REVIEW,
        self::IMPORT,
        self::RECEIPT_DRAFT,
        self::RECEIPT_CONFIRM,
        self::MOVEMENTS_VIEW,
        self::ADJUSTMENT_CREATE,
        self::ADJUSTMENT_APPROVE,
        self::IDENTITY_RESOLVE,
        self::POS_VIEW,
        self::POS_SELL,
        self::POS_SHIFT_MANAGE,
        self::SELLERS_MANAGE,
        self::PRICES_VIEW,
        self::PRICES_MANAGE,
        self::DISCOUNTS_VIEW,
        self::DISCOUNTS_MANAGE,
        self::DISCOUNTS_APPROVE,
        self::SALES_VIEW,
        self::SALES_CANCEL,
        self::SALES_RETURN,
        self::REPORTS_VIEW,
        self::REPORTS_EXPORT,
    ];

    public function allows(User $user, AutomotiveBranch $branch, string $catalog, string $capability): bool
    {
        // No role bypass, implicit grants, wildcards or access to other catalogs.
        if ($catalog !== self::CATALOG_AUTOMOTIVE || ! in_array($capability, self::ALL_CAPABILITIES, true)) {
            return false;
        }

        AutopartesConnectionGuard::assertDedicatedConnectionSafe();

        // Todos los modelos implicados viven dentro de la misma conexión 'autopartes'.
        return AutomotiveBranchCapability::query()
            ->where('capability', $capability)
            ->whereHas('membership', fn ($query) => $query
                ->where('user_id', $user->getKey())
                ->where('branch_id', $branch->getKey())
                ->where('is_active', true)
                ->whereHas('branch', fn ($branchQuery) => $branchQuery->where('is_active', true)))
            ->exists();
    }

    /** @return list<string> */
    public function capabilities(User $user, AutomotiveBranch $branch): array
    {
        AutopartesConnectionGuard::assertDedicatedConnectionSafe();

        return AutomotiveBranchCapability::query()
            ->whereIn('capability', self::ALL_CAPABILITIES)
            ->whereHas('membership', fn ($query) => $query
                ->where('user_id', $user->getKey())
                ->where('branch_id', $branch->getKey())
                ->where('is_active', true)
                ->whereHas('branch', fn ($branchQuery) => $branchQuery->where('is_active', true)))
            ->pluck('capability')
            ->values()
            ->all();
    }

    public function authorize(User $user, AutomotiveBranch $branch, string $capability): void
    {
        if (! $this->allows($user, $branch, self::CATALOG_AUTOMOTIVE, $capability)) {
            throw new AuthorizationException('No tiene autorización para este catálogo y sucursal.');
        }
    }
}
