<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $email = 'me@example.com', string $password = 'secret-pass-1', string $device = 'web'): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password, 'device_name' => $device])->assertOk()->json('token');
    }

    private function fresh(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_tokens_expire_after_30_days(): void
    {
        User::factory()->create(['email' => 'me@example.com', 'password' => 'secret-pass-1']);
        $token = $this->login();
        $this->travel(29)->days();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->fresh();
        $this->travel(2)->days();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_cors_only_allows_our_frontend(): void
    {
        config(['cors.allowed_origins' => ['https://finance.example.com']]);
        $pre = fn (string $origin) => $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => $origin, 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);
        $this->assertSame('https://finance.example.com', $pre('https://finance.example.com')->headers->get('Access-Control-Allow-Origin'));
        // Another site never gets its own origin back (the browser then blocks it), and never a wildcard.
        $evil = $pre('https://evil.example.com')->headers->get('Access-Control-Allow-Origin');
        $this->assertNotSame('https://evil.example.com', $evil);
        $this->assertNotSame('*', $evil);
    }

    public function test_unknown_email_and_wrong_password_look_the_same(): void
    {
        User::factory()->create(['email' => 'me@example.com', 'password' => 'secret-pass-1']);
        $a = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'x'])->assertStatus(422)->json();
        $b = $this->postJson('/api/v1/auth/login', ['email' => 'me@example.com', 'password' => 'x'])->assertStatus(422)->json();
        $this->assertSame($a, $b);
    }

    public function test_profile_update_and_email_change_needs_current_password(): void
    {
        $me = User::factory()->create(['email' => 'me@example.com', 'password' => 'secret-pass-1']);
        User::factory()->create(['email' => 'taken@example.com']);
        Sanctum::actingAs($me);

        $this->patchJson('/api/v1/auth/profile', ['name' => 'Muhammad Sobhi'])->assertOk()->assertJsonPath('name', 'Muhammad Sobhi');
        $this->patchJson('/api/v1/auth/profile', ['email' => 'new@example.com'])->assertJsonValidationErrors('current_password');
        $this->patchJson('/api/v1/auth/profile', ['email' => 'new@example.com', 'current_password' => 'wrong'])->assertJsonValidationErrors('current_password');
        $this->patchJson('/api/v1/auth/profile', ['email' => 'taken@example.com', 'current_password' => 'secret-pass-1'])->assertJsonValidationErrors('email');
        $this->patchJson('/api/v1/auth/profile', ['email' => 'NEW@example.com', 'current_password' => 'secret-pass-1'])->assertOk()->assertJsonPath('email', 'new@example.com');
    }

    public function test_password_change_signs_out_other_devices(): void
    {
        User::factory()->create(['email' => 'me@example.com', 'password' => 'secret-pass-1']);
        $phone = $this->login(device: 'phone');
        $laptop = $this->login(device: 'laptop');

        $this->withToken($laptop)->putJson('/api/v1/auth/password', ['current_password' => 'wrong', 'password' => 'new-secret-22', 'password_confirmation' => 'new-secret-22'])
            ->assertJsonValidationErrors('current_password');
        $this->fresh();
        $this->withToken($laptop)->putJson('/api/v1/auth/password', ['current_password' => 'secret-pass-1', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertJsonValidationErrors('password');
        $this->fresh();
        $this->withToken($laptop)->putJson('/api/v1/auth/password', ['current_password' => 'secret-pass-1', 'password' => 'new-secret-22', 'password_confirmation' => 'new-secret-22'])->assertOk();

        $this->fresh();
        $this->withToken($phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->fresh();
        $this->withToken($laptop)->getJson('/api/v1/auth/me')->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'me@example.com', 'password' => 'secret-pass-1'])->assertStatus(422);
        $this->login(password: 'new-secret-22');
    }

    public function test_logout_other_devices(): void
    {
        User::factory()->create(['email' => 'me@example.com', 'password' => 'secret-pass-1']);
        $a = $this->login(device: 'a');
        $b = $this->login(device: 'b');
        $c = $this->login(device: 'c');
        $this->withToken($a)->postJson('/api/v1/auth/logout-others')->assertJsonPath('revoked', 2);
        foreach ([$b, $c] as $t) {
            $this->fresh();
            $this->withToken($t)->getJson('/api/v1/auth/me')->assertUnauthorized();
        }
        $this->fresh();
        $this->withToken($a)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_heir_cannot_escalate_but_can_change_own_password(): void
    {
        $owner = User::factory()->create(['heir_access_activated_at' => now()]);
        $heir = User::factory()->heirOf($owner)->create(['email' => 'heir@example.com', 'password' => 'heir-pass-123']);
        $token = $this->login('heir@example.com', 'heir-pass-123');

        // Mass-assignment attempts through the endpoints an heir may write to.
        $this->withToken($token)->patchJson('/api/v1/auth/me', ['role' => 'owner', 'owner_id' => null, 'theme' => 'vault'])->assertOk();
        $this->assertSame('heir', $heir->fresh()->role->value);
        $this->assertSame($owner->id, $heir->fresh()->owner_id);
        $this->fresh();
        $this->withToken($token)->patchJson('/api/v1/auth/profile', ['name' => 'x'])->assertForbidden();
        $this->fresh();
        $this->withToken($token)->putJson('/api/v1/auth/password', ['current_password' => 'heir-pass-123', 'password' => 'heir-pass-456', 'password_confirmation' => 'heir-pass-456'])->assertOk();
    }

    /** No owner can read or change another owner's records on any module. */
    public function test_cross_owner_access_is_refused_everywhere(): void
    {
        $victim = User::factory()->create();
        $account = Account::factory()->for($victim)->create();
        $records = [
            'accounts' => $account->id,
            'categories' => $victim->categories()->value('id'),
            'debts' => $victim->debts()->create(['person' => 'x', 'direction' => 'i_owe', 'amount' => 1, 'currency' => 'EGP', 'date' => '2026-01-01'])->id,
            'bills' => $victim->bills()->create(['name' => 'x', 'amount' => 1, 'currency' => 'EGP', 'due_date' => '2026-01-01', 'cycle' => 'monthly'])->id,
            'subscriptions' => $victim->subscriptions()->create(['name' => 'x', 'amount' => 1, 'currency' => 'EGP', 'renew_date' => '2026-01-01', 'cycle' => 'monthly', 'scope' => 'work'])->id,
            'income-sources' => $victim->incomeSources()->create(['name' => 'x', 'amount' => 1, 'currency' => 'EGP', 'next_date' => '2026-01-01', 'cycle' => 'monthly'])->id,
            'projects' => $victim->projects()->create(['client' => 'x', 'name' => 'y', 'amount' => 1, 'currency' => 'EGP'])->id,
            'investments' => $victim->investments()->create(['name' => 'x', 'type' => 'fund', 'invested_amount' => 1, 'current_value' => 1, 'currency' => 'EGP'])->id,
            'assets' => $victim->assets()->create(['name' => 'x', 'type' => 'car', 'currency' => 'EGP'])->id,
            'heirs' => $victim->heirs()->create(['name' => 'x', 'email' => 'h@example.com', 'national_id' => '123'])->id,
            'trusted-contacts' => $victim->trustedContacts()->create(['name' => 'x', 'email' => 't@example.com'])->id,
            'exchange-rates' => $victim->exchangeRates()->create(['currency' => 'USD', 'rate' => 50])->id,
        ];

        Sanctum::actingAs(User::factory()->create());
        foreach ($records as $resource => $id) {
            $readable = ! in_array($resource, ['heirs', 'trusted-contacts', 'exchange-rates'], true);
            if ($readable) {
                $this->assertContains($this->getJson("/api/v1/{$resource}/{$id}")->status(), [403, 404], "GET {$resource}");
            }
            $this->assertContains($this->patchJson("/api/v1/{$resource}/{$id}", ['name' => 'pwned'])->status(), [403, 404], "PATCH {$resource}");
            $this->assertContains($this->deleteJson("/api/v1/{$resource}/{$id}")->status(), [403, 404], "DELETE {$resource}");
        }
        // Their data never shows up in our lists or our heir page.
        $this->getJson('/api/v1/accounts')->assertJsonCount(0);
        $this->getJson('/api/v1/heir')->assertJsonCount(0, 'heirs');
        // And can't be used as a reference in our writes.
        $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $account->id, 'amount' => 1])->assertJsonValidationErrors('account_id');
        $this->assertDatabaseHas('accounts', ['id' => $account->id, 'name' => $account->name]);
    }
}
