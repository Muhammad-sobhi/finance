<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DebtController;
use App\Http\Controllers\Api\V1\InvestmentController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');

    Route::middleware(['auth:sanctum', 'throttle:api', 'activity', 'heir.readonly'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::patch('auth/me', [AuthController::class, 'update'])->name('auth.me.update');

        Route::get('dashboard', DashboardController::class)->name('dashboard');

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

        Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
        Route::patch('settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('exchange-rates', [SettingsController::class, 'storeRate'])->name('rates.store');
        Route::patch('exchange-rates/{rate}', [SettingsController::class, 'updateRate'])->name('rates.update');
        Route::delete('exchange-rates/{rate}', [SettingsController::class, 'destroyRate'])->name('rates.destroy');
    });
});
