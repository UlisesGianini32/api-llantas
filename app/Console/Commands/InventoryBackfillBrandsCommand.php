<?php

namespace App\Console\Commands;

use App\Models\InventoryProduct;
use App\Models\MeliBrandAlias;
use App\Models\MeliBrandGroup;
use App\Models\MeliPriceManagerItem;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class InventoryBackfillBrandsCommand extends Command
{
    protected $signature = 'inventory:backfill-brands
        {--force : Sobrescribir marcas existentes en los productos}
        {--dry-run : Ejecutar simulación sin guardar cambios en la base de datos}';

    protected $description = 'Autocompleta marcas y proveedores en el catálogo de almacén a partir de MeLi, productos y nombres';

    private const GENERIC_WORDS = [
        'KIT', 'SET', 'PACK', 'PAR', 'JUEGO', 'LLANTA', 'LLANTAS', 'CREMA', 'SHAMPOO',
        'ACONDICIONADOR', 'TRATAMIENTO', 'TINTE', 'MASCARILLA', 'SERUM', 'ACEITE',
        'SPRAY', 'GEL', 'POLVO', 'CERA', 'ESPUMA', 'LOCION', 'BALSAMO', 'JABON',
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        $this->info('Iniciando proceso de detección y asignación de marcas...');
        if ($dryRun) {
            $this->warn('[MODO SIMULACIÓN - DRY RUN] No se guardarán cambios en la base de datos.');
        }

        // 1. Recopilar marcas conocidas del sistema
        $knownBrands = $this->collectKnownBrands();
        $this->line("Marcas conocidas identificadas en el sistema: <comment>{$knownBrands->count()}</comment>");

        // 2. Obtener productos de almacén a procesar
        if (! Schema::hasTable('inventory_products')) {
            $this->error('La tabla inventory_products no existe en la base de datos.');

            return self::FAILURE;
        }

        $query = InventoryProduct::query();
        if (! $force) {
            $query->where(function ($q) {
                $q->whereNull('brand')->orWhere('brand', '');
            });
        }

        $products = $query->orderBy('id')->get();
        $this->line("Productos a evaluar: <comment>{$products->count()}</comment>");

        $updatedCount = 0;
        $matchedFromCatalog = 0;
        $matchedFromMeli = 0;
        $matchedFromKnown = 0;
        $matchedFromTitle = 0;

        foreach ($products as $prod) {
            $detectedBrand = null;
            $detectedSupplier = null;

            // Estrategia 1: Coincidencia por SKU en tabla products
            if (Schema::hasTable('products')) {
                $p = Product::query()
                    ->where('sku', $prod->sku)
                    ->whereNotNull('brand')
                    ->where('brand', '!=', '')
                    ->first();

                if ($p && trim((string) $p->brand) !== '') {
                    $detectedBrand = trim((string) $p->brand);
                    $matchedFromCatalog++;
                }
            }

            // Estrategia 2: Coincidencia por SKU en meli_price_manager_items
            if (! $detectedBrand && Schema::hasTable('meli_price_manager_items')) {
                $mpm = MeliPriceManagerItem::query()
                    ->with('brandGroup')
                    ->where('sku', $prod->sku)
                    ->first();

                if ($mpm) {
                    $brandVal = $mpm->brandGroup?->name ?: $mpm->meli_brand;
                    if ($brandVal && trim($brandVal) !== '') {
                        $detectedBrand = trim($brandVal);
                        $matchedFromMeli++;
                    }
                }
            }

            // Estrategia 3: Buscar marcas conocidas dentro del nombre o SKU
            if (! $detectedBrand) {
                $nameUpper = ' '.mb_strtoupper($prod->name).' ';
                $skuUpper = ' '.mb_strtoupper($prod->sku).' ';

                foreach ($knownBrands as $known) {
                    $pattern = ' '.mb_strtoupper($known).' ';
                    if (str_contains($nameUpper, $pattern) || str_contains($skuUpper, $pattern) || str_starts_with($nameUpper, ' '.mb_strtoupper($known))) {
                        $detectedBrand = $known;
                        $matchedFromKnown++;
                        break;
                    }
                }
            }

            // Estrategia 4: Primera palabra del nombre (si es marca clara como BUNEE, JOICO)
            if (! $detectedBrand) {
                $words = preg_split('/\s+/', trim($prod->name));
                if (! empty($words[0])) {
                    $firstWord = mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $words[0]));
                    if (strlen($firstWord) >= 3 && ! in_array($firstWord, self::GENERIC_WORDS, true)) {
                        $detectedBrand = $firstWord;
                        $matchedFromTitle++;
                    }
                }
            }

            if ($detectedBrand) {
                $detectedBrand = mb_strtoupper(trim($detectedBrand));
                $detectedSupplier = $prod->supplier ?: $detectedBrand;

                if (! $dryRun) {
                    $prod->update([
                        'brand' => $detectedBrand,
                        'supplier' => $detectedSupplier,
                    ]);
                }

                $updatedCount++;
                $this->line("✓ [<info>{$prod->sku}</info>] {$prod->name} → Marca: <comment>{$detectedBrand}</comment>");
            }
        }

        $this->newLine();
        $this->table(
            ['Métrica', 'Total'],
            [
                ['Productos evaluados', $products->count()],
                ['Marcas asignadas / actualizadas', $updatedCount],
                ['Por catálogo (products.brand)', $matchedFromCatalog],
                ['Por Mercado Libre Price Manager', $matchedFromMeli],
                ['Por lista de marcas del sistema', $matchedFromKnown],
                ['Por extracción de título', $matchedFromTitle],
                ['Sin clasificar', $products->count() - $updatedCount],
            ]
        );

        $this->info($dryRun ? 'Simulación finalizada.' : 'Proceso de asignación de marcas completado con éxito.');

        return self::SUCCESS;
    }

    private function collectKnownBrands(): \Illuminate\Support\Collection
    {
        $brands = collect([
            // Belleza / Capilar
            'BUNEE', 'JOICO', 'OLAPLEX', 'REDKEN', 'MOROCCANOIL', 'ALFAPARF',
            'SCHWARZKOPF', 'KERASTASE', 'WELLA', 'MATRIX', 'AVEDA', 'TIGI',
            'PAUL MITCHELL', 'LOREAL', "L'OREAL", 'SEBASTIAN', 'NEOXIN',
            // Llantas / Automotriz
            'MICHELIN', 'BFGOODRICH', 'CONTINENTAL', 'PIRELLI', 'BRIDGESTONE',
            'GOODYEAR', 'HANKOOK', 'YOKOHAMA', 'DUNLOP', 'KUMHO', 'TOYO',
            'MAXXIS', 'COOPER', 'FIRESTONE', 'UNIROYAL', 'GENERAL TIRE',
            'NEXEN', 'FALKEN', 'GT RADIAL', 'TORNEL', 'STARFIRE', 'ROADSTONE',
        ]);

        if (Schema::hasTable('meli_brand_groups')) {
            $meliGroups = MeliBrandGroup::pluck('name');
            $brands = $brands->merge($meliGroups);
        }

        if (Schema::hasTable('meli_brand_aliases')) {
            $meliAliases = MeliBrandAlias::where('active', true)->pluck('alias');
            $brands = $brands->merge($meliAliases);
        }

        if (Schema::hasTable('products')) {
            $catalogBrands = Product::distinct()
                ->whereNotNull('brand')
                ->where('brand', '!=', '')
                ->pluck('brand');
            $brands = $brands->merge($catalogBrands);
        }

        return $brands
            ->filter()
            ->map(fn ($b) => trim((string) $b))
            ->unique()
            // Ordenar de mayor a menor longitud para que 'GENERAL TIRE' coincida antes que 'TIRE'
            ->sortByDesc(fn ($b) => strlen($b))
            ->values();
    }
}
