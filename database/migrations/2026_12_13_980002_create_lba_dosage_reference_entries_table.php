<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Referensi Rumusan Dosis — one numbered Lampiran A entry (`001`..`226`)
     * of a source version (CENTRAL database).
     *
     * `entry_number` preserves the ORIGINAL number exactly (zero-padded string),
     * `condition_name` preserves the original condition name verbatim, and
     * `tambahan_text` preserves the `Tambahan` reference text as-is (it is a
     * reference note, never a machine-readable instruction). Nothing here is
     * rewritten or normalised.
     */
    public function up(): void
    {
        Schema::create('lba_dosage_reference_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('reference_version_id')
                ->constrained('lba_dosage_reference_versions')
                ->cascadeOnDelete();
            $table->string('entry_number', 8);
            $table->text('condition_name');
            $table->text('tambahan_text')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            // Nama index eksplisit: nama auto Laravel melebihi batas 64 karakter MySQL.
            $table->unique(['reference_version_id', 'entry_number'], 'lba_dosage_entry_version_number_unique');
            $table->index('entry_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_dosage_reference_entries');
    }
};
