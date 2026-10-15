<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Bill;
use App\Models\User;
use App\Notifications\DueAlert;
use App\Services\AlertScanner;
use App\Services\RecurringService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

class AlertTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['alert_days' => 3, 'email_alerts' => false, 'locale' => 'en']);
    }

    private function bill(array $attrs = []): Bill
    {
        return Bill::forceCreate($attrs + [
            'user_id' => $this->owner->id, 'name' => 'Electricity', 'amount' => 640, 'currency' => 'EGP',
            'due_date' => '2026-10-20', 'cycle' => 'monthly',
        ]);
    }

    private function scan(string $date): int
    {
        return app(AlertScanner::class)->run(CarbonImmutable::parse($date));
    }

    public function test_each_stage_fires_once(): void
    {
        $this->bill();

        $this->assertSame(0, $this->scan('2026-10-16')); // 4 days out: too early
        $this->assertSame(1, $this->scan('2026-10-17')); // 3 days: before
        $this->assertSame(0, $this->scan('2026-10-17')); // same run again: deduped
        $this->assertSame(0, $this->scan('2026-10-18')); // still "before", already sent
        $this->assertSame(1, $this->scan('2026-10-20')); // on the day
        $this->assertSame(1, $this->scan('2026-10-22')); // overdue
        $this->assertSame(0, $this->scan('2026-10-25')); // overdue only once

        $stages = $this->owner->notifications()->get()->pluck('data.stage')->sort()->values()->all();
        $this->assertSame(['before', 'due', 'overdue'], $stages);
    }

    public function test_missed_runs_still_alert_and_next_cycle_alerts_again(): void
    {
        $bill = $this->bill(['account_id' => Account::factory()->for($this->owner)->create()->id]);
        $this->assertSame(1, $this->scan('2026-10-19')); // first run inside the window still sends "before"

        $this->travelTo('2026-10-20');
        app(RecurringService::class)->pay($bill->fresh()); // rolls to 2026-11-20
        $this->assertSame(0, $this->scan('2026-10-20'));
        $this->assertSame(1, $this->scan('2026-11-18'));
    }

    public function test_per_item_remind_days_override_default(): void
    {
        $this->bill(['remind_days' => 7]);
        $this->assertSame(1, $this->scan('2026-10-13'));
        $this->bill(['name' => 'No early warning', 'remind_days' => 0, 'due_date' => '2026-10-21']);
        $this->assertSame(0, $this->scan('2026-10-19'));
    }

    public function test_covers_subscriptions_debts_cards_and_maturities(): void
    {
        $this->owner->subscriptions()->create(['name' => 'Netflix', 'amount' => 240, 'currency' => 'EGP', 'renew_date' => '2026-10-20', 'cycle' => 'monthly', 'scope' => 'personal']);
        $this->owner->debts()->create(['person' => 'Ahmed', 'direction' => 'owed_to_me', 'amount' => 500, 'currency' => 'EGP', 'date' => '2026-09-01', 'due_date' => '2026-10-19']);
        $this->owner->debts()->create(['person' => 'Settled', 'direction' => 'i_owe', 'amount' => 500, 'currency' => 'EGP', 'date' => '2026-09-01', 'due_date' => '2026-10-19', 'status' => 'settled']);
        $this->owner->investments()->create(['name' => 'Cert', 'type' => 'certificate', 'invested_amount' => 1000, 'current_value' => 1000, 'currency' => 'EGP', 'maturity_date' => '2026-10-18']);
        Account::factory()->for($this->owner)->create(['type' => 'credit_card', 'balance' => -900, 'due_day' => 19, 'name' => 'Visa']);

        $this->assertSame(4, $this->scan('2026-10-17'));
        $kinds = $this->owner->notifications()->get()->pluck('data.kind')->sort()->values()->all();
        $this->assertSame(['credit_card', 'maturity', 'owed_to_me', 'subscription'], $kinds);
    }

    public function test_channels_follow_settings_and_subscriptions(): void
    {
        Notification::fake();
        $this->bill();
        $this->scan('2026-10-17');
        Notification::assertSentTo($this->owner, DueAlert::class, fn ($n, $channels) => $channels === ['database']);

        $this->owner->update(['email_alerts' => true]);
        $this->owner->updatePushSubscription('https://push.example.com/abc', 'key', 'auth');
        $this->bill(['name' => 'Gas', 'due_date' => '2026-10-19']);
        $this->scan('2026-10-17');
        Notification::assertSentTo($this->owner, DueAlert::class, fn ($n, $channels) => $channels === ['database', 'mail', WebPushChannel::class]);
    }

    public function test_message_text_follows_owner_locale(): void
    {
        $this->owner->update(['locale' => 'ar']);
        $this->bill(['name' => 'الكهرباء']);
        $this->scan('2026-10-23');
        $data = $this->owner->notifications()->first()->data;
        $this->assertSame('متأخر', $data['title']);
        $this->assertStringContainsString('متأخر 3 يوم', $data['body']);
        $this->assertSame(-3, $data['days']);
    }

    public function test_notification_center_api(): void
    {
        $this->bill();
        $this->bill(['name' => 'Gas', 'due_date' => '2026-10-19']);
        $this->scan('2026-10-17');
        Sanctum::actingAs($this->owner);

        $res = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.unread', 2);
        $this->postJson('/api/v1/notifications/'.$res->json('data.0.id').'/read')->assertJsonPath('unread', 1);
        $this->postJson('/api/v1/notifications/read-all')->assertJsonPath('unread', 0);
        $this->getJson('/api/v1/notifications/unread')->assertJsonPath('unread', 0);

        $other = User::factory()->create();
        $this->postJson('/api/v1/notifications/'.$res->json('data.0.id').'/read');
        Sanctum::actingAs($other);
        $this->postJson('/api/v1/notifications/'.$res->json('data.0.id').'/read')->assertNotFound();
    }

    public function test_push_subscription_api(): void
    {
        config(['webpush.vapid.public_key' => 'PUBKEY']);
        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/push/key')->assertJsonPath('key', 'PUBKEY');
        $sub = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/xyz', 'keys' => ['p256dh' => 'p', 'auth' => 'a']];
        $this->postJson('/api/v1/push/subscribe', $sub)->assertCreated();
        $this->postJson('/api/v1/push/subscribe', ['endpoint' => 'http://insecure.example.com', 'keys' => ['p256dh' => 'p', 'auth' => 'a']])->assertJsonValidationErrors('endpoint');
        $this->assertSame(1, $this->owner->pushSubscriptions()->count());
        $this->postJson('/api/v1/push/unsubscribe', ['endpoint' => $sub['endpoint']])->assertOk();
        $this->assertSame(0, $this->owner->pushSubscriptions()->count());
    }

    public function test_command_runs(): void
    {
        $this->artisan('finance:alerts')->assertSuccessful();
    }
}
