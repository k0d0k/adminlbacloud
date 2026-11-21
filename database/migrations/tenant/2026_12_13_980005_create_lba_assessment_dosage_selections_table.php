<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Referensi Rumusan Dosis — the per-phase reference SELECTION for one
     * assessment (TENANT database).
     *
     * One row per (assessment, phase) records what the rules resolved for the
     * selected phase — the single source is `selected`, several candidates is
     * `multiple_choices` (the examiner must choose), a red indicator whose
     * reference is unmapped/draft is `needs_review`, an ambiguous label is
     * `needs_mapping`, and an all-green/unassessed assessment is
     * `no_reference`.
     *
     * `snapshot` freezes the reference version + the considered candidates at
     * decision time; `decision` records the examiner's final call (`pending` /
     * `accepted` / `overridden`) with `decided_by` and `decided_at`. This row is
     * internal review data and is NEVER published to the patient portal.
     */
    public function up(): void
    {
        // Percobaan sebelumnya bisa menyisakan tabel setengah jadi (CREATE TABLE
        // sukses, tetapi ALTER index berikutnya gagal karena nama index melebihi
        // 64 karakter). Bersihkan sisa itu agar percobaan ulang berhasil.
        Schema::dropIfExists('lba_assessment_dosage_selections');

        Schema::create('lba_assessment_dosage_selections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('assessment_id')->constrained('lba_assessments')->cascadeOnDelete();
            $table->string('program_phase');
            $table->string('status');
            $table->string('indicator_key')->nullable();
            $table->string('category')->nullable();
            $table->string('reference_key')->nullable();
            $table->unsignedInteger('reference_version')->nullable();
            $table->string('reference_entry_number')->nullable();
            $table->string('source_notation')->nullable();
            $table->unsignedInteger('total_sachets_per_day')->nullable();
            $table->text('reason')->nullable();
            $table->json('considered')->nullable();
            $table->json('snapshot')->nullable();
            $table->string('decision')->default('pending');
            $table->string('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('examiner_notes')->nullable();
            $table->timestamps();

            // Nama index eksplisit: nama auto Laravel (67 karakter) melebihi batas 64 karakter MySQL.
            $table->unique(['assessment_id', 'program_phase'], 'lba_dosage_selection_assess_phase_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lba_assessment_dosage_selections');
    }
};
