<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Production has no seed data: create the owner with `php artisan finance:owner`.
     * Local demo data: `php artisan db:seed --class=DemoSeeder`.
     */
    public function run(): void
    {
        if (app()->environment('local', 'testing')) {
            $this->call(DemoSeeder::class);
        }
    }
}
