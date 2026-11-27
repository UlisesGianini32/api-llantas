<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireWarehouseDispatchesEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('inventory.warehouse_dispatches.enabled', false)) {
            abort(404);
        }

        if ((bool) config('inventory.warehouse_dispatches.read_only', false) && ! $request->isMethodSafe()) {
            abort(503, 'El módulo de Salidas de Almacén está temporalmente en modo de solo consulta.');
        }

        return $next($request);
    }
}
