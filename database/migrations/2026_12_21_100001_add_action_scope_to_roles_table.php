<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Requirement 3.a — the CRUD vs CRU action level on every role.
     *
     * `cru` (View/Create/Update) or `crud` (adds Delete). Materialised from
     * {@see \App\Platform\Access\RbacCatalog::roleActionScopes()} by the seeder so
     * the database stays authoritative at request time, and it applies the
     * moment a role is attached to a user. The default is the safer `cru`.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('action_scope')->default('cru')->after('panel');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('action_scope');
        });
    }
};
