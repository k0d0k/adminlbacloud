<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patient health profile — health problems / conditions (Phase 2B).
     *
     * Longitudinal: a condition carries when it started and whether it is still
     * active, so the same row survives across many encounters instead of being
     * re-typed at every visit.
     *
     * Provenance is explicit and two-axis:
     *  - `source` says who supplied it (the patient, a medical document, or a
     *    clinician entering it directly);
     *  - `verification_status` + `verified_by` + `verified_at` say whether an
     *    authorized person has reviewed it.
     * A condition reported by the patient is therefore stored as unverified
     * until a clinician reviews it — nothing here becomes a "diagnosis" merely
     * because a patient typed it.
     *
     * `icd10_code` is optional and is only expected to be set by an authorized
     * clinician; the platform never assigns a code by itself.
     */
    public function up(): void
    {
        Schema::create('patient_conditions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('patient_id');
            $table->string('name');
            $table->string('icd10_code')->nullable();
            $table->date('onset_date')->nullable();
            $table->string('onset_note')->nullable();
            $table->string('status')->default('active');
            $table->string('source')->default('clinician_entered');
            $table->string('verification_status')->default('unverified');
            $table->string('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('recorded_by')->nullable();
            $table->timestamps();

            $table->index('patient_id');
            $table->index('status');
            $table->index('verification_status');

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_conditions');
    }
};
