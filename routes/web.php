<?php

use App\Http\Controllers\AmsPedidosController;
use App\Http\Controllers\AmsProductIssueController;
use App\Http\Controllers\AmsSecondaryOrdersController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExcelImportController;
use App\Http\Controllers\InventoryChannelLinkController;
use App\Http\Controllers\InventoryKitController;
use App\Http\Controllers\InventoryKitReservationController;
use App\Http\Controllers\InventoryLocationController;
use App\Http\Controllers\InventoryMovementController;
use App\Http\Controllers\InventoryProductController;
use App\Http\Controllers\InventoryReservationController;
use App\Http\Controllers\LlantaComparisonController;
use App\Http\Controllers\LlantaController;
use App\Http\Controllers\MeliBatchRepublishController;
use App\Http\Controllers\MeliClaimAttachmentController;
use App\Http\Controllers\MeliClaimController;
use App\Http\Controllers\MeliClaimMessageController;
use App\Http\Controllers\MeliClaimResolutionController;
use App\Http\Controllers\MeliCompareController;
use App\Http\Controllers\MeliFullShipmentController;
use App\Http\Controllers\MeliFullStockController;
use App\Http\Controllers\MeliLabelController;
use App\Http\Controllers\MeliMessagingController;
use App\Http\Controllers\MeliOrderCancellationController;
use App\Http\Controllers\MeliPriceManager\MeliAccountTaxProfileController;
use App\Http\Controllers\MeliPriceManager\MeliBeautyScheduledDiscountController;
use App\Http\Controllers\MeliPriceManager\MeliBrandAliasController;
use App\Http\Controllers\MeliPriceManager\MeliBrandGroupController;
use App\Http\Controllers\MeliPriceManager\MeliBrandReclassificationController;
use App\Http\Controllers\MeliPriceManager\MeliBulkCategorizedItemBrandController;
use App\Http\Controllers\MeliPriceManager\MeliCategorizedItemBrandController;
use App\Http\Controllers\MeliPriceManager\MeliItemClassificationActionController;
use App\Http\Controllers\MeliPriceManager\MeliPriceManagerDashboardController;
use App\Http\Controllers\MeliPriceManager\MeliPriceSimulationController;
use App\Http\Controllers\MeliPriceManager\MeliPriceUpdateController;
use App\Http\Controllers\MeliPriceManager\MeliUncategorizedItemController;
use App\Http\Controllers\MeliPublishController;
use App\Http\Controllers\MeliQuestionController;
use App\Http\Controllers\MeliRepublishController;
use App\Http\Controllers\MeliSecondaryPublicationController;
use App\Http\Controllers\Pos\PosController;
use App\Http\Controllers\Pos\PosShiftController;
use App\Http\Controllers\PriceRulesController;
use App\Http\Controllers\ProductoCompuestoController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ProductoSyncController;
use App\Http\Controllers\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Purchasing\SupplierController;
use App\Http\Controllers\QzTrayController;
use App\Http\Controllers\Restock\RestockForecastController;
use App\Http\Controllers\Settings\ChannelSettingsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\SyscomMeliController;
use App\Http\Controllers\SystemActionController;
use App\Http\Controllers\SystemHealthController;
use App\Http\Controllers\SystemLogController;
use App\Http\Controllers\SystemQueueController;
use App\Http\Controllers\SystemServerController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

// CALLBACK (fuera de auth)
Route::get('/auth/meli/callback', [AuthController::class, 'handleMeliCallback'])
    ->name('meli.callback');
Route::get('/auth/shopify/callback', [ChannelSettingsController::class, 'handleShopifyCallback'])
    ->name('shopify.callback');

