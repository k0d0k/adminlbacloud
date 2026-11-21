<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patient health profile — additional history (Phase 2E).
     *
     * One flexible, categorised table for the supporting history that does not
     * warrant its own entity: past surgery, hospitalisation, relevant family
     * history, and lifestyle context (smoking, alcohol, activity, diet, sleep,
     * pregnancy/lactation). Keeping them in ONE table means a new category is a
     * value, not a schema change.
     *
     * Same provenance and verification rules as the rest of the profile: an
     * entry reported by the patient stays unverified until an authorized person
     * reviews it, and only the minimum needed for care is stored.
     */
    public function up(): void
    {
        Schema::create('patient_health_histories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('patient_id');
            $table->string('category');
            $table->string('title');
            $table->text('detail')->nullable();
            $table->date('occurred_on')->nullable();
            $table->string('source')->default('clinician_entered');
            $table->string('verification_status')->default('unverified');
            $table->string('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('recorded_by')->nullable();
            $table->timestamps();

            $table->index('patient_id');
            $table->index('category');
            $table->index('verification_status');

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_health_histories');
    }
};
