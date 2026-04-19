<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api;

// =============================================
// ROUTES PUBLIQUES
// =============================================

// Fallback media route to bypass strict Nginx /storage/ directives on Laravel Cloud
Route::get('media/{path}', function ($path) {
    if (!\Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
        abort(404);
    }
    return response()->file(\Illuminate\Support\Facades\Storage::disk('public')->path($path));
})->where('path', '.*');

Route::get('test-email', function (\Illuminate\Http\Request $request) {
    $to = $request->query('email', 'sewodakomla@gmail.com');
    try {
        \Illuminate\Support\Facades\Mail::raw("Ceci est un test de configuration SMTP pour Smartflow POS. Si vous recevez cet e-mail, la configuration Brevo est fonctionnelle.", function ($message) use ($to) {
            $message->to($to)
                ->subject("🧪 TEST SMTP Smartflow POS")
                ->from(config('mail.from.address'), config('mail.from.name'));
        });
        return response()->json([
            'message' => "E-mail de test envoyé à $to",
            'from' => config('mail.from.address'),
            'status' => 'success'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'message' => "Erreur d'envoi : " . $e->getMessage(),
            'status' => 'error'
        ], 500);
    }
});


Route::prefix('auth')->group(function () {
    Route::post('login',     [Api\Auth\AuthController::class, 'login']);
    Route::post('login-pin', [Api\Auth\AuthController::class, 'loginPin']);
});

Route::get('menu/{restaurantSlug}', [Api\ProductController::class, 'publicMenu']);
Route::get('orders/{orderId}/ticket', [Api\ReceiptController::class, 'ticket']);
Route::get('customer-tabs/{tab}/invoice', [Api\CustomerTabController::class, 'invoice']);
Route::get('receipts/{orderId}/kitchen-ticket', [Api\ReceiptController::class, 'kitchenTicket']);
Route::get('expenses/{expense}/receipt',        [Api\ExpenseController::class, 'receipt']);
Route::get('cash-sessions/{session}/report-preview',   [Api\CashSessionController::class, 'reportPreview']);

