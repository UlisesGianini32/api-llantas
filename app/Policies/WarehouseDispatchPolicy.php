<?php

namespace App\Policies;

use App\Models\InventoryDispatch;
use App\Models\User;

class WarehouseDispatchPolicy
{
    private function canUseModule(User $user): bool
    {
        if ($user->isBranchRestricted()) {
            return false;
        }

        return $user->isAdmin() || $user->isOperations() || $user->isWarehouse();
    }

    public function viewAny(User $user): bool
    {
        return $this->canUseModule($user);
    }

    public function view(User $user, InventoryDispatch $dispatch): bool
    {
        return $this->canUseModule($user);
    }

    public function viewPdf(User $user, InventoryDispatch $dispatch): bool
    {
        return $this->canUseModule($user);
    }

    public function create(User $user): bool
    {
        return $this->canUseModule($user);
    }

    public function update(User $user, InventoryDispatch $dispatch): bool
    {
        if (! $dispatch->isDraft()) {
            return false;
        }

        if ($user->isAdmin() || $user->isOperations()) {
            return true;
        }

        return $user->isWarehouse() && (int) $dispatch->created_by === (int) $user->getKey();
    }

    public function cancelDraft(User $user, InventoryDispatch $dispatch): bool
    {
        return $this->update($user, $dispatch);
    }

    public function confirm(User $user, InventoryDispatch $dispatch): bool
    {
        return $this->canUseModule($user);
    }

    public function createRetroactive(User $user): bool
    {
        return $user->isAdmin() || $user->isOperations();
    }

    public function cancelConfirmedWithoutLedger(User $user, InventoryDispatch $dispatch): bool
    {
        return ($user->isAdmin() || $user->isOperations()) && $dispatch->isConfirmed();
    }

    public function viewCosts(User $user): bool
    {
        return $user->isAdmin();
    }

    public function viewStylistPrices(User $user): bool
    {
        return $user->isAdmin() || $user->isOperations();
    }

    public function viewCommercialSubtotals(User $user): bool
    {
        return $user->isAdmin() || $user->isOperations();
    }

    public function showPricesInPdf(User $user, InventoryDispatch $dispatch): bool
    {
        return $this->viewStylistPrices($user)
            && $dispatch->operation_type === InventoryDispatch::OP_SALON_SALE;
    }

    public function viewProvisional(User $user): bool
    {
        return $this->canUseModule($user);
    }

    public function linkProvisional(User $user): bool
    {
        return $user->isAdmin() || $user->isOperations();
    }

    public function mergeProvisional(User $user): bool
    {
        return $user->isAdmin() || $user->isOperations();
    }

    public function exportSummary(User $user): bool
    {
        return $user->isAdmin() || $user->isOperations();
    }

    public function authorizeMeliSync(User $user): bool
    {
        // Strictly disabled in Phase 1 (fail-closed).
        return false;
    }
}
