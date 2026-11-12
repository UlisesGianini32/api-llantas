<?php

use App\Http\Controllers\Autopartes\CatalogController;
use App\Http\Controllers\Autopartes\DiscountController;
use App\Http\Controllers\Autopartes\ImportController;
use App\Http\Controllers\Autopartes\InventoryController;
use App\Http\Controllers\Autopartes\MovementController;
use App\Http\Controllers\Autopartes\PosController;
use App\Http\Controllers\Autopartes\PriceController;
use App\Http\Controllers\Autopartes\ReceiptController;
use App\Http\Controllers\Autopartes\ReportController;
use App\Http\Controllers\Autopartes\SaleHistoryController;
use App\Http\Controllers\Autopartes\SellerController;
use App\Http\Middleware\RequireAutopartesEnabled;
use Illuminate\Support\Facades\Route;

// Explicit capability checks in every controller action; no inherited role grants.
Route::middleware(['auth', RequireAutopartesEnabled::class])->prefix('autopartes/{branch:code}')->name('autopartes.')->group(function (): void {
    Route::get('/', [CatalogController::class, 'index'])->name('index');
    Route::get('/registros/{row}', [CatalogController::class, 'show'])->name('show');

    // Inventario físico de sucursal y verificación de identidad
    Route::get('/inventario', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('/identidades/{part}/verificar', [InventoryController::class, 'resolveIdentity'])->name('identities.resolve');

    // Recepción y entradas mediante código de barras
    Route::get('/entradas', [ReceiptController::class, 'index'])->name('receipts.index');
    Route::post('/entradas', [ReceiptController::class, 'store'])->name('receipts.store');
    Route::post('/entradas/buscar-codigo', [ReceiptController::class, 'lookup'])->name('receipts.lookup');
    Route::get('/entradas/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::post('/entradas/{receipt}/partidas', [ReceiptController::class, 'addItem'])->name('receipts.items.store');
    Route::patch('/entradas/{receipt}/partidas/{item}', [ReceiptController::class, 'updateItem'])->name('receipts.items.update');
    Route::delete('/entradas/{receipt}/partidas/{item}', [ReceiptController::class, 'removeItem'])->name('receipts.items.destroy');
    Route::post('/entradas/{receipt}/confirmar', [ReceiptController::class, 'confirm'])->name('receipts.confirm');

    // Historial de movimientos y ajustes auditados de stock
    Route::get('/movimientos', [MovementController::class, 'index'])->name('movements.index');
    Route::post('/ajustes', [MovementController::class, 'storeAdjustment'])->name('adjustments.store');
    Route::post('/ajustes/{adjustment}/aprobar', [MovementController::class, 'approveAdjustment'])->name('adjustments.approve');

    // Importaciones de catálogo Excel
    Route::get('/importaciones', [ImportController::class, 'index'])->name('imports.index');
    Route::post('/importaciones', [ImportController::class, 'store'])->middleware('throttle:6,1')->name('imports.store');
    Route::get('/importaciones/{run}', [ImportController::class, 'show'])->name('imports.show');
    Route::post('/importaciones/{run}/confirmar', [ImportController::class, 'confirm'])->name('imports.confirm');
    Route::post('/importaciones/{run}/reintentar', [ImportController::class, 'retry'])->name('imports.retry');
    Route::get('/importaciones/{run}/original', [ImportController::class, 'download'])->name('imports.original');

    // Fase 4: Punto de venta exclusivo de Autopartes Hermosillo
    Route::get('/pos', [PosController::class, 'index'])->name('pos.index');
    Route::get('/pos/productos', [PosController::class, 'searchProducts'])->name('pos.products.search');
    Route::post('/pos/escanear', [PosController::class, 'lookupCode'])->name('pos.lookup');
    Route::post('/pos/calcular', [PosController::class, 'calculateCart'])->name('pos.calculate');
    Route::post('/pos/descuento-manual/solicitar', [PosController::class, 'requestDiscountApproval'])->name('pos.discounts.request');
    Route::post('/pos/descuento-manual/{approval}/aprobar', [PosController::class, 'approveDiscountRequest'])->name('pos.discounts.approve');
    Route::post('/pos/ventas', [PosController::class, 'storeSale'])->name('pos.sales.store');
    Route::get('/pos/ventas/{sale}/ticket', [PosController::class, 'receipt'])->name('pos.sales.receipt');
    Route::post('/pos/ventas/{sale}/reimprimir', [PosController::class, 'reprintReceipt'])->name('pos.sales.reprint');
    Route::post('/pos/cajas', [PosController::class, 'storeRegister'])->name('pos.registers.store');
    Route::post('/pos/turnos/abrir', [PosController::class, 'openShift'])->name('pos.shifts.open');
    Route::post('/pos/turnos/{shift}/movimientos', [PosController::class, 'addCashMovement'])->name('pos.shifts.movements.store');
    Route::post('/pos/turnos/{shift}/cerrar', [PosController::class, 'closeShift'])->name('pos.shifts.close');

    // Administración de vendedores
    Route::get('/vendedores', [SellerController::class, 'index'])->name('sellers.index');
    Route::post('/vendedores', [SellerController::class, 'store'])->name('sellers.store');
    Route::patch('/vendedores/{seller}', [SellerController::class, 'update'])->name('sellers.update');

    // Administración de precios en MXN y disponibilidad comercial local
    Route::get('/precios', [PriceController::class, 'index'])->name('prices.index');
    Route::post('/precios/lote/vista-previa', [PriceController::class, 'previewBatch'])->name('prices.batch.preview');
    Route::post('/precios/lote/aplicar', [PriceController::class, 'applyBatch'])->name('prices.batch.apply');
    Route::post('/precios/importar/vista-previa', [PriceController::class, 'previewImport'])->name('prices.import.preview');
    Route::post('/precios/importar/confirmar', [PriceController::class, 'applyImport'])->name('prices.import.apply');
    Route::post('/precios/{part}', [PriceController::class, 'update'])->name('prices.update');
    Route::get('/precios/{part}/historial', [PriceController::class, 'history'])->name('prices.history');

    // Administración de reglas de descuento por mayoreo y política comercial
    Route::get('/descuentos', [DiscountController::class, 'index'])->name('discounts.index');
    Route::patch('/descuentos/politica-comercial', [DiscountController::class, 'updatePolicy'])->name('discounts.policy.update');
    Route::post('/descuentos', [DiscountController::class, 'store'])->name('discounts.store');
    Route::patch('/descuentos/{rule}', [DiscountController::class, 'update'])->name('discounts.update');

    // Historial de ventas, cancelaciones y devoluciones
    Route::get('/ventas', [SaleHistoryController::class, 'index'])->name('sales.index');
    Route::post('/ventas/{sale}/cancelar', [SaleHistoryController::class, 'cancel'])->name('sales.cancel');
    Route::post('/ventas/{sale}/devolver', [SaleHistoryController::class, 'returnItems'])->name('sales.return');

    // Reportes por vendedor y exportación CSV autorizada
    Route::get('/reportes', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reportes/exportar', [ReportController::class, 'exportCsv'])->name('reports.export');
});
