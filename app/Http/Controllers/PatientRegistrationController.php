<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Branches\Models\TenantBranch;
use App\Platform\Site\Services\PatientRegistrationOutcome;
use App\Platform\Site\Services\PatientSelfRegistration;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rules\Password;

/**
 * Website CMS — public patient registration from a clinic frontpage (Task 8).
 *
 * The form is public and tenant-scoped: the clinic is resolved from the URL
 * slug, never trusted from the body. Registration provisions a central identity
 * + membership and a tenant patient row, then requires email verification
 * before the portal opens. The response is GENERIC so it never reveals whether
 * an email already has an account.
 */
final class PatientRegistrationController extends Controller
{
    public function __construct(
        private readonly PatientSelfRegistration $registration,
        private readonly ResolvesTenantDatabase $databases,
    ) {}

    public function show(Request $request, string $slug): View
    {
        $tenant = $this->resolveTenant($slug);

        return view('site.register', [
            'tenant' => $tenant,
            'branches' => $this->activeBranches($tenant),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request, string $slug): RedirectResponse
    {
        $tenant = $this->resolveTenant($slug);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
            'privacy' => ['accepted'],
        ]);

        $outcome = $this->registration->register(
            tenant: $tenant,
            name: $data['name'],
            email: $data['email'],
            phone: $data['phone'] ?? null,
            password: $data['password'],
            actor: $request->user() instanceof User ? $request->user() : null,
        );

        if ($outcome->status === PatientRegistrationOutcome::STATUS_EMAIL_MISMATCH) {
            return back()
                ->withInput()
                ->withErrors(['email' => __('site.registration.email_mismatch')]);
        }

        if ($outcome->status === PatientRegistrationOutcome::STATUS_MEMBERSHIP_UNAVAILABLE) {
            return back()
                ->withInput()
                ->withErrors(['email' => __('site.registration.membership_unavailable')]);
        }

        // Existing account: send the visitor to sign in. The message is
        // identical to the success path, so account existence is never revealed.
        return redirect()
            ->to('/klinik/'.$tenant->slug.'/daftar')
            ->with('status', __('site.registration.check_email'));
    }

    private function resolveTenant(string $slug): Tenant
    {
        $tenant = Tenant::query()
            ->where('slug', $slug)
            ->where('status', Tenant::STATUS_ACTIVE)
            ->first();

        if ($tenant === null) {
            abort(404);
        }

        return $tenant;
    }

    /**
     * Public branch list for the clinic, read from the tenant database and
     * purged afterwards. Only public metadata — never clinical data.
     *
     * @return Collection<int, TenantBranch>
     */
    private function activeBranches(Tenant $tenant): Collection
    {
        try {
            $this->databases->configure((string) $tenant->getKey());

            return TenantBranch::query()->active()->orderBy('name')->get();
        } catch (\Throwable) {
            return collect();
        } finally {
            $this->databases->forget();
        }
    }
}
