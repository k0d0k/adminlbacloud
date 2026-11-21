<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * v3.3 — location discovery columns on `branches`.
     *
     * "Cari Lokasi LBA" lets a patient narrow the active branch list by the
     * city/postal code they type, and — only when they grant the browser's
     * geolocation permission — sort the remaining locations by distance. This
     * adds the three nullable columns that make both possible:
     *
     *  - `postal_code` lets a typed postal code match a location directly;
     *  - `latitude` / `longitude` (decimal degrees, 7 dp ≈ 1 cm) let the portal
     *    compute a nearest-first ordering in PHP.
     *
     * Every column is nullable, so existing rows keep working and a branch
     * without coordinates is still listed (it simply never participates in the
     * distance sort). No index is added: matching is done on the small, already
     * tenant-scoped branch set, and a compound index on a low-cardinality city
     * would not pay off.
     */
    public function up(): void
    {
        if (Schema::hasColumn('branches', 'postal_code')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->string('postal_code')->nullable()->after('region');
            $table->decimal('latitude', 10, 7)->nullable()->after('postal_code');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('branches', 'postal_code')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['postal_code', 'latitude', 'longitude']);
        });
    }
};
