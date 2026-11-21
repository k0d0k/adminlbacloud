<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Referensi Rumusan Dosis — explicit indicator -> Lampiran A mapping
     * (TENANT database).
     *
     * One digital indicator may carry several conditions, and one condition may
     * map to more than one Lampiran A entry, so this is an explicit, reviewed
     * mapping (indicator key + recorded/confirmed condition -> entry number),
     * NOT an inferred one. `review_status` is `reviewed` for a name equivalence
     * a human approved, or `needs_mapping` for a combined/ambiguous label that
     * still requires mapping (surfaced to the examiner as "Perlu Pemetaan").
     *
     * `condition_label` is stored as an empty string (never NULL) when there is
     * no separate condition, so the uniqueness holds on every engine.
     */
    public function up(): void
    {
        Schema::create('lba_dosage_indicator_mappings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('indicator_key');
            $table->string('condition_label')->default('');
            $table->string('reference_entry_number');
            $table->string('reference_key')->nullable();
            $table->string('review_status')->default('reviewed');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['indicator_key', 'condition_label', 'reference_entry_number'], 'lba_dosage_map_unique');
            $table->index('indicator_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_dosage_indicator_mappings');
    }
};
