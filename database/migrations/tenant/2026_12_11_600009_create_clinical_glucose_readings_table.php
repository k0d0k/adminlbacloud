<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blood glucose and HbA1c results (Phase 3B).
     *
     * `kind` is explicit because fasting, random and post-meal glucose are
     * different measurements that must never be plotted as one series, and
     * HbA1c is a different test entirely.
     *
     * The value the operator entered is stored VERBATIM (`value` + `unit`); a
     * derived `value_normalized` (+ `normalized_unit`) is stored alongside so
     * charts and comparisons can use one canonical unit WITHOUT ever rewriting
     * the original record. Conversion happens in the recording service, which
     * validates the unit for the measurement kind.
     *
     * `value` is nullable and `status` distinguishes "not performed" from a
     * measured value: an unperformed test is stored — and displayed — as
     * "not performed", never as 0.
     */
    public function up(): void
    {
        Schema::create('clinical_glucose_readings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('encounter_id');
            $table->ulid('patient_id');
            $table->string('kind');
            $table->string('status')->default('measured');
            $table->decimal('value', 8, 3)->nullable();
            $table->string('unit')->nullable();
            $table->decimal('value_normalized', 8, 3)->nullable();
            $table->string('normalized_unit')->nullable();
            $table->string('specimen')->nullable();
            $table->string('method')->nullable();
            $table->timestamp('measured_at')->nullable();
            $table->timestamp('last_meal_at')->nullable();
            $table->string('source_name')->nullable();
            $table->text('notes')->nullable();
            $table->string('recorded_by')->nullable();
            $table->timestamps();

            $table->index('encounter_id');
            $table->index(['patient_id', 'kind']);

            $table->foreign('encounter_id')->references('id')->on('clinical_encounters')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_glucose_readings');
    }
};
