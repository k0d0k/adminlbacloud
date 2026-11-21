<?php

namespace App\Platform\Tenancy\Domain;

use App\Platform\Tenancy\Domain\Contracts\ProbesDomainOwnership;

/**
 * Phase D — the default DNS TXT ownership probe.
 *
 * Reads the hostname's TXT records through the system resolver. It NEVER throws
 * and NEVER blocks a request indefinitely beyond the resolver's own timeout: any
 * failure (missing function, NXDOMAIN, network error, malformed answer) yields
 * an empty list, which the caller treats as "not verified" (fail closed).
 */
final class DnsDomainOwnershipProbe implements ProbesDomainOwnership
{
    public function txtRecords(string $domain): array
    {
        $domain = trim($domain);

        if ($domain === '' || ! function_exists('dns_get_record')) {
            return [];
        }

        $records = @dns_get_record($domain, DNS_TXT);

        if (! is_array($records)) {
            return [];
        }

        $values = [];

        foreach ($records as $record) {
            if (isset($record['txt']) && is_string($record['txt'])) {
                $values[] = $record['txt'];

                continue;
            }

            // Long TXT records are split into `entries` on some resolvers.
            if (isset($record['entries']) && is_array($record['entries'])) {
                foreach ($record['entries'] as $entry) {
                    if (is_string($entry)) {
                        $values[] = $entry;
                    }
                }
            }
        }

        return array_values($values);
    }
}
