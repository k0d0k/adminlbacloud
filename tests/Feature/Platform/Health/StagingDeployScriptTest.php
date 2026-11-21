<?php

namespace Tests\Feature\Platform\Health;

use Tests\TestCase;

/**
 * WS6.G — guard rails for the staging deploy script.
 *
 * The staging login regression shipped because the deploy path could build the
 * front-end without its dev dependencies and had no post-deploy check that the
 * /login page's assets are actually SERVED. These assertions pin the two fixes
 * in place so a future edit of deploy-staging.sh cannot silently undo them.
 */
class StagingDeployScriptTest extends TestCase
{
    private function script(): string
    {
        return (string) file_get_contents(base_path('deploy-staging.sh'));
    }

    public function test_the_front_end_build_installs_dev_dependencies(): void
    {
        // vite / laravel-vite-plugin / tailwind are devDependencies; without
        // them `npm run build` cannot emit public/build and every asset 404s.
        $this->assertStringContainsString('npm ci --include=dev', $this->script());
    }

    public function test_the_deploy_wires_the_login_asset_smoke_check(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('lba:staging:login-assets', $script);
        $this->assertStringContainsString('verify_login_assets()', $script);
        $this->assertMatchesRegularExpression('/^\s*verify_login_assets\s*$/m', $script);
    }

    public function test_the_login_asset_smoke_check_runs_after_the_panel_check(): void
    {
        $script = $this->script();

        $panels = strpos($script, "\n    verify_panels\n");
        $assets = strpos($script, "\n    verify_login_assets\n");

        $this->assertNotFalse($panels, 'main() must invoke verify_panels.');
        $this->assertNotFalse($assets, 'main() must invoke verify_login_assets.');
        $this->assertGreaterThan($panels, $assets, 'The login-asset smoke check must run after the panel check.');
    }

    /**
     * A CGI `php` on PATH (SAPI `cgi-fcgi`) makes `runningInConsole()` false,
     * which silently disables the `$this->commands([...])` registrations: the
     * stage then aborts with "There are no commands defined in the
     * lba:staging namespace". Every Artisan call must therefore go through a
     * resolved PHP CLI binary, never a bare `php`.
     */
    public function test_the_deploy_routes_every_artisan_call_through_a_php_cli_binary(): void
    {
        $script = $this->script();

        $this->assertStringContainsString('resolve_php_binary()', $script);
        $this->assertStringContainsString('PHP_BIN', $script);

        // No Artisan call may sit at a command position with a bare `php`.
        $this->assertDoesNotMatchRegularExpression('/^\s*run php artisan/m', $script);
        $this->assertDoesNotMatchRegularExpression('/^\s*php artisan/m', $script);
        $this->assertDoesNotMatchRegularExpression('/\$\s*\(\s*php -r/', $script);

        // …while the resolved binary is used for the key stages.
        $this->assertStringContainsString('run "${PHP_BIN}" artisan migrate --force', $script);
        $this->assertStringContainsString('"${PHP_BIN}" artisan lba:staging:health', $script);
    }

    public function test_the_deploy_syncs_rbac_after_migrations_and_before_tenant_setup(): void
    {
        $script = $this->script();

        $this->assertStringContainsString(
            "artisan db:seed --class='Database\\Seeders\\RbacSeeder' --force",
            $script,
        );

        $migrations = strpos($script, "\n    central_migrations\n");
        $catalog = strpos($script, "\n    sync_authorization_catalog\n");
        $tenants = strpos($script, "\n    tenant_migrations\n");

        $this->assertNotFalse($migrations);
        $this->assertNotFalse($catalog);
        $this->assertNotFalse($tenants);
        $this->assertGreaterThan($migrations, $catalog);
        $this->assertGreaterThan($catalog, $tenants);
    }

    public function test_the_deploy_exposes_a_php_binary_override(): void
    {
        $script = $this->script();

        // Operators must be able to force the binary when auto-detection is
        // inconclusive on an unusual host.
        $this->assertStringContainsString('--php', $script);
        $this->assertStringContainsString('LBA_PHP_BIN', $script);
    }
}
