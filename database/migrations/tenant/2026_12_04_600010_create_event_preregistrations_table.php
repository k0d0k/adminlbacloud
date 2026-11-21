<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10 — preregistration for tentative events.
     *
     * A tentative (unconfirmed temporary / event) location publishes no slot
     * and must never produce a falsely confirmed booking. Instead a patient may
     * register interest, which is recorded here and confirmed once the organiser
     * publishes the event. Tenant-local foreign keys only.
     */
    public function up(): void
    {
        if (Schema::hasTable('event_preregistrations')) {
            return;
        }

        Schema::create('event_preregistrations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('branch_id');
            $table->ulid('patient_id');
            $table->string('status')->default('registered');
            $table->text('note')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'patient_id']);
            $table->index(['branch_id', 'status']);

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_preregistrations');
    }
};
