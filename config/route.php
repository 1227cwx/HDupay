<?php

use app\controller\AdminAddressController;
use app\controller\AdminAuthController;
use app\controller\AdminCollectionController;
use app\controller\AdminDashboardController;
use app\controller\AdminOpenApiController;
use app\controller\AdminOrderController;
use app\controller\AdminProxyController;
use app\controller\AdminRpcConfigController;
use app\controller\AdminSystemController;
use app\controller\AdminWalletController;
use app\controller\AdminWithdrawController;
use app\controller\DepositController;
use app\controller\EasyPayController;
use app\controller\IndexController;
use app\controller\OpenApiController;
use app\middleware\AdminAuthMiddleware;
use app\middleware\AdminDomainMiddleware;
use app\middleware\PublicDomainMiddleware;
use Webman\Route;

Route::disableDefaultRoute();

/*
 * Admin frontend SPA routes.
 */
Route::group('/hdupay', function () {
    Route::get('', [IndexController::class, 'index']);
    Route::get('/login', [IndexController::class, 'index']);
    Route::get('/overview', [IndexController::class, 'index']);
    Route::get('/dashboard', [IndexController::class, 'index']);
    Route::get('/rpc', [IndexController::class, 'index']);
    Route::get('/network-settings', [IndexController::class, 'index']);
    Route::get('/proxies', [IndexController::class, 'index']);
    Route::get('/wallet-settings', [IndexController::class, 'index']);
    Route::get('/wallet', [IndexController::class, 'index']);
    Route::get('/collection-wallets', [IndexController::class, 'index']);
    Route::get('/gas-wallets', [IndexController::class, 'index']);
    Route::get('/addresses', [IndexController::class, 'index']);
    Route::get('/orders', [IndexController::class, 'index']);
    Route::get('/collections', [IndexController::class, 'index']);
    Route::get('/withdraw-settings', [IndexController::class, 'index']);
    Route::get('/withdrawals', [IndexController::class, 'index']);
    Route::get('/deposit-create', [IndexController::class, 'index']);
    Route::get('/open-api', [IndexController::class, 'index']);
    Route::get('/admin-profile', [IndexController::class, 'index']);
    Route::get('/fiat-rates', [IndexController::class, 'index']);
    Route::get('/admin-settings', [IndexController::class, 'index']);
})->middleware(AdminDomainMiddleware::class);

/*
 * Public pay routes.
 */
Route::group('/pay', function () {
    Route::get('', [IndexController::class, 'pay']);
})->middleware(PublicDomainMiddleware::class);
Route::get('/submit.php', [EasyPayController::class, 'submit'])->middleware(PublicDomainMiddleware::class);
Route::post('/submit.php', [EasyPayController::class, 'submit'])->middleware(PublicDomainMiddleware::class);
Route::group('/api/deposit', function () {
    Route::post('/create', [DepositController::class, 'create']);
    Route::get('/networks', [DepositController::class, 'networks']);
    Route::get('/options', [DepositController::class, 'options']);
    Route::post('/status', [DepositController::class, 'status']);
})->middleware(PublicDomainMiddleware::class);
Route::group('/api/easypay', function () {
    Route::post('/detail', [EasyPayController::class, 'detail']);
})->middleware(PublicDomainMiddleware::class);

/*
 * OpenAPI routes. All endpoints use POST.
 */
Route::group('/api/v1', function () {
    Route::post('/networks', [OpenApiController::class, 'networks']);
    Route::post('/orders/create', [OpenApiController::class, 'createOrder']);
    Route::post('/orders/status', [OpenApiController::class, 'orderStatus']);
})->middleware(PublicDomainMiddleware::class);

/*
 * Admin API routes. Auth login routes are public, other routes require login.
 */
Route::group('/admin', function () {
    Route::group('/auth', function () {
        Route::post('/login', [AdminAuthController::class, 'login']);
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::get('/me', [AdminAuthController::class, 'me']);
    });
})->middleware(AdminDomainMiddleware::class);

