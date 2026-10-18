<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DataTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $wallet;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15');
        $this->owner = User::factory()->create(['locale' => 'en', 'password' => 'correct-horse-1']);
        $this->wallet = Account::factory()->for($this->owner)->create(['name' => 'CIB', 'balance' => 10000, 'account_number' => '1234-5678']);
        Sanctum::actingAs($this->owner);
    }

    private function cat(string $key): int
    {
        return $this->owner->categories()->where('key', $key)->value('id');
    }

    public function test_csv_export_with_bom_labels_and_formula_guard(): void
    {
        $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $this->wallet->id, 'category_id' => $this->cat('food'), 'amount' => 120.5, 'date' => '2026-10-01', 'note' => 'كشري']);
        $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $this->wallet->id, 'amount' => 10, 'date' => '2026-09-01', 'note' => '=HYPERLINK("x")']);

        $csv = $this->get('/api/v1/export/transactions?from=2026-09-01')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFDate,Type,Amount", $csv);
        $this->assertStringContainsString('2026-10-01,Expense,120.50,EGP,CIB,,,Food,كشري', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $lines = array_filter(explode("\n", $csv));
        $this->assertCount(3, $lines);
        $this->assertStringNotContainsString('2026-09-01', $this->get('/api/v1/export/transactions?from=2026-10-01')->streamedContent());

        $this->owner->update(['locale' => 'ar']);
        $this->assertStringContainsString('التاريخ,النوع', $this->get('/api/v1/export/transactions')->streamedContent());
    }

    public function test_receipts_are_private_and_follow_the_transaction(): void
    {
        Storage::fake('local');
        $id = $this->postJson('/api/v1/transactions', ['type' => 'expense', 'account_id' => $this->wallet->id, 'amount' => 50, 'date' => '2026-10-10'])->json('id');

        $this->post("/api/v1/transactions/{$id}/receipt", ['file' => UploadedFile::fake()->create('x.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('file');
        $this->post("/api/v1/transactions/{$id}/receipt", ['file' => UploadedFile::fake()->image('r.jpg', 800, 1200)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('has_receipt', true);
        $path = $this->owner->transactions()->find($id)->receipt_path;
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith("receipts/{$this->owner->id}/", $path);
        $this->assertStringNotContainsString('JFIF', Storage::disk('local')->get($path)); // stored encrypted

        $this->get("/api/v1/transactions/{$id}/receipt")->assertOk()->assertHeader('content-type', 'image/jpeg');
        $this->assertStringStartsWith("\xFF\xD8", $this->get("/api/v1/transactions/{$id}/receipt")->getContent()); // decrypted JPEG

        // An heir can view; a stranger can't.
        Sanctum::actingAs(User::factory()->heirOf($this->owner)->create());
        $this->get("/api/v1/transactions/{$id}/receipt")->assertOk();
        $this->delete("/api/v1/transactions/{$id}/receipt", [], ['Accept' => 'application/json'])->assertForbidden();
        Sanctum::actingAs(User::factory()->create());
        $this->get("/api/v1/transactions/{$id}/receipt", ['Accept' => 'application/json'])->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/v1/transactions/{$id}")->assertNoContent();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_encrypted_backup_restores_into_a_new_owner(): void
    {
        // A small but linked ledger: debt with payment, bill paid, installment, goal, heir.
        $debt = $this->postJson('/api/v1/debts', ['person' => 'Ali', 'direction' => 'owed_to_me', 'amount' => 1000, 'currency' => 'EGP', 'account_id' => $this->wallet->id, 'notes' => 'secret note'])->json('id');
        $this->postJson("/api/v1/debts/{$debt}/payments", ['amount' => 400, 'account_id' => $this->wallet->id]);
        $bill = $this->postJson('/api/v1/bills', ['name' => 'Internet', 'amount' => 300, 'currency' => 'EGP', 'due_date' => '2026-10-10', 'cycle' => 'monthly', 'account_id' => $this->wallet->id, 'category_id' => $this->cat('bills')])->json('id');
        $this->postJson("/api/v1/bills/{$bill}/pay");
        $this->postJson('/api/v1/budgets', ['category_id' => $this->cat('bills'), 'amount' => 1000]);
        $this->postJson('/api/v1/heirs', ['name' => 'Sara', 'relation' => 'daughter', 'email' => 'sara@example.com', 'national_id' => '29901011234567']);
        $this->patchJson('/api/v1/settings', ['gold_price_24k' => 4000]);

        // Wrong password or short passphrase → refused.
        $this->postJson('/api/v1/backup', ['current_password' => 'nope', 'passphrase' => 'long enough pass'])->assertJsonValidationErrors('current_password');
        $this->postJson('/api/v1/backup', ['current_password' => 'correct-horse-1', 'passphrase' => 'short'])->assertJsonValidationErrors('passphrase');
        $file = $this->post('/api/v1/backup', ['current_password' => 'correct-horse-1', 'passphrase' => 'my long passphrase'])->assertOk()->streamedContent();

        // Sealed: no plain data inside.
        $this->assertStringNotContainsString('Ali', $file);
        $this->assertStringNotContainsString('secret note', $file);
        $backups = app(BackupService::class);
        try {
            $backups->open($file, 'wrong passphrase!');
            $this->fail('Opened with a wrong passphrase.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Wrong passphrase or damaged file.', $e->getMessage());
        }

        // Restore through the CLI into a fresh owner (seeded categories are replaced).
        $path = tempnam(sys_get_temp_dir(), 'bak');
        file_put_contents($path, $file);
        $new = User::factory()->create(['email' => 'restored@example.com']);
        $this->artisan('finance:restore', ['file' => $path, 'email' => 'restored@example.com'])
            ->expectsQuestion('Backup passphrase', 'my long passphrase')->assertSuccessful();
        // A second restore into the same (now full) account is refused.
        $this->artisan('finance:restore', ['file' => $path, 'email' => 'restored@example.com'])
            ->expectsQuestion('Backup passphrase', 'my long passphrase')->assertFailed();
        unlink($path);

        $new->refresh();
        $this->assertSame(4000.0, (float) $new->gold_price_24k);
        $acc = $new->accounts()->first();
        $this->assertSame((float) $this->wallet->fresh()->balance, (float) $acc->balance);
        $this->assertSame('1234-5678', $acc->account_number); // re-encrypted on the new side
        $this->assertSame($this->owner->transactions()->count(), $new->transactions()->count());
        $this->assertSame($this->owner->categories()->count(), $new->categories()->count());
        $newDebt = $new->debts()->first();
        $this->assertSame('secret note', $newDebt->notes);
        $this->assertSame(1, $newDebt->payments()->count());
        // Links point at the new rows, not the old ids.
        $payTx = $new->transactions()->where('source_type', 'debt_payment')->first();
        $this->assertSame($newDebt->payments()->value('id'), $payTx->source_id);
        $this->assertSame($acc->id, $payTx->account_id);
        $billTx = $new->transactions()->where('source_type', 'bill')->first();
        $this->assertSame($new->bills()->value('id'), $billTx->source_id);
        $this->assertSame($new->categories()->where('key', 'bills')->value('id'), $new->budgets()->value('category_id'));
        $this->assertSame('29901011234567', $new->heirs()->first()->national_id);
        $this->assertNull($new->heirs()->first()->login_user_id);
    }

    public function test_heir_cannot_back_up(): void
    {
        Sanctum::actingAs(User::factory()->heirOf($this->owner)->create());
        $this->postJson('/api/v1/backup', ['current_password' => 'x', 'passphrase' => 'xxxxxxxxxxxx'])->assertForbidden();
        $this->get('/api/v1/export/transactions')->assertOk();
    }
}
