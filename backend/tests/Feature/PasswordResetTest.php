<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\InfoAlert;
use App\Notifications\ResetPasswordMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_and_reset_password(): void
    {
        Notification::fake();
        $u = User::factory()->create(['email' => 'me@example.com', 'password' => 'old-password-1', 'locale' => 'ar']);
        $old = $u->createToken('phone')->plainTextToken;

        // Same answer for known and unknown emails.
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ME@example.com'])->assertOk()->json('message');
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->json('message');
        $this->assertSame($known, $unknown);

        $token = null;
        Notification::assertSentTo($u, ResetPasswordMail::class, function (ResetPasswordMail $n) use ($u, &$token) {
            $token = $n->token;
            $mail = $n->toMail($u);

            return str_contains($mail->actionUrl, '/reset-password?token=') && str_contains($mail->actionUrl, 'email=me%40example.com')
                && $mail->subject === 'تغيير كلمة مرور خزينتي';
        });

        $this->postJson('/api/v1/auth/reset-password', ['token' => 'wrong', 'email' => 'me@example.com', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertJsonValidationErrors('token');
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'me@example.com', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'me@example.com', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertOk();

        // Old sessions are gone, the new password works, the token can't be reused, and the owner is told.
        $this->assertSame(0, $u->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($old)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => 'me@example.com', 'password' => 'new-password-1'])->assertOk();
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => 'me@example.com', 'password' => 'another-pass-1', 'password_confirmation' => 'another-pass-1'])
            ->assertJsonValidationErrors('token');
        Notification::assertSentTo($u, InfoAlert::class, fn (InfoAlert $n, $channels) => $n->kind === 'password_reset' && in_array('mail', $channels, true));
    }

    public function test_reset_emails_are_rate_limited(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'me@example.com']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => 'me@example.com'])->assertOk();
        }
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'me@example.com'])->assertStatus(429);
    }

    public function test_emails_are_in_the_users_language_and_right_to_left(): void
    {
        config(['mail.default' => 'array']);
        $ar = User::factory()->create(['locale' => 'ar']);
        $ar->notifyNow(new ResetPasswordMail('tok'));
        $en = User::factory()->create(['locale' => 'en']);
        $en->notifyNow(new ResetPasswordMail('tok'));

        [$mAr, $mEn] = app('mailer')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage()->getHtmlBody())->all();
        foreach (['مرحبًا', 'مع التحية', 'انسخ الرابط', 'direction: rtl', 'اختر كلمة مرور جديدة', 'جميع الحقوق محفوظة'] as $text) {
            $this->assertStringContainsString($text, $mAr);
        }
        $this->assertStringContainsString('Hello!', $mEn);
        $this->assertStringNotContainsString('direction: rtl', $mEn);
        $this->assertSame('en', app()->getLocale()); // the app locale is restored after sending
    }
}
