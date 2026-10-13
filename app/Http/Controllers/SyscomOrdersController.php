<?php

namespace App\Http\Controllers;

use App\Models\MeliOrder;
use App\Models\User;
use App\Services\SyscomOrderFromMeliService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SyscomOrdersController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->get('search', ''));
        $statusFilter = trim((string) $request->get('status', 'all'));
        $dateFilter = trim((string) $request->get('date', 'all'));

        $today = now()->toDateString();

        $syscomSyncOkToday = MeliOrder::query()
            ->whereDate('syscom_order_synced_at', $today)
            ->count();

        $syscomSyncSkipToday = MeliOrder::query()
            ->whereDate('updated_at', $today)
            ->where('syscom_order_error', 'like', 'SKIP_NO_SYSCOM_ITEMS:%')
            ->count();

        $syscomSyncErrToday = MeliOrder::query()
            ->whereDate('updated_at', $today)
            ->whereNotNull('syscom_order_error')
            ->where('syscom_order_error', 'not like', 'SKIP_NO_SYSCOM_ITEMS:%')
            ->count();

        $totalFolios = MeliOrder::query()
            ->whereNotNull('syscom_order_folio')
            ->where('syscom_order_folio', '!=', '')
            ->count();

        $query = MeliOrder::query()
            ->where(function ($q) {
                $q->whereNotNull('syscom_order_folio')
                    ->where('syscom_order_folio', '!=', '')
                    ->orWhereNotNull('syscom_order_synced_at')
                    ->orWhereNotNull('syscom_order_error');
            });

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('order_id', 'like', "%{$search}%")
                    ->orWhere('syscom_order_folio', 'like', "%{$search}%")
                    ->orWhere('syscom_order_error', 'like', "%{$search}%");
            });
        }

        if ($dateFilter === 'today') {
            $query->where(function ($q) use ($today) {
                $q->whereDate('syscom_order_synced_at', $today)
                    ->orWhereDate('updated_at', $today);
            });
        }

        if ($statusFilter === 'ok') {
            $query->whereNotNull('syscom_order_folio')
                ->where('syscom_order_folio', '!=', '')
                ->whereNull('syscom_order_cancelled_at');
        } elseif ($statusFilter === 'error') {
            $query->whereNotNull('syscom_order_error')
                ->where('syscom_order_error', 'not like', 'SKIP_NO_SYSCOM_ITEMS:%');
        } elseif ($statusFilter === 'skip') {
            $query->where('syscom_order_error', 'like', 'SKIP_NO_SYSCOM_ITEMS:%');
        } elseif ($statusFilter === 'cancelled') {
            $query->whereNotNull('syscom_order_cancelled_at');
        }

        $orders = $query->orderByDesc('syscom_order_synced_at')
            ->orderByDesc('updated_at')
            ->paginate(30)
            ->withQueryString()
            ->through(function (MeliOrder $o) {
                $status = mb_strtolower(trim((string) ($o->status ?? '')));
                $mlCancelled = in_array($status, ['cancelled', 'canceled', 'invalid', 'expired'], true);
                $syscomCancelled = $o->syscom_order_cancelled_at !== null;

                // Auto-reparación inteligente: si la orden no tiene folio pero tiene respuesta cruda guardada, reintentar extraerlo
                if (empty($o->syscom_order_folio) && ! empty($o->syscom_order_raw)) {
                    $extracted = SyscomOrderFromMeliService::extractFolioFromSyscomResponse($o->syscom_order_raw);
                    if ($extracted) {
                        $o->syscom_order_folio = $extracted;
                        if ($o->syscom_order_error && str_contains($o->syscom_order_error, 'no devolvió folio')) {
                            $o->syscom_order_error = null;
                        }
                        $o->save();
                    }
                }

                return [
                    'id' => $o->id,
                    'order_id' => (string) $o->order_id,
                    'referencia_ml' => 'ML-' . $o->order_id,
                    'status' => $o->status,
                    'syscom_order_folio' => (string) ($o->syscom_order_folio ?? ''),
                    'syscom_order_synced_at' => $o->syscom_order_synced_at?->format('d/m/Y H:i'),
                    'syscom_order_error' => (string) ($o->syscom_order_error ?? ''),
                    'ml_cancelled' => $mlCancelled,
                    'syscom_cancelled' => $syscomCancelled,
                    'syscom_order_cancelled_at' => $o->syscom_order_cancelled_at?->format('d/m/Y H:i'),
                    'syscom_order_cancel_error' => $syscomCancelled
                        ? null
                        : ($mlCancelled ? (string) ($o->syscom_order_cancel_error ?? '') : null),
                    'has_raw' => ! empty($o->syscom_order_raw),
                    'syscom_order_raw' => $o->syscom_order_raw,
                ];
            });

        return Inertia::render('Syscom/Orders', [
            'orders' => $orders,
            'filters' => [
                'search' => $search,
                'status' => $statusFilter,
                'date' => $dateFilter,
            ],
            'stats' => [
                'syncOkToday' => $syscomSyncOkToday,
                'syncSkipToday' => $syscomSyncSkipToday,
                'syncErrToday' => $syscomSyncErrToday,
                'totalFolios' => $totalFolios,
            ],
        ]);
    }

    /**
     * Permite asignar o editar manualmente el folio SYSCOM de un pedido.
     */
    public function updateFolio(Request $request, MeliOrder $order): JsonResponse
    {
        $validated = $request->validate([
            'syscom_order_folio' => ['required', 'string', 'max:100'],
        ]);

        $newFolio = trim($validated['syscom_order_folio']);
        $order->syscom_order_folio = $newFolio;

        if ($order->syscom_order_error && str_contains($order->syscom_order_error, 'no devolvió folio')) {
            $order->syscom_order_error = null;
        }

        $order->save();

        return response()->json([
            'success' => true,
            'message' => "Folio SYSCOM guardado exitosamente: {$newFolio}",
            'syscom_order_folio' => $newFolio,
        ]);
    }

    /**
     * Re-analiza la respuesta cruda de SYSCOM para extraer el folio si no se capturó inicialmente.
     */
    public function extractFolio(MeliOrder $order): JsonResponse
    {
        if (empty($order->syscom_order_raw)) {
            return response()->json([
                'success' => false,
                'error' => 'No hay respuesta cruda guardada de SYSCOM para este pedido.',
            ], 422);
        }

        $extracted = SyscomOrderFromMeliService::extractFolioFromSyscomResponse($order->syscom_order_raw);

        if ($extracted) {
            $order->syscom_order_folio = $extracted;
            if ($order->syscom_order_error && str_contains($order->syscom_order_error, 'no devolvió folio')) {
                $order->syscom_order_error = null;
            }
            $order->save();

            return response()->json([
                'success' => true,
                'message' => "¡Folio encontrado y asignado exitosamente!: {$extracted}",
                'syscom_order_folio' => $extracted,
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => 'No se detectó ningún folio en el JSON de SYSCOM. Puedes asignarlo manualmente o reintentar.',
            'raw' => $order->syscom_order_raw,
        ], 404);
    }

    /**
     * Reintenta la sincronización con SYSCOM para un pedido que no obtuvo folio o falló.
     */
    public function retrySync(Request $request, MeliOrder $order, SyscomOrderFromMeliService $service): JsonResponse
    {
        $user = $request->user() ?? User::query()->whereNotNull('access_token')->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'error' => 'No se encontró un usuario con token de acceso para ejecutar la sincronización.',
            ], 401);
        }

        // Restablecer campos para permitir reintento
        $order->syscom_order_synced_at = null;
        $order->syscom_order_folio = null;
        $order->syscom_order_error = null;
        $order->save();

        $service->syncIfEligible($user, $order);
        $order->refresh();

        if (! empty($order->syscom_order_folio)) {
            return response()->json([
                'success' => true,
                'message' => "Pedido generado en SYSCOM con folio: {$order->syscom_order_folio}",
                'syscom_order_folio' => $order->syscom_order_folio,
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $order->syscom_order_error ?: 'No se generó folio en SYSCOM.',
            'raw' => $order->syscom_order_raw,
        ]);
    }
}

