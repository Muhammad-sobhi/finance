<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_totals_in_base_currency(): void
    {
        Carbon::setTestNow('2026-10-15');
        Sanctum::actingAs($owner = User::factory()->create());
        $owner->exchangeRates()->create(['currency' => 'USD', 'rate' => 50]);

        $bank = Account::factory()->for($owner)->create(['balance' => 1000]);
        Account::factory()->for($owner)->create(['currency' => 'USD', 'balance' => 10, 'type' => 'cash']);
        Account::factory()->for($owner)->create(['balance' => 5000, 'type' => 'savings']);
        Account::factory()->for($owner)->create(['balance' => -300, 'type' => 'credit_card', 'due_day' => 20, 'name' => 'Visa']);
        Account::factory()->for($owner)->create(['balance' => 999, 'is_active' => false]);

        $svc = app(TransactionService::class);
        $svc->create($owner, ['type' => 'expense', 'account_id' => $bank->id, 'amount' => 100, 'date' => '2026-10-02']);
        $svc->create($owner, ['type' => 'expense', 'account_id' => $bank->id, 'amount' => 40, 'date' => '2026-08-02']);
        $svc->create($owner, ['type' => 'income', 'account_id' => $bank->id, 'amount' => 600, 'date' => '2026-10-01']);

        $this->getJson('/api/v1/dashboard')->assertOk()
            ->assertJsonPath('net_worth', 6660)          // 1460 + 500 + 5000 - 300
            ->assertJsonPath('liquid', 1960)              // bank + USD cash
            ->assertJsonPath('balances.0.base_balance', 5000)
            ->assertJsonCount(6, 'spending')
            ->assertJsonPath('spending.5.month', '2026-10')
            ->assertJsonPath('spending.5.expense', 100)
            ->assertJsonPath('spending.5.income', 600)
            ->assertJsonPath('spending.3.expense', 40)
            ->assertJsonPath('upcoming.0.name', 'Visa')
            ->assertJsonPath('upcoming.0.days', 5);
    }
}
