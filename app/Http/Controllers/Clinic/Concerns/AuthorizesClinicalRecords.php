<?php

namespace App\Http\Controllers\Clinic\Concerns;

use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\FeatureGate\ModuleGate;
use Modules\Patients\Filament\Resources\PatientResource;

/**
 * Shared server-side authorization for clinical-record surfaces (Phases 2–6).
 *
 * A clinical capability is reachable only when BOTH hold:
 *  - the Patient module is active in the Clinic panel for the verified context;
 *  - the acting identity's tenant role grants the canonical clinical permission.
 *
 * Every value is re-derived from trusted services — the tenant from the
 * membership-backed context, the branch from the verified branch context — so a
 * client-supplied id is never the authorization boundary.
 */
trait AuthorizesClinicalRecords
{
    protected function clinicalAllows(string $permission): bool
    {
        $user = request()->user();

        if (! $user instanceof User || ! $user->is_active) {
            return false;
        }

        if (! app(ModuleGate::class)->allows(PatientResource::MODULE_KEY, PanelAccessPolicy::PANEL_CLINIC)) {
            return false;
        }

        $tenantId = app(ManagesTenantContext::class)->currentTenantId($user);

        if ($tenantId === null) {
            return false;
        }

        $branchId = app(ManagesBranchContext::class)->currentBranchId($user);

        return app(ChecksPermissions::class)->allows(
            $user,
            $permission,
            AccessContext::forTenant($tenantId, $branchId),
        );
    }

    protected function authorizeClinical(string $permission): void
    {
        abort_unless($this->clinicalAllows($permission), 403);
    }

    /**
     * Refuse a clinical write aimed at a branch the identity cannot operate in.
     */
    protected function assertBranchAccessible(?string $branchId): void
    {
        if ($branchId === null) {
            return;
        }

        $user = request()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $eligible = array_map(
            static fn ($option): string => $option->id,
            app(ManagesBranchContext::class)->eligibleOptions($user),
        );

        abort_unless(in_array($branchId, $eligible, true), 403);
    }
}
