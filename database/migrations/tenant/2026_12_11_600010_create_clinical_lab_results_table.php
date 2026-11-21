<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Other supporting laboratory results (Phase 3C).
     *
     * Deliberately generic: a test name, a free-text result (so "Negatif" or
     * "< 0,5" are representable without lossy coercion), a unit, and the
     * REFERENCE RANGE THE LABORATORY SUPPLIED. There is no global "normal
     * range" here — a reference range depends on the method, the laboratory and
     * the patient, so the platform stores what the source reported instead of
     * inventing one.
     *
     * Attachments are recorded as a private-disk reference (disk + object key),
     * never a public URL.
     */
    public function up(): void
    {
        Schema::create('clinical_lab_results', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('patient_id');
            $table->ulid('encounter_id')->nullable();
            $table->string('test_name');
            $table->string('status')->default('measured');
            $table->string('result_value')->nullable();
            $table->string('unit')->nullable();
            $table->string('reference_range')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('resulted_at')->nullable();
            $table->string('source_name')->nullable();
            $table->string('attachment_disk')->nullable();
            $table->string('attachment_key')->nullable();
            $table->text('notes')->nullable();
            $table->string('recorded_by')->nullable();
            $table->timestamps();

            $table->index('patient_id');
            $table->index('encounter_id');

            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $table->foreign('encounter_id')->references('id')->on('clinical_encounters')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_lab_results');
    }
};
