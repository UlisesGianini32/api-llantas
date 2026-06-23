<?php

use App\Http\Controllers\AmsPedidosController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExcelImportController;
use App\Http\Controllers\LlantaController;
use App\Http\Controllers\MeliCompareController;
use App\Http\Controllers\MeliMessagingController;
use App\Http\Controllers\MeliPublishController;
use App\Http\Controllers\MeliRepublishController;
use App\Http\Controllers\PriceRulesController;
use App\Http\Controllers\ProductoCompuestoController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\ProductoSyncController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\SyscomMeliController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

// CALLBACK (fuera de auth)
Route::get('/auth/meli/callback', [AuthController::class, 'handleMeliCallback'])
    ->name('meli.callback');

Route::middleware('auth')->group(function () {
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

    // PEDIDOS
    Route::get('/ams/pedidos', [AmsPedidosController::class, 'index'])->name('ams.pedidos.index');
    Route::get('/ams/pedidos-procesar', [AmsPedidosController::class, 'procesar'])->name('ams.pedidos.procesar');
    Route::get('/ams/pedidos-manana', [AmsPedidosController::class, 'procesarManana'])->name('ams.pedidos.manana');
    Route::get('/ams/pedidos/shipping-label/{shippingId}/print', [AmsPedidosController::class, 'shippingLabelPrintPage'])
        ->name('ams.pedidos.shipping_label_print');
    Route::get('/ams/pedidos/shipping-label/{shippingId}', [AmsPedidosController::class, 'printShippingLabel'])
        ->name('ams.pedidos.shipping_label');

    // MERCADO LIBRE
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

    // SYSCOM → MERCADO LIBRE (catálogo sucursal Hermosillo, publicación 1 clic)
    Route::get('/syscom-ml', [SyscomMeliController::class, 'index'])->name('syscom.meli.index');
    Route::get('/syscom-ml/{id}/editar', [SyscomMeliController::class, 'editWeb'])->name('syscom.meli.edit');
    Route::put('/syscom-ml/{id}', [SyscomMeliController::class, 'updateWeb'])->name('syscom.meli.update');
    Route::post('/syscom-ml/{id}/price/manual', [SyscomMeliController::class, 'setPriceManual'])->name('syscom.meli.price.manual');
    Route::post('/syscom-ml/{id}/price/auto', [SyscomMeliController::class, 'setPriceAuto'])->name('syscom.meli.price.auto');
    Route::post('/syscom-ml/{id}/price/recalc', [SyscomMeliController::class, 'recalcPrice'])->name('syscom.meli.price.recalc');
    Route::get('/syscom-ml/meli-categories/browse', [SyscomMeliController::class, 'meliCategoriesBrowse'])->name('syscom.meli.categories.browse');
    Route::get('/syscom-ml/meli-categories/search', [SyscomMeliController::class, 'meliCategoriesSearch'])->name('syscom.meli.categories.search');
    Route::post('/syscom-ml/sync-catalog', [SyscomMeliController::class, 'requestCatalogSync'])->name('syscom.meli.sync');
    Route::post('/syscom-ml/import-search', [SyscomMeliController::class, 'importSearchFromSyscom'])->name('syscom.meli.import_search');
    Route::post('/syscom-ml/refresh-status', [SyscomMeliController::class, 'refreshPublicationStatus'])->name('syscom.meli.refresh_status');
    Route::post('/syscom-ml/refresh-prices-page', [SyscomMeliController::class, 'refreshPricesOnPage'])->name('syscom.meli.refresh_prices_page');
    Route::post('/syscom-ml/sync-prices-page', [SyscomMeliController::class, 'syncPricesOnPage'])->name('syscom.meli.sync_prices_page');
    Route::post('/syscom-ml/{id}/sync-price', [SyscomMeliController::class, 'syncPriceToMl'])
        ->whereNumber('id')
        ->name('syscom.meli.sync_price');
    Route::post('/syscom-ml/publish/{id}', [SyscomMeliController::class, 'publish'])->name('syscom.meli.publish');

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
                && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            )
        )
        ->name('two-factor.show');
});

Route::get('/', fn () => redirect()->route('login'));
Route::get('/home', fn () => redirect('/dashboard'))->name('home');