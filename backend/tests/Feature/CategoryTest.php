<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs($this->owner = User::factory()->create());
    }

    public function test_new_owner_gets_default_categories(): void
    {
        $keys = $this->getJson('/api/v1/categories')->assertOk()->collect()->pluck('key');

        foreach (['food', 'transport', 'bills', 'shopping', 'health', 'education', 'entertainment', 'family', 'work', 'other', 'salary', 'freelance', 'other_income'] as $key) {
            $this->assertContains($key, $keys);
        }
        $this->getJson('/api/v1/categories?type=income')->assertJsonCount(3);
    }

    public function test_crud_and_rename_clears_translation_key(): void
    {
        $food = $this->owner->categories()->where('key', 'food')->first();
        $this->patchJson("/api/v1/categories/{$food->id}", ['name' => 'Eating out', 'color' => '#112233'])
            ->assertOk()->assertJsonPath('key', null)->assertJsonPath('color', '#112233');

        $id = $this->postJson('/api/v1/categories', ['name' => 'Pets', 'type' => 'expense', 'icon' => 'paw', 'color' => '#aa5500'])
            ->assertCreated()->json('id');
        $this->postJson('/api/v1/categories', ['name' => 'X', 'type' => 'expense', 'color' => 'red'])->assertJsonValidationErrors('color');

        $this->deleteJson("/api/v1/categories/{$id}")->assertNoContent();
    }

    public function test_most_used_categories_come_first(): void
    {
        $account = Account::factory()->for($this->owner)->create();
        $health = $this->owner->categories()->where('key', 'health')->first();
        Transaction::factory()->count(3)->create(['account_id' => $account->id, 'category_id' => $health->id]);

        $this->getJson('/api/v1/categories?type=expense')->assertJsonPath('0.key', 'health')->assertJsonPath('0.usage_count', 3);
    }

    public function test_deleting_a_category_keeps_its_transactions(): void
    {
        $account = Account::factory()->for($this->owner)->create();
        $cat = $this->owner->categories()->first();
        $tx = Transaction::factory()->create(['account_id' => $account->id, 'category_id' => $cat->id]);

        $this->deleteJson("/api/v1/categories/{$cat->id}")->assertNoContent();
        $this->assertNull($tx->fresh()->category_id);
    }

    public function test_cannot_touch_another_owners_category(): void
    {
        $other = Category::factory()->create();

        $this->patchJson("/api/v1/categories/{$other->id}", ['name' => 'x'])->assertForbidden();
        $this->deleteJson("/api/v1/categories/{$other->id}")->assertForbidden();
    }
}
