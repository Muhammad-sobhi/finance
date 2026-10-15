<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every authenticated owner request counts as activity for the dead-man switch.
 * Writes at most once a minute to keep requests cheap.
 */
class TrackActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isOwner() && ($user->last_activity_at === null || $user->last_activity_at->lt(now()->subMinute()))) {
            $user->forceFill(['last_activity_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
