<?php

namespace App\Console\Commands;

use App\Models\BrandRestockConfiguration;
use App\Models\InventoryLocation;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Purchasing\PurchaseOrderService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class PurchasingGenerateWellaOrderCommand extends Command
{
    protected $signature = 'purchasing:generate-wella-order
                            {--file= : Ruta al formato Excel de pedido de Wella Distribuidor}
                            {--cadence=semanal : Cadencia de reabastecimiento (semanal o bimestral)}
                            {--level=balanced : Nivel de pedido (balanced, full, conservative)}
                            {--register-po : Crear formalmente la orden de compra en la base de datos}
                            {--as-ordered : Si se registra en BD, marcarla como Colocada en vez de Borrador}';

    protected $description = 'Genera el pedido oficial de Wella / Beauty Art en Excel basado en rotación de ventas semanal o bimestral';

    public function handle(PurchaseOrderService $poService): int
    {
        $cadence = strtolower((string) $this->option('cadence'));
        $isWeekly = ($cadence === 'semanal' || $cadence === 'weekly');

        $this->info('========================================================================');
        if ($isWeekly) {
            $this->info('  GENERADOR DE PEDIDO SEMANAL WELLA / BEAUTY ART (7 A 10 DÍAS)        ');
            $this->info('  (Rotación histórica 3 meses + Proyección semanal + Promos semanales)  ');
        } else {
            $this->info('  GENERADOR DE PEDIDO BIMESTRAL WELLA / BEAUTY ART (NOV - DIC 2026)    ');
            $this->info('  (Rotación histórica 3 meses + Proyección temporada Q4 + Promos)      ');
        }
        $this->info('========================================================================');

        $templatePath = $this->option('file') ?: base_path('database/data/formato_pedido_wella_distribuidor.xlsx');
        if (! file_exists($templatePath)) {
            $this->error("No se encontró el archivo de plantilla en: {$templatePath}");
            return Command::FAILURE;
        }

        $level = strtolower((string) $this->option('level'));
        $shouldRegisterPo = (bool) $this->option('register-po');
        $asOrdered = (bool) $this->option('as-ordered');

        $this->line("Cargando formato de pedido: <comment>{$templatePath}</comment>");
        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        // 1. Datos del encabezado oficial
        $sheet->setCellValue('A3', 'T.O. THE BEAUTY SHOP (SBS)');
        $sheet->setCellValue('A4', 'CLI-SBS-2026');
        $sheet->setCellValue('A5', 'Beauty Art Asesor Comercial');
        $sheet->setCellValue('A6', 'Noroeste / Hermosillo, Sonora');
        $sheet->setCellValue('A7', 'Gerente Comercial Beauty Art');
        $sheet->setCellValue('A8', now()->format('d/m/Y'));
        $sheet->setCellValue('A9', $isWeekly ? 'Semanal / Transferencia' : 'Bimestral / Crédito Distribuidor');
        $sheet->setCellValue('A29', $isWeekly ? 'OC-WELLA-SEM-01' : 'OC-WELLA-202611');

        // Limpiar pedidos anteriores en columna F y G (partidas 32 a 578)
        for ($r = 32; $r <= 578; $r++) {
            $sheet->setCellValue('F' . $r, null);
            $sheet->setCellValue('G' . $r, null);
        }

        // Limpiar promociones de abajo
        for ($r = 580; $r <= 711; $r++) {
            $sheet->setCellValue('F' . $r, null);
            $sheet->setCellValue('G' . $r, null);
            $sheet->setCellValue('H' . $r, null);
        }

        // 2. Ventana de análisis de ventas históricas (últimos 85 días)
        $now = now();
        $threeMonthsAgo = $now->copy()->subDays(85)->startOfDay();

        $this->line("Ventana de análisis de ventas: <info>{$threeMonthsAgo->format('d/m/Y')} al {$now->format('d/m/Y')}</info> (85 días)");
        $this->line($isWeekly
            ? "Horizonte de proyección: <info>Semanal (7 a 10 días de inventario ágil)</info>"
            : "Horizonte de proyección: <info>Bimestral Nov + Dic (61 días)</info>");

        // 3. Procesar catálogo de productos en el formato
        $matchedCount = 0;
        $totalPiecesFact = 0;
        $orderedItemsForPo = [];

        for ($r = 32; $r <= 578; $r++) {
            $code = trim((string) $sheet->getCell('A' . $r)->getValue());
            $brand = trim((string) $sheet->getCell('B' . $r)->getValue());
            $status = trim((string) $sheet->getCell('C' . $r)->getValue());
            $name = trim((string) $sheet->getCell('D' . $r)->getValue());
            $price = (float) $sheet->getCell('E' . $r)->getValue();

            if ($code === '' || $name === '' || $status === 'FALTANTE') {
                continue;
            }

            $qty = $this->calculateQuantityToOrder($code, $brand, $name, $threeMonthsAgo, $now, $level, $isWeekly);

            if ($qty > 0) {
                $sheet->setCellValue('F' . $r, $qty);
                $matchedCount++;
                $totalPiecesFact += $qty;

                $orderedItemsForPo[] = [
                    'code' => $code,
                    'brand' => $brand,
                    'name' => $name,
                    'unit_cost' => $price,
                    'quantity_ordered' => $qty,
                ];
            }
        }

        $this->info("Partidas activadas con cantidad a ordenar: <info>{$matchedCount}</info> productos ({$totalPiecesFact} piezas facturadas).");

        // 4. Configurar promociones y regalos en Columna G
        $this->applyWellaPromotions($sheet, $isWeekly);

        // 5. Guardar archivo Excel resultante
        $outputFileName = $isWeekly ? 'pedido_wella_semanal_2026.xlsx' : 'pedido_wella_noviembre_diciembre_2026.xlsx';
        $outputPath = base_path('database/data/' . $outputFileName);
        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($outputPath);
        $this->info("✓ Archivo de pedido generado exitosamente en: <comment>{$outputPath}</comment>");

        // 6. Dar de alta / Sincronizar Proveedor 'Beauty Art (Wella)'
        $supplier = Supplier::firstOrCreate(
            ['name' => 'Beauty Art (Wella)'],
            [
                'contact_name' => 'Atención a Clientes Beauty Art',
                'notes' => 'Distribuidor oficial de Wella Company en México (Color Perfect, Koleston, Color Touch, Illumina, Shinefinity, Blondor, Invigo, Sebastian, SP Luxe, Ultimate Repair).',
                'is_active' => true,
            ]
        );

        $wellaBrands = [
            'Wella',
            'Color Perfect',
            'Koleston Perfect',
            'Color Touch',
            'Illumina Color',
            'Shinefinity',
            'Welloxon',
            'Blondor',
            'Wellaplex',
            'Wellastrate',
            'Invigo',
            'Wella Boutique',
            'Sebastian',
            'SP Luxe',
            'EIMI',
            'Ultimate Repair',
        ];
        $supplier->syncBrands($wellaBrands);

        // Configuración de reabastecimiento semanal
        foreach ($wellaBrands as $bName) {
            BrandRestockConfiguration::firstOrCreate(
                ['brand' => $bName],
                [
                    'supplier' => 'Beauty Art (Wella)',
                    'cadence_preset' => BrandRestockConfiguration::PRESET_WEEKLY_7_10,
                    'lead_time_days' => 3,
                    'target_coverage_days' => 7,
                    'safety_stock_days' => 3,
                    'notes' => 'Reabastecimiento semanal Wella / Beauty Art.',
                ]
            );
        }

        $this->info("✓ Proveedor '<info>{$supplier->name}</info>' registrado y vinculado con sus marcas oficiales y cadencia semanal.");

        // 7. Registro opcional de la Orden de Compra en BD
        if ($shouldRegisterPo && ! empty($orderedItemsForPo)) {
            $location = InventoryLocation::where('is_active', true)->orderBy('sort_order')->orderBy('id')->first();
            $buyer = User::where('role', User::ROLE_ADMIN)->first() ?: User::first();

            $order = DB::transaction(function () use ($poService, $supplier, $location, $buyer, $orderedItemsForPo, $totalPiecesFact, $asOrdered, $isWeekly) {
                $orderNumber = $poService->generateOrderNumber();

                $order = PurchaseOrder::create([
                    'order_number' => $orderNumber,
                    'supplier_name' => $supplier->name,
                    'brand' => 'Wella / Sebastian / Care',
                    'status' => $asOrdered ? PurchaseOrder::STATUS_ORDERED : PurchaseOrder::STATUS_DRAFT,
                    'inventory_location_id' => $location?->id,
                    'user_id' => $buyer?->id,
                    'ordered_at' => $asOrdered ? now() : null,
                    'total_items_count' => count($orderedItemsForPo),
                    'total_units_ordered' => $totalPiecesFact,
                    'total_units_received' => 0,
                    'subtotal' => 0.00,
                    'tax_amount' => 0.00,
                    'shipping_cost' => 0.00,
                    'total_cost' => 0.00,
                    'supplier_quote_reference' => $isWeekly ? 'BEAUTY-ART-SEM-' . now()->format('W') : 'BEAUTY-ART-NOV-DIC-2026',
                    'notes' => $isWeekly
                        ? 'Pedido de reabastecimiento semanal Wella / Beauty Art (7 días) basado en formato oficial distribuidor.'
                        : 'Pedido de reabastecimiento bimestral Wella / Beauty Art para Noviembre y Diciembre 2026.',
                ]);

                foreach ($orderedItemsForPo as $it) {
                    $prod = InventoryProduct::where('barcode', $it['code'])
                        ->orWhere('sku', $it['code'])
                        ->first();

                    PurchaseOrderItem::create([
                        'purchase_order_id' => $order->id,
                        'inventory_product_id' => $prod?->id,
                        'sku' => $prod?->sku ?: $it['code'],
                        'supplier_sku' => $it['code'],
                        'product_name' => $it['name'],
                        'quantity_ordered' => $it['quantity_ordered'],
                        'quantity_received' => 0,
                        'unit_cost' => $it['unit_cost'],
                        'subtotal' => round($it['quantity_ordered'] * $it['unit_cost'], 2),
                        'status' => PurchaseOrderItem::STATUS_PENDING,
                    ]);
                }

                return $order;
            });

            $this->info("✓ Orden de Compra creada formalmente en la plataforma: <info>{$order->order_number}</info> (ID: {$order->id})");
            $this->info("  Puedes verla y gestionarla en la ruta web: <comment>/compras/ordenes/{$order->id}</comment>");
        }

        return Command::SUCCESS;
    }

    private function calculateQuantityToOrder(string $code, string $brand, string $name, Carbon $from, Carbon $to, string $level, bool $isWeekly): int
    {
        $upperName = mb_strtoupper($name);

        // 1. Verificar si el producto existe y tiene historial de ventas reales en la BD
        $realSales = 0;
        $currentStock = 0;

        try {
            $product = InventoryProduct::where('barcode', $code)
                ->orWhere('sku', $code)
                ->orWhere('barcode_secondary', $code)
                ->first();

            if ($product) {
                $currentStock = (int) ($product->physical_stock ?? $product->physicalStock());

                if (Schema::hasTable('inventory_movements')) {
                    $localSales = (int) abs(InventoryMovement::where('inventory_product_id', $product->id)
                        ->where(function ($q) {
                            $q->where('type', InventoryMovement::SALE)->orWhere('quantity', '<', 0);
                        })
                        ->whereBetween('occurred_at', [$from, $to])
                        ->sum('quantity'));
                    $realSales += $localSales;
                }

                if (Schema::hasTable('meli_orders') && Schema::hasTable('meli_order_items')) {
                    $meliSales = (int) DB::table('meli_orders as o')
                        ->join('meli_order_items as i', 'i.meli_order_id', '=', 'o.id')
                        ->where(function ($q) use ($code, $product) {
                            $q->where('i.sku', $product->sku)
                              ->orWhere('i.sku', $code);
                        })
                        ->whereRaw("LOWER(COALESCE(o.status, '')) NOT IN ('cancelled', 'invalid')")
                        ->whereBetween('o.created_at', [$from, $to])
                        ->sum('i.quantity');
                    $realSales += $meliSales;
                }
            }
        } catch (Throwable) {
            // Continuar con estimación paramétrica
        }

        // Si tenemos ventas reales en DB:
        if ($realSales > 0) {
            $dailyVelocity = $realSales / 85.0;
            $horizonDays = $isWeekly ? 7 : 61;
            $seasonalFactor = $isWeekly ? 1.15 : 1.30;

            $projectedDemand = ceil($dailyVelocity * $horizonDays * $seasonalFactor);
            $netToOrder = max(0, $projectedDemand - $currentStock);

            if ($netToOrder > 0) {
                return (int) $netToOrder;
            }
        }

        // 2. Modelo paramétrico calibrado
        if ($isWeekly) {
            // === MODO SEMANAL (7 a 10 días) ===
            if ($brand === 'COLOR PERFECT' || $brand === 'KOLESTON PERFECT') {
                if (preg_match('/\b(2\/0|3\/0|4\/0|5\/0|6\/0|7\/0|8\/0|9\/0|10\/0)\b/', $upperName)) {
                    return 4;
                }
                if (preg_match('/\b(44\/0|55\/0|66\/0|77\/0|88\/0)\b/', $upperName)) {
                    return 3;
                }
                if (preg_match('/\b(6\/1|7\/1|7\/11|7\/18|8\/1|9\/1|9\/11|9\/16|9\/8|9\/81|10\/1|10\/86|12\/0|12\/1|12\/11|12\/89)\b/', $upperName)) {
                    return 3;
                }
                if (preg_match('/\b(4\/77|5\/7|5\/71|5\/77|6\/7|6\/71|6\/77|7\/7|7\/71|7\/77|8\/7|8\/71)\b/', $upperName)) {
                    return 2;
                }
                if (preg_match('/\b(0\/11|0\/22|0\/33|0\/44|0\/66|0\/88|00\/00)\b/', $upperName)) {
                    return 2;
                }
                if (preg_match('/\b(5\/3|6\/3|7\/3|8\/3|9\/3)\b/', $upperName)) {
                    return 1;
                }
                return 0; // Evitar stock muerto semanal en tonos secundarios
            }

            if ($brand === 'COLOR TOUCH') {
                if (preg_match('/\b(9\/16|9\/01|9\/03|9\/86|9\/96|9\/97|10\/0|10\/6|10\/73|8\/81|8\/71|7\/89|7\/86|6\/71|5\/71)\b/', $upperName)) {
                    return 4;
                }
                if (preg_match('/\b(2\/0|3\/0|4\/0|5\/0|6\/0|7\/0|8\/0|9\/0)\b/', $upperName)) {
                    return 3;
                }
                if (preg_match('/\b(55\/03|55\/07|66\/07|77\/07|88\/07)\b/', $upperName)) {
                    return 2;
                }
                if (preg_match('/\b(6\/7|6\/77|7\/7|7\/71|7\/73|8\/73)\b/', $upperName)) {
                    return 1;
                }
                return 0;
            }

            if ($brand === 'ILLUMINA COLOR') {
                if (preg_match('/\b(10\/|10\/1|10\/36|10\/38|10\/69|10\/81|9\/|9\/60|9\/19|8\/|8\/1|8\/69|7\/|7\/81|6\/|5\/)\b/', $upperName)) {
                    return 2;
                }
                return 0;
            }

            if ($brand === 'SHINEFINITY') {
                if (str_contains($upperName, 'CRYSTAL GLAZE') || str_contains($upperName, '500ML')) {
                    return 2;
                }
                if (preg_match('/\b(09\/61|09\/81|09\/13|09\/02|08\/98|08\/8|07\/81|06\/71|04\/07)\b/', $upperName)) {
                    return 2;
                }
                return 0;
            }

            if ($brand === 'WELLOXON') {
                if (str_contains($upperName, '20 VOL') && str_contains($upperName, '1000')) {
                    return 14; // 2 x 7 = 14 pzas -> 2 de regalo
                }
                if (str_contains($upperName, '30 VOL') && str_contains($upperName, '1000')) {
                    return 7; // 1 x 7 -> 1 de regalo
                }
                if (str_contains($upperName, '40 VOL') && str_contains($upperName, '1000') || str_contains($upperName, '1.9%') || str_contains($upperName, '3%')) {
                    return 3;
                }
                if (str_contains($upperName, '3700') || str_contains($upperName, 'GALÓN') || str_contains($upperName, 'GALON') || str_contains($upperName, '20V 6')) {
                    return 2;
                }
                return 0;
            }

            if ($brand === 'EMULSIÓN') {
                if (str_contains($upperName, '1.9%') && str_contains($upperName, '1000')) {
                    return 7; // 1 x 7 -> 1 de regalo
                }
                if (str_contains($upperName, '4%') && str_contains($upperName, '1000')) {
                    return 4;
                }
                return 1;
            }

            if ($brand === 'ACTIVADOR SF') {
                return str_contains($upperName, 'BROCHA') ? 4 : 3;
            }

            if (str_starts_with($brand, 'BLONDOR')) {
                if ($brand === 'BLONDOR MB 800') {
                    return 6;
                }
                if ($brand === 'BLONDORPLEX 800') {
                    return 4;
                }
                if ($brand === 'BLONDOR MB 400') {
                    return 2;
                }
            }

            if ($brand === 'WELLAPLEX') {
                return 1;
            }

            if ($brand === 'WELLASTRATE') {
                return 2;
            }

            if ($brand === 'INVIGO') {
                if (str_contains($upperName, 'SH 1000')) {
                    return 2;
                }
                if (str_contains($upperName, 'SH 250')) {
                    return 4;
                }
                if (str_contains($upperName, 'MASK 500')) {
                    return 2;
                }
                if (str_contains($upperName, 'MASK 150')) {
                    return 3;
                }
                return 2;
            }

            if (str_starts_with($brand, 'WELLA Boutique')) {
                if (str_contains($upperName, 'OIL REF 100 ML SUAVIZANTE') || str_contains($upperName, 'OIL REFLECTIONS SUAVIZANTE') || $code === '4015400793045') {
                    return 6;
                }
                if (str_contains($upperName, 'OIL REFLECTIONS LIGHT 100ML') || $code === '4015400793182') {
                    return 3;
                }
                if (str_contains($upperName, 'OIL REFLECTIONS ACEITE 30 ML') || $code === '81642883') {
                    return 6;
                }
                if (str_contains($upperName, 'SHAMPOO 250ML') || str_contains($upperName, 'MASK 150ML')) {
                    return 3;
                }
                if (str_contains($upperName, 'SHAMPOO 1000') || str_contains($upperName, 'MASK 500')) {
                    return 2;
                }
                return 1;
            }

            if ($brand === 'Ultimate Repair') {
                if (str_contains($upperName, 'MIRACLE SERUM') || $code === '4064666580050' || $code === '4064666580005') {
                    return 3;
                }
                if (str_contains($upperName, 'SHAMPOO 250') || str_contains($upperName, 'ACONDICIONADOR 200') || str_contains($upperName, 'MASK 150')) {
                    return 3;
                }
                if (str_contains($upperName, 'SHAMPOO 1L') || str_contains($upperName, 'ACONDICIONADOR 500') || str_contains($upperName, 'MASK 500')) {
                    return 2;
                }
                return 2;
            }

            if ($brand === 'EIMI') {
                return str_contains($upperName, 'THERMAL IMAGE') ? 3 : 2;
            }

            if ($brand === 'SP') {
                return str_contains($upperName, 'LUXE OIL') ? 4 : 2;
            }

            if ($brand === 'SEBASTIAN') {
                if (str_contains($upperName, 'DARK OIL ACEITE') && ! str_contains($upperName, '30 ML') || $code === '4084500999206') {
                    return 6;
                }
                if (str_contains($upperName, 'DARK OIL ACEITE 30 ML') || $code === '99350045971') {
                    return 4;
                }
                if (str_contains($upperName, 'PENETRAITT MASK 145') || str_contains($upperName, 'PENETRAITT SHAMPOO 250')) {
                    return 4;
                }
                if (str_contains($upperName, 'NO BREAKER 100 ML') || str_contains($upperName, 'POTION 9 150 ML')) {
                    return 3;
                }
                if (str_contains($upperName, 'SHAMPOO 1000') || str_contains($upperName, 'MASK 500')) {
                    return 2;
                }
                return 2;
            }

            return 0;
        }

        // === MODO BIMESTRAL (61 días) ===
        if ($brand === 'COLOR PERFECT' || $brand === 'KOLESTON PERFECT') {
            if (preg_match('/\b(2\/0|3\/0|4\/0|5\/0|6\/0|7\/0|8\/0|9\/0|10\/0)\b/', $upperName)) {
                return 14;
            }
            if (preg_match('/\b(44\/0|55\/0|66\/0|77\/0|88\/0)\b/', $upperName)) {
                return 10;
            }
            if (preg_match('/\b(6\/1|7\/1|7\/11|7\/18|8\/1|9\/1|9\/11|9\/16|9\/8|9\/81|10\/1|10\/86|12\/0|12\/1|12\/11|12\/89)\b/', $upperName)) {
                return 12;
            }
            if (preg_match('/\b(4\/77|5\/7|5\/71|5\/77|6\/7|6\/71|6\/77|7\/7|7\/71|7\/77|8\/7|8\/71)\b/', $upperName)) {
                return 6;
            }
            if (preg_match('/\b(0\/11|0\/22|0\/33|0\/44|0\/66|0\/88|00\/00)\b/', $upperName)) {
                return 6;
            }
            return 2;
        }

        if ($brand === 'COLOR TOUCH') {
            if (preg_match('/\b(9\/16|9\/01|9\/03|9\/86|9\/96|9\/97|10\/0|10\/6|10\/73|8\/81|8\/71|7\/89|7\/86|6\/71|5\/71)\b/', $upperName)) {
                return 14;
            }
            if (preg_match('/\b(2\/0|3\/0|4\/0|5\/0|6\/0|7\/0|8\/0|9\/0)\b/', $upperName)) {
                return 10;
            }
            if (preg_match('/\b(55\/03|55\/07|66\/07|77\/07|88\/07)\b/', $upperName)) {
                return 6;
            }
            return 2;
        }

        if ($brand === 'ILLUMINA COLOR') {
            if (preg_match('/\b(10\/|10\/1|10\/36|10\/38|10\/69|10\/81|9\/|9\/60|9\/19|8\/|8\/1|8\/69|7\/|7\/81|6\/|5\/)\b/', $upperName)) {
                return 8;
            }
            return 2;
        }

        if ($brand === 'SHINEFINITY') {
            if (str_contains($upperName, 'CRYSTAL GLAZE') || str_contains($upperName, '500ML')) {
                return 6;
            }
            if (preg_match('/\b(09\/61|09\/81|09\/13|09\/02|08\/98|08\/8|07\/81|06\/71|04\/07)\b/', $upperName)) {
                return 8;
            }
            return 2;
        }

        if ($brand === 'WELLOXON') {
            if (str_contains($upperName, '20 VOL') && str_contains($upperName, '1000')) {
                return 28;
            }
            if (str_contains($upperName, '30 VOL') && str_contains($upperName, '1000')) {
                return 21;
            }
            return 7;
        }

        if ($brand === 'EMULSIÓN') {
            return str_contains($upperName, '1.9%') && str_contains($upperName, '1000') ? 21 : 14;
        }

        if (str_starts_with($brand, 'BLONDOR')) {
            return ($brand === 'BLONDOR MB 800') ? 48 : 16;
        }

        return 4;
    }

    private function applyWellaPromotions($sheet, bool $isWeekly): void
    {
        if ($isWeekly) {
            // Reveladores 7 + 1 semanal:
            $sheet->setCellValue('G366', 2); // 2 Welloxon 20V 1000ml gratis
            $sheet->setCellValue('G367', 1); // 1 Welloxon 30V 1000ml gratis
            $sheet->setCellValue('G373', 1); // 1 Emulsión CT 1.9% gratis

            // Promo color $18,000 (15 tubos gratis + 15 toallas negras):
            $sheet->setCellValue('H586', 1);
            $sheet->setCellValue('G588', 15); // 15 toallas negras
            $sheet->setCellValue('G32', 2);   // 2 tubos CP 2/0 Negro
            $sheet->setCellValue('G37', 2);   // 2 tubos CP 5/0
            $sheet->setCellValue('G48', 2);   // 2 tubos CP 6/0
            $sheet->setCellValue('G50', 2);   // 2 tubos CP 6/1
            $sheet->setCellValue('G63', 2);   // 2 tubos CP 8/1
            $sheet->setCellValue('G73', 2);   // 2 tubos CP 9/16
            $sheet->setCellValue('G244', 3);  // 3 tubos CT 9/16
        } else {
            // Promociones bimestrales completas
            $sheet->setCellValue('G366', 5);
            $sheet->setCellValue('G367', 4);
            $sheet->setCellValue('G368', 2);
            $sheet->setCellValue('G369', 2);
            $sheet->setCellValue('G373', 3);
            $sheet->setCellValue('G374', 2);
            $sheet->setCellValue('G378', 2);

            $sheet->setCellValue('H628', 1);
            $sheet->setCellValue('G629', 7);

            $sheet->setCellValue('H591', 1);
            $sheet->setCellValue('G593', 25);
            $sheet->setCellValue('G32', 3);
            $sheet->setCellValue('G37', 3);
            $sheet->setCellValue('G48', 3);
            $sheet->setCellValue('G50', 3);
            $sheet->setCellValue('G63', 3);
            $sheet->setCellValue('G73', 4);
            $sheet->setCellValue('G244', 3);
            $sheet->setCellValue('G247', 3);

            $sheet->setCellValue('H647', 1);
            $sheet->setCellValue('G648', 4);
            $sheet->setCellValue('G650', 8);
            $sheet->setCellValue('G651', 8);

            $sheet->setCellValue('H697', 1);
            $sheet->setCellValue('G698', 1);

            $sheet->setCellValue('H693', 1);
            $sheet->setCellValue('G694', 3);
            $sheet->setCellValue('G695', 15);
        }
    }
}
