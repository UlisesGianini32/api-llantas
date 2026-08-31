<?php

use App\Http\Controllers\AmsPedidosController;
use App\Http\Controllers\AmsSecondaryOrdersController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExcelImportController;
use App\Http\Controllers\LlantaController;
use App\Http\Controllers\MeliBatchRepublishController;
use App\Http\Controllers\MeliCompareController;
use App\Http\Controllers\MeliClaimController;
use App\Http\Controllers\MeliClaimMessageController;
use App\Http\Controllers\MeliClaimAttachmentController;
use App\Http\Controllers\MeliClaimResolutionController;
use App\Http\Controllers\MeliFullStockController;
use App\Http\Controllers\MeliMessagingController;
use App\Http\Controllers\MeliPriceManager\MeliAccountTaxProfileController;
use App\Http\Controllers\MeliPriceManager\MeliBrandAliasController;
use App\Http\Controllers\MeliPriceManager\MeliBrandGroupController;
use App\Http\Controllers\MeliPriceManager\MeliBrandReclassificationController;
use App\Http\Controllers\MeliPriceManager\MeliItemClassificationActionController;
use App\Http\Controllers\MeliPriceManager\MeliPriceManagerDashboardController;
use App\Http\Controllers\MeliPriceManager\MeliPriceSimulationController;
use App\Http\Controllers\MeliPriceManager\MeliPriceUpdateController;
use App\Http\Controllers\MeliPriceManager\MeliUncategorizedItemController;
use App\Http\Controllers\MeliQuestionController;
use App\Http\Controllers\MeliPublishController;
use App\Http\Controllers\MeliRepublishController;
use App\Http\Controllers\MeliSecondaryPublicationController;
use App\Http\Controllers\PriceRulesController;
use App\Http\Controllers\ProductoCompuestoController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ProductoSyncController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\SyscomMeliController;
use App\Http\Controllers\SystemHealthController;
use App\Http\Controllers\SystemActionController;
use App\Http\Controllers\SystemLogController;
use App\Http\Controllers\SystemQueueController;
use App\Http\Controllers\SystemServerController;
use App\Http\Controllers\QzTrayController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

// CALLBACK (fuera de auth)
Route::get('/auth/meli/callback', [AuthController::class, 'handleMeliCallback'])
    ->name('meli.callback');

Route::middleware('auth')->group(function () {
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
    Route::get('/ams/pedidos-procesar', [AmsPedidosController::class, 'procesar'])->name('ams.pedidos.procesar');
    Route::get('/ams/pedidos-manana', [AmsPedidosController::class, 'procesarManana'])->name('ams.pedidos.manana');

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

        //QZTRAY
        Route::get('/qz/certificate', [QzTrayController::class, 'certificate'])
    ->name('qz.certificate');

    Route::post('/qz/sign', [QzTrayController::class, 'sign'])
    ->name('qz.sign');

    // MERCADO LIBRE
    Route::prefix('/meli-price-manager')->name('meli-price-manager.')->group(function () {
        Route::get('/', [MeliPriceManagerDashboardController::class, 'index'])->name('index');
        Route::post('/sync', [MeliPriceManagerDashboardController::class, 'sync'])->name('sync');
        Route::put('/tax-profile', [MeliAccountTaxProfileController::class, 'update'])->name('tax-profile.update');
        Route::post('/items/{item}/simulate-price', MeliPriceSimulationController::class)
            ->whereNumber('item')
            ->name('items.price.simulate');
        Route::put('/items/{item}/price', MeliPriceUpdateController::class)
            ->whereNumber('item')
            ->name('items.price.update');

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
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', function () {
        return inertia('Settings/Profile');
    })->name('profile.edit');

    Route::patch('settings/profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    Route::get('settings/password', function () {
        return inertia('Settings/Password');
    })->name('user-password.edit');

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
