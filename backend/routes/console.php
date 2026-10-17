<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AlertScanner;
use App\Services\DataVault;
use App\Services\HeirService;
use App\Services\NetWorth;
use App\Services\RateFetcher;
use App\Services\RecurringService;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

Artisan::command('finance:heir-check', function (HeirService $heirs) {
    $s = $heirs->run();
    $this->info("Heir check: {$s['warned']} warning(s), {$s['activated']} activation(s).");
})->purpose('Dead-man switch reminders/activation and confirmed heir requests');

Schedule::command('finance:heir-check')->hourly()->withoutOverlapping();

// Data master key (config/vault.php). Store it outside the project and the backups, with two offline copies.
Artisan::command('vault:key', function (DataVault $vault) {
    $key = 'base64:'.base64_encode(Encrypter::generateKey('aes-256-gcm'));
    $this->line($key);
    $this->info('Fingerprint: '.$vault->fingerprint($key));
    $this->warn('Save this key in two safe places away from the server and the backups. Without it the data cannot be read.');
})->purpose('Generate a new data master key (prints it; changes nothing)');

Artisan::command('vault:check {--ask : Type a saved copy of the key to verify it, instead of the live one}', function (DataVault $vault) {
    $key = $this->option('ask') ? password('Saved master key (base64:...)', required: true) : null;
    $this->info('Fingerprint: '.$vault->fingerprint($key));
    $keys = DB::table('users')->whereNotNull('data_key')->pluck('data_key');
    $bad = $keys->reject(fn (string $wrapped) => $vault->canUnwrap($wrapped, $key))->count();
    if ($bad > 0) {
        $this->error("{$bad} of {$keys->count()} user key(s) can NOT be opened with this master key.");

        return 1;
    }
    $this->info("All {$keys->count()} user key(s) open with this master key.");
})->purpose('Verify the data master key (or a saved copy) opens every user key');

Artisan::command('vault:rotate', function (DataVault $vault) {
    $count = 0;
    DB::transaction(function () use ($vault, &$count) {
        DB::table('users')->whereNotNull('data_key')->orderBy('id')->lockForUpdate()->get(['id', 'data_key'])
            ->each(function ($row) use ($vault, &$count) {
                DB::table('users')->where('id', $row->id)->update(['data_key' => $vault->rewrap($row->data_key)]);
                $count++;
            });
    });
    $this->info("Re-wrapped {$count} user key(s) with the current master key. Remove DATA_MASTER_KEY_PREVIOUS once `vault:check` passes.");
})->purpose('Re-wrap every user key with the current master key (after setting DATA_MASTER_KEY_PREVIOUS to the old one)');
