<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\InfoAlert;
use App\Notifications\InviteMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_invites_and_the_client_activates_their_account(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['name' => 'Muhammad']);
        $this->artisan('finance:admin', ['email' => $admin->email])->assertSuccessful();
        $this->assertTrue($admin->fresh()->is_admin);
        Sanctum::actingAs($admin->fresh());
        $this->getJson('/api/v1/auth/me')->assertJsonPath('is_admin', true);

        $id = $this->postJson('/api/v1/admin/invites', ['name' => 'Client One', 'email' => 'Client@Example.com'])->assertCreated()->json('id');
        $this->postJson('/api/v1/admin/invites', ['name' => 'x', 'email' => 'client@example.com'])->assertJsonValidationErrors('email');
        $client = User::find($id);
        $this->assertTrue($client->invitePending());
        $this->assertSame('client@example.com', $client->email);
        $this->assertGreaterThan(10, $client->categories()->count()); // default categories seeded

        $token = null;
        Notification::assertSentTo($client, InviteMail::class, function (InviteMail $n) use ($client, &$token) {
            $token = $n->token;
            $mail = $n->toMail($client);

            return $mail->subject === 'دعوة لاستخدام دينار' && str_contains($mail->actionUrl, 'invite=1') && str_contains(implode(' ', $mail->introLines), 'Muhammad');
        });
        $this->getJson('/api/v1/admin/users')->assertJsonPath('data.0.email', 'client@example.com')->assertJsonPath('data.0.status', 'pending');

        // Can't log in before activating; the invite link still works after the normal 60-minute reset window.
        $this->postJson('/api/v1/auth/login', ['email' => 'client@example.com', 'password' => 'anything-123'])->assertStatus(422);
        $this->travel(3)->days();
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'client@example.com', 'invite' => true, 'password' => 'client-pass-123', 'password_confirmation' => 'client-pass-123'])->assertOk();
        $this->assertFalse($client->fresh()->invitePending());
        Notification::assertNotSentTo($client, InfoAlert::class); // no "your password was changed" scare on first activation
        $this->postJson('/api/v1/auth/login', ['email' => 'client@example.com', 'password' => 'client-pass-123'])->assertOk();

        Sanctum::actingAs($admin->fresh());
        $this->getJson('/api/v1/admin/users')->assertJsonPath('data.0.status', 'active');
        $this->postJson("/api/v1/admin/invites/{$id}/resend")->assertStatus(409);
        $this->deleteJson("/api/v1/admin/invites/{$id}")->assertStatus(409); // active accounts are never deleted here
    }

    public function test_invite_links_expire_and_only_work_for_pending_invites(): void
    {
        Notification::fake();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs($admin);
        $id = $this->postJson('/api/v1/admin/invites', ['name' => 'Late', 'email' => 'late@example.com', 'locale' => 'en'])->json('id');
        $token = null;
        Notification::assertSentTo(User::find($id), InviteMail::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->travel(8)->days();
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'late@example.com', 'invite' => true, 'password' => 'late-pass-123', 'password_confirmation' => 'late-pass-123'])
            ->assertJsonValidationErrors('token');

        // Resend gives a fresh link; cancel removes a pending account.
        $this->postJson("/api/v1/admin/invites/{$id}/resend")->assertOk();
        Notification::assertSentToTimes(User::find($id), InviteMail::class, 2);
        $this->deleteJson("/api/v1/admin/invites/{$id}")->assertNoContent();
        $this->assertNull(User::find($id));

        // A normal reset token can't borrow the week-long invite window.
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $resetToken = Password::broker()->createToken($owner);
        $this->travel(2)->hours();
        $this->postJson('/api/v1/auth/reset-password', ['token' => $resetToken, 'email' => 'owner@example.com', 'invite' => true, 'password' => 'owner-pass-123', 'password_confirmation' => 'owner-pass-123'])
            ->assertJsonValidationErrors('token');
    }

    public function test_only_admins_can_invite(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->postJson('/api/v1/admin/invites', ['name' => 'x', 'email' => 'x@example.com'])->assertForbidden();
        $this->getJson('/api/v1/auth/me')->assertJsonPath('is_admin', false);

        // An heir of an admin isn't an admin.
        $owner->forceFill(['is_admin' => true])->save();
        Sanctum::actingAs(User::factory()->heirOf($owner)->create(['is_admin' => false]));
        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }
}
