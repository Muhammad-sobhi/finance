<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AssetController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BillController;
use App\Http\Controllers\Api\V1\BudgetController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DataController;
use App\Http\Controllers\Api\V1\DebtController;
use App\Http\Controllers\Api\V1\GameyaController;
use App\Http\Controllers\Api\V1\GameyaMemberController;
use App\Http\Controllers\Api\V1\GoalController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\HeirController;
use App\Http\Controllers\Api\V1\HeirRequestController;
use App\Http\Controllers\Api\V1\IncomeController;
use App\Http\Controllers\Api\V1\IncomeSourceController;
use App\Http\Controllers\Api\V1\InstallmentController;
use App\Http\Controllers\Api\V1\InvestmentController;
use App\Http\Controllers\Api\V1\LinkController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\SharedGameyaController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\VaultController;
use App\Http\Controllers\Api\V1\ZakatController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Server status for an uptime monitor (no login; states only).
    Route::get('health', HealthController::class)->middleware('throttle:60,1')->name('health');

    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
    Route::post('auth/two-factor', [AuthController::class, 'twoFactorChallenge'])->middleware('throttle:two-factor')->name('auth.two-factor');
    Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset')->name('auth.forgot');
    Route::post('auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:two-factor')->name('auth.reset');

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
        Route::patch('auth/profile', [AuthController::class, 'updateProfile'])->name('auth.profile');
        Route::put('auth/password', [AuthController::class, 'updatePassword'])->middleware('throttle:account-security')->name('auth.password');
        Route::post('auth/logout-others', [AuthController::class, 'logoutOthers'])->name('auth.logout-others');
        Route::get('auth/two-factor', [AuthController::class, 'twoFactorStatus'])->name('auth.2fa.status');
        Route::get('auth/activity', [AuthController::class, 'activity'])->name('auth.activity');
        Route::post('auth/two-factor/setup', [AuthController::class, 'twoFactorSetup'])->middleware('throttle:account-security')->name('auth.2fa.setup');
        Route::post('auth/two-factor/confirm', [AuthController::class, 'twoFactorConfirm'])->middleware('throttle:account-security')->name('auth.2fa.confirm');
        Route::post('auth/two-factor/disable', [AuthController::class, 'twoFactorDisable'])->middleware('throttle:account-security')->name('auth.2fa.disable');
        Route::post('auth/two-factor/recovery-codes', [AuthController::class, 'twoFactorRecoveryCodes'])->middleware('throttle:account-security')->name('auth.2fa.recovery');

        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::get('reports', ReportController::class)->name('reports');

        Route::get('transactions/quick-meta', [TransactionController::class, 'quickMeta'])->name('transactions.quick-meta');
        Route::apiResource('accounts', AccountController::class);
        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('transactions', TransactionController::class);
        Route::post('transactions/{transaction}/receipt', [TransactionController::class, 'storeReceipt'])->name('transactions.receipt.store');
        Route::get('transactions/{transaction}/receipt', [TransactionController::class, 'showReceipt'])->name('transactions.receipt.show');
        Route::delete('transactions/{transaction}/receipt', [TransactionController::class, 'destroyReceipt'])->name('transactions.receipt.destroy');
        Route::get('export/transactions', [DataController::class, 'exportTransactions'])->name('export.transactions');
        Route::post('backup', [DataController::class, 'backup'])->middleware('throttle:account-security')->name('backup');

        Route::apiResource('debts', DebtController::class);
        Route::post('debts/{debt}/payments', [DebtController::class, 'addPayment'])->name('debts.payments.store');
        Route::delete('debts/{debt}/payments/{payment}', [DebtController::class, 'deletePayment'])->name('debts.payments.destroy');
        Route::apiResource('installments', InstallmentController::class);
        Route::post('installments/{installment}/pay', [InstallmentController::class, 'pay'])->name('installments.pay');
        Route::delete('installments/{installment}/payments/{payment}', [InstallmentController::class, 'deletePayment'])->name('installments.payments.destroy');
        Route::apiResource('gameyas', GameyaController::class);
        Route::post('gameyas/{gameya}/pay', [GameyaController::class, 'pay'])->name('gameyas.pay');
        Route::post('gameyas/{gameya}/receive', [GameyaController::class, 'receive'])->name('gameyas.receive');
        Route::delete('gameyas/{gameya}/payments/{payment}', [GameyaController::class, 'deletePayment'])->name('gameyas.payments.destroy');
        Route::patch('gameyas/{gameya}/members/{member}', [GameyaMemberController::class, 'update'])->name('gameyas.members.update');
        Route::post('gameyas/{gameya}/members/{member}/pay', [GameyaMemberController::class, 'pay'])->name('gameyas.members.pay');
        Route::delete('gameyas/{gameya}/members/{member}/payments/{payment}', [GameyaMemberController::class, 'unpay'])->name('gameyas.members.unpay');
        Route::post('gameyas/{gameya}/members/{member}/payout', [GameyaMemberController::class, 'payout'])->name('gameyas.members.payout');
        Route::delete('gameyas/{gameya}/members/{member}/payout', [GameyaMemberController::class, 'unpayout'])->name('gameyas.members.unpayout');
        Route::post('gameyas/{gameya}/members/{member}/invite', [GameyaMemberController::class, 'invite'])->middleware('throttle:30,1')->name('gameyas.members.invite');
        Route::delete('gameyas/{gameya}/members/{member}/link', [GameyaMemberController::class, 'unlink'])->name('gameyas.members.unlink');
        Route::post('gameyas/{gameya}/members/{member}/remind', [GameyaMemberController::class, 'remind'])->middleware('throttle:30,1')->name('gameyas.members.remind');
        Route::post('gameyas/{gameya}/remind', [GameyaMemberController::class, 'remindAll'])->middleware('throttle:10,1')->name('gameyas.remind');
        Route::get('shared-gameyas', [SharedGameyaController::class, 'index'])->name('shared-gameyas.index');
        Route::post('shared-gameyas/{member}/accept', [SharedGameyaController::class, 'accept'])->name('shared-gameyas.accept');
        Route::post('shared-gameyas/{member}/decline', [SharedGameyaController::class, 'decline'])->name('shared-gameyas.decline');
        Route::post('shared-gameyas/{member}/paid', [SharedGameyaController::class, 'claim'])->name('shared-gameyas.claim');

        Route::apiResource('bills', BillController::class)->parameters(['bills' => 'id']);
        Route::post('bills/{id}/pay', [BillController::class, 'pay'])->name('bills.pay');
        Route::apiResource('subscriptions', SubscriptionController::class)->parameters(['subscriptions' => 'id']);
        Route::post('subscriptions/{id}/pay', [SubscriptionController::class, 'pay'])->name('subscriptions.pay');

        Route::get('income/summary', [IncomeController::class, 'summary'])->name('income.summary');
        Route::apiResource('income-sources', IncomeSourceController::class)->parameters(['income-sources' => 'id']);
        Route::post('income-sources/{id}/receive', [IncomeSourceController::class, 'pay'])->name('income-sources.receive');
        Route::apiResource('projects', ProjectController::class);
        Route::post('projects/{project}/payments', [ProjectController::class, 'addPayment'])->name('projects.payments.store');
        Route::delete('projects/{project}/payments/{payment}', [ProjectController::class, 'deletePayment'])->name('projects.payments.destroy');

        Route::apiResource('investments', InvestmentController::class);
        Route::post('investments/{investment}/values', [InvestmentController::class, 'addValue'])->name('investments.values.store');
        Route::delete('investments/{investment}/values/{value}', [InvestmentController::class, 'deleteValue'])->name('investments.values.destroy');

        Route::apiResource('assets', AssetController::class);

        Route::apiResource('budgets', BudgetController::class)->except('show');
        Route::apiResource('goals', GoalController::class);
        Route::post('goals/{goal}/contributions', [GoalController::class, 'addContribution'])->name('goals.contributions.store');
        Route::delete('goals/{goal}/contributions/{contribution}', [GoalController::class, 'deleteContribution'])->name('goals.contributions.destroy');
        Route::get('zakat', [ZakatController::class, 'show'])->name('zakat.show');
        Route::patch('zakat/settings', [ZakatController::class, 'updateSettings'])->name('zakat.settings');
        Route::post('zakat/pay', [ZakatController::class, 'pay'])->name('zakat.pay');
        Route::delete('zakat/payments/{payment}', [ZakatController::class, 'destroyPayment'])->name('zakat.payments.destroy');

        Route::get('vault', [VaultController::class, 'show'])->name('vault.show');
        Route::post('vault', [VaultController::class, 'setup'])->name('vault.setup');
        Route::get('vault/escrow', [VaultController::class, 'escrow'])->middleware('throttle:account-security')->name('vault.escrow');
        Route::put('vault/key', [VaultController::class, 'rekey'])->middleware('throttle:account-security')->name('vault.rekey');
        Route::delete('vault', [VaultController::class, 'reset'])->middleware('throttle:account-security')->name('vault.reset');
        Route::post('vault/items', [VaultController::class, 'store'])->name('vault.items.store');
        Route::put('vault/items/{item}', [VaultController::class, 'update'])->name('vault.items.update');
        Route::delete('vault/items/{item}', [VaultController::class, 'destroy'])->name('vault.items.destroy');

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

        Route::get('admin/users', [AdminController::class, 'users'])->name('admin.users');
        Route::post('admin/invites', [AdminController::class, 'invite'])->middleware('throttle:account-security')->name('admin.invite');
        Route::post('admin/invites/{user}/resend', [AdminController::class, 'resend'])->middleware('throttle:account-security')->name('admin.resend');
        Route::delete('admin/invites/{user}', [AdminController::class, 'cancel'])->name('admin.cancel');
        Route::delete('admin/users/{user}', [AdminController::class, 'destroy'])->middleware('throttle:account-security')->name('admin.destroy');

        Route::get('settings', [SettingsController::class, 'show'])->name('settings.show');
        Route::patch('settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('settings/metal-prices', [SettingsController::class, 'refreshMetals'])->middleware('throttle:account-security')->name('settings.metals');
        Route::post('exchange-rates', [SettingsController::class, 'storeRate'])->name('rates.store');
        Route::patch('exchange-rates/{rate}', [SettingsController::class, 'updateRate'])->name('rates.update');
        Route::delete('exchange-rates/{rate}', [SettingsController::class, 'destroyRate'])->name('rates.destroy');
    });
});
