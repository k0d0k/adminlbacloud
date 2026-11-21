<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Menu Dynamic (v3.4) — central navigation builder.
     *
     * A menu row is a single navigable entry that the Principal composes per
     * panel ("principal" | "clinic") and per GROUP. Rows form a tree through
     * `parent_id` so a menu can nest, and `sort_order` keeps the drag-and-drop
     * position the Principal arranged. This table is central identity/UX
     * metadata only — it never stores or reaches clinical data.
     *
     * Visibility is fail-closed: an entry is only rendered for an actor when
     * `is_active` is true AND (its `permission_key` is empty OR the actor holds
     * that permission) AND (its `role_keys` is empty OR the actor holds one of
     * those roles). The server re-derives this on every render; hiding an entry
     * client-side is never an authorization decision.
     */
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('panel')->default('principal');
            $table->string('group_key');
            $table->string('group_label');
            $table->string('label');
            $table->string('icon')->nullable();
            $table->string('route_name')->nullable();
            $table->string('url')->nullable();
            $table->string('permission_key')->nullable();
            $table->json('role_keys')->nullable();
            $table->ulid('parent_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('source')->default('custom');
            $table->timestamps();

            $table->index(['panel', 'group_key', 'sort_order']);
            $table->index('parent_id');

            $table->foreign('parent_id')->references('id')->on('menus')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
