<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AlertScanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VaultTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private array $key = ['mode' => 'pin', 'salt' => 'c2FsdHNhbHRzYWx0c2FsdA==', 'iterations' => 600000, 'iv' => 'aXZpdml2aXZpdml2', 'wrapped_key' => 'd3JhcHBlZGtleXdyYXBwZWRrZXl3cmFwcGVka2V5d3JhcA=='];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['locale' => 'en', 'email_alerts' => false]);
        Sanctum::actingAs($this->owner);
    }

    private function item(array $o = []): array
    {
        return $o + ['kind' => 'password', 'iv' => 'aXZpdml2aXZpdml2', 'ciphertext' => 'Y2lwaGVydGV4dA=='];
    }

    public function test_setup_store_and_read_back_only_ciphertext(): void
    {
        $this->getJson('/api/v1/vault')->assertJsonPath('vault', null)->assertJsonCount(0, 'items');
        $this->postJson('/api/v1/vault/items', $this->item())->assertStatus(409); // no vault yet

        $this->postJson('/api/v1/vault', ['iterations' => 1000] + $this->key)->assertJsonValidationErrors('iterations'); // too weak
        $this->postJson('/api/v1/vault', $this->key)->assertCreated();
        $this->postJson('/api/v1/vault', $this->key)->assertStatus(409);

        $id = $this->postJson('/api/v1/vault/items', $this->item(['kind' => 'insurance', 'remind_on' => '2026-11-01']))->assertCreated()->json('id');
        $this->postJson('/api/v1/vault/items', $this->item(['kind' => 'nope']))->assertJsonValidationErrors('kind');
        $this->postJson('/api/v1/vault/items', $this->item(['ciphertext' => 'my password is 1234!']))->assertJsonValidationErrors('ciphertext'); // must be base64
        $this->putJson("/api/v1/vault/items/{$id}", $this->item(['ciphertext' => 'bmV3']))->assertJsonPath('ciphertext', 'bmV3');

        $this->getJson('/api/v1/vault')->assertJsonPath('vault.wrapped_key', $this->key['wrapped_key'])->assertJsonPath('items.0.kind', 'password')
            ->assertJsonPath('items.0.remind_on', '2026-11-01');
        $this->deleteJson("/api/v1/vault/items/{$id}")->assertNoContent();
    }

    public function test_changing_the_pin_or_wiping_needs_the_account_password(): void
    {
        $this->postJson('/api/v1/vault', $this->key)->assertCreated();
        $this->postJson('/api/v1/vault/items', $this->item())->assertCreated();

        $new = ['wrapped_key' => 'bmV3a2V5bmV3a2V5bmV3a2V5bmV3a2V5bmV3a2V5bmV3a2V5'] + $this->key;
        $this->putJson('/api/v1/vault/key', $new)->assertJsonValidationErrors('current_password');
        $this->putJson('/api/v1/vault/key', $new + ['current_password' => 'password'])->assertOk();
        $this->getJson('/api/v1/vault')->assertJsonPath('vault.wrapped_key', $new['wrapped_key'])->assertJsonCount(1, 'items');

        $this->deleteJson('/api/v1/vault', ['current_password' => 'nope'])->assertJsonValidationErrors('current_password');
        $this->deleteJson('/api/v1/vault', ['current_password' => 'password'])->assertNoContent();
        $this->getJson('/api/v1/vault')->assertJsonPath('vault', null)->assertJsonCount(0, 'items');
    }

    public function test_heirs_read_strangers_cannot(): void
    {
        $this->postJson('/api/v1/vault', $this->key)->assertCreated();
        $id = $this->postJson('/api/v1/vault/items', $this->item())->json('id');

        Sanctum::actingAs(User::factory()->heirOf($this->owner)->create());
        $this->getJson('/api/v1/vault')->assertJsonCount(1, 'items');
        $this->postJson('/api/v1/vault/items', $this->item())->assertForbidden();
        $this->deleteJson("/api/v1/vault/items/{$id}")->assertForbidden();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/vault')->assertJsonPath('vault', null)->assertJsonCount(0, 'items');
        $this->putJson("/api/v1/vault/items/{$id}", $this->item())->assertForbidden();
        $this->deleteJson("/api/v1/vault/items/{$id}")->assertForbidden();
    }

    public function test_heir_copy_opens_only_after_heir_access_and_pin_is_optional(): void
    {
        $copy = base64_encode(random_bytes(32));
        $this->postJson('/api/v1/vault', ['mode' => 'pin_heir'] + $this->key)->assertJsonValidationErrors('escrow_key');
        $this->postJson('/api/v1/vault', ['mode' => 'pin_heir', 'escrow_key' => $copy] + $this->key)->assertCreated();
        $this->getJson('/api/v1/vault')->assertJsonPath('vault.mode', 'pin_heir')->assertJsonMissingPath('vault.escrow_key');
        $this->assertStringNotContainsString($copy, json_encode(\DB::table('vaults')->get())); // stored encrypted
        $this->getJson('/api/v1/vault/escrow')->assertForbidden(); // the owner uses the PIN

        $heir = User::factory()->heirOf($this->owner)->create();
        Sanctum::actingAs($heir);
        $this->getJson('/api/v1/vault/escrow')->assertForbidden(); // access not active yet
        $this->owner->forceFill(['heir_access_activated_at' => now()])->save();
        Sanctum::actingAs($heir->fresh());
        $this->getJson('/api/v1/vault/escrow')->assertOk()->assertJsonPath('key', $copy);

        // No PIN: the owner gets the copy right away. PIN only: nobody does.
        Sanctum::actingAs($this->owner);
        $this->putJson('/api/v1/vault/key', ['mode' => 'open', 'escrow_key' => $copy, 'current_password' => 'password'])->assertOk();
        $this->getJson('/api/v1/vault')->assertJsonPath('vault.mode', 'open')->assertJsonPath('vault.wrapped_key', null);
        $this->getJson('/api/v1/vault/escrow')->assertJsonPath('key', $copy);
        $this->putJson('/api/v1/vault/key', $this->key + ['escrow_key' => $copy, 'current_password' => 'password'])->assertJsonValidationErrors('escrow_key');
        $this->putJson('/api/v1/vault/key', $this->key + ['current_password' => 'password'])->assertOk();
        $this->getJson('/api/v1/vault/escrow')->assertForbidden();
        Sanctum::actingAs($heir->fresh());
        $this->getJson('/api/v1/vault/escrow')->assertForbidden();
    }

    public function test_renewal_reminder_is_generic(): void
    {
        $this->postJson('/api/v1/vault', $this->key)->assertCreated();
        $this->postJson('/api/v1/vault/items', $this->item(['kind' => 'insurance', 'remind_on' => '2026-11-01']))->assertCreated();

        $this->assertSame(1, app(AlertScanner::class)->run(CarbonImmutable::parse('2026-10-30')));
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.0.data.kind', 'vault')
            ->assertJsonPath('data.0.data.body', 'A vault item needs renewal, in 2 days');
    }
}
