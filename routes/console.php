<?php

use App\Services\SyscomApiService;
use App\Support\SyscomCarritoPagoHelper;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('syscom:order-pago-methods', function (SyscomApiService $api) {
    try {
        $token = $api->getAccessToken();
    } catch (\Throwable $e) {
        $this->error($e->getMessage());

        return 1;
    }

    $baseUrl = rtrim((string) config('services.syscom.base_url', 'https://developers.syscom.mx/api/v1'), '/');
    $resp = Http::withToken($token)->acceptJson()->timeout(30)->get($baseUrl.'/carrito/pago');

    if (! $resp->successful()) {
        $this->error('SYSCOM /carrito/pago: '.$resp->status().' '.$resp->body());

        return 1;
    }

    $flat = SyscomCarritoPagoHelper::flattenPaymentMethods($resp->json());

    if ($flat === []) {
        $this->warn('Sin métodos en la respuesta.');

        return 0;
    }

    $this->info('SYSCOM pago: metodo_pago = ID forma.pue (generar) | codigo_sat = Forma de pago en PDF');
    $this->newLine();

    foreach ($flat as $row) {
        $nombre = (string) ($row['nombre'] ?? '');
        $titulo = (string) ($row['titulo'] ?? '');
        $codigo = (string) ($row['codigo_sat'] ?? '');
        $pue = (string) ($row['metodo_pago_pue'] ?? '');
        $ppd = (string) ($row['metodo_pago_ppd'] ?? '');

        $parts = array_filter([
            $nombre !== '' ? $nombre : null,
            $titulo !== '' ? "({$titulo})" : null,
            $codigo !== '' ? "codigo_sat={$codigo}" : null,
            $pue !== '' ? "metodo_pago_si_pue={$pue}" : null,
            $ppd !== '' ? "metodo_pago_si_ppd={$ppd}" : null,
        ]);
        $this->line('  '.implode(' | ', $parts));
    }

    $tipoPago = (string) config('syscom.orders_from_meli.tipo_pago', 'pue');
    $prefer = (string) config('syscom.orders_from_meli.metodo_pago_prefer', 'tarjeta+credito');
    $resolved = SyscomCarritoPagoHelper::resolvePaymentForOrder($resp->json(), $tipoPago, $prefer);
    $this->newLine();
    $this->info(sprintf(
        'Selección tarjeta crédito: metodo_pago=%s | codigo_sat=%s | %s',
        $resolved['metodo_pago'] !== '' ? $resolved['metodo_pago'] : '(vacío)',
        $resolved['codigo_sat'] ?? '—',
        $resolved['label'] !== '' ? $resolved['label'] : 'sin coincidencia'
    ));
    if (($resolved['source'] ?? '') !== '') {
        $this->line('  origen: '.$resolved['source']);
    }
    $this->comment('Tarjeta crédito en sucursal → SYSCOM_ORDER_METODO_PAGO_ID=04 (codigo_sat cuando no hay forma.pue).');
    $this->comment('«CONDICIONADO A PAGO» = pedido OK; SYSCOM espera confirmar cobro en portal/cartera.');

    return 0;
})->purpose('Lista métodos de pago SYSCOM (GET /carrito/pago)');


// ===============================
// ✅ SCHEDULE (Laravel 12)
// ===============================

// Refrescar tokens antes de expirar (cada 10 min)
Schedule::command('meli:refresh-token')
    ->everyTenMinutes()
    ->withoutOverlapping();

// Preguntas preventa de todas las cuentas vinculadas.
Schedule::command('meli:sync-questions --pages=4')
    ->everyTwoMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/meli-questions-sync.log'));

// Sincronizar inventario/precio local con Mercado Libre cada 15 minutos.
// Este comando ya no consulta SYSCOM; la consulta rápida de SYSCOM corre aparte cada hora.
Schedule::command('meli:sync-stock')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

$meliSyncOrdersUserId = (int) config('services.meli.sync_orders_user_id');
if ($meliSyncOrdersUserId > 0) {
    Schedule::command("meli:sync-orders --user_id={$meliSyncOrdersUserId} --today")
        ->everyFiveMinutes()
        ->withoutOverlapping();

    // Re-sync de respaldo para órdenes que cambian de estado después del día de creación.
    // AMS "Como ML · etiqueta lista" no filtra por día; Mercado puede mostrar pedidos
    // de varios días atrás que recién quedaron listos para imprimir.
    Schedule::command("meli:sync-orders --user_id={$meliSyncOrdersUserId} --days=14")
        ->everyThirtyMinutes()
        ->withoutOverlapping();

    // Barrido amplio para que AMS no se quede corto frente al filtro "Últimos 2 meses" de ML.
    Schedule::command("meli:sync-orders --user_id={$meliSyncOrdersUserId} --days=60")
        ->dailyAt('03:30')
        ->withoutOverlapping();
}

// Stock y precios SYSCOM por lotes de hasta 300 IDs.
// Corre al minuto 5 de cada hora y solo llama a Mercado Libre cuando detecta cambios.
Schedule::command('syscom:sync-stock-fast')
    ->hourlyAt(5)
    ->withoutOverlapping(55)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/syscom-stock-fast.log'));

// Convierte órdenes ML pagadas a pedidos SYSCOM (solo para publicaciones SYSCOM-*).
Schedule::command('syscom:sync-orders-from-ml --max=100')
    ->everyTenMinutes()
    ->withoutOverlapping();

Schedule::command('syscom:cancel-orders-from-ml --max=50')
    ->everyTenMinutes()
    ->withoutOverlapping();

// Mantiene alineado el listado AMS "Como ML · etiqueta lista" con el filtro real de ML.
// El sync por día de creación no toca órdenes antiguas; sin esto, las órdenes que ML ya
// pasó a printed/picked_up/shipped quedan como ready_to_print en BD y aparecen de más.
Schedule::command('ams:refresh-ready-to-print --max=300')
    ->everyTenMinutes()
    ->withoutOverlapping();

// Heartbeat del panel de salud.
Schedule::command('system:heartbeat')
    ->everyMinute()
    ->withoutOverlapping();
