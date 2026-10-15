<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\InfoAlert;
use App\Services\SignInLog;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SignInActivityTest extends TestCase
{
    use RefreshDatabase;

    private const ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    private function login(User $u, string $ua, string $password = 'secret-pass-1')
    {
        return $this->withHeaders(['User-Agent' => $ua])->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => $password]);
    }

    public function test_device_labels(): void
    {
        $this->assertSame('Chrome on Android', SignInLog::device(self::ANDROID));
        $this->assertSame('Safari on iPhone', SignInLog::device(self::IPHONE));
        $this->assertSame('Edge on Windows', SignInLog::device('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 Edg/129.0'));
        $this->assertSame('Firefox on Mac', SignInLog::device('Mozilla/5.0 (Macintosh; Intel Mac OS X 14.5; rv:131.0) Gecko/20100101 Firefox/131.0'));
        $this->assertSame('Unknown on unknown device', SignInLog::device(''));
    }

    public function test_new_device_alert_and_activity_list(): void
    {
        Notification::fake();
        $u = User::factory()->create(['password' => 'secret-pass-1', 'email_alerts' => false, 'locale' => 'en']);

        $this->login($u, self::ANDROID)->assertOk();             // first ever: nothing to compare with
        $this->login($u, self::ANDROID)->assertOk();             // same device: quiet
        Notification::assertNothingSent();

        $this->login($u, self::IPHONE, 'wrong')->assertStatus(422); // failed attempt is logged, not alerted
        Notification::assertNothingSent();

        $this->login($u, self::IPHONE)->assertOk();
        // Security notices are emailed even when email alerts are off.
        Notification::assertSentTo($u, InfoAlert::class, function (InfoAlert $n, array $channels) use ($u) {
            $data = $n->toArray($u);

            return $n->kind === 'new_sign_in' && in_array('mail', $channels, true)
                && str_contains($data['text']['en'], 'Safari on iPhone') && str_contains($data['text']['ar'], 'Safari على iPhone');
        });

        $token = $this->login($u, self::IPHONE)->json('token');
        Notification::assertSentToTimes($u, InfoAlert::class, 1);

        $rows = $this->withToken($token)->getJson('/api/v1/auth/activity')->assertOk()->json('data');
        $this->assertCount(5, $rows);
        $this->assertSame(['Safari on iPhone', true, 'password'], [$rows[0]['device'], $rows[0]['success'], $rows[0]['method']]);
        $this->assertFalse($rows[2]['success']);
    }

    public function test_two_factor_methods_and_unknown_emails(): void
    {
        $u = User::factory()->create(['password' => 'secret-pass-1']);
        $this->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'x'])->assertStatus(422);
        $this->assertDatabaseCount('login_events', 0);    // nothing stored for emails that don't exist

        app(TwoFactor::class)->begin($u);
        $u->forceFill(['two_factor_confirmed_at' => now()])->save();
        $challenge = $this->login($u, self::ANDROID)->assertJsonPath('two_factor', true)->json('challenge');
        $this->assertDatabaseCount('login_events', 0);    // password alone isn't a sign-in yet
        $this->withHeaders(['User-Agent' => self::ANDROID])->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);
        $this->assertDatabaseHas('login_events', ['user_id' => $u->id, 'success' => false, 'method' => 'authenticator']);
    }
}
