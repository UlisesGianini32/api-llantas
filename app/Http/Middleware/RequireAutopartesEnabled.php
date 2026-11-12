<?php

namespace App\Http\Middleware;

use App\Support\AutopartesConnectionGuard;
use Closure;
use Illuminate\Http\Request;

class RequireAutopartesEnabled
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('autopartes.enabled'), 404);
        abort_unless(
            config('autopartes.controllers_enabled', false),
            503,
            'El acceso interactivo de Autopartes permanece bloqueado durante la transición arquitectónica R2–R3.'
        );

        AutopartesConnectionGuard::assertDedicatedConnectionSafe();

        return $next($request);
    }
}
