<?php

namespace App\Console\Commands;

use App\Models\MeliAccount;
use App\Services\MeliFullStockService;
use Illuminate\Console\Command;
use Throwable;

class MeliSyncFullStock extends Command
{
    protected $signature = 'meli:sync-full-stock
                            {--account= : ID de meli_accounts}
                            {--user= : ID del usuario dueño de las cuentas}
                            {--mlm= : Sincronizar únicamente una publicación MLM}
                            {--deep : Revisar todos los User Products; es más completo pero mucho más lento por el límite de 100 RPM}';

    protected $description = 'Consulta solo publicaciones logistic_type=fulfillment y guarda su inventario FULL con variantes';

    public function handle(MeliFullStockService $service): int
    {
        $accountId = (int) $this->option('account');
        $userId = (int) $this->option('user');
        $mlm = strtoupper(trim((string) $this->option('mlm')));
        $deep = (bool) $this->option('deep');

        $accounts = MeliAccount::query()
            ->when($accountId > 0, fn ($query) => $query->whereKey($accountId))
            ->when($userId > 0, fn ($query) => $query->where('user_id', $userId))
            ->where(function ($query) {
                $query->whereNotNull('access_token')
                    ->orWhereNotNull('refresh_token');
            })
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();

        if ($accounts->isEmpty()) {
            $this->error('No se encontraron cuentas de Mercado Libre con token.');

            return self::FAILURE;
        }

        $fatalErrors = 0;

        foreach ($accounts as $account) {
            $this->newLine();
            $this->info(sprintf(
                'Cuenta #%d: %s (%s)',
                $account->id,
                $account->nickname ?: 'Sin nombre',
                $account->meli_user_id,
            ));

            $this->comment($deep
                ? 'Modo PROFUNDO: revisará todos los User Products y puede tardar cerca de una hora.'
                : 'Modo RÁPIDO: primero filtra logistic_type=fulfillment y luego consulta lotes de 20.');

            try {
                $stats = $service->syncAccount(
                    $account,
                    $mlm !== '' ? $mlm : null,
                    fn (string $message) => $this->line($message),
                    $deep,
                );

                $this->table(
                    ['Concepto', 'Cantidad'],
                    [
                        ['Publicaciones FULL encontradas en ML', $stats['remote_items_found']],
                        ['Lotes multiget ejecutados', $stats['item_batches']],
                        ['Publicaciones revisadas', $stats['publications_scanned']],
                        ['Candidatos de stock consultados', $stats['stock_candidates_checked']],
                        ['User Products omitidos en modo rápido', $stats['user_products_skipped_fast']],
                        ['Publicaciones con FULL', $stats['publications_with_full']],
                        ['Filas FULL guardadas', $stats['full_rows_saved']],
                        ['Filas anteriores eliminadas', $stats['full_rows_removed']],
                        ['Errores parciales', $stats['errors']],
                    ],
                );

                if (! $deep && $stats['user_products_skipped_fast'] > 0) {
                    $this->warn('Se omitieron algunos User Products sin identificador FULL visible. Usa --deep solamente para auditarlos.');
                }
            } catch (Throwable $exception) {
                $fatalErrors++;
                $this->error($exception->getMessage());
            }
        }

        return $fatalErrors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
