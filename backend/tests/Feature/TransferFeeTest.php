<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransferFeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_fee_is_spending_and_follows_the_transfer(): void
    {
        Carbon::setTestNow('2026-10-15');
        Sanctum::actingAs($owner = User::factory()->create());
        $bank = Account::factory()->for($owner)->create(['balance' => 10000]);
        $wallet = Account::factory()->for($owner)->create(['balance' => 0, 'type' => 'vodafone_cash']);

        $id = $this->postJson('/api/v1/transactions', ['type' => 'transfer', 'account_id' => $bank->id, 'to_account_id' => $wallet->id, 'amount' => 1000, 'fee' => 5, 'date' => '2026-10-15'])
            ->assertCreated()->assertJsonPath('fee', 5)->json('id');
        $this->assertSame(8995.0, (float) $bank->fresh()->balance);   // 1000 + 5 fee
        $this->assertSame(1000.0, (float) $wallet->fresh()->balance);

        // The fee is an expense in the "fees" category; the transfer itself isn't.
        $this->getJson('/api/v1/transactions?month=2026-10')->assertJsonPath('meta.totals.expense', 5);
        $fee = $owner->transactions()->where('source_type', 'transfer_fee')->first();
        $this->assertSame($owner->categories()->where('key', 'fees')->value('id'), $fee->category_id);
        $this->deleteJson("/api/v1/transactions/{$fee->id}")->assertStatus(409)->assertJsonPath('code', 'managed_by_transfer');

        // Editing other fields keeps the fee; changing it re-books it; 0 removes it.
        $this->patchJson("/api/v1/transactions/{$id}", ['note' => 'to wallet'])->assertJsonPath('fee', 5);
        $this->assertSame(8995.0, (float) $bank->fresh()->balance);
        $this->patchJson("/api/v1/transactions/{$id}", ['fee' => 7.5]);
        $this->assertSame(8992.5, (float) $bank->fresh()->balance);
        $this->assertSame(1, $owner->transactions()->where('source_type', 'transfer_fee')->count());
        $this->patchJson("/api/v1/transactions/{$id}", ['fee' => 0])->assertJsonPath('fee', null);
        $this->assertSame(9000.0, (float) $bank->fresh()->balance);

        // Deleting the transfer removes its fee too.
        $this->patchJson("/api/v1/transactions/{$id}", ['fee' => 3]);
        $this->deleteJson("/api/v1/transactions/{$id}")->assertNoContent();
        $this->assertSame(10000.0, (float) $bank->fresh()->balance);
        $this->assertSame(0.0, (float) $wallet->fresh()->balance);
        $this->assertSame(0, $owner->transactions()->count());

        // Only transfers carry a fee.
        $e = $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $bank->id, 'amount' => 10, 'fee' => 2])->assertJsonPath('fee', null);
        $this->assertSame(9990.0, (float) $bank->fresh()->balance);
        $this->postJson('/api/v1/transactions', ['type' => 'transfer', 'account_id' => $bank->id, 'to_account_id' => $wallet->id, 'amount' => 1, 'fee' => -1])->assertJsonValidationErrors('fee');
    }
}
