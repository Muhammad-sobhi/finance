<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\OpsAlert;
use App\Services\Heartbeat;
use App\Services\ServerBackup;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PDO;
use Tests\TestCase;
use ZipArchive;

class OpsTest extends TestCase
{
    // Not RefreshDatabase: SQLite can't VACUUM INTO (the backup snapshot) inside its wrapping transaction.
    use DatabaseMigrations;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-17 03:15:00');
        $this->admin = User::factory()->create(['locale' => 'ar']);
        $this->admin->forceFill(['is_admin' => true])->save();
        config(['ops.backup.key' => ServerBackup::newKey()]);
        Storage::fake('backups');
        Storage::fake('local');
    }

    private function tmp(string $name): string
    {
        $dir = storage_path('framework/testing/ops');
        is_dir($dir) || mkdir($dir, 0700, true);

        return "{$dir}/{$name}";
    }

    public function test_backup_is_encrypted_uploaded_and_opens_back_into_database_and_receipts(): void
    {
        Storage::disk('local')->put('receipts/1/abc.jpg.enc', 'sealed-receipt');

        $this->artisan('finance:backup')->assertSuccessful();

        $files = Storage::disk('backups')->files();
        $this->assertSame(['dinar-2026-10-17-031500.dinarbak'], $files);
        $sealed = Storage::disk('backups')->get($files[0]);
        $this->assertStringStartsWith(ServerBackup::MAGIC, $sealed);
        $this->assertStringNotContainsString($this->admin->email, $sealed);
        $this->assertNotNull(app(Heartbeat::class)->get('backup')->ok_at);

        file_put_contents($in = $this->tmp('in.dinarbak'), $sealed);
        $this->artisan('finance:backup-open', ['file' => $in, 'out' => $out = $this->tmp('out.zip')])->assertSuccessful();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($out));
        $this->assertSame('sealed-receipt', $zip->getFromName('receipts/1/abc.jpg.enc'));
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $this->assertSame('dinar-server-backup', $manifest['format']);
        $this->assertSame(1, $manifest['receipts']);
        $this->assertNotEmpty($manifest['master_key_fingerprint']);
        file_put_contents($db = $this->tmp('db.sqlite'), $zip->getFromName('database.sqlite'));
        $zip->close();

        $copy = new PDO("sqlite:{$db}");
        $this->assertSame($this->admin->email, $copy->query('select email from users')->fetchColumn());
    }

    public function test_wrong_key_and_damaged_file_are_rejected(): void
    {
        $this->artisan('finance:backup')->assertSuccessful();
        $sealed = Storage::disk('backups')->get(Storage::disk('backups')->files()[0]);
        file_put_contents($in = $this->tmp('in.dinarbak'), $sealed);

        config(['ops.backup.key' => ServerBackup::newKey()]);
        $this->artisan('finance:backup-open', ['file' => $in, 'out' => $this->tmp('x.zip')])
            ->expectsOutputToContain('Wrong backup key')->assertFailed();

        file_put_contents($cut = $this->tmp('cut.dinarbak'), substr($sealed, 0, 60));
        $this->artisan('finance:backup-open', ['file' => $cut, 'out' => $this->tmp('y.zip')])->assertFailed();
    }

    public function test_old_backups_are_pruned_after_keep_days(): void
    {
        config(['ops.backup.keep_days' => 30]);
        Storage::disk('backups')->put('dinar-2026-09-01-031500.dinarbak', 'old');
        Storage::disk('backups')->put('dinar-2026-10-01-031500.dinarbak', 'recent');
        Storage::disk('backups')->put('notes.txt', 'not ours');

        $this->artisan('finance:backup')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['dinar-2026-10-01-031500.dinarbak', 'dinar-2026-10-17-031500.dinarbak', 'notes.txt'],
            Storage::disk('backups')->files(),
        );
    }

    public function test_backup_failure_emails_the_admins_right_away_and_fails_health(): void
    {
        Notification::fake();
        config(['ops.backup.key' => 'base64:short']);

        $this->artisan('finance:backup')->assertFailed();

        Notification::assertSentTo($this->admin, OpsAlert::class, fn (OpsAlert $n) => $n->check === 'backup' && ! $n->recovered);
        $this->artisan('finance:watchdog');
        $this->assertSame('fail', $this->getJson('/api/v1/health')->assertStatus(503)->json('checks.backup.state'));
    }

    public function test_backups_are_off_without_a_key(): void
    {
        config(['ops.backup.key' => null]);

        $this->artisan('finance:backup')->expectsOutputToContain('Backups are off')->assertSuccessful();
        $this->assertSame([], Storage::disk('backups')->files());
    }

    public function test_health_is_503_until_the_scheduler_and_queue_have_run(): void
    {
        $this->getJson('/api/v1/health')->assertStatus(503)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('checks.database.state', 'ok')
            ->assertJsonPath('checks.scheduler.state', 'fail');

        $this->artisan('finance:watchdog')->assertSuccessful();   // sync queue: the heartbeat job runs now

        $this->getJson('/api/v1/health')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.scheduler.state', 'ok')
            ->assertJsonPath('checks.queue.state', 'ok')
            ->assertJsonPath('checks.encryption.state', 'ok')
            ->assertJsonPath('checks.backup.state', 'pending')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_health_fails_when_the_scheduler_stops(): void
    {
        $this->artisan('finance:watchdog');
        Carbon::setTestNow(now()->addMinutes(11));

        $this->getJson('/api/v1/health')->assertStatus(503)
            ->assertJsonPath('checks.scheduler.state', 'fail')
            ->assertJsonPath('checks.scheduler.minutes', 11);
    }

    public function test_stalled_queue_alerts_once_repeats_after_hours_and_reports_recovery(): void
    {
        Notification::fake();
        config(['queue.default' => 'database']);   // jobs wait for a worker that never comes

        $this->artisan('finance:watchdog');
        Notification::assertNothingSent();

        Carbon::setTestNow(now()->addMinutes(25));
        $this->artisan('finance:watchdog')->expectsOutputToContain('Alerts: queue');
        Notification::assertSentToTimes($this->admin, OpsAlert::class, 1);

        Carbon::setTestNow(now()->addHour());
        $this->artisan('finance:watchdog')->expectsOutputToContain('All good');   // no repeat yet
        Carbon::setTestNow(now()->addHours(12));
        $this->artisan('finance:watchdog');
        Notification::assertSentToTimes($this->admin, OpsAlert::class, 2);

        app(Heartbeat::class)->ok('queue');   // the worker is back
        $this->artisan('finance:watchdog')->expectsOutputToContain('queue:recovered');
        Notification::assertSentTo($this->admin, OpsAlert::class, fn (OpsAlert $n) => $n->check === 'queue' && $n->recovered);
    }

    public function test_new_failed_jobs_alert_the_admins_and_the_extra_address(): void
    {
        Notification::fake();
        config(['ops.alert_email' => 'ops@example.com']);
        DB::table('failed_jobs')->insert(['uuid' => 'u1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

        $this->artisan('finance:watchdog');
        $this->artisan('finance:watchdog');   // same failure, no second mail

        Notification::assertSentToTimes($this->admin, OpsAlert::class, 1);
        Notification::assertSentTo(new AnonymousNotifiable, OpsAlert::class,
            fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'ops@example.com' && $n->params['count'] === 1);
        $this->assertSame('warn', $this->getJson('/api/v1/health')->json('checks.failed_jobs.state'));
    }

    public function test_alert_mail_is_in_the_admins_language(): void
    {
        $mail = (new OpsAlert('queue', params: ['minutes' => 30]))->toMail($this->admin);

        $this->assertStringContainsString('توقف عامل الطابور', $mail->subject);
        $this->assertStringContainsString('30', $mail->introLines[0]);
    }
}
