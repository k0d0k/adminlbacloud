<?php

namespace App\Models;

use Database\Factories\TenantDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Central per-partner custom domain (Phase D, v3.3).
 *
 * A `TenantDomain` is the ONLY place a hostname is bound to a partner. It is
 * central metadata — it holds no clinical data and lives on the central
 * connection, never inside a tenant database.
 *
 * Security contract:
 *  - a hostname can point at exactly one partner (database-level UNIQUE);
 *  - only a `verified` row with a non-null `verified_at` is load-bearing;
 *  - resolving a host to a partner NEVER opens a session, membership or data by
 *    itself — it only tells the application which partner a public host belongs
 *    to. Access is still re-derived from the central membership tables.
 */
#[Fillable([
    'tenant_id',
    'domain',
    'status',
    'verification_token',
    'verified_at',
    'is_primary',
    'last_checked_at',
    'metadata',
])]
class TenantDomain extends Model
{
    /** @use HasFactory<TenantDomainFactory> */
    use HasFactory, HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'is_primary' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED && $this->verified_at !== null;
    }

    /**
     * @param  Builder<TenantDomain>  $query
     * @return Builder<TenantDomain>
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_VERIFIED)->whereNotNull('verified_at');
    }

    /**
     * Normalise a submitted hostname to the stored form: no scheme, no path, no
     * query, no port, no trailing dot, lower-cased. Invalid input yields an empty
     * string so the caller can reject it rather than persist a guessed host.
     */
    public static function normalizeDomain(?string $value): string
    {
        $host = strtolower(trim((string) $value));

        if ($host === '') {
            return '';
        }

        // Drop an optional scheme ("https://") and anything after the authority.
        $host = preg_replace('~^[a-z][a-z0-9+.-]*://~', '', $host) ?? $host;
        $host = preg_replace('~[/?#].*$~', '', $host) ?? $host;

        // Drop a "user@host" prefix and a ":port" suffix (but keep IPv6 brackets).
        if (! str_contains($host, '[')) {
            $host = str_contains($host, '@') ? (explode('@', $host)[1] ?? '') : $host;
            $host = explode(':', $host)[0];
        }

        $host = rtrim($host, '.');

        return $host;
    }

    /**
     * True when the value is an acceptable stored hostname: a dotted name or an
     * IPv4 literal, with no scheme/path/port or whitespace left over.
     */
    public static function isValidDomain(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }

        return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host);
    }
}
