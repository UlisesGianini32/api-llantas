<?php

namespace App\Http\Controllers;

use App\Services\System\ServerHealthService;
use Inertia\Inertia;
use Inertia\Response;

class SystemHealthController extends Controller
{
    public function index(ServerHealthService $health): Response
    {
        $snapshot = $health->snapshot();

        $checks = collect([
            'cpu' => 'Procesador',
            'memory' => 'Memoria RAM',
            'swap' => 'Memoria swap',
            'disk' => 'Almacenamiento',
            'load' => 'Carga del servidor',
            'system' => 'Sistema operativo',
            'runtime' => 'Entorno de ejecución',
            'database' => 'Base de datos',
            'workers' => 'Workers de colas',
            'scheduler' => 'Programador de tareas',
        ])->map(function (string $name, string $key) use ($snapshot) {
            $data = $snapshot[$key] ?? [];

            $originalStatus = strtolower(
                trim((string) ($data['status'] ?? 'unknown'))
            );

            $status = match ($originalStatus) {
                'healthy', 'ok', 'success' => 'ok',
                'warning', 'degraded' => 'warning',
                'critical', 'error', 'failed', 'unhealthy' => 'error',
                default => 'info',
            };

            $message = $data['message']
                ?? $this->defaultMessageForCheck($key, $status);

            $details = collect($data)
                ->except(['status', 'message'])
                ->all();

            return [
                'key' => $key,
                'name' => $name,
                'status' => $status,
                'message' => $message,
                'details' => $details,
            ];
        })->values()->all();

        $summary = [
            'ok' => collect($checks)->where('status', 'ok')->count(),
            'warning' => collect($checks)->where('status', 'warning')->count(),
            'error' => collect($checks)->where('status', 'error')->count(),
            'info' => collect($checks)->where('status', 'info')->count(),
        ];

        return Inertia::render('SystemHealth/Index', [
            'generatedAt' => $snapshot['generated_at'] ?? now()->toIso8601String(),
            'summary' => $summary,
            'checks' => $checks,
        ]);
    }

    /**
     * Mensaje alternativo para secciones que no incluyen message.
     */
    protected function defaultMessageForCheck(
        string $key,
        string $status
    ): string {
        $messages = [
            'system' => 'Información general del servidor.',
            'runtime' => 'Información del entorno de Laravel y PHP.',
        ];

        if (isset($messages[$key])) {
            return $messages[$key];
        }

        return match ($status) {
            'ok' => 'El componente funciona correctamente.',
            'warning' => 'El componente requiere revisión.',
            'error' => 'Se detectó un problema en este componente.',
            default => 'Información disponible del componente.',
        };
    }
}
