<?php

namespace App\Platform\Tenancy\Domain\Contracts;

use App\Models\Tenant;

/**
 * Phase D — resolves a request hostname to the partner it belongs to.
 *
 * Resolution is CENTRAL identity lookup only. A successful resolution returns a
 * Tenant the host is bound to; it grants NOTHING by itself — no session, no
 * membership, no data access. Callers must still authorize every operation
 * against the central membership tables. Implementations fail closed: an
 * unknown, unverified or suspended binding resolves to null.
 */
interface ResolvesTenantByDomain
{
    /**
     * The ACTIVE partner a verified, custom hostname is bound to, or null.
     *
     * Accepts a raw host (scheme/port/path tolerated) and normalises it.
     */
    public function resolveHost(?string $host): ?Tenant;

    /**
     * Every VERIFIED hostname currently bound to the given partner.
     *
     * @return list<string>
     */
    public function verifiedDomainsFor(string $tenantId): array;
}
