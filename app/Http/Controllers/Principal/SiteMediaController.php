<?php

namespace App\Http\Controllers\Principal;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Access\PermissionKey;
use App\Platform\Site\Models\SiteMedia as CentralSiteMedia;
use App\Platform\Site\SiteMediaStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Principal website CMS media (TailAdmin).
 *
 * Only an active Principal holding `platform.site.manage` may upload or remove
 * LBA Cloud frontpage media. The asset is written to the dedicated, non
 * web-served CMS disk — never a private clinical disk — and is read back only
 * through the published-gated public media controller.
 */
final class SiteMediaController extends Controller
{
    use AuthorizesPrincipalResources;

    public function store(Request $request, SiteMediaStore $store): RedirectResponse
    {
        $this->authorizeMedia();

        $data = $request->validate($this->rules($store));

        try {
            $store->storePlatform($data['media'], $this->actor(), $data['alt_text'] ?? null);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['media' => __('principal.site.media.invalid')]);
        }

        return back()->with('success', __('principal.site.media.uploaded'));
    }

    public function destroy(Request $request, string $media, SiteMediaStore $store): RedirectResponse
    {
        $this->authorizeMedia();

        $asset = CentralSiteMedia::query()
            ->where('owner_type', SiteMediaStore::OWNER_PLATFORM)
            ->whereKey($media)
            ->first();

        abort_if($asset === null, 404);

        $store->deletePlatform($asset);

        return back()->with('success', __('principal.site.media.deleted'));
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

    private function authorizeMedia(): void
    {
        abort_unless(self::principalCan(PermissionKey::PLATFORM_SITE_MANAGE), 403);
    }

    private function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
