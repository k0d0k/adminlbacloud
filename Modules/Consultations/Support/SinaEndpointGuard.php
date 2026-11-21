<?php

namespace Modules\Consultations\Support;

/**
 * Validates the base URL a SINA AI provider is configured with.
 *
 * A production credential must never be sent in the clear to a PUBLIC host, so
 * HTTPS is always accepted and plain HTTP to a public host is always refused.
 * Plain HTTP is accepted only for a host that is NOT publicly routable:
 *
 *  - loopback (`localhost`, `127.0.0.0/8`, `::1`);
 *  - a private / link-local IP (RFC1918 e.g. a Mac Mini on the clinic LAN);
 *  - a single-label or `.local` (mDNS) host name.
 *
 * This is what lets a clinic host its own model — Open WebUI on a Mac Mini M4 —
 * be reached over the LAN without exposing it to the public internet.
 */
final class SinaEndpointGuard
{
    public static function isAcceptable(string $baseUrl): bool
    {
        $parts = parse_url(trim($baseUrl));

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if ($scheme === 'https') {
            return true;
        }

        if ($scheme !== 'http') {
            return false;
        }

        return self::isLocalOrPrivateHost($host);
    }

    private static function isLocalOrPrivateHost(string $host): bool
    {
        if ($host === 'localhost' || $host === '::1') {
            return true;
        }

        // An mDNS name (`mac-mini.local`) or a bare single-label host
        // (`minimac`) is resolved on the LAN only, never a public DNS name.
        if (! str_contains($host, '.') || str_ends_with($host, '.local')) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        // A valid IP that is NOT private and NOT reserved is publicly routable.
        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
