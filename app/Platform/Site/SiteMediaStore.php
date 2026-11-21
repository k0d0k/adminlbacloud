<?php

namespace App\Platform\Site;

use App\Models\User;
use App\Platform\Site\Models\SiteMedia as CentralSiteMedia;
use App\Platform\Site\Models\Tenant\SiteMedia as TenantSiteMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes/removes website CMS media on a dedicated, NON web-served disk.
 *
 * The file is named from a freshly generated ULID plus an extension derived
 * from the VERIFIED MIME — the client-supplied filename is never used to build
 * the path, so a crafted name such as `../../private/secret.png` cannot escape
 * the prefix. The disk is fixed by configuration and can never point at a
 * private clinical disk (`lba`, `lba-reports`, ...).
 */
final class SiteMediaStore
{
    public const OWNER_PLATFORM = 'platform';

    public const OWNER_TENANT = 'tenant';

    public const PREFIX = 'site/';

    /**
     * Verified-MIME → canonical extension. Anything not listed here is refused.
     *
     * @var array<string, string>
     */
    private const EXTENSION_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/svg+xml' => 'svg',
    ];

    public function disk(): string
    {
        return (string) config('lba.site.media_disk', 'site-media');
    }

    /**
     * @return list<string>
     */
    public function allowedMimes(): array
    {
        return array_values((array) config('lba.site.media_allowed_mimes', []));
    }

    /**
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        return array_values((array) config('lba.site.media_allowed_extensions', []));
    }

    public function maxKilobytes(): int
    {
        return (int) config('lba.site.media_max_kilobytes', 4096);
    }

    public function maxDimension(): int
    {
        return (int) config('lba.site.media_max_dimension', 6000);
    }

    public function platformPrefix(): string
    {
        return (string) config('lba.site.platform_prefix', 'site/platform');
    }

    public function tenantPrefix(string $tenantId): string
    {
        return rtrim((string) config('lba.site.tenant_prefix', 'site/tenants'), '/').'/'.$tenantId;
    }

    /**
     * Store an uploaded file as platform (Principal) CMS media.
     */
    public function storePlatform(UploadedFile $file, ?User $actor = null, ?string $altText = null): CentralSiteMedia
    {
        [$path, $mime] = $this->store($file, $this->platformPrefix());

        return CentralSiteMedia::query()->create([
            'owner_type' => self::OWNER_PLATFORM,
            'owner_id' => null,
            'disk' => $this->disk(),
            'path' => $path,
            'mime_type' => $mime,
            'size_bytes' => (int) $file->getSize(),
            'alt_text' => $altText,
            'created_by' => $actor?->getKey(),
        ]);
    }

    /**
     * Store an uploaded file as tenant (clinic) CMS media.
     */
    public function storeTenant(UploadedFile $file, string $tenantId, ?User $actor = null, ?string $altText = null): TenantSiteMedia
    {
        [$path, $mime] = $this->store($file, $this->tenantPrefix($tenantId));

        return TenantSiteMedia::query()->create([
            'disk' => $this->disk(),
            'path' => $path,
            'mime_type' => $mime,
            'size_bytes' => (int) $file->getSize(),
            'alt_text' => $altText,
            'created_by' => $actor?->getKey(),
        ]);
    }

    /**
     * Delete a platform media row together with its stored object.
     */
    public function deletePlatform(CentralSiteMedia $media): void
    {
        Storage::disk($media->disk)->delete($media->path);
        $media->delete();
    }

    /**
     * Delete a tenant media row together with its stored object.
     */
    public function deleteTenant(TenantSiteMedia $media): void
    {
        Storage::disk($media->disk)->delete($media->path);
        $media->delete();
    }

    /**
     * True only when the object is a CMS media object: it lives on the CMS disk,
     * under the CMS prefix, has no traversal segment, and actually exists.
     *
     * Fails closed for a row (or request) that names a private clinical disk,
     * so the CMS surface can never be used to read `lba`/`lba-reports` files.
     */
    public function isServable(string $disk, string $path): bool
    {
        if ($disk !== $this->disk()) {
            return false;
        }

        if (! str_starts_with($path, self::PREFIX)) {
            return false;
        }

        if (str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }

        return Storage::disk($disk)->exists($path);
    }

    /**
     * @return array{0: string, 1: string} The stored path and the verified MIME.
     */
    private function store(UploadedFile $file, string $prefix): array
    {
        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());

        if (! in_array($mime, $this->allowedMimes(), true)) {
            throw new RuntimeException('unsupported_media_type');
        }

        $extension = $this->extensionFor($mime, $file);

        $this->guardDimensions($file, $mime);

        // ULID name — never the client filename — so no traversal is possible.
        $name = (string) Str::ulid().'.'.$extension;

        $path = $file->storeAs($prefix, $name, ['disk' => $this->disk()]);

        if (! is_string($path)) {
            throw new RuntimeException('media_store_failed');
        }

        return [$path, $mime];
    }

    private function extensionFor(string $mime, UploadedFile $file): string
    {
        if (isset(self::EXTENSION_BY_MIME[$mime])) {
            return self::EXTENSION_BY_MIME[$mime];
        }

        // Some finfo builds report an SVG as text/plain or text/xml; accept the
        // declared `svg` extension in that one case, still bounded by the
        // whitelist.
        $clientExtension = strtolower((string) $file->getClientOriginalExtension());

        if ($clientExtension === 'svg' && in_array('svg', $this->allowedExtensions(), true)) {
            return 'svg';
        }

        throw new RuntimeException('unsupported_media_type');
    }

    /**
     * Guard decompression bombs: a raster image may not exceed the configured
     * dimension. SVG is vector, so it is exempt from the pixel bound.
     */
    private function guardDimensions(UploadedFile $file, string $mime): void
    {
        if ($mime === 'image/svg+xml') {
            return;
        }

        $size = @getimagesize($file->getRealPath());

        if ($size === false) {
            throw new RuntimeException('unsupported_media_type');
        }

        $max = $this->maxDimension();

        if (($size[0] ?? 0) > $max || ($size[1] ?? 0) > $max) {
            throw new RuntimeException('media_dimensions_exceeded');
        }
    }
}
