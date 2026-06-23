<?php

namespace App\Http\Controllers;

use App\Models\MeliAccount;
use App\Models\User;
use App\Services\MeliOAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * PKCE S256 (RFC 7636). Obligatorio en authorization + token si MELI_USE_PKCE=true en Dev Center.
     *
     * @return array{verifier:string, challenge:string}
     */
    protected function newPkcePair(): array
    {
        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(
            base64_encode(hash('sha256', $verifier, true)),
            '+/',
            '-_'
        ), '=');

        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    /**
     * Paso 1 (doc ML): redirige a /authorization con response_type=code, client_id, redirect_uri, state
     * y opcionalmente scope y PKCE si aplica.
     *
     * @see https://developers.mercadolibre.com.mx/es_ar/autenticacion-y-autorizacion
     */
    public function redirectToMeli(Request $request)
    {
        if (!Auth::check()) {
            return redirect()->route('login')->with('error', 'Debes iniciar sesión primero.');
        }

        $reauthAccountId = $request->integer('account') ?: null;
        if ($reauthAccountId) {
            $acc = MeliAccount::query()
                ->where('user_id', Auth::id())
                ->whereKey($reauthAccountId)
                ->first();
            if (! $acc) {
                return redirect()
                    ->route('profile.edit')
                    ->with('error', 'No se encontro esa cuenta de Mercado Libre.');
            }
        }

        $additional = $request->boolean('additional');

        $clientId = (string) config('services.meli.client_id');
        $redirectUri = (string) config('services.meli.redirect_uri');
        $authorizationUrl = rtrim((string) config('services.meli.authorization_url'), '/');
        $scope = trim((string) config('services.meli.oauth_scope'));
        $usePkce = (bool) config('services.meli.use_pkce');

        if ($clientId === '' || $redirectUri === '') {
            return redirect()
                ->route('profile.edit')
                ->with(
                    'error',
                    'Falta configurar Mercado Libre en el servidor: define MELI_CLIENT_ID (o MELI_APP_ID), MELI_CLIENT_SECRET y APP_URL correcto; opcional MELI_REDIRECT_URI. Luego ejecuta php artisan config:clear en producción.'
                );
        }

        if ($authorizationUrl === '') {
            $authorizationUrl = 'https://auth.mercadolibre.com.mx/authorization';
        }

        $state = bin2hex(random_bytes(24));

        if ($reauthAccountId) {
            $intent = 'reauth';
        } elseif ($additional) {
            $intent = 'additional';
        } else {
            $intent = 'default';
        }

        $payload = [
            'state' => $state,
            'user_id' => Auth::id(),
            'pkce_verifier' => null,
            'intent' => $intent,
            'meli_account_id' => $reauthAccountId,
        ];

        $query = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ];

        if ($scope !== '') {
            $query['scope'] = $scope;
        }

        if ($usePkce) {
            $pkce = $this->newPkcePair();
            $payload['pkce_verifier'] = $pkce['verifier'];
            $query['code_challenge'] = $pkce['challenge'];
            $query['code_challenge_method'] = 'S256';
        }

        session(['meli_oauth' => $payload]);
        Cache::put("meli_oauth_state:{$state}", $payload, now()->addMinutes(15));

        $url = $authorizationUrl . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return redirect($url);
    }

    public function handleMeliCallback(Request $request, MeliOAuthService $meliOAuth)
    {
        try {
            if ($request->filled('error')) {
                $error = (string) $request->query('error');
                $description = (string) $request->query('error_description', 'Sin descripcion');
                throw new \RuntimeException("Mercado Libre devolvio error: {$error} ({$description})");
            }

            $code = (string) $request->query('code', '');
            $state = (string) $request->query('state', '');

            if ($code === '') {
                throw new \RuntimeException('No se recibio el codigo de autorizacion.');
            }

            if ($state === '') {
                throw new \RuntimeException('No se recibio el state del flujo OAuth.');
            }

            $cachedOAuth = Cache::get("meli_oauth_state:{$state}");
            $sessionOAuth = session('meli_oauth');
            $oauth = is_array($cachedOAuth) ? $cachedOAuth : (is_array($sessionOAuth) ? $sessionOAuth : null);

            if (!is_array($oauth) || empty($oauth['state']) || !hash_equals((string) $oauth['state'], $state)) {
                throw new \RuntimeException('State invalido o expirado. Intenta vincular de nuevo.');
            }

            $userId = (int) ($oauth['user_id'] ?? 0);
            $user = User::query()->find($userId);
            if (!$user) {
                throw new \RuntimeException('No se encontro el usuario para completar la vinculacion.');
            }

            $clientId = (string) config('services.meli.client_id');
            $clientSecret = (string) config('services.meli.client_secret');
            $redirectUri = (string) config('services.meli.redirect_uri');
            $usePkce = (bool) config('services.meli.use_pkce');
            $pkceVerifier = is_array($oauth) ? ($oauth['pkce_verifier'] ?? null) : null;

            if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
                throw new \RuntimeException('Configuracion MELI incompleta (client_id, client_secret o redirect_uri).');
            }

            if ($usePkce && (!is_string($pkceVerifier) || $pkceVerifier === '')) {
                throw new \RuntimeException('PKCE activado (MELI_USE_PKCE) pero falta code_verifier en sesion. Vincula de nuevo.');
            }

            Log::info('Iniciando intercambio de token con Mercado Libre', [
                'user_id' => $user->id,
                'redirect_uri' => $redirectUri,
                'pkce' => $usePkce,
            ]);

            $verifier = ($usePkce && is_string($pkceVerifier) && $pkceVerifier !== '') ? $pkceVerifier : null;
            $tokenData = $meliOAuth->exchangeAuthorizationCode(
                $clientId,
                $clientSecret,
                $code,
                $redirectUri,
                $verifier,
            );

            $mlUid = (string) ($tokenData['user_id'] ?? '');
            if ($mlUid === '') {
                throw new \RuntimeException('Mercado Libre no devolvio user_id.');
            }

            $intent = (string) ($oauth['intent'] ?? 'default');
            $reauthAccountId = isset($oauth['meli_account_id']) ? (int) $oauth['meli_account_id'] : null;

            $expiresAt = now()->addSeconds((int) ($tokenData['expires_in'] ?? 0));

            if ($intent === 'reauth' && $reauthAccountId) {
                $acc = MeliAccount::query()
                    ->where('user_id', $user->id)
                    ->whereKey($reauthAccountId)
                    ->first();
                if (! $acc || (string) $acc->meli_user_id !== $mlUid) {
                    throw new \RuntimeException(
                        'La cuenta autorizada de Mercado Libre no coincide con la cuenta seleccionada para reautorizar.'
                    );
                }
                $acc->update([
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'] ?? $acc->refresh_token,
                    'expires_at' => $expiresAt,
                ]);
            } else {
                $existing = MeliAccount::query()
                    ->where('user_id', $user->id)
                    ->where('meli_user_id', $mlUid)
                    ->first();

                if ($existing) {
                    $existing->update([
                        'access_token' => $tokenData['access_token'],
                        'refresh_token' => $tokenData['refresh_token'] ?? $existing->refresh_token,
                        'expires_at' => $expiresAt,
                    ]);
                } else {
                    $count = MeliAccount::query()->where('user_id', $user->id)->count();
                    $isDefault = $count === 0;
                    if ($intent === 'additional' && $count > 0) {
                        $isDefault = false;
                    }

                    MeliAccount::query()->create([
                        'user_id' => $user->id,
                        'meli_user_id' => $mlUid,
                        'nickname' => null,
                        'official_store_id' => $count === 0 ? $user->official_store_id : null,
                        'access_token' => $tokenData['access_token'],
                        'refresh_token' => $tokenData['refresh_token'] ?? null,
                        'expires_at' => $expiresAt,
                        'is_default' => $isDefault,
                    ]);
                }
            }

            $user->syncMeliColumnsFromDefaultAccount();

            Cache::forget("meli_oauth_state:{$state}");
            session()->forget('meli_oauth');

            Auth::login($user);

            $msg = match ($intent) {
                'additional' => 'Nueva cuenta de Mercado Libre vinculada.',
                'reauth' => 'Token de Mercado Libre actualizado correctamente.',
                default => 'Cuenta de Mercado Libre vinculada correctamente.',
            };

            return redirect()
                ->route('profile.edit')
                ->with('success', $msg);
        } catch (\Throwable $e) {
            Log::error('ERROR en callback Mercado Libre: ' . $e->getMessage(), [
                'request' => $request->only(['error', 'error_description', 'state']),
            ]);

            return redirect()
                ->route('profile.edit')
                ->with('error', 'Error al vincular: ' . $e->getMessage());
        }
    }

    public function unlinkMeli(MeliAccount $meliAccount)
    {
        if (! Auth::check()) {
            return redirect()->route('login')->with('error', 'Debes iniciar sesión primero.');
        }

        abort_unless((int) $meliAccount->user_id === (int) Auth::id(), 403);

        $wasDefault = $meliAccount->is_default;
        $meliAccount->delete();

        if ($wasDefault || ! MeliAccount::query()->where('user_id', Auth::id())->where('is_default', true)->exists()) {
            $next = MeliAccount::query()->where('user_id', Auth::id())->orderBy('id')->first();
            if ($next) {
                $next->update(['is_default' => true]);
            }
        }

        Auth::user()->syncMeliColumnsFromDefaultAccount();

        return redirect()
            ->route('profile.edit')
            ->with('success', 'Cuenta de Mercado Libre desvinculada.');
    }
}