<?php

namespace App\Console\Commands;

use App\Jobs\PushMeliSharedStockGroupJob;
use App\Models\MeliAccount;
use App\Models\MeliSharedStockGroup;
use App\Services\MeliAccountPublicationSyncService;
use App\Services\MeliSharedStockLinkService;
use Illuminate\Console\Command;

class MeliLinkSharedStock extends Command
{
    protected $signature = 'meli:shared-stock-link
        {--master=1 : ID de la cuenta maestra}
        {--secondary=2 : ID de la cuenta secundaria}
        {--apply : Guardar las conexiones encontradas}
        {--sync : Sincronizar primero las publicaciones de ambas cuentas}
        {--refresh-master-stock : Reemplazar el stock compartido con el stock actual de cuenta 1}
        {--push : Enviar el stock resultante a todos los miembros conectados}
        {--show=10 : Cantidad de ejemplos a mostrar}';

    protected $description = 'Conecta publicaciones y variantes de las cuentas 1 y 2 para compartir stock';

    public function handle(
        MeliSharedStockLinkService $linker,
        MeliAccountPublicationSyncService $publicationSync,
    ): int {
        $masterId = max(1, (int) $this->option('master'));
        $secondaryId = max(1, (int) $this->option('secondary'));

        $master = MeliAccount::query()->find($masterId);
        $secondary = MeliAccount::query()->find($secondaryId);

        if (! $master || ! $secondary || (int) $master->user_id !== (int) $secondary->user_id) {
            $this->error('Las cuentas indicadas no existen o no pertenecen al mismo usuario.');

            return self::FAILURE;
        }

        if ($this->option('sync')) {
            foreach ([$master, $secondary] as $account) {
                $this->info("Sincronizando publicaciones de cuenta {$account->id}...");
                $publicationSync->sync($account, function (array $state): void {
                    if (! empty($state['message'])) {
                        $this->line((string) $state['message']);
                    }
                });
            }
        }

        $apply = (bool) $this->option('apply');
        $summary = $linker->build(
            userId: (int) $master->user_id,
            masterAccountId: $masterId,
            secondaryAccountId: $secondaryId,
            apply: $apply,
            refreshMasterStock: (bool) $this->option('refresh-master-stock'),
        );

        $this->table(['Concepto', 'Cantidad'], [
            ['Publicaciones cuenta 1', $summary['master_publications']],
            ['Publicaciones cuenta 2', $summary['secondary_publications']],
            ['Filas/variantes cuenta 1', $summary['master_rows']],
            ['Filas/variantes cuenta 2', $summary['secondary_rows']],
            ['Grupos detectados', $summary['groups_found']],
            ['Grupos seguros para activar', $summary['safe_groups']],
            ['Omitidos: sin stock legible en cuenta 1', $summary['skipped_master_stock_missing_groups']],
            ['Omitidos: stocks distintos en cuenta 1', $summary['skipped_master_stock_conflict_groups']],
            ['Miembros cuenta 1 detectados', $summary['master_members']],
            ['Miembros cuenta 2 detectados', $summary['mirror_members']],
            ['Miembros cuenta 1 seguros', $summary['safe_master_members']],
            ['Miembros cuenta 2 seguros', $summary['safe_mirror_members']],
            ['Sin coincidencia', $summary['unmatched_rows']],
            ['Ambiguas', $summary['ambiguous_rows']],
            ['Grupos con varias publicaciones cuenta 1', $summary['multi_master_groups']],
            ['Grupos con varias publicaciones cuenta 2', $summary['multi_mirror_groups']],
            ['Grupos con stock distinto dentro de cuenta 1', $summary['master_stock_conflict_groups']],
            ['Grupos con stock distinto dentro de cuenta 2', $summary['mirror_stock_conflict_groups']],
            ['Grupos donde cuenta 2 difiere del stock elegido', $summary['master_mirror_difference_groups']],
            ['Cambios guardados', $summary['applied'] ? 'Sí' : 'No (vista previa)'],
            ['Grupos guardados/activados', $summary['applied_groups'] ?? 0],
        ]);

        if (! empty($summary['match_method_counts'])) {
            $this->newLine();
            $this->info('Métodos de vinculación:');
            $this->table(
                ['Método', 'Filas'],
                collect($summary['match_method_counts'])
                    ->map(fn ($count, $method) => [$method, $count])
                    ->values()
                    ->all(),
            );
        }

        if (! empty($summary['unmatched_reason_counts'])) {
            $this->newLine();
            $this->warn('Motivos de filas sin coincidencia:');
            $this->table(
                ['Motivo', 'Filas'],
                collect($summary['unmatched_reason_counts'])
                    ->map(fn ($count, $reason) => [$reason, $count])
                    ->values()
                    ->all(),
            );
        }

        $show = max(0, min(50, (int) $this->option('show')));

        if ($show > 0 && ! empty($summary['sample_groups'])) {
            $this->newLine();
            $this->info('Ejemplos de grupos detectados:');
            $this->table(
                ['SKU', 'Stock elegido', 'Stocks C1', 'Stocks C2', 'MLM maestro', 'Variante', 'Cuenta 1', 'Cuenta 2', 'Seguro'],
                collect($summary['sample_groups'])->take($show)->map(fn (array $row) => [
                    $row['sku'] ?: '—',
                    $row['stock'] === null ? '—' : $row['stock'],
                    $row['master_stock_values'] === '' ? '—' : $row['master_stock_values'],
                    $row['mirror_stock_values'] === '' ? '—' : $row['mirror_stock_values'],
                    $row['master_mlm'],
                    $row['master_variation_id'] ?: '—',
                    $row['master_members'],
                    $row['mirror_members'],
                    $row['safe_to_apply'] ? 'Sí' : 'No',
                ])->all(),
            );
        }

        if ($show > 0 && ! empty($summary['sample_unsafe_groups'])) {
            $this->newLine();
            $this->error('Grupos omitidos por seguridad:');
            $this->table(
                ['SKU', 'MLM maestro', 'Stocks cuenta 1', 'Stocks cuenta 2', 'Motivo', 'Miembros C1', 'Miembros C2'],
                collect($summary['sample_unsafe_groups'])->take($show)->map(fn (array $row) => [
                    $row['sku'] ?: '—',
                    $row['master_mlm'] ?: '—',
                    $row['master_stock_values'] === '' ? '—' : $row['master_stock_values'],
                    $row['mirror_stock_values'] === '' ? '—' : $row['mirror_stock_values'],
                    $row['reason'],
                    $row['master_members'],
                    $row['mirror_members'],
                ])->all(),
            );
        }

        if ($show > 0 && ! empty($summary['sample_stock_conflicts'])) {
            $this->newLine();
            $this->warn('Grupos con stocks diferentes antes de activar:');
            $this->table(
                ['SKU', 'MLM elegido', 'Stocks cuenta 1', 'Stocks cuenta 2', 'Stock elegido', 'Miembros C1', 'Miembros C2'],
                collect($summary['sample_stock_conflicts'])->take($show)->map(fn (array $row) => [
                    $row['sku'] ?: '—',
                    $row['master_mlm'] ?: '—',
                    $row['master_stock_values'] === '' ? '—' : $row['master_stock_values'],
                    $row['mirror_stock_values'] === '' ? '—' : $row['mirror_stock_values'],
                    $row['selected_stock'],
                    $row['master_members'],
                    $row['mirror_members'],
                ])->all(),
            );
        }

        if ($show > 0 && ! empty($summary['sample_unmatched'])) {
            $this->newLine();
            $this->warn('Ejemplos sin coincidencia:');
            $this->table(
                ['MLM cuenta 2', 'Variante', 'SKU', 'MLM origen', 'Motivo', 'Estados C1'],
                collect($summary['sample_unmatched'])->take($show)->map(fn (array $row) => [
                    $row['mlm'],
                    $row['variation_id'] ?: '—',
                    $row['sku'] ?: '—',
                    $row['source_mlm'] ?: '—',
                    $row['reason'] ?? '—',
                    $row['master_statuses'] ?? '—',
                ])->all(),
            );
        }

        if ($show > 0 && ! empty($summary['sample_ambiguous'])) {
            $this->newLine();
            $this->warn('Ejemplos ambiguos (no se conectaron por seguridad):');
            $this->table(
                ['MLM cuenta 2', 'Variante', 'SKU', 'Motivo'],
                collect($summary['sample_ambiguous'])->take($show)->map(fn (array $row) => [
                    $row['mlm'],
                    $row['variation_id'] ?: '—',
                    $row['sku'] ?: '—',
                    $row['reason'],
                ])->all(),
            );
        }

        if (! $apply) {
            $this->warn('Vista previa únicamente. --apply guardará solo los grupos seguros y omitirá los conflictivos o sin stock maestro legible.');
        } elseif (($summary['skipped_master_stock_missing_groups'] ?? 0) > 0 || ($summary['skipped_master_stock_conflict_groups'] ?? 0) > 0) {
            $this->warn('Los grupos inseguros no fueron activados. Permanecerán fuera del stock compartido hasta corregirlos.');
        }

        if ($apply && $this->option('push')) {
            $ids = MeliSharedStockGroup::query()
                ->where('user_id', $master->user_id)
                ->where('master_account_id', $masterId)
                ->where('is_enabled', true)
                ->pluck('id');

            foreach ($ids as $id) {
                PushMeliSharedStockGroupJob::dispatch((int) $id)->onQueue('meli');
            }

            $this->info("Se enviaron {$ids->count()} grupos a la cola meli.");
        }

        return self::SUCCESS;
    }
}
