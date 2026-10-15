<?php

use App\Enums\UserRole;
use App\Models\User;
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
