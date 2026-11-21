<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PermissionKey;
use App\Platform\Site\Models\Tenant\SiteMedia as TenantSiteMedia;
use App\Platform\Site\SiteMediaStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Clinic (tenant) website CMS media (TailAdmin).
 *
 * Only a Tenant Owner / Clinic Admin holding `tenant.site.manage` for the
 * CURRENT tenant may upload or remove that clinic's frontpage media. Assets live
 * in the tenant's own isolated database, so a media id belonging to another
 * tenant simply does not resolve here — a foreign admin can never delete it.
 */
final class SiteMediaController extends Controller
{
    public function __construct(
        private readonly ChecksPermissions $permissions,
        private readonly ManagesTenantContext $tenants,
    ) {}

    public function store(Request $request, SiteMediaStore $store): RedirectResponse
    {
        $tenantId = $this->authorizeMedia($request);

        $data = $request->validate($this->rules($store));

        try {
            $store->storeTenant($data['media'], $tenantId, $this->actor($request), $data['alt_text'] ?? null);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['media' => __('clinic.site.media.invalid')]);
        }

        return back()->with('success', __('clinic.site.media.uploaded'));
    }

    public function destroy(Request $request, string $media, SiteMediaStore $store): RedirectResponse
    {
        $this->authorizeMedia($request);

        // The query runs on the CURRENT tenant's connection; a media row owned by
        // another tenant can never be reached from here (fail-closed 404).
        $asset = TenantSiteMedia::query()->whereKey($media)->first();

        abort_if($asset === null, 404);

        $store->deleteTenant($asset);

        return back()->with('success', __('clinic.site.media.deleted'));
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rules(SiteMediaStore $store): array
    {
        return [
            'media' => [
                'required',
                'file',
                'mimes:'.implode(',', $store->allowedExtensions()),
                'mimetypes:'.implode(',', $store->allowedMimes()),
                'max:'.$store->maxKilobytes(),
            ],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function authorizeMedia(Request $request): string
    {
        $user = $this->actor($request);
        abort_unless($user !== null, 403);

        $tenantId = $this->tenants->currentTenantId($user);
        abort_unless($tenantId !== null, 403);

        abort_unless(
            $this->permissions->allows($user, PermissionKey::TENANT_SITE_MANAGE, AccessContext::forTenant($tenantId)),
            403,
        );

        return $tenantId;
    }

    private function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
