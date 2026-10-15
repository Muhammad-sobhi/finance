<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Notifications\InfoAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SmartTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15'); // 16 days left in October
        $this->owner = User::factory()->create(['email_alerts' => false, 'locale' => 'en']);
        $this->wallet = Account::factory()->for($this->owner)->create(['balance' => 100000]);
        Sanctum::actingAs($this->owner);
    }

    private function tx(string $type, float $amount, string $date, string $cat = 'food'): int
    {
        return $this->postJson('/api/v1/transactions', [
            'type' => $type, 'account_id' => $this->wallet->id, 'amount' => $amount, 'date' => $date,
            'category_id' => $this->owner->categories()->where('key', $cat)->value('id'),
        ])->assertCreated()->json('id');
    }

    public function test_month_end_forecast(): void
    {
        $this->tx('expense', 3000, '2026-10-05');                 // everyday: 3000 / 15 days = 200/day
        $this->tx('income', 20000, '2026-10-01', 'salary');
        // A bill already paid this month counts as spent, not as pace.
        $bill = $this->postJson('/api/v1/bills', ['name' => 'Rent', 'amount' => 5000, 'currency' => 'EGP', 'due_date' => '2026-10-02', 'cycle' => 'monthly', 'account_id' => $this->wallet->id])->json('id');
        $this->postJson("/api/v1/bills/{$bill}/pay", ['date' => '2026-10-02']);
        // Still to come: a weekly subscription (Oct 20, 27) and an installment due Oct 25.
        $this->postJson('/api/v1/subscriptions', ['name' => 'Gym', 'amount' => 100, 'currency' => 'EGP', 'renew_date' => '2026-10-20', 'cycle' => 'weekly', 'scope' => 'personal']);
        $this->postJson('/api/v1/installments', ['name' => 'TV', 'currency' => 'EGP', 'installment_amount' => 1000, 'count' => 5, 'first_due_date' => '2026-10-25']);
        // Expected income: a project payment due this month.
        $this->postJson('/api/v1/projects', ['client' => 'C', 'name' => 'P', 'amount' => 4000, 'currency' => 'EGP', 'due_date' => '2026-10-30']);

        $this->getJson('/api/v1/dashboard')
            ->assertJsonPath('forecast.spent', 8000)
            ->assertJsonPath('forecast.daily_rate', 200)
            ->assertJsonPath('forecast.scheduled', 1200)                // 2 × 100 + 1000
            ->assertJsonPath('forecast.projected_spend', 12400)         // 8000 + 200 × 16 + 1200
            ->assertJsonPath('forecast.projected_income', 24000)
            ->assertJsonPath('forecast.projected_savings', 11600);
    }

    public function test_forecast_early_in_month_uses_three_month_pace_and_feeds_advice(): void
    {
        Carbon::setTestNow('2026-10-03');
        $this->tx('expense', 9200, '2026-08-10');               // Jul–Sep: 9200 / 92 days = 100/day
        $this->tx('expense', 2000, '2026-10-01');               // one big day doesn't set the pace
        $this->getJson('/api/v1/dashboard')->assertJsonPath('forecast.daily_rate', 100)->assertJsonPath('forecast.projected_spend', 4800);

        // No income → spending more than earning by month end: advice warns.
        $codes = collect($this->getJson('/api/v1/reports?period=month&value=2026-10')->json('advice'))->pluck('code');
        $this->assertContains('forecast_negative', $codes);
    }

    public function test_unusual_expense_alert(): void
    {
        foreach ([100, 120, 90, 110, 130] as $n => $amount) {
            $this->tx('expense', $amount, '2026-10-0'.($n + 1));
        }
        $notes = fn () => $this->owner->notifications()->where('type', InfoAlert::class)->where('data->kind', 'unusual_expense')->get();
        $this->tx('expense', 300, '2026-10-10');                 // 2.7× the median 110: fine
        $this->assertCount(0, $notes());
        $this->tx('expense', 900, '2026-10-11');                 // 8× → flagged
        $this->assertCount(1, $notes());
        $this->assertSame('You just spent 900 EGP on Food — about 7× what it usually costs (115).', $notes()->first()->data['text']['en']);
        $this->tx('expense', 5000, '2026-10-12', 'health');      // no history for health: no judgement
        $this->assertCount(1, $notes());
    }

    public function test_metal_prices_from_spot_feed(): void
    {
        $down = false;
        Http::fake(function ($req) use (&$down) {
            return match (true) {
                $down => Http::response('down', 500),
                str_ends_with($req->url(), '/XAU') => Http::response(['price' => 3110.34768]), // 100 USD per gram
                default => Http::response(['price' => 31.1034768]),                           // 1 USD per gram
            };
        });
        // No USD rate yet: refuse rather than guess.
        $this->postJson('/api/v1/settings/metal-prices')->assertStatus(422)->assertJsonPath('code', 'usd_rate_missing');

        $this->postJson('/api/v1/exchange-rates', ['currency' => 'USD', 'rate' => 48.5]);
        $this->postJson('/api/v1/settings/metal-prices')->assertOk()
            ->assertJsonPath('gold_price_24k', 4850)   // 100 USD/g × 48.5
            ->assertJsonPath('silver_price', 48.5);

        // The daily job only touches owners who opted in.
        $this->patchJson('/api/v1/settings', ['gold_price_24k' => 1, 'auto_metal_prices' => true])->assertJsonPath('auto_metal_prices', true);
        $this->artisan('finance:fetch-metals')->expectsOutputToContain('1 owner')->assertSuccessful();
        $this->assertSame(4850.0, (float) $this->owner->fresh()->gold_price_24k);

        $down = true;
        $this->postJson('/api/v1/settings/metal-prices')->assertStatus(503);
    }
}
