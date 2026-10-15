<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\IncomeSource;
use App\Models\Project;
use App\Models\User;
use App\Services\AlertScanner;
use App\Services\RecurringService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IncomeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Account $bank;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15');
        Sanctum::actingAs($this->owner = User::factory()->create(['email_alerts' => false]));
        $this->bank = Account::factory()->for($this->owner)->create(['balance' => 1000]);
    }

    private function bal(): float
    {
        return (float) $this->bank->fresh()->balance;
    }

    public function test_salary_received_records_income_and_rolls_to_next_month(): void
    {
        $salary = $this->owner->categories()->where('key', 'salary')->value('id');
        $id = $this->postJson('/api/v1/income-sources', [
            'name' => 'Salary', 'category_id' => $salary, 'amount' => 30000, 'currency' => 'EGP',
            'next_date' => '2026-10-25', 'cycle' => 'monthly', 'account_id' => $this->bank->id,
        ])->assertCreated()->assertJsonPath('next_date', '2026-10-25')->assertJsonPath('days', 10)->json('id');

        // Received early and a bit different (bonus).
        $this->postJson("/api/v1/income-sources/{$id}/receive", ['amount' => 32000])->assertOk()
            ->assertJsonPath('transaction.type', 'income')
            ->assertJsonPath('transaction.category_id', $salary)
            ->assertJsonPath('transaction.source_type', 'income_source')
            ->assertJsonPath('item.next_date', '2026-11-25');
        $this->assertSame(33000.0, $this->bal());

        $this->getJson('/api/v1/income-sources')->assertJsonPath('meta.monthly', 30000);
        // Only income categories are allowed.
        $food = $this->owner->categories()->where('key', 'food')->value('id');
        $this->patchJson("/api/v1/income-sources/{$id}", ['category_id' => $food])->assertJsonValidationErrors('category_id');
    }

    public function test_auto_record_income(): void
    {
        IncomeSource::forceCreate(['user_id' => $this->owner->id, 'name' => 'Rent', 'amount' => 5000, 'currency' => 'EGP',
            'next_date' => '2026-10-01', 'cycle' => 'monthly', 'auto_pay' => true, 'account_id' => $this->bank->id]);
        $this->assertSame(1, app(RecurringService::class)->runAutoPay());
        $this->assertSame(6000.0, $this->bal());
    }

    public function test_freelance_project_payments_and_outstanding(): void
    {
        $id = $this->postJson('/api/v1/projects', [
            'client' => 'Acme', 'name' => 'Website', 'amount' => 20000, 'currency' => 'EGP', 'due_date' => '2026-10-30', 'notes' => 'phase 1',
        ])->assertCreated()->assertJsonPath('outstanding', 20000)->assertJsonPath('status', 'active')->json('id');

        $this->postJson("/api/v1/projects/{$id}/payments", ['amount' => 8000])->assertJsonValidationErrors('account_id');
        $pid = $this->postJson("/api/v1/projects/{$id}/payments", ['amount' => 8000, 'account_id' => $this->bank->id, 'note' => 'deposit'])
            ->assertCreated()->assertJsonPath('project.received', 8000)->assertJsonPath('project.outstanding', 12000)->json('id');
        $this->assertSame(9000.0, $this->bal());

        // The income transaction is a real income (counts in reports) but is locked to the project.
        $tx = $this->getJson('/api/v1/transactions')->assertJsonPath('meta.totals.income', 8000)->json('data.0');
        $this->assertSame('freelance', $this->owner->categories()->find($tx['category_id'])->key);
        $this->assertStringContainsString('Acme — Website', $tx['note']);
        $this->deleteJson("/api/v1/transactions/{$tx['id']}")->assertStatus(409)->assertJsonPath('code', 'managed_by_project');

        $this->getJson('/api/v1/projects')->assertJsonPath('meta.outstanding', 12000)->assertJsonPath('meta.received', 8000);

        // Paying the rest closes the project.
        $this->postJson("/api/v1/projects/{$id}/payments", ['amount' => 12000, 'account_id' => $this->bank->id])
            ->assertJsonPath('project.status', 'done');

        // Deleting a payment reverses its money.
        $this->deleteJson("/api/v1/projects/{$id}/payments/{$pid}")->assertOk()->assertJsonPath('received', 12000);
        $this->assertSame(13000.0, $this->bal());
        $this->deleteJson("/api/v1/projects/{$id}")->assertNoContent();
        $this->assertSame(1000.0, $this->bal());
    }

    public function test_foreign_currency_project_payment_converts(): void
    {
        $this->owner->exchangeRates()->create(['currency' => 'USD', 'rate' => 50]);
        $id = $this->postJson('/api/v1/projects', ['client' => 'US Co', 'name' => 'App', 'amount' => 1000, 'currency' => 'USD'])->json('id');
        $this->postJson("/api/v1/projects/{$id}/payments", ['amount' => 100, 'account_id' => $this->bank->id]);
        $this->assertSame(6000.0, $this->bal()); // 100 USD → 5000 EGP
        $this->getJson('/api/v1/projects')->assertJsonPath('meta.outstanding', 45000);
    }

    public function test_income_summary_by_kind(): void
    {
        $src = $this->postJson('/api/v1/income-sources', ['name' => 'Salary', 'amount' => 30000, 'currency' => 'EGP', 'next_date' => '2026-10-01', 'cycle' => 'monthly', 'account_id' => $this->bank->id])->json('id');
        $this->postJson("/api/v1/income-sources/{$src}/receive", ['date' => '2026-10-01']);
        $p = $this->postJson('/api/v1/projects', ['client' => 'Acme', 'name' => 'Site', 'amount' => 9000, 'currency' => 'EGP'])->json('id');
        $this->postJson("/api/v1/projects/{$p}/payments", ['amount' => 4000, 'account_id' => $this->bank->id, 'date' => '2026-10-05']);
        $this->postJson('/api/v1/transactions', ['type' => 'income', 'account_id' => $this->bank->id, 'amount' => 700, 'date' => '2026-10-07']);
        $this->postJson('/api/v1/transactions', ['type' => 'income', 'account_id' => $this->bank->id, 'amount' => 1000, 'date' => '2026-09-07']);
        $this->postJson('/api/v1/income-sources', ['name' => 'Side', 'amount' => 2000, 'currency' => 'EGP', 'next_date' => '2026-10-28', 'cycle' => 'monthly']);

        $this->getJson('/api/v1/income/summary?month=2026-10')->assertOk()
            ->assertJsonPath('received.recurring', 30000)
            ->assertJsonPath('received.projects', 4000)
            ->assertJsonPath('received.other', 700)
            ->assertJsonPath('received.total', 34700)
            ->assertJsonPath('expected', 2000)        // salary already rolled to November
            ->assertJsonPath('outstanding', 5000)
            ->assertJsonCount(6, 'series')
            ->assertJsonPath('series.4.other', 1000);
    }

    public function test_alerts_and_dashboard_include_expected_income_and_project_payments(): void
    {
        IncomeSource::forceCreate(['user_id' => $this->owner->id, 'name' => 'Salary', 'amount' => 30000, 'currency' => 'EGP', 'next_date' => '2026-10-15', 'cycle' => 'monthly']);
        IncomeSource::forceCreate(['user_id' => $this->owner->id, 'name' => 'Auto', 'amount' => 1, 'currency' => 'EGP', 'next_date' => '2026-10-15', 'cycle' => 'monthly', 'auto_pay' => true]);
        IncomeSource::forceCreate(['user_id' => $this->owner->id, 'name' => 'Later', 'amount' => 1, 'currency' => 'EGP', 'next_date' => '2026-10-17', 'cycle' => 'monthly']);
        Project::forceCreate(['user_id' => $this->owner->id, 'client' => 'Acme', 'name' => 'Site', 'amount' => 5000, 'currency' => 'EGP', 'due_date' => '2026-10-10']);

        // Salary due today (no early warning by default), Acme overdue; auto-recorded & "Later" are silent.
        $this->assertSame(2, app(AlertScanner::class)->run(CarbonImmutable::parse('2026-10-15')));
        $kinds = $this->owner->notifications()->get()->pluck('data.kind')->sort()->values()->all();
        $this->assertSame(['income', 'project'], $kinds);
        $body = $this->owner->notifications()->get()->firstWhere('data.kind', 'project')->data['body'];
        $this->assertStringContainsString('Acme — Site', $body);

        $kinds = collect($this->getJson('/api/v1/dashboard')->json('upcoming'))->pluck('kind')->all();
        $this->assertContains('income', $kinds);
        $this->assertContains('project', $kinds);
    }

    public function test_heir_cannot_write_income(): void
    {
        $this->owner->forceFill(['heir_access_activated_at' => now()])->save();
        Sanctum::actingAs(User::factory()->heirOf($this->owner)->create());
        $this->getJson('/api/v1/projects')->assertOk();
        $this->getJson('/api/v1/income/summary')->assertOk();
        $this->postJson('/api/v1/projects', ['client' => 'x', 'name' => 'y', 'amount' => 1, 'currency' => 'EGP'])->assertForbidden();
        $this->postJson('/api/v1/income-sources', [])->assertForbidden();
    }
}