Route::middleware(['auth', 'role'])->group(function () {
    // SISTEMA
    Route::get('/sistema/estado', [SystemHealthController::class, 'index'])
        ->name('system.health.index');

    Route::get('/sistema/servidor/metricas', [SystemServerController::class, 'metrics'])
        ->name('system.server.metrics');

    // CENTRO DE CONTROL
    Route::get('/sistema/colas', [SystemQueueController::class, 'index'])
        ->name('system.queues.index');
    Route::post('/sistema/colas/retry-all', [SystemQueueController::class, 'retryAll'])
        ->name('system.queues.retry-all');
    Route::post('/sistema/colas/flush', [SystemQueueController::class, 'flush'])
        ->name('system.queues.flush');
    Route::post('/sistema/colas/{uuid}/retry', [SystemQueueController::class, 'retry'])
        ->name('system.queues.retry');
    Route::delete('/sistema/colas/{uuid}', [SystemQueueController::class, 'destroy'])
        ->name('system.queues.destroy');

    Route::get('/sistema/logs', [SystemLogController::class, 'index'])
        ->name('system.logs.index');

    Route::get('/sistema/acciones', fn () => inertia('System/Actions'))
        ->name('system.actions.index');
    Route::post('/sistema/acciones/{action}', [SystemActionController::class, 'run'])
        ->whereIn('action', [
            'cache-clear',
            'config-clear',
            'route-clear',
            'view-clear',
            'queue-restart',
            'schedule-run',
        ])
        ->name('system.actions.run');

    // DASHBOARD
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::post('/dashboard/stock/zero', [DashboardController::class, 'zeroStock'])
        ->name('dashboard.stock.zero');

    Route::post('/dashboard/meli/refresh-token', [DashboardController::class, 'refreshMeliToken'])
        ->name('dashboard.meli.refresh');

    Route::post('/meli/sync-manual', [DashboardController::class, 'syncMeliManual'])
        ->name('meli.sync-manual');

    // PRODUCTOS
    Route::get('/producto', [ProductoController::class, 'index'])->name('producto.index');
    Route::post('/producto/sync', [ProductoSyncController::class, 'sync'])->name('producto.sync');
    Route::post('/producto/resolve-shopify-categories', [ProductoController::class, 'resolveShopifyCategories'])
        ->name('producto.resolve_shopify_categories');

    Route::get('/producto/export/shopify/tobeauty', [ProductoController::class, 'exportShopifyTobeauty'])
        ->name('producto.export.shopify.tobeauty');

    // PUNTO DE VENTA (POS / MOSTRADOR)
    Route::prefix('pos')->name('pos.')->group(function () {
        Route::get('/', [PosController::class, 'index'])->name('index');
        Route::get('/search', [PosController::class, 'search'])->name('search');
        Route::post('/sales', [PosController::class, 'store'])->name('sales.store');
        Route::get('/sales/{posSale}', [PosController::class, 'show'])
            ->whereNumber('posSale')->name('sales.show');
        Route::get('/sales/{posSale}/receipt', [PosController::class, 'receipt'])
            ->whereNumber('posSale')->name('sales.receipt');
        Route::get('/sales/{posSale}/voucher', [CustomerController::class, 'voucher'])
            ->whereNumber('posSale')->name('sales.voucher');
        Route::post('/sales/{posSale}/cancel', [PosController::class, 'cancel'])
            ->whereNumber('posSale')->name('sales.cancel');

        Route::get('/customers/search', [CustomerController::class, 'search'])->name('customers.search');

        // Turnos y Caja (Shifts & Drawer)
        Route::prefix('shifts')->name('shifts.')->group(function () {
            Route::get('/current', [PosShiftController::class, 'current'])->name('current');
            Route::post('/open', [PosShiftController::class, 'open'])->name('open');
            Route::post('/{posShift}/movement', [PosShiftController::class, 'movement'])
                ->whereNumber('posShift')->name('movement');
            Route::get('/{posShift}/summary', [PosShiftController::class, 'summary'])
                ->whereNumber('posShift')->name('summary');
            Route::post('/{posShift}/close', [PosShiftController::class, 'close'])
                ->whereNumber('posShift')->name('close');
            Route::get('/{posShift}/receipt', [PosShiftController::class, 'receipt'])
                ->whereNumber('posShift')->name('receipt');
        });
        Route::post('/drawer/open', [PosShiftController::class, 'drawer'])->name('drawer.open');
    });

    // CLIENTES & CARTERA DE CRÉDITO (Estilistas, Mayoristas, Créditos 7/15/30 días)
    Route::prefix('pos/clientes')->name('customers.')->group(function () {
        Route::get('/', [CustomerController::class, 'index'])->name('index');
        Route::post('/', [CustomerController::class, 'store'])->name('store');
        Route::get('/{customer}', [CustomerController::class, 'show'])
            ->whereNumber('customer')->name('show');
        Route::put('/{customer}', [CustomerController::class, 'update'])
            ->whereNumber('customer')->name('update');
        Route::post('/{customer}/pagos', [CustomerController::class, 'addPayment'])
            ->whereNumber('customer')->name('payments.store');
    });
    Route::get('/clientes', fn () => redirect()->route('customers.index'));

    // REABASTECIMIENTO INTELIGENTE Y PRONÓSTICO DE COMPRAS (Ticket 19)
    Route::prefix('reabastecimiento')->name('restock.')->group(function () {
        Route::get('/pronostico', [RestockForecastController::class, 'index'])->name('forecast.index');
        Route::post('/configuraciones', [RestockForecastController::class, 'saveConfiguration'])->name('configurations.save');
        Route::get('/exportar', [RestockForecastController::class, 'export'])->name('forecast.export');
        Route::post('/productos/{product}/marca', [RestockForecastController::class, 'updateProductBrand'])
            ->whereNumber('product')->name('products.brand');
    });

    // ÓRDENES DE COMPRA Y RECEPCIÓN DE ALMACÉN (Ticket 20)
    Route::prefix('compras/ordenes')->name('purchasing.orders.')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'index'])->name('index');
        Route::get('/crear', [PurchaseOrderController::class, 'create'])->name('create');
        Route::post('/', [PurchaseOrderController::class, 'store'])->name('store');
        Route::get('/buscar-productos', [PurchaseOrderController::class, 'searchProducts'])->name('search-products');
        Route::get('/{purchaseOrder}', [PurchaseOrderController::class, 'show'])
            ->whereNumber('purchaseOrder')->name('show');
        Route::post('/{purchaseOrder}/ordenar', [PurchaseOrderController::class, 'order'])
            ->whereNumber('purchaseOrder')->name('order');
        Route::post('/{purchaseOrder}/recibir', [PurchaseOrderController::class, 'receive'])
            ->whereNumber('purchaseOrder')->name('receive');
        Route::post('/{purchaseOrder}/recepcionar', [PurchaseOrderController::class, 'receive'])
            ->whereNumber('purchaseOrder');
        Route::post('/{purchaseOrder}/cancelar', [PurchaseOrderController::class, 'cancel'])
            ->whereNumber('purchaseOrder')->name('cancel');
    });

    // PROVEEDORES Y MARCAS
    Route::prefix('compras/proveedores')->name('purchasing.suppliers.')->group(function () {
        Route::get('/', [SupplierController::class, 'index'])->name('index');
        Route::post('/', [SupplierController::class, 'store'])->name('store');
        Route::get('/buscar', [SupplierController::class, 'search'])->name('search');
        Route::get('/{supplier}', [SupplierController::class, 'show'])
            ->whereNumber('supplier')->name('show');
        Route::put('/{supplier}', [SupplierController::class, 'update'])
            ->whereNumber('supplier')->name('update');
        Route::patch('/{supplier}/estado', [SupplierController::class, 'toggle'])
            ->whereNumber('supplier')->name('toggle');
        Route::delete('/{supplier}', [SupplierController::class, 'destroy'])
            ->whereNumber('supplier')->name('destroy');
    });
    Route::get('/proveedores', fn () => redirect()->route('purchasing.suppliers.index'));

    // ALMACÉN: catálogo maestro independiente de llantas y Syscom.
    Route::prefix('almacen/productos')->name('inventory.products.')->group(function () {
        Route::get('/', [InventoryProductController::class, 'index'])->name('index');
        Route::get('/crear', [InventoryProductController::class, 'create'])->name('create');
        Route::post('/', [InventoryProductController::class, 'store'])->name('store');
        Route::get('/{inventoryProduct}', [InventoryProductController::class, 'show'])
            ->whereNumber('inventoryProduct')->name('show');
        Route::get('/{inventoryProduct}/editar', [InventoryProductController::class, 'edit'])
            ->whereNumber('inventoryProduct')->name('edit');
        Route::put('/{inventoryProduct}', [InventoryProductController::class, 'update'])
            ->whereNumber('inventoryProduct')->name('update');
        Route::patch('/{inventoryProduct}/estado', [InventoryProductController::class, 'toggle'])
            ->whereNumber('inventoryProduct')->name('toggle');
    });

    Route::prefix('almacen/ubicaciones')->name('inventory.locations.')->group(function () {
        Route::get('/', [InventoryLocationController::class, 'index'])->name('index');
        Route::get('/crear', [InventoryLocationController::class, 'create'])->name('create');
        Route::post('/', [InventoryLocationController::class, 'store'])->name('store');
        Route::get('/{inventoryLocation}', [InventoryLocationController::class, 'show'])
            ->whereNumber('inventoryLocation')->name('show');
        Route::get('/{inventoryLocation}/editar', [InventoryLocationController::class, 'edit'])
            ->whereNumber('inventoryLocation')->name('edit');
        Route::put('/{inventoryLocation}', [InventoryLocationController::class, 'update'])
            ->whereNumber('inventoryLocation')->name('update');
        Route::patch('/{inventoryLocation}/estado', [InventoryLocationController::class, 'toggle'])
            ->whereNumber('inventoryLocation')->name('toggle');
    });

    Route::prefix('almacen/movimientos')->name('inventory.movements.')->group(function () {
        Route::get('/', [InventoryMovementController::class, 'index'])->name('index');
        Route::get('/crear', [InventoryMovementController::class, 'create'])->name('create');
        Route::post('/', [InventoryMovementController::class, 'store'])->name('store');
        Route::get('/{inventoryMovement}', [InventoryMovementController::class, 'show'])
            ->whereNumber('inventoryMovement')->name('show');
    });

    Route::prefix('almacen/reservas')->name('inventory.reservations.')->group(function () {
        Route::get('/', [InventoryReservationController::class, 'index'])->name('index');
        Route::get('/crear', [InventoryReservationController::class, 'create'])->name('create');
        Route::post('/', [InventoryReservationController::class, 'store'])->name('store');
        Route::get('/{inventoryReservation}', [InventoryReservationController::class, 'show'])
            ->whereNumber('inventoryReservation')->name('show');
        Route::post('/{inventoryReservation}/liberar', [InventoryReservationController::class, 'release'])
            ->whereNumber('inventoryReservation')->name('release');
        Route::post('/{inventoryReservation}/cancelar', [InventoryReservationController::class, 'cancel'])
            ->whereNumber('inventoryReservation')->name('cancel');
        Route::post('/{inventoryReservation}/expirar', [InventoryReservationController::class, 'expire'])
            ->whereNumber('inventoryReservation')->name('expire');
        Route::post('/{inventoryReservation}/cumplir', [InventoryReservationController::class, 'fulfill'])
            ->whereNumber('inventoryReservation')->name('fulfill');
    });

    Route::prefix('almacen/kits')->name('inventory.kits.')->group(function () {
        Route::get('/', [InventoryKitController::class, 'index'])->name('index');
        Route::get('/reservas/{inventoryKitReservation}', [InventoryKitReservationController::class, 'show'])
            ->whereNumber('inventoryKitReservation')->name('reservations.show');
        Route::post('/reservas/{reservation}/liberar', [InventoryKitReservationController::class, 'release'])
            ->whereNumber('reservation')->name('reservations.release');
        Route::post('/reservas/{reservation}/cancelar', [InventoryKitReservationController::class, 'cancel'])
            ->whereNumber('reservation')->name('reservations.cancel');
        Route::post('/reservas/{reservation}/expirar', [InventoryKitReservationController::class, 'expire'])
            ->whereNumber('reservation')->name('reservations.expire');
        Route::post('/reservas/{reservation}/cumplir', [InventoryKitReservationController::class, 'fulfill'])
            ->whereNumber('reservation')->name('reservations.fulfill');
        Route::get('/{inventoryKit}/editar-componentes', [InventoryKitController::class, 'edit'])
            ->whereNumber('inventoryKit')->name('edit');
        Route::put('/{inventoryKit}/componentes', [InventoryKitController::class, 'updateComponents'])
            ->whereNumber('inventoryKit')->name('components.update');
        Route::post('/{inventoryKit}/reservas', [InventoryKitController::class, 'reserve'])
            ->whereNumber('inventoryKit')->name('reservations.store');
        Route::get('/{inventoryKit}', [InventoryKitController::class, 'show'])
            ->whereNumber('inventoryKit')->name('show');
    });

    Route::prefix('almacen/canales')->name('inventory.channels.')->group(function () {
        Route::get('/', [InventoryChannelLinkController::class, 'index'])->name('index');
        Route::get('/crear', [InventoryChannelLinkController::class, 'create'])->name('create');
        Route::post('/', [InventoryChannelLinkController::class, 'store'])->name('store');
        Route::get('/importar', [InventoryChannelLinkController::class, 'import'])->name('import');
        Route::post('/importar', [InventoryChannelLinkController::class, 'applyImport'])->name('import.apply');
        Route::get('/mercado-libre/importar', [InventoryChannelLinkController::class, 'import'])->name('mercado-libre.import');
        Route::post('/mercado-libre/importar', [InventoryChannelLinkController::class, 'applyImport'])->name('mercado-libre.apply');
        Route::get('/buscar-productos', [InventoryChannelLinkController::class, 'searchProducts'])->name('search-products');
        Route::get('/mercado-libre/buscar-productos', [InventoryChannelLinkController::class, 'searchProducts'])->name('mercado-libre.search-products');
        Route::post('/vincular-manual', [InventoryChannelLinkController::class, 'linkManual'])->name('link-manual');
        Route::post('/mercado-libre/vincular-manual', [InventoryChannelLinkController::class, 'linkManual'])->name('mercado-libre.link-manual');
        Route::post('/vincular-seleccionados', [InventoryChannelLinkController::class, 'linkSelected'])->name('link-selected');
        Route::post('/mercado-libre/vincular-seleccionados', [InventoryChannelLinkController::class, 'linkSelected'])->name('mercado-libre.link-selected');
        Route::post('/amazon/cargar-reporte', [InventoryChannelLinkController::class, 'uploadAmazonReport'])->name('amazon.upload-report');
        Route::post('/amazon/limpiar-reporte', [InventoryChannelLinkController::class, 'clearAmazonReport'])->name('amazon.clear-report');
        Route::get('/mercado-libre/stock', [InventoryChannelLinkController::class, 'stock'])->name('mercado-libre.stock');
        Route::post('/mercado-libre/stock/sync', [InventoryChannelLinkController::class, 'syncMeliStock'])->name('mercado-libre.stock.sync');
        Route::get('/{inventoryChannelLink}', [InventoryChannelLinkController::class, 'show'])
            ->whereNumber('inventoryChannelLink')->name('show');
        Route::get('/{inventoryChannelLink}/editar', [InventoryChannelLinkController::class, 'edit'])
            ->whereNumber('inventoryChannelLink')->name('edit');
        Route::patch('/{inventoryChannelLink}', [InventoryChannelLinkController::class, 'update'])
            ->whereNumber('inventoryChannelLink')->name('update');
        Route::patch('/{inventoryChannelLink}/estado', [InventoryChannelLinkController::class, 'toggle'])
            ->whereNumber('inventoryChannelLink')->name('toggle');
        Route::patch('/{inventoryChannelLink}/stock-sync', [InventoryChannelLinkController::class, 'toggleStockSync'])
            ->whereNumber('inventoryChannelLink')->name('stock-sync.toggle');
    });

    // COMPARE
    Route::get('/ml/compare', [MeliCompareController::class, 'index'])->name('ml.compare');
    Route::post('/ml/compare/run', [MeliCompareController::class, 'run'])->name('ml.compare.run');

    Route::get('/meli/mensajeria', [MeliMessagingController::class, 'index'])->name('meli.messaging.index');
    Route::get('/meli/mensajeria/flows/{flow}/messages', [MeliMessagingController::class, 'messages'])
        ->whereNumber('flow')
        ->name('meli.messaging.messages');
    Route::get('/meli/mensajeria/flows/{flow}/venta', [MeliMessagingController::class, 'saleDetails'])
        ->whereNumber('flow')
        ->name('meli.messaging.sale-details');
    Route::post('/meli/mensajeria/flows/{flow}/reply', [MeliMessagingController::class, 'reply'])
        ->whereNumber('flow')
        ->name('meli.messaging.reply');

    Route::get('/meli/preguntas', [MeliQuestionController::class, 'index'])
        ->name('meli.questions.index');
    Route::post('/meli/preguntas/sincronizar', [MeliQuestionController::class, 'sync'])
        ->name('meli.questions.sync');
    Route::post('/meli/preguntas/{question}/responder', [MeliQuestionController::class, 'answer'])
        ->whereNumber('question')
        ->name('meli.questions.answer');

    Route::get('/meli-claims', [MeliClaimController::class, 'index'])->name('meli.claims.index');
    Route::post('/meli-claims/sync', [MeliClaimController::class, 'sync'])->name('meli.claims.sync');
    Route::get('/meli-claims/{claim}', [MeliClaimController::class, 'show'])->whereNumber('claim')->name('meli.claims.show');
    Route::post('/meli-claims/{claim}/refresh', [MeliClaimController::class, 'refresh'])->whereNumber('claim')->name('meli.claims.refresh');
    Route::post('/meli-claims/{claim}/messages', [MeliClaimMessageController::class, 'store'])->whereNumber('claim')->name('meli.claims.messages.store');
    Route::post('/meli-claims/{claim}/resolutions/refund', [MeliClaimResolutionController::class, 'refund'])->whereNumber('claim')->name('meli.claims.resolutions.refund');
    Route::post('/meli-claims/{claim}/resolutions/allow-return', [MeliClaimResolutionController::class, 'allowReturn'])->whereNumber('claim')->name('meli.claims.resolutions.allow-return');
    Route::get('/meli-claims/{claim}/resolutions/partial-refund/offers', [MeliClaimResolutionController::class, 'partialOffers'])->whereNumber('claim')->name('meli.claims.resolutions.partial-refund.offers');
    Route::post('/meli-claims/{claim}/resolutions/partial-refund', [MeliClaimResolutionController::class, 'partialRefund'])->whereNumber('claim')->name('meli.claims.resolutions.partial-refund');
    Route::get('/meli-claims/{claim}/attachments/{attachment}/download', [MeliClaimAttachmentController::class, 'download'])
        ->whereNumber('claim')->where('attachment', '[A-Za-z0-9._-]+')->name('meli.claims.attachments.download');

    // PEDIDOS PRINCIPALES
    Route::get('/ams/pedidos', [AmsPedidosController::class, 'index'])->name('ams.pedidos.index');
    Route::post('/ams/pedidos/{order}/solicitar-datos-envio', [AmsPedidosController::class, 'requestDeliveryDetails'])
        ->whereNumber('order')
        ->name('ams.pedidos.delivery_details.request');
    Route::get('/ams/pedidos-procesar', [AmsPedidosController::class, 'procesar'])->name('ams.pedidos.procesar');
    Route::get('/ams/pedidos-manana', [AmsPedidosController::class, 'procesarManana'])->name('ams.pedidos.manana');

    // INCIDENCIAS DE PRODUCTOS AMS
    Route::get('/ams/incidencias', [AmsProductIssueController::class, 'index'])->name('ams.incidencias.index');
    Route::post('/ams/incidencias', [AmsProductIssueController::class, 'store'])->name('ams.incidencias.store');
    Route::put('/ams/incidencias/{issue}', [AmsProductIssueController::class, 'update'])->whereNumber('issue')->name('ams.incidencias.update');
    Route::post('/ams/incidencias/{issue}/resolve', [AmsProductIssueController::class, 'resolve'])->whereNumber('issue')->name('ams.incidencias.resolve');
    Route::post('/ams/incidencias/{issue}/reopen', [AmsProductIssueController::class, 'reopen'])->whereNumber('issue')->name('ams.incidencias.reopen');
    Route::delete('/ams/incidencias/{issue}', [AmsProductIssueController::class, 'destroy'])->whereNumber('issue')->name('ams.incidencias.destroy');

    Route::get(
        '/ams/pedidos/shipping-label/{shippingId}/print',
        [AmsPedidosController::class, 'shippingLabelPrintPage']
    )->name('ams.pedidos.shipping_label_print');

    Route::get(
        '/ams/pedidos/shipping-label/{shippingId}',
        [AmsPedidosController::class, 'printShippingLabel']
    )->name('ams.pedidos.shipping_label');

    Route::get(
        '/ams/pedidos/shipping-label/{shippingId}/zpl-raw',
        [AmsPedidosController::class, 'rawShippingLabelZpl']
    )
        ->whereNumber('shippingId')
        ->name('ams.pedidos.shipping_label_zpl_raw');

    Route::get(
        '/ams/pedidos/shipping-label/{shippingId}/kamo-png',
        [AmsPedidosController::class, 'kamoShippingLabelPng']
    )
        ->whereNumber('shippingId')
        ->name('ams.pedidos.shipping_label_kamo_png');

    Route::get(
        '/ams/pedidos/shipping-label/{shippingId}/kamo-tspl',
        [AmsPedidosController::class, 'kamoShippingLabelTspl']
    )
        ->whereNumber('shippingId')
        ->name('ams.pedidos.shipping_label_kamo_tspl');

    // PEDIDOS - CUENTAS SECUNDARIAS
    Route::get(
        '/ams/pedidos-secundaria',
        [AmsSecondaryOrdersController::class, 'procesar']
    )->name('ams.secondary.procesar');

    Route::post(
        '/ams/pedidos-secundaria/sync',
        [AmsSecondaryOrdersController::class, 'sync']
    )->name('ams.secondary.sync');

    Route::post(
        '/ams/pedidos-secundaria/orders/{order}/cancel',
        [MeliOrderCancellationController::class, 'store']
    )->whereNumber('order')->name('ams.secondary.orders.cancel');

    Route::get(
        '/ams/secundaria/pedidos/shipping-label/{shippingId}/print',
        [AmsSecondaryOrdersController::class, 'shippingLabelPrintPage']
    )
        ->whereNumber('shippingId')
        ->name('ams.secondary.shipping_label_print');

    Route::get(
        '/ams/secundaria/pedidos/shipping-label/{shippingId}/zpl',
        [AmsSecondaryOrdersController::class, 'downloadShippingLabelZpl']
    )
        ->whereNumber('shippingId')
        ->name('ams.secondary.shipping_label_zpl');

    Route::get(
        '/ams/secundaria/pedidos/shipping-label/{shippingId}/kamo-png',
        [AmsSecondaryOrdersController::class, 'kamoShippingLabelPng']
    )
        ->whereNumber('shippingId')
        ->name('ams.secondary.shipping_label_kamo_png');

    Route::get(
        '/ams/secundaria/pedidos/shipping-label/{shippingId}/kamo-tspl',
        [AmsSecondaryOrdersController::class, 'kamoShippingLabelTspl']
    )
        ->whereNumber('shippingId')
        ->name('ams.secondary.shipping_label_kamo_tspl');

    Route::get(
        '/ams/secundaria/pedidos/shipping-label/{shippingId}',
        [AmsSecondaryOrdersController::class, 'printShippingLabel']
    )
        ->whereNumber('shippingId')
        ->name('ams.secondary.shipping_label');

    Route::get(
        '/ams/secundaria/pedidos/shipping-label/{shippingId}/zpl-raw',
        [AmsSecondaryOrdersController::class, 'rawShippingLabelZpl']
    )
        ->whereNumber('shippingId')
        ->name('ams.secondary.shipping_label_zpl_raw');

    // QZTRAY
    Route::get('/qz/certificate', [QzTrayController::class, 'certificate'])
        ->name('qz.certificate');

    Route::post('/qz/sign', [QzTrayController::class, 'sign'])
        ->name('qz.sign');

    // MERCADO LIBRE
    Route::get('/mercado-libre/etiquetas', [MeliLabelController::class, 'index'])->name('meli.labels.index');
    Route::post('/mercado-libre/etiquetas/procesar', [MeliLabelController::class, 'process'])->name('meli.labels.process');
    Route::post('/mercado-libre/etiquetas/registrar-impresion/{labelPrint}', [MeliLabelController::class, 'record'])
        ->whereNumber('labelPrint')
        ->name('meli.labels.printed');

    Route::prefix('/meli-price-manager')->name('meli-price-manager.')->group(function () {
        Route::get('/', [MeliPriceManagerDashboardController::class, 'index'])->name('index');
        Route::get('/scheduled-discounts', [MeliBeautyScheduledDiscountController::class, 'index'])->name('scheduled-discounts.index');
        Route::post('/scheduled-discounts', [MeliBeautyScheduledDiscountController::class, 'store'])->name('scheduled-discounts.store');
        Route::put('/scheduled-discounts/{discount}', [MeliBeautyScheduledDiscountController::class, 'update'])->name('scheduled-discounts.update');
        Route::patch('/scheduled-discounts/{discount}/status', [MeliBeautyScheduledDiscountController::class, 'status'])->name('scheduled-discounts.status');
        Route::post('/sync', [MeliPriceManagerDashboardController::class, 'sync'])->name('sync');
        Route::put('/tax-profile', [MeliAccountTaxProfileController::class, 'update'])->name('tax-profile.update');
        Route::post('/items/{item}/simulate-price', MeliPriceSimulationController::class)
            ->whereNumber('item')
            ->name('items.price.simulate');
        Route::put('/items/{item}/price', MeliPriceUpdateController::class)
            ->whereNumber('item')
            ->name('items.price.update');
        Route::post('/items/{item}/brand', MeliCategorizedItemBrandController::class)
            ->whereNumber('item')
            ->name('items.brand.update');
        Route::post('/items/bulk-brand', MeliBulkCategorizedItemBrandController::class)
            ->name('items.brand.bulk');

        Route::get('/brands', [MeliBrandGroupController::class, 'index'])->name('brands.index');
        Route::post('/brands', [MeliBrandGroupController::class, 'store'])->name('brands.store');
        Route::put('/brands/{brand}', [MeliBrandGroupController::class, 'update'])->name('brands.update');
        Route::patch('/brands/{brand}/status', [MeliBrandGroupController::class, 'status'])->name('brands.status');

        Route::post('/brands/{brand}/aliases', [MeliBrandAliasController::class, 'store'])->name('aliases.store');
        Route::put('/aliases/{alias}', [MeliBrandAliasController::class, 'update'])->name('aliases.update');
        Route::patch('/aliases/{alias}/status', [MeliBrandAliasController::class, 'status'])->name('aliases.status');
        Route::delete('/aliases/{alias}', [MeliBrandAliasController::class, 'destroy'])->name('aliases.destroy');

        Route::post('/brands/reclassification/preview', [MeliBrandReclassificationController::class, 'preview'])
            ->name('reclassification.preview');
        Route::post('/brands/{brand}/reclassification/preview', [MeliBrandReclassificationController::class, 'previewBrand'])
            ->name('brands.reclassification.preview');
        Route::post('/brands/reclassification/apply', [MeliBrandReclassificationController::class, 'apply'])
            ->name('reclassification.apply');

        Route::get('/uncategorized', [MeliUncategorizedItemController::class, 'index'])->name('uncategorized.index');
        Route::post('/uncategorized/bulk', [MeliItemClassificationActionController::class, 'bulk'])
            ->name('uncategorized.bulk');
        Route::post('/items/{item}/suggestion/accept', [MeliItemClassificationActionController::class, 'accept'])
            ->whereNumber('item')
            ->name('items.suggestion.accept');
        Route::post('/items/{item}/assign', [MeliItemClassificationActionController::class, 'assign'])
            ->whereNumber('item')
            ->name('items.assign');
        Route::post('/items/{item}/alias-and-assign', [MeliItemClassificationActionController::class, 'alias'])
            ->whereNumber('item')
            ->name('items.alias-and-assign');
        Route::post('/items/{item}/brand-and-assign', [MeliItemClassificationActionController::class, 'brand'])
            ->whereNumber('item')
            ->name('items.brand-and-assign');
        Route::post('/items/{item}/ignore', [MeliItemClassificationActionController::class, 'ignore'])
            ->whereNumber('item')
            ->name('items.ignore');
        Route::post('/items/{item}/restore', [MeliItemClassificationActionController::class, 'restore'])
            ->whereNumber('item')
            ->name('items.restore');
    });

    Route::post('/producto/ml/batch-republish', [MeliBatchRepublishController::class, 'store'])
        ->name('producto.ml.batch-republish');

    Route::delete('/producto/ml/secondary-publications', [MeliSecondaryPublicationController::class, 'destroy'])
        ->name('producto.ml.secondary-publications.destroy');

    // CENTRO DE PUBLICACIONES MERCADO LIBRE
    Route::get('/meli/publicaciones', [MeliSecondaryPublicationController::class, 'index'])
        ->name('meli.publications.index');
    Route::get('/meli/publicaciones/{publication}/editar', [MeliSecondaryPublicationController::class, 'edit'])
        ->name('meli.publications.edit');

    Route::put('/meli/publicaciones/{publication}', [MeliSecondaryPublicationController::class, 'update'])
        ->whereNumber('publication')
        ->name('meli.publications.update');

    Route::post('/meli/publicaciones/{publication}/refresh', [MeliSecondaryPublicationController::class, 'refresh'])
        ->whereNumber('publication')
        ->name('meli.publications.refresh');

    Route::patch('/meli/publicaciones/{publication}/status', [MeliSecondaryPublicationController::class, 'changeStatus'])
        ->whereNumber('publication')
        ->name('meli.publications.status');

    Route::delete('/meli/publicaciones/{publication}', [MeliSecondaryPublicationController::class, 'destroy'])
        ->whereNumber('publication')
        ->name('meli.publications.destroy');

    // INVENTARIO FULL MERCADO LIBRE
    Route::get('/meli/full', [MeliFullStockController::class, 'index'])
        ->name('meli.full.index');

    Route::get('/meli/full/recommendations/export', [MeliFullStockController::class, 'exportRecommendations'])
        ->name('meli.full.recommendations.export');

    Route::post('/meli/full/sync', [MeliFullStockController::class, 'sync'])
        ->name('meli.full.sync');

    Route::post('/meli/full/{mlm}/sync', [MeliFullStockController::class, 'syncOne'])
        ->where('mlm', '[A-Za-z0-9]+')
        ->name('meli.full.sync-one');

    // ENVÍOS MERCADO LIBRE FULL (Cajas de 30 y Guías ENVIA)
    Route::get('/meli/full/envios', [MeliFullShipmentController::class, 'index'])->name('meli-full-shipments.index');
    Route::get('/meli/full/envios/crear', [MeliFullShipmentController::class, 'create'])->name('meli-full-shipments.create');
    Route::post('/meli/full/envios', [MeliFullShipmentController::class, 'store'])->name('meli-full-shipments.store');
    Route::get('/meli/full/envios/{shipment}', [MeliFullShipmentController::class, 'show'])->name('meli-full-shipments.show');
    Route::get('/meli/full/envios/{shipment}/editar', [MeliFullShipmentController::class, 'edit'])->name('meli-full-shipments.edit');
    Route::put('/meli/full/envios/{shipment}', [MeliFullShipmentController::class, 'update'])->name('meli-full-shipments.update');
    Route::post('/meli/full/envios/{shipment}/despachar', [MeliFullShipmentController::class, 'dispatch'])->name('meli-full-shipments.dispatch');
    Route::post('/meli/full/envios/{shipment}/revertir', [MeliFullShipmentController::class, 'revert'])->name('meli-full-shipments.revert');
    Route::post('/meli/full/envios/{shipment}/recibir', [MeliFullShipmentController::class, 'receive'])->name('meli-full-shipments.receive');
    Route::get('/meli/full/envios/{shipment}/rotulos', [MeliFullShipmentController::class, 'printLabels'])->name('meli-full-shipments.labels');
    Route::get('/meli/full/envios/{shipment}/cajas/{box}/rotulo', [MeliFullShipmentController::class, 'printLabels'])->name('meli-full-shipments.box-label');
    Route::get('/meli/full/envios/{shipment}/etiquetas-productos', [MeliFullShipmentController::class, 'printProductLabels'])->name('meli-full-shipments.product-labels');

    Route::post('/ml/publications/{pub}/refresh', [MeliRepublishController::class, 'refreshPublication'])
        ->name('ml.publications.refresh');

    Route::post('/ml/categories/suggest', [MeliPublishController::class, 'suggestCategories'])
        ->name('ml.categories.suggest');

    Route::post('/ml/categories/meta', [MeliPublishController::class, 'categoryMeta'])
        ->name('ml.categories.meta');

    Route::post('/ml/catalog/search', [MeliPublishController::class, 'searchCatalog'])
        ->name('ml.catalog.search');

    Route::get('/llantas/{id}/ml/publish', [MeliPublishController::class, 'create'])
        ->name('llantas.ml.publish.form');

    Route::post('/llantas/{id}/ml/publish', [MeliPublishController::class, 'publishLlantaById'])
        ->name('llantas.ml.publish');

    Route::post('/llantas/{id}/ml/republish', [MeliRepublishController::class, 'republishLlantaById'])
        ->name('llantas.ml.republish');

    Route::get('/producto/{ml}/republish', [MeliRepublishController::class, 'showProductRepublishForm'])
        ->name('producto.ml.republish.form');

    Route::post('/producto/{ml}/republish', [MeliRepublishController::class, 'republishProductByMlm'])
        ->name('producto.ml.republish');

    // MERCADO LIBRE - COMPUESTOS
    Route::get('/productos/{id}/ml/publish', [MeliPublishController::class, 'createCompuesto'])
        ->name('productos.ml.publish.form');

    Route::post('/productos/{id}/ml/publish', [MeliPublishController::class, 'publishCompuestoById'])
        ->name('productos.ml.publish');

    Route::post('/productos/{id}/ml/republish', [MeliRepublishController::class, 'republishCompuestoById'])
        ->name('productos.ml.republish');

    // AUTH ML
    Route::get('/auth/meli', [AuthController::class, 'redirectToMeli'])
        ->name('meli.redirect');

    Route::delete('/auth/meli/unlink/{meliAccount}', [AuthController::class, 'unlinkMeli'])
        ->name('meli.unlink');

    // LLANTAS
    Route::get('/llantas', [LlantaController::class, 'indexWeb'])->name('llantas.index');
    Route::get('/llantas/comparador', [LlantaComparisonController::class, 'index'])
        ->name('llantas.comparador.index');
    Route::post('/llantas/comparador/{comparison}/decision', [LlantaComparisonController::class, 'decide'])
        ->name('llantas.comparador.decision');
    Route::get('/llantas/{id}/editar', [LlantaController::class, 'editWeb'])->name('llantas.edit');
    Route::put('/llantas/{id}', [LlantaController::class, 'updateWeb'])->name('llantas.update');

    Route::get('/llantas/agotadas', [LlantaController::class, 'agotadasWeb'])->name('llantas.agotadas');

    Route::get('/llantas/no-actualizadas', [LlantaController::class, 'noActualizadasWeb'])
        ->name('llantas.no_actualizadas');

    Route::post('/llantas/no-actualizadas/poner-cero', [LlantaController::class, 'ponerStockCero'])
        ->name('llantas.poner_cero');

    Route::post('/llantas/{llanta}/price/manual', [LlantaController::class, 'setPriceManual'])
        ->name('llantas.price.manual');

    Route::post('/llantas/{llanta}/price/auto', [LlantaController::class, 'setPriceAuto'])
        ->name('llantas.price.auto');

    Route::post('/llantas/{llanta}/price/recalc', [LlantaController::class, 'recalcPrice'])
        ->name('llantas.price.recalc');

    // PRODUCTOS COMPUESTOS
    Route::get('/productos', [ProductoCompuestoController::class, 'indexWeb'])->name('productos.index');
    Route::get('/productos/{id}/editar', [ProductoCompuestoController::class, 'editWeb'])->name('productos.edit');
    Route::put('/productos/{id}', [ProductoCompuestoController::class, 'updateWeb'])->name('productos.update');

    Route::post('/productos/{compuesto}/price/manual', [ProductoCompuestoController::class, 'setPriceManual'])
        ->name('productos.price.manual');

    Route::post('/productos/{compuesto}/price/auto', [ProductoCompuestoController::class, 'setPriceAuto'])
        ->name('productos.price.auto');

    Route::post('/productos/{compuesto}/price/recalc', [ProductoCompuestoController::class, 'recalcPrice'])
        ->name('productos.price.recalc');

    // EXCEL
    Route::get('/importar-excel', [ExcelImportController::class, 'vista'])->name('excel.vista');
    Route::post('/importar-excel', [ExcelImportController::class, 'importar'])->name('excel.importar');

    // SYSCOM → MERCADO LIBRE
    Route::get('/syscom-ml', [SyscomMeliController::class, 'index'])->name('syscom.meli.index');
    Route::get('/syscom-ml/{id}/editar', [SyscomMeliController::class, 'editWeb'])->name('syscom.meli.edit');
    Route::put('/syscom-ml/{id}', [SyscomMeliController::class, 'updateWeb'])->name('syscom.meli.update');

    Route::post('/syscom-ml/{id}/price/manual', [SyscomMeliController::class, 'setPriceManual'])
        ->name('syscom.meli.price.manual');

    Route::post('/syscom-ml/{id}/price/auto', [SyscomMeliController::class, 'setPriceAuto'])
        ->name('syscom.meli.price.auto');

    Route::post('/syscom-ml/{id}/price/recalc', [SyscomMeliController::class, 'recalcPrice'])
        ->name('syscom.meli.price.recalc');

    Route::get('/syscom-ml/meli-categories/browse', [SyscomMeliController::class, 'meliCategoriesBrowse'])
        ->name('syscom.meli.categories.browse');

    Route::get('/syscom-ml/meli-categories/search', [SyscomMeliController::class, 'meliCategoriesSearch'])
        ->name('syscom.meli.categories.search');

    Route::post('/syscom-ml/sync-catalog', [SyscomMeliController::class, 'requestCatalogSync'])
        ->name('syscom.meli.sync');

    Route::post('/syscom-ml/import-search', [SyscomMeliController::class, 'importSearchFromSyscom'])
        ->name('syscom.meli.import_search');

    Route::post('/syscom-ml/refresh-status', [SyscomMeliController::class, 'refreshPublicationStatus'])
        ->name('syscom.meli.refresh_status');

    Route::post('/syscom-ml/refresh-prices-page', [SyscomMeliController::class, 'refreshPricesOnPage'])
        ->name('syscom.meli.refresh_prices_page');

    Route::post('/syscom-ml/sync-prices-page', [SyscomMeliController::class, 'syncPricesOnPage'])
        ->name('syscom.meli.sync_prices_page');

    Route::post('/syscom-ml/{id}/sync-price', [SyscomMeliController::class, 'syncPriceToMl'])
        ->whereNumber('id')
        ->name('syscom.meli.sync_price');

    Route::post('/syscom-ml/publish/{id}', [SyscomMeliController::class, 'publish'])
        ->name('syscom.meli.publish');

    Route::post(
        '/syscom-ml/publish-categorized-marketmax',
        [
            SyscomMeliController::class,
            'publishCategorizedMarketmax',
        ]
    )->name(
        'syscom.meli.publish_categorized_marketmax'
    );

    // PRICE RULES
    Route::get('/price-rules', [PriceRulesController::class, 'index'])->name('price_rules.index');
    Route::post('/price-rules', [PriceRulesController::class, 'update'])->name('price_rules.update');
    Route::post('/price-rules/test', [PriceRulesController::class, 'test'])->name('price_rules.test');

    // SETTINGS
    Route::redirect('settings', 'settings/profile')->name('settings.index');

    Route::get('settings/profile', function () {
        return inertia('Settings/Profile');
    })->name('profile.edit');

    Route::patch('settings/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::get('settings/channels', [ChannelSettingsController::class, 'index'])
        ->name('channels.index');
    Route::post('settings/channels/shopify/test', [ChannelSettingsController::class, 'testShopify'])
        ->name('channels.shopify.test');
    Route::post('settings/channels/amazon/test', [ChannelSettingsController::class, 'testAmazon'])
        ->name('channels.amazon.test');
    Route::post('settings/channels/save', [ChannelSettingsController::class, 'save'])
        ->name('channels.save');
    Route::get('/auth/shopify', [ChannelSettingsController::class, 'redirectToShopify'])
        ->name('shopify.redirect');

    Route::get('settings/password', function () {
        return inertia('Settings/Password');
    })->name('user-password.edit');

    Route::patch('settings/password', [PasswordController::class, 'update'])
        ->name('user-password.update');

    Route::get('settings/appearance', function () {
        return inertia('Settings/Appearance');
    })->name('appearance.edit');

    Route::get('settings/two-factor', function () {
        return inertia('Settings/TwoFactor');
    })
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                && Features::optionEnabled(
                    Features::twoFactorAuthentication(),
                    'confirmPassword'
                ),
                ['password.confirm'],
                [],
            )
        )
        ->name('two-factor.show');
});

Route::get('/', fn () => redirect()->route('login'));
Route::get('/home', fn () => redirect('/dashboard'))->name('home');
