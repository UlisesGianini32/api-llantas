<?php

namespace App\Http\Controllers;

use App\Services\NotificationCenterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationCenterController extends Controller
{
    public function __construct(
        protected NotificationCenterService $notificationService
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = [
            'type' => $request->query('type'),
            'category' => $request->query('category'),
            'status' => $request->query('status', 'all'),
            'search' => $request->query('search', ''),
        ];

        $notifications = $this->notificationService->getNotifications($user, $filters);
        $summary = $this->notificationService->getSummary($user, true);

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'summary' => $summary,
            'filters' => $filters,
        ]);
    }

    public function feed(Request $request): JsonResponse
    {
        $user = $request->user();
        $summary = $this->notificationService->getSummary($user, true);

        return response()->json([
            'ok' => true,
            'summary' => $summary,
        ]);
    }

    public function markAsRead(Request $request, string $key): JsonResponse|RedirectResponse
    {
        $this->notificationService->markAsRead($request->user(), $key);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Notificación marcada como leída.');
    }

    public function markAllAsRead(Request $request): JsonResponse|RedirectResponse
    {
        $this->notificationService->markAllAsRead($request->user());

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Todas las notificaciones han sido marcadas como leídas.');
    }

    public function dismiss(Request $request, string $key): JsonResponse|RedirectResponse
    {
        $this->notificationService->dismiss($request->user(), $key);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', 'Notificación descartada.');
    }
}
