<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Referensi Rumusan Dosis — one PHASE value of an entry (CENTRAL database).
     *
     * Four phases are stored per entry (Cleansing, Balancing, Protecting,
     * Maintenance): 226 entries x 4 phases = 904 rows. The ORIGINAL notation is
     * preserved verbatim in `dosage_notation` (e.g. `4 X 2`), and the structured
     * reading of the "Cara membaca" rule ("A x B" = A sachet per intake, B times
     * a day) is stored alongside as `sachets_per_intake`, `intakes_per_day` and
     * `sachets_per_day`.
     *
     * Duration and phase-transition rules are NOT in the source file, so no such
     * column exists: the structured values are a reading aid only. The main
     * product dose is the sachet count; `tambahan` lives on the entry.
     */
    public function up(): void
    {
        Schema::create('lba_dosage_reference_phase_values', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('reference_entry_id')
                ->constrained('lba_dosage_reference_entries')
                ->cascadeOnDelete();
            $table->string('phase');
            $table->string('dosage_notation');
            $table->unsignedInteger('sachets_per_intake')->nullable();
            $table->unsignedInteger('intakes_per_day')->nullable();
            $table->unsignedInteger('sachets_per_day')->nullable();
            $table->timestamps();

            // Nama index eksplisit: nama auto Laravel melebihi batas 64 karakter MySQL.
            $table->unique(['reference_entry_id', 'phase'], 'lba_dosage_phase_entry_phase_unique');
            $table->index('phase');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_dosage_reference_phase_values');
    }
};
