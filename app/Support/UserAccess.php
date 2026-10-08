<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

final class UserAccess
{
    /** @var list<string> */
    private const COMMON_ACCOUNT_PATTERNS = [
        'dashboard',
        'dashboard.*',
        'profile.edit',
        'profile.update',
        'user-password.edit',
        'user-password.update',
        'appearance.edit',
        'two-factor.*',
    ];

    /** @var list<string> */
    private const POS_ROUTE_PATTERNS = [
        ...self::COMMON_ACCOUNT_PATTERNS,
        'pos.*',
        'qz.*',
    ];

    /** @var list<string> */
    private const WAREHOUSE_ROUTE_PATTERNS = [
        ...self::COMMON_ACCOUNT_PATTERNS,
        'inventory.products.*',
        'inventory.locations.*',
        'inventory.movements.*',
        'inventory.reservations.*',
        'inventory.kits.*',
        'purchasing.orders.index',
        'purchasing.orders.show',
        'purchasing.orders.receive',
    ];

    /** @var list<string> */
    private const OPERATIONS_ROUTE_PATTERNS = [
        ...self::COMMON_ACCOUNT_PATTERNS,
        'pos.*',
        'customers.*',
        'restock.*',
        'purchasing.*',
        'inventory.*',
        'meli.sync-manual',
        'meli.questions.*',
        'meli.messaging.*',
        'meli.claims.*',
        'meli.publications.*',
        'meli.full.*',
        'meli.labels.*',
        'ams.*',
        'qz.*',
        'settings.index',
    ];

    public static function canAccessRoute(User $user, ?string $routeName): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (blank($routeName)) {
            return false;
        }

        if ($user->isOperations()) {
            return Str::is(self::OPERATIONS_ROUTE_PATTERNS, $routeName);
        }

        if ($user->isPos()) {
            return Str::is(self::POS_ROUTE_PATTERNS, $routeName);
        }

        if ($user->isWarehouse()) {
            return Str::is(self::WAREHOUSE_ROUTE_PATTERNS, $routeName);
        }

        return false;
    }
}
