<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Last success / failure of each background part, read by the health checks. */
class Heartbeat
{
    public function ok(string $name): void
    {
        $this->put($name, ['ok_at' => now(), 'error' => null]);
    }

    public function failed(string $name, string $error): void
    {
        $this->put($name, ['failed_at' => now(), 'error' => mb_substr($error, 0, 500)]);
    }

    /** @return object{name: string, ok_at: ?Carbon, failed_at: ?Carbon, error: ?string, count: int, alerted_at: ?Carbon}|null */
    public function get(string $name): ?object
    {
        $row = DB::table('heartbeats')->where('name', $name)->first();
        if (! $row) {
            return null;
        }
        foreach (['ok_at', 'failed_at', 'alerted_at'] as $col) {
            $row->{$col} = $row->{$col} ? Carbon::parse($row->{$col}) : null;
        }
        $row->count = (int) $row->count;

        return $row;
    }

    public function put(string $name, array $values): void
    {
        DB::table('heartbeats')->updateOrInsert(['name' => $name], $values);
    }
}
