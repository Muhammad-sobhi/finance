<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Notifications\InfoAlert;
use App\Services\AlertScanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GameyaManagerTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16');
        $this->manager = User::factory()->create(['name' => 'Umm Ahmed', 'email_alerts' => false, 'locale' => 'en']);
        $this->member = User::factory()->create(['name' => 'Sara', 'email' => 'sara@example.com', 'email_alerts' => false, 'locale' => 'en']);
        Sanctum::actingAs($this->manager);
    }

    /** 5 turns of 1000/month from Oct 1; the manager has no turn. */
    private function gameya(array $o = []): array
    {
        return $this->postJson('/api/v1/gameyas', $o + [
            'role' => 'manager', 'name' => 'Office', 'members' => 5, 'amount' => 1000, 'currency' => 'EGP', 'start_date' => '2026-10-01',
        ])->assertCreated()->json();
    }

    private function slot(int $gid, int $turn): array
    {
        return collect($this->getJson("/api/v1/gameyas/{$gid}")->json('slots'))->firstWhere('turn', $turn);
    }

    public function test_manager_tracks_members_payments_and_payouts(): void
    {
        $g = $this->gameya();
        $this->assertSame('manager', $g['role']);
        $this->assertNull($g['my_turn']);
        $this->assertSame(1, $g['current_round']);
        $this->getJson("/api/v1/gameyas/{$g['id']}")->assertJsonCount(5, 'slots');

        $s1 = $this->slot($g['id'], 1)['id'];
        $this->patchJson("/api/v1/gameyas/{$g['id']}/members/{$s1}", ['name' => 'Mona Ali', 'phone' => '0100', 'notes' => 'cousin'])->assertOk();
        $this->postJson("/api/v1/gameyas/{$g['id']}/members/{$s1}/pay", ['round' => 1])->assertOk()->assertJsonPath('paid_this_round', 1);
        $this->postJson("/api/v1/gameyas/{$g['id']}/members/{$s1}/pay", ['round' => 9])->assertJsonValidationErrors('round');
        $this->postJson("/api/v1/gameyas/{$g['id']}/members/{$s1}/payout", [])->assertOk();
        $slot = $this->slot($g['id'], 1);
        $this->assertSame([1], array_column($slot['payments'], 'round'));
        $this->assertSame(5000.0, (float) $slot['payout_amount']);

        // The manager's own money is untouched (they only hold it for the group).
        $this->assertSame(0, DB::table('transactions')->count());
        // Names, phones and amounts are encrypted with the manager's key.
        $raw = json_encode(DB::table('gameya_members')->get()).json_encode(DB::table('gameya_member_payments')->get());
        $this->assertStringNotContainsString('Mona', $raw);
        $this->assertStringNotContainsString('0100', $raw);

        // Shrinking can't drop a turn that holds data; growing adds turns.
        $this->patchJson("/api/v1/gameyas/{$g['id']}", ['members' => 6])->assertOk();
        $this->getJson("/api/v1/gameyas/{$g['id']}")->assertJsonCount(6, 'slots');
        $this->patchJson("/api/v1/gameyas/{$g['id']}", ['members' => 5])->assertOk();
        $this->patchJson("/api/v1/gameyas/{$g['id']}", ['role' => 'member'])->assertJsonValidationErrors('role');
    }

    public function test_manager_with_own_turn_uses_the_money_flow(): void
    {
        $wallet = Account::factory()->for($this->manager)->create(['balance' => 5000]);
        $g = $this->gameya(['my_turn' => 2, 'account_id' => $wallet->id]);
        $me = $this->slot($g['id'], 2);
        $this->assertTrue($me['is_me']);
        $this->postJson("/api/v1/gameyas/{$g['id']}/members/{$me['id']}/pay", ['round' => 1])->assertJsonValidationErrors('member');
        $this->postJson("/api/v1/gameyas/{$g['id']}/pay", [])->assertCreated();
        $this->assertSame(4000.0, (float) $wallet->fresh()->balance);
        $this->assertSame([1], array_column($this->slot($g['id'], 2)['payments'], 'round'));
    }

    public function test_linked_member_sees_only_their_turn_and_payments(): void
    {
        Notification::fake();
        $g = $this->gameya();
        $s3 = $this->slot($g['id'], 3)['id'];
        $s4 = $this->slot($g['id'], 4)['id'];
        $this->patchJson("/api/v1/gameyas/{$g['id']}/members/{$s3}", ['name' => 'Sara', 'email' => 'SARA@example.com'])->assertOk();
        $this->patchJson("/api/v1/gameyas/{$g['id']}/members/{$s4}", ['name' => 'Secret Other', 'phone' => '0111', 'email' => 'nobody@example.com'])->assertOk();
        $this->postJson("/api/v1/gameyas/{$g['id']}/members/{$s3}/invite")->assertOk();
        $this->postJson("/api/v1/gameyas/{$g['id']}/members/{$s4}/invite")->assertOk();
        // Both just say "invited": the manager can't tell who has an account.
        $this->assertSame('invited', $this->slot($g['id'], 3)['link']);
        $this->assertSame('invited', $this->slot($g['id'], 4)['link']);
        Notification::assertSentTo($this->member, InfoAlert::class, fn ($n) => $n->kind === 'gameya_invite');
        $this->postJson("/api/v1/gameyas/{$g['id']}/members/{$s4}/pay", ['round' => 1])->assertOk();

        Sanctum::actingAs($this->member);
        $this->getJson('/api/v1/shared-gameyas')->assertJsonCount(1, 'invites')->assertJsonCount(0, 'data')
            ->assertJsonPath('invites.0.manager', 'Umm Ahmed');
        $this->postJson("/api/v1/shared-gameyas/{$s3}/paid", ['round' => 1])->assertNotFound(); // not accepted yet
        $res = $this->postJson("/api/v1/shared-gameyas/{$s3}/accept")->assertOk()
            ->assertJsonPath('data.0.gameya', 'Office')->assertJsonPath('data.0.turns.0.turn', 3)
            ->assertJsonPath('data.0.turns.0.payout_date', '2026-12-01')->assertJsonPath('data.0.next.round', 1);
        $this->assertStringNotContainsString('Secret Other', $res->getContent());
        $this->assertStringNotContainsString('0111', $res->getContent());
        $this->assertStringNotContainsString('slots', $res->getContent());
        // Members can't open the manager's gameya or someone else's turn.
        $this->getJson("/api/v1/gameyas/{$g['id']}")->assertForbidden();
        $this->postJson("/api/v1/shared-gameyas/{$s4}/paid", ['round' => 1])->assertNotFound();

        // "I paid" → unconfirmed until the manager confirms; the manager is told.
        $this->postJson("/api/v1/shared-gameyas/{$s3}/paid", ['round' => 1])->assertOk()->assertJsonPath('data.0.paid.0.confirmed', false);
        $this->postJson("/api/v1/shared-gameyas/{$s3}/paid", ['round' => 1])->assertJsonValidationErrors('round');
        Notification::assertSentTo($this->manager, InfoAlert::class, fn ($n) => $n->kind === 'gameya_claim');

        Sanctum::actingAs($this->manager);
        $this->assertSame('linked', $this->slot($g['id'], 3)['link']);
        $this->getJson("/api/v1/gameyas/{$g['id']}")->assertJsonPath('claims', 1);
        $this->postJson("/api/v1/gameyas/{$g['id']}/members/{$s3}/pay", ['round' => 1])->assertJsonPath('claims', 0)->assertJsonPath('paid_this_round', 2);

        Sanctum::actingAs($this->member);
        $this->getJson('/api/v1/shared-gameyas')->assertJsonPath('data.0.paid.0.confirmed', true)->assertJsonPath('data.0.next.round', 2);
    }

    public function test_reminders_and_alerts_for_linked_members(): void
    {
        Notification::fake();
        $g = $this->gameya();
        $s2 = $this->slot($g['id'], 2)['id'];
        $this->patchJson("/api/v1/gameyas/{$g['id']}/members/{$s2}", ['email' => 'sara@example.com']);
        $this->postJson("/api/v1/gameyas/{$g['id']}/members/{$s2}/invite");
        $this->postJson("/api/v1/gameyas/{$g['id']}/remind")->assertJsonPath('sent', 0); // not accepted yet
        Sanctum::actingAs($this->member);
        $this->postJson("/api/v1/shared-gameyas/{$s2}/accept");
        Sanctum::actingAs($this->manager);
        $this->postJson("/api/v1/gameyas/{$g['id']}/remind")->assertJsonPath('sent', 1);
        Notification::assertSentTo($this->member, InfoAlert::class, fn ($n) => $n->kind === 'gameya_reminder' && $n->params['round'] === 1);

        // The member's own alerts: round 1 overdue, payout on Nov 1.
        $kinds = app(AlertScanner::class)->items($this->member, CarbonImmutable::today())->pluck('kind')->all();
        $this->assertEqualsCanonicalizing(['gameya_shared', 'gameya_shared_payout'], $kinds);
        // A manager without a turn gets no "pay your round" alerts for it.
        $this->assertNotContains('gameya', app(AlertScanner::class)->items($this->manager, CarbonImmutable::today())->pluck('kind')->all());
    }

    public function test_strangers_and_heirs(): void
    {
        $g = $this->gameya();
        $s1 = $this->slot($g['id'], 1)['id'];
        Sanctum::actingAs(User::factory()->create());
        $this->patchJson("/api/v1/gameyas/{$g['id']}/members/{$s1}", ['name' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/shared-gameyas/{$s1}/accept")->assertNotFound();
        Sanctum::actingAs(User::factory()->heirOf($this->manager)->create());
        $this->getJson("/api/v1/gameyas/{$g['id']}")->assertOk()->assertJsonCount(5, 'slots');
        $this->postJson("/api/v1/gameyas/{$g['id']}/members/{$s1}/pay", ['round' => 1])->assertForbidden();
    }
}
