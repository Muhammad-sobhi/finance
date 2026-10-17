<?php

namespace App\Services;

use App\Jobs\QueueHeartbeat;
use App\Models\User;
use App\Notifications\OpsAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Runs every 5 minutes from the scheduler: records that the scheduler is alive, sends a
 * heartbeat job through the queue, and emails the admins when a part stops (once per
 * problem, repeated every few hours while it lasts) and when it works again.
 *
 * A dead scheduler or server can't report itself — an outside monitor on /api/v1/health covers that.
 */
class Watchdog
{
    /** Parts the watchdog itself alerts about. */
    private const WATCHED = ['queue', 'encryption', 'backup'];

    public function __construct(private Heartbeat $heartbeat, private HealthCheck $health) {}

    /** @return list<string> alerts sent */
    public function run(): array
    {
        $this->heartbeat->ok('scheduler');
        // The queue gets a grace period from the first run, before its first heartbeat lands.
        DB::table('heartbeats')->insertOrIgnore(['name' => 'queue', 'ok_at' => now()]);
        QueueHeartbeat::dispatch();

        $checks = $this->health->checks();
        $sent = [];
        foreach (self::WATCHED as $part) {
            $state = $checks[$part]['state'] ?? 'ok';
            $mark = "alert:{$part}";
            $alerted = $this->heartbeat->get($mark)?->alerted_at;

            if ($state === 'fail') {
                if (! $alerted || $alerted->lt(now()->subHours(config('ops.realert_hours')))) {
                    $params = match ($part) {
                        'queue' => ['minutes' => $checks['queue']['minutes'] ?? 0],
                        'backup' => ['error' => $this->heartbeat->get('backup')?->error ?: '—'],
                        default => [],
                    };
                    $this->send(new OpsAlert($part, params: $params));
                    $this->heartbeat->put($mark, ['alerted_at' => now()]);
                    $sent[] = $part;
                }
            } elseif ($alerted && in_array($state, ['ok', 'pending', 'off'], true)) {
                $this->send(new OpsAlert($part, recovered: true));
                $this->heartbeat->put($mark, ['alerted_at' => null]);
                $sent[] = "{$part}:recovered";
            }
        }

        // New failed jobs since the last alert (the table only grows until someone retries or flushes).
        $failed = DB::table('failed_jobs')->count();
        $seen = $this->heartbeat->get('alert:failed_jobs')?->count ?? 0;
        if ($failed > $seen) {
            $this->send(new OpsAlert('failed_jobs', params: ['count' => $failed - $seen]));
            $sent[] = 'failed_jobs';
        }
        if ($failed !== $seen) {
            $this->heartbeat->put('alert:failed_jobs', ['count' => $failed]);
        }

        return $sent;
    }

    /** A failed backup is reported at once, not on the next watchdog run. */
    public function backupFailed(string $error): void
    {
        $this->heartbeat->failed('backup', $error);
        $this->send(new OpsAlert('backup', params: ['error' => $error]));
        $this->heartbeat->put('alert:backup', ['alerted_at' => now()]);
    }

    private function send(OpsAlert $alert): void
    {
        try {
            $admins = User::where('is_admin', true)->get();
            if ($admins->isNotEmpty()) {
                Notification::sendNow($admins, $alert);
            }
            if ($extra = config('ops.alert_email')) {
                if (! $admins->contains(fn ($u) => strcasecmp($u->email, $extra) === 0)) {
                    Notification::route('mail', $extra)->notifyNow($alert);
                }
            }
        } catch (Throwable $e) {
            report($e);   // mail itself is down: Sentry / the log is all that's left
        }
    }
}
