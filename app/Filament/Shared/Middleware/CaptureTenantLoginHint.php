<?php

namespace App\Filament\Shared\Middleware;

use App\Platform\Access\TenantLoginHint;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Website CMS (Task 9/10) — capture the `?tenant={slug}` clinic hint from a
 * login-page URL into the session.
 *
 * Runs on the login entry points only (the unified `/login` and the patient
 * panel). The hint is applied later, once the identity has signed in and its
 * memberships can be re-validated — see {@see TenantLoginHint::apply()}.
 * A request without the query parameter is a no-op.
 */
final class CaptureTenantLoginHint
{
    public function __construct(private readonly TenantLoginHint $hint) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->hint->capture($request);

        return $next($request);
    }
}
