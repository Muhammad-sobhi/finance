<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Bill;
use App\Models\User;
use App\Notifications\DueAlert;
use App\Services\AlertScanner;
use App\Services\DataVault;
use App\Services\DueItem;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

/** Financial data is unreadable in the database and each owner's key opens only their rows. */
class EncryptionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['locale' => 'en', 'email_alerts' => false]);
        Sanctum::actingAs($this->owner);
    }

    /** Every non-null value in the row, as stored. */
    private function raw(string $table, int $id): string
    {
        return json_encode(DB::table($table)->find($id));
    }

    public function test_every_user_gets_a_wrapped_data_key(): void
    {
        $wrapped = DB::table('users')->where('id', $this->owner->id)->value('data_key');
        $this->assertNotNull($wrapped);
        $this->assertTrue(app(DataVault::class)->canUnwrap($wrapped));
        $this->assertArrayNotHasKey('data_key', $this->owner->toArray());
    }

    public function test_amounts_names_and_notes_are_not_readable_in_the_database(): void
    {
        $bank = $this->postJson('/api/v1/accounts', ['name' => 'Payroll CIB', 'type' => 'bank', 'currency' => 'EGP', 'balance' => 98765.43])->assertCreated()->json('id');
        $tx = $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $bank, 'amount' => 4321.5, 'date' => '2026-10-01', 'note' => 'Secret dinner'])->assertCreated()->json('id');
        $debt = $this->postJson('/api/v1/debts', ['person' => 'Khaled Mahmoud', 'direction' => 'owed_to_me', 'amount' => 7777, 'currency' => 'EGP', 'date' => '2026-10-01'])->assertCreated()->json('id');
        $this->postJson("/api/v1/debts/{$debt}/payments", ['amount' => 1234, 'date' => '2026-10-02', 'note' => 'first part'])->assertCreated();
        $inv = $this->postJson('/api/v1/investments', ['name' => 'Gold Fund', 'type' => 'fund', 'invested_amount' => 55555, 'current_value' => 66666, 'currency' => 'EGP'])->assertCreated()->json('id');

        $this->assertStringNotContainsString('Payroll', $this->raw('accounts', $bank));
        $this->assertStringNotContainsString('94443.93', $this->raw('accounts', $bank)); // 98765.43 − 4321.5
        $this->assertStringNotContainsString('4321', $this->raw('transactions', $tx));
        $this->assertStringNotContainsString('Secret', $this->raw('transactions', $tx));
        $this->assertStringNotContainsString('Khaled', $this->raw('debts', $debt));
        $this->assertStringNotContainsString('7777', $this->raw('debts', $debt));
        $this->assertStringNotContainsString('1234', json_encode(DB::table('debt_payments')->get()));
        $this->assertStringNotContainsString('66666', $this->raw('investments', $inv));
        $this->assertStringNotContainsString('66666', json_encode(DB::table('investment_values')->get()));

        // ...while the API and the calculations see the real values.
        $this->getJson("/api/v1/accounts/{$bank}")->assertJsonPath('name', 'Payroll CIB')->assertJsonPath('balance', 94443.93);
        $this->getJson("/api/v1/debts/{$debt}")->assertJsonPath('person', 'Khaled Mahmoud')->assertJsonPath('remaining', 6543);
    }

    public function test_one_owner_key_cannot_open_another_owners_rows(): void
    {
        $other = User::factory()->create();
        $account = Account::factory()->for($other)->create(['name' => 'Theirs']);
        $payload = DB::table('accounts')->where('id', $account->id)->value('name');

        $this->expectException(DecryptException::class);
        app(DataVault::class)->decrypt($this->owner->id, $payload);
    }

    public function test_the_master_key_is_required_to_read_the_data(): void
    {
        $account = Account::factory()->for($this->owner)->create(['name' => 'Mine']);
        $wrapped = DB::table('users')->where('id', $this->owner->id)->value('data_key');

        $wrongKey = 'base64:'.base64_encode(Encrypter::generateKey('aes-256-gcm'));
        $this->assertFalse(app(DataVault::class)->canUnwrap($wrapped, $wrongKey));
        $this->assertSame('Mine', $account->fresh()->name);
    }

    public function test_balances_stay_exact_through_create_update_delete(): void
    {
        $a = Account::factory()->for($this->owner)->create(['balance' => 1000]);
        $b = Account::factory()->for($this->owner)->create(['balance' => 0]);
        $id = $this->postJson('/api/v1/transactions', ['type' => 'transfer', 'account_id' => $a->id, 'to_account_id' => $b->id, 'amount' => 250.25, 'date' => '2026-10-01'])->json('id');
        $this->assertSame([749.75, 250.25], [(float) $a->fresh()->balance, (float) $b->fresh()->balance]);

        $this->patchJson("/api/v1/transactions/{$id}", ['type' => 'transfer', 'account_id' => $a->id, 'to_account_id' => $b->id, 'amount' => 100, 'date' => '2026-10-01'])->assertOk();
        $this->assertSame([900.0, 100.0], [(float) $a->fresh()->balance, (float) $b->fresh()->balance]);

        $this->deleteJson("/api/v1/transactions/{$id}")->assertNoContent();
        $this->assertSame([1000.0, 0.0], [(float) $a->fresh()->balance, (float) $b->fresh()->balance]);
    }

    public function test_sql_increments_on_encrypted_columns_are_refused(): void
    {
        $this->expectException(LogicException::class);
        Account::factory()->for($this->owner)->create()->increment('balance', 5);
    }

    public function test_in_app_alerts_are_stored_encrypted_and_queued_alerts_carry_no_plain_details(): void
    {
        Bill::forceCreate(['user_id' => $this->owner->id, 'name' => 'Private Clinic', 'amount' => 3210, 'currency' => 'EGP', 'due_date' => '2026-10-20', 'cycle' => 'monthly']);
        app(AlertScanner::class)->run(CarbonImmutable::parse('2026-10-18'));

        $this->assertStringNotContainsString('Private Clinic', json_encode(DB::table('notifications')->get()));
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.0.data.name', 'Private Clinic')->assertJsonPath('data.0.data.amount', 3210);

        $item = new DueItem($this->owner->id, 'bill', 1, 'Private Clinic', CarbonImmutable::parse('2026-10-20'), 3210, 'EGP', null, '/bills');
        $serialized = serialize(new DueAlert($item, 'before', 2));
        $this->assertStringNotContainsString('Private Clinic', $serialized);
        $this->assertStringNotContainsString('3210', $serialized);
        $back = unserialize($serialized);
        $this->assertSame(['Private Clinic', 3210.0], [$back->item->name, $back->item->amount]);
    }

    public function test_vault_commands(): void
    {
        $this->artisan('vault:key')->assertSuccessful();
        $this->artisan('vault:check')->expectsOutputToContain('open with this master key')->assertSuccessful();

        // Rotation: new key live, old one as "previous" → rotate → old key no longer needed.
        $old = config('vault.master_key');
        $new = 'base64:'.base64_encode(Encrypter::generateKey('aes-256-gcm'));
        Account::factory()->for($this->owner)->create(['name' => 'Survives rotation']);
        config(['vault.master_key' => $new, 'vault.previous_keys' => [$old]]);
        $this->app->forgetInstance(DataVault::class);
        $this->artisan('vault:rotate')->assertSuccessful();

        config(['vault.previous_keys' => []]);
        $this->app->forgetInstance(DataVault::class);
        $this->artisan('vault:check')->assertSuccessful();
        $this->assertSame('Survives rotation', Account::where('user_id', $this->owner->id)->first()->name);
    }
}
