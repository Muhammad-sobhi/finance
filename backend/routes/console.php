<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AlertScanner;
use App\Services\NetWorth;
use App\Services\RateFetcher;
use App\Services\RecurringService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

use function Laravel\Prompts\password;

Artisan::command('finance:owner {email} {name}', function (string $email, string $name) {
    $email = strtolower($email);
    if (User::where('email', $email)->exists()) {
        $this->error('A user with that email already exists.');

        return 1;
    }
    $pass = password('Password (min 10 chars)', required: true, validate: fn ($v) => strlen($v) < 10 ? 'Too short.' : null);
    User::create(['email' => $email, 'name' => $name, 'password' => $pass, 'role' => UserRole::Owner, 'last_activity_at' => now()]);
    $this->info("Owner {$email} created with default categories.");
})->purpose('Create the owner account');

Artisan::command('finance:auto-pay', function (RecurringService $service) {
    $this->info('Paid '.$service->runAutoPay().' due auto-pay item(s).');
})->purpose('Pay bills/subscriptions flagged auto-pay that are due');

Schedule::command('finance:auto-pay')->dailyAt('06:00')->withoutOverlapping();

Artisan::command('finance:alerts', function (AlertScanner $scanner) {
    $this->info('Sent '.$scanner->run().' alert(s).');
})->purpose('Send due/overdue alerts (in-app, email, web push), each item+stage once');

Schedule::command('finance:alerts')->hourly()->withoutOverlapping();

Artisan::command('finance:snapshot', function (NetWorth $netWorth) {
    User::where('role', 'owner')->each(fn ($owner) => $netWorth->snapshot($owner));
    $this->info('Net-worth snapshots updated.');
})->purpose('Record this month\'s net worth for every owner (trend charts)');

Artisan::command('finance:fetch-rates', function (RateFetcher $fetcher) {
    $this->info('Updated '.$fetcher->run().' rate(s).');
})->purpose('Auto-update exchange rates for owners who enabled it');

Schedule::command('finance:snapshot')->dailyAt('23:50');
Schedule::command('finance:fetch-rates')->dailyAt('07:00');
