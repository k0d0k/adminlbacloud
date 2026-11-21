<?php

use App\Http\Controllers\Principal\AiWalletController;
use App\Http\Controllers\Principal\AnalyticsController;
use App\Http\Controllers\Principal\AuditLogController;
use App\Http\Controllers\Principal\BrandController;
use App\Http\Controllers\Principal\BrandTenantController;
use App\Http\Controllers\Principal\CrudGeneratorController;
use App\Http\Controllers\Principal\FeatureAccessController;
use App\Http\Controllers\Principal\GlobalSettingsController;
use App\Http\Controllers\Principal\MenuController;
use App\Http\Controllers\Principal\ModulePackageController;
use App\Http\Controllers\Principal\PaymentGatewayController;
use App\Http\Controllers\Principal\SinaController;
use App\Http\Controllers\Principal\SiteController;
use App\Http\Controllers\Principal\SiteMediaController;
use App\Http\Controllers\Principal\SubscriptionPlanController;
use App\Http\Controllers\Principal\TenantController;
use App\Http\Controllers\Principal\TenantRelationController;
use App\Http\Controllers\Principal\UserController;
use App\Http\Middleware\EnsurePrincipalPanelAccess;
use App\Models\Brand;
use App\Models\Tenant;
use App\Platform\Access\PermissionKey;
use App\Platform\Crud\BrandCrudDefinition;
use App\Platform\Crud\Generated\BrandCrud;
use Illuminate\Support\Facades\Route;

