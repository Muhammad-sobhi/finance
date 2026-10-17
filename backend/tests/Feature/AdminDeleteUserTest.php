<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminDeleteUserTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['password' => 'admin-password-1']);
        $this->admin->forceFill(['is_admin' => true])->save();
    }

    public function test_admin_deletes_a_user_and_everything_they_have(): void
    {
        Storage::fake('local');
        $client = User::factory()->create(['email' => 'client@example.com']);
        $heir = User::factory()->heirOf($client)->create();
        $other = User::factory()->create();
        $otherAccount = Account::factory()->for($other)->create(['name' => 'Stays']);

        // The client builds up data, a receipt, a notification and a token.
        Sanctum::actingAs($client);
        $acc = $this->postJson('/api/v1/accounts', ['name' => 'Bank', 'type' => 'bank', 'currency' => 'EGP', 'balance' => 1000])->json('id');
        $tx = $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $acc, 'amount' => 50, 'date' => '2026-10-01'])->json('id');
        $this->post("/api/v1/transactions/{$tx}/receipt", ['file' => UploadedFile::fake()->image('r.jpg')], ['Accept' => 'application/json'])->assertOk();
        $debt = $this->postJson('/api/v1/debts', ['person' => 'Ali', 'direction' => 'owed_to_me', 'amount' => 300, 'currency' => 'EGP', 'date' => '2026-10-01'])->json('id');
        $this->postJson("/api/v1/debts/{$debt}/payments", ['amount' => 100, 'date' => '2026-10-02'])->assertCreated();
        $client->createToken('phone');
        $heir->createToken('heir');
        $this->assertNotEmpty(Storage::disk('local')->allFiles("receipts/{$client->id}"));

        Sanctum::actingAs($this->admin);
        $url = "/api/v1/admin/users/{$client->id}";
        $this->deleteJson($url, ['current_password' => 'wrong', 'confirm_email' => 'client@example.com'])->assertJsonValidationErrors('current_password');
        $this->deleteJson($url, ['current_password' => 'admin-password-1', 'confirm_email' => 'someone@else.com'])->assertJsonValidationErrors('confirm_email');
        $this->assertNotNull($client->fresh());

        $this->deleteJson($url, ['current_password' => 'admin-password-1', 'confirm_email' => 'CLIENT@example.com '])->assertNoContent();

        $this->assertNull(User::find($client->id));
        $this->assertNull(User::find($heir->id));
        foreach (['accounts', 'transactions', 'debts', 'debt_payments', 'categories'] as $table) {
            $this->assertSame(0, DB::table($table)->where('user_id', $client->id)->count(), $table);
        }
        $this->assertSame(0, DB::table('personal_access_tokens')->whereIn('tokenable_id', [$client->id, $heir->id])->count());
        $this->assertSame([], Storage::disk('local')->allFiles("receipts/{$client->id}"));
        $this->assertDatabaseHas('admin_audit_logs', ['admin_id' => $this->admin->id, 'action' => 'delete_user', 'target_id' => $client->id, 'target_email' => 'client@example.com']);

        // Other users are untouched.
        $this->assertSame('Stays', $otherAccount->fresh()->name);
        $this->assertNotNull($other->fresh());
    }

    public function test_only_admins_and_never_admin_accounts(): void
    {
        $client = User::factory()->create();
        $body = ['current_password' => 'password', 'confirm_email' => $client->email];

        Sanctum::actingAs(User::factory()->create());
        $this->deleteJson("/api/v1/admin/users/{$client->id}", $body)->assertForbidden();

        Sanctum::actingAs($this->admin);
        $this->deleteJson("/api/v1/admin/users/{$this->admin->id}", ['current_password' => 'admin-password-1', 'confirm_email' => $this->admin->email])->assertStatus(409);
        $heir = User::factory()->heirOf($client)->create();
        $this->deleteJson("/api/v1/admin/users/{$heir->id}", ['current_password' => 'admin-password-1', 'confirm_email' => $heir->email])->assertNotFound();
        $this->assertNotNull($client->fresh());
    }
}
