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

    public function viewPhoto(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->view($user, $purchaseOrder)
            && UserAccess::canAccessRoute($user, 'purchasing.orders.item-photo');
    }

    public function viewPdf(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return UserAccess::canAccessRoute($user, 'purchasing.orders.pdf');
    }

    public function sharePdf(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->viewPdf($user, $purchaseOrder);
    }

    public function managePhotos(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return UserAccess::canAccessRoute($user, 'purchasing.orders.approve-photo');
    }
}
