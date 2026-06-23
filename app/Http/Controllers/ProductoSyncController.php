<?php

namespace App\Http\Controllers;

use App\Jobs\RunProductoSync;
use Illuminate\Routing\Controller; // ← Importante: usa esta clase
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class ProductoSyncController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function sync()
    {
        $user = Auth::user();

        if (!$user) {
            return back()->with('error', 'Usuario no autenticado.');
        }

        $runningKey = "producto_sync:running:user:{$user->id}";

        if (Cache::get($runningKey)) {
            return back()->with('error', 'Ya hay una sincronización corriendo. Espera a que termine.');
        }

        Cache::put($runningKey, true, now()->addHour());
        RunProductoSync::dispatch($user->id);

        return back()->with('success', 'Sincronización iniciada en background. Refresca en unos minutos.');
    }
}