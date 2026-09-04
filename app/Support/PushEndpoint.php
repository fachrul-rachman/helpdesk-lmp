<?php

namespace App\Support;

final class PushEndpoint
{
    public static function allowed(string $endpoint): bool
    {
        $url = parse_url($endpoint);
        if (! $url || ($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || ($url['port'] ?? 443) !== 443 || preg_match('/[\x00-\x20\\\\]/', $endpoint)) {
            return false;
        }

        $host = strtolower($url['host'] ?? '');

        // Only browser-owned push services; never arbitrary URLs supplied by a client.
        return in_array($host, ['fcm.googleapis.com', 'updates.push.services.mozilla.com', 'web.push.apple.com'], true)
            || str_ends_with($host, '.push.services.mozilla.com')
            || str_ends_with($host, '.notify.windows.com');
    }
}
