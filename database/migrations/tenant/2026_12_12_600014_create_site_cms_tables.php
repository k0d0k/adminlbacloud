<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Website CMS tables for the CLINIC (tenant) website — stored INSIDE each
     * tenant's isolated clinical database. Two tenants sharing a brand (e.g.
     * NuBiom) therefore keep their own content, media and revision history.
     *
     * There is no owner_id column: the tenant IS the owner (the database is the
     * tenant boundary, ARCH-05). The public frontpage initialises the tenant
     * connection before reading published content; if the database is
     * unreachable the visitor sees a generic "service unavailable" page.
     */
    public function up(): void
    {
        if (Schema::hasTable('site_pages')) {
            return;
        }

        Schema::create('site_pages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('owner_type', 20)->default('tenant');
            $table->string('slug', 100)->default('home');
            $table->string('status', 20)->default('draft'); // draft|published
            $table->ulid('current_revision_id')->nullable();
            $table->json('settings')->nullable(); // display settings (colors, logo, typography)
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['owner_type', 'slug']);
        });

        Schema::create('site_page_revisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('page_id')->constrained('site_pages')->cascadeOnDelete();
            $table->string('locale', 10); // id|en|ms-MY
            $table->string('title')->nullable();
            $table->json('content'); // array of sections
            $table->json('seo')->nullable(); // title, description, og_image
            $table->string('status', 20)->default('draft'); // draft|published
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['page_id', 'locale']);
            $table->index('status');
        });

        Schema::table('site_pages', function (Blueprint $table) {
            $table->foreign('current_revision_id')
                ->references('id')->on('site_page_revisions')
                ->nullOnDelete();
        });

        Schema::create('site_media', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('disk', 50);
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('alt_text')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_media');
        Schema::table('site_pages', function (Blueprint $table) {
            $table->dropForeign(['current_revision_id']);
        });
        Schema::dropIfExists('site_page_revisions');
        Schema::dropIfExists('site_pages');
    }
};
