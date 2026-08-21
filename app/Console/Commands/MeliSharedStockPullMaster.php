<?php

namespace App\Console\Commands;

use App\Services\MeliSharedStockMasterReconcileService;
use Illuminate\Console\Command;

class MeliSharedStockPullMaster extends Command
{
    protected $signature = 'meli:shared-stock-pull-master
        {--master=1 : Cuenta principal}
        {--push : Enviar a ambas cuentas cualquier cambio detectado}';

    protected $description = 'Lee el stock en vivo de la cuenta 1 y actualiza los grupos compartidos';

    public function handle(MeliSharedStockMasterReconcileService $service): int
    {
        $result = $service->reconcile(
            max(1, (int) $this->option('master')),
            (bool) $this->option('push'),
        );

        $this->table(['Grupos', 'Revisados', 'Cambios', 'Errores', 'Enviados a cola'], [[
            $result['groups'],
            $result['checked'],
            $result['changed'],
            $result['errors'],
            $result['queued'],
        ]]);

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
