<?php

namespace App\Platform\Subscriptions;

use App\Models\Tenant;
use App\Platform\Subscriptions\Models\TenantSubscription;

/**
 * Answers whether a tenant currently holds a LIVE subscription, and whether a
 * tenant's subscription has LAPSED.
 *
 * This is the tenant-wide counterpart of
 * {@see \App\Platform\FeatureGate\Providers\DatabaseModuleSubscriptions}, which
 * decides a single module's entitlement from an opaque reference. The Clinic
 * gateway asks this question about the whole tenant so a partner whose period
 * has ended loses the workspace and is pointed at the Principal instead
 * (requirement 3.a.ii).
 *
 * `activeFor()` fails closed on any ambiguity (unknown tenant, no row,
 * cancelled, ended, outside its period, non-live status). `hasLapsed()` is the
 * gateway's trigger and is deliberately narrower: it is true only when the
 * tenant HAS a subscription on file and none of them is live. A tenant with no
 * subscription row at all is not "lapsed" — it is outside the licensing
 * lifecycle and keeps the gateway's existing membership + database contract, so
 * onboarding and legacy partners are unaffected.
 */
final class TenantSubscriptionGate
{
    public function isActive(Tenant|string $tenant): bool
    {
        return $this->activeFor($tenant) instanceof TenantSubscription;
    }

    /**
     * True when a subscription is on file but none is live: the period ended,
     * it was cancelled/expired, or it is still pending activation.
     */
    public function hasLapsed(Tenant|string $tenant): bool
    {
        $tenantId = $this->tenantId($tenant);

        if ($tenantId === null) {
            return false;
        }

        if ($this->isActive($tenantId)) {
            return false;
        }

        return TenantSubscription::query()->where('tenant_id', $tenantId)->exists();
    }

    /**
     * The most recently ending live subscription for the tenant, or null.
     */
    public function activeFor(Tenant|string $tenant): ?TenantSubscription
    {
        $tenantId = $this->tenantId($tenant);

        if ($tenantId === null) {
            return null;
        }

        return TenantSubscription::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('cancelled_at')
            ->whereNull('ended_at')
            ->whereIn('status', [
                TenantSubscription::STATUS_ACTIVE,
                TenantSubscription::STATUS_TRIALING,
            ])
            ->where(function ($query): void {
                $query->whereNull('current_period_end')
                    ->orWhere('current_period_end', '>', now());
            })
            ->orderByDesc('current_period_end')
            ->first();
    }

    private function tenantId(Tenant|string $tenant): ?string
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
    }
}
