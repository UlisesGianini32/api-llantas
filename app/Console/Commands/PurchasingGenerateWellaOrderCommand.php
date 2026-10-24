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
                            {--level=balanced : Nivel de pedido (balanced, full, conservative)}
                            {--register-po : Crear formalmente la orden de compra en la base de datos}
                            {--as-ordered : Si se registra en BD, marcarla como Colocada en vez de Borrador}';

    protected $description = 'Genera el pedido oficial de Wella / Beauty Art en Excel basado en rotación de los últimos 3 meses y predicción para Noviembre y Diciembre';

    public function handle(PurchaseOrderService $poService): int
    {
        $this->info('========================================================================');
        $this->info('  GENERADOR DE PEDIDO OFICIAL WELLA / BEAUTY ART (NOV - DIC 2026)      ');
        $this->info('  (Rotación histórica 3 meses + Proyección de temporada Q4 + Promos)   ');
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
        $sheet->setCellValue('A9', 'Transferencia / Crédito Distribuidor');
        $sheet->setCellValue('A29', 'OC-WELLA-202611');

        // Limpiar pedidos anteriores en columna F y G (partidas 32 a 578)
        for ($r = 32; $r <= 578; $r++) {
            $sheet->setCellValue('F' . $r, null);
            $sheet->setCellValue('G' . $r, null);
        }

        // 2. Ventana de análisis de ventas históricas (últimos ~85-90 días: 1 agosto a hoy)
        $now = now();
        $threeMonthsAgo = $now->copy()->subDays(85)->startOfDay();

        $this->line("Ventana de análisis de ventas: <info>{$threeMonthsAgo->format('d/m/Y')} al {$now->format('d/m/Y')}</info> (85 días)");
        $this->line("Horizonte de proyección de demanda: <info>Noviembre + Diciembre (61 días)</info> con factor estacional de temporada alta.");

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

            // Consultar ventas reales en base de datos si existe el producto
            $qty = $this->calculateQuantityToOrder($code, $brand, $name, $threeMonthsAgo, $now, $level);

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
        $this->applyWellaPromotions($sheet);

        // 5. Guardar archivo Excel resultante
        $outputPath = base_path('database/data/pedido_wella_noviembre_diciembre_2026.xlsx');
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
        $this->info("✓ Proveedor '<info>{$supplier->name}</info>' registrado y vinculado con sus marcas oficiales.");

        // 7. Registro opcional de la Orden de Compra en BD
        if ($shouldRegisterPo && ! empty($orderedItemsForPo)) {
            $location = InventoryLocation::where('is_active', true)->orderBy('sort_order')->orderBy('id')->first();
            $buyer = User::where('role', User::ROLE_ADMIN)->first() ?: User::first();

            $order = DB::transaction(function () use ($poService, $supplier, $location, $buyer, $orderedItemsForPo, $totalPiecesFact, $asOrdered) {
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
                    'supplier_quote_reference' => 'BEAUTY-ART-NOV-DIC-2026',
                    'notes' => 'Pedido de reabastecimiento bimestral Wella / Beauty Art para Noviembre y Diciembre 2026 basado en formato oficial distribuidor.',
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

    private function calculateQuantityToOrder(string $code, string $brand, string $name, Carbon $from, Carbon $to, string $level): int
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
                // Stock actual en almacén local
                $currentStock = (int) ($product->physical_stock ?? $product->physicalStock());

                // Ventas en almacén físico / mostrador POS en el período
                if (Schema::hasTable('inventory_movements')) {
                    $localSales = (int) abs(InventoryMovement::where('inventory_product_id', $product->id)
                        ->where(function ($q) {
                            $q->where('type', InventoryMovement::SALE)->orWhere('quantity', '<', 0);
                        })
                        ->whereBetween('occurred_at', [$from, $to])
                        ->sum('quantity'));
                    $realSales += $localSales;
                }

                // Ventas despachadas en Mercado Libre FULL
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
            // Continuar con estimación paramétrica si falla consulta de ventas
        }

        // Si tenemos ventas reales constatadas en los últimos 85 días:
        if ($realSales > 0) {
            $dailyVelocity = $realSales / 85.0;

            // Factor de estacionalidad Q4 (Noviembre Buen Fin + Diciembre Fiestas Navideñas):
            // Salones y reventa suben entre 30% y 35%
            $seasonalFactor = 1.30;
            if (str_contains($brand, 'Ultimate') || str_contains($brand, 'Boutique') || str_contains($brand, 'SEBASTIAN')) {
                $seasonalFactor = 1.35;
            }

            $projectedDemand61d = ceil($dailyVelocity * 61 * $seasonalFactor);
            $netToOrder = max(0, $projectedDemand61d - $currentStock);

            if ($netToOrder > 0) {
                return (int) $netToOrder;
            }
        }

        // 2. Modelo paramétrico calibrado para salón profesional (en caso de no tener historial en DB)
        // Tintes Color Perfect / Koleston Perfect
        if ($brand === 'COLOR PERFECT' || $brand === 'KOLESTON PERFECT') {
            if (preg_match('/\b(2\/0|3\/0|4\/0|5\/0|6\/0|7\/0|8\/0|9\/0|10\/0)\b/', $upperName)) {
                return ($level === 'full') ? 18 : (($level === 'conservative') ? 8 : 14);
            }
            if (preg_match('/\b(44\/0|55\/0|66\/0|77\/0|88\/0)\b/', $upperName)) {
                return ($level === 'full') ? 14 : (($level === 'conservative') ? 6 : 10);
            }
            if (preg_match('/\b(6\/1|7\/1|7\/11|7\/18|8\/1|9\/1|9\/11|9\/16|9\/8|9\/81|10\/1|10\/86|12\/0|12\/1|12\/11|12\/89)\b/', $upperName)) {
                return ($level === 'full') ? 14 : (($level === 'conservative') ? 6 : 12);
            }
            if (preg_match('/\b(4\/77|5\/7|5\/71|5\/77|6\/7|6\/71|6\/77|7\/7|7\/71|7\/77|8\/7|8\/71)\b/', $upperName)) {
                return ($level === 'full') ? 8 : (($level === 'conservative') ? 4 : 6);
            }
            if (preg_match('/\b(0\/11|0\/22|0\/33|0\/44|0\/66|0\/88|00\/00)\b/', $upperName)) {
                return ($level === 'full') ? 8 : (($level === 'conservative') ? 3 : 6);
            }
            return ($level === 'full') ? 4 : (($level === 'conservative') ? 0 : 2);
        }

        // Color Touch (Matizadores demipermanentes de balayage)
        if ($brand === 'COLOR TOUCH') {
            if (preg_match('/\b(9\/16|9\/01|9\/03|9\/86|9\/96|9\/97|10\/0|10\/6|10\/73|8\/81|8\/71|7\/89|7\/86|6\/71|5\/71)\b/', $upperName)) {
                return ($level === 'full') ? 16 : (($level === 'conservative') ? 8 : 14);
            }
            if (preg_match('/\b(2\/0|3\/0|4\/0|5\/0|6\/0|7\/0|8\/0|9\/0)\b/', $upperName)) {
                return ($level === 'full') ? 12 : (($level === 'conservative') ? 6 : 10);
            }
            if (preg_match('/\b(55\/03|55\/07|66\/07|77\/07|88\/07)\b/', $upperName)) {
                return ($level === 'full') ? 8 : (($level === 'conservative') ? 4 : 6);
            }
            return ($level === 'full') ? 4 : (($level === 'conservative') ? 0 : 2);
        }

        // Illumina Color
        if ($brand === 'ILLUMINA COLOR') {
            if (preg_match('/\b(10\/|10\/1|10\/36|10\/38|10\/69|10\/81|9\/|9\/60|9\/19|9\/03|8\/|8\/1|8\/69|8\/38|7\/|7\/81|6\/|5\/)\b/', $upperName)) {
                return ($level === 'full') ? 10 : (($level === 'conservative') ? 4 : 8);
            }
            return ($level === 'full') ? 4 : (($level === 'conservative') ? 0 : 2);
        }

        // Shinefinity
        if ($brand === 'SHINEFINITY') {
            if (str_contains($upperName, 'CRYSTAL GLAZE') || str_contains($upperName, '500ML')) {
                return 6;
            }
            if (preg_match('/\b(09\/61|09\/81|09\/13|09\/02|08\/98|08\/8|07\/81|06\/71|04\/07)\b/', $upperName)) {
                return ($level === 'full') ? 10 : (($level === 'conservative') ? 4 : 8);
            }
            return ($level === 'full') ? 4 : (($level === 'conservative') ? 0 : 2);
        }

        // Peróxidos y Reveladores (Múltiplos de 7 para asegurar promo 7+1)
        if ($brand === 'WELLOXON') {
            if (str_contains($upperName, '20 VOL') && str_contains($upperName, '1000')) {
                return ($level === 'full') ? 35 : (($level === 'conservative') ? 14 : 28);
            }
            if (str_contains($upperName, '30 VOL') && str_contains($upperName, '1000')) {
                return ($level === 'full') ? 28 : (($level === 'conservative') ? 14 : 21);
            }
            if (str_contains($upperName, '40 VOL') && str_contains($upperName, '1000')) {
                return 14;
            }
            if (str_contains($upperName, '1.9%') || str_contains($upperName, '3%')) {
                return 14;
            }
            if (str_contains($upperName, '3700') || str_contains($upperName, 'GALÓN') || str_contains($upperName, 'GALON') || str_contains($upperName, '20V 6')) {
                return 7;
            }
            return 7;
        }

        if ($brand === 'EMULSIÓN') {
            if (str_contains($upperName, '1.9%') && str_contains($upperName, '1000')) {
                return 21;
            }
            if (str_contains($upperName, '4%') && str_contains($upperName, '1000')) {
                return 14;
            }
            if (str_contains($upperName, '3700')) {
                return 4;
            }
            return 7;
        }

        if ($brand === 'ACTIVADOR SF') {
            return str_contains($upperName, 'BROCHA') ? 14 : 7;
        }

        if ($brand === 'REVELADOR FL') {
            return str_contains($upperName, '40 VOL') ? 7 : 0;
        }

        // Decolorantes
        if (str_starts_with($brand, 'BLONDOR')) {
            if ($brand === 'BLONDOR MB 800') {
                return ($level === 'conservative') ? 24 : 48; // 48 aprovecha precio especial de $565
            }
            if ($brand === 'BLONDORPLEX 800') {
                return ($level === 'conservative') ? 16 : 32; // 32 aprovecha precio especial de $805
            }
            if ($brand === 'BLONDOR MB 400') {
                return 12;
            }
        }

        // Wellaplex
        if ($brand === 'WELLAPLEX') {
            return str_contains($upperName, 'PASO NO. 1') ? 2 : 4;
        }

        // Wellastrate
        if ($brand === 'WELLASTRATE') {
            return str_contains($upperName, 'INTENSA') || str_contains($upperName, 'NEUTRALIZANTE') ? 6 : 3;
        }

        // Care - Invigo
        if ($brand === 'INVIGO') {
            if (str_contains($upperName, 'BRIILIANCE SH 1000') || str_contains($upperName, 'BRILLIANCE SH 1000') || str_contains($upperName, 'NUTRI-ENRICH SH 1000')) {
                return 8;
            }
            if (str_contains($upperName, 'BRIILIANCE SH 250') || str_contains($upperName, 'BRILLIANCE SH 250') || str_contains($upperName, 'NUTRI-ENRICH SH 250')) {
                return 16;
            }
            if (str_contains($upperName, 'MASK 500')) {
                return 6;
            }
            if (str_contains($upperName, 'MASK 150')) {
                return 12;
            }
            if (str_contains($upperName, 'BLONDE RECHARGE')) {
                return 8;
            }
            return 4;
        }

        // Care - Boutique (Oil Reflections / Fusion)
        if (str_starts_with($brand, 'WELLA Boutique')) {
            if (str_contains($upperName, 'OIL REF 100 ML SUAVIZANTE') || str_contains($upperName, 'OIL REFLECTIONS SUAVIZANTE') || $code === '4015400793045') {
                return ($level === 'full') ? 36 : 24; // Best seller
            }
            if (str_contains($upperName, 'OIL REFLECTIONS LIGHT 100ML') || $code === '4015400793182') {
                return 12;
            }
            if (str_contains($upperName, 'OIL REFLECTIONS ACEITE 30 ML') || $code === '81642883') {
                return 24;
            }
            if (str_contains($upperName, 'OIL REFLECTIONS SHAMPOO 250ML')) {
                return 16;
            }
            if (str_contains($upperName, 'OIL REFLECTIONS MASK 150ML')) {
                return 12;
            }
            if (str_contains($upperName, 'FUSION SHAMPOO 1000')) {
                return 8;
            }
            if (str_contains($upperName, 'FUSION SHAMPOO 250') || str_contains($upperName, 'FUSION MASK 150')) {
                return 16;
            }
            if (str_contains($upperName, 'FUSION MASK 500')) {
                return 8;
            }
            if (str_contains($upperName, 'COLORMO') || str_contains($upperName, 'NUTRICURLS') || str_contains($upperName, 'ELEMENTS')) {
                return 4;
            }
            return 4;
        }

        // Ultimate Repair
        if ($brand === 'Ultimate Repair') {
            if (str_contains($upperName, 'MIRACLE SERUM 95 ML') || $code === '4064666580050') {
                return 14;
            }
            if (str_contains($upperName, 'MIRACLE SERUM 30 ML') || $code === '4064666580005') {
                return 14;
            }
            if (str_contains($upperName, 'SHAMPOO 1L') || $code === '4064666579931') {
                return 8;
            }
            if (str_contains($upperName, 'SHAMPOO 250 ML') || $code === '4064666579924') {
                return 16;
            }
            if (str_contains($upperName, 'ACONDICIONADOR 500 ML') || $code === '4064666336206') {
                return 8;
            }
            if (str_contains($upperName, 'ACONDICIONADOR 200 ML') || $code === '4064666336169') {
                return 12;
            }
            if (str_contains($upperName, 'MASK 500 ML') || $code === '4064666599526') {
                return 8;
            }
            if (str_contains($upperName, 'MASK 150 ML') || $code === '4064666599533') {
                return 12;
            }
            if (str_contains($upperName, 'LEAVE IN PASO 4') || $code === '99350187667') {
                return 12;
            }
            return 6;
        }

        if ($brand === 'Ultimate Color') {
            return str_contains($upperName, 'SHAMP') || str_contains($upperName, 'MASK') ? 6 : 4;
        }

        // EIMI Styling
        if ($brand === 'EIMI') {
            if (str_contains($upperName, 'THERMAL IMAGE')) {
                return 12;
            }
            if (str_contains($upperName, 'MISTIFY') || str_contains($upperName, 'SUPER SET')) {
                return 8;
            }
            return 4;
        }

        // SP Luxe
        if ($brand === 'SP') {
            if (str_contains($upperName, 'LUXE OIL 100 ML') || $code === '4015600260361') {
                return 18;
            }
            return 8;
        }

        // Sebastian Professional
        if ($brand === 'SEBASTIAN') {
            if (str_contains($upperName, 'DARK OIL ACEITE') && ! str_contains($upperName, '30 ML') || $code === '4084500999206') {
                return ($level === 'full') ? 30 : 20;
            }
            if (str_contains($upperName, 'DARK OIL ACEITE 30 ML') || $code === '99350045971') {
                return 24;
            }
            if (str_contains($upperName, 'DARK OIL SH') || str_contains($upperName, 'DARK OIL MASK 145')) {
                return 12;
            }
            if (str_contains($upperName, 'PENETRAITT SHAMPOO 250 ML') || $code === '4015600054670' || str_contains($upperName, 'PENETRAITT MASK 145ML')) {
                return 16;
            }
            if (str_contains($upperName, 'PENETRAITT MASK 500ML') || str_contains($upperName, 'PENETRAITT SHAMPOO 1000')) {
                return 8;
            }
            if (str_contains($upperName, 'NO BREAKER 100 ML') || $code === '99350077467') {
                return 14;
            }
            if (str_contains($upperName, 'POTION 9 150 ML') || $code === '99350188550' || $code === '4015600056070') {
                return 16;
            }
            if (str_contains($upperName, 'POTION 9 PROFESIONAL 500 ML') || $code === '99350188548') {
                return 6;
            }
            if (str_contains($upperName, 'SHAPER FIERCE') || str_contains($upperName, 'SHAPER ZERO GRAVITY')) {
                return 12;
            }
            return 4;
        }

        return 0;
    }

    private function applyWellaPromotions($sheet): void
    {
        // 1. Reveladores 7 + 1 (Promo W2023WED0009):
        $sheet->setCellValue('G366', 5); // 5 Welloxon 20V 1000ml gratis
        $sheet->setCellValue('G367', 4); // 4 Welloxon 30V 1000ml gratis
        $sheet->setCellValue('G368', 2); // 2 Welloxon 40V 1000ml gratis
        $sheet->setCellValue('G369', 2); // 2 Welloxon 1.9% 1000ml gratis
        $sheet->setCellValue('G373', 3); // 3 Emulsión CT 1.9% gratis
        $sheet->setCellValue('G374', 2); // 2 Emulsión CT 4% gratis
        $sheet->setCellValue('G378', 2); // 2 Activador SF Brocha gratis

        // 2. Galones Welloxon (Promo W2023WED0010): 7 pumps de galón de regalo
        $sheet->setCellValue('H628', 1);
        $sheet->setCellValue('G629', 7); // 7 Wella pump Galón 3.7 L

        // 3. Promoción $30,000 en color (Promo W2023WED0001):
        // 25 tubos de regalo a elegir + 25 toallas negras
        $sheet->setCellValue('H591', 1);
        $sheet->setCellValue('G593', 25); // 25 Wella toalla microfibra negra
        $sheet->setCellValue('G32', 3);   // 3 tubos CP 2/0 Negro
        $sheet->setCellValue('G37', 3);   // 3 tubos CP 5/0 Castaño Claro
        $sheet->setCellValue('G48', 3);   // 3 tubos CP 6/0 Rubio Oscuro
        $sheet->setCellValue('G50', 3);   // 3 tubos CP 6/1 Rubio Oscuro Ceniza
        $sheet->setCellValue('G63', 3);   // 3 tubos CP 8/1 Rubio Claro Ceniza
        $sheet->setCellValue('G73', 4);   // 4 tubos CP 9/16 Rubio Extra Claro Ceniza Violeta
        $sheet->setCellValue('G244', 3);  // 3 tubos CT 9/16
        $sheet->setCellValue('G247', 3);  // 3 tubos CT 9/86

        // 4. Promoción Ultimate Repair $28,000 (Promo W2023WED0014):
        // 4 shampoos 1L + 16 travel size a elegir
        $sheet->setCellValue('H647', 1);
        $sheet->setCellValue('G648', 4); // 4 WP Ult Repair Shampoo 1L gratis
        $sheet->setCellValue('G650', 8); // 8 WP Ult Repair Shampoo 15 ml sachet
        $sheet->setCellValue('G651', 8); // 8 WP Ult Repair Acondicionador 15 ml sachet

        // 5. Promoción Invigo / Boutique / EIMI / Sebastian $19,000 (Promo W2023WED0021):
        // 1 secadora BaByliss Red de regalo
        $sheet->setCellValue('H697', 1);
        $sheet->setCellValue('G698', 1); // 1 Wella secadora BaByliss Red gratis

        // 6. Promoción Sebastian $15,000 (Promo W2023WED0020):
        // 3 mask 500ml No Breaker + 15 sachet leave in
        $sheet->setCellValue('H693', 1);
        $sheet->setCellValue('G694', 3);  // 3 SEB No Breaker mask 500 ml gratis
        $sheet->setCellValue('G695', 15); // 15 sachet leave-in No Breaker gratis
    }
}
