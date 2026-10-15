<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs($this->owner = User::factory()->create());
    }

    public function test_create_list_update_delete(): void
    {
        $id = $this->postJson('/api/v1/accounts', [
            'name' => 'CIB Credit', 'type' => 'credit_card', 'currency' => 'egp', 'balance' => -1200,
            'credit_limit' => 30000, 'due_day' => 25, 'last4' => '1234',
        ])->assertCreated()->assertJsonPath('currency', 'EGP')->assertJsonPath('balance', -1200)->json('id');

        $this->getJson('/api/v1/accounts')->assertOk()->assertJsonCount(1)->assertJsonPath('0.base_balance', -1200);

        $this->patchJson("/api/v1/accounts/{$id}", ['name' => 'CIB Platinum', 'is_active' => false])
            ->assertOk()->assertJsonPath('name', 'CIB Platinum')->assertJsonPath('is_active', false);

        $this->deleteJson("/api/v1/accounts/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('accounts', ['id' => $id]);
    }

    public function test_validation(): void
    {
        $this->postJson('/api/v1/accounts', ['name' => '', 'type' => 'piggy', 'currency' => 'EGPX', 'due_day' => 40, 'last4' => '12'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'type', 'currency', 'due_day', 'last4']);
    }

    public function test_all_wallet_types_are_supported(): void
    {
        foreach (['debit_card', 'credit_card', 'instapay', 'vodafone_cash', 'etisalat_cash', 'orange_cash', 'we_pay', 'cash', 'bank', 'savings'] as $type) {
            $this->postJson('/api/v1/accounts', ['name' => $type, 'type' => $type, 'currency' => 'EGP'])->assertCreated();
        }
    }

    public function test_sensitive_fields_are_encrypted_at_rest(): void
    {
        $id = $this->postJson('/api/v1/accounts', ['name' => 'NBE', 'type' => 'bank', 'currency' => 'EGP', 'account_number' => '1234567890', 'notes' => 'branch X'])
            ->assertCreated()->assertJsonPath('account_number', '1234567890')->json('id');

        $raw = DB::table('accounts')->find($id);
        $this->assertStringNotContainsString('1234567890', $raw->account_number);
        $this->assertStringNotContainsString('branch X', $raw->notes);
    }

    public function test_balances_convert_to_base_currency(): void
    {
        $this->owner->exchangeRates()->create(['currency' => 'USD', 'rate' => 50]);
        Account::factory()->for($this->owner)->create(['currency' => 'USD', 'balance' => 10]);

        $this->getJson('/api/v1/accounts')->assertJsonPath('0.base_balance', 500);
    }

    public function test_cannot_touch_another_owners_account(): void
    {
        $other = Account::factory()->create();

        $this->getJson("/api/v1/accounts/{$other->id}")->assertForbidden();
        $this->patchJson("/api/v1/accounts/{$other->id}", ['name' => 'x'])->assertForbidden();
        $this->deleteJson("/api/v1/accounts/{$other->id}")->assertForbidden();
        $this->getJson('/api/v1/accounts')->assertJsonCount(0);
    }

    public function test_account_with_transactions_cannot_be_deleted_or_change_currency(): void
    {
        $account = Account::factory()->for($this->owner)->create();
        app(TransactionService::class)->create($this->owner, ['type' => 'expense', 'account_id' => $account->id, 'amount' => 10, 'date' => '2026-10-01']);

        $this->deleteJson("/api/v1/accounts/{$account->id}")->assertStatus(409)->assertJsonPath('code', 'has_transactions');
        $this->patchJson("/api/v1/accounts/{$account->id}", ['currency' => 'USD'])->assertStatus(422);
    }
}
