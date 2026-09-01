<?php

namespace App\Http\Middleware;

use App\Support\UserAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_unless($user, 403);

        $allowed = $roles === []
            ? UserAccess::canAccessRoute($user, $request->route()?->getName())
            : collect($roles)->contains(fn (string $role): bool => $user->hasRole($role));

        abort_unless($allowed, 403);

        return $next($request);
    }
}
