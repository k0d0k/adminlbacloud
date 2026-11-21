<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin multi-factor authentication (TOTP) — PRODUCT_REQUIREMENTS.md
 * ("admin MFA required before production").
 *
 * Both columns are the framework's canonical storage for the Filament
 * `AppAuthentication` provider and are written through the model, never mass
 * assigned:
 *
 *  - `app_authentication_secret` holds the per-account TOTP secret. The model
 *    casts it as `encrypted`, so the persisted value is ciphertext (hence a
 *    `text` column, not a short `string`) and it is never exposed in an array /
 *    JSON representation.
 *  - `app_authentication_recovery_codes` holds the (already hashed) one-time
 *    recovery codes as an `encrypted:array`, again requiring a `text` column,
 *    and is likewise hidden from serialisation.
 *
 * Both are additive and nullable, so every existing identity is unaffected and
 * simply has no second factor until it enrols. The migration is idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'app_authentication_secret')) {
                $table->text('app_authentication_secret')->nullable();
            }

            if (! Schema::hasColumn('users', 'app_authentication_recovery_codes')) {
                $table->text('app_authentication_recovery_codes')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('users', 'app_authentication_secret') ? 'app_authentication_secret' : null,
                Schema::hasColumn('users', 'app_authentication_recovery_codes') ? 'app_authentication_recovery_codes' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
