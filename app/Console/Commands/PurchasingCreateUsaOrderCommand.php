<?php

namespace App\Console\Commands;

use App\Models\InventoryLocation;
use App\Models\InventoryProduct;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderService;
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
                            {--dry-run : Mostrar la coincidencia de productos y SKUs sin guardar cambios}
                            {--keep-previous : No eliminar la orden anterior generada para Compras USA}';

    protected $description = 'Crea una orden de compra consolidada para Compras USA vinculando a productos existentes con sus SKUs reales de plataformas';

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

    public function handle(PurchaseOrderService $poService): int
    {
        $this->info('=====================================================');
        $this->info('  GENERADOR DE ORDEN DE COMPRA CONSOLIDADA - USA     ');
        $this->info('=====================================================');

        $csvPath = base_path('database/data/pedido_usa.csv');
        if (! file_exists($csvPath)) {
            $this->error("No se encontró el archivo {$csvPath}");
            return Command::FAILURE;
        }

        $includeDesired = (bool) $this->option('include-desired');
        $asOrdered = (bool) $this->option('as-ordered');
        $dryRun = (bool) $this->option('dry-run');
        $keepPrevious = (bool) $this->option('keep-previous');

        if ($dryRun) {
            $this->warn('--- MODO SIMULACIÓN (DRY-RUN): No se guardarán cambios en la BD ---');
        }

        // 1. Limpieza de orden anterior y productos provisionales "USA-..."
        if (! $dryRun && ! $keepPrevious) {
            $this->cleanPreviousUsaOrdersAndOrphanProducts();
        }

        // 2. Obtener o crear proveedor 'Compras USA'
        $supplier = Supplier::firstOrCreate(
            ['name' => 'Compras USA'],
            [
                'contact_name' => 'Encargado Compras USA',
                'notes' => 'Proveedor y logística para compras en Estados Unidos (Walmart, Sam\'s, Amazon US, marcas directas)',
                'is_active' => true,
            ]
        );

        // 3. Sincronizar marcas permitidas asociadas al proveedor (excluyendo las 5 marcas)
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
        $this->info("Buscando coincidencias en catálogo de almacén y vinculando productos...");

        $validatedItems = [];
        $totalUnits = 0;
        $matchedExistingCount = 0;
        $newCreatedCount = 0;

        foreach ($itemsToOrder as $entry) {
            $name = $entry['name'];
            $brand = $entry['brand'];
            $qty = $entry['quantity_ordered'];

            // Buscar producto existente por nombre exacto, tokens o catálogo
            $product = $this->findExistingProduct($brand, $name);

            if ($product) {
                $matchedExistingCount++;
                $this->line("  [CATÁLOGO] <info>SKU: {$product->sku}</info> | {$product->name} (<comment>{$qty} pzas</comment>)");
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
                    $newCreatedCount++;
                    $this->line("  [NUEVO ALTA] SKU provisional: <comment>{$product->sku}</comment> | {$name} ({$qty} pzas)");
                } else {
                    $newCreatedCount++;
                    $this->line("  [PENDIENTE] No encontrado en catálogo existente: {$name} ({$qty} pzas)");
                    continue;
                }
            }

            $validatedItems[] = [
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
                'notes' => "Pedido consolidado de pastillas, suplementos y productos de Estados Unidos. Vinculado a productos existentes de plataformas con SKUs originales.",
            ]);

            foreach ($validatedItems as $vItem) {
                $order->items()->create($vItem);
            }

            return $order;
        });

        // 9. Exportar a Excel
        $this->exportOrderToExcel($order, $validatedItems);

        $this->newLine();
        $this->info("=====================================================");
        $this->info("✓ NUEVA ORDEN DE COMPRA CREADA: {$order->order_number}");
        $this->info("  Proveedor: Compras USA");
        $this->info("  Estado: " . ($asOrdered ? "COLOCADA (En camino)" : "BORRADOR"));
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
     * Limpia la orden anterior errónea de Compras USA y los productos con prefijo USA- creados en esa ejecución.
     */
    private function cleanPreviousUsaOrdersAndOrphanProducts(): void
    {
        // 1. Buscar órdenes de Compras USA generadas automáticamente
        $previousOrders = PurchaseOrder::where('supplier_name', 'Compras USA')
            ->where(function ($q) {
                $q->where('supplier_quote_reference', 'like', 'COMPRAS-USA-%')
                  ->orWhere('order_number', 'OC-20261022-0001');
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
     * NUNCA modifica el SKU existente del producto.
     */
    private function findExistingProduct(string $brand, string $name): ?InventoryProduct
    {
        $cleanName = trim(preg_replace('/\s+/', ' ', $name));
        $lowerName = mb_strtolower($cleanName);

        // 1. Coincidencia exacta de nombre
        $prod = InventoryProduct::where('sku', 'not like', 'USA-%')
            ->where(function ($q) use ($lowerName) {
                $q->whereRaw('LOWER(TRIM(name)) = ?', [$lowerName])
                  ->orWhere('name', $lowerName);
            })
            ->first();
        if ($prod) {
            return $prod;
        }

        // 2. Coincidencia con marca antepuesta: "$brand $name"
        $withBrand = mb_strtolower("{$brand} {$cleanName}");
        $prod = InventoryProduct::where('sku', 'not like', 'USA-%')
            ->where(function ($q) use ($withBrand) {
                $q->whereRaw('LOWER(TRIM(name)) = ?', [$withBrand])
                  ->orWhere('name', $withBrand);
            })
            ->first();
        if ($prod) {
            return $prod;
        }

        // 3. Normalización y extracción de tokens numéricos y palabras clave
        $tokens = $this->extractProductTokens($cleanName);
        $numbers = $tokens['numbers']; // ej: [10000, 60]
        $words = $tokens['words'];     // ej: ['biotin']

        // Obtener candidatos potenciales en la base de datos
        $candidates = InventoryProduct::where('sku', 'not like', 'USA-%')
            ->where(function ($q) use ($brand, $words) {
                $q->where('brand', 'like', "%{$brand}%")
                  ->orWhere('name', 'like', "%{$brand}%");
                if (! empty($words[0])) {
                    $q->orWhere('name', 'like', "%{$words[0]}%");
                }
            })
            ->get();

        $bestCandidate = null;
        $highestScore = 0;

        foreach ($candidates as $cand) {
            $candTokens = $this->extractProductTokens($cand->name . ' ' . ($cand->description ?? ''));
            $candNumbers = $candTokens['numbers'];
            $candWords = $candTokens['words'];
            $candSku = mb_strtolower($cand->sku);

            $score = 0;

            // Coincidencia de marca
            if (strcasecmp($cand->brand ?? '', $brand) === 0 || str_contains($candTokens['clean'], mb_strtolower($brand))) {
                $score += 20;
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
                $score -= 20;
            }

            if ($score > $highestScore) {
                $highestScore = $score;
                $bestCandidate = $cand;
            }
        }

        // Umbral de coincidencia
        if ($bestCandidate && $highestScore >= 35) {
            return $bestCandidate;
        }

        // 4. Buscar en tabla products (catálogo de Mercado Libre) si existe
        if (Schema::hasTable('products') && ! empty($words[0])) {
            $mlProduct = DB::table('products')
                ->where('name', 'like', "%{$words[0]}%")
                ->when(! empty($numbers[0]), fn ($q) => $q->where('name', 'like', "%{$numbers[0]}%"))
                ->first();

            if ($mlProduct && ! empty($mlProduct->sku)) {
                $invProd = InventoryProduct::where('sku', $mlProduct->sku)->first();
                if ($invProd) {
                    return $invProd;
                }
            }
        }

        return null;
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
