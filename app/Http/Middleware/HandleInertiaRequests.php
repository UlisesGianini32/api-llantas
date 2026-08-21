<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user
                    ? [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'meli_id' => $user->meli_id,
                        // No mandar access_token al JS ($hidden). Bandera explícita para la UI:
                        'meli_linked' => filled($user->meli_id),
                        'meli_accounts' => $user->meliAccounts()
                            ->orderByDesc('is_default')
                            ->orderBy('id')
                            ->get(['id', 'meli_user_id', 'nickname', 'is_default'])
                            ->values()
                            ->all(),
                    ]
                    : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'ok' => fn () => $request->session()->get('ok'),
                'err' => fn () => $request->session()->get('err'),
            ],
            'meli_questions_pending' => fn () => $user && Schema::hasTable('meli_questions')
                ? DB::table('meli_questions')
                    ->where('user_id', $user->id)
                    ->where('status', 'UNANSWERED')
                    ->count()
                : 0,
        ];
    }
}
