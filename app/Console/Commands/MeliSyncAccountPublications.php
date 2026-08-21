<?php

namespace App\Console\Commands;

use App\Models\MeliAccount;
use App\Services\MeliAccountPublicationSyncService;
use Illuminate\Console\Command;

class MeliSyncAccountPublications extends Command
{
    protected $signature = 'meli:sync-account-publications {--account= : ID interno de meli_accounts}';

    protected $description = 'Descarga todas las publicaciones de una cuenta de Mercado Libre y actualiza meli_publications';

    public function handle(MeliAccountPublicationSyncService $service): int
    {
        $accountId = (int) $this->option('account');

        if ($accountId <= 0) {
            $this->error('Indica la cuenta. Ejemplo: php artisan meli:sync-account-publications --account=1');
            return self::FAILURE;
        }

        $account = MeliAccount::query()->with('user')->find($accountId);
        if (! $account) {
            $this->error("No existe meli_accounts.id={$accountId}.");
            return self::FAILURE;
        }

        $this->info("Cuenta #{$account->id}: ".($account->nickname ?: 'Sin nombre')." ({$account->meli_user_id})");
        $this->line('Consultando todas las publicaciones directamente en Mercado Libre...');

        try {
            $summary = $service->sync($account, function (array $state): void {
                if (! empty($state['message'])) {
                    $this->line((string) $state['message']);
                }
            });
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->table(['Concepto', 'Cantidad'], [
            ['Publicaciones encontradas', $summary['discovered']],
            ['Publicaciones procesadas', $summary['processed']],
            ['Filas guardadas/actualizadas', $summary['saved']],
            ['Ocultas por bloqueo o revisión', $summary['hidden_blocked_or_review']],
            ['Visibles estimadas en el panel', $summary['visible_estimate']],
            ['Filas anteriores marcadas no actuales', $summary['marked_not_current']],
            ['Errores parciales', $summary['errors']],
        ]);

        return self::SUCCESS;
    }
}
