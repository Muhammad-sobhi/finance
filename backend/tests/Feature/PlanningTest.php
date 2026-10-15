<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Notifications\InfoAlert;
use App\Services\AlertScanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanningTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15');
        $this->owner = User::factory()->create(['email_alerts' => false, 'locale' => 'en']);
        $this->wallet = Account::factory()->for($this->owner)->create(['balance' => 10000]);
        Sanctum::actingAs($this->owner);
    }

    private function cat(string $key): int
    {
        return $this->owner->categories()->where('key', $key)->value('id');
    }

    private function spend(float $amount, string $key = 'food', string $date = '2026-10-10'): void
    {
        $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $this->wallet->id, 'category_id' => $this->cat($key), 'amount' => $amount, 'date' => $date])->assertCreated();
    }

    public function test_budget_tracks_month_spending_in_base_currency(): void
    {
        $this->postJson('/api/v1/exchange-rates', ['currency' => 'USD', 'rate' => 50]);
        $usd = Account::factory()->for($this->owner)->create(['currency' => 'USD', 'balance' => 100]);
        $this->postJson('/api/v1/budgets', ['category_id' => $this->cat('food'), 'amount' => 5000])->assertCreated();

        $this->spend(1000);
        $this->spend(700, 'food', '2026-09-30'); // last month: ignored
        $this->spend(300, 'transport');          // other category
        $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $usd->id, 'category_id' => $this->cat('food'), 'amount' => 20, 'date' => '2026-10-12']);

        $this->getJson('/api/v1/budgets')
            ->assertJsonPath('data.0.spent', 2000) // 1000 + 20 USD × 50
            ->assertJsonPath('data.0.remaining', 3000)->assertJsonPath('data.0.pct', 40)->assertJsonPath('data.0.status', 'ok')
            ->assertJsonPath('data.0.projected', 4133.33) // 2000 / 15 days × 31
            ->assertJsonPath('meta.budgeted', 5000);
        $this->getJson('/api/v1/budgets?month=2026-09')->assertJsonPath('data.0.spent', 700)->assertJsonPath('data.0.projected', null);

        // Income categories and duplicates are rejected.
        $this->postJson('/api/v1/budgets', ['category_id' => $this->cat('salary'), 'amount' => 1])->assertJsonValidationErrors('category_id');
        $this->postJson('/api/v1/budgets', ['category_id' => $this->cat('food'), 'amount' => 1])->assertJsonValidationErrors('category_id');
    }

    public function test_budget_warns_at_80_and_100_percent_once_each(): void
    {
        $this->postJson('/api/v1/budgets', ['category_id' => $this->cat('food'), 'amount' => 1000]);
        $notes = fn () => $this->owner->notifications()->where('type', InfoAlert::class)->get();

        $this->spend(500);
        $this->assertCount(0, $notes());
        $this->spend(350); // 85%
        $this->assertSame(['budget_near'], $notes()->pluck('data.kind')->all());
        $this->assertSame('You have spent 850 of your 1,000 EGP budget for Food this month (85%).', $notes()->first()->data['text']['en']);
        $this->spend(10);
        $this->assertCount(1, $notes());
        $this->spend(200); // 106%
        $this->assertEqualsCanonicalizing(['budget_near', 'budget_over'], $notes()->pluck('data.kind')->all());
        $this->assertSame('تجاوزت الميزانية: طعام', $notes()->firstWhere('data.kind', 'budget_over')->data['title']['ar']);

        // Hourly scan doesn't repeat them; next month starts fresh.
        app(AlertScanner::class)->scanOwner($this->owner, CarbonImmutable::today());
        $this->assertCount(2, $notes());
    }

    public function test_goal_with_contributions_and_linked_account(): void
    {
        $id = $this->postJson('/api/v1/goals', ['name' => 'Car', 'target_amount' => 120000, 'currency' => 'EGP', 'target_date' => '2027-10-15'])
            ->assertCreated()->assertJsonPath('saved', 0)->assertJsonPath('months_left', 12)->assertJsonPath('monthly_needed', 10000)->json('id');

        $this->postJson("/api/v1/goals/{$id}/contributions", ['amount' => 30000])->assertCreated()
            ->assertJsonPath('goal.saved', 30000)->assertJsonPath('goal.pct', 25)->assertJsonPath('goal.monthly_needed', 7500)->assertJsonPath('goal.on_track', true);
        $this->postJson("/api/v1/goals/{$id}/contributions", ['amount' => -40000])->assertJsonValidationErrors('amount');
        $cid = $this->postJson("/api/v1/goals/{$id}/contributions", ['amount' => -5000])->assertJsonPath('goal.saved', 25000)->json('id');
        $this->deleteJson("/api/v1/goals/{$id}/contributions/{$cid}")->assertJsonPath('saved', 30000);
        $this->getJson("/api/v1/goals/{$id}")->assertJsonCount(1, 'contributions');

        // Half a year later with nothing more saved: behind schedule.
        Carbon::setTestNow('2027-04-15');
        $this->getJson('/api/v1/goals')->assertJsonPath('data.0.on_track', false)->assertJsonPath('data.0.months_left', 6);

        // Linked goal follows the account balance (converted) and refuses manual entries.
        $savings = Account::factory()->for($this->owner)->create(['currency' => 'EGP', 'balance' => 50000]);
        $linked = $this->postJson('/api/v1/goals', ['name' => 'Emergency', 'target_amount' => 40000, 'currency' => 'EGP', 'account_id' => $savings->id])
            ->assertJsonPath('saved', 50000)->assertJsonPath('pct', 100)->assertJsonPath('achieved', true)->json('id');
        $this->postJson("/api/v1/goals/{$linked}/contributions", ['amount' => 1])->assertStatus(409);
    }

    public function test_zakat_calculation(): void
    {
        $this->patchJson('/api/v1/settings', ['gold_price_24k' => 4000, 'silver_price' => 50]);
        // Cash 10000 (setUp) + 90000 savings, gold 50 g 21k, a car (not zakatable), a fund, rental property (not zakatable).
        Account::factory()->for($this->owner)->create(['balance' => 90000]);
        Account::factory()->for($this->owner)->create(['type' => 'credit_card', 'balance' => -5000]);
        $this->postJson('/api/v1/assets', ['name' => 'Gold', 'type' => 'gold', 'currency' => 'EGP', 'grams' => 50, 'karat' => 21])->assertJsonPath('zakatable', true);
        $this->postJson('/api/v1/assets', ['name' => 'Car', 'type' => 'car', 'currency' => 'EGP', 'current_value' => 500000])->assertJsonPath('zakatable', false);
        $this->postJson('/api/v1/investments', ['name' => 'Fund', 'type' => 'fund', 'invested_amount' => 50000, 'currency' => 'EGP']);
        $this->postJson('/api/v1/investments', ['name' => 'Flat', 'type' => 'real_estate', 'invested_amount' => 900000, 'currency' => 'EGP'])->assertJsonPath('zakatable', false);
        $this->postJson('/api/v1/debts', ['person' => 'A', 'direction' => 'owed_to_me', 'amount' => 10000, 'currency' => 'EGP']);
        $this->postJson('/api/v1/debts', ['person' => 'B', 'direction' => 'i_owe', 'amount' => 20000, 'currency' => 'EGP']);

        // gross = 100000 + 175000 + 50000 + 10000 = 335000; minus 20000 + 5000 = 310000.
        $this->getJson('/api/v1/zakat')
            ->assertJsonPath('parts.cash', 100000)->assertJsonPath('parts.metals', 175000)->assertJsonPath('parts.investments', 50000)
            ->assertJsonPath('gross', 335000)->assertJsonPath('deductions', 25000)->assertJsonPath('net', 310000)
            ->assertJsonPath('nisab', 340000)->assertJsonPath('reached', false)->assertJsonPath('due', 0); // 85 g × 4000

        // Silver nisab (595 g × 50 = 29750) is reached: 2.5% hijri, 2.577% solar.
        $this->patchJson('/api/v1/zakat/settings', ['nisab' => 'silver'])->assertJsonPath('nisab', 29750)->assertJsonPath('due', 7750);
        $this->patchJson('/api/v1/zakat/settings', ['calendar' => 'gregorian'])->assertJsonPath('due', 7988.7);
        $this->patchJson('/api/v1/zakat/settings', ['deduct_debts' => false, 'calendar' => 'hijri'])->assertJsonPath('net', 335000)->assertJsonPath('due', 8375);

        // Marked not zakatable (e.g. worn jewelry) → excluded.
        $gold = $this->owner->assets()->where('type', 'gold')->value('id');
        $this->patchJson("/api/v1/assets/{$gold}", ['zakatable' => false])->assertJsonPath('zakatable', false);
        $this->getJson('/api/v1/zakat')->assertJsonPath('parts.metals', 0);

        $this->patchJson('/api/v1/settings', ['silver_price' => 0]);
        $this->getJson('/api/v1/zakat')->assertJsonPath('price_missing', true)->assertJsonPath('due', 0);
    }

    public function test_paying_zakat_records_expense_and_rolls_the_date(): void
    {
        $this->patchJson('/api/v1/settings', ['silver_price' => 10]); // nisab 5950
        $this->patchJson('/api/v1/zakat/settings', ['nisab' => 'silver', 'date' => '2026-10-20'])->assertJsonPath('due', 250)->assertJsonPath('days_left', 5);

        // Reminder before the zakat day.
        $this->assertSame(1, app(AlertScanner::class)->scanOwner($this->owner->fresh(), CarbonImmutable::today()));
        $this->assertSame('zakat', $this->owner->notifications()->first()->data['kind']);

        // A partial payment keeps the date; completing moves it a lunar year (354 days).
        $this->postJson('/api/v1/zakat/pay', ['account_id' => $this->wallet->id, 'amount' => 100, 'complete' => false])->assertCreated();
        $this->getJson('/api/v1/zakat')->assertJsonPath('settings.date', '2026-10-20');
        $pid = $this->postJson('/api/v1/zakat/pay', ['account_id' => $this->wallet->id])->assertCreated()->json('id');
        $this->getJson('/api/v1/zakat')->assertJsonPath('settings.date', '2027-10-09')->assertJsonCount(2, 'payments');
        $this->assertSame(9652.5, (float) $this->wallet->fresh()->balance); // 10000 − 100 − 2.5% of the remaining 9900

        $tx = $this->owner->transactions()->where('source_type', 'zakat_payment')->where('source_id', $pid)->first();
        $this->assertSame($this->cat('zakat'), $tx->category_id);
        $this->putJson("/api/v1/transactions/{$tx->id}", ['type' => 'expense', 'account_id' => $this->wallet->id, 'amount' => 1, 'date' => '2026-10-15'])->assertStatus(409)->assertJsonPath('code', 'managed_by_zakat');

        $this->deleteJson("/api/v1/zakat/payments/{$pid}")->assertNoContent();
        $this->assertSame(9900.0, (float) $this->wallet->fresh()->balance);
        $this->assertDatabaseMissing('transactions', ['id' => $tx->id]);
    }

    public function test_heir_reads_but_cannot_change_plans(): void
    {
        $heir = User::factory()->create(['role' => 'heir', 'owner_id' => $this->owner->id]);
        $gid = $this->postJson('/api/v1/goals', ['name' => 'G', 'target_amount' => 100, 'currency' => 'EGP'])->json('id');
        Sanctum::actingAs($heir);
        $this->getJson('/api/v1/goals')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/zakat')->assertOk();
        $this->getJson('/api/v1/budgets')->assertOk();
        $this->postJson('/api/v1/budgets', ['category_id' => $this->cat('food'), 'amount' => 1])->assertForbidden();
        $this->postJson("/api/v1/goals/{$gid}/contributions", ['amount' => 1])->assertForbidden();
        $this->patchJson('/api/v1/zakat/settings', ['nisab' => 'silver'])->assertForbidden();

        // Other owners can't see or touch them.
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/goals/{$gid}")->assertForbidden();
        $this->getJson('/api/v1/goals')->assertJsonCount(0, 'data');
    }
}
