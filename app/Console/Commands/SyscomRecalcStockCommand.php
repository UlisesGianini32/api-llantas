<?php

namespace App\Console\Commands;

use App\Models\SyscomMeliQueue;
use App\Models\SyscomProduct;
use App\Support\SyscomHermosilloStock;
use Illuminate\Console\Command;

class SyscomRecalcStockCommand extends Command
{
    protected $signature = 'syscom:recalc-stock
                            {--branch= : Nombre de sucursal (default: config syscom.sucursal_nombre)}
                            {--no-total-fallback : NO usar total_existencia como respaldo cuando existencia esté vacía}
                            {--dry-run : Solo muestra cuántos cambiarian, sin guardar}';

    protected $description = 'Recalcula stock_hermosillo desde el JSON existencia ya guardado (sin llamar a SYSCOM)';

    public function handle(): int
    {
        $branchName = trim((string) ($this->option('branch') ?: config('syscom.sucursal_nombre', 'hermosillo')));
        $dry = (bool) $this->option('dry-run');
        $useTotalFallback = ! (bool) $this->option('no-total-fallback')
            && ! (bool) config('syscom.import_only_hermosillo_stock', true);

        $branchCodeByProduct = SyscomMeliQueue::query()
            ->whereNotNull('branch_code')
            ->pluck('branch_code', 'syscom_product_id');

        $total = SyscomProduct::query()->count();
        $changed = 0;
        $withStock = 0;
        $byParser = 0;
        $byTotal = 0;

        SyscomProduct::query()->chunkById(200, function ($chunk) use (&$changed, &$withStock, &$byParser, &$byTotal, $branchCodeByProduct, $branchName, $dry, $useTotalFallback) {
            foreach ($chunk as $p) {
                /** @var SyscomProduct $p */
                $branchCode = (string) ($branchCodeByProduct[$p->id] ?? '');
                $existencia = is_array($p->existencia) ? $p->existencia : [];

                $stock = SyscomHermosilloStock::forBranch($existencia, $branchCode, $branchName);
                if ($stock > 0) {
                    $byParser++;
                } elseif ($useTotalFallback && $existencia === [] && (int) ($p->total_existencia ?? 0) > 0) {
                    // Fallback: el listado SYSCOM se filtró con sucursal=X&stock=1, así que un producto
                    // con total_existencia>0 en BD tiene stock en esa sucursal por construcción.
                    $stock = (int) $p->total_existencia;
                    $byTotal++;
                }

                if ($stock > 0) {
                    $withStock++;
                }

                if ((int) ($p->stock_hermosillo ?? 0) !== $stock) {
                    $changed++;
                    if (! $dry) {
                        $p->stock_hermosillo = $stock;
                        $p->save();
                    }
                }
            }
        });

        if ($dry) {
            $this->info("(dry-run) Stock>0: {$withStock}/{$total}  (parser: {$byParser}, fallback total: {$byTotal})  Cambiarían: {$changed}.");
        } else {
            $this->info("Stock recalculado. Stock>0: {$withStock}/{$total}  (parser: {$byParser}, fallback total: {$byTotal})  Filas actualizadas: {$changed}.");
        }

        if ($byParser === 0 && $byTotal > 0) {
            $this->line('');
            $this->warn('La existencia detallada no está poblada en BD. El stock se está aplicando por total_existencia (fallback).');
            $this->warn('Para tener desglose por sucursal real, ejecutá:');
            $this->line('   php artisan syscom:sync-products --with-detail --max-pages=0');
        }

        return self::SUCCESS;
    }
}
