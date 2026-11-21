<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clinical encounters — one visit record per patient visit (Phase 3).
     *
     * A patient has MANY encounters and an encounter NEVER overwrites an
     * earlier one: every measurement, note and assessment hangs off its own
     * encounter row, so history is preserved by construction.
     *
     * Lifecycle: `draft` while an authorized person is still writing, `final`
     * once signed off, `amended` once a final record has been corrected. A
     * final record is never silently rewritten — see
     * `clinical_encounter_amendments`, which is the only path that may change
     * a final encounter and always stores the reason and the previous values.
     *
     * SOAP is stored as four explicit fields (subjective/objective/assessment/
     * plan) rather than one blob, so the objective findings can be told apart
     * from the clinical assessment. All clinical columns are nullable: an
     * encounter may legitimately be recorded with only some parts completed.
     */
    public function up(): void
    {
        Schema::create('clinical_encounters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('patient_id');
            $table->ulid('branch_id')->nullable();
            $table->ulid('appointment_id')->nullable();
            $table->ulid('practitioner_id')->nullable();
            $table->timestamp('encounter_at');
            $table->string('timezone')->default('Asia/Jakarta');
            $table->string('type')->default('new');
            $table->string('status')->default('draft');
            $table->text('chief_complaint')->nullable();
            $table->text('history_of_present_illness')->nullable();
            $table->json('reviewed_history')->nullable();
            $table->text('physical_exam')->nullable();
            $table->text('supporting_findings')->nullable();
            $table->text('subjective')->nullable();
            $table->text('objective')->nullable();
            $table->text('assessment')->nullable();
            $table->text('plan')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->string('created_by')->nullable();
            $table->string('finalized_by')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->string('amended_by')->nullable();
            $table->timestamp('amended_at')->nullable();
            $table->timestamps();

            $table->index('patient_id');
            $table->index('branch_id');
            $table->index('status');
            $table->index(['patient_id', 'encounter_at']);

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('appointment_id')->references('id')->on('appointments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_encounters');
    }
};
