<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Admin multi-factor authentication (PRODUCT_REQUIREMENTS.md: "admin MFA
 * required before production").
 *
 * The Principal (platform administration) panel registers a TOTP second factor.
 * These tests prove the four properties the requirement calls for, over the
 * REAL login path a browser uses (`/login` renders a Filament Livewire page, so
 * each step is a real HTTP round-trip against the Livewire update endpoint):
 *
 *  - enrolment stores an encrypted secret and hashed recovery codes;
 *  - an enrolled admin is challenged BEFORE any panel session is created;
 *  - a recovery code gets an admin in WITHOUT the authenticator, exactly once;
 *  - direct panel access can never skip the second factor (fail closed).
 *
 * Identities are synthetic (UserFactory); the factory password is a literal and
 * is never a real credential.
 */
class AdminMultiFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const SYNTHETIC_PASSWORD = 'password';

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /** The app-authentication provider registered on the Principal panel. */
    private function provider(): AppAuthentication
    {
        $providers = Filament::getPanel('principal')->getMultiFactorAuthenticationProviders();

        $this->assertArrayHasKey('app', $providers);

        return $providers['app'];
    }

    private function admin(): User
    {
        return User::factory()->role('principal_super_admin')->create();
    }

    /** Enrol the given admin with a fresh TOTP secret (the same call the UI makes). */
    private function enrol(User $user): string
    {
        $secret = $this->provider()->generateSecret();
        $this->provider()->saveSecret($user, $secret);

        return $secret;
    }

    private function renderLogin(): string
    {
        return $this->get('/login')->assertOk()->getContent();
    }

    /**
     * Submit the login form the way the browser does.
     *
     * @param  array<string, mixed>  $updates  extra top-level component state
     */
    private function submitLogin(string $html, array $updates): TestResponse
    {
        if (! preg_match('/wire:snapshot="([^"]+)"/', $html, $matches)) {
            $this->fail('The login page did not render a Livewire snapshot.');
        }

        return $this->postSnapshot(html_entity_decode($matches[1], ENT_QUOTES), $updates);
    }

    /** Replay a (possibly challenge-stage) snapshot with extra component state. */
    private function postSnapshot(string $snapshot, array $updates): TestResponse
    {
        return $this->json('POST', app('livewire')->getUpdateUri(), [
            'components' => [[
                'snapshot' => $snapshot,
                'calls' => [['method' => 'authenticate', 'params' => [], 'path' => '']],
                'updates' => $updates,
            ]],
        ], ['X-Livewire' => 'true']);
    }

    /** The panel the login response redirects to, if it authenticated. */
    private function redirectTarget(TestResponse $response): ?string
    {
        return $response->json('components.0.effects.redirect');
    }

    /** Submit an email + password pair to the rendered login page. */
    private function submitCredentials(string $html, string $email, string $password): TestResponse
    {
        return $this->submitLogin($html, [
            'data.email' => $email,
            'data.password' => $password,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Wiring
    |--------------------------------------------------------------------------
    */

    public function test_the_principal_panel_registers_the_app_second_factor(): void
    {
        $providers = Filament::getPanel('principal')->getMultiFactorAuthenticationProviders();

        $this->assertArrayHasKey('app', $providers);
        $this->assertInstanceOf(AppAuthentication::class, $providers['app']);
        // Recovery codes are enabled so a lost authenticator never locks an
        // admin out of the platform they administer.
        $this->assertTrue($providers['app']->isRecoverable());
    }

    public function test_mandatory_enrolment_follows_the_production_switch(): void
    {
        $panel = Filament::getPanel('principal');

        // Off by default: MFA is offered, existing installs are unaffected.
        config(['lba.security.admin_mfa_required' => false]);
        $this->assertFalse($panel->isMultiFactorAuthenticationRequired());

        // Flip the production switch: the panel now fails closed for admins who
        // have not enrolled.
        config(['lba.security.admin_mfa_required' => true]);
        $this->assertTrue($panel->isMultiFactorAuthenticationRequired());
    }

    /*
    |--------------------------------------------------------------------------
    | Enrolment
    |--------------------------------------------------------------------------
    */

    public function test_enrolment_stores_an_encrypted_secret_and_hashed_recovery_codes(): void
    {
        $user = $this->admin();
        $secret = $this->enrol($user);

        $this->assertInstanceOf(HasAppAuthentication::class, $user);
        $this->assertTrue($this->provider()->isEnabled($user));
        $this->assertSame($secret, $user->getAppAuthenticationSecret());

        $recoveryCodes = $this->provider()->generateRecoveryCodes();
        $this->provider()->saveRecoveryCodes($user, $recoveryCodes);
        $this->assertCount(count($recoveryCodes), $user->getAppAuthenticationRecoveryCodes());

        // The secret is ciphertext at rest — never the plaintext TOTP seed...
        $storedSecret = DB::table('users')->where('id', $user->getKey())->value('app_authentication_secret');
        $this->assertNotSame($secret, $storedSecret);

        // ...and the recovery codes are stored hashed, never recoverable in the
        // clear from the database.
        $storedCodes = (string) DB::table('users')->where('id', $user->getKey())->value('app_authentication_recovery_codes');
        $this->assertStringNotContainsString($recoveryCodes[0], $storedCodes);

        // Neither secret material leaks through array / JSON serialisation.
        $serialised = $user->fresh()->toArray();
        $this->assertArrayNotHasKey('app_authentication_secret', $serialised);
        $this->assertArrayNotHasKey('app_authentication_recovery_codes', $serialised);
    }

    /*
    |--------------------------------------------------------------------------
    | Challenge
    |--------------------------------------------------------------------------
    */

    public function test_an_enrolled_admin_is_challenged_before_any_session_is_created(): void
    {
        $user = $this->admin();
        $this->enrol($user);

        $response = $this->submitCredentials($this->renderLogin(), $user->email, self::SYNTHETIC_PASSWORD);

        // The password was accepted, but no session exists yet: the response is
        // the second-factor challenge, not an authenticated redirect.
        $this->assertNull($this->redirectTarget($response));
        $this->assertGuest();
    }

    public function test_the_correct_second_factor_completes_login(): void
    {
        $user = $this->admin();
        $this->enrol($user);

        $challenge = $this->submitCredentials($this->renderLogin(), $user->email, self::SYNTHETIC_PASSWORD);
        $snapshot = $challenge->json('components.0.snapshot');
        $this->assertIsString($snapshot);

        $code = $this->provider()->getCurrentCode($user);
        $response = $this->postSnapshot($snapshot, ['data.multiFactor.app.code' => $code]);

        $this->assertSame('/principal', $this->redirectTarget($response));
        $this->assertTrue(Auth::check());
    }

    public function test_a_wrong_second_factor_does_not_authenticate(): void
    {
        $user = $this->admin();
        $this->enrol($user);

        $challenge = $this->submitCredentials($this->renderLogin(), $user->email, self::SYNTHETIC_PASSWORD);
        $snapshot = $challenge->json('components.0.snapshot');

        $response = $this->postSnapshot($snapshot, ['data.multiFactor.app.code' => '000000']);

        $this->assertNull($this->redirectTarget($response));
        $this->assertGuest();
    }

    /*
    |--------------------------------------------------------------------------
    | Recovery
    |--------------------------------------------------------------------------
    */

    public function test_a_recovery_code_authenticates_once_and_is_consumed(): void
    {
        $user = $this->admin();
        $secret = $this->enrol($user);

        $codes = $this->provider()->generateRecoveryCodes();
        $this->provider()->saveRecoveryCodes($user, $codes);
        $recoveryCode = $codes[0];
        $remaining = count($codes) - 1;

        $challenge = $this->submitCredentials($this->renderLogin(), $user->email, self::SYNTHETIC_PASSWORD);
        $snapshot = $challenge->json('components.0.snapshot');

        $response = $this->postSnapshot($snapshot, [
            'data.multiFactor.app.useRecoveryCode' => true,
            'data.multiFactor.app.recoveryCode' => $recoveryCode,
        ]);

        $this->assertSame('/principal', $this->redirectTarget($response));
        $this->assertTrue(Auth::check());

        // The used code is burnt: it can never be replayed.
        $this->assertCount($remaining, $user->refresh()->getAppAuthenticationRecoveryCodes());
        $this->assertFalse($this->provider()->verifyRecoveryCode($recoveryCode, $user));
        $this->assertNotNull($secret);
    }

    /*
    |--------------------------------------------------------------------------
    | No bypass
    |--------------------------------------------------------------------------
    */

    public function test_direct_panel_access_cannot_skip_the_second_factor(): void
    {
        $user = $this->admin();
        $this->enrol($user);

        // Password-only sign-in leaves the visitor a guest — the challenge is
        // mandatory, not a suggestion.
        $this->submitCredentials($this->renderLogin(), $user->email, self::SYNTHETIC_PASSWORD);
        $this->assertGuest();

        // With no completed second factor, the panel is unreachable: a direct
        // visit is bounced back to the login form (which then re-demands MFA).
        $this->get('/principal')->assertRedirect('/principal/login');
    }
}
