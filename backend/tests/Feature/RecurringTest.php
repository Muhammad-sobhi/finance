<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Bill;
use App\Models\User;
use App\Services\RecurringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecurringTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15');
        Sanctum::actingAs($this->owner = User::factory()->create());
        $this->bank = Account::factory()->for($this->owner)->create(['balance' => 5000]);
    }

    public function test_bill_crud_and_mark_paid_rolls_due_date(): void
    {
        $cat = $this->owner->categories()->where('key', 'bills')->value('id');
        $id = $this->postJson('/api/v1/bills', [
            'name' => 'Electricity', 'category_id' => $cat, 'amount' => 640, 'currency' => 'EGP', 'due_date' => '2026-10-17',
            'cycle' => 'monthly', 'remind_days' => 3, 'account_id' => $this->bank->id,
        ])->assertCreated()->assertJsonPath('days', 2)->json('id');

        $this->postJson("/api/v1/bills/{$id}/pay")->assertOk()
            ->assertJsonPath('transaction.amount', 640)->assertJsonPath('transaction.category_id', $cat)
            ->assertJsonPath('transaction.source_type', 'bill')
            ->assertJsonPath('item.due_date', '2026-11-17')->assertJsonPath('item.last_paid_at', '2026-10-15');
        $this->assertSame(4360.0, (float) $this->bank->fresh()->balance);

        $this->patchJson("/api/v1/bills/{$id}", ['amount' => 700])->assertJsonPath('amount', 700);
        $this->getJson('/api/v1/bills')->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/bills/{$id}")->assertNoContent();
        $this->assertDatabaseCount('transactions', 1); // history stays
    }

    public function test_cycles_roll_correctly_and_once_deactivates(): void
    {
        foreach ([['weekly', '2026-10-22'], ['quarterly', '2027-01-15'], ['yearly', '2027-10-15']] as [$cycle, $next]) {
            $id = $this->postJson('/api/v1/bills', ['name' => $cycle, 'amount' => 1, 'currency' => 'EGP', 'due_date' => '2026-10-15', 'cycle' => $cycle])->json('id');
            $this->postJson("/api/v1/bills/{$id}/pay", ['account_id' => $this->bank->id])->assertJsonPath('item.due_date', $next);
        }
        $id = $this->postJson('/api/v1/bills', ['name' => 'Jan 31', 'amount' => 1, 'currency' => 'EGP', 'due_date' => '2027-01-31', 'cycle' => 'monthly'])->json('id');
        $this->postJson("/api/v1/bills/{$id}/pay", ['account_id' => $this->bank->id])->assertJsonPath('item.due_date', '2027-02-28');

        $id = $this->postJson('/api/v1/bills', ['name' => 'Once', 'amount' => 1, 'currency' => 'EGP', 'due_date' => '2026-10-15', 'cycle' => 'once'])->json('id');
        $this->postJson("/api/v1/bills/{$id}/pay", ['account_id' => $this->bank->id])->assertJsonPath('item.is_active', false);
        $this->postJson("/api/v1/bills/{$id}/pay", ['account_id' => $this->bank->id])->assertStatus(422);
    }

    public function test_paying_requires_an_account(): void
    {
        $id = $this->postJson('/api/v1/bills', ['name' => 'Gas', 'amount' => 120, 'currency' => 'EGP', 'due_date' => '2026-10-20', 'cycle' => 'monthly'])->json('id');
        $this->postJson("/api/v1/bills/{$id}/pay")->assertJsonValidationErrors('account_id');
    }

    public function test_subscriptions_monthly_cost_split_by_scope(): void
    {
        $this->owner->exchangeRates()->create(['currency' => 'USD', 'rate' => 50]);
        $post = fn (array $d) => $this->postJson('/api/v1/subscriptions', $d + ['currency' => 'EGP', 'renew_date' => '2026-11-01', 'start_date' => '2025-11-01'])->assertCreated();
        $post(['name' => 'Netflix', 'amount' => 240, 'cycle' => 'monthly', 'scope' => 'personal']);
        $post(['name' => 'Gym', 'amount' => 3600, 'cycle' => 'yearly', 'scope' => 'personal']);
        $post(['name' => 'Claude', 'amount' => 20, 'currency' => 'USD', 'cycle' => 'monthly', 'scope' => 'work']);
        $post(['name' => 'VPS', 'amount' => 1800, 'cycle' => 'quarterly', 'scope' => 'work']);
        $post(['name' => 'Old', 'amount' => 999, 'cycle' => 'monthly', 'scope' => 'work', 'is_active' => false]);

        $this->getJson('/api/v1/subscriptions')->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.monthly.personal', 540)   // 240 + 3600/12
            ->assertJsonPath('meta.monthly.work', 1600)      // 20×50 + 1800/3
            ->assertJsonPath('meta.monthly.total', 2140);
    }

    public function test_subscription_mark_paid_rolls_renew_date(): void
    {
        $id = $this->postJson('/api/v1/subscriptions', ['name' => 'Netflix', 'amount' => 240, 'currency' => 'EGP', 'renew_date' => '2026-10-15', 'cycle' => 'monthly', 'scope' => 'personal', 'account_id' => $this->bank->id])->json('id');
        $this->postJson("/api/v1/subscriptions/{$id}/pay")->assertJsonPath('item.renew_date', '2026-11-15')->assertJsonPath('transaction.source_type', 'subscription');
    }

    public function test_auto_pay_catches_up_due_items(): void
    {
        $internet = Bill::forceCreate(['user_id' => $this->owner->id, 'name' => 'Internet', 'amount' => 450, 'currency' => 'EGP', 'due_date' => '2026-08-20', 'cycle' => 'monthly', 'auto_pay' => true, 'account_id' => $this->bank->id]);
        Bill::forceCreate(['user_id' => $this->owner->id, 'name' => 'Manual', 'amount' => 1, 'currency' => 'EGP', 'due_date' => '2026-10-01', 'cycle' => 'monthly', 'account_id' => $this->bank->id]);

        $this->assertSame(2, app(RecurringService::class)->runAutoPay()); // Aug 20 + Sep 20
        $this->assertSame('2026-10-20', $internet->fresh()->due_date->format('Y-m-d'));
        $this->assertSame(4100.0, (float) $this->bank->fresh()->balance);
        $this->artisan('finance:auto-pay')->assertSuccessful();
    }

    public function test_dashboard_lists_upcoming_bills(): void
    {
        $this->postJson('/api/v1/bills', ['name' => 'Water', 'amount' => 90, 'currency' => 'EGP', 'due_date' => '2026-10-12', 'cycle' => 'monthly']);
        $this->postJson('/api/v1/bills', ['name' => 'Far', 'amount' => 90, 'currency' => 'EGP', 'due_date' => '2027-01-12', 'cycle' => 'monthly']);
        $this->getJson('/api/v1/dashboard')->assertJsonCount(1, 'upcoming')
            ->assertJsonPath('upcoming.0.kind', 'bill')->assertJsonPath('upcoming.0.days', -3);
    }
}
