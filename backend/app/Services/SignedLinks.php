<?php

namespace App\Services;

use Illuminate\Support\Facades\URL;

/**
 * Emailed one-click actions. The API route is signed (relative, so it survives proxies)
 * and the email links to the SPA page /link, which performs it against the API.
 */
class SignedLinks
{
    public static function make(string $route, array $params, int $days = 7): string
    {
        $path = URL::temporarySignedRoute($route, now()->addDays($days), $params, absolute: false);

        return rtrim(config('app.frontend_url'), '/').'/link?to='.rawurlencode($path);
    }
}
