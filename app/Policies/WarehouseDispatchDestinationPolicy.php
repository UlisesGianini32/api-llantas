<?php

namespace App\Policies;

use App\Models\InventoryDispatchDestination;
use App\Models\User;

class WarehouseDispatchDestinationPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isBranchRestricted()) {
            return false;
        }

        return $user->isAdmin() || $user->isOperations() || $user->isWarehouse();
    }

    public function view(User $user, InventoryDispatchDestination $destination): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isOperations();
    }

    public function update(User $user, InventoryDispatchDestination $destination): bool
    {
        return $user->isAdmin() || $user->isOperations();
    }

    public function toggle(User $user, InventoryDispatchDestination $destination): bool
    {
        return $user->isAdmin() || $user->isOperations();
    }
}
