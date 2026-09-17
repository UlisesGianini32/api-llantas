<?php

namespace App\Console\Commands;

use App\Models\Llanta;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class LlantasPurgarStockCero extends Command
{
    protected $signature = 'llantas:purgar-stock-cero
                            {--execute : Ejecuta la eliminación; sin esta opción solo muestra el plan}
                            {--export : Genera un respaldo CSV aunque no se ejecute la eliminación}
                            {--no-backup : Permite ejecutar sin generar respaldo CSV}
                            {--chunk=200 : Cantidad de llantas procesadas por bloque}';

    protected $description = 'Elimina llantas con stock 0 y sus variantes, conservando cualquier registro relacionado con una publicación de Mercado Libre.';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $export = (bool) $this->option('export');
        $noBackup = (bool) $this->option('no-backup');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $this->newLine();
        $this->info('Auditando llantas con stock 0...');

        $summary = $this->buildSummary();
        $this->printSummary($summary);

        if ($summary['deletable_llantas'] === 0) {
            $this->newLine();
            $this->info('No hay llantas elegibles para eliminar.');

            return self::SUCCESS;
        }

        $backupPath = null;
        $mustBackup = $export || ($execute && !$noBackup);

        if ($mustBackup) {
            try {
                $backupPath = $this->exportBackup();
                $this->newLine();
                $this->info("Respaldo generado: {$backupPath}");
            } catch (Throwable $e) {
                $this->error('No se pudo generar el respaldo: '.$e->getMessage());

                if ($execute && !$noBackup) {
                    $this->error('La eliminación fue cancelada porque el respaldo es obligatorio.');

                    return self::FAILURE;
                }
            }
        }

        if (!$execute) {
            $this->newLine();
            $this->warn('MODO AUDITORÍA: no se eliminó ni modificó ningún registro.');
            $this->line('Para ejecutar la limpieza:');
            $this->line('php artisan llantas:purgar-stock-cero --execute');

            return self::SUCCESS;
        }

        if (!$this->confirmExecution($summary, $backupPath, $noBackup)) {
            $this->warn('Operación cancelada.');

            return self::SUCCESS;
        }

        $deleted = [
            'llantas' => 0,
            'compuestos' => 0,
            'aliases' => 0,
            'candidates' => 0,
        ];

        try {
            $this->deletableQuery()
                ->select('id')
                ->orderBy('id')
                ->chunkById($chunkSize, function (Collection $rows) use (&$deleted): void {
                    $ids = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();

                    if ($ids === []) {
                        return;
                    }

                    DB::transaction(function () use ($ids, &$deleted): void {
                        // Bloquea nuevamente los registros elegibles para evitar borrar una llanta
                        // cuyo stock o publicación haya cambiado desde la auditoría inicial.
                        $safeIds = $this->deletableQuery()
                            ->whereIn('llantas.id', $ids)
                            ->lockForUpdate()
                            ->pluck('llantas.id')
                            ->map(fn ($id) => (int) $id)
                            ->all();

                        if ($safeIds === []) {
                            return;
                        }

                        $deleted['aliases'] += $this->deleteRelatedRows(
                            'llanta_sku_aliases',
                            ['llanta_id'],
                            $safeIds
                        );

                        $deleted['candidates'] += $this->deleteRelatedRows(
                            'llanta_sku_candidates',
                            [
                                'llanta_id',
                                'source_llanta_id',
                                'candidate_llanta_id',
                                'target_llanta_id',
                                'old_llanta_id',
                                'new_llanta_id',
                            ],
                            $safeIds
                        );

                        if (Schema::hasTable('producto_compuestos') && Schema::hasColumn('producto_compuestos', 'llanta_id')) {
                            $deleted['compuestos'] += DB::table('producto_compuestos')
                                ->whereIn('llanta_id', $safeIds)
                                ->delete();
                        }

                        $deleted['llantas'] += DB::table('llantas')
                            ->whereIn('id', $safeIds)
                            ->where('stock', 0)
                            ->where(function ($query): void {
                                $query->whereNull('MLM')->orWhere('MLM', '');
                            })
                            ->delete();
                    });
                }, 'llantas.id', 'id');
        } catch (Throwable $e) {
            report($e);
            $this->newLine();
            $this->error('La limpieza falló y el bloque actual fue revertido: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Limpieza terminada.');
        $this->table(
            ['Tipo', 'Eliminados'],
            [
                ['Llantas', $deleted['llantas']],
                ['Productos compuestos', $deleted['compuestos']],
                ['Aliases', $deleted['aliases']],
                ['Candidatos', $deleted['candidates']],
            ]
        );

        $remaining = $this->buildSummary();
        $this->newLine();
        $this->info('Estado después de la limpieza:');
        $this->printSummary($remaining);

        return self::SUCCESS;
    }

    private function buildSummary(): array
    {
        $total = Llanta::query()->count();
        $stockZero = Llanta::query()->where('stock', 0)->count();
        $protectedByOwnMlm = Llanta::query()
            ->where('stock', 0)
            ->whereNotNull('MLM')
            ->where('MLM', '<>', '')
            ->count();

        $protectedByCompoundMlm = 0;
        if ($this->hasCompoundTable()) {
            $protectedByCompoundMlm = Llanta::query()
                ->where('llantas.stock', 0)
                ->where(function ($query): void {
                    $query->whereNull('llantas.MLM')->orWhere('llantas.MLM', '');
                })
                ->whereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('producto_compuestos as pc')
                        ->whereColumn('pc.llanta_id', 'llantas.id')
                        ->whereNotNull('pc.MLM')
                        ->where('pc.MLM', '<>', '');
                })
                ->count();
        }

        $deletable = $this->deletableQuery()->count();
        $deletableCompounds = $this->countDeletableCompounds();

        return [
            'total_llantas' => $total,
            'stock_zero' => $stockZero,
            'protected_own_mlm' => $protectedByOwnMlm,
            'protected_compound_mlm' => $protectedByCompoundMlm,
            'protected_total' => $stockZero - $deletable,
            'deletable_llantas' => $deletable,
            'deletable_compounds' => $deletableCompounds,
        ];
    }

    private function printSummary(array $summary): void
    {
        $this->table(
            ['Concepto', 'Cantidad'],
            [
                ['Total de llantas', $summary['total_llantas']],
                ['Llantas con stock 0', $summary['stock_zero']],
                ['Protegidas por MLM propio', $summary['protected_own_mlm']],
                ['Protegidas por MLM en variante -2/-4', $summary['protected_compound_mlm']],
                ['Protegidas en total', $summary['protected_total']],
                ['Llantas elegibles para eliminar', $summary['deletable_llantas']],
                ['Variantes elegibles para eliminar', $summary['deletable_compounds']],
            ]
        );
    }

    private function deletableQuery(): Builder
    {
        $query = Llanta::query()
            ->where('llantas.stock', 0)
            ->where(function ($query): void {
                $query->whereNull('llantas.MLM')->orWhere('llantas.MLM', '');
            });

        if ($this->hasCompoundTable()) {
            $query->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('producto_compuestos as pc')
                    ->whereColumn('pc.llanta_id', 'llantas.id')
                    ->whereNotNull('pc.MLM')
                    ->where('pc.MLM', '<>', '');
            });
        }

        return $query;
    }

    private function countDeletableCompounds(): int
    {
        if (!$this->hasCompoundTable()) {
            return 0;
        }

        return DB::table('producto_compuestos as pc')
            ->whereIn('pc.llanta_id', $this->deletableQuery()->select('llantas.id'))
            ->count();
    }

    private function exportBackup(): string
    {
        $directory = storage_path('app/backups');

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException("No se pudo crear el directorio {$directory}");
        }

        $filename = 'llantas_stock_cero_'.now()->format('Ymd_His').'.csv';
        $path = $directory.DIRECTORY_SEPARATOR.$filename;
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("No se pudo crear el archivo {$path}");
        }

        try {
            // BOM UTF-8 para que Excel abra correctamente los acentos.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'tipo_registro',
                'llanta_id',
                'llanta_sku',
                'llanta_stock',
                'llanta_MLM',
                'marca',
                'medida',
                'descripcion',
                'costo',
                'precio_ML',
                'last_import_at',
                'compuesto_id',
                'compuesto_sku',
                'compuesto_tipo',
                'compuesto_stock',
                'compuesto_MLM',
                'compuesto_precio_ML',
            ]);

            $this->deletableQuery()
                ->orderBy('llantas.id')
                ->chunkById(200, function (Collection $llantas) use ($handle): void {
                    $ids = $llantas->pluck('id')->all();
                    $compounds = collect();

                    if ($this->hasCompoundTable() && $ids !== []) {
                        $compounds = DB::table('producto_compuestos')
                            ->whereIn('llanta_id', $ids)
                            ->orderBy('llanta_id')
                            ->orderBy('id')
                            ->get()
                            ->groupBy('llanta_id');
                    }

                    foreach ($llantas as $llanta) {
                        fputcsv($handle, [
                            'LLANTA',
                            $llanta->id,
                            $llanta->sku,
                            $llanta->stock,
                            $llanta->MLM,
                            $llanta->marca,
                            $llanta->medida,
                            $llanta->descripcion,
                            $llanta->costo,
                            $llanta->precio_ML,
                            optional($llanta->last_import_at)->format('Y-m-d H:i:s'),
                            '', '', '', '', '', '',
                        ]);

                        foreach ($compounds->get($llanta->id, collect()) as $compound) {
                            fputcsv($handle, [
                                'COMPUESTO',
                                $llanta->id,
                                $llanta->sku,
                                $llanta->stock,
                                $llanta->MLM,
                                $llanta->marca,
                                $llanta->medida,
                                $llanta->descripcion,
                                $llanta->costo,
                                $llanta->precio_ML,
                                optional($llanta->last_import_at)->format('Y-m-d H:i:s'),
                                $compound->id ?? '',
                                $compound->sku ?? '',
                                $compound->tipo ?? '',
                                $compound->stock ?? '',
                                $compound->MLM ?? '',
                                $compound->precio_ML ?? '',
                            ]);
                        }
                    }
                }, 'llantas.id', 'id');
        } finally {
            fclose($handle);
        }

        @chmod($path, 0664);

        return $path;
    }

    private function confirmExecution(array $summary, ?string $backupPath, bool $noBackup): bool
    {
        $this->newLine();
        $this->warn('ATENCIÓN: esta operación elimina registros de forma permanente.');
        $this->line("Se eliminarán {$summary['deletable_llantas']} llantas y {$summary['deletable_compounds']} variantes.");

        if ($backupPath !== null) {
            $this->line("Respaldo: {$backupPath}");
        } elseif ($noBackup) {
            $this->warn('Se solicitó ejecutar SIN respaldo.');
        }

        if (!$this->confirm('¿Deseas continuar?', false)) {
            return false;
        }

        $typed = trim((string) $this->ask('Escribe ELIMINAR para confirmar'));

        return $typed === 'ELIMINAR';
    }

    private function deleteRelatedRows(string $table, array $possibleColumns, array $llantaIds): int
    {
        if (!Schema::hasTable($table) || $llantaIds === []) {
            return 0;
        }

        $columns = collect($possibleColumns)
            ->filter(fn (string $column): bool => Schema::hasColumn($table, $column))
            ->values();

        if ($columns->isEmpty()) {
            return 0;
        }

        return DB::table($table)
            ->where(function ($query) use ($columns, $llantaIds): void {
                foreach ($columns as $index => $column) {
                    if ($index === 0) {
                        $query->whereIn($column, $llantaIds);
                    } else {
                        $query->orWhereIn($column, $llantaIds);
                    }
                }
            })
            ->delete();
    }

    private function hasCompoundTable(): bool
    {
        return Schema::hasTable('producto_compuestos')
            && Schema::hasColumn('producto_compuestos', 'llanta_id')
            && Schema::hasColumn('producto_compuestos', 'MLM');
    }
}
