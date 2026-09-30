<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Amazon\AmazonTokenService;
use App\Services\Shopify\InventoryShopifyClient;
use App\Services\ShopifyTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ChannelSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Settings/Channels');
    }

    public function testShopify(Request $request, InventoryShopifyClient $shopifyClient): JsonResponse
    {
        try {
            $storeDomain = (string) config('services.shopify.store_domain');
            if (empty(trim($storeDomain))) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Falta configurar SHOPIFY_STORE_DOMAIN en el entorno (.env).',
                ], 422);
            }

            $locations = $shopifyClient->getLocations();
            $locList = $locations['locations'] ?? [];
            $locCount = count($locList);
            $names = collect($locList)->pluck('name')->filter()->take(3)->implode(', ');

            return response()->json([
                'ok' => true,
                'message' => "Conexión exitosa con Shopify ({$storeDomain}). Ubicaciones detectadas: {$locCount}".($names ? " ({$names})" : '').'.',
                'locations' => $locList,
                'store' => $storeDomain,
            ]);
        } catch (Throwable $e) {
            Log::warning('Fallo prueba de conexión con Shopify en settings', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error de conexión con Shopify: '.$e->getMessage(),
            ], 422);
        }
    }

    public function testAmazon(Request $request, AmazonTokenService $tokenService): JsonResponse
    {
        try {
            $clientId = (string) config('services.amazon.lwa_client_id');
            $refreshToken = (string) config('services.amazon.lwa_refresh_token');

            if (empty(trim($clientId)) || empty(trim($refreshToken))) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Faltan credenciales de Amazon SP-API (LWA Client ID o Refresh Token) en el entorno (.env).',
                ], 422);
            }

            $tokenService->forgetToken();
            $tokenService->getAccessToken();
            $sellerId = $tokenService->getSellerId();
            $marketplaceId = $tokenService->getMarketplaceId();

            return response()->json([
                'ok' => true,
                'message' => "Autenticación LWA exitosa con Amazon SP-API. Token de acceso generado correctamente para Marketplace {$marketplaceId}".($sellerId ? " (Seller ID: {$sellerId})" : '').'.',
                'seller_id' => $sellerId,
                'marketplace_id' => $marketplaceId,
            ]);
        } catch (Throwable $e) {
            Log::warning('Fallo prueba de conexión con Amazon SP-API en settings', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error de autenticación con Amazon SP-API: '.$e->getMessage(),
            ], 422);
        }
    }

    public function save(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user && $user->role !== User::ROLE_ADMIN) {
            return back()->with('error', 'Solo administradores pueden modificar credenciales de canales.');
        }

        $channel = $request->input('channel');

        if ($channel === 'shopify') {
            $validated = $request->validate([
                'store_domain' => ['required', 'string', 'max:255'],
                'client_id' => ['nullable', 'string', 'max:500'],
                'client_secret' => ['nullable', 'string', 'max:500'],
                'api_version' => ['nullable', 'string', 'max:30'],
            ]);

            // Normalizar store domain si el usuario puso https:// o slashes
            $storeDomain = preg_replace('#^https?://#', '', trim($validated['store_domain']));
            $storeDomain = rtrim($storeDomain, '/');

            $updates = [
                'SHOPIFY_STORE_DOMAIN' => $storeDomain,
            ];

            if (filled($validated['client_id'] ?? null)) {
                $updates['SHOPIFY_CLIENT_ID'] = trim($validated['client_id']);
            }
            if (filled($validated['client_secret'] ?? null)) {
                $updates['SHOPIFY_CLIENT_SECRET'] = trim($validated['client_secret']);
            }
            if (filled($validated['api_version'] ?? null)) {
                $updates['SHOPIFY_API_VERSION'] = trim($validated['api_version']);
            }

            $written = $this->updateEnvFile($updates);
            app(ShopifyTokenService::class)->forgetToken();

            try {
                Artisan::call('config:clear');
            } catch (Throwable) {
                // Continuar si config:clear falla
            }

            if ($written) {
                return back()->with('success', 'Credenciales de Shopify guardadas en .env correctamente.');
            }

            return back()->with('warning', 'El archivo .env tiene permisos de solo lectura en el servidor. Copia manualmente los valores indicados.');
        }

        if ($channel === 'amazon') {
            $validated = $request->validate([
                'seller_id' => ['nullable', 'string', 'max:100'],
                'marketplace_id' => ['nullable', 'string', 'max:50'],
                'lwa_client_id' => ['nullable', 'string', 'max:500'],
                'lwa_client_secret' => ['nullable', 'string', 'max:500'],
                'lwa_refresh_token' => ['nullable', 'string', 'max:2000'],
            ]);

            $updates = [];
            if (isset($validated['seller_id'])) {
                $updates['AMAZON_SELLER_ID'] = trim($validated['seller_id']);
            }
            if (filled($validated['marketplace_id'] ?? null)) {
                $updates['AMAZON_MARKETPLACE_ID'] = trim($validated['marketplace_id']);
            }
            if (filled($validated['lwa_client_id'] ?? null)) {
                $updates['AMAZON_SP_API_CLIENT_ID'] = trim($validated['lwa_client_id']);
            }
            if (filled($validated['lwa_client_secret'] ?? null)) {
                $updates['AMAZON_SP_API_CLIENT_SECRET'] = trim($validated['lwa_client_secret']);
            }
            if (filled($validated['lwa_refresh_token'] ?? null)) {
                $updates['AMAZON_SP_API_REFRESH_TOKEN'] = trim($validated['lwa_refresh_token']);
            }

            $written = $this->updateEnvFile($updates);
            app(AmazonTokenService::class)->forgetToken();

            try {
                Artisan::call('config:clear');
            } catch (Throwable) {
                // Continuar si config:clear falla
            }

            if ($written) {
                return back()->with('success', 'Credenciales de Amazon SP-API guardadas en .env correctamente.');
            }

            return back()->with('warning', 'El archivo .env tiene permisos de solo lectura en el servidor. Copia manualmente los valores indicados.');
        }

        return back()->with('error', 'Canal no reconocido.');
    }

    public function redirectToShopify(Request $request): RedirectResponse
    {
        $shop = trim((string) $request->input('shop', config('services.shopify.store_domain')));
        $shop = preg_replace('#^https?://#', '', $shop);
        $shop = rtrim($shop, '/');

        if (empty($shop)) {
            return redirect()->route('profile.edit')
                ->with('error', 'Debes ingresar el dominio de la tienda de Shopify (ej. tu-tienda.myshopify.com).');
        }

        $clientId = trim((string) config('services.shopify.client_id'));
        if (empty($clientId)) {
            return redirect()->route('profile.edit')
                ->with('error', 'Falta SHOPIFY_CLIENT_ID para iniciar el flujo de vinculación OAuth de Shopify.');
        }

        $state = bin2hex(random_bytes(24));
        Cache::put("shopify_oauth_state_{$state}", ['shop' => $shop, 'user_id' => $request->user()?->id], now()->addMinutes(15));

        $redirectUri = url('/auth/shopify/callback');
        $scopes = 'read_products,write_products,read_inventory,write_inventory,read_orders,write_orders';

        $authorizeUrl = "https://{$shop}/admin/oauth/authorize?".http_build_query([
            'client_id' => $clientId,
            'scope' => $scopes,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);

        return redirect()->away($authorizeUrl);
    }

    public function handleShopifyCallback(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state');
        $code = (string) $request->query('code');
        $shop = (string) $request->query('shop');

        $sessionData = Cache::pull("shopify_oauth_state_{$state}");
        if (! $sessionData || empty($code) || empty($shop)) {
            return redirect()->route('profile.edit')
                ->with('error', 'Estado de autorización de Shopify inválido o sesión expirada.');
        }

        $clientId = (string) config('services.shopify.client_id');
        $clientSecret = (string) config('services.shopify.client_secret');

        try {
            $response = Http::asForm()
                ->timeout(20)
                ->post("https://{$shop}/admin/oauth/access_token", [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'code' => $code,
                ]);

            if ($response->successful() && filled($response->json('access_token'))) {
                $accessToken = $response->json('access_token');
                $this->updateEnvFile([
                    'SHOPIFY_STORE_DOMAIN' => $shop,
                    'SHOPIFY_CLIENT_ID' => $accessToken,
                ]);
                app(ShopifyTokenService::class)->forgetToken();
                try {
                    Artisan::call('config:clear');
                } catch (Throwable) {
                }

                return redirect()->route('profile.edit')
                    ->with('success', "¡Tienda Shopify ({$shop}) vinculada exitosamente!");
            }

            return redirect()->route('profile.edit')
                ->with('error', 'Shopify denegó el intercambio de token: '.$response->body());
        } catch (Throwable $e) {
            return redirect()->route('profile.edit')
                ->with('error', 'Error conectando con Shopify: '.$e->getMessage());
        }
    }

    protected function updateEnvFile(array $values): bool
    {
        $envPath = base_path('.env');
        if (! file_exists($envPath) || ! is_writable($envPath)) {
            // Actualizar variables en memoria para la petición actual
            foreach ($values as $key => $value) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }

            return false;
        }

        $content = file_get_contents($envPath);
        if ($content === false) {
            return false;
        }

        foreach ($values as $key => $value) {
            $val = trim((string) $value);
            $quote = str_contains($val, ' ') || str_contains($val, '#') || str_contains($val, '"') || str_contains($val, '$');
            $formatted = $quote ? '"'.addcslashes($val, '"').'"' : $val;

            putenv("{$key}={$val}");
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;

            $pattern = "/^{$key}=.*$/m";
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, "{$key}={$formatted}", $content);
            } else {
                $content = rtrim($content)."\n{$key}={$formatted}\n";
            }
        }

        return file_put_contents($envPath, $content) !== false;
    }
}
