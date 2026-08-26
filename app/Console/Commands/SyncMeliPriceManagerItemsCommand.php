<?php

namespace App\Console\Commands;

use App\Jobs\SyncMeliPriceManagerItemsJob;
use App\Models\MeliAccount;
use App\Services\MercadoLibre\PriceManager\MeliPriceManagerSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncMeliPriceManagerItemsCommand extends Command
{
    protected $signature = 'meli:price-manager-sync
        {--account= : ID interno de meli_accounts}
        {--sync : Ejecutar en el proceso actual en lugar de encolar el job}';

    protected $description = 'Sincroniza publicaciones de Mercado Libre para Meli Price Manager';

    public function handle(MeliPriceManagerSyncService $service): int
    {
        $account = $this->resolveAccount();
        if ($account === null) {
            return self::FAILURE;
        }

        if (! $this->option('sync')) {
            SyncMeliPriceManagerItemsJob::dispatch((int) $account->id);
            $this->info("Sincronización encolada para la cuenta #{$account->id} en la cola meli.");

            return self::SUCCESS;
        }

        $this->info("Sincronizando cuenta #{$account->id} ({$account->nickname})...");

        try {
            $summary = $service->syncAccount($account);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Concepto', 'Cantidad'], [
            ['Publicaciones encontradas', $summary['total_found']],
            ['Procesadas', $summary['processed']],
            ['Creadas', $summary['created']],
            ['Actualizadas', $summary['updated']],
            ['Fallidas', $summary['failed']],
        ]);

        if ($summary['error_details'] !== []) {
            $this->warn('Detalle de errores (máximo 10):');
            $this->table(
                ['Item', 'HTTP', 'Mensaje'],
                array_map(static fn (array $detail): array => [
                    $detail['meli_item_id'],
                    $detail['http_status'] ?? '—',
                    $detail['message'],
                ], $summary['error_details']),
            );
        }

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

        $accounts = MeliAccount::query()
            ->where(function ($query): void {
                $query->whereNotNull('access_token')->where('access_token', '!=', '')
                    ->orWhere(function ($query): void {
                        $query->whereNotNull('refresh_token')->where('refresh_token', '!=', '');
                    });
            })
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($accounts->count() === 1) {
            return $accounts->first();
        }

        if ($accounts->isEmpty()) {
            $this->error('No hay cuentas de Mercado Libre con access_token o refresh_token.');
        } else {
            $this->error('Hay varias cuentas disponibles. Indica una con --account=ID.');
        }

        return null;
    }
}
