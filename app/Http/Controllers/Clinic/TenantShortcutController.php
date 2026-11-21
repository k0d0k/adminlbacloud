<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PublicSiteController;
use App\Models\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Friendly per-clinic frontpage shortcut: a single-segment path such as
 * `/nubiom` serves that partner's PUBLIC frontpage (the same content as
 * `/klinik/{slug}`), complete with the staff/patient login CTAs.
 *
 * This is the canonical, easy-to-share URL for a clinic. It is registered as
 * a fallback so it never shadows platform entry points (/principal, /clinic,
 * /patient, /login, ...).
 *
 * Security contract:
 *  - only an ACTIVE tenant's slug resolves (suspended / soft-deleted = 404);
 *  - the slug is identity lookup only — the frontpage renders public content
 *    from the tenant's isolated DB and never opens a session or membership;
 *  - authentication still goes through the trusted `/login?tenant={slug}`
 *    flow (the frontpage CTA), which re-validates membership after sign-in.
 */
final class TenantShortcutController extends Controller
{
    public function __invoke(Request $request, PublicSiteController $site): View
    {
        $path = trim($request->path(), '/');

        // A shortcut is a single path segment. Multi-segment or empty paths
        // fall through to the normal 404 handling.
        if ($path === '' || str_contains($path, '/')) {
            abort(404);
        }

        // Reject anything that is not a real, active tenant slug. PublicSite
        // does its own active-check, but doing it here keeps the fallback
        // from matching platform-like slugs that happen to be inactive.
        $tenant = Tenant::query()
            ->where('slug', $path)
            ->where('status', Tenant::STATUS_ACTIVE)
            ->first();

        if (! $tenant instanceof Tenant) {
            abort(404);
        }

        // Render the same public frontpage as /klinik/{slug} — including the
        // staff and patient login buttons. No session, no membership, no
        // clinical data: only published public content.
        return $site->showTenant($request, $tenant->slug);
    }
}
