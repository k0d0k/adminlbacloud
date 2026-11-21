<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail for clinical-record changes (Phase 6).
     *
     * Appended on every clinically meaningful write: profile entries added,
     * changed or verified, encounters created, finalized and amended, and
     * sensitive reads such as the patient's clinical summary.
     *
     * `subject_type` + `subject_id` point at the affected row (a condition, an
     * encounter, a vital set…), while `patient_id` keeps the whole trail
     * queryable per patient. `changes` holds only the metadata needed to answer
     * "who changed what, when" — it deliberately never contains raw clinical
     * text beyond what the operator explicitly recorded, and never credentials.
     */
    public function up(): void
    {
        Schema::create('clinical_audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('patient_id')->nullable();
            $table->string('subject_type');
            $table->string('subject_id')->nullable();
            $table->string('action');
            $table->string('actor_id')->nullable();
            $table->json('changes')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('patient_id');
            $table->index(['subject_type', 'subject_id']);
            $table->index('action');

            $table->foreign('patient_id')->references('id')->on('patients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_audit_logs');
    }
};
