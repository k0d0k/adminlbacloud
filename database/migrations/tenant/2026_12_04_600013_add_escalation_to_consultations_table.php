<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 13 — link a human-expert consultation back to the SINA AI session
     * it was escalated from.
     *
     * `escalated_from_id` is a NULLABLE tenant-local self-reference (no foreign
     * key, to avoid a self-referencing cascade): a NULL value is every ordinary
     * consultation, so existing rows are unchanged. The link is only ever set by
     * the escalation action and is purely for traceability — it never changes how
     * a consultation is scheduled, billed or authorized.
     */
    public function up(): void
    {
        if (Schema::hasColumn('consultations', 'escalated_from_id')) {
            return;
        }

        Schema::table('consultations', function (Blueprint $table) {
            $table->ulid('escalated_from_id')->nullable()->after('appointment_id');
            $table->index('escalated_from_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('consultations', 'escalated_from_id')) {
            return;
        }

        Schema::table('consultations', function (Blueprint $table) {
            $table->dropIndex(['escalated_from_id']);
            $table->dropColumn('escalated_from_id');
        });
    }
};
