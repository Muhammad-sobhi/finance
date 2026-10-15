<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Services\DebtService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15');
        Sanctum::actingAs($this->owner = User::factory()->create());
        $this->bank = Account::factory()->for($this->owner)->create(['balance' => 0]);
    }

    private function tx(string $type, float $amount, string $date, ?string $cat = null): void
    {
        app(TransactionService::class)->create($this->owner, [
            'type' => $type, 'account_id' => $this->bank->id, 'amount' => $amount, 'date' => $date,
            'category_id' => $cat ? $this->owner->categories()->where('key', $cat)->value('id') : null,
        ]);
    }

    private function codes($res): array
    {
        return collect($res->json('advice'))->pluck('code')->all();
    }

    public function test_month_totals_categories_and_comparison(): void
    {
        $this->tx('income', 10000, '2026-10-01', 'salary');
        $this->tx('expense', 3000, '2026-10-03', 'food');
        $this->tx('expense', 1000, '2026-10-05', 'transport');
        $this->tx('income', 8000, '2026-09-01', 'salary');
        $this->tx('expense', 4000, '2026-09-03', 'food');
        // Not income/spending: transfers and loans.
        $wallet = Account::factory()->for($this->owner)->create();
        app(TransactionService::class)->create($this->owner, ['type' => 'transfer', 'account_id' => $this->bank->id, 'to_account_id' => $wallet->id, 'amount' => 500, 'date' => '2026-10-06']);
        app(DebtService::class)->create($this->owner, ['person' => 'A', 'direction' => 'owed_to_me', 'amount' => 900, 'currency' => 'EGP', 'date' => '2026-10-07', 'account_id' => $this->bank->id]);

        $this->getJson('/api/v1/reports?period=month&month=2026-10')->assertOk()
            ->assertJsonPath('totals.income', 10000)
            ->assertJsonPath('totals.expense', 4000)
            ->assertJsonPath('totals.savings', 6000)
            ->assertJsonPath('totals.savings_rate', 60)
            ->assertJsonPath('previous.savings', 4000)
            ->assertJsonPath('change.income', 25)
            ->assertJsonPath('change.savings', 50)
            ->assertJsonPath('by_category.0.key', 'food')
            ->assertJsonPath('by_category.0.share', 75)
            ->assertJsonPath('by_category.0.previous', 4000)
            ->assertJsonPath('by_category.0.change', -25)
            ->assertJsonCount(6, 'series')
            ->assertJsonPath('series.5.month', '2026-10')
            ->assertJsonPath('series.4.expense', 4000);
    }

    public function test_growth_uses_net_worth_snapshots(): void
    {
        $this->owner->snapshots()->create(['month' => '2026-09-01', 'net_worth' => 10000, 'breakdown' => []]);
        $this->bank->update(['balance' => 12000]);

        $this->getJson('/api/v1/reports?month=2026-10')
            ->assertJsonPath('growth.basis', 'net_worth')
            ->assertJsonPath('growth.growing', true)
            ->assertJsonPath('growth.pct', 20)
            ->assertJsonPath('net_worth_trend.1.net_worth', 12000);

        $this->bank->update(['balance' => 9000]);
        $this->getJson('/api/v1/reports?month=2026-10')->assertJsonPath('growth.growing', false)->assertJsonPath('growth.pct', -10);
        $this->getJson('/api/v1/dashboard')->assertJsonPath('net_worth_change_pct', -10);
    }

    public function test_growth_falls_back_to_savings_without_snapshots(): void
    {
        $this->tx('income', 1000, '2026-10-01');
        $this->tx('income', 500, '2026-09-01');
        // No snapshot for the previous month → compare savings instead.
        $this->getJson('/api/v1/reports?month=2026-10')->assertJsonPath('growth.basis', 'savings')
            ->assertJsonPath('growth.growing', true)->assertJsonPath('growth.pct', 100);
    }

    public function test_year_report(): void
    {
        $this->tx('income', 1200, '2026-02-01');
        $this->tx('income', 1000, '2025-02-01');
        $this->getJson('/api/v1/reports?period=year&year=2026')->assertOk()
            ->assertJsonCount(12, 'series')->assertJsonPath('series.1.income', 1200)
            ->assertJsonPath('change.income', 20)->assertJsonPath('from', '2026-01-01')->assertJsonPath('to', '2026-12-31');
        $this->getJson('/api/v1/reports?period=year&year=1900')->assertStatus(422);
    }

    public function test_advice_rules(): void
    {
        // Overspending this month; food spiking vs a 3-month average.
        $this->tx('income', 1000, '2026-10-01');
        $this->tx('expense', 3000, '2026-10-02', 'food');
        foreach (['2026-07-05', '2026-08-05', '2026-09-05'] as $d) {
            $this->tx('income', 10000, $d);
            $this->tx('expense', 1000, $d, 'food');
        }
        $this->owner->subscriptions()->create(['name' => 'Gym', 'amount' => 500, 'currency' => 'EGP', 'renew_date' => '2026-11-01', 'cycle' => 'monthly', 'scope' => 'personal']);
        Account::factory()->for($this->owner)->create(['type' => 'credit_card', 'balance' => -5000, 'credit_limit' => 10000, 'name' => 'Visa']);
        $this->owner->debts()->create(['person' => 'Omar', 'direction' => 'i_owe', 'amount' => 700, 'currency' => 'EGP', 'date' => '2026-09-01', 'due_date' => '2026-10-01']);
        $this->owner->debts()->create(['person' => 'Sara', 'direction' => 'owed_to_me', 'amount' => 300, 'currency' => 'EGP', 'date' => '2026-09-01', 'due_date' => '2026-10-10']);

        $res = $this->getJson('/api/v1/reports?month=2026-10')->assertOk();
        $codes = $this->codes($res);
        foreach (['overspending', 'category_up', 'subscriptions_high', 'card_utilization', 'debt_overdue', 'collect_debt'] as $code) {
            $this->assertContains($code, $codes);
        }
        $this->assertSame('danger', $res->json('advice.0.level'));
        $spike = collect($res->json('advice'))->firstWhere('code', 'category_up');
        $this->assertStringContainsString('food', $spike['text']['en']);
        $this->assertStringContainsString('الطعام', $spike['text']['ar']);
        $this->assertStringContainsString('200', $spike['text']['en']); // 3000 vs avg 1000
    }

    public function test_low_savings_and_idle_cash(): void
    {
        foreach (['2026-07-05', '2026-08-05', '2026-09-05', '2026-10-05'] as $d) {
            $this->tx('income', 1000, $d);
            $this->tx('expense', 900, $d);
        }
        $this->bank->update(['balance' => 100000]);
        $codes = $this->codes($this->getJson('/api/v1/reports?month=2026-10'));
        $this->assertContains('low_savings', $codes);
        $this->assertContains('idle_cash', $codes);
    }

    public function test_healthy_month_has_no_advice(): void
    {
        $this->tx('income', 10000, '2026-10-01');
        $this->tx('expense', 2000, '2026-10-02', 'food');
        $this->getJson('/api/v1/reports')->assertJsonCount(0, 'advice');
    }

    public function test_snapshot_command_upserts_monthly(): void
    {
        $this->bank->update(['balance' => 500]);
        $this->artisan('finance:snapshot')->assertSuccessful();
        $this->bank->update(['balance' => 700]);
        $this->artisan('finance:snapshot')->assertSuccessful();
        $this->assertSame(1, $this->owner->snapshots()->count());
        $this->assertSame(700.0, (float) $this->owner->snapshots()->first()->net_worth);
    }

    public function test_fetch_rates_updates_enabled_owners(): void
    {
        Http::fake(['open.er-api.com/*' => Http::response(['result' => 'success', 'rates' => ['EGP' => 1, 'USD' => 0.02, 'EUR' => 0.0185]])]);
        $this->owner->exchangeRates()->create(['currency' => 'USD', 'rate' => 45]);
        $this->owner->exchangeRates()->create(['currency' => 'SAR', 'rate' => 12]);

        $this->artisan('finance:fetch-rates')->expectsOutput('Updated 0 rate(s).');
        $this->owner->update(['auto_fetch_rates' => true]);
        $this->artisan('finance:fetch-rates')->expectsOutput('Updated 1 rate(s).');
        $this->assertSame(50.0, (float) $this->owner->exchangeRates()->where('currency', 'USD')->value('rate'));
        $this->assertSame(12.0, (float) $this->owner->exchangeRates()->where('currency', 'SAR')->value('rate')); // missing → untouched
    }
}
