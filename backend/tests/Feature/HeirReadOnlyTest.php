<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HeirReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_heir_reads_owner_data_but_every_write_is_refused(): void
    {
        $owner = User::factory()->create(['heir_access_activated_at' => now()]);
        $account = Account::factory()->for($owner)->create(['balance' => 500]);
        $tx = app(TransactionService::class)->create($owner, ['type' => 'expense', 'account_id' => $account->id, 'amount' => 10, 'date' => now()->toDateString()]);
        $category = $owner->categories()->first();

        Sanctum::actingAs($heir = User::factory()->heirOf($owner)->create());

        $this->getJson('/api/v1/accounts')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $account->id);
        $this->getJson("/api/v1/transactions/{$tx->id}")->assertOk();
        $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('net_worth', 490);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('read_only', true);
        foreach (['debts', 'bills', 'subscriptions', 'investments', 'assets', 'settings'] as $path) {
            $this->getJson("/api/v1/{$path}")->assertOk();
        }

        $writes = [
            ['post', '/api/v1/accounts', ['name' => 'x', 'type' => 'cash', 'currency' => 'EGP']],
            ['patch', "/api/v1/accounts/{$account->id}", ['name' => 'x']],
            ['delete', "/api/v1/accounts/{$account->id}", []],
            ['post', '/api/v1/categories', ['name' => 'x', 'type' => 'expense']],
            ['patch', "/api/v1/categories/{$category->id}", ['name' => 'x']],
            ['delete', "/api/v1/categories/{$category->id}", []],
            ['post', '/api/v1/transactions', ['type' => 'expense', 'account_id' => $account->id, 'amount' => 1]],
            ['put', "/api/v1/transactions/{$tx->id}", ['amount' => 1]],
            ['delete', "/api/v1/transactions/{$tx->id}", []],
            ['post', '/api/v1/debts', ['person' => 'x', 'direction' => 'i_owe', 'amount' => 1, 'currency' => 'EGP']],
            ['post', '/api/v1/bills', ['name' => 'x', 'amount' => 1, 'currency' => 'EGP', 'due_date' => '2026-01-01', 'cycle' => 'once']],
            ['post', '/api/v1/subscriptions', []],
            ['post', '/api/v1/investments', []],
            ['post', '/api/v1/assets', []],
            ['patch', '/api/v1/settings', ['alert_days' => 1]],
            ['post', '/api/v1/exchange-rates', ['currency' => 'USD', 'rate' => 1]],
        ];
        foreach ($writes as [$method, $url, $body]) {
            $this->json($method, $url, $body)->assertForbidden()->assertJsonPath('code', 'read_only');
        }

        $this->assertSame(490.0, (float) $account->fresh()->balance);
        $this->assertNull($heir->fresh()->last_activity_at);
        // Heir browsing must not reset the owner's dead-man timer.
        $this->assertNull($owner->fresh()->last_activity_at);
    }

    public function test_policies_also_refuse_heir_writes_without_middleware(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->for($owner)->create();
        $heir = User::factory()->heirOf($owner)->create();

        $this->assertTrue($heir->can('view', $account));
        $this->assertFalse($heir->can('update', $account));
        $this->assertFalse($heir->can('delete', $account));
        $this->assertFalse($heir->can('create', Account::class));
    }
}
