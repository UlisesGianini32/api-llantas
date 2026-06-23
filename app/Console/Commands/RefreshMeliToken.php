<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\MeliAccount;
use App\Services\MeliOAuthService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class RefreshMeliToken extends Command
{
    protected $signature = 'meli:refresh-token';
    protected $description = 'Refresca los tokens de acceso de Mercado Libre';

    public function handle(MeliOAuthService $meliOAuth)
    {
        $accounts = MeliAccount::query()
            ->whereNotNull('refresh_token')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->addMinutes(10))
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('No hay tokens que necesiten ser renovados.');
            Log::info('MELI TOKEN: no hay tokens por renovar');

            return 0;
        }

        $clientId = (string) config('services.meli.client_id', '');
        $clientSecret = (string) config('services.meli.client_secret', '');

        if ($clientId === '' || $clientSecret === '') {
            $this->error('MELI_CLIENT_ID / MELI_APP_ID o MELI_CLIENT_SECRET no están configurados.');
            Log::error('MELI TOKEN: faltan credenciales');

            return 1;
        }

        $this->info("Cuentas MeLi a renovar: {$accounts->count()}");
        Log::info("MELI TOKEN: cuentas a renovar: {$accounts->count()}");

        foreach ($accounts as $acc) {
            $this->info("Renovando cuenta MeLi id {$acc->id} (user {$acc->user_id})...");
            Log::info("MELI TOKEN: renovando meli_account {$acc->id}", [
                'expires_at' => $acc->expires_at,
            ]);

            try {
                $data = $meliOAuth->refreshAccessToken(
                    $clientId,
                    $clientSecret,
                    (string) $acc->refresh_token,
                );

                $acc->update([
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? $acc->refresh_token,
                    'expires_at' => Carbon::now()
                        ->addSeconds((int) ($data['expires_in'] ?? 0))
                        ->subMinutes(2),
                ]);

                $acc->user?->syncMeliColumnsFromDefaultAccount();

                $this->info("OK cuenta {$acc->id} -> expira {$acc->expires_at}");
                Log::info("MELI TOKEN: OK meli_account {$acc->id}", [
                    'expires_at' => $acc->expires_at,
                ]);

            } catch (\Throwable $e) {
                $this->error("Excepción cuenta {$acc->id}: {$e->getMessage()}");
                Log::error("MELI TOKEN: excepción meli_account {$acc->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return 0;
    }
}
