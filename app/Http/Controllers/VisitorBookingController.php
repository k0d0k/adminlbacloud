<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Site\Services\BookingRequestOutcome;
use App\Platform\Site\Services\VisitorBooking;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Appointments\Models\Appointment;

/**
 * Website CMS — public visitor booking from a clinic frontpage (Task 11).
 *
 * The form is public and tenant-scoped: the clinic is resolved from the URL
 * slug, never trusted from the body, and only PUBLIC scheduling data (branches,
 * services, free slots) is shown — never any medical data. Submitting records a
 * `booking_requests` row in the tenant database and emails a verification link;
 * the request only becomes a real appointment later, in the patient portal.
 *
 * A patient who is ALREADY signed in with a membership in this clinic is sent
 * straight into the portal's existing booking flow instead of the public form.
 */
final class VisitorBookingController extends Controller
{
    public function __construct(
        private readonly VisitorBooking $booking,
    ) {}

    public function show(Request $request, string $slug): View|RedirectResponse
    {
        $tenant = $this->resolveTenant($slug);

        if ($this->signedInPatient($request, $tenant)) {
            return redirect()->to('/patient/booking?tenant='.$tenant->slug);
        }

        $branches = $this->booking->activeBranches($tenant);

        $requestedBranch = (string) $request->query('branch', '');
        $selectedBranchId = $branches->contains('id', $requestedBranch)
            ? $requestedBranch
            : (string) $branches->first()?->getKey();

        $requestedDate = (string) $request->query('date', '');
        $selectedDate = $requestedDate !== '' && strtotime($requestedDate) !== false
            ? Carbon::parse($requestedDate)->toDateString()
            : now()->addDay()->toDateString();

        $slots = $selectedBranchId === ''
            ? []
            : $this->booking->availableSlots($tenant, $selectedBranchId, Carbon::parse($selectedDate));

        return view('site.booking', [
            'tenant' => $tenant,
            'branches' => $branches,
            'services' => Appointment::SERVICES,
            'slots' => $slots,
            'selectedBranchId' => $selectedBranchId,
            'selectedDate' => $selectedDate,
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request, string $slug): RedirectResponse
    {
        $tenant = $this->resolveTenant($slug);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'branch_id' => ['required', 'string'],
            'service_type' => ['required', 'string', 'in:'.implode(',', Appointment::SERVICES)],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'privacy' => ['accepted'],
        ]);

        $outcome = $this->booking->request(
            tenant: $tenant,
            name: $data['full_name'],
            email: $data['email'],
            phone: $data['phone'] ?? null,
            branchId: $data['branch_id'],
            serviceType: $data['service_type'],
            scheduledAt: Carbon::parse($data['scheduled_at']),
        );

        if ($outcome->status === BookingRequestOutcome::STATUS_SLOT_UNAVAILABLE) {
            return back()->withInput()->withErrors(['scheduled_at' => __('booking.errors.slot_unavailable')]);
        }

        if ($outcome->status === BookingRequestOutcome::STATUS_INVALID_BRANCH) {
            return back()->withInput()->withErrors(['branch_id' => __('booking.errors.branch')]);
        }

        return redirect()
            ->to('/klinik/'.$tenant->slug.'/booking')
            ->with('status', __('booking.pending'));
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
     * True when the visitor is a signed-in PATIENT with an ACTIVE membership in
     * this clinic — they should continue in the portal, not the public form.
     */
    private function signedInPatient(Request $request, Tenant $tenant): bool
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_active || $user->role !== RoleKey::PATIENT_CUSTOMER) {
            return false;
        }

        return TenantMembership::query()
            ->where('user_id', $user->getKey())
            ->where('tenant_id', $tenant->getKey())
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->exists();
    }
}
