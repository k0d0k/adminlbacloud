<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patient health profile — the overall allergy status (Phase 2C).
     *
     * Additive and nullable: existing patient rows keep working. The status is
     * a three-valued clinical assertion, deliberately NOT a boolean, because
     * "no known allergies" and "not yet known / not assessed" are different
     * clinical statements and must never collapse into each other (or into an
     * empty allergy list):
     *
     *  - `has_allergies`       — at least one allergy/intolerance is recorded;
     *  - `no_known_allergies`  — an authorized person asserted there are none;
     *  - `unknown`             — never assessed; the default, so a fresh record
     *                            never implies "no allergies".
     *
     * It is set only through the authorized, audited clinical service; it is
     * never mass-assignable from patient or portal input.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('allergy_status')->default('unknown')->after('status');
            $table->string('allergy_status_recorded_by')->nullable()->after('allergy_status');
            $table->timestamp('allergy_status_recorded_at')->nullable()->after('allergy_status_recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn([
                'allergy_status',
                'allergy_status_recorded_by',
                'allergy_status_recorded_at',
            ]);
        });
    }
};
