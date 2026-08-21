<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class SystemActionController extends Controller
{
    public function run(string $action): RedirectResponse
    {
        $allowed = [
            'cache-clear' => ['command' => 'cache:clear', 'message' => 'Caché eliminada correctamente.'],
            'config-clear' => ['command' => 'config:clear', 'message' => 'Caché de configuración eliminada.'],
            'route-clear' => ['command' => 'route:clear', 'message' => 'Caché de rutas eliminada.'],
            'view-clear' => ['command' => 'view:clear', 'message' => 'Caché de vistas eliminada.'],
            'queue-restart' => ['command' => 'queue:restart', 'message' => 'Se solicitó el reinicio de workers.'],
            'schedule-run' => ['command' => 'schedule:run', 'message' => 'Scheduler ejecutado manualmente.'],
        ];

        if (! array_key_exists($action, $allowed)) {
            abort(404);
        }

        try {
            Artisan::call($allowed[$action]['command']);

            return back()->with('success', $allowed[$action]['message']);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'La acción no pudo completarse.');
        }
    }
}
