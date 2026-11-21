<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Referensi Rumusan Dosis — one imported SOURCE VERSION of a dosage master
     * (CENTRAL database).
     *
     * The master is identified by a stable `key` (e.g. `lba-dosage-babul-syiffa`);
     * every re-import of a CHANGED source file appends a NEW version
     * (`key` + `version` unique) so the previous versions remain traceable. The
     * `source_checksum` (SHA-256 of the exact imported bytes) makes the import
     * idempotent: an unchanged file is a no-op.
     *
     * This is central, non-patient reference metadata (a DRAF INTERNAL master).
     * It carries NO assessment result, NO patient identity and NO examiner
     * decision — those live in the tenant database. `brand_id` is a central
     * brand id stored as a plain string (no cross-database foreign key): the
     * same reference version may back more than one brand/SKU once its product
     * mapping is confirmed.
     */
    public function up(): void
    {
        Schema::create('lba_dosage_reference_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key');
            $table->unsignedInteger('version')->default(1);
            $table->string('label');
            $table->string('status')->default('draft');
            $table->string('brand_id')->nullable();
            $table->string('brand_key')->nullable();
            $table->string('source_file')->nullable();
            $table->string('source_checksum', 64)->nullable();
            $table->unsignedInteger('entry_count')->default(0);
            $table->unsignedInteger('phase_value_count')->default(0);
            $table->unsignedInteger('tambahan_count')->default(0);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->string('imported_by')->nullable();
            $table->timestamps();

            $table->unique(['key', 'version']);
            $table->index('status');
            $table->index('brand_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_dosage_reference_versions');
    }
};
