<?php

namespace App\Services;

/**
 * The server POSTs to a push subscription's endpoint on every alert, so only the browsers'
 * real push services are accepted — never an internal or arbitrary host (SSRF).
 */
class PushEndpoints
{
    /** Web push services of Chrome/Edge (FCM), Firefox, Windows and Safari. A leading dot matches subdomains. */
    private const HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', '.push.services.mozilla.com',
        '.notify.windows.com', 'web.push.apple.com'];

    public static function allowed(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (($parts['scheme'] ?? null) !== 'https' || isset($parts['port']) || isset($parts['user'])) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        foreach (self::HOSTS as $allowed) {
            if ($host === $allowed || (str_starts_with($allowed, '.') && str_ends_with($host, $allowed))) {
                return true;
            }
        }

        return false;
    }
}
