<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Is every part that runs without anyone watching still working? Used by GET /api/v1/health
 * (for an outside uptime monitor) and by the watchdog that emails the admins.
 *
 * States: ok, fail (makes the health URL answer 503), warn, pending (never ran yet), off.
 */
class HealthCheck
{
    public function __construct(private Heartbeat $heartbeat, private DataVault $vault, private ServerBackup $backup) {}

    /** @return array<string, array{state: string, minutes?: int}> */
    public function checks(): array
    {
        try {
            DB::select('select 1');
        } catch (Throwable) {
            // Nothing else can be read without the database.
            return ['database' => ['state' => 'fail']];
        }

        return [
            'database' => ['state' => 'ok'],
            'encryption' => ['state' => $this->vaultOk() ? 'ok' : 'fail'],
            'scheduler' => $this->fresh('scheduler'),
            'queue' => $this->fresh('queue'),
            'failed_jobs' => ['state' => $this->failedJobs() ? 'warn' : 'ok'],
            'backup' => $this->backupState(),
        ];
    }

    /** @param  array<string, array{state: string}>  $checks */
    public function healthy(array $checks): bool
    {
        return ! in_array('fail', array_column($checks, 'state'), true);
    }

    /** Failed queue jobs in the last 24 hours. */
    public function failedJobs(): int
    {
        return DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
    }

    private function fresh(string $name): array
    {
        $ok = $this->heartbeat->get($name)?->ok_at;
        if (! $ok) {
            return ['state' => 'fail'];
        }
        $minutes = (int) $ok->diffInMinutes(now());

        return ['state' => $minutes > config("ops.stale_minutes.{$name}") ? 'fail' : 'ok', 'minutes' => $minutes];
    }

    private function backupState(): array
    {
        if (! $this->backup->enabled()) {
            return ['state' => 'off'];
        }
        $row = $this->heartbeat->get('backup');
        if (! $row?->ok_at && ! $row?->failed_at) {
            return ['state' => 'pending'];
        }
        if ($row->failed_at && (! $row->ok_at || $row->failed_at->gt($row->ok_at))) {
            return ['state' => 'fail'];
        }

        return $this->fresh('backup');
    }

    private function vaultOk(): bool
    {
        try {
            $this->vault->fingerprint();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
