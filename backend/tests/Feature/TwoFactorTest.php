<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->totp = new Google2FA;
        User::factory()->create(['email' => 'me@example.com', 'password' => 'secret-pass-1']);
    }

    private function token(): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => 'me@example.com', 'password' => 'secret-pass-1'])->json('token');
    }

    private function codeAt(string $secret, int $offsetSteps = 0): string
    {
        return $this->totp->oathTotp($secret, $this->totp->getTimestamp() + $offsetSteps);
    }

    /** Enable 2FA through the API; returns [secret, recovery codes, token]. */
    private function enable(): array
    {
        $token = $this->token();
        $this->withToken($token)->postJson('/api/v1/auth/two-factor/setup', ['current_password' => 'wrong'])->assertJsonValidationErrors('current_password');
        $setup = $this->withToken($token)->postJson('/api/v1/auth/two-factor/setup', ['current_password' => 'secret-pass-1'])->assertOk()->json();
        $this->assertStringStartsWith('otpauth://totp/', $setup['otpauth_url']);
        $this->withToken($token)->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])->assertJsonValidationErrors('code');
        $codes = $this->withToken($token)->postJson('/api/v1/auth/two-factor/confirm', ['code' => $this->codeAt($setup['secret'], -1)])
            ->assertOk()->assertJsonCount(8, 'recovery_codes')->json('recovery_codes');

        return [$setup['secret'], $codes, $token];
    }

    public function test_secret_is_encrypted_and_hidden(): void
    {
        [$secret] = $this->enable();
        $raw = DB::table('users')->value('two_factor_secret');
        $this->assertStringNotContainsString($secret, $raw);
        $this->getJson('/api/v1/auth/me')->assertJsonMissingPath('two_factor_secret')->assertJsonPath('two_factor', true);
    }

    public function test_login_needs_the_code_once_enabled(): void
    {
        [$secret] = $this->enable();
        $this->app['auth']->forgetGuards();

        $step1 = $this->postJson('/api/v1/auth/login', ['email' => 'me@example.com', 'password' => 'secret-pass-1'])
            ->assertOk()->assertJsonPath('two_factor', true)->assertJsonMissingPath('token');
        $challenge = $step1->json('challenge');

        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => '123456'])->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => 'forged', 'code' => $this->codeAt($secret)])->assertJsonValidationErrors('challenge');
        $code = $this->codeAt($secret);
        $token = $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $code])->assertOk()->json('token');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        // The same code can't be replayed.
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $code])->assertJsonValidationErrors('code');
    }

    public function test_challenge_expires_after_five_minutes(): void
    {
        [$secret] = $this->enable();
        $challenge = $this->postJson('/api/v1/auth/login', ['email' => 'me@example.com', 'password' => 'secret-pass-1'])->json('challenge');
        $this->travel(6)->minutes();
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $this->codeAt($secret)])->assertJsonValidationErrors('challenge');
    }

    public function test_recovery_codes_work_once(): void
    {
        [, $codes] = $this->enable();
        $challenge = fn () => $this->postJson('/api/v1/auth/login', ['email' => 'me@example.com', 'password' => 'secret-pass-1'])->json('challenge');

        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge(), 'recovery_code' => strtoupper($codes[0])])->assertOk()->assertJsonStructure(['token']);
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge(), 'recovery_code' => $codes[0]])->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge(), 'recovery_code' => 'aaaaa-bbbbb'])->assertJsonValidationErrors('code');
    }

    public function test_code_guessing_is_rate_limited(): void
    {
        $this->enable();
        $challenge = $this->postJson('/api/v1/auth/login', ['email' => 'me@example.com', 'password' => 'secret-pass-1'])->json('challenge');
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => sprintf('%06d', $i)])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => '999999'])->assertStatus(429);
    }

    public function test_disable_needs_password_and_code(): void
    {
        [$secret, $codes, $token] = $this->enable();
        $this->withToken($token)->getJson('/api/v1/auth/two-factor')->assertJsonPath('enabled', true)->assertJsonPath('recovery_codes_left', 8);
        $this->withToken($token)->postJson('/api/v1/auth/two-factor/disable', ['current_password' => 'secret-pass-1', 'code' => '000000'])->assertJsonValidationErrors('code');
        $this->withToken($token)->postJson('/api/v1/auth/two-factor/disable', ['current_password' => 'wrong', 'code' => $codes[1]])->assertJsonValidationErrors('current_password');
        $this->withToken($token)->postJson('/api/v1/auth/two-factor/disable', ['current_password' => 'secret-pass-1', 'code' => $codes[1]])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'me@example.com', 'password' => 'secret-pass-1'])->assertJsonStructure(['token']);
    }

    public function test_new_recovery_codes_replace_old_ones(): void
    {
        [, $old, $token] = $this->enable();
        $new = $this->withToken($token)->postJson('/api/v1/auth/two-factor/recovery-codes', ['current_password' => 'secret-pass-1'])->assertOk()->json('recovery_codes');
        $this->assertNotEquals($old, $new);
        $challenge = $this->postJson('/api/v1/auth/login', ['email' => 'me@example.com', 'password' => 'secret-pass-1'])->json('challenge');
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'recovery_code' => $old[0]])->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'recovery_code' => $new[0]])->assertOk();
    }

    public function test_trusted_device_skips_the_code_for_30_days(): void
    {
        [$secret] = $this->enable();
        $challenge = $this->postJson('/api/v1/auth/login', ['email' => 'me@example.com', 'password' => 'secret-pass-1'])->json('challenge');
        $device = $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $this->codeAt($secret), 'remember' => true])
            ->assertOk()->json('device_token');
        $this->assertNotEmpty($device);
        $this->assertDatabaseMissing('trusted_devices', ['token_hash' => $device]); // only the hash is stored

        $login = fn (?string $d) => $this->postJson('/api/v1/auth/login', ['email' => 'me@example.com', 'password' => 'secret-pass-1', 'device_token' => $d]);
        $login($device)->assertJsonStructure(['token'])->assertJsonMissingPath('two_factor');
        $login('someone-elses-token')->assertJsonPath('two_factor', true);
        $login(null)->assertJsonPath('two_factor', true);
        // Still needs the password.
        $this->travel(61)->seconds(); // stay under the 5/min login limit
        $this->postJson('/api/v1/auth/login', ['email' => 'me@example.com', 'password' => 'nope', 'device_token' => $device])->assertStatus(422);

        $this->travel(31)->days();
        $login($device)->assertJsonPath('two_factor', true);
    }

    public function test_trusted_devices_are_forgotten_on_sign_out_others(): void
    {
        [$secret, , $token] = $this->enable();
        $challenge = $this->postJson('/api/v1/auth/login', ['email' => 'me@example.com', 'password' => 'secret-pass-1'])->json('challenge');
        $device = $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $this->codeAt($secret), 'remember' => true])->json('device_token');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth/logout-others')->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'me@example.com', 'password' => 'secret-pass-1', 'device_token' => $device])->assertJsonPath('two_factor', true);
    }
}
