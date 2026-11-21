<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Patient health profile — medications, herbal products and supplements
     * (Phase 2D).
     *
     * This is a REPORTED-CONSUMPTION list, not a prescription: it records what
     * the patient says they take (including over-the-counter products, herbal
     * products and supplements), with the source and verification state made
     * explicit. Nothing recorded here is ever turned into an order, a
     * prescription or an administration instruction by the platform.
     *
     * `status` distinguishes a current medication from a stopped one and from
     * an entry whose current use is not known.
     */
    public function up(): void
    {
        Schema::create('patient_medications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('patient_id');
            $table->string('name');
            $table->string('strength')->nullable();
            $table->string('dose')->nullable();
            $table->string('route')->nullable();
            $table->string('frequency')->nullable();
            $table->string('indication')->nullable();
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
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
        Schema::dropIfExists('patient_medications');
    }
};
