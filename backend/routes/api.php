<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DebtController;
use App\Http\Controllers\Api\V1\HeirController;
use App\Http\Controllers\Api\V1\HeirRequestController;
use App\Http\Controllers\Api\V1\InvestmentController;
use App\Http\Controllers\Api\V1\LinkController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');

    // Public: an heir asks for access (generic response, rate-limited).
    Route::post('heir-requests', [HeirRequestController::class, 'store'])->middleware('throttle:heir-request')->name('heir-requests.store');

    // Emailed one-click actions: signed + expiring, no login.
    Route::middleware(['signed:relative', 'throttle:30,1'])->prefix('links')->name('links.')->group(function () {
        Route::match(['get', 'post'], 'check-in/{user}', [LinkController::class, 'checkIn'])->name('check-in');
        Route::match(['get', 'post'], 'confirm/{heirRequest}/{contact}', [LinkController::class, 'confirm'])->name('confirm');
        Route::match(['get', 'post'], 'cancel/{heirRequest}', [LinkController::class, 'cancel'])->name('cancel');
        Route::match(['get', 'post'], 'activate/{heir}', [LinkController::class, 'activate'])->name('activate');
    });

    Route::middleware(['auth:sanctum', 'throttle:api', 'activity', 'heir.readonly'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::patch('auth/me', [AuthController::class, 'update'])->name('auth.me.update');

        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('reports', ReportController::class)->name('reports');

        Route::get('transactions/quick-meta', [TransactionController::class, 'quickMeta'])->name('transactions.quick-meta');
        Route::apiResource('accounts', AccountController::class);
        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('transactions', TransactionController::class);

        Route::apiResource('debts', DebtController::class);
        Route::post('debts/{debt}/payments', [DebtController::class, 'addPayment'])->name('debts.payments.store');
        Route::delete('debts/{debt}/payments/{payment}', [DebtController::class, 'deletePayment'])->name('debts.payments.destroy');

        Route::apiResource('bills', BillController::class)->parameters(['bills' => 'id']);
        Route::post('bills/{id}/pay', [BillController::class, 'pay'])->name('bills.pay');
        Route::apiResource('subscriptions', SubscriptionController::class)->parameters(['subscriptions' => 'id']);
        Route::post('subscriptions/{id}/pay', [SubscriptionController::class, 'pay'])->name('subscriptions.pay');

        Route::apiResource('investments', InvestmentController::class);
        Route::post('investments/{investment}/values', [InvestmentController::class, 'addValue'])->name('investments.values.store');
        Route::delete('investments/{investment}/values/{value}', [InvestmentController::class, 'deleteValue'])->name('investments.values.destroy');

        Route::apiResource('assets', AssetController::class);

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/unread', [NotificationController::class, 'unread'])->name('notifications.unread');
        Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');
        Route::get('push/key', [NotificationController::class, 'pushKey'])->name('push.key');
        Route::post('push/subscribe', [NotificationController::class, 'subscribe'])->name('push.subscribe');
        Route::post('push/unsubscribe', [NotificationController::class, 'unsubscribe'])->name('push.unsubscribe');

        Route::get('heir', [HeirController::class, 'show'])->name('heir.show');
        Route::patch('heir/settings', [HeirController::class, 'updateSettings'])->name('heir.settings');
        Route::post('heir/check-in', [HeirController::class, 'checkIn'])->name('heir.check-in');
        Route::post('heir/revoke', [HeirController::class, 'revoke'])->name('heir.revoke');
        Route::post('heirs', [HeirController::class, 'storeHeir'])->name('heirs.store');
        Route::patch('heirs/{heir}', [HeirController::class, 'updateHeir'])->name('heirs.update');
        Route::delete('heirs/{heir}', [HeirController::class, 'destroyHeir'])->name('heirs.destroy');
        Route::post('heirs/{heir}/invite', [HeirController::class, 'inviteHeir'])->name('heirs.invite');
        Route::post('trusted-contacts', [HeirController::class, 'storeContact'])->name('contacts.store');
        Route::patch('trusted-contacts/{contact}', [HeirController::class, 'updateContact'])->name('contacts.update');
        Route::delete('trusted-contacts/{contact}', [HeirController::class, 'destroyContact'])->name('contacts.destroy');
        Route::post('heir-requests/{heirRequest}/cancel', [HeirController::class, 'cancelRequest'])->name('heir-requests.cancel');

        Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
        Route::patch('settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('exchange-rates', [SettingsController::class, 'storeRate'])->name('rates.store');
        Route::patch('exchange-rates/{rate}', [SettingsController::class, 'updateRate'])->name('rates.update');
        Route::delete('exchange-rates/{rate}', [SettingsController::class, 'destroyRate'])->name('rates.destroy');
    });
});
