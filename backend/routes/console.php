<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

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
