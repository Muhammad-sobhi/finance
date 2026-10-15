<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Heir;
use App\Models\HeirRequest;
use App\Models\User;
use App\Notifications\HeirMail;
use App\Services\HeirService;
use App\Services\SignedLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HeirTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo('2026-10-15 09:00');
        $this->owner = User::factory()->create([
            'name' => 'Muhammad', 'heir_enabled' => true, 'heir_inactivity_days' => 60, 'heir_grace_days' => 14,
            'last_activity_at' => now(), 'locale' => 'en',
        ]);
        $this->owner->heirs()->create(['name' => 'Omar', 'relation' => 'son', 'email' => 'omar@example.com', 'national_id' => '29901011234567', 'address' => 'Cairo']);
        $this->owner->trustedContacts()->create(['name' => 'Hassan', 'email' => 'hassan@example.com']);
        Account::factory()->for($this->owner)->create(['balance' => 1000]);
    }

    private function run_(): array
    {
        return app(HeirService::class)->run();
    }

    /** The /link?to=... URL from an email → the signed API path. */
    private function linkPath(string $url): string
    {
        parse_str(parse_url($url, PHP_URL_QUERY), $q);

        return $q['to'];
    }

    private function sentTo(string $email, string $kind): ?HeirMail
    {
        $found = null;
        Notification::assertSentOnDemand(HeirMail::class, function (HeirMail $n, array $channels, AnonymousNotifiable $to) use ($email, $kind, &$found) {
            if ($n->kind === $kind && $to->routes['mail'] === $email) {
                $found = $n;
            }

            return true;
        });

        return $found;
    }

    public function test_crud_and_sensitive_fields_are_encrypted(): void
    {
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/heir')->assertOk()
            ->assertJsonPath('settings.state', 'armed')->assertJsonPath('heirs.0.national_id', '29901011234567')
            ->assertJsonCount(1, 'trusted');
        $raw = DB::table('heirs')->first();
        $this->assertStringNotContainsString('2990101', $raw->national_id);
        $this->assertStringNotContainsString('Cairo', $raw->address);

        $id = $this->postJson('/api/v1/heirs', ['name' => 'Mona', 'email' => 'MONA@example.com', 'relation' => 'daughter'])->assertCreated()->assertJsonPath('email', 'mona@example.com')->json('id');
        $this->patchJson("/api/v1/heirs/{$id}", ['phone' => '0100'])->assertJsonPath('phone', '0100');
        $this->postJson('/api/v1/heirs', ['name' => 'x', 'email' => 'not-an-email'])->assertJsonValidationErrors('email');
        $this->deleteJson("/api/v1/heirs/{$id}")->assertNoContent();
        $cid = $this->postJson('/api/v1/trusted-contacts', ['name' => 'Aya', 'email' => 'aya@example.com'])->assertCreated()->json('id');
        $this->deleteJson("/api/v1/trusted-contacts/{$cid}")->assertNoContent();
        $this->patchJson('/api/v1/heir/settings', ['heir_inactivity_days' => 3])->assertJsonValidationErrors('heir_inactivity_days');
        $this->patchJson('/api/v1/heir/settings', ['heir_inactivity_days' => 30, 'heir_grace_days' => 7])->assertJsonPath('heir_inactivity_days', 30);
    }

    public function test_dead_man_switch_full_timeline(): void
    {
        $this->travel(59)->days();
        $this->assertSame(['warned' => 0, 'activated' => 0], $this->run_());

        $this->travel(1)->days(); // day 60: warning, grace window starts
        $this->assertSame(1, $this->run_()['warned']);
        $this->assertSame(0, $this->run_()['warned']); // same day: no spam
        $this->travel(1)->days();
        $this->assertSame(1, $this->run_()['warned']); // daily reminder
        $this->assertSame('warning', $this->owner->fresh()->heir_warning_started_at ? 'warning' : 'none');
        Notification::assertSentTo($this->owner, HeirMail::class, fn ($n) => $n->kind === 'warning' && str_contains($n->url, '/link?to='));

        $this->travel(13)->days(); // grace (14 days) over without check-in
        $this->assertSame(1, $this->run_()['activated']);
        $this->assertTrue($this->owner->fresh()->heirAccessActive());
        Notification::assertSentTo($this->owner, HeirMail::class, fn ($n) => $n->kind === 'activated');

        // Heir sets a password from the emailed link, then logs in read-only.
        $mail = $this->sentTo('omar@example.com', 'granted');
        $this->assertNotNull($mail);
        $path = $this->linkPath($mail->url);
        $this->getJson($path)->assertOk()->assertJsonPath('action', 'activate')->assertJsonPath('owner', 'Muhammad');
        $this->postJson($path, ['password' => 'short', 'password_confirmation' => 'short'])->assertJsonValidationErrors('password');
        $this->postJson($path, ['password' => 'heir-secret-1', 'password_confirmation' => 'heir-secret-1'])->assertOk()->assertJsonPath('email', 'omar@example.com');

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'omar@example.com', 'password' => 'heir-secret-1'])
            ->assertOk()->assertJsonPath('user.read_only', true)->json('token');
        $this->withToken($token)->getJson('/api/v1/accounts')->assertOk()->assertJsonCount(1);
        $this->withToken($token)->postJson('/api/v1/accounts', ['name' => 'x', 'type' => 'cash', 'currency' => 'EGP'])->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/heir')->assertOk()->assertJsonPath('settings.state', 'triggered');
        $this->withToken($token)->postJson('/api/v1/heir/check-in')->assertForbidden();

        // Owner comes back and revokes.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->owner->fresh());
        $this->postJson('/api/v1/heir/revoke')->assertJsonPath('state', 'armed');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/accounts')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => 'omar@example.com', 'password' => 'heir-secret-1'])->assertStatus(422);
    }

    public function test_any_activity_or_check_in_resets_the_switch(): void
    {
        $this->travel(61)->days();
        $this->run_();
        $this->assertNotNull($this->owner->fresh()->heir_warning_started_at);

        // Activity through the API ends the grace window.
        Sanctum::actingAs($this->owner->fresh());
        $this->getJson('/api/v1/dashboard')->assertOk();
        $this->assertNull($this->owner->fresh()->heir_warning_started_at);

        // The emailed check-in link works without logging in.
        $this->travel(61)->days();
        $this->run_();
        $mail = null;
        Notification::assertSentTo($this->owner, HeirMail::class, function ($n) use (&$mail) {
            if ($n->kind === 'warning') {
                $mail = $n;
            }

            return true;
        });
        $this->app['auth']->forgetGuards();
        $path = $this->linkPath($mail->url);
        $this->getJson($path)->assertJsonPath('action', 'check_in');
        $this->postJson($path)->assertJsonPath('done', true);
        $this->assertNull($this->owner->fresh()->heir_warning_started_at);
        $this->travel(20)->days();
        $this->assertSame(['warned' => 0, 'activated' => 0], $this->run_());
    }

    public function test_signed_links_reject_tampering_and_expiry(): void
    {
        $this->travel(60)->days();
        $this->run_();
        $mail = null;
        Notification::assertSentTo($this->owner, HeirMail::class, function ($n) use (&$mail) {
            $mail = $n;

            return true;
        });
        $path = $this->linkPath($mail->url);
        $other = User::factory()->create();
        $this->postJson(str_replace("check-in/{$this->owner->id}", "check-in/{$other->id}", $path))->assertForbidden();
        $this->postJson(preg_replace('/signature=[^&]+/', 'signature=abc', $path))->assertForbidden();
        $this->travel(15)->days(); // link lifetime = grace days
        $this->postJson($path)->assertForbidden();
    }

    public function test_manual_request_confirm_wait_activate(): void
    {
        $this->postJson('/api/v1/heir-requests', ['email' => 'OMAR@example.com'])->assertOk()->assertJsonPath('ok', true);
        $req = HeirRequest::first();
        $this->assertSame('pending', $req->status);

        $mail = $this->sentTo('hassan@example.com', 'confirm');
        $this->assertNotNull($mail);
        $path = $this->linkPath($mail->url);
        $this->getJson($path)->assertJsonPath('heir', 'Omar')->assertJsonPath('owner', 'Muhammad');
        $this->postJson($path)->assertOk();
        $this->assertSame('confirmed', $req->fresh()->status);
        $this->postJson($path)->assertStatus(409); // only once

        // Owner is told (email + in-app) and can cancel during the window…
        Notification::assertSentTo($this->owner, HeirMail::class, fn ($n, $ch) => $n->kind === 'confirmed' && in_array('database', $ch));
        $this->travel(13)->days();
        $this->assertSame(0, $this->run_()['activated']);
        // …but didn't, so access opens when the window ends.
        $this->travel(1)->days();
        $this->assertSame(1, $this->run_()['activated']);
        $this->assertSame('activated', $req->fresh()->status);
        $this->assertNotNull($this->sentTo('omar@example.com', 'granted'));
    }

    public function test_owner_cancels_request(): void
    {
        $this->postJson('/api/v1/heir-requests', ['email' => 'omar@example.com']);
        $this->postJson($this->linkPath($this->sentTo('hassan@example.com', 'confirm')->url));

        $cancel = null;
        Notification::assertSentTo($this->owner, HeirMail::class, function ($n) use (&$cancel) {
            if ($n->kind === 'confirmed') {
                $cancel = $n;
            }

            return true;
        });
        $this->postJson($this->linkPath($cancel->url))->assertOk();
        $this->assertSame('cancelled', HeirRequest::first()->status);
        $this->travel(30)->days();
        $this->owner->forceFill(['last_activity_at' => now()])->save();
        $this->assertSame(0, $this->run_()['activated']);

        // And from the app while logged in.
        $this->postJson('/api/v1/heir-requests', ['email' => 'omar@example.com']);
        Sanctum::actingAs($this->owner->fresh());
        $id = HeirRequest::where('status', 'pending')->value('id');
        $this->postJson("/api/v1/heir-requests/{$id}/cancel")->assertOk();
        $this->postJson("/api/v1/heir-requests/{$id}/cancel")->assertStatus(409);
    }

    public function test_requests_do_not_reveal_unknown_emails_and_are_rate_limited(): void
    {
        $this->postJson('/api/v1/heir-requests', ['email' => 'stranger@example.com'])->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(0, HeirRequest::count());
        Notification::assertNothingSentTo(new AnonymousNotifiable);

        $this->owner->update(['heir_enabled' => false]);
        $this->postJson('/api/v1/heir-requests', ['email' => 'omar@example.com'])->assertOk();
        $this->assertSame(0, HeirRequest::count());

        $this->postJson('/api/v1/heir-requests', ['email' => 'x@example.com'])->assertOk();
        $this->postJson('/api/v1/heir-requests', ['email' => 'y@example.com'])->assertOk();
        $this->postJson('/api/v1/heir-requests', ['email' => 'z@example.com'])->assertOk();
        $this->postJson('/api/v1/heir-requests', ['email' => 'w@example.com'])->assertStatus(429); // 5 per hour per IP
    }

    public function test_disabled_switch_never_fires(): void
    {
        $this->owner->update(['heir_enabled' => false]);
        $this->travel(400)->days();
        $this->assertSame(['warned' => 0, 'activated' => 0], $this->run_());
    }

    public function test_heir_set_password_requires_active_access(): void
    {
        $heir = Heir::first();
        $url = SignedLinks::make('links.activate', ['heir' => $heir->id]);
        $this->postJson($this->linkPath($url), ['password' => 'heir-secret-1', 'password_confirmation' => 'heir-secret-1'])->assertForbidden();
    }

    public function test_command_runs(): void
    {
        $this->artisan('finance:heir-check')->assertSuccessful();
    }
}
