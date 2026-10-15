<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Account> */
class AccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company(),
            'type' => AccountType::DebitCard,
            'provider' => fake()->company(),
            'currency' => 'EGP',
            'balance' => 1000,
            'is_active' => true,
        ];
    }
}
