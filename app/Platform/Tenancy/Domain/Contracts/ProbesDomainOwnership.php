<?php

namespace App\Platform\Tenancy\Domain\Contracts;

/**
 * Phase D — an out-of-band probe of a hostname's published DNS TXT records.
 *
 * Ownership of a custom domain is proven by the operator publishing the
 * verification token as a TXT record on that domain; the application never
 * accepts a self-asserted claim. Kept behind a contract so the DNS lookup can be
 * replaced by a controlled fake in tests and so a host with no resolver simply
 * fails closed (no records ⇒ not verified).
 */
interface ProbesDomainOwnership
{
    /**
     * The TXT record values currently published for the hostname.
     *
     * @return list<string>
     */
    public function txtRecords(string $domain): array;
}
