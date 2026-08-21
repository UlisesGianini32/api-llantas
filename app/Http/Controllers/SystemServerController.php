<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\System\ServerHealthService;
use Illuminate\Http\JsonResponse;
use Throwable;

final class SystemServerController extends Controller
{
    public function metrics(ServerHealthService $serverHealthService): JsonResponse
    {
        try {
            return response()->json([
                'ok' => true,
                'data' => $serverHealthService->snapshot(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'ok' => false,
                'message' => 'No fue posible obtener las métricas del servidor.',
                'error' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ], 500);
        }
    }
}