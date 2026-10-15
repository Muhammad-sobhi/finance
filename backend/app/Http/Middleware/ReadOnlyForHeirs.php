<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Heirs get read-only access to everything. Enforced on the API, not only in the UI.
 */
class ReadOnlyForHeirs
{
    /** Routes an heir may still call with a write method: their own session, display prefs and password. */
    private const ALLOWED = ['auth.logout', 'auth.me.update', 'auth.password', 'auth.logout-others'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isHeir()
            && ! $request->isMethodSafe()
            && ! in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return response()->json(['message' => 'Read-only access.', 'code' => 'read_only'], 403);
        }

        return $next($request);
    }
}
