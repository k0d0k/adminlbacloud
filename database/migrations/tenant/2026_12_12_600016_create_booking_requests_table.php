<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Appointments\Models\Appointment;

return new class extends Migration
{
    /**
     * Website CMS (Task 11) — public visitor booking requests, stored INSIDE the
     * tenant's isolated database.
     *
     * A `booking_requests` row records a visitor's INTENT to book one slot: it
     * is NOT an appointment. The visitor proves ownership of the contact email
     * (`pending_verification` → `verified`), then confirms the SAME request in
     * the patient portal, where the slot is re-validated and a real
     * {@see Appointment} is created through the
     * existing scheduler (`verified` → `converted`).
     *
     * `branch_id` is a tenant-local foreign key; `patient_id` and
     * `appointment_id` are filled only after the portal confirmation. The
     * verification token is stored HASHED — the raw value only ever lives in
     * the signed email link.
     */
    public function up(): void
    {
        if (Schema::hasTable('booking_requests')) {
            return;
        }

        Schema::create('booking_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('full_name', 120);
            $table->string('email', 190);
            $table->string('phone', 32)->nullable();
            $table->ulid('branch_id');
            $table->string('service_type');
            $table->timestamp('scheduled_at');
            $table->string('status', 32)->default('pending_verification');
            $table->string('verification_token_hash', 64)->nullable();
            $table->timestamp('verification_expires_at')->nullable();
            $table->timestamp('privacy_consent_at')->nullable();
            $table->ulid('patient_id')->nullable();
            $table->ulid('appointment_id')->nullable();
            $table->timestamps();

            // One live request per contact/slot pair — a double submit can never
            // queue the same visitor twice for the same slot.
            $table->unique(['email', 'branch_id', 'scheduled_at']);
            $table->index('status');
            $table->index('verification_token_hash');

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('appointment_id')->references('id')->on('appointments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_requests');
    }
};
