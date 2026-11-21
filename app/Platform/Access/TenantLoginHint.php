<?php

namespace App\Platform\Access;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use Illuminate\Http\Request;

/**
 * Website CMS (Task 9/10) — carry a clinic context from the public frontpage
 * through the login flow.
 *
 * A frontpage CTA links to `/login?tenant={slug}` or `/patient/login?tenant={slug}`.
 * The click is a hint, NEVER an authorization: it is captured on the login page
 * (while the visitor is still unauthenticated), then re-validated against the
 * signed-in identity's own ACTIVE memberships before it is ever applied. An
 * unknown, suspended or foreign clinic simply produces no context — the identity
 * still falls through to the existing single-membership auto-select or the
 * explicit "choose a clinic" screen.
 *
 * The hint is always consumed (single-use), so a stale value can never linger in
 * the session and override a later explicit choice.
 */
final class TenantLoginHint
{
    /** Session key holding the requested tenant id, pending sign-in. */
    public const SESSION_KEY = 'lba.tenant_context.login_hint';

    public function __construct(private readonly ManagesTenantContext $context) {}

    /**
     * Remember the clinic requested on the login-page URL, if it is an active
     * clinic. A malformed, unknown or suspended slug is ignored.
     */
    public function capture(Request $request): void
    {
        $slug = $request->query('tenant');

        if (! is_string($slug) || $slug === '') {
            return;
        }

        $tenantId = Tenant::query()
            ->where('slug', $slug)
            ->where('status', Tenant::STATUS_ACTIVE)
            ->value('id');

        if ($tenantId !== null) {
            $request->session()->put(self::SESSION_KEY, (string) $tenantId);
        }
    }

    /**
     * Apply a remembered hint to the signed-in identity.
     *
     * `select()` re-derives eligibility from central data and clears itself when
     * the tenant is not one the identity may enter, so a captured hint can never
     * grant access on its own. The hint is consumed either way.
     */
    public function apply(User $user, Request $request): void
    {
        $pending = $request->session()->pull(self::SESSION_KEY);

        if (is_string($pending) && $pending !== '') {
            $this->context->select($user, $pending);
        }
    }
}
