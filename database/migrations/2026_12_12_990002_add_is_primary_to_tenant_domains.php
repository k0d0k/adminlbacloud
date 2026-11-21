<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add an `is_primary` flag to tenant_domains so one verified domain can be
     * designated the canonical hostname for a partner. Only a Principal Super
     * Admin may set it (enforced in the controller via the tenant `update`
     * policy, which already gates the entire relations group).
     */
    public function up(): void
    {
        if (! Schema::hasTable('tenant_domains')) {
            return;
        }

        if (! Schema::hasColumn('tenant_domains', 'is_primary')) {
            Schema::table('tenant_domains', function (Blueprint $table): void {
                $table->boolean('is_primary')->default(false)->after('verified_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tenant_domains', 'is_primary')) {
            Schema::table('tenant_domains', function (Blueprint $table): void {
                $table->dropColumn('is_primary');
            });
        }
    }
};
