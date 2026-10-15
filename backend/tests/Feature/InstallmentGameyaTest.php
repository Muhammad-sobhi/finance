<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Services\AlertScanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InstallmentGameyaTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15');
        $this->owner = User::factory()->create(['email_alerts' => false, 'locale' => 'en']);
        $this->wallet = Account::factory()->for($this->owner)->create(['balance' => 50000]);
        Sanctum::actingAs($this->owner);
    }

    public function test_installment_plan_schedule_payments_and_net_worth(): void
    {
        $shopping = $this->owner->categories()->where('key', 'shopping')->value('id');
        $id = $this->postJson('/api/v1/installments', [
            'name' => 'Phone', 'provider' => 'valU', 'currency' => 'EGP', 'installment_amount' => 2500, 'count' => 12,
            'first_due_date' => '2026-01-31', 'account_id' => $this->wallet->id, 'category_id' => $shopping,
        ])->assertCreated()
            ->assertJsonPath('total', 30000)->assertJsonPath('remaining', 30000)
            ->assertJsonPath('next_due', '2026-01-31')->assertJsonPath('last_due', '2026-12-31')->json('id');

        // Month-end dates don't drift: Jan 31 → Feb 28 → Mar 31.
        $this->postJson("/api/v1/installments/{$id}/pay", ['date' => '2026-01-31'])->assertCreated()->assertJsonPath('next_due', '2026-02-28');
        $this->postJson("/api/v1/installments/{$id}/pay", ['date' => '2026-02-28'])->assertJsonPath('next_due', '2026-03-31')
            ->assertJsonPath('paid_count', 2)->assertJsonPath('remaining', 25000);
        $this->assertSame(45000.0, (float) $this->wallet->fresh()->balance);

        // It's spending in reports, on the plan's category, and locked from direct edits.
        $tx = $this->owner->transactions()->where('source_type', 'installment_payment')->latest('id')->first();
        $this->assertSame($shopping, $tx->category_id);
        $this->deleteJson("/api/v1/transactions/{$tx->id}")->assertStatus(409)->assertJsonPath('code', 'managed_by_installment');

        // Net worth counts what's left as a liability; zakat deducts only the next 12 months (Mar–Dec 2026 = 10).
        $this->getJson('/api/v1/dashboard')->assertJsonPath('installments', 25000)->assertJsonPath('net_worth', 20000);
        $this->getJson('/api/v1/zakat')->assertJsonPath('parts.installments', 25000);
        $this->getJson('/api/v1/installments')->assertJsonPath('meta.remaining', 25000)->assertJsonPath('meta.this_month', 20000); // 8 overdue + Oct

        // Only the latest payment can be undone; count can't drop below paid.
        $payments = $this->getJson("/api/v1/installments/{$id}")->assertJsonCount(2, 'payments')->json('payments');
        $this->deleteJson("/api/v1/installments/{$id}/payments/{$payments[1]['id']}")->assertJsonValidationErrors('payment');
        $this->deleteJson("/api/v1/installments/{$id}/payments/{$payments[0]['id']}")->assertJsonPath('paid_count', 1);
        $this->assertSame(47500.0, (float) $this->wallet->fresh()->balance);
        $this->patchJson("/api/v1/installments/{$id}", ['count' => 0])->assertJsonValidationErrors('count');

        // Paying them all finishes the plan.
        $this->patchJson("/api/v1/installments/{$id}", ['count' => 2]);
        $this->postJson("/api/v1/installments/{$id}/pay", [])->assertJsonPath('done', true)->assertJsonPath('next_due', null);
        $this->postJson("/api/v1/installments/{$id}/pay", [])->assertJsonValidationErrors('amount');

        $this->deleteJson("/api/v1/installments/{$id}")->assertNoContent();
        $this->assertSame(50000.0, (float) $this->wallet->fresh()->balance);
    }

    public function test_installment_alerts(): void
    {
        $this->postJson('/api/v1/installments', ['name' => 'Fridge', 'currency' => 'EGP', 'installment_amount' => 1000, 'count' => 6, 'first_due_date' => '2026-10-17']);
        $this->assertSame(1, app(AlertScanner::class)->scanOwner($this->owner, CarbonImmutable::today()));
        $this->getJson('/api/v1/dashboard')->assertJsonPath('upcoming.0.kind', 'installment')->assertJsonPath('upcoming.0.days', 2);
    }

    public function test_gameya_rounds_payout_and_position(): void
    {
        // 10 members × 1000/month, my turn is the 3rd → I take 10000 in December.
        $id = $this->postJson('/api/v1/gameyas', [
            'name' => 'Work gameya', 'organizer' => 'Mona', 'members' => 10, 'my_turn' => 3, 'amount' => 1000,
            'currency' => 'EGP', 'start_date' => '2026-10-01', 'account_id' => $this->wallet->id,
        ])->assertCreated()
            ->assertJsonPath('payout', 10000)->assertJsonPath('payout_date', '2026-12-01')->assertJsonPath('end_date', '2027-07-01')
            ->assertJsonPath('next_round', 1)->assertJsonPath('days', -14)->json('id');
        $this->postJson('/api/v1/gameyas', ['name' => 'x', 'members' => 5, 'my_turn' => 6, 'amount' => 1, 'currency' => 'EGP', 'start_date' => '2026-10-01'])
            ->assertJsonValidationErrors('my_turn');

        $this->postJson("/api/v1/gameyas/{$id}/pay", [])->assertCreated()->assertJsonPath('next_round', 2)->assertJsonPath('position', 1000);
        $this->postJson("/api/v1/gameyas/{$id}/pay", [])->assertJsonPath('position', 2000);
        $this->assertSame(48000.0, (float) $this->wallet->fresh()->balance);

        // Savings, not spending: excluded from the month's expenses.
        $this->getJson('/api/v1/dashboard')->assertJsonPath('spending.5.expense', 0)->assertJsonPath('gameya', 2000)->assertJsonPath('net_worth', 50000);

        $this->postJson("/api/v1/gameyas/{$id}/receive", [])->assertJsonPath('received', true)->assertJsonPath('position', -8000);
        $this->postJson("/api/v1/gameyas/{$id}/receive", [])->assertJsonValidationErrors('round');
        $this->assertSame(58000.0, (float) $this->wallet->fresh()->balance);
        // After the payout you owe the remaining rounds: net worth unchanged, zakat deducts it.
        $this->getJson('/api/v1/dashboard')->assertJsonPath('net_worth', 50000);
        $this->getJson('/api/v1/zakat')->assertJsonPath('parts.i_owe', 8000);
        $this->patchJson("/api/v1/gameyas/{$id}", ['my_turn' => 5])->assertJsonValidationErrors('my_turn');

        for ($i = 3; $i <= 10; $i++) {
            $this->postJson("/api/v1/gameyas/{$id}/pay", []);
        }
        $this->getJson("/api/v1/gameyas/{$id}")->assertJsonPath('done', true)->assertJsonPath('position', 0)->assertJsonCount(11, 'payments');
        $this->postJson("/api/v1/gameyas/{$id}/pay", [])->assertJsonValidationErrors('round');
        $this->assertSame(50000.0, (float) $this->wallet->fresh()->balance);

        $this->deleteJson("/api/v1/gameyas/{$id}")->assertNoContent();
        $this->assertSame(0, $this->owner->transactions()->count());
    }

    public function test_gameya_alerts_contribution_and_turn(): void
    {
        $this->postJson('/api/v1/gameyas', ['name' => 'Family', 'members' => 5, 'my_turn' => 1, 'amount' => 500, 'currency' => 'EGP', 'start_date' => '2026-10-16']);
        app(AlertScanner::class)->scanOwner($this->owner, CarbonImmutable::today());
        $this->assertEqualsCanonicalizing(['gameya', 'gameya_payout'], $this->owner->notifications()->get()->pluck('data.kind')->all());
    }

    public function test_heirs_and_strangers(): void
    {
        $gid = $this->postJson('/api/v1/gameyas', ['name' => 'G', 'members' => 3, 'my_turn' => 1, 'amount' => 1, 'currency' => 'EGP', 'start_date' => '2026-10-01'])->json('id');
        $iid = $this->postJson('/api/v1/installments', ['name' => 'I', 'currency' => 'EGP', 'installment_amount' => 1, 'count' => 2, 'first_due_date' => '2026-10-01'])->json('id');
        Sanctum::actingAs(User::factory()->heirOf($this->owner)->create());
        $this->getJson('/api/v1/gameyas')->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/gameyas/{$gid}/pay", [])->assertForbidden();
        $this->postJson("/api/v1/installments/{$iid}/pay", [])->assertForbidden();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/installments/{$iid}")->assertForbidden();
        $this->postJson("/api/v1/gameyas/{$gid}/pay", [])->assertForbidden();
    }
}