Route::prefix('principal')->name('principal.tailadmin.')
    ->middleware(['auth', 'auth.session', EnsurePrincipalPanelAccess::class])
    ->group(function (): void {
        Route::prefix('master-brands')->name('brands.')->controller(BrandController::class)
            ->group(function (): void {
                Route::get('/', 'index')->can('viewAny', Brand::class)->name('index');
                Route::get('/{brand}/report-identity', 'reportIdentity')
                    ->can('update', 'brand')->name('report-identity');
                Route::patch('/{brand}/report-identity', 'updateReportIdentity')
                    ->can('update', 'brand')->name('report-identity.update');

                if (in_array('create', BrandCrud::OPTIONS['actions'], true)) {
                    Route::get('/create', 'create')->can('create', Brand::class)->name('create');
                    Route::post('/', 'store')->can('create', Brand::class)->name('store');
                }

                if (in_array('edit', BrandCrud::OPTIONS['actions'], true)) {
                    Route::get('/{brand}/edit', 'edit')->can('update', 'brand')->name('edit');
                    Route::patch('/{brand}', 'update')->can('update', 'brand')->name('update');
                }

                if (in_array('change_status', BrandCrud::OPTIONS['actions'], true)) {
                    Route::patch('/{brand}/status', 'status')->can('update', 'brand')->name('status');
                }

                // "Hapus Brand" (v3.3) — narrower than edit: only an active
                // Principal Super Admin may remove a brand (BrandPolicy::delete).
                Route::delete('/{brand}', 'destroy')->can('delete', 'brand')->name('destroy');
            });

        Route::prefix('master-brands/{brand}/tenants')->name('brands.tenants.')
            ->can('update', 'brand')->controller(BrandTenantController::class)
            ->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'attach')->name('attach');
                Route::patch('/{tenant}/primary', 'primary')->name('primary');
                Route::delete('/{tenant}', 'detach')->name('detach');
                Route::post('/{tenant}/open', 'open')->name('open');
            });

        Route::prefix('master-tenants')->name('tenants.')->controller(TenantController::class)
            ->group(function (): void {
                Route::get('/', 'index')->can('viewAny', Tenant::class)->name('index');
                Route::get('/create', 'create')->can('create', Tenant::class)->name('create');
                Route::post('/', 'store')->can('create', Tenant::class)->name('store');
                Route::get('/{tenant}/edit', 'edit')->can('update', 'tenant')->name('edit');
                Route::patch('/{tenant}', 'update')->can('update', 'tenant')->name('update');
                Route::post('/{tenant}/setup', 'setup')->can('update', 'tenant')->name('setup');
                Route::post('/{tenant}/provision', 'provision')->can('update', 'tenant')->name('provision');
                Route::post('/{tenant}/open', 'open')->can('update', 'tenant')->name('open');

                // "Hapus Mitra" (v3.3) — narrower than edit: only an active
                // Principal Super Admin may remove a partner (TenantPolicy::delete).
                Route::delete('/{tenant}', 'destroy')->can('delete', 'tenant')->name('destroy');
            });

        Route::prefix('global-settings')->name('settings.')
            ->middleware('can:'.PermissionKey::PLATFORM_RBAC_MANAGE)
            ->controller(GlobalSettingsController::class)
            ->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::patch('/general', 'updateGeneral')->name('general.update');
                Route::patch('/hosting', 'updateCpanel')->name('hosting.update');
                Route::post('/hosting/test', 'testConnection')->name('hosting.test');
            });

        // Phase 14 — Trusted module distribution: browse the trusted registry,
        // upload an approved signed package (never executed), and activate or
        // roll back a package per tenant. Gated on the platform module
        // management capability. The URI is `module-registry` (not `modules`)
        // because the Filament Principal panel still serves its own Module
        // Manager page at `principal/modules`; sharing that URI would make
        // Filament skip registering `filament.principal.pages.modules` and
        // break its sidebar.
        Route::prefix('module-registry')->name('modules.')
            ->middleware('can:'.PermissionKey::PLATFORM_MODULE_MANAGE)
            ->controller(ModulePackageController::class)
            ->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::post('/packages', 'store')->name('packages.store');
                Route::post('/packages/{package}/activate', 'activate')
                    ->where('package', '[0-9A-Za-z]{26}')->name('packages.activate');
                Route::post('/packages/{package}/rollback', 'rollback')
                    ->where('package', '[0-9A-Za-z]{26}')->name('packages.rollback');
            });

        // Phase 5 — Principal governance surfaces migrated from the interim
        // Filament screens to TailAdmin: Paket Langganan, Penggunaan & Token AI,
        // Hak Akses Fitur and Penyedia Pembayaran. The URIs deliberately differ
        // from the Filament slugs (subscription-plans / ai-wallets /
        // feature-access / payment-gateways) so Filament keeps registering its
        // own pages and neither surface shadows the other. Every action
        // re-derives authorization server side through the same principal
        // authorizer the sidebar and Gates use.
        Route::prefix('subscriptions')->name('subscriptions.')
            ->controller(SubscriptionPlanController::class)
            ->group(function (): void {
                Route::get('/', 'index')->name('index');

                Route::middleware('can:'.PermissionKey::PLATFORM_PLAN_MANAGE)->group(function (): void {
                    Route::get('/create', 'create')->name('create');
                    Route::post('/', 'store')->name('store');
                    Route::get('/{plan}/edit', 'edit')->name('edit');
                    Route::patch('/{plan}', 'update')->name('update');
                    Route::patch('/{plan}/archive', 'archive')->name('archive');
                    Route::patch('/{plan}/reactivate', 'reactivate')->name('reactivate');
                });
            });

        Route::prefix('ai-credits')->name('ai-credits.')
            ->controller(AiWalletController::class)
            ->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::post('/{wallet}/grant', 'grant')
                    ->middleware('can:'.PermissionKey::PLATFORM_BILLING_MANAGE)->name('grant');
            });

        // Workstream 2 — Principal "Integrasi SINA AI": the SINA provider
        // endpoint per partner, the chat token/price policy and the read-only
        // usage register. Viewing requires `platform.billing.manage` OR
        // `platform.analytics.view` (re-checked in the controller); both writes
        // require `platform.billing.manage`. The URI is `sina` — the Filament
        // Principal panel serves its own provider page at `ai-providers`, so the
        // two never shadow one another.
        Route::prefix('sina')->name('sina.')
            ->controller(SinaController::class)
            ->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::middleware('can:'.PermissionKey::PLATFORM_BILLING_MANAGE)->group(function (): void {
                    Route::patch('/billing', 'updateBilling')->name('billing.update');
                    Route::post('/providers', 'saveProvider')->name('providers.save');
                });
            });

        Route::prefix('entitlements')->name('entitlements.')
            ->middleware('can:'.PermissionKey::PLATFORM_ENTITLEMENT_MANAGE)
            ->controller(FeatureAccessController::class)
            ->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::post('/{tenant}/module-grant', 'grant')->name('grant');
                Route::post('/{tenant}/module-revoke', 'revoke')->name('revoke');
                Route::patch('/{tenant}/members/{membership}/roles', 'saveRoles')->name('roles');
            });

        Route::prefix('payment-providers')->name('payment-providers.')
            ->middleware('can:'.PermissionKey::PLATFORM_PAYMENT_MANAGE)
            ->controller(PaymentGatewayController::class)
            ->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::get('/create', 'create')->name('create');
                Route::post('/', 'store')->name('store');
                Route::get('/{gateway}/edit', 'edit')->name('edit');
                Route::patch('/{gateway}', 'update')->name('update');
                Route::patch('/{gateway}/verify', 'verify')->name('verify');
                Route::patch('/{gateway}/unverify', 'unverify')->name('unverify');
            });

        // WS4.3 / WS4.4 — the last two governance surfaces migrated from the
        // interim Filament screens to TailAdmin: Analitik Bisnis (read-only,
        // non-clinical platform figures) and Audit Sistem (the read-only,
        // redacted central audit trail). The URIs deliberately differ from the
        // Filament slugs (`analytics` / `audit-logs`) so Filament keeps
        // registering its own pages and neither surface shadows the other. Both
        // actions re-derive authorization server side through the same principal
        // authorizer the sidebar and Gates use.
        Route::prefix('business-analytics')->name('analytics.')
            ->controller(AnalyticsController::class)
            ->group(function (): void {
                Route::get('/', 'index')->name('index');
            });

        Route::prefix('system-audit')->name('audit.')
            ->controller(AuditLogController::class)
            ->group(function (): void {
                Route::get('/', 'index')->name('index');
            });

        // Phase D — TailAdmin parity for the Filament tenant relation managers
        // (memberships, subscriptions, brand links). Gated on the same
        // `update` tenant policy the Filament "Edit tenant" page used.
        Route::prefix('master-tenants/{tenant}/relations')->name('tenants.')
            ->can('update', 'tenant')->controller(TenantRelationController::class)
            ->group(function (): void {
                Route::get('/', 'relations')->name('relations');

                Route::post('/members/owner', 'assignOwner')->name('members.owner');
                Route::post('/members/invite', 'inviteMember')->name('members.invite');
                Route::patch('/members/{membership}/roles', 'updateMemberRoles')->name('members.roles');
                Route::patch('/members/{membership}/status', 'updateMemberStatus')->name('members.status');
                Route::post('/members/{membership}/password-reset', 'sendPasswordReset')->name('members.password-reset');

                Route::post('/subscriptions', 'subscribe')->name('subscriptions.store');
                Route::patch('/subscriptions/{subscription}/activate', 'activateSubscription')->name('subscriptions.activate');
                Route::patch('/subscriptions/{subscription}/cancel', 'cancelSubscription')->name('subscriptions.cancel');

                Route::post('/brands', 'attachBrand')->name('brands.attach');
                Route::patch('/brands/{brand}/primary', 'primaryBrand')->name('brands.primary');
                Route::delete('/brands/{brand}', 'detachBrand')->name('brands.detach');

                // Phase D — per-partner custom domains. The same tenant `update`
                // policy that gates this group governs every write, and each
                // hostname is resolved THROUGH the partner so one partner can
                // never mutate another's binding.
                Route::post('/domains', 'storeDomain')->name('domains.store');
                Route::patch('/domains/{tenantDomain}/verify', 'verifyDomain')->name('domains.verify');
                Route::patch('/domains/{tenantDomain}/primary', 'setPrimaryDomain')->name('domains.primary');
                Route::delete('/domains/{tenantDomain}', 'destroyDomain')->name('domains.destroy');

                // Database Tenant — restricted to the Principal Super Admin
                // capability, both here (route gate) and in the controller.
                Route::middleware('can:'.PermissionKey::PLATFORM_RBAC_MANAGE)
                    ->prefix('database')->name('database.')
                    ->group(function (): void {
                        Route::post('/credentials', 'saveDatabaseCredentials')->name('credentials');
                        Route::post('/test', 'testDatabaseConnection')->name('test');
                        Route::post('/migrate', 'migrateDatabase')->name('migrate');
                    });
            });

        // Administrative credential management across EVERY level: a principal
        // holding the access-management capability may set a new password or
        // trigger a reset link for any identity — platform principal, tenant
        // staff of any role, or patient. Gated the same way as the tenant
        // database block and re-checked server-side in the controller.
        Route::prefix('users')->name('users.')
            ->middleware('can:'.PermissionKey::PLATFORM_RBAC_MANAGE)
            ->controller(UserController::class)
            ->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::patch('/{user}/roles', 'updateRoles')->name('roles');
                Route::patch('/{user}/password', 'setPassword')->name('password');
                Route::post('/{user}/password-reset', 'sendReset')->name('password-reset');
            });

        Route::prefix('crud-generator')->name('crud.')
            ->middleware('can:'.BrandCrudDefinition::ABILITY)
            ->controller(CrudGeneratorController::class)
            ->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::post('/preview', 'preview')->name('preview');
                Route::post('/generate', 'generate')->name('generate');
                Route::post('/export', 'export')->name('export');
            });

        // Menu Dinamis — the per-group navigation builder. The Principal
        // composes the sidebar per panel and per group, nests entries with
        // drag-and-drop and re-derives every rule on the server side. Gated on
        // the platform access-management capability because a menu entry is an
        // access surface, not a cosmetic one.
        Route::prefix('menus')->name('menus.')
            ->middleware('can:'.PermissionKey::PLATFORM_RBAC_MANAGE)
            ->controller(MenuController::class)
            ->group(function (): void {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::post('/reorder', 'reorder')->name('reorder');
                Route::patch('/{menu}', 'update')->name('update');
                Route::delete('/{menu}', 'destroy')->name('destroy');
            });

        // Website CMS — Principal edits the LBA Cloud public frontpage.
        // Stored in the central database so the anonymous landing page never
        // opens a tenant connection. Gated on platform.site.manage.
        Route::prefix('website')->name('site.')
            ->middleware('can:'.PermissionKey::PLATFORM_SITE_MANAGE)
            ->controller(SiteController::class)
            ->group(function (): void {
                Route::get('/', 'edit')->name('edit');
                Route::post('/draft', 'updateDraft')->name('draft');
                Route::post('/publish', 'publish')->name('publish');
                Route::post('/restore', 'restore')->name('restore');

                // CMS media — upload/remove the platform frontpage's own images.
                // The asset is written to the dedicated, non web-served CMS disk
                // and is read back only through the published-gated media route.
                Route::post('/media', [SiteMediaController::class, 'store'])->name('media.store');
                Route::delete('/media/{media}', [SiteMediaController::class, 'destroy'])
                    ->where('media', '[0-9A-Za-z]{26}')->name('media.destroy');
            });
    });
