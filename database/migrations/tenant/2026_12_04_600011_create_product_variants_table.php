<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 11 — product variants.
     *
     * A variant is an optional sellable form of a product (size, pack, colour)
     * carrying its own SKU and price. A product with no variants keeps behaving
     * exactly as before: the legacy `products.sku` / `products.base_price` stay
     * authoritative, so old products, old orders and the POS are untouched.
     * Tenant-local foreign key only.
     */
    public function up(): void
    {
        if (Schema::hasTable('product_variants')) {
            return;
        }

        Schema::create('product_variants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('product_id');
            $table->string('sku')->unique();
            $table->string('name');
            $table->decimal('price', 14, 2)->nullable();
            $table->string('status')->default('active');
            $table->unsignedInteger('position')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
