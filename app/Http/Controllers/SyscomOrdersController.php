<?php

namespace App\Http\Controllers;

use App\Models\MeliOrder;
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
}
