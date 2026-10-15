<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WealthTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15');
        Sanctum::actingAs($this->owner = User::factory()->create());
    }

    public function test_investment_profit_roi_and_value_history(): void
    {
        $id = $this->postJson('/api/v1/investments', [
            'name' => 'NBE certificate', 'type' => 'certificate', 'platform' => 'NBE', 'invested_amount' => 100000,
            'currency' => 'EGP', 'start_date' => '2026-01-01', 'maturity_date' => '2029-01-01', 'annual_return' => 22.5, 'payout_cycle' => 'monthly',
        ])->assertCreated()->assertJsonPath('current_value', 100000)->assertJsonPath('roi', 0)->json('id');

        $this->patchJson("/api/v1/investments/{$id}", ['current_value' => 112000])
            ->assertJsonPath('profit', 12000)->assertJsonPath('roi', 12);
        $this->postJson("/api/v1/investments/{$id}/values", ['date' => '2026-06-01', 'value' => 105000])
            ->assertOk()->assertJsonCount(2, 'values')->assertJsonPath('values.0.date', '2026-06-01')
            ->assertJsonPath('current_value', 112000); // back-dated value doesn't replace the latest
        $this->postJson("/api/v1/investments/{$id}/values", ['date' => '2027-01-01', 'value' => 1])->assertJsonValidationErrors('date');

        $this->getJson('/api/v1/investments')->assertJsonPath('meta.profit', 12000)->assertJsonPath('meta.roi', 12);
        $this->postJson('/api/v1/investments', ['name' => 'x', 'type' => 'tulips', 'invested_amount' => 1, 'currency' => 'EGP'])->assertJsonValidationErrors('type');
    }

    public function test_gold_and_silver_value_from_settings_price(): void
    {
        $this->patchJson('/api/v1/settings', ['gold_price_24k' => 4800, 'silver_price' => 60])->assertOk();

        $this->postJson('/api/v1/assets', ['name' => 'Ring', 'type' => 'gold', 'currency' => 'EGP', 'grams' => 40, 'karat' => 21])
            ->assertCreated()->assertJsonPath('value', 168000)->assertJsonPath('auto_valued', true); // 4800 × 40 × 21/24
        $this->postJson('/api/v1/assets', ['name' => 'Bars', 'type' => 'silver', 'currency' => 'EGP', 'grams' => 250])->assertJsonPath('value', 15000);
        $this->postJson('/api/v1/assets', ['name' => 'Car', 'type' => 'car', 'currency' => 'EGP', 'purchase_price' => 900000, 'current_value' => 750000])
            ->assertJsonPath('value', 750000);
        $this->postJson('/api/v1/assets', ['name' => 'x', 'type' => 'gold', 'currency' => 'EGP'])->assertJsonValidationErrors('grams');

        $this->getJson('/api/v1/assets')->assertJsonPath('meta.total', 933000)->assertJsonPath('data.0.name', 'Car');

        // Price changes revalue instantly.
        $this->patchJson('/api/v1/settings', ['gold_price_24k' => 5000]);
        $this->getJson('/api/v1/assets')->assertJsonPath('meta.total', 940000);
    }

    public function test_net_worth_includes_everything(): void
    {
        Account::factory()->for($this->owner)->create(['balance' => 10000]);
        $this->patchJson('/api/v1/settings', ['gold_price_24k' => 4000]);
        $this->postJson('/api/v1/assets', ['name' => 'Gold', 'type' => 'gold', 'currency' => 'EGP', 'grams' => 10, 'karat' => 24]);
        $this->postJson('/api/v1/investments', ['name' => 'Fund', 'type' => 'fund', 'invested_amount' => 5000, 'currency' => 'EGP', 'maturity_date' => '2026-11-01']);
        $this->postJson('/api/v1/debts', ['person' => 'A', 'direction' => 'owed_to_me', 'amount' => 1000, 'currency' => 'EGP']);
        $this->postJson('/api/v1/debts', ['person' => 'B', 'direction' => 'i_owe', 'amount' => 3000, 'currency' => 'EGP', 'due_date' => '2026-10-20']);

        $this->getJson('/api/v1/dashboard')
            ->assertJsonPath('net_worth', 53000) // 10000 + 40000 + 5000 + 1000 - 3000
            ->assertJsonPath('investments', 5000)->assertJsonPath('assets', 40000)
            ->assertJsonPath('upcoming.0.kind', 'i_owe')->assertJsonPath('upcoming.1.kind', 'maturity');
    }

    public function test_settings_and_exchange_rates(): void
    {
        $this->getJson('/api/v1/settings')->assertJsonPath('base_currency', 'EGP')->assertJsonPath('alert_days', 3);
        $this->patchJson('/api/v1/settings', ['alert_days' => 5, 'email_alerts' => false])->assertJsonPath('alert_days', 5)->assertJsonPath('email_alerts', false);
        $this->patchJson('/api/v1/settings', ['alert_days' => 500])->assertJsonValidationErrors('alert_days');

        $id = $this->postJson('/api/v1/exchange-rates', ['currency' => 'usd', 'rate' => 48.5])->assertCreated()->assertJsonPath('currency', 'USD')->json('id');
        $this->postJson('/api/v1/exchange-rates', ['currency' => 'USD', 'rate' => 49])->assertCreated(); // upsert
        $this->postJson('/api/v1/exchange-rates', ['currency' => 'EGP', 'rate' => 2])->assertJsonValidationErrors('currency');
        $this->getJson('/api/v1/settings')->assertJsonCount(1, 'rates')->assertJsonPath('rates.0.rate', 49);
        $this->patchJson("/api/v1/exchange-rates/{$id}", ['rate' => 50])->assertJsonPath('rate', 50);
        $this->deleteJson("/api/v1/exchange-rates/{$id}")->assertNoContent();
    }
}
