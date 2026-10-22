<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Debt;
use App\Models\User;
use App\Services\DebtService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DebtTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs($this->owner = User::factory()->create());
        $this->bank = Account::factory()->for($this->owner)->create(['balance' => 10000]);
    }

    private function bal(): float
    {
        return (float) $this->bank->fresh()->balance;
    }

    public function test_lending_moves_money_out_and_repayments_bring_it_back(): void
    {
        $id = $this->postJson('/api/v1/debts', [
            'person' => 'Ahmed', 'direction' => 'owed_to_me', 'amount' => 2000, 'currency' => 'EGP',
            'date' => '2026-10-01', 'due_date' => '2026-11-01', 'account_id' => $this->bank->id, 'notes' => 'for the car',
        ])->assertCreated()->assertJsonPath('remaining', 2000)->assertJsonPath('status', 'open')->json('id');
        $this->assertSame(8000.0, $this->bal());

        $this->postJson("/api/v1/debts/{$id}/payments", ['amount' => 500, 'account_id' => $this->bank->id])
            ->assertCreated()->assertJsonPath('debt.paid', 500)->assertJsonPath('debt.remaining', 1500);
        $this->assertSame(8500.0, $this->bal());

        // Partial payment without an account just records it.
        $this->postJson("/api/v1/debts/{$id}/payments", ['amount' => 1000, 'date' => '2026-10-10'])->assertCreated();
        $this->assertSame(8500.0, $this->bal());

        $this->postJson("/api/v1/debts/{$id}/payments", ['amount' => 600])->assertJsonValidationErrors('amount');
        $this->postJson("/api/v1/debts/{$id}/payments", ['amount' => 500])->assertJsonPath('debt.status', 'settled');

        $this->getJson("/api/v1/debts/{$id}")->assertJsonCount(3, 'payments');
    }

    public function test_payment_rechecks_whats_left_inside_the_transaction(): void
    {
        $id = $this->postJson('/api/v1/debts', ['person' => 'Sara', 'direction' => 'owed_to_me', 'amount' => 2000, 'currency' => 'EGP'])->json('id');
        // A second request validated against the old remaining amount, before the first one saved.
        $stale = Debt::with('payments')->find($id);
        $this->postJson("/api/v1/debts/{$id}/payments", ['amount' => 1500])->assertCreated();

        try {
            app(DebtService::class)->addPayment($stale, ['amount' => 1000, 'date' => '2026-10-10']);
            $this->fail('Overpayment was accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }
        $this->assertSame(1, Debt::find($id)->payments()->count());
    }

    public function test_borrowing_and_paying_back(): void
    {
        $id = $this->postJson('/api/v1/debts', ['person' => 'Bank', 'direction' => 'i_owe', 'amount' => 3000, 'currency' => 'EGP', 'account_id' => $this->bank->id])
            ->assertCreated()->json('id');
        $this->assertSame(13000.0, $this->bal());

        $pid = $this->postJson("/api/v1/debts/{$id}/payments", ['amount' => 3000, 'account_id' => $this->bank->id])->json('id');
        $this->assertSame(10000.0, $this->bal());

        // Deleting a payment reverses its money and reopens the debt.
        $this->deleteJson("/api/v1/debts/{$id}/payments/{$pid}")->assertOk()->assertJsonPath('status', 'open');
        $this->assertSame(13000.0, $this->bal());

        $this->deleteJson("/api/v1/debts/{$id}")->assertNoContent();
        $this->assertSame(10000.0, $this->bal());
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_editing_the_debt_resyncs_its_transaction(): void
    {
        $id = $this->postJson('/api/v1/debts', ['person' => 'Sara', 'direction' => 'owed_to_me', 'amount' => 1000, 'currency' => 'EGP', 'account_id' => $this->bank->id])->json('id');
        $this->patchJson("/api/v1/debts/{$id}", ['amount' => 1500])->assertOk();
        $this->assertSame(8500.0, $this->bal());
        $this->patchJson("/api/v1/debts/{$id}", ['account_id' => null])->assertOk();
        $this->assertSame(10000.0, $this->bal());
    }

    public function test_debt_movements_are_locked_and_excluded_from_cashflow(): void
    {
        $id = $this->postJson('/api/v1/debts', ['person' => 'Ali', 'direction' => 'owed_to_me', 'amount' => 700, 'currency' => 'EGP', 'account_id' => $this->bank->id])->json('id');
        $tx = Debt::find($id)->transactions()->first();

        $this->patchJson("/api/v1/transactions/{$tx->id}", ['amount' => 1])->assertStatus(409)->assertJsonPath('code', 'managed_by_debt');
        $this->deleteJson("/api/v1/transactions/{$tx->id}")->assertStatus(409);
        $this->getJson('/api/v1/transactions')->assertJsonCount(1, 'data')->assertJsonPath('meta.totals.expense', 0);
    }

    public function test_totals_and_foreign_currency(): void
    {
        $this->owner->exchangeRates()->create(['currency' => 'USD', 'rate' => 50]);
        $this->postJson('/api/v1/debts', ['person' => 'A', 'direction' => 'owed_to_me', 'amount' => 100, 'currency' => 'usd', 'account_id' => $this->bank->id])
            ->assertJsonPath('currency', 'USD');
        $this->assertSame(5000.0, $this->bal()); // 100 USD left the EGP account as 5000 EGP
        $this->postJson('/api/v1/debts', ['person' => 'B', 'direction' => 'i_owe', 'amount' => 300, 'currency' => 'EGP']);

        $this->getJson('/api/v1/debts')->assertJsonPath('meta.totals.owed_to_me', 5000)->assertJsonPath('meta.totals.i_owe', 300);
        $this->getJson('/api/v1/dashboard')->assertJsonPath('owed_to_me', 5000)->assertJsonPath('i_owe', 300);
    }

    public function test_other_owner_cannot_touch_debt(): void
    {
        $debt = Debt::forceCreate(['person' => 'x', 'direction' => 'i_owe', 'amount' => 1, 'currency' => 'EGP', 'date' => '2026-01-01'] + ['user_id' => User::factory()->create()->id]);
        $this->getJson("/api/v1/debts/{$debt->id}")->assertForbidden();
        $this->postJson("/api/v1/debts/{$debt->id}/payments", ['amount' => 1])->assertForbidden();
        $this->deleteJson("/api/v1/debts/{$debt->id}")->assertForbidden();
    }
}
