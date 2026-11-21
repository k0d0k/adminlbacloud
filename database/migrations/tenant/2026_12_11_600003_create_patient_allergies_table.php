<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patient health profile — allergies and intolerances (Phase 2C).
     *
     * Multi-entry: one row per substance. The severity of a reaction is kept
     * explicit (including `unknown`, which is NOT the same as "mild") so a
     * clinician can distinguish an unassessed reaction from a graded one.
     *
     * Provenance and verification mirror `patient_conditions`: patient-reported
     * entries stay unverified until an authorized person reviews them, and the
     * reviewer identity and time are retained.
     */
    public function up(): void
    {
        Schema::create('patient_allergies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('patient_id');
            $table->string('substance');
            $table->string('category')->default('other');
            $table->string('reaction')->nullable();
            $table->string('severity')->default('unknown');
            $table->date('occurred_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('source')->default('clinician_entered');
            $table->string('verification_status')->default('unverified');
            $table->string('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('recorded_by')->nullable();
            $table->timestamps();

            $table->index('patient_id');
            $table->index('is_active');
            $table->index('verification_status');

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_allergies');
    }
};
