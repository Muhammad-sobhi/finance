<?php

namespace App\Jobs;

use App\Services\Heartbeat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Dispatched by the watchdog; when it runs, the queue worker is alive. */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(Heartbeat $heartbeat): void
    {
        $heartbeat->ok('queue');
    }
}
