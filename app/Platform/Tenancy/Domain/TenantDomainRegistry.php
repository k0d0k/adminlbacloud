<?php

namespace App\Platform\Tenancy\Domain;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Platform\Tenancy\Domain\Contracts\ProbesDomainOwnership;
use App\Platform\Tenancy\Domain\Exceptions\DomainRegistrationException;
use Illuminate\Support\Str;

/**
 * Phase D — the single write path for per-partner custom domains.
 *
 * Registering a hostname creates a PENDING binding and a random verification
 * token; it NEVER makes the hostname load-bearing by itself. A binding only
 * becomes `verified` once the token is observed out-of-band as a DNS TXT record
 * on that hostname — the application never trusts a self-asserted claim, and an
 * unverified or unclaimable hostname is rejected before anything is persisted.
 */
final class TenantDomainRegistry
{
    public function __construct(private readonly ProbesDomainOwnership $ownership) {}

    /**
     * Register a new PENDING hostname for a partner.
     *
     * @throws DomainRegistrationException
     */
    public function register(Tenant $tenant, string $domain): TenantDomain
    {
        $host = TenantDomain::normalizeDomain($domain);

        if (! TenantDomain::isValidDomain($host)) {
            throw DomainRegistrationException::invalidDomain($domain);
        }

        if ($this->isReserved($host)) {
            throw DomainRegistrationException::reservedDomain($host);
        }

        if (TenantDomain::query()->where('domain', $host)->exists()) {
            throw DomainRegistrationException::alreadyClaimed($host);
        }

        return TenantDomain::query()->create([
            'tenant_id' => $tenant->getKey(),
            'domain' => $host,
            'status' => TenantDomain::STATUS_PENDING,
            'verification_token' => 'lba-verify-'.Str::lower(Str::random(32)),
            'verified_at' => null,
        ]);
    }

    /**
     * Attempt to verify ownership by reading the hostname's published TXT
     * records. On a match the binding becomes `verified`; otherwise the status
     * is recorded as `failed` (or left `verified` when it already was), and the
     * method returns false. Idempotent and retry-safe.
     */
    public function verify(TenantDomain $domain): bool
    {
        $token = trim((string) $domain->verification_token);
        $records = array_map('trim', $this->ownership->txtRecords((string) $domain->domain));
        $matched = $token !== '' && in_array($token, $records, true);

        if ($matched) {
            $domain->forceFill([
                'status' => TenantDomain::STATUS_VERIFIED,
                'verified_at' => $domain->verified_at ?? now(),
                'last_checked_at' => now(),
            ])->save();

            return true;
        }

        // A failed check never DOWNGRADES an already-verified binding.
        $domain->forceFill([
            'status' => $domain->isVerified() ? TenantDomain::STATUS_VERIFIED : TenantDomain::STATUS_FAILED,
            'last_checked_at' => now(),
        ])->save();

        return $domain->isVerified();
    }

    public function remove(TenantDomain $domain): void
    {
        $domain->delete();
    }

    /**
     * A hostname the platform itself uses (or an operator has reserved) may
     * never be claimed by a partner.
     */
    private function isReserved(string $host): bool
    {
        $reserved = array_map(
            static fn (string $value): string => TenantDomain::normalizeDomain($value),
            (array) config('lba.domains.reserved', []),
        );

        $reserved[] = TenantDomain::normalizeDomain((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?? ''));

        return in_array($host, array_filter($reserved), true);
    }
}
