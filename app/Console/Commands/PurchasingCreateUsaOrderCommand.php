<?php

namespace App\Console\Commands;

use App\Models\BrandRestockConfiguration;
use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderService;
use App\Services\Restock\InventoryDemandForecastingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class PurchasingCreateUsaOrderCommand extends Command
{
    protected $signature = 'purchasing:create-usa-order
                            {--include-desired : Incluir también productos que solo tienen cantidad deseada}
                            {--as-ordered : Marcar la orden como Colocada / En camino en vez de Borrador}
                            {--apply-forecast : Ajustar cantidades automáticamente con base en las ventas y predicción semanal (7 días)}
                            {--dry-run : Mostrar la coincidencia de productos, pronóstico y SKUs sin guardar cambios}
                            {--keep-previous : No eliminar la orden anterior generada para Compras USA}';

    protected $description = 'Crea una orden de compra consolidada para Compras USA vinculando a productos existentes y con predicción de demanda semanal';

    private const BRAND_MAP = [
        'PURITANS PRIDE' => "Puritan's Pride",
        'NATURES BOUNTY' => "Nature's Bounty",
        'NATURE MADE' => 'Nature Made',
        'GOLI NUTRITION' => 'Goli Nutrition',
        'LIFE EXTENSION' => 'Life Extension',
        'NATROL' => 'Natrol',
        'EQUATE' => 'Equate',
        'SIBERIAN GREEN' => 'Siberian Green',
        'KUKAMONGA' => 'Kukamonga',
        'NOW' => 'NOW Foods',
    ];

    /**
     * Marcas expresamente excluidas a petición del usuario.
     */
    private const EXCLUDED_BRANDS = [
        "Nature's Bounty",
        "Puritan's Pride",
        "Goli Nutrition",
        "Natrol",
        "Siberian Green",
    ];

    /**
     * Marcas de salón profesionales que JAMÁS deben mezclarse ni vincularse a compras de USA.
     */
    private const FOREIGN_SALON_BRANDS = [
        'LOREAL',
        "L'OREAL",
        "L'ORÉAL",
        'JOICO',
        'ALFAPARF',
        'SALERM',
        'WELLA',
        'SCHWARZKOPF',
        'LENDAN',
        'TEC ITALY',
        'FANOLA',
        'OLAPLEX',
        'ICON',
    ];

    public function handle(PurchaseOrderService $poService, InventoryDemandForecastingService $forecastingService): int
    {
        $this->info('=====================================================');
        $this->info('  GENERADOR DE ORDEN CONSOLIDADA SEMANAL - USA       ');
        $this->info('  (Basado en catálogo real y predicción de ventas)   ');
        $this->info('=====================================================');

        $csvPath = base_path('database/data/pedido_usa.csv');
        if (! file_exists($csvPath)) {
            $this->error("No se encontró el archivo {$csvPath}");
            return Command::FAILURE;
        }

        $includeDesired = (bool) $this->option('include-desired');
        $asOrdered = (bool) $this->option('as-ordered');
        $applyForecast = (bool) $this->option('apply-forecast');
        $dryRun = (bool) $this->option('dry-run');
        $keepPrevious = (bool) $this->option('keep-previous');

        if ($dryRun) {
            $this->warn('--- MODO SIMULACIÓN (DRY-RUN): No se guardarán cambios en la BD ---');
        }

        // 1. Limpieza de órdenes anteriores erróneas y productos provisionales "USA-..."
        if (! $dryRun && ! $keepPrevious) {
            $this->cleanPreviousUsaOrdersAndOrphanProducts();
        }

        // 2. Obtener o crear proveedor 'Compras USA'
        $supplier = Supplier::firstOrCreate(
            ['name' => 'Compras USA'],
            [
                'contact_name' => 'Encargado Compras USA',
                'notes' => 'Proveedor y logística para compras semanales en Estados Unidos (Walmart, Sam\'s, Amazon US, marcas directas)',
                'is_active' => true,
            ]
        );

        // 3. Sincronizar marcas permitidas asociadas al proveedor
        $supplierBrands = [
            'Spring Valley',
            'Life Extension',
            'Equate',
            'Native',
            'Starface',
            "Burt's Bees",
            'Nature Made',
            "O'Keeffe's",
            'Zep',
        ];
        if (! $dryRun) {
            $supplier->syncBrands($supplierBrands);

            // Asegurar configuración de cadencia semanal (7 días de cobertura + 3 lead time + 3 safety stock)
            foreach ($supplierBrands as $bName) {
                BrandRestockConfiguration::firstOrCreate(
                    ['brand' => $bName],
                    [
                        'supplier' => 'Compras USA',
                        'cadence_preset' => BrandRestockConfiguration::PRESET_WEEKLY_7_10,
                        'lead_time_days' => 3,
                        'target_coverage_days' => 7,
                        'safety_stock_days' => 3,
                        'notes' => 'Reabastecimiento semanal recurrente de compras en USA.',
                    ]
                );
            }
        }

        // 4. Ubicación de inventario
        $location = InventoryLocation::where('is_active', true)->orderBy('sort_order')->orderBy('id')->first();
        if (! $location) {
            $location = InventoryLocation::create([
                'code' => 'BOD-CENTRAL',
                'name' => 'Bodega Central SBS',
                'is_active' => true,
            ]);
        }

        // 5. Usuario comprador
        $buyer = User::where('role', User::ROLE_ADMIN)->first() ?: User::first();
        if (! $buyer) {
            $buyer = User::forceCreate([
                'name' => 'Admin Compras',
                'email' => 'compras@sbs.com',
                'role' => User::ROLE_ADMIN,
            ]);
        }

        // 6. Parsear CSV filtrando marcas excluidas
        $file = fopen($csvPath, 'r');
        $currentBrand = 'Spring Valley';
        $itemsToOrder = [];
        $excludedCount = 0;
        $skippedCount = 0;

        while (($r = fgetcsv($file)) !== false) {
            if (empty(array_filter($r))) {
                continue;
            }
            if (isset($r[2]) && str_contains(mb_strtolower((string) $r[2]), 'deseada')) {
                continue;
            }

            $name = isset($r[1]) ? trim((string) $r[1]) : '';
            $desiredStr = isset($r[2]) ? trim((string) $r[2]) : '';
            $pedidoStr = isset($r[4]) ? trim((string) $r[4]) : '';

            if ($name === '') {
                continue;
            }

            // Detectar encabezado de sección de marca
            $upperName = mb_strtoupper($name);
            if (isset(self::BRAND_MAP[$upperName])) {
                $currentBrand = self::BRAND_MAP[$upperName];
                continue;
            }

            // Detección de marca por contenido del nombre
            $brand = $currentBrand;
            if (str_contains($upperName, "O'KEEFFE")) {
                $brand = "O'Keeffe's";
            } elseif (str_contains($upperName, 'NATIVE')) {
                $brand = 'Native';
            } elseif (str_contains($upperName, 'STARFACE')) {
                $brand = 'Starface';
            } elseif (str_contains($upperName, "BURT'S BEES") || str_contains($upperName, 'BURTS BEES')) {
                $brand = "Burt's Bees";
            } elseif (str_contains($upperName, 'EUCERIN') || str_contains($upperName, 'AQUAPHOR')) {
                $brand = 'Eucerin';
            } elseif (str_contains($upperName, 'EQUATE')) {
                $brand = 'Equate';
            } elseif (str_contains($upperName, 'SPRING VALLEY')) {
                $brand = 'Spring Valley';
            } elseif (str_contains($upperName, 'ZEP')) {
                $brand = 'Zep';
            }

            // EXCLUSIÓN ESTRICTA DE LAS 5 MARCAS INDICADAS POR EL USUARIO
            if (in_array($brand, self::EXCLUDED_BRANDS, true)) {
                $excludedCount++;
                continue;
            }

            $pedidoQty = (is_numeric($pedidoStr) && (int) $pedidoStr > 0) ? (int) $pedidoStr : 0;
            $desiredQty = (is_numeric($desiredStr) && (int) $desiredStr > 0) ? (int) $desiredStr : 0;

            $qtyToOrder = $pedidoQty;
            if ($qtyToOrder <= 0 && $includeDesired && $desiredQty > 0) {
                $qtyToOrder = $desiredQty;
            }

            if ($qtyToOrder > 0) {
                $itemsToOrder[] = [
                    'brand' => $brand,
                    'name' => $name,
                    'quantity_ordered' => $qtyToOrder,
                    'quantity_desired' => $desiredQty,
                ];
            } else {
                $skippedCount++;
            }
        }
        fclose($file);

        $this->line("Marcas excluidas omitidas (Nature's Bounty, Puritan's Pride, Goli, Natrol, Siberian Green): <comment>{$excludedCount}</comment> filas");
        $this->line("Partidas válidas a procesar: <info>" . count($itemsToOrder) . "</info>");

        if (empty($itemsToOrder)) {
            $this->warn('No se encontraron partidas con cantidad mayor a cero para ordenar.');
            return Command::SUCCESS;
        }

        // 7. Búsqueda y vinculación a productos existentes en catálogo (respetando sus SKUs)
        $this->newLine();
        $this->info("Buscando coincidencias en catálogo de almacén y calculando predicción semanal...");

        $validatedItems = [];
        $usedProductIds = [];
        $totalUnits = 0;
        $matchedExistingCount = 0;
        $newCreatedCount = 0;

        foreach ($itemsToOrder as $entry) {
            $name = $entry['name'];
            $brand = $entry['brand'];
            $qty = $entry['quantity_ordered'];

            // Buscar producto existente estrictamente dentro de la marca correcta sin duplicar IDs ya asignados
            $product = $this->findExistingProduct($brand, $name, $usedProductIds);

            $forecastInfo = '';
            if ($product) {
                $usedProductIds[] = $product->id;
                $matchedExistingCount++;

                // Predicción semanal de ventas
                try {
                    $forecast = $forecastingService->getProductForecast($product, [
                        'cadence_preset' => BrandRestockConfiguration::PRESET_WEEKLY_7_10,
                        'target_coverage_days' => 7,
                        'lead_time_days' => 3,
                        'safety_stock_days' => 3,
                    ]);

                    $suggestedQty = (int) ($forecast['suggested_quantity'] ?? 0);
                    $sales30d = (int) ($forecast['sales_30d'] ?? 0);
                    $avail = (int) ($forecast['available_stock'] ?? 0);
                    $forecastInfo = " | Ventas 30d: {$sales30d} | Disp: {$avail} | Predicción semanal: {$suggestedQty} pzas";

                    if ($applyForecast && $suggestedQty > 0) {
                        $qty = $suggestedQty;
                    }
                } catch (Throwable) {
                    // Continuar si falla el cálculo individual de pronóstico
                }

                $this->line("  [CATÁLOGO] <info>SKU: {$product->sku}</info> | [{$product->brand}] {$product->name} (<comment>{$qty} pzas</comment>){$forecastInfo}");
            } else {
                // Producto no encontrado en catálogo existente
                if (! $dryRun) {
                    $sku = $this->generateUniqueSku($brand, $name);
                    $product = InventoryProduct::create([
                        'sku' => $sku,
                        'name' => $name,
                        'brand' => $brand,
                        'supplier' => 'Compras USA',
                        'product_type' => InventoryProduct::SIMPLE,
                        'cost' => 0.00,
                        'is_active' => true,
                        'primary_location_id' => $location->id,
                    ]);
                    $usedProductIds[] = $product->id;
                    $newCreatedCount++;
                    $this->line("  [NUEVO ALTA] SKU provisional: <comment>{$product->sku}</comment> | [{$brand}] {$name} ({$qty} pzas)");
                } else {
                    $newCreatedCount++;
                    $this->line("  [PENDIENTE] No encontrado en catálogo existente: [{$brand}] {$name} ({$qty} pzas)");
                    continue;
                }
            }

            // Consolidar cantidades si por alguna razón el producto ya estaba en la orden
            if (isset($validatedItems[$product->id])) {
                $validatedItems[$product->id]['quantity_ordered'] += $qty;
                $this->warn("  [CONSOLIDADO] Partida repetida sumada (+{$qty} pzas) a partida única: {$product->name}");
            } else {
                $validatedItems[$product->id] = [
                    'inventory_product_id' => $product->id,
                    'sku' => $product->sku, // SKU 100% ORIGINAL DEL PRODUCTO
                    'supplier_sku' => null,
                    'product_name' => $product->name,
                    'quantity_ordered' => $qty,
                    'quantity_received' => 0,
                    'unit_cost' => (float) ($product->cost ?? 0),
                    'subtotal' => 0.00,
                    'status' => PurchaseOrderItem::STATUS_PENDING,
                ];
            }

            $totalUnits += $qty;
        }

        $this->newLine();
        $this->info("Resumen de vinculación:");
        $this->line("  • Productos vinculados a catálogo existente (SKU conservado): <info>{$matchedExistingCount}</info>");
        $this->line("  • Productos no encontrados previamente: <comment>{$newCreatedCount}</comment>");

        if ($dryRun) {
            $this->info('--- FIN DE SIMULACIÓN (DRY-RUN) ---');
            return Command::SUCCESS;
        }

        // 8. Crear Orden de Compra en DB
        $order = DB::transaction(function () use ($poService, $supplier, $location, $buyer, $validatedItems, $totalUnits, $asOrdered) {
            $orderNumber = $poService->generateOrderNumber();

            $order = PurchaseOrder::create([
                'order_number' => $orderNumber,
                'supplier_name' => $supplier->name,
                'brand' => 'Suplementos / Compras USA',
                'status' => $asOrdered ? PurchaseOrder::STATUS_ORDERED : PurchaseOrder::STATUS_DRAFT,
                'inventory_location_id' => $location->id,
                'user_id' => $buyer->id,
                'ordered_at' => $asOrdered ? now() : null,
                'total_items_count' => count($validatedItems),
                'total_units_ordered' => $totalUnits,
                'total_units_received' => 0,
                'subtotal' => 0.00,
                'tax_amount' => 0.00,
                'shipping_cost' => 0.00,
                'total_cost' => 0.00,
                'supplier_quote_reference' => 'COMPRAS-USA-' . now()->format('Ymd'),
                'notes' => "Pedido consolidado semanal de pastillas, suplementos y productos de Estados Unidos. Vinculado a productos existentes con SKUs originales.",
            ]);

            foreach ($validatedItems as $vItem) {
                $order->items()->create($vItem);
            }

            return $order;
        });

        // 9. Exportar a Excel
        $this->exportOrderToExcel($order, array_values($validatedItems));

        $this->newLine();
        $this->info("=====================================================");
        $this->info("✓ NUEVA ORDEN DE COMPRA CREADA: {$order->order_number} (ID: {$order->id})");
        $this->info("  Proveedor: Compras USA");
        $this->info("  Estado: " . ($asOrdered ? "COLOCADA (En camino)" : "BORRADOR (Modificable en web)"));
        $this->info("  Total de partidas: " . count($validatedItems));
        $this->info("  Total de piezas pedidas: {$totalUnits} pzas");
        $this->info("  Almacén de destino: {$location->name} ({$location->code})");
        $this->info("=====================================================");
        $this->line("• Ver / Editar en sistema: " . url("/compras/ordenes/{$order->id}"));
        $this->line("• PDF Oficial de la orden: " . url("/compras/ordenes/{$order->id}/pdf"));
        $this->line("• Excel generado en: database/data/orden_compra_{$order->order_number}.xlsx");
        $this->newLine();

        return Command::SUCCESS;
    }

    /**
     * Limpia órdenes anteriores de Compras USA y los productos con prefijo USA- creados erróneamente.
     */
    private function cleanPreviousUsaOrdersAndOrphanProducts(): void
    {
        // 1. Buscar órdenes de Compras USA generadas automáticamente (incluidas órdenes 8 y 9)
        $previousOrders = PurchaseOrder::where('supplier_name', 'Compras USA')
            ->where(function ($q) {
                $q->where('supplier_quote_reference', 'like', 'COMPRAS-USA-%')
                  ->orWhere('order_number', 'like', 'OC-20261022-%')
                  ->orWhere('status', PurchaseOrder::STATUS_DRAFT);
            })
            ->get();

        foreach ($previousOrders as $prevOrder) {
            $this->warn("Limpiando orden previa: {$prevOrder->order_number} (ID: {$prevOrder->id})");
            $prevOrder->items()->delete();
            $prevOrder->delete();
        }

        // 2. Eliminar productos temporales creados con SKU "USA-..." que no tengan movimientos, pedidos ni ventas
        $orphans = InventoryProduct::where('sku', 'like', 'USA-%')
            ->whereDoesntHave('movements')
            ->whereDoesntHave('reservations')
            ->get();

        $orphansToDelete = $orphans->filter(function ($orphan) {
            $hasPoItems = Schema::hasTable('purchase_order_items')
                && DB::table('purchase_order_items')->where('inventory_product_id', $orphan->id)->exists();
            $hasPosItems = Schema::hasTable('pos_sale_items')
                && DB::table('pos_sale_items')->where('inventory_product_id', $orphan->id)->exists();

            return ! $hasPoItems && ! $hasPosItems;
        });

        if ($orphansToDelete->isNotEmpty()) {
            $this->warn("Eliminando {$orphansToDelete->count()} productos temporales creados anteriormente con prefijo 'USA-'...");
            foreach ($orphansToDelete as $orphan) {
                $orphan->delete();
            }
        }
    }

    /**
     * Búsqueda inteligente de productos en el catálogo existente.
     * NUNCA modifica el SKU existente del producto, excluye IDs ya asignados en esta orden
     * y RECHAZA estrictamente marcas de salón como L'Oreal o Joico.
     */
    private function findExistingProduct(string $brand, string $name, array $excludedIds = []): ?InventoryProduct
    {
        $cleanName = trim(preg_replace('/\s+/', ' ', $name));
        $lowerName = mb_strtolower($cleanName);

        // 1. Coincidencia exacta de nombre
        $prod = InventoryProduct::where('sku', 'not like', 'USA-%')
            ->where(function ($q) use ($lowerName) {
                $q->whereRaw('LOWER(TRIM(name)) = ?', [$lowerName])
                  ->orWhere('name', $lowerName);
            })
            ->when(! empty($excludedIds), fn ($q) => $q->whereNotIn('id', $excludedIds))
            ->first();
        if ($prod && ! $this->isForeignSalonBrand($prod->brand, $brand)) {
            return $prod;
        }

        // 2. Coincidencia con marca antepuesta: "$brand $name"
        $withBrand = mb_strtolower("{$brand} {$cleanName}");
        $prod = InventoryProduct::where('sku', 'not like', 'USA-%')
            ->where(function ($q) use ($withBrand) {
                $q->whereRaw('LOWER(TRIM(name)) = ?', [$withBrand])
                  ->orWhere('name', $withBrand);
            })
            ->when(! empty($excludedIds), fn ($q) => $q->whereNotIn('id', $excludedIds))
            ->first();
        if ($prod && ! $this->isForeignSalonBrand($prod->brand, $brand)) {
            return $prod;
        }

        // 3. Normalización y extracción de tokens numéricos y palabras clave
        $tokens = $this->extractProductTokens($cleanName);
        $numbers = $tokens['numbers']; // ej: [10000, 60]
        $words = $tokens['words'];     // ej: ['biotin']

        // Conteo de piezas/cápsulas común
        $packageCounts = array_values(array_filter($numbers, fn ($n) => in_array((int) $n, [30, 40, 50, 60, 90, 100, 120, 140, 150, 180, 200, 240, 250, 300, 400, 500], true)));

        // Obtener candidatos potenciales en la base de datos que pertenezcan a la MISMA marca
        $candidates = InventoryProduct::where('sku', 'not like', 'USA-%')
            ->where(function ($q) use ($brand) {
                $q->where('brand', 'like', "%{$brand}%")
                  ->orWhere('name', 'like', "%{$brand}%");
            })
            ->when(! empty($excludedIds), fn ($q) => $q->whereNotIn('id', $excludedIds))
            ->get()
            ->filter(fn ($p) => ! $this->isForeignSalonBrand($p->brand, $brand));

        $bestCandidate = null;
        $highestScore = 0;

        foreach ($candidates as $cand) {
            $candTokens = $this->extractProductTokens($cand->name . ' ' . ($cand->description ?? ''));
            $candNumbers = $candTokens['numbers'];
            $candWords = $candTokens['words'];
            $candSku = mb_strtolower($cand->sku);

            // Si ambos especifican un conteo de unidades/gomitas (ej. 60 vs 180) y son diferentes, NO vincular
            if (! empty($packageCounts)) {
                $candCounts = array_values(array_filter($candNumbers, fn ($n) => in_array((int) $n, [30, 40, 50, 60, 90, 100, 120, 140, 150, 180, 200, 240, 250, 300, 400, 500], true)));
                if (! empty($candCounts)) {
                    $intersection = array_intersect($packageCounts, $candCounts);
                    if (empty($intersection)) {
                        continue; // Presentación distinta (ej. 60 tabletas vs 180 tabletas), no es el mismo producto
                    }
                }
            }

            $score = 0;

            // Coincidencia de marca
            if (strcasecmp($cand->brand ?? '', $brand) === 0 || str_contains($candTokens['clean'], mb_strtolower($brand))) {
                $score += 30;
            }

            // Coincidencia de números (dosis y conteo de piezas)
            $matchedNumbers = 0;
            foreach ($numbers as $num) {
                if (in_array($num, $candNumbers, true) || str_contains($candSku, $num)) {
                    $score += 25;
                    $matchedNumbers++;
                }
            }

            // Coincidencia de palabras clave e ingredientes
            foreach ($words as $w) {
                if (in_array($w, $candWords, true) || str_contains($candSku, $w)) {
                    $score += 15;
                } else {
                    $synonyms = [
                        'biotin' => 'biotina',
                        'melatonin' => 'melatonina',
                        'curcumin' => 'curcuma',
                        'iron' => 'hierro',
                        'citrate' => 'citrato',
                        'salmon' => 'salmon',
                        'flaxseed' => 'linaza',
                        'garlic' => 'ajo',
                        'cranberry' => 'arandano',
                        'calcium' => 'calcio',
                        'magnesium' => 'magnesio',
                    ];
                    if (isset($synonyms[$w]) && in_array($synonyms[$w], $candWords, true)) {
                        $score += 15;
                    }
                }
            }

            // Penalización si no coincide ninguno de los números clave
            if (! empty($numbers) && $matchedNumbers === 0) {
                $score -= 25;
            }

            if ($score > $highestScore) {
                $highestScore = $score;
                $bestCandidate = $cand;
            }
        }

        // Umbral de coincidencia estricto
        if ($bestCandidate && $highestScore >= 45) {
            return $bestCandidate;
        }

        return null;
    }

    /**
     * Verifica si el producto candidato es de una marca salón ajena a USA (Loreal, Joico, Alfaparf, etc.).
     */
    private function isForeignSalonBrand(?string $candBrand, string $targetBrand): bool
    {
        if (! $candBrand) {
            return false;
        }
        $candUpper = mb_strtoupper(trim($candBrand));
        $targetUpper = mb_strtoupper(trim($targetBrand));

        if ($candUpper !== $targetUpper && in_array($candUpper, self::FOREIGN_SALON_BRANDS, true)) {
            return true;
        }

        return false;
    }

    /**
     * Normaliza una cadena y extrae números y palabras significativas.
     */
    private function extractProductTokens(string $str): array
    {
        $clean = mb_strtolower($str);
        $clean = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'], ['a', 'e', 'i', 'o', 'u', 'n', 'u'], $clean);
        // Remover comas dentro de números: 10,000 -> 10000
        $clean = preg_replace('/(?<=\d),(?=\d)/', '', $clean);
        // Separar letras de números: 10000mcg -> 10000 mcg, 60tabletas -> 60 tabletas
        $clean = preg_replace('/(\d+)([a-zA-Z]+)/', '$1 $2', $clean);
        $clean = preg_replace('/([a-zA-Z]+)(\d+)/', '$1 $2', $clean);
        // Reemplazar caracteres especiales por espacios
        $clean = preg_replace('/[^\w\s]/u', ' ', $clean);
        $clean = trim(preg_replace('/\s+/', ' ', $clean));

        preg_match_all('/\b\d+\b/', $clean, $numMatches);
        $numbers = array_values(array_unique($numMatches[0] ?? []));

        $words = explode(' ', $clean);
        $stopWords = ['de', 'la', 'el', 'y', 'en', 'por', 'con', 'para', 'del', 'los', 'las', 'mg', 'mcg', 'iu', 'ml', 'gr', 'ct', 'tabletas', 'tabs', 'capsulas', 'caps', 'softgels', 'gomitas', 'gummies', 'tablets', 'vegetarian', 'zero', 'sugar', 'sin', 'sabor'];
        $significant = array_values(array_unique(array_filter($words, fn ($w) => strlen($w) >= 3 && ! in_array($w, $stopWords, true) && ! is_numeric($w))));

        return [
            'numbers' => $numbers,
            'words' => $significant,
            'clean' => $clean,
        ];
    }

    private function generateUniqueSku(string $brand, string $name): string
    {
        $bClean = preg_replace('/[^A-Za-z0-9]/', '', mb_strtoupper($brand));
        $prefix = mb_substr($bClean, 0, 3) ?: 'USA';

        // Extraer palabras clave de nombre
        $words = preg_split('/[\s\-\/\,\.\(\)\+]+/', mb_strtoupper($name));
        $cleanWords = array_values(array_filter($words, fn ($w) => strlen($w) >= 2 && ! in_array($w, ['DE', 'LA', 'EL', 'Y', 'EN', 'POR', 'CON'], true)));
        $slug = implode('-', array_slice($cleanWords, 0, 3));

        $baseSku = "USA-{$prefix}-{$slug}";
        $candidate = mb_substr($baseSku, 0, 45);

        $seq = 1;
        while (InventoryProduct::where('sku', $candidate)->exists()) {
            $seq++;
            $candidate = mb_substr($baseSku, 0, 40) . "-{$seq}";
        }

        return $candidate;
    }

    private function exportOrderToExcel(PurchaseOrder $order, array $items): void
    {
        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Orden de Compra USA');

            // Encabezado
            $sheet->setCellValue('A1', 'T.O. THE BEAUTY SHOP - SALON & BARBER SUPPLY (SBS)');
            $sheet->setCellValue('A2', "ORDEN DE COMPRA CONSOLIDADA: {$order->order_number}");
            $sheet->setCellValue('A3', 'Proveedor: Compras USA | Fecha: ' . now()->format('d/m/Y'));

            // Columnas
            $headers = ['#', 'SKU Catálogo', 'Producto / Descripción', 'Cantidad Pedida'];
            $colLetter = 'A';
            foreach ($headers as $h) {
                $sheet->setCellValue("{$colLetter}5", $h);
                $colLetter++;
            }

            $rowIdx = 6;
            foreach ($items as $idx => $it) {
                $sheet->setCellValue("A{$rowIdx}", $idx + 1);
                $sheet->setCellValue("B{$rowIdx}", $it['sku'] ?? '');
                $sheet->setCellValue("C{$rowIdx}", $it['product_name']);
                $sheet->setCellValue("D{$rowIdx}", $it['quantity_ordered']);
                $rowIdx++;
            }

            $writer = new Xlsx($spreadsheet);
            $targetPath = base_path("database/data/orden_compra_{$order->order_number}.xlsx");
            $writer->save($targetPath);
        } catch (Throwable) {
            // Silently ignore if spreadsheet fails to export
        }
    }
}
