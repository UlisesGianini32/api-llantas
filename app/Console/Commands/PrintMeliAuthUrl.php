<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PrintMeliAuthUrl extends Command
{
    protected $signature = 'meli:print-auth-url
                            {--state= : State fijo para pruebas (opcional)}';

    protected $description = 'Muestra client_id, redirect_uri y la URL de autorización OAuth de Mercado Libre (depuración)';

    public function handle(): int
    {
        $clientId = (string) config('services.meli.client_id', '');
        $redirectUri = (string) config('services.meli.redirect_uri', '');
        $authorizationUrl = rtrim((string) config('services.meli.authorization_url', ''), '/');
        if ($authorizationUrl === '') {
            $authorizationUrl = 'https://auth.mercadolibre.com.mx/authorization';
        }
        $scope = trim((string) config('services.meli.oauth_scope', ''));
        $usePkce = (bool) config('services.meli.use_pkce');
        $tokenUrl = (string) config('services.meli.oauth_token_url', 'https://api.mercadolibre.com/oauth/token');

        $this->newLine();
        $this->info('Valores efectivos (config / .env):');
        $this->table(
            ['Clave', 'Valor'],
            [
                ['client_id', $clientId !== '' ? $clientId : '(vacío)'],
                ['redirect_uri', $redirectUri !== '' ? $redirectUri : '(vacío)'],
                ['authorization_url', $authorizationUrl],
                ['oauth_token_url', $tokenUrl],
                ['scope (en la URL)', $scope !== '' ? $scope : '(no se envía)'],
                ['MELI_USE_PKCE', $usePkce ? 'true' : 'false'],
            ]
        );

        if ($clientId === '' || $redirectUri === '') {
            $this->error('Falta client_id o redirect_uri. Revisa MELI_APP_ID, MELI_CLIENT_ID, APP_URL y MELI_REDIRECT_URI.');

            return self::FAILURE;
        }

        $state = (string) $this->option('state');
        if ($state === '') {
            $state = 'debug_'.bin2hex(random_bytes(8));
            $this->comment('State de ejemplo generado. Usa --state=tu_valor para uno fijo.');
        }

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
            $this->warn('PKCE activo: esta URL no incluye code_challenge; para flujo real usa “Vincular” en el panel.');
        }

        $url = $authorizationUrl.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $this->newLine();
        $this->info('URL de autorización (copiar y pegar en el navegador):');
        $this->line($url);
        $this->newLine();
        $this->comment('Comprueba que redirect_uri coincida exactamente con Dev Center (https, www, sin barra final extra).');
        $this->comment('Al aceptar, ML redirige al callback; sin sesión Laravel el state no validará — sirve para ver si ML acepta la app.');

        return self::SUCCESS;
    }
}
