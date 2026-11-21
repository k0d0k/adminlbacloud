<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Website CMS — self-registered patient provenance (TENANT database).
     *
     * A patient who registers from a clinic's public frontpage gets a tenant
     * patient record created by the platform itself, WITHOUT ever matching an
     * existing medical record by name, email or phone. `registration_source`
     * records that provenance so clinic staff can tell a self-registered row
     * apart from one a clinic operator created, without changing the clinical
     * `status` lifecycle (active / archived).
     *
     * `self_registered_at` records WHEN the platform created the row; it stays
     * null for clinic-created patients, preserving the historical behaviour.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('registration_source')->default('clinic')->after('status');
            $table->timestamp('self_registered_at')->nullable()->after('registration_source');
            $table->index('registration_source');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropIndex(['registration_source']);
            $table->dropColumn(['registration_source', 'self_registered_at']);
        });
    }
};
