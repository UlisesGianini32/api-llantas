<?php

namespace App\Console\Commands;

use App\Models\MeliAccount;
use App\Services\MercadoLibre\PriceManager\MeliBrandClassificationService;
use Illuminate\Console\Command;

class ClassifyMeliPriceManagerBrandsCommand extends Command
{
    protected $signature = 'meli:price-manager-classify
        {--account= : ID interno de meli_accounts}
        {--all : Reconsiderar clasificaciones automáticas existentes}
        {--dry-run : Calcular el resultado sin modificar la base de datos}';

    protected $description = 'Clasifica determinísticamente las marcas de Meli Price Manager';

    public function handle(MeliBrandClassificationService $service): int
    {
        $account = $this->resolveAccount();
        if ($account === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN: no se guardará ningún cambio.');
        }

        $summary = $service->classifyAccount(
            $account,
            reclassifyAll: (bool) $this->option('all'),
            dryRun: $dryRun,
        );

        $this->table(['Resultado', 'Cantidad'], [
            ['Procesados', $summary['processed']],
            ['Categorizados', $summary['categorized']],
            ['Sugeridos', $summary['suggested']],
            ['Sin categoría', $summary['uncategorized']],
            ['Ignorados', $summary['ignored']],
            ['Manuales preservados', $summary['skipped_manual']],
            [$dryRun ? 'Cambiarían' : 'Modificados', $summary['changed']],
        ]);

        return self::SUCCESS;
    }

    private function resolveAccount(): ?MeliAccount
    {
        $accountOption = trim((string) $this->option('account'));
        if ($accountOption !== '') {
            if (! ctype_digit($accountOption) || (int) $accountOption <= 0) {
                $this->error('--account debe ser un ID numérico válido.');

                return null;
            }

            $account = MeliAccount::query()->find((int) $accountOption);
            if ($account === null) {
                $this->error("No existe meli_accounts.id={$accountOption}.");
            }

            return $account;
        }

        $accounts = MeliAccount::query()->orderBy('id')->limit(2)->get();
        if ($accounts->count() === 1) {
            return $accounts->first();
        }

        $this->error($accounts->isEmpty()
            ? 'No hay cuentas de Mercado Libre registradas.'
            : 'Hay varias cuentas disponibles. Indica una con --account=ID.');

        return null;
    }
}
