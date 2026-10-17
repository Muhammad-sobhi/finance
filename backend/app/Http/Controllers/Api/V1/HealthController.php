<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\HealthCheck;
use Illuminate\Http\JsonResponse;

/**
 * Public status for an uptime monitor (UptimeRobot, Healthchecks.io…): 200 when every part
 * works, 503 when one has stopped. Only states and ages — never data or error messages.
 */
class HealthController extends Controller
{
    public function __invoke(HealthCheck $health): JsonResponse
    {
        $checks = $health->checks();
        $ok = $health->healthy($checks);

        return response()->json(['status' => $ok ? 'ok' : 'fail', 'checks' => $checks], $ok ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
