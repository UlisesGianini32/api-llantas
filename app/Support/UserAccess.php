<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

final class UserAccess
{
    /** @var list<string> */
    private const OPERATIONS_ROUTE_PATTERNS = [
        'dashboard',
        'dashboard.*',
        'meli.sync-manual',
        'meli.questions.*',
        'meli.messaging.*',
        'meli.claims.*',
        'meli.publications.*',
        'meli.full.*',
        'ams.*',
        'qz.*',
        'settings.index',
        'profile.edit',
        'profile.update',
        'user-password.edit',
        'user-password.update',
        'appearance.edit',
        'two-factor.*',
    ];

    public static function canAccessRoute(User $user, ?string $routeName): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isOperations() || blank($routeName)) {
            return false;
        }

        return Str::is(self::OPERATIONS_ROUTE_PATTERNS, $routeName);
    }
}
