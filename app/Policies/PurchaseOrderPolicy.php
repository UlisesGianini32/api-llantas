<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\UserAccess;

class PurchaseOrderPolicy
{
    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return UserAccess::canAccessRoute($user, 'purchasing.orders.show');
    }

    public function viewPdf(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return UserAccess::canAccessRoute($user, 'purchasing.orders.pdf');
    }

    public function sharePdf(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->viewPdf($user, $purchaseOrder);
    }
}
