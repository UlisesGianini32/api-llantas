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
                        'role' => $user->role,
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
                'warning' => fn () => $request->session()->get('warning'),
                'ok' => fn () => $request->session()->get('ok'),
                'err' => fn () => $request->session()->get('err'),
            ],
            'channels' => fn () => [
                'shopify' => [
                    'store_domain' => (string) config('services.shopify.store_domain'),
                    'api_version' => (string) config('services.shopify.api_version', '2025-01'),
                    'is_configured' => filled(config('services.shopify.store_domain')) && (filled(config('services.shopify.client_id')) || filled(config('services.shopify.client_secret'))),
                    'has_client_id' => filled(config('services.shopify.client_id')),
                    'has_client_secret' => filled(config('services.shopify.client_secret')),
                    'masked_client_id' => filled(config('services.shopify.client_id'))
                        ? \Illuminate\Support\Str::mask((string) config('services.shopify.client_id'), '*', 4, -4)
                        : null,
                ],
                'amazon' => [
                    'seller_id' => (string) config('services.amazon.seller_id'),
                    'marketplace_id' => (string) config('services.amazon.marketplace_id', 'A1AM78C64UM0Y8'),
                    'endpoint' => (string) config('services.amazon.endpoint', 'https://sellingpartnerapi-na.amazon.com'),
                    'is_configured' => filled(config('services.amazon.lwa_client_id'))
                        && filled(config('services.amazon.lwa_client_secret'))
                        && filled(config('services.amazon.lwa_refresh_token')),
                    'has_client_id' => filled(config('services.amazon.lwa_client_id')),
                    'has_client_secret' => filled(config('services.amazon.lwa_client_secret')),
                    'has_refresh_token' => filled(config('services.amazon.lwa_refresh_token')),
                    'masked_client_id' => filled(config('services.amazon.lwa_client_id'))
                        ? \Illuminate\Support\Str::mask((string) config('services.amazon.lwa_client_id'), '*', 4, -4)
                        : null,
                ],
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
