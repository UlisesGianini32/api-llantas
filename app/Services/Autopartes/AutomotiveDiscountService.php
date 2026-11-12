<?php

namespace App\Services\Autopartes;

use App\Models\AutomotiveBranch;
use App\Models\AutomotiveBranchCommercialPolicy;
use App\Models\AutomotiveDiscountApproval;
use App\Models\AutomotiveWholesaleDiscountRule;
use App\Models\AutopartesModel;
use App\Models\User;
use App\Support\AutopartesUserDirectory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AutomotiveDiscountService
{
    /** @var list<int> */
    private array $pendingInvalidatedApprovalIds = [];

    public function __construct(
        private readonly BranchCatalogAccess $access,
    ) {}

    public function flushPendingInvalidations(): void
    {
        if (empty($this->pendingInvalidatedApprovalIds)) {
            return;
        }

        $ids = array_values(array_unique($this->pendingInvalidatedApprovalIds));
        $this->pendingInvalidatedApprovalIds = [];

        AutomotiveDiscountApproval::query()
            ->whereIn('id', $ids)
            ->where('status', AutomotiveDiscountApproval::STATUS_APPROVED)
            ->update(['status' => AutomotiveDiscountApproval::STATUS_INVALIDATED]);
    }

    public static function toCents(float|int|string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public function getCommercialPolicy(AutomotiveBranch $branch): AutomotiveBranchCommercialPolicy
    {
        return $branch->ensureDefaultCommercialPolicy();
    }

    /**
     * Configure branch commercial, fiscal and manual-discount policy without modifying code.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateCommercialPolicy(AutomotiveBranch $branch, array $data, User $actor): AutomotiveBranchCommercialPolicy
    {
        $this->access->authorize($actor, $branch, BranchCatalogAccess::DISCOUNTS_MANAGE);

        $reason = trim((string) ($data['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'reason' => 'Debe indicar el motivo de actualización de la política comercial (mínimo 5 caracteres).',
            ]);
        }

        $policy = $branch->ensureDefaultCommercialPolicy();

        $pct = array_key_exists('cashier_max_discount_percent', $data)
            ? (float) $data['cashier_max_discount_percent']
            : (float) $policy->cashier_max_discount_percent;
        if ($pct < 0 || $pct >= 100) {
            throw ValidationException::withMessages([
                'cashier_max_discount_percent' => 'El porcentaje máximo de descuento de cajero debe estar entre 0% y 99.99%.',
            ]);
        }

        $amt = array_key_exists('cashier_max_discount_amount', $data)
            ? (float) $data['cashier_max_discount_amount']
            : (float) $policy->cashier_max_discount_amount;
        if ($amt < 0) {
            throw ValidationException::withMessages([
                'cashier_max_discount_amount' => 'El monto máximo de descuento de cajero no puede ser negativo.',
            ]);
        }

        $taxRate = array_key_exists('tax_rate', $data)
            ? (float) $data['tax_rate']
            : (float) $policy->tax_rate;
        if ($taxRate < 0 || $taxRate > 1) {
            throw ValidationException::withMessages([
                'tax_rate' => 'La tasa de impuesto debe expresarse entre 0.0000 y 1.0000 (ej. 0.1600 para 16%).',
            ]);
        }

        $thermalWidth = array_key_exists('default_thermal_width', $data)
            ? trim((string) $data['default_thermal_width'])
            : (string) $policy->default_thermal_width;
        if (! in_array($thermalWidth, ['58mm', '80mm'], true)) {
            $thermalWidth = '80mm';
        }

        $policy->update([
            'pos_sales_enabled' => array_key_exists('pos_sales_enabled', $data) ? (bool) $data['pos_sales_enabled'] : $policy->pos_sales_enabled,
            'fiscal_policy_validated' => array_key_exists('fiscal_policy_validated', $data) ? (bool) $data['fiscal_policy_validated'] : $policy->fiscal_policy_validated,
            'fiscal_regime_notes' => array_key_exists('fiscal_regime_notes', $data)
                ? (trim((string) $data['fiscal_regime_notes']) ?: null)
                : $policy->fiscal_regime_notes,
            'tax_enabled' => array_key_exists('tax_enabled', $data) ? (bool) $data['tax_enabled'] : $policy->tax_enabled,
            'tax_rate' => number_format($taxRate, 4, '.', ''),
            'manual_discounts_enabled' => array_key_exists('manual_discounts_enabled', $data) ? (bool) $data['manual_discounts_enabled'] : $policy->manual_discounts_enabled,
            'cashier_max_discount_percent' => number_format($pct, 2, '.', ''),
            'cashier_max_discount_amount' => number_format($amt, 2, '.', ''),
            'default_thermal_width' => $thermalWidth,
            'reason' => $reason,
            'updated_by' => $actor->getKey(),
        ]);

        $fresh = $policy->fresh();
        $fresh->setAttribute('updated_by_name', $actor->name);

        return $fresh;
    }

    /** @return Collection<int, AutomotiveWholesaleDiscountRule> */
    public function listRules(AutomotiveBranch $branch, User $actor): Collection
    {
        if (
            ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::DISCOUNTS_VIEW)
            && ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::DISCOUNTS_MANAGE)
            && ! $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::POS_VIEW)
        ) {
            $this->access->authorize($actor, $branch, BranchCatalogAccess::DISCOUNTS_VIEW);
        }

        $rules = AutomotiveWholesaleDiscountRule::query()
            ->where('branch_id', $branch->getKey())
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $userIds = [];
        foreach ($rules as $rule) {
            $userIds[] = $rule->created_by;
            $userIds[] = $rule->updated_by;
        }
        $users = AutopartesUserDirectory::mapByIds($userIds);
        foreach ($rules as $rule) {
            $rule->setAttribute('created_by_name', $users->get((int) $rule->created_by)?->name);
            $rule->setAttribute('updated_by_name', $users->get((int) $rule->updated_by)?->name);
        }

        return $rules;
    }

    public function createRule(AutomotiveBranch $branch, array $data, User $actor): AutomotiveWholesaleDiscountRule
    {
        $this->access->authorize($actor, $branch, BranchCatalogAccess::DISCOUNTS_MANAGE);

        $validated = $this->validateRulePayload($branch, $data);

        return AutomotiveWholesaleDiscountRule::create([
            ...$validated,
            'branch_id' => $branch->getKey(),
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]);
    }

    public function updateRule(
        AutomotiveBranch $branch,
        AutomotiveWholesaleDiscountRule $rule,
        array $data,
        User $actor,
    ): AutomotiveWholesaleDiscountRule {
        $this->access->authorize($actor, $branch, BranchCatalogAccess::DISCOUNTS_MANAGE);

        if ((int) $rule->branch_id !== (int) $branch->getKey()) {
            throw ValidationException::withMessages([
                'rule' => 'La regla de descuento no pertenece a esta sucursal.',
            ]);
        }

        $merged = array_merge($rule->toArray(), $data);
        $validated = $this->validateRulePayload($branch, $merged);

        $rule->update([
            ...$validated,
            'updated_by' => $actor->getKey(),
        ]);

        $fresh = $rule->fresh();
        $users = AutopartesUserDirectory::mapByIds([$fresh->created_by, $fresh->updated_by]);
        $fresh->setAttribute('created_by_name', $users->get((int) $fresh->created_by)?->name);
        $fresh->setAttribute('updated_by_name', $users->get((int) $fresh->updated_by)?->name);

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validateRulePayload(AutomotiveBranch $branch, array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if (mb_strlen($name) < 3) {
            throw ValidationException::withMessages([
                'name' => 'El nombre de la regla de mayoreo es obligatorio (mínimo 3 caracteres).',
            ]);
        }

        $minQty = (int) ($data['min_quantity'] ?? 0);
        if ($minQty < 1) {
            throw ValidationException::withMessages([
                'min_quantity' => 'La cantidad mínima debe ser al menos 1 unidad.',
            ]);
        }

        $maxQty = isset($data['max_quantity']) && $data['max_quantity'] !== '' && $data['max_quantity'] !== null
            ? (int) $data['max_quantity']
            : null;

        if ($maxQty !== null && $maxQty < $minQty) {
            throw ValidationException::withMessages([
                'max_quantity' => 'La cantidad máxima no puede ser menor que la cantidad mínima.',
            ]);
        }

        $discountType = (string) ($data['discount_type'] ?? '');
        if (! in_array($discountType, AutomotiveWholesaleDiscountRule::TYPES, true)) {
            throw ValidationException::withMessages([
                'discount_type' => 'El tipo de descuento debe ser percentage o fixed_amount.',
            ]);
        }

        $discountValue = isset($data['discount_value']) && is_numeric($data['discount_value'])
            ? round((float) $data['discount_value'], 2)
            : 0.0;

        if ($discountValue <= 0) {
            throw ValidationException::withMessages([
                'discount_value' => 'El valor del descuento debe ser mayor a cero.',
            ]);
        }

        if ($discountType === AutomotiveWholesaleDiscountRule::TYPE_PERCENTAGE && $discountValue >= 100) {
            throw ValidationException::withMessages([
                'discount_value' => 'El porcentaje de descuento por mayoreo debe ser menor al 100%.',
            ]);
        }

        $scopeType = (string) ($data['scope_type'] ?? '');
        if (! in_array($scopeType, AutomotiveWholesaleDiscountRule::SCOPES, true)) {
            throw ValidationException::withMessages([
                'scope_type' => 'El alcance de la regla debe ser same_product, category o eligible_cart_total.',
            ]);
        }

        $eligibleCategories = array_values(array_filter(array_map(
            fn ($cat) => trim((string) $cat),
            is_array($data['eligible_categories'] ?? null) ? $data['eligible_categories'] : []
        ), fn ($cat) => $cat !== ''));

        $rawPartIds = $data['eligible_part_ids'] ?? null;
        $eligiblePartIds = array_values(array_unique(array_filter(array_map(
            fn ($id) => (int) $id,
            is_array($rawPartIds) ? $rawPartIds : []
        ), fn ($id) => $id > 0)));

        $startsAt = ! empty($data['starts_at']) ? $data['starts_at'] : null;
        $endsAt = ! empty($data['ends_at']) ? $data['ends_at'] : null;

        if ($startsAt && $endsAt && strtotime((string) $endsAt) < strtotime((string) $startsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => 'La fecha de vencimiento no puede ser anterior a la fecha de inicio.',
            ]);
        }

        return [
            'name' => $name,
            'is_active' => isset($data['is_active']) ? (bool) $data['is_active'] : true,
            'min_quantity' => $minQty,
            'max_quantity' => $maxQty,
            'discount_type' => $discountType,
            'discount_value' => number_format($discountValue, 2, '.', ''),
            'scope_type' => $scopeType,
            'eligible_categories' => ! empty($eligibleCategories) ? $eligibleCategories : null,
            'eligible_part_ids' => ! empty($eligiblePartIds) ? $eligiblePartIds : null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'priority' => isset($data['priority']) ? (int) $data['priority'] : 100,
            'stackable' => isset($data['stackable']) ? (bool) $data['stackable'] : false,
            'allow_manual_combination' => isset($data['allow_manual_combination']) ? (bool) $data['allow_manual_combination'] : false,
            'notes' => isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null,
        ];
    }

    /**
     * Compute deterministic wholesale discounts for normalized cart lines.
     *
     * @param  list<array{
     *     automotive_part_id: int,
     *     category: ?string,
     *     quantity: int,
     *     unit_price_cents: int,
     *     line_gross_cents: int
     * }>  $lines
     * @return array{
     *     line_discounts_cents: array<int, int>,
     *     total_wholesale_cents: int,
     *     applied_rules: list<array<string, mixed>>,
     *     allows_manual_combination: bool
     * }
     */
    public function evaluateWholesaleDiscounts(AutomotiveBranch $branch, array $lines): array
    {
        $lineDiscountsCents = [];
        $lineAppliedRuleNonStackable = [];
        foreach ($lines as $idx => $line) {
            $lineDiscountsCents[$idx] = 0;
            $lineAppliedRuleNonStackable[$idx] = false;
        }

        $now = now();
        $activeRules = AutomotiveWholesaleDiscountRule::query()
            ->where('branch_id', $branch->getKey())
            ->where('is_active', true)
            ->where(function ($q) use ($now): void {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($q) use ($now): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        if ($activeRules->isEmpty() || empty($lines)) {
            return [
                'line_discounts_cents' => $lineDiscountsCents,
                'total_wholesale_cents' => 0,
                'applied_rules' => [],
                'allows_manual_combination' => true,
            ];
        }

        $appliedRules = [];
        $allowsManualCombination = true;

        foreach ($activeRules as $rule) {
            $eligibleIndices = [];
            foreach ($lines as $idx => $line) {
                if (! $this->lineMatchesRuleEligibility($line, $rule)) {
                    continue;
                }
                if ($lineAppliedRuleNonStackable[$idx]) {
                    continue;
                }
                if ($lineDiscountsCents[$idx] > 0 && ! $rule->stackable) {
                    continue;
                }
                $eligibleIndices[] = $idx;
            }

            if (empty($eligibleIndices)) {
                continue;
            }

            $qualifyingGroups = [];

            if ($rule->scope_type === AutomotiveWholesaleDiscountRule::SCOPE_SAME_PRODUCT) {
                foreach ($eligibleIndices as $idx) {
                    $qty = (int) $lines[$idx]['quantity'];
                    if ($this->quantityMatchesRule($qty, $rule)) {
                        $qualifyingGroups[] = [$idx];
                    }
                }
            } elseif ($rule->scope_type === AutomotiveWholesaleDiscountRule::SCOPE_CATEGORY) {
                $byCategory = [];
                foreach ($eligibleIndices as $idx) {
                    $catKey = mb_strtoupper(trim((string) ($lines[$idx]['category'] ?? '__UNCATEGORIZED__')));
                    $byCategory[$catKey][] = $idx;
                }
                foreach ($byCategory as $catIndices) {
                    $catQty = 0;
                    foreach ($catIndices as $idx) {
                        $catQty += (int) $lines[$idx]['quantity'];
                    }
                    if ($this->quantityMatchesRule($catQty, $rule)) {
                        $qualifyingGroups[] = $catIndices;
                    }
                }
            } elseif ($rule->scope_type === AutomotiveWholesaleDiscountRule::SCOPE_ELIGIBLE_CART_TOTAL) {
                $totalEligibleQty = 0;
                foreach ($eligibleIndices as $idx) {
                    $totalEligibleQty += (int) $lines[$idx]['quantity'];
                }
                if ($this->quantityMatchesRule($totalEligibleQty, $rule)) {
                    $qualifyingGroups[] = $eligibleIndices;
                }
            }

            if (empty($qualifyingGroups)) {
                continue;
            }

            $ruleDiscountCentsTotal = 0;

            foreach ($qualifyingGroups as $groupIndices) {
                if ($rule->discount_type === AutomotiveWholesaleDiscountRule::TYPE_PERCENTAGE) {
                    $pct = (float) $rule->discount_value;
                    foreach ($groupIndices as $idx) {
                        $remainingLineCents = max(0, $lines[$idx]['line_gross_cents'] - $lineDiscountsCents[$idx]);
                        $disc = (int) round($remainingLineCents * ($pct / 100));
                        // Leave at least 1 cent per line so wholesale discount never makes a line 0 or negative
                        $disc = min($disc, max(0, $remainingLineCents - 1));
                        if ($disc > 0) {
                            $lineDiscountsCents[$idx] += $disc;
                            $ruleDiscountCentsTotal += $disc;
                            if (! $rule->stackable) {
                                $lineAppliedRuleNonStackable[$idx] = true;
                            }
                        }
                    }
                } else {
                    $fixedCents = self::toCents((float) $rule->discount_value);
                    $groupRemainingCents = 0;
                    foreach ($groupIndices as $idx) {
                        $groupRemainingCents += max(0, $lines[$idx]['line_gross_cents'] - $lineDiscountsCents[$idx]);
                    }
                    $effectiveGroupDisc = min($fixedCents, max(0, $groupRemainingCents - count($groupIndices)));
                    if ($effectiveGroupDisc > 0 && $groupRemainingCents > 0) {
                        $allocated = 0;
                        $lastIdx = end($groupIndices);
                        foreach ($groupIndices as $idx) {
                            $rem = max(0, $lines[$idx]['line_gross_cents'] - $lineDiscountsCents[$idx]);
                            if ($idx === $lastIdx) {
                                $disc = max(0, $effectiveGroupDisc - $allocated);
                            } else {
                                $disc = (int) floor($effectiveGroupDisc * ($rem / $groupRemainingCents));
                            }
                            $disc = min($disc, max(0, $rem - 1));
                            if ($disc > 0) {
                                $lineDiscountsCents[$idx] += $disc;
                                $allocated += $disc;
                                $ruleDiscountCentsTotal += $disc;
                                if (! $rule->stackable) {
                                    $lineAppliedRuleNonStackable[$idx] = true;
                                }
                            }
                        }
                    }
                }
            }

            if ($ruleDiscountCentsTotal > 0) {
                $appliedRules[] = [
                    'rule_id' => $rule->id,
                    'name' => $rule->name,
                    'scope_type' => $rule->scope_type,
                    'discount_type' => $rule->discount_type,
                    'discount_value' => (string) $rule->discount_value,
                    'discount_amount' => self::fromCents($ruleDiscountCentsTotal),
                    'priority' => $rule->priority,
                    'stackable' => (bool) $rule->stackable,
                    'allow_manual_combination' => (bool) $rule->allow_manual_combination,
                ];
                if (! $rule->allow_manual_combination) {
                    $allowsManualCombination = false;
                }
            }
        }

        return [
            'line_discounts_cents' => $lineDiscountsCents,
            'total_wholesale_cents' => array_sum($lineDiscountsCents),
            'applied_rules' => $appliedRules,
            'allows_manual_combination' => $allowsManualCombination,
        ];
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function lineMatchesRuleEligibility(array $line, AutomotiveWholesaleDiscountRule $rule): bool
    {
        $partIds = $rule->eligible_part_ids ?? [];
        if (! empty($partIds) && ! in_array((int) $line['automotive_part_id'], array_map('intval', $partIds), true)) {
            return false;
        }

        $categories = $rule->eligible_categories ?? [];
        if (! empty($categories)) {
            $lineCat = mb_strtoupper(trim((string) ($line['category'] ?? '')));
            $normalizedAllowed = array_map(fn ($c) => mb_strtoupper(trim((string) $c)), $categories);
            if ($lineCat === '' || ! in_array($lineCat, $normalizedAllowed, true)) {
                return false;
            }
        }

        return true;
    }

    private function quantityMatchesRule(int $qty, AutomotiveWholesaleDiscountRule $rule): bool
    {
        if ($qty < (int) $rule->min_quantity) {
            return false;
        }
        if ($rule->max_quantity !== null && $qty > (int) $rule->max_quantity) {
            return false;
        }

        return true;
    }

    /**
     * Compute a deterministic SHA-256 hash of the cart so that any modification to autoparts,
     * quantities, authorized prices, or wholesale discounts invalidates manual discount approvals.
     *
     * @param  list<array{automotive_part_id: int, quantity: int, unit_price_cents: int, wholesale_discount_cents?: int}>  $lines
     */
    public function computeCartHash(AutomotiveBranch $branch, array $lines): string
    {
        $canonical = array_map(fn (array $line): array => [
            'p' => (int) $line['automotive_part_id'],
            'q' => (int) $line['quantity'],
            'u' => (int) $line['unit_price_cents'],
            'w' => (int) ($line['wholesale_discount_cents'] ?? 0),
        ], $lines);

        usort($canonical, fn (array $a, array $b): int => $a['p'] <=> $b['p']);

        return hash('sha256', json_encode([
            'branch_id' => (int) $branch->getKey(),
            'lines' => $canonical,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Evaluate and validate a manual discount on the server.
     *
     * @param  list<array{
     *     automotive_part_id: int,
     *     quantity: int,
     *     unit_price_cents: int,
     *     line_gross_cents: int,
     *     wholesale_discount_cents: int
     * }>  $lines
     * @param  ?array{
     *     discount_type?: ?string,
     *     discount_value?: numeric|string|null,
     *     reason?: ?string,
     *     approval_token?: ?string
     * }  $manualInput
     * @return array{
     *     manual_discount_cents: int,
     *     line_manual_discounts_cents: array<int, int>,
     *     requires_approval: bool,
     *     approval_id: ?int,
     *     snapshot: ?array<string, mixed>
     * }
     */
    public function evaluateManualDiscount(
        AutomotiveBranch $branch,
        array $lines,
        ?array $manualInput,
        bool $wholesaleAllowsManualCombination,
        User $actor,
        bool $enforceApproval = true,
    ): array {
        $lineManualCents = array_fill(0, count($lines), 0);

        if (empty($manualInput) || empty($manualInput['discount_type']) || ($manualInput['discount_value'] ?? null) === null || (float) ($manualInput['discount_value'] ?? 0) <= 0) {
            return [
                'manual_discount_cents' => 0,
                'line_manual_discounts_cents' => $lineManualCents,
                'requires_approval' => false,
                'approval_id' => null,
                'snapshot' => null,
            ];
        }

        $policy = $branch->ensureDefaultCommercialPolicy();
        $manualDiscountsEnabled = (bool) $policy->manual_discounts_enabled
            || (bool) config('autopartes.pos.manual_discounts_enabled_by_default', false);

        if (! $manualDiscountsEnabled) {
            throw ValidationException::withMessages([
                'manual_discount' => 'Los descuentos manuales permanecen deshabilitados hasta configurar las capacidades y límites autorizados de la sucursal.',
            ]);
        }

        $totalWholesaleCents = array_sum(array_column($lines, 'wholesale_discount_cents'));
        if ($totalWholesaleCents > 0 && ! $wholesaleAllowsManualCombination) {
            throw ValidationException::withMessages([
                'manual_discount' => 'No se permite acumular un descuento manual sobre una regla de mayoreo que no autoriza combinación.',
            ]);
        }

        $discountType = (string) $manualInput['discount_type'];
        if (! in_array($discountType, ['percentage', 'fixed_amount'], true)) {
            throw ValidationException::withMessages([
                'manual_discount.discount_type' => 'El tipo de descuento manual debe ser percentage o fixed_amount.',
            ]);
        }

        $discountValue = round((float) $manualInput['discount_value'], 2);
        if ($discountValue <= 0) {
            throw ValidationException::withMessages([
                'manual_discount.discount_value' => 'El descuento manual debe ser mayor a cero.',
            ]);
        }

        $reason = trim((string) ($manualInput['reason'] ?? ''));
        if (mb_strlen($reason) < 5) {
            throw ValidationException::withMessages([
                'manual_discount.reason' => 'Es obligatorio indicar el motivo del descuento manual (mínimo 5 caracteres).',
            ]);
        }

        $grossSubtotalCents = array_sum(array_column($lines, 'line_gross_cents'));
        $baseAfterWholesaleCents = max(0, $grossSubtotalCents - $totalWholesaleCents);

        if ($baseAfterWholesaleCents <= 1) {
            throw ValidationException::withMessages([
                'manual_discount' => 'El importe del carrito no permite aplicar un descuento manual adicional.',
            ]);
        }

        if ($discountType === 'percentage') {
            if ($discountValue >= 100) {
                throw ValidationException::withMessages([
                    'manual_discount.discount_value' => 'El descuento manual no puede ser del 100% o superior (no se permiten totales en cero o negativos).',
                ]);
            }
            $computedManualCents = (int) round($baseAfterWholesaleCents * ($discountValue / 100));
        } else {
            $computedManualCents = self::toCents($discountValue);
        }

        if ($computedManualCents <= 0) {
            throw ValidationException::withMessages([
                'manual_discount.discount_value' => 'El descuento manual calculado debe ser de al menos $0.01 MXN.',
            ]);
        }

        if ($computedManualCents >= $baseAfterWholesaleCents) {
            throw ValidationException::withMessages([
                'manual_discount.discount_value' => 'El descuento manual no puede igualar ni superar el subtotal de la venta.',
            ]);
        }

        $effectivePercent = round(($computedManualCents / $baseAfterWholesaleCents) * 100, 4);
        $maxCashierPct = (float) ($policy->cashier_max_discount_percent > 0
            ? $policy->cashier_max_discount_percent
            : config('autopartes.pos.cashier_max_manual_discount_percent', 0.00));
        $maxCashierAmt = (float) ($policy->cashier_max_discount_amount > 0
            ? $policy->cashier_max_discount_amount
            : config('autopartes.pos.cashier_max_manual_discount_amount', 0.00));
        $maxCashierAmtCents = self::toCents($maxCashierAmt);

        $exceedsCashierLimit = ($maxCashierPct <= 0)
            || ($maxCashierAmtCents <= 0)
            || ($effectivePercent > $maxCashierPct)
            || ($computedManualCents > $maxCashierAmtCents);
        $actorCanApprove = $this->access->allows($actor, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::DISCOUNTS_APPROVE);

        $cartHash = $this->computeCartHash($branch, $lines);
        $approvalId = null;
        $requestedById = $actor->getKey();
        $approvedById = $actorCanApprove ? $actor->getKey() : null;

        if ($exceedsCashierLimit && ! $actorCanApprove) {
            $token = trim((string) ($manualInput['approval_token'] ?? ''));
            if ($enforceApproval) {
                if ($token === '') {
                    throw ValidationException::withMessages([
                        'manual_discount' => sprintf(
                            'El descuento manual solicitado (%s%% / $%s MXN) supera el límite del cajero (%s%% / $%s MXN) y requiere aprobación administrativa.',
                            number_format($effectivePercent, 2),
                            self::fromCents($computedManualCents),
                            number_format($maxCashierPct, 2),
                            self::fromCents($maxCashierAmtCents),
                        ),
                    ]);
                }

                $approval = AutomotiveDiscountApproval::query()
                    ->where('branch_id', $branch->getKey())
                    ->where('token', $token)
                    ->lockForUpdate()
                    ->first();

                if (! $approval || $approval->status !== AutomotiveDiscountApproval::STATUS_APPROVED || $approval->consumed_sale_id !== null) {
                    throw ValidationException::withMessages([
                        'manual_discount.approval_token' => 'El token de aprobación administrativa no es válido o ya fue utilizado.',
                    ]);
                }

                if ($approval->expires_at && $approval->expires_at->isPast()) {
                    $this->pendingInvalidatedApprovalIds[] = (int) $approval->getKey();
                    $approval->update(['status' => AutomotiveDiscountApproval::STATUS_INVALIDATED]);
                    throw ValidationException::withMessages([
                        'manual_discount.approval_token' => 'La aprobación administrativa del descuento ha expirado.',
                    ]);
                }

                if (! hash_equals((string) $approval->cart_hash, $cartHash)) {
                    $this->pendingInvalidatedApprovalIds[] = (int) $approval->getKey();
                    $approval->update(['status' => AutomotiveDiscountApproval::STATUS_INVALIDATED]);
                    throw ValidationException::withMessages([
                        'manual_discount.approval_token' => 'El carrito o sus importes cambiaron después de la aprobación; solicite una nueva autorización.',
                    ]);
                }

                if (
                    $approval->discount_type !== $discountType
                    || self::toCents((float) $approval->discount_value) !== self::toCents($discountValue)
                    || self::toCents((float) $approval->computed_discount_amount) !== $computedManualCents
                ) {
                    $this->pendingInvalidatedApprovalIds[] = (int) $approval->getKey();
                    $approval->update(['status' => AutomotiveDiscountApproval::STATUS_INVALIDATED]);
                    throw ValidationException::withMessages([
                        'manual_discount.approval_token' => 'El importe del descuento no coincide con el autorizado.',
                    ]);
                }

                $approvalId = $approval->getKey();
                $requestedById = $approval->requested_by;
                $approvedById = $approval->approved_by;
            }
        } elseif (! $exceedsCashierLimit) {
            $approvedById = $actor->getKey();
        }

        // Distribute manual discount proportionally across lines
        $allocated = 0;
        $lastIdx = count($lines) - 1;
        foreach ($lines as $idx => $line) {
            $rem = max(0, $line['line_gross_cents'] - $line['wholesale_discount_cents']);
            if ($idx === $lastIdx) {
                $disc = max(0, $computedManualCents - $allocated);
            } else {
                $disc = (int) floor($computedManualCents * ($rem / $baseAfterWholesaleCents));
            }
            $disc = min($disc, max(0, $rem - 1));
            $lineManualCents[$idx] = $disc;
            $allocated += $disc;
        }

        return [
            'manual_discount_cents' => array_sum($lineManualCents),
            'line_manual_discounts_cents' => $lineManualCents,
            'requires_approval' => $exceedsCashierLimit && ! $actorCanApprove,
            'approval_id' => $approvalId,
            'snapshot' => [
                'discount_type' => $discountType,
                'discount_value' => number_format($discountValue, 2, '.', ''),
                'effective_percentage' => number_format($effectivePercent, 4, '.', ''),
                'computed_amount' => self::fromCents(array_sum($lineManualCents)),
                'reason' => $reason,
                'requested_by' => $requestedById,
                'approved_by' => $approvedById,
                'approval_id' => $approvalId,
                'cart_hash' => $cartHash,
            ],
        ];
    }

    /**
     * Create a pending (or immediately approved if actor has DISCOUNTS_APPROVE) manual discount approval record.
     *
     * @param  list<array{
     *     automotive_part_id: int,
     *     quantity: int,
     *     unit_price_cents: int,
     *     line_gross_cents: int,
     *     wholesale_discount_cents: int
     * }>  $lines
     * @param  array<string, mixed>  $manualInput
     */
    public function requestApproval(
        AutomotiveBranch $branch,
        array $lines,
        array $manualInput,
        bool $wholesaleAllowsManualCombination,
        User $requester,
        ?User $approver = null,
    ): AutomotiveDiscountApproval {
        if (
            ! $this->access->allows($requester, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::POS_SELL)
            && ! $this->access->allows($requester, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::DISCOUNTS_APPROVE)
        ) {
            $this->access->authorize($requester, $branch, BranchCatalogAccess::POS_SELL);
        }

        $eval = $this->evaluateManualDiscount(
            $branch,
            $lines,
            $manualInput,
            $wholesaleAllowsManualCombination,
            $requester,
            false,
        );

        if ($eval['manual_discount_cents'] <= 0 || ! $eval['snapshot']) {
            throw ValidationException::withMessages([
                'manual_discount' => 'Debe especificar un descuento manual válido para solicitar aprobación.',
            ]);
        }

        $effectiveApprover = $approver;
        if (! $effectiveApprover && $this->access->allows($requester, $branch, BranchCatalogAccess::CATALOG_AUTOMOTIVE, BranchCatalogAccess::DISCOUNTS_APPROVE)) {
            $effectiveApprover = $requester;
        }

        if ($effectiveApprover) {
            $this->access->authorize($effectiveApprover, $branch, BranchCatalogAccess::DISCOUNTS_APPROVE);
        }

        $grossSubtotalCents = array_sum(array_column($lines, 'line_gross_cents'));
        $totalWholesaleCents = array_sum(array_column($lines, 'wholesale_discount_cents'));
        $baseAfterWholesaleCents = max(0, $grossSubtotalCents - $totalWholesaleCents);
        $ttlMinutes = max(5, (int) config('autopartes.pos.discount_approval_ttl_minutes', 30));

        return AutopartesModel::transaction(function () use ($branch, $eval, $requester, $effectiveApprover, $baseAfterWholesaleCents, $ttlMinutes): AutomotiveDiscountApproval {
            return AutomotiveDiscountApproval::create([
                'branch_id' => $branch->getKey(),
                'token' => 'APR-'.strtoupper($branch->code).'-'.Str::upper(Str::random(24)),
                'cart_hash' => $eval['snapshot']['cart_hash'],
                'requested_by' => $requester->getKey(),
                'approved_by' => $effectiveApprover?->getKey(),
                'status' => $effectiveApprover
                    ? AutomotiveDiscountApproval::STATUS_APPROVED
                    : AutomotiveDiscountApproval::STATUS_PENDING,
                'discount_type' => $eval['snapshot']['discount_type'],
                'discount_value' => $eval['snapshot']['discount_value'],
                'discount_percentage_effective' => $eval['snapshot']['effective_percentage'],
                'computed_discount_amount' => $eval['snapshot']['computed_amount'],
                'cart_subtotal_before_manual' => self::fromCents($baseAfterWholesaleCents),
                'reason' => $eval['snapshot']['reason'],
                'approved_at' => $effectiveApprover ? now() : null,
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);
        });
    }

    public function approveRequest(
        AutomotiveBranch $branch,
        AutomotiveDiscountApproval $approval,
        User $approver,
    ): AutomotiveDiscountApproval {
        $this->access->authorize($approver, $branch, BranchCatalogAccess::DISCOUNTS_APPROVE);

        if ((int) $approval->branch_id !== (int) $branch->getKey()) {
            throw ValidationException::withMessages([
                'approval' => 'La solicitud de descuento no pertenece a esta sucursal.',
            ]);
        }

        return AutopartesModel::transaction(function () use ($approval, $approver): AutomotiveDiscountApproval {
            $locked = AutomotiveDiscountApproval::query()->lockForUpdate()->findOrFail($approval->getKey());

            if ($locked->status !== AutomotiveDiscountApproval::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'approval' => "La solicitud de descuento no puede aprobarse en estado [{$locked->status}].",
                ]);
            }

            if ($locked->expires_at && $locked->expires_at->isPast()) {
                $locked->update(['status' => AutomotiveDiscountApproval::STATUS_INVALIDATED]);
                throw ValidationException::withMessages([
                    'approval' => 'La solicitud de descuento ha expirado.',
                ]);
            }

            $ttlMinutes = max(5, (int) config('autopartes.pos.discount_approval_ttl_minutes', 30));

            $locked->update([
                'approved_by' => $approver->getKey(),
                'status' => AutomotiveDiscountApproval::STATUS_APPROVED,
                'approved_at' => now(),
                'expires_at' => now()->addMinutes($ttlMinutes),
            ]);

            $fresh = $locked->fresh();
            $users = AutopartesUserDirectory::mapByIds([$fresh->requested_by, $fresh->approved_by]);
            $fresh->setAttribute('requested_by_name', $users->get((int) $fresh->requested_by)?->name);
            $fresh->setAttribute('approved_by_name', $users->get((int) $fresh->approved_by)?->name);

            return $fresh;
        });
    }
}
