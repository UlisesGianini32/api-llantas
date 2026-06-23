<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location of this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'meli' => [
        /** ID de aplicación en el centro de desarrolladores ML (validación de webhooks). */
        'app_id' => env('MELI_APP_ID'),

        /** Mismo valor que App ID en Dev Center; si no defines MELI_CLIENT_ID, se usa MELI_APP_ID. */
        'client_id' => env('MELI_CLIENT_ID', env('MELI_APP_ID')),
        'client_secret' => env('MELI_CLIENT_SECRET'),
        /** Debe coincidir con la Redirect URI en Dev Center; si falta, se arma con APP_URL. */
        'redirect_uri' => env('MELI_REDIRECT_URI') ?: rtrim((string) env('APP_URL', ''), '/') . '/auth/meli/callback',

        /**
         * URL de autorización OAuth (por país). México: https://auth.mercadolibre.com.mx/authorization
         * @see https://developers.mercadolibre.com.mx/es_ar/autenticacion-y-autorizacion
         */
        'authorization_url' => env('MELI_AUTHORIZATION_URL', 'https://auth.mercadolibre.com.mx/authorization'),

        /** Paso 2 OAuth: siempre api.mercadolibre.com (global). */
        'oauth_token_url' => env('MELI_OAUTH_TOKEN_URL', 'https://api.mercadolibre.com/oauth/token'),

        /**
         * Scopes permitidos: offline_access (refresh_token), read, write.
         * Cadena con espacios, tal como indica la doc (ej. respuesta incluye "offline_access read write").
         */
        'oauth_scope' => env('MELI_OAUTH_SCOPE', 'offline_access read write'),

        /**
         * Si en Dev Center activaste "Requiere PKCE", pon true y envía code_challenge + code_verifier.
         */
        'use_pkce' => filter_var(env('MELI_USE_PKCE', false), FILTER_VALIDATE_BOOLEAN),

        'official_store_id' => env('MELI_OFFICIAL_STORE_ID'),
        'official_store_id_marketmax' => env('MELI_OFFICIAL_STORE_ID_MARKETMAX'),
        'official_store_id_tobeauty' => env('MELI_OFFICIAL_STORE_ID_TOBEAUTY'),

        /**
         * Llantas en ML: si el stock local es exactamente este valor, la sync pausa la publicación (PUT status=paused).
         * 0 = desactivado (solo actualiza cantidad/precio como antes).
         * @see https://developers.mercadolibre.com.mx/es_ar/descripcion-de-la-publicaciones
         */
        'pause_llantas_when_stock_equals' => max(0, (int) env('MELI_PAUSE_LLANTAS_WHEN_STOCK_EQUALS', 1)),

        /**
         * SYSCOM en ML: si el stock de Hermosillo es <= a este valor, la sync pausa la publicación
         * (PUT status=paused). Al recuperar stock por encima del umbral, reactiva (status=active).
         * Por defecto 0 = pausa solo cuando se queda en 0. -1 = desactivar el pausado automático.
         */
        'pause_syscom_when_stock_at_or_below' => (int) env('MELI_PAUSE_SYSCOM_WHEN_STOCK_AT_OR_BELOW', 0),

        /**
         * PUT concurrentes contra la API de items en meli:sync-stock (reduce latencia acumulada).
         */
        'sync_concurrency' => max(1, min(25, (int) env('MELI_SYNC_CONCURRENCY', 8))),

        /**
         * Si no hay cambios respecto al último JSON guardado (raw) + estado, omitir PUT (ahorra llamadas y rate limits).
         */
        'sync_skip_noop_puts' => filter_var(env('MELI_SYNC_SKIP_NOOP_PUTS', true), FILTER_VALIDATE_BOOLEAN),

        /**
         * Webhook orders_v2: encola ProcessMeliOrderNotification. Por defecto false si ya usas MeliOrderSyncService.
         * MELI_WEBHOOK_DISPATCH_ORDERS_V2 tiene prioridad; si no existe, se usa MELI_WEBHOOK_PROCESS_ORDERS.
         */
        'webhook_dispatch_orders_v2' => filter_var(
            env('MELI_WEBHOOK_DISPATCH_ORDERS_V2', env('MELI_WEBHOOK_PROCESS_ORDERS', false)),
            FILTER_VALIDATE_BOOLEAN
        ),

        /**
         * Usuario de BD cuyas cuentas ML usa `meli:sync-orders` en el schedule (routes/console.php).
         * Debe coincidir con el usuario que enlazó Mercado Libre en el panel.
         */
        'sync_orders_user_id' => max(0, (int) env('MELI_SYNC_ORDERS_USER_ID', 4)),
    ],

    'shopify' => [
        'store_domain' => env('SHOPIFY_STORE_DOMAIN'),
        'client_id' => env('SHOPIFY_CLIENT_ID'),
        'client_secret' => env('SHOPIFY_CLIENT_SECRET'),
        'api_version' => env('SHOPIFY_API_VERSION', '2025-01'),
        /** Resolver en cola: si Shopify va lento, baja timeout para no “colgar” tanto (segundos). */
        'taxonomy_connect_timeout' => (int) env('SHOPIFY_TAXONOMY_CONNECT_TIMEOUT', 8),
        'taxonomy_timeout' => (int) env('SHOPIFY_TAXONOMY_TIMEOUT', 20),
        /** Intentos totales de la petición HTTP (Laravel retry). */
        'taxonomy_retry_times' => (int) env('SHOPIFY_TAXONOMY_RETRY_TIMES', 2),
        'taxonomy_retry_delay_ms' => (int) env('SHOPIFY_TAXONOMY_RETRY_DELAY_MS', 400),
    ],

    'syscom' => [
        'base_url' => env('SYSCOM_BASE_URL', 'https://developers.syscom.mx/api/v1'),
        'oauth_url' => env('SYSCOM_OAUTH_URL', 'https://developers.syscom.mx/oauth/token'),
        'client_id' => env('SYSCOM_CLIENT_ID'),
        'client_secret' => env('SYSCOM_CLIENT_SECRET'),
        'access_token' => env('SYSCOM_ACCESS_TOKEN'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];