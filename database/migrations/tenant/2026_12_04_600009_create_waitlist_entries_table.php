<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10 — booking waitlist.
     *
     * When a location is at capacity a patient may join the waitlist for the
     * requested window instead of being turned away. An entry is `waiting`
     * until a slot frees (`offered`), converted into a real appointment
     * (`converted`), cancelled by the patient, or has expired. Tenant-local
     * foreign keys only — no cross-database constraints.
     */
    public function up(): void
    {
        if (Schema::hasTable('waitlist_entries')) {
            return;
        }

        Schema::create('waitlist_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('branch_id');
            $table->ulid('patient_id');
            $table->string('service_type')->nullable();
            $table->timestamp('desired_starts_at')->nullable();
            $table->timestamp('desired_ends_at')->nullable();
            $table->string('status')->default('waiting');
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('note')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['patient_id', 'status']);

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_entries');
    }
};
