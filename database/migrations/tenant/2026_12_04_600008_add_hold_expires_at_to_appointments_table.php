<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 10 — expiring per-booking holds.
     *
     * A reservation created in the `held` status occupies its slot only until
     * `hold_expires_at`. An expired hold no longer counts towards branch
     * capacity or practitioner overlap, so the slot frees itself even before
     * the expiry sweep runs. Idempotent + MySQL-safe (short index name).
     */
    public function up(): void
    {
        if (Schema::hasColumn('appointments', 'hold_expires_at')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('hold_expires_at')->nullable()->after('status');
            $table->index('hold_expires_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('appointments', 'hold_expires_at')) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['hold_expires_at']);
            $table->dropColumn('hold_expires_at');
        });
    }
};
