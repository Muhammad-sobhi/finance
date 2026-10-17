<?php

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AlertScanner;
use App\Services\BackupService;
use App\Services\DataVault;
use App\Services\Heartbeat;
use App\Services\HeirService;
use App\Services\MetalPriceFetcher;
use App\Services\NetWorth;
use App\Services\RateFetcher;
use App\Services\RecurringService;
use App\Services\ServerBackup;
use App\Services\Watchdog;
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

Artisan::command('finance:restore {file} {email}', function (BackupService $backups, string $file, string $email) {
    $owner = User::where('email', strtolower($email))->where('role', UserRole::Owner)->first();
    if (! $owner) {
        $this->error('Create the owner first: php artisan finance:owner '.$email.' "Name"');

        return 1;
    }
    if (! is_readable($file)) {
        $this->error("Can't read {$file}.");

        return 1;
    }
    try {
        $counts = $backups->restore($owner, $backups->open(file_get_contents($file), password('Backup passphrase', required: true)));
    } catch (RuntimeException $e) {
        $this->error($e->getMessage());

        return 1;
    }
    $this->table(['Table', 'Rows'], collect($counts)->filter()->map(fn ($n, $t) => [$t, $n])->values()->all());
    $this->info("Restored into {$owner->email}.");
})->purpose('Restore an encrypted backup into a new, empty owner account');

Artisan::command('finance:admin {email} {--revoke}', function (string $email) {
    $user = User::where('email', strtolower($email))->where('role', UserRole::Owner)->first();
    if (! $user) {
        $this->error('No owner with that email.');

        return 1;
    }
    $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();
    $this->info($user->is_admin ? "{$user->email} can now invite users (Settings → Users)." : "{$user->email} is no longer an admin.");
})->purpose('Let an owner invite new users by email (or --revoke)');

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

Artisan::command('finance:fetch-metals', function (MetalPriceFetcher $fetcher) {
    $this->info('Updated gold/silver prices for '.$fetcher->run().' owner(s).');
})->purpose('Auto-update gold/silver prices for owners who enabled it');

Schedule::command('finance:snapshot')->dailyAt('23:50');
Schedule::command('finance:fetch-metals')->dailyAt('07:05');
Schedule::command('finance:fetch-rates')->dailyAt('07:00');

Artisan::command('finance:heir-check', function (HeirService $heirs) {
    $s = $heirs->run();
    $this->info("Heir check: {$s['warned']} warning(s), {$s['activated']} activation(s).");
})->purpose('Dead-man switch reminders/activation and confirmed heir requests');

Schedule::command('finance:heir-check')->hourly()->withoutOverlapping();

Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Nightly encrypted backup of the database + receipts to the "backups" disk (off-server in production).
Artisan::command('finance:backup', function (ServerBackup $backup, Heartbeat $heartbeat, Watchdog $watchdog) {
    if (! $backup->enabled()) {
        $this->warn('Backups are off: set BACKUP_KEY (php artisan finance:backup-key).');

        return 0;
    }
    try {
        $r = $backup->run();
    } catch (Throwable $e) {
        report($e);
        $watchdog->backupFailed($e->getMessage());
        $this->error('Backup failed: '.$e->getMessage());

        return 1;
    }
    $heartbeat->ok('backup');
    $this->info(sprintf('Saved %s (%s KB) on the "%s" disk; removed %d old backup(s).', $r['path'], number_format($r['bytes'] / 1024), config('filesystems.disks.backups.driver'), $r['pruned']));
})->purpose('Encrypted backup of the database and receipts to the backups disk');

Schedule::command('finance:backup')->dailyAt('03:15')->withoutOverlapping();

Artisan::command('finance:backup-key', function () {
    $this->line(ServerBackup::newKey());
    $this->warn('Put it in .env as BACKUP_KEY=… and keep a copy away from the server (not with the backups). Without it the backups cannot be opened.');
})->purpose('Generate a key for the nightly backups (prints it, changes nothing)');

Artisan::command('finance:backup-open {file} {out} {--ask : Type the backup key instead of using BACKUP_KEY}', function (ServerBackup $backup, string $file, string $out) {
    $key = $this->option('ask') ? password('BACKUP_KEY (base64:…)', required: true) : null;
    try {
        $backup->open($file, $out, $key);
    } catch (RuntimeException $e) {
        $this->error($e->getMessage());

        return 1;
    }
    $this->info("Decrypted into {$out} (a zip: database dump, receipts/, manifest.json).");
})->purpose('Decrypt a nightly backup file into its zip');

// Every 5 minutes: scheduler + queue heartbeats, email the admins when a part stops or recovers.
Artisan::command('finance:watchdog', function (Watchdog $watchdog) {
    $sent = $watchdog->run();
    $this->info($sent ? 'Alerts: '.implode(', ', $sent) : 'All good.');
})->purpose('Check the queue, backups and encryption key; email the admins about problems');

Schedule::command('finance:watchdog')->everyFiveMinutes()->withoutOverlapping();
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