// =============================================
// ROUTES PROTÉGÉES
// =============================================
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('logout',         [Api\Auth\AuthController::class, 'logout']);
        Route::get('me',              [Api\Auth\AuthController::class, 'me']);
        Route::put('change-password', [Api\Auth\AuthController::class, 'changePassword']);
    });

    // Utilisateurs & Rôles
    Route::apiResource('users', Api\UserController::class);
    Route::patch('users/{user}/toggle-active', [Api\UserController::class, 'toggleActive']);
    Route::get('roles', [Api\RoleController::class, 'index']);

    // Salles
    Route::apiResource('floors', Api\FloorController::class);
    Route::get('floors/{floor}/tables',            [Api\FloorController::class, 'getTables']);
    Route::post('floors/{floor}/tables',           [Api\FloorController::class, 'addTable']);
    Route::delete('floors/{floor}/tables/{table}', [Api\FloorController::class, 'removeTable']);

    // Tables
    Route::get('tables',                   [Api\TableController::class, 'allTables']);
    Route::put('tables/{table}/status',    [Api\TableController::class, 'updateStatus']);
    Route::put('tables/{table}/layout',    [Api\TableController::class, 'updateLayout']);
    Route::put('tables/{table}/assign',    [Api\TableController::class, 'assign']);
    Route::post('tables/{table}/reserve',  [Api\TableController::class, 'reserve']);
    Route::post('tables/{table}/transfer', [Api\TableController::class, 'transfer']);
    Route::post('tables/merge',            [Api\TableController::class, 'merge']);

    // Commandes
    Route::apiResource('orders', Api\OrderController::class)->except(['destroy']);
    Route::get('orders/table/{tableId}/current',      [Api\OrderController::class, 'currentByTable']);
    Route::put('orders/{order}/items',                [Api\OrderController::class, 'updateItems']);
    Route::post('orders/{order}/add-items',           [Api\OrderController::class, 'addItems']);
    Route::post('orders/{order}/send-to-kitchen',     [Api\OrderController::class, 'sendToKitchen']);
    Route::put('orders/{order}/discount',             [Api\OrderController::class, 'applyDiscount']);

    // Paiements
    Route::post('payments',       [Api\PaymentController::class, 'store']);
    Route::post('payments/split', [Api\PaymentController::class, 'split']);

    // ------------------------------------------------
    // CAISSE — Sessions + Dépenses + Rapport
    // ------------------------------------------------
    Route::get('cash-sessions/current',                    [Api\CashSessionController::class, 'current']);
    Route::post('cash-sessions/open',                      [Api\CashSessionController::class, 'open']);
    Route::get('cash-sessions',                            [Api\CashSessionController::class, 'index']);
    Route::post('cash-sessions/{session}/close',           [Api\CashSessionController::class, 'close']);
    Route::post('cash-sessions/{session}/send-report',     [Api\CashSessionController::class, 'sendReport']);

    // Dépenses de caisse
    Route::get('expenses',              [Api\ExpenseController::class, 'index']);
    Route::post('expenses',             [Api\ExpenseController::class, 'store']);
    Route::delete('expenses/{expense}', [Api\ExpenseController::class, 'destroy']);
    Route::get('expenses/summary',      [Api\ExpenseController::class, 'summary']);

    // ------------------------------------------------
    // CUISINE / BAR / PIZZA — KDS par destination
    // ------------------------------------------------
    Route::prefix('kitchen')->group(function () {
        // ?destination=kitchen|bar|pizza
        Route::get('orders',                                  [Api\KitchenController::class, 'orders']);
        Route::put('items/{item}/status',                     [Api\KitchenController::class, 'updateItemStatus']);
        Route::put('orders/{order}/validate-all',             [Api\KitchenController::class, 'validateAll']);
        // Ticket impression SANS prix pour une destination
        Route::get('orders/{order}/ticket',                   [Api\KitchenController::class, 'ticket']);
    });

    // ------------------------------------------------
    // ARDOISES CLIENTS (Consommation Différée)
    // ------------------------------------------------
    Route::prefix('customer-tabs')->group(function () {
        Route::get('/',                           [Api\CustomerTabController::class, 'index']);
        Route::post('/',                          [Api\CustomerTabController::class, 'store']);
        Route::get('/{tab}',                      [Api\CustomerTabController::class, 'show']);
        Route::post('/{tab}/attach',              [Api\CustomerTabController::class, 'attachOrder']);
        Route::post('/{tab}/pay',                 [Api\CustomerTabController::class, 'pay']);
        Route::post('/{tab}/cancel',              [Api\CustomerTabController::class, 'cancel']);
        Route::post('/{tab}/print-network',       [Api\CustomerTabController::class, 'printNetwork']);
    });

    // ------------------------------------------------
    // COMMANDES GÂTEAUX
    // ------------------------------------------------
    Route::prefix('cake-orders')->group(function () {
        Route::get('/',                               [Api\CakeOrderController::class, 'index']);
        Route::post('/',                              [Api\CakeOrderController::class, 'store']);
        Route::get('/{cakeOrder}',                    [Api\CakeOrderController::class, 'show']);
        Route::put('/{cakeOrder}',                    [Api\CakeOrderController::class, 'update']);
        Route::patch('/{cakeOrder}/status',           [Api\CakeOrderController::class, 'updateStatus']);
        Route::post('/{cakeOrder}/collect',           [Api\CakeOrderController::class, 'collect']);
        Route::get('/{cakeOrder}/ticket',             [Api\CakeOrderController::class, 'ticket']);
        Route::get('/{cakeOrder}/pdf',                [Api\CakeOrderController::class, 'receiptPdf']);
        Route::post('/{cakeOrder}/print-network',     [Api\CakeOrderController::class, 'printNetwork']);
    });

    // Catégories
    Route::apiResource('categories', Api\CategoryController::class);
    Route::get('categories-flat',          [Api\CategoryController::class, 'flat']);
    Route::post('categories/reorder',      [Api\CategoryController::class, 'reorder']);

    // Produits
    Route::apiResource('products', Api\ProductController::class);
    Route::patch('products/{product}/toggle-available', [Api\ProductController::class, 'toggleAvailable']);
    Route::post('products/reorder',                     [Api\ProductController::class, 'reorder']);

    // Menus composés
    Route::apiResource('combos', Api\ComboMenuController::class);

    // Ingrédients & Stock
    Route::apiResource('ingredients', Api\IngredientController::class);
    Route::get('ingredients-categories',             [Api\IngredientController::class, 'categories']);
    Route::get('stock/alerts',                       [Api\StockController::class, 'alerts']);
    Route::get('stock/value',                        [Api\StockController::class, 'value']);
    Route::post('stock/movements',                   [Api\StockController::class, 'createMovement']);
    Route::get('ingredients/{ingredient}/movements', [Api\StockController::class, 'movements']);

    // Recettes
    Route::get('recipes/{productId}', [Api\StockController::class, 'getRecipe']);
    Route::post('recipes',            [Api\StockController::class, 'saveRecipe']);

    // Livraisons
    Route::apiResource('deliveries', Api\DeliveryController::class)->except(['destroy']);
    Route::get('deliveries/drivers',               [Api\DeliveryController::class, 'availableDrivers']);
    Route::put('deliveries/{delivery}/assign',     [Api\DeliveryController::class, 'assign']);
    Route::put('deliveries/{delivery}/status',     [Api\DeliveryController::class, 'updateStatus']);

    // Annulations
    Route::get('cancellations',                         [Api\CancellationController::class, 'index']);
    Route::post('cancellations/request',                [Api\CancellationController::class, 'request']);
    Route::post('cancellations/{cancellation}/approve', [Api\CancellationController::class, 'approve']);
    Route::post('cancellations/{cancellation}/reject',  [Api\CancellationController::class, 'reject']);

    // Rapports
    Route::prefix('reports')->group(function () {
        Route::get('dashboard',        [Api\ReportController::class, 'dashboard']);
        Route::get('sales',            [Api\ReportController::class, 'sales']);
        Route::get('top-products',     [Api\ReportController::class, 'topProducts']);
        Route::get('department-sales',     [Api\ReportController::class, 'departmentSales']);
        Route::get('department-sales/pdf', [Api\ReportController::class, 'departmentSalesPdf']);
        Route::get('daily-analysis/pdf',   [Api\ReportController::class, 'dailyAnalysisPdf']);
        Route::get('by-waiter',        [Api\ReportController::class, 'byWaiter']);
        Route::get('by-category',      [Api\ReportController::class, 'byCategory']);
        Route::get('cash-summary',     [Api\ReportController::class, 'cashSummary']);
        Route::get('tax-summary',      [Api\ReportController::class, 'taxSummary']);
        Route::get('export-all',       [Api\ExportController::class, 'export']);
        Route::post('purge-data',      [Api\ExportController::class, 'purge']);
    });

    Route::prefix('receipts')->group(function () {
        Route::get('{orderId}',             [Api\ReceiptController::class, 'show']);
        Route::get('{orderId}/pdf',         [Api\ReceiptController::class, 'pdf']);
        Route::get('{orderId}/html',        [Api\ReceiptController::class, 'html']);
        Route::get('{orderId}/a4',          [Api\ReceiptController::class, 'invoiceA4']);
        Route::post('bulk-a4',              [Api\ReceiptController::class, 'bulkA4']);
        Route::post('bulk-network',         [Api\ReceiptController::class, 'bulkPrintNetwork']);
        Route::post('{orderId}/print-network', [Api\ReceiptController::class, 'printNetwork']);
        Route::post('{orderId}/send-email', [Api\ReceiptController::class, 'sendEmail']);
    });

    // QR Code
    Route::prefix('qr')->group(function () {
        Route::get('{tableId}',           [Api\QrCodeController::class, 'generate']);
        Route::get('{tableId}/url',       [Api\QrCodeController::class, 'url']);
        Route::get('floor/{floorId}/all', [Api\QrCodeController::class, 'allForFloor']);
    });

    // Paramètres
    Route::get('settings',        [Api\SettingsController::class, 'show']);
    Route::put('settings',        [Api\SettingsController::class, 'update']);
    Route::get('settings/config', [Api\SettingsController::class, 'getConfig']);
    Route::put('settings/config', [Api\SettingsController::class, 'updateConfig']);

    // Fournisseurs
    Route::apiResource('suppliers', Api\SupplierController::class);

    // Audit Trail
    Route::prefix('activity-logs')->group(function () {
        Route::get('/',                   [Api\ActivityLogController::class, 'index']);
        Route::get('summary',             [Api\ActivityLogController::class, 'summary']);
        Route::get('subject/{type}/{id}', [Api\ActivityLogController::class, 'forSubject']);
    });

    // Notifications
    Route::get('notifications', [Api\NotificationController::class, 'index']);
});
