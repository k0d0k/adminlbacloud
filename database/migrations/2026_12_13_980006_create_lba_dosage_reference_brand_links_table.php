<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Referensi Rumusan Dosis — the Brand/SKU links of one source version
     * (CENTRAL database).
     *
     * One imported source version may back MORE THAN ONE brand/SKU once its
     * product mapping is confirmed (Babul Syiffa, NuBiom and Biodam all reuse
     * the same version). This is commercial linking metadata only: it carries NO
     * patient, assessment or examiner data, and a `brand_id` is stored as a plain
     * string (no cross-database foreign key). `brand_key` is the stable logical
     * key used when no central Brand row exists yet.
     */
    public function up(): void
    {
        Schema::create('lba_dosage_reference_brand_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('reference_version_id')
                ->constrained('lba_dosage_reference_versions')
                ->cascadeOnDelete();
            $table->string('brand_key');
            $table->string('brand_id')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();

            // Nama index eksplisit: nama auto Laravel melebihi batas 64 karakter MySQL.
            $table->unique(['reference_version_id', 'brand_key'], 'lba_dosage_brand_link_version_key_unique');
            $table->index('brand_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_dosage_reference_brand_links');
    }
};
