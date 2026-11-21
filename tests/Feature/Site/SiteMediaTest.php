<?php

namespace Tests\Feature\Site;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\Site\Models\SiteMedia as CentralSiteMedia;
use App\Platform\Site\Models\SitePage as CentralSitePage;
use App\Platform\Site\Models\Tenant\SiteMedia as TenantSiteMedia;
use App\Platform\Site\Models\Tenant\SitePage as TenantSitePage;
use App\Platform\Site\SiteContentPublisher;
use App\Platform\Site\SiteMediaStore;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Website CMS — media storage isolation (Task 12).
 *
 * Proves that CMS media is written to the dedicated, NON web-served `site-media`
 * disk (never a private clinical disk such as `lba`), that the stored object is
 * named from a generated ULID (so a crafted client filename can never traverse
 * the prefix), and that a public read is gated on the owning page being
 * published. Only synthetic identities and clinics are used (DECISIONS.md
 * PRV-01).
 */
class SiteMediaTest extends TestCase
{
    use BuildsAccess, BuildsPrincipalGovernance, BuildsTenantDatabases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    // --------------------------------------------------------------- Helpers

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function inTenant(Tenant $tenant, callable $callback): mixed
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());

        try {
            return $callback();
        } finally {
            $this->tenantDatabases()->forget();
        }
    }

    private function tenantOwner(Tenant $tenant): User
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        return $user;
    }

    private function tenantMedia(Tenant $tenant): TenantSiteMedia
    {
        return $this->inTenant($tenant, fn (): TenantSiteMedia => app(SiteMediaStore::class)->storeTenant(
            UploadedFile::fake()->image('clinic.jpg', 40, 40),
            (string) $tenant->getKey(),
        ));
    }

    private function publishPlatformPage(): void
    {
        $publisher = app(SiteContentPublisher::class);
        $page = $publisher->getOrCreatePage(CentralSitePage::OWNER_PLATFORM);
        $publisher->saveDraft($page, 'id', [['type' => 'hero', 'data' => ['title' => 'Halo']]]);
        $publisher->publish($page, 'id');
    }

    private function publishTenantPage(Tenant $tenant): void
    {
        $this->inTenant($tenant, function (): void {
            $publisher = app(SiteContentPublisher::class);
            $page = $publisher->getOrCreatePage(TenantSitePage::OWNER_TENANT);
            $publisher->saveDraft($page, 'id', [['type' => 'hero', 'data' => ['title' => 'Klinik']]]);
            $publisher->publish($page, 'id');
        });
    }

    // ================================= 1. Platform media (TR-12.1 / TR-12.5)

    /**
     * TR-12.1 + TR-12.5 — a valid image upload is stored on the dedicated CMS
     * disk under the platform prefix and is served publicly ONLY while the
     * owning page is published; before that the public endpoint refuses a guest
     * while an authorised editor may still preview it.
     */
    public function test_a_platform_upload_is_stored_on_the_cms_disk_and_served_only_once_published(): void
    {
        Storage::fake('site-media');
        $actor = $this->principal();

        $this->actingAs($actor)->post(route('principal.tailadmin.site.media.store'), [
            'media' => UploadedFile::fake()->image('banner.jpg', 120, 80),
            'alt_text' => 'Banner utama',
        ])->assertRedirect()->assertSessionHas('success', __('principal.site.media.uploaded'));

        $media = CentralSiteMedia::query()->sole();

        $this->assertSame(SiteMediaStore::OWNER_PLATFORM, $media->owner_type);
        $this->assertSame('site-media', $media->disk);
        $this->assertSame('image/jpeg', $media->mime_type);
        $this->assertStringStartsWith('site/platform/', $media->path);
        // The stored name is a ULID — never the client filename.
        $this->assertMatchesRegularExpression('#^site/platform/[0-9A-Za-z]{26}\.jpg$#', $media->path);
        $this->assertStringNotContainsString('banner', $media->path);
        Storage::disk('site-media')->assertExists($media->path);

        // Draft page → the public read is refused to an anonymous visitor...
        $this->app['auth']->forgetGuards();
        $this->get(route('site.media.show', $media->getKey()))->assertForbidden();

        // ...but the authorised editor may preview it.
        $this->actingAs($actor)->get(route('site.media.show', $media->getKey()))->assertOk();

        // Publishing the page exposes the asset to the public.
        $this->publishPlatformPage();

        $this->app['auth']->forgetGuards();
        $this->get(route('site.media.show', $media->getKey()))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    // ================================= 2. Refusal of non-media (TR-12.2)

    /**
     * TR-12.2 — a non-image file, or one that exceeds the size bound, is
     * refused before anything is written.
     */
    public function test_a_non_image_or_oversized_upload_is_refused(): void
    {
        Storage::fake('site-media');
        $actor = $this->principal();

        $this->actingAs($actor)->post(route('principal.tailadmin.site.media.store'), [
            'media' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
        ])->assertSessionHasErrors('media');

        $this->actingAs($actor)->post(route('principal.tailadmin.site.media.store'), [
            'media' => UploadedFile::fake()->image('huge.jpg', 10, 10)->size(5000),
        ])->assertSessionHasErrors('media');

        $this->assertSame(0, CentralSiteMedia::query()->count());
        $this->assertSame([], Storage::disk('site-media')->allFiles());
    }

    // ================================= 3. Path traversal (TR-12.3)

    /**
     * TR-12.3 — a crafted client filename containing a traversal segment is
     * neutralised: the object is named from a ULID and the serving guard refuses
     * any path that is not the CMS prefix.
     */
    public function test_a_traversal_filename_is_neutralised(): void
    {
        Storage::fake('site-media');
        $actor = $this->principal();

        $this->actingAs($actor)->post(route('principal.tailadmin.site.media.store'), [
            'media' => UploadedFile::fake()->image('../../private/secret.jpg', 30, 30),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $media = CentralSiteMedia::query()->sole();

        $this->assertMatchesRegularExpression('#^site/platform/[0-9A-Za-z]{26}\.jpg$#', $media->path);
        $this->assertStringNotContainsString('..', $media->path);
        $this->assertStringNotContainsString('secret', $media->path);

        $store = app(SiteMediaStore::class);
        $this->assertFalse($store->isServable('site-media', 'site/platform/../../private/secret.jpg'));
        $this->assertFalse($store->isServable('site-media', 'private/secret.jpg'));
    }

    // ================================= 4. Tenant ownership (TR-12.4)

    /**
     * TR-12.4 — a media row owned by another tenant can never be reached from a
     * different clinic: the delete runs on the CURRENT tenant's database, so a
     * foreign id simply does not resolve (fail-closed 404).
     */
    public function test_a_clinic_cannot_delete_another_clinics_media(): void
    {
        Storage::fake('site-media');

        $alpha = Tenant::factory()->create();
        $beta = Tenant::factory()->create();
        $this->provisionTenantDatabase($alpha);
        $this->provisionTenantDatabase($beta);

        $alphaOwner = $this->tenantOwner($alpha);
        $betaOwner = $this->tenantOwner($beta);

        $media = $this->tenantMedia($alpha);
        $alphaMediaId = (string) $media->getKey();

        // Beta's admin attempts to remove Alpha's media.
        $this->actingAs($betaOwner)
            ->delete(route('clinic.tailadmin.site.media.destroy', ['media' => $alphaMediaId]))
            ->assertNotFound();

        // Alpha's row (and its object) are untouched.
        $this->inTenant($alpha, fn () => $this->assertSame(1, TenantSiteMedia::query()->count()));
        Storage::disk('site-media')->assertExists($media->path);

        // Alpha's own admin CAN remove it.
        $this->actingAs($alphaOwner)
            ->delete(route('clinic.tailadmin.site.media.destroy', ['media' => $alphaMediaId]))
            ->assertRedirect();

        $this->inTenant($alpha, fn () => $this->assertSame(0, TenantSiteMedia::query()->count()));
        Storage::disk('site-media')->assertMissing($media->path);
    }

    /**
     * A clinic upload is stored under its own tenant prefix and served through
     * the tenant media route once the clinic page is published.
     */
    public function test_a_clinic_upload_is_served_under_the_tenant_prefix_once_published(): void
    {
        Storage::fake('site-media');

        $tenant = Tenant::factory()->create();
        $this->provisionTenantDatabase($tenant);

        $owner = $this->tenantOwner($tenant);

        $this->actingAs($owner)->post(route('clinic.tailadmin.site.media.store'), [
            'media' => UploadedFile::fake()->image('logo.png', 64, 64),
        ])->assertRedirect()->assertSessionHas('success', __('clinic.site.media.uploaded'));

        $media = $this->inTenant($tenant, fn (): TenantSiteMedia => TenantSiteMedia::query()->sole());

        $this->assertStringStartsWith('site/tenants/'.$tenant->getKey().'/', $media->path);
        $this->assertSame('image/png', $media->mime_type);
        Storage::disk('site-media')->assertExists($media->path);

        // Draft → refused to the public.
        $this->app['auth']->forgetGuards();
        $this->get(route('tenant.site.media.show', ['slug' => $tenant->slug, 'media' => $media->getKey()]))
            ->assertForbidden();

        // Publish → served.
        $this->publishTenantPage($tenant);

        $this->get(route('tenant.site.media.show', ['slug' => $tenant->slug, 'media' => $media->getKey()]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    // ================================= 5. Private disk isolation (TR-12.6)

    /**
     * TR-12.6 — a file recorded on a private clinical disk (`lba`) can never be
     * read through the CMS media endpoint: the serving guard fails closed on any
     * disk that is not the CMS disk.
     */
    public function test_a_private_clinical_file_cannot_be_read_through_the_cms_endpoint(): void
    {
        Storage::fake('site-media');
        Storage::fake('lba');

        Storage::disk('lba')->put('reports/secret.png', 'private-clinical-bytes');

        $media = CentralSiteMedia::query()->create([
            'owner_type' => SiteMediaStore::OWNER_PLATFORM,
            'owner_id' => null,
            'disk' => 'lba',
            'path' => 'reports/secret.png',
            'mime_type' => 'image/png',
            'size_bytes' => 22,
        ]);

        // Even with the owning page published, the private disk is not servable.
        $this->publishPlatformPage();

        $this->get(route('site.media.show', $media->getKey()))->assertNotFound();

        $store = app(SiteMediaStore::class);
        $this->assertFalse($store->isServable('lba', 'reports/secret.png'));
        $this->assertFalse($store->isServable('site-media', 'site/platform/missing.png'));
    }
}