Route::group('/admin', function () {
    Route::get('/dashboard/summary', [AdminDashboardController::class, 'summary']);

    Route::post('/auth/profile/update', [AdminAuthController::class, 'updateProfile']);
    Route::post('/auth/password/update', [AdminAuthController::class, 'updatePassword']);

    Route::group('/rpc-config', function () {
        Route::get('/list', [AdminRpcConfigController::class, 'list']);
        Route::post('/network/save', [AdminRpcConfigController::class, 'saveNetwork']);
        Route::post('/group/save', [AdminRpcConfigController::class, 'saveGroup']);
        Route::post('/group/delete', [AdminRpcConfigController::class, 'deleteGroup']);
        Route::post('/node/save', [AdminRpcConfigController::class, 'saveNode']);
        Route::post('/node/toggle', [AdminRpcConfigController::class, 'toggleNode']);
        Route::post('/node/delete', [AdminRpcConfigController::class, 'deleteNode']);
        Route::post('/test', [AdminRpcConfigController::class, 'test']);
        Route::post('/test-node', [AdminRpcConfigController::class, 'testNode']);
        Route::post('/test-group', [AdminRpcConfigController::class, 'testGroup']);
    });

    Route::group('/proxy', function () {
        Route::get('/list', [AdminProxyController::class, 'list']);
        Route::get('/enabled', [AdminProxyController::class, 'enabled']);
        Route::post('/save', [AdminProxyController::class, 'save']);
        Route::post('/delete', [AdminProxyController::class, 'delete']);
        Route::post('/toggle', [AdminProxyController::class, 'toggle']);
        Route::post('/test', [AdminProxyController::class, 'test']);
    });

    Route::group('/open-api', function () {
        Route::get('/list', [AdminOpenApiController::class, 'list']);
        Route::post('/save', [AdminOpenApiController::class, 'save']);
        Route::post('/secret/reset', [AdminOpenApiController::class, 'resetSecret']);
        Route::post('/toggle', [AdminOpenApiController::class, 'toggle']);
        Route::post('/delete', [AdminOpenApiController::class, 'delete']);
    });

    Route::group('/wallet', function () {
        Route::post('/initialize', [AdminWalletController::class, 'initialize']);
        Route::get('/overview', [AdminWalletController::class, 'overview']);
        Route::get('/masters', [AdminWalletController::class, 'masters']);
        Route::get('/accounts', [AdminWalletController::class, 'accounts']);
        Route::get('/account/balance', [AdminWalletController::class, 'accountBalance']);
        Route::post('/account/create', [AdminWalletController::class, 'createAccount']);
        Route::post('/account/toggle', [AdminWalletController::class, 'toggleAccount']);
        Route::post('/account/collection-target/save', [AdminWalletController::class, 'saveCollectionTarget']);
        Route::post('/root-private-key/export', [AdminWalletController::class, 'exportRootPrivateKey']);
        Route::post('/root/delete', [AdminWalletController::class, 'deleteRootWallet']);
        Route::post('/account/save', [AdminWalletController::class, 'updateAccount']);
        Route::get('/collection-wallets', [AdminWalletController::class, 'collectionWallets']);
        Route::post('/collection-wallets/config/save', [AdminWalletController::class, 'saveCollectionWalletConfig']);
        Route::post('/collection-address/add', [AdminWalletController::class, 'addCollectionAddress']);
        Route::post('/collection-address/toggle', [AdminWalletController::class, 'toggleCollectionAddress']);
        Route::post('/collection-address/sync-toggle', [AdminWalletController::class, 'toggleCollectionAddressSync']);
        Route::post('/collection-address/delete', [AdminWalletController::class, 'deleteCollectionAddress']);
        Route::post('/collection-address/sync', [AdminWalletController::class, 'syncCollectionAddress']);
        Route::post('/collection-wallets/sync-all', [AdminWalletController::class, 'syncAllCollectionWallets']);
        Route::get('/gas-wallets', [AdminWalletController::class, 'gasWallets']);
        Route::post('/gas-wallets/config/save', [AdminWalletController::class, 'saveGasWalletConfig']);
        Route::post('/gas-wallet/sync-toggle', [AdminWalletController::class, 'toggleGasWalletSync']);
        Route::post('/gas-wallet/sync', [AdminWalletController::class, 'syncGasWallet']);
        Route::post('/gas-wallets/sync-all', [AdminWalletController::class, 'syncAllGasWallets']);
    });

    Route::get('/address/list', [AdminAddressController::class, 'list']);

    Route::group('/deposit', function () {
        Route::get('/networks', [AdminOrderController::class, 'networks']);
        Route::get('/options', [AdminOrderController::class, 'options']);
        Route::get('/list', [AdminOrderController::class, 'list']);
        Route::post('/create', [AdminOrderController::class, 'create']);
        Route::get('/detail', [AdminOrderController::class, 'detail']);
        Route::post('/callback', [AdminOrderController::class, 'callback']);
    });

    Route::group('/system', function () {
        Route::get('/settings', [AdminSystemController::class, 'settings']);
        Route::post('/site/save', [AdminSystemController::class, 'saveSite']);
        Route::post('/fiat-rate/save', [AdminSystemController::class, 'saveFiatRate']);
        Route::post('/fiat-rate/test', [AdminSystemController::class, 'testFiatRate']);
        Route::post('/fiat-rate/refresh', [AdminSystemController::class, 'refreshFiatRate']);
        Route::post('/fiat-rate/toggle-currency', [AdminSystemController::class, 'toggleFiatCurrency']);
    });

    Route::group('/collection', function () {
        Route::get('/config', [AdminCollectionController::class, 'config']);
        Route::post('/config/save', [AdminCollectionController::class, 'saveConfig']);
        Route::get('/list', [AdminCollectionController::class, 'list']);
        Route::post('/retry', [AdminCollectionController::class, 'retry']);
        Route::post('/manual-create', [AdminCollectionController::class, 'manualCreate']);
        Route::post('/process', [AdminCollectionController::class, 'process']);
        Route::post('/process-all', [AdminCollectionController::class, 'processAll']);
        Route::post('/process-one', [AdminCollectionController::class, 'processOne']);
    });

    Route::group('/withdraw', function () {
        Route::get('/config', [AdminWithdrawController::class, 'config']);
        Route::post('/config/save', [AdminWithdrawController::class, 'saveConfig']);
        Route::get('/settings', [AdminWithdrawController::class, 'settings']);
        Route::post('/setting/save', [AdminWithdrawController::class, 'saveSetting']);
        Route::get('/list', [AdminWithdrawController::class, 'list']);
        Route::post('/preview', [AdminWithdrawController::class, 'preview']);
        Route::post('/create', [AdminWithdrawController::class, 'create']);
        Route::post('/process-all', [AdminWithdrawController::class, 'processAll']);
        Route::post('/process-one', [AdminWithdrawController::class, 'processOne']);
    });
})->middleware(AdminAuthMiddleware::class);
