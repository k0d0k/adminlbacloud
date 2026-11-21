<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vital signs and anthropometry (Phase 3A).
     *
     * One row per measurement set, so a SINGLE encounter can hold several
     * readings (e.g. a first blood pressure and a repeat a few minutes later)
     * without overwriting either. Each row carries the measurement context a
     * clinician needs to interpret the number: patient position, arm side,
     * cuff size, device and the time it was taken.
     *
     * Every value is independently nullable, and `status` makes "not measured"
     * explicit, so a missing value is never stored — or displayed — as 0.
     * `bmi` is derived only when both weight and height are present.
     */
    public function up(): void
    {
        Schema::create('clinical_vitals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('encounter_id');
            $table->ulid('patient_id');
            $table->timestamp('measured_at')->nullable();
            $table->string('status')->default('measured');
            $table->string('position')->nullable();
            $table->string('arm')->nullable();
            $table->string('cuff_size')->nullable();
            $table->string('device')->nullable();
            $table->unsignedSmallInteger('systolic')->nullable();
            $table->unsignedSmallInteger('diastolic')->nullable();
            $table->unsignedSmallInteger('pulse')->nullable();
            $table->unsignedSmallInteger('respiratory_rate')->nullable();
            $table->decimal('temperature_c', 3, 1)->nullable();
            $table->unsignedSmallInteger('oxygen_saturation')->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('height_cm', 5, 2)->nullable();
            $table->decimal('bmi', 4, 2)->nullable();
            $table->text('notes')->nullable();
            $table->string('source')->nullable();
            $table->string('measured_by')->nullable();
            $table->string('recorded_by')->nullable();
            $table->timestamps();

            $table->index('encounter_id');
            $table->index('patient_id');

            $table->foreign('encounter_id')->references('id')->on('clinical_encounters')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_vitals');
    }
};
