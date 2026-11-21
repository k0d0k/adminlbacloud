<?php

namespace App\Platform\Tenancy\Domain;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Platform\Tenancy\Domain\Contracts\ResolvesTenantByDomain;

/**
 * Phase D — the central hostname → partner resolver.
 *
 * A hostname resolves ONLY when every one of these holds:
 *  - the value normalises to a valid hostname;
 *  - a CENTRAL `tenant_domains` row exists for it AND is `verified` with a
 *    non-null `verified_at`;
 *  - the bound partner still exists and is `active` (a suspended or soft-deleted
 *    partner fails closed).
 *
 * Resolution is identity lookup only: it returns the partner a host belongs to
 * and grants no session, membership or data access. Persisting an authorization
 * decision from a resolved host would be a security bug — callers must still
 * re-derive access from the central membership tables.
 */
final class TenantDomainResolver implements ResolvesTenantByDomain
{
    public function resolveHost(?string $host): ?Tenant
    {
        $normalized = TenantDomain::normalizeDomain($host);

        if ($normalized === '' || ! TenantDomain::isValidDomain($normalized)) {
            return null;
        }

        $binding = TenantDomain::query()
            ->verified()
            ->where('domain', $normalized)
            ->first();

        if (! $binding instanceof TenantDomain) {
            return null;
        }

        // `tenant()` honours soft deletes: a soft-deleted partner resolves to
        // null rather than to a stale row.
        $tenant = $binding->tenant;

        if (! $tenant instanceof Tenant || ! $tenant->isActive()) {
            return null;
        }

        return $tenant;
    }

    public function verifiedDomainsFor(string $tenantId): array
    {
        return TenantDomain::query()
            ->verified()
            ->where('tenant_id', $tenantId)
            ->orderBy('domain')
            ->pluck('domain')
            ->values()
            ->all();
    }
}
