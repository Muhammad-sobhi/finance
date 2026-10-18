<?php

namespace Tests\Feature;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Bill;
use App\Models\User;
use App\Notifications\InfoAlert;
use App\Services\RecurringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FundsCheckTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15');
        Sanctum::actingAs($this->owner = User::factory()->create());
        $this->wallet = Account::factory()->for($this->owner)->create(['type' => AccountType::Cash, 'balance' => 100]);
    }

    private function balance(Account $a): float
    {
        return (float) $a->fresh()->balance;
    }

    public function test_expense_larger_than_balance_is_refused(): void
    {
        $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $this->wallet->id, 'amount' => 150])
            ->assertUnprocessable()->assertJsonPath('code', 'insufficient_funds')
            ->assertJsonValidationErrors('account_id')
            ->assertJsonPath('funds.account_id', $this->wallet->id)
            ->assertJsonPath('funds.available', 100)->assertJsonPath('funds.shortfall', 50);

        $this->assertSame(100.0, $this->balance($this->wallet));
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_spending_the_exact_balance_is_allowed(): void
    {
        $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $this->wallet->id, 'amount' => 100])->assertCreated();
        $this->assertSame(0.0, $this->balance($this->wallet));
    }

    public function test_transfer_and_its_fee_must_fit(): void
    {
        $bank = Account::factory()->for($this->owner)->create(['balance' => 0]);
        $this->postJson('/api/v1/transactions', ['type' => 'transfer', 'account_id' => $this->wallet->id, 'to_account_id' => $bank->id, 'amount' => 95, 'fee' => 10])
            ->assertUnprocessable()->assertJsonPath('funds.shortfall', 5);
        $this->assertSame(0.0, $this->balance($bank));

        // Topping up the short account, then paying, is the flow the app offers.
        $this->postJson('/api/v1/transactions', ['type' => 'transfer', 'account_id' => $this->wallet->id, 'to_account_id' => $bank->id, 'amount' => 90, 'fee' => 10])->assertCreated();
        $this->assertSame(0.0, $this->balance($this->wallet));
        $this->assertSame(90.0, $this->balance($bank));
    }

    public function test_editing_counts_only_the_extra_amount(): void
    {
        $id = $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $this->wallet->id, 'amount' => 80])->json('id');

        $this->patchJson("/api/v1/transactions/{$id}", ['type' => 'expense', 'account_id' => $this->wallet->id, 'amount' => 100])->assertOk();
        $this->patchJson("/api/v1/transactions/{$id}", ['type' => 'expense', 'account_id' => $this->wallet->id, 'amount' => 101])
            ->assertUnprocessable()->assertJsonPath('funds.shortfall', 1);
        $this->assertSame(0.0, $this->balance($this->wallet));
    }

    public function test_an_already_negative_account_can_still_be_corrected(): void
    {
        $id = $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $this->wallet->id, 'amount' => 60])->json('id');
        $this->wallet->fresh()->update(['balance' => -20]);

        // Lowering the expense or changing its note doesn't deepen the hole.
        $this->patchJson("/api/v1/transactions/{$id}", ['type' => 'expense', 'account_id' => $this->wallet->id, 'amount' => 50, 'note' => 'fixed'])->assertOk();
        $this->assertSame(-10.0, $this->balance($this->wallet));
        $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $this->wallet->id, 'amount' => 1])->assertUnprocessable();
        $this->deleteJson("/api/v1/transactions/{$id}")->assertNoContent();
    }

    public function test_credit_card_spends_against_its_limit(): void
    {
        $card = Account::factory()->for($this->owner)->create(['type' => AccountType::CreditCard, 'balance' => -4000, 'credit_limit' => 5000]);
        $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $card->id, 'amount' => 1000])->assertCreated();
        $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $card->id, 'amount' => 1])
            ->assertUnprocessable()->assertJsonPath('funds.available', 0)->assertJsonPath('funds.shortfall', 1);
        $this->assertSame(-5000.0, $this->balance($card));

        $noLimit = Account::factory()->for($this->owner)->create(['type' => AccountType::CreditCard, 'balance' => 0, 'credit_limit' => null]);
        $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $noLimit->id, 'amount' => 900])->assertCreated();
    }

    public function test_mark_paid_and_debt_payment_are_checked(): void
    {
        $bill = $this->postJson('/api/v1/bills', ['name' => 'Rent', 'amount' => 500, 'currency' => 'EGP', 'due_date' => '2026-10-20', 'cycle' => 'monthly', 'account_id' => $this->wallet->id])->json('id');
        $this->postJson("/api/v1/bills/{$bill}/pay")->assertUnprocessable()->assertJsonPath('code', 'insufficient_funds');
        $this->assertSame('2026-10-20', Bill::find($bill)->due_date->format('Y-m-d'));   // not rolled

        $debt = $this->postJson('/api/v1/debts', ['person' => 'Ali', 'direction' => 'i_owe', 'amount' => 300, 'currency' => 'EGP', 'date' => '2026-10-01'])->json('id');
        $this->postJson("/api/v1/debts/{$debt}/payments", ['amount' => 200, 'date' => '2026-10-15', 'account_id' => $this->wallet->id])
            ->assertUnprocessable()->assertJsonPath('code', 'insufficient_funds');
        $this->assertDatabaseCount('debt_payments', 0);
    }

    public function test_auto_pay_skips_a_short_account_and_tells_the_owner_once(): void
    {
        Notification::fake();
        $bill = Bill::forceCreate(['user_id' => $this->owner->id, 'name' => 'Internet', 'amount' => 450, 'currency' => 'EGP', 'due_date' => '2026-10-10', 'cycle' => 'monthly', 'auto_pay' => true, 'account_id' => $this->wallet->id]);

        $this->assertSame(0, app(RecurringService::class)->runAutoPay());
        $this->assertSame(0, app(RecurringService::class)->runAutoPay());
        $this->assertSame('2026-10-10', $bill->fresh()->due_date->format('Y-m-d'));
        Notification::assertSentToTimes($this->owner, InfoAlert::class, 1);
        Notification::assertSentTo($this->owner, InfoAlert::class, fn (InfoAlert $n) => $n->kind === 'autopay_short' && $n->params['shortfall'] === 350.0);

        $this->wallet->fresh()->update(['balance' => 1000]);
        $this->assertSame(1, app(RecurringService::class)->runAutoPay());
    }
}
