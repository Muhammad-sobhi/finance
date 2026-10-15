<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $bank;

    private Account $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs($this->owner = User::factory()->create());
        $this->bank = Account::factory()->for($this->owner)->create(['balance' => 1000]);
        $this->wallet = Account::factory()->for($this->owner)->create(['balance' => 200]);
    }

    private function cat(string $key): int
    {
        return $this->owner->categories()->where('key', $key)->value('id');
    }

    private function balance(Account $a): float
    {
        return (float) $a->fresh()->balance;
    }

    public function test_expense_decreases_and_income_increases_balance(): void
    {
        $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $this->bank->id, 'category_id' => $this->cat('food'), 'amount' => 125.5])
            ->assertCreated()->assertJsonPath('date', now()->toDateString())->assertJsonPath('currency', 'EGP');
        $this->assertSame(874.5, $this->balance($this->bank));

        $this->postJson('/api/v1/transactions', ['type' => 'income', 'account_id' => $this->bank->id, 'category_id' => $this->cat('salary'), 'amount' => 500, 'date' => '2026-10-01'])
            ->assertCreated();
        $this->assertSame(1374.5, $this->balance($this->bank));
    }

    public function test_transfer_moves_money_between_accounts(): void
    {
        $this->postJson('/api/v1/transactions', ['type' => 'transfer', 'account_id' => $this->bank->id, 'to_account_id' => $this->wallet->id, 'amount' => 300])
            ->assertCreated()->assertJsonPath('to_amount', 300)->assertJsonPath('category_id', null);

        $this->assertSame(700.0, $this->balance($this->bank));
        $this->assertSame(500.0, $this->balance($this->wallet));
    }

    public function test_cross_currency_transfer_converts_with_rates(): void
    {
        $this->owner->exchangeRates()->create(['currency' => 'USD', 'rate' => 50]);
        $usd = Account::factory()->for($this->owner)->create(['currency' => 'USD', 'balance' => 0]);

        $this->postJson('/api/v1/transactions', ['type' => 'transfer', 'account_id' => $this->bank->id, 'to_account_id' => $usd->id, 'amount' => 500])
            ->assertCreated()->assertJsonPath('to_amount', 10);
        $this->assertSame(10.0, $this->balance($usd));

        // An explicit received amount (the real rate the bank used) wins.
        $this->postJson('/api/v1/transactions', ['type' => 'transfer', 'account_id' => $this->bank->id, 'to_account_id' => $usd->id, 'amount' => 100, 'to_amount' => 1.9])
            ->assertCreated();
        $this->assertSame(11.9, $this->balance($usd));
    }

    public function test_update_reverses_old_effect_and_applies_new(): void
    {
        $id = $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $this->bank->id, 'amount' => 100])->json('id');

        $this->patchJson("/api/v1/transactions/{$id}", ['amount' => 40])->assertOk()->assertJsonPath('amount', 40);
        $this->assertSame(960.0, $this->balance($this->bank));

        $this->patchJson("/api/v1/transactions/{$id}", ['account_id' => $this->wallet->id])->assertOk();
        $this->assertSame(1000.0, $this->balance($this->bank));
        $this->assertSame(160.0, $this->balance($this->wallet));

        $this->patchJson("/api/v1/transactions/{$id}", ['type' => 'transfer', 'to_account_id' => $this->bank->id])->assertOk();
        $this->assertSame(1040.0, $this->balance($this->bank));
        $this->assertSame(160.0, $this->balance($this->wallet));

        $this->patchJson("/api/v1/transactions/{$id}", ['type' => 'income'])->assertOk()->assertJsonPath('to_account_id', null);
        $this->assertSame(1000.0, $this->balance($this->bank));
        $this->assertSame(240.0, $this->balance($this->wallet));
    }

    public function test_delete_reverses_effect(): void
    {
        $id = $this->postJson('/api/v1/transactions', ['type' => 'transfer', 'account_id' => $this->bank->id, 'to_account_id' => $this->wallet->id, 'amount' => 250])->json('id');

        $this->deleteJson("/api/v1/transactions/{$id}")->assertNoContent();
        $this->assertSame(1000.0, $this->balance($this->bank));
        $this->assertSame(200.0, $this->balance($this->wallet));
    }

    public function test_failed_write_leaves_balances_untouched(): void
    {
        $this->postJson('/api/v1/transactions', ['type' => 'transfer', 'account_id' => $this->bank->id, 'amount' => 50])
            ->assertJsonValidationErrors('to_account_id');
        $this->postJson('/api/v1/transactions', ['type' => 'transfer', 'account_id' => $this->bank->id, 'to_account_id' => $this->bank->id, 'amount' => 50])
            ->assertJsonValidationErrors('to_account_id');
        $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $this->bank->id, 'amount' => -5])
            ->assertJsonValidationErrors('amount');
        $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $this->bank->id, 'category_id' => $this->cat('salary'), 'amount' => 5])
            ->assertJsonValidationErrors('category_id');

        $this->assertSame(1000.0, $this->balance($this->bank));
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_cannot_use_or_see_another_owners_records(): void
    {
        $foreign = Account::factory()->create(['balance' => 100]);

        $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $foreign->id, 'amount' => 5])
            ->assertJsonValidationErrors('account_id');

        $other = $foreign->user;
        $txId = app(TransactionService::class)->create($other, ['type' => 'expense', 'account_id' => $foreign->id, 'amount' => 5, 'date' => now()->toDateString()])->id;

        $this->getJson("/api/v1/transactions/{$txId}")->assertForbidden();
        $this->patchJson("/api/v1/transactions/{$txId}", ['amount' => 1])->assertForbidden();
        $this->deleteJson("/api/v1/transactions/{$txId}")->assertForbidden();
        $this->getJson('/api/v1/transactions')->assertJsonCount(0, 'data');
        $this->assertSame(95.0, (float) $foreign->fresh()->balance);
    }

    public function test_month_listing_with_filters_and_day_totals(): void
    {
        $food = $this->cat('food');
        $post = fn (array $d) => $this->postJson('/api/v1/transactions', $d + ['account_id' => $this->bank->id])->assertCreated();
        $post(['type' => 'expense', 'category_id' => $food, 'amount' => 50, 'date' => '2026-09-10']);
        $post(['type' => 'expense', 'category_id' => $food, 'amount' => 20, 'date' => '2026-09-10']);
        $post(['type' => 'expense', 'category_id' => $this->cat('transport'), 'amount' => 30, 'date' => '2026-09-11']);
        $post(['type' => 'income', 'category_id' => $this->cat('salary'), 'amount' => 1000, 'date' => '2026-09-01']);
        $post(['type' => 'expense', 'amount' => 999, 'date' => '2026-08-31']);
        $post(['type' => 'transfer', 'to_account_id' => $this->wallet->id, 'amount' => 5, 'date' => '2026-09-12']);

        $this->getJson('/api/v1/transactions?month=2026-09')->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.date', '2026-09-12')
            ->assertJsonPath('meta.totals.expense', 100)
            ->assertJsonPath('meta.totals.income', 1000)
            ->assertJsonPath('meta.totals.net', 900)
            ->assertJsonPath('meta.days.2026-09-10.expense', 70);

        $this->getJson("/api/v1/transactions?month=2026-09&category_id={$food}")
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.totals.expense', 70);
        $this->getJson("/api/v1/transactions?month=2026-09&account_id={$this->wallet->id}")->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/transactions?month=bad')->assertStatus(422);
    }

    public function test_quick_meta_returns_last_used_account(): void
    {
        $this->getJson('/api/v1/transactions/quick-meta')->assertJsonPath('last_account_id', null);
        $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $this->wallet->id, 'category_id' => $this->cat('food'), 'amount' => 5]);

        $this->getJson('/api/v1/transactions/quick-meta')
            ->assertJsonPath('last_account_id', $this->wallet->id)
            ->assertJsonPath('last_category_id', $this->cat('food'));
    }
}
