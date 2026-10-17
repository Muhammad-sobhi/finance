<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_logs_in_and_gets_a_token(): void
    {
        $user = User::factory()->create(['email' => 'me@example.com', 'password' => 'secret-pass']);

        $res = $this->postJson('/api/v1/auth/login', ['email' => 'ME@example.com', 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('user.email', 'me@example.com')
            ->assertJsonPath('user.read_only', false);

        $this->withToken($res->json('token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('id', $user->id);
        $this->assertNotNull($user->fresh()->last_activity_at);
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'me@example.com', 'password' => 'secret-pass']);

        $this->postJson('/api/v1/auth/login', ['email' => 'me@example.com', 'password' => 'nope'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'me@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'me@example.com', 'password' => 'bad'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'me@example.com', 'password' => 'bad'])->assertStatus(429);
    }

    public function test_protected_routes_require_a_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->get('/api/v1/accounts')->assertUnauthorized();
    }

    public function test_logout_revokes_the_token(): void
    {
        User::factory()->create(['email' => 'me@example.com', 'password' => 'secret-pass']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'me@example.com', 'password' => 'secret-pass'])->json('token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_preferences_are_saved_per_user(): void
    {
        Sanctum::actingAs($user = User::factory()->create());

        $this->patchJson('/api/v1/auth/me', ['theme' => 'vault', 'locale' => 'en', 'list_mode' => 'cards'])
            ->assertOk()->assertJsonPath('theme', 'vault')->assertJsonPath('locale', 'en');
        $this->patchJson('/api/v1/auth/me', ['theme' => 'purple'])->assertStatus(422);
        $this->assertSame('vault', $user->fresh()->theme);
    }

    public function test_requests_update_last_activity(): void
    {
        $user = User::factory()->create(['last_activity_at' => now()->subDays(10)]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/accounts')->assertOk();
        $this->assertTrue($user->fresh()->last_activity_at->isAfter(now()->subMinute()));
    }

    public function test_heir_cannot_log_in_until_access_is_activated(): void
    {
        $owner = User::factory()->create();
        User::factory()->heirOf($owner)->create(['email' => 'heir@example.com', 'password' => 'heir-pass']);

        $this->postJson('/api/v1/auth/login', ['email' => 'heir@example.com', 'password' => 'heir-pass'])->assertStatus(422);

        $owner->forceFill(['heir_access_activated_at' => now()])->save();
        $this->postJson('/api/v1/auth/login', ['email' => 'heir@example.com', 'password' => 'heir-pass'])
            ->assertOk()->assertJsonPath('user.read_only', true);
    }

    public function test_phone_bottom_bar_is_saved_per_user(): void
    {
        Sanctum::actingAs($u = User::factory()->create());
        $this->getJson('/api/v1/auth/me')->assertJsonPath('phone_nav', ['/', '/accounts', '/transactions', '/bills']);
        $this->patchJson('/api/v1/auth/me', ['phone_nav' => ['/transactions', '/', '/planning', '/debts']])
            ->assertOk()->assertJsonPath('phone_nav.2', '/planning');
        $this->patchJson('/api/v1/auth/me', ['phone_nav' => ['/', '/', '/planning', '/debts']])->assertJsonValidationErrors('phone_nav.1');
        $this->patchJson('/api/v1/auth/me', ['phone_nav' => ['/', '/admin', '/planning', '/debts']])->assertJsonValidationErrors('phone_nav.1');
        $this->patchJson('/api/v1/auth/me', ['phone_nav' => ['/', '/planning']])->assertJsonValidationErrors('phone_nav');
        $this->patchJson('/api/v1/auth/me', ['phone_nav' => null])->assertJsonPath('phone_nav.1', '/accounts');
    }
}
