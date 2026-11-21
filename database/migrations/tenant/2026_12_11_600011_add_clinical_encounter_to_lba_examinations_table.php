<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link an LBA examination to the clinical encounter it was performed in
     * (Phase 4).
     *
     * Additive and nullable. The LBA module keeps owning its own workflow,
     * consent and report lifecycle — this column only lets an examination be
     * opened FROM a legitimate encounter so the examiner sees the patient's
     * clinical context, and so the encounter can list its examinations. No LBA
     * data is duplicated onto the encounter, and an examination without an
     * encounter keeps working exactly as before.
     */
    public function up(): void
    {
        Schema::table('lba_examinations', function (Blueprint $table) {
            $table->ulid('clinical_encounter_id')->nullable()->after('appointment_id');
            $table->index('clinical_encounter_id');
        });
    }

    public function down(): void
    {
        Schema::table('lba_examinations', function (Blueprint $table) {
            $table->dropIndex(['clinical_encounter_id']);
            $table->dropColumn('clinical_encounter_id');
        });
    }
};
