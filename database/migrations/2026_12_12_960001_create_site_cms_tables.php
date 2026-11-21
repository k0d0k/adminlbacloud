<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Website CMS tables for the PLATFORM (Principal) website — stored in the
     * central database so the anonymous landing page never has to open a tenant
     * database connection.
     *
     * `site_pages` is the page container (one published frontpage per owner).
     * `site_page_revisions` holds every draft/published version per locale so
     * edits can be previewed, published and restored without data loss.
     * `site_media` records uploaded CMS assets (images, logos) so public URLs
     * can be revoked and ownership traced.
     */
    public function up(): void
    {
        if (Schema::hasTable('site_pages')) {
            return;
        }

        Schema::create('site_pages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('owner_type', 20); // 'platform'
            $table->ulid('owner_id')->nullable(); // null for platform
            $table->string('slug', 100)->default('home');
            $table->string('status', 20)->default('draft'); // draft|published
            $table->ulid('current_revision_id')->nullable();
            $table->json('settings')->nullable(); // display settings (colors, logo)
            $table->ulid('created_by')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'slug']);
            $table->index(['owner_type', 'owner_id']);
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
            $table->string('owner_type', 20);
            $table->ulid('owner_id')->nullable();
            $table->string('disk', 50);
            $table->string('path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('alt_text')->nullable();
            $table->ulid('created_by')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
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
