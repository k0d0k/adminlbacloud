<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audited amendments to a FINAL clinical encounter (Phase 3D).
     *
     * A finalized note is not editable in place. The only way to correct it is
     * an amendment, which MUST carry a reason and always stores the previous
     * and the new values, so the superseded content stays auditable even though
     * the encounter row now shows the corrected version.
     *
     * Append-only: one row per correction, with the resulting revision number.
     */
    public function up(): void
    {
        Schema::create('clinical_encounter_amendments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('encounter_id');
            $table->string('amended_by')->nullable();
            $table->text('reason');
            $table->json('changes');
            $table->unsignedInteger('revision');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('encounter_id');

            $table->foreign('encounter_id')->references('id')->on('clinical_encounters')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_encounter_amendments');
    }
};
