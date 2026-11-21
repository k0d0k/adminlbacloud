<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 11 — variant-aware stock and order lines.
     *
     * `inventory_stocks.variant_id` and `order_items.variant_id` stay NULLABLE:
     * a NULL variant is the legacy product-level row, so existing stock and
     * existing orders are unchanged. The stock uniqueness is widened from
     * (product, branch) to (product, variant, branch) so each variant keeps its
     * own per-branch level. MySQL-safe: the new index name is short and the
     * legacy index is dropped explicitly.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('inventory_stocks', 'variant_id')) {
            Schema::table('inventory_stocks', function (Blueprint $table) {
                $table->ulid('variant_id')->nullable()->after('product_id');
            });

            Schema::table('inventory_stocks', function (Blueprint $table) {
                // The composite unique (product_id, branch_id) backs the
                // product_id foreign key in InnoDB, so it cannot be dropped
                // until that FK is dropped first. Re-add it afterwards.
                $table->dropForeign(['product_id']);
                $table->dropUnique('inventory_stocks_product_id_branch_id_unique');
                $table->unique(['product_id', 'variant_id', 'branch_id'], 'inv_stocks_variant_branch_unique');
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('stock_movements', 'variant_id')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                $table->ulid('variant_id')->nullable()->after('product_id');
            });
        }

        if (! Schema::hasColumn('order_items', 'variant_id')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->ulid('variant_id')->nullable()->after('product_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_items', 'variant_id')) {
            Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('variant_id'));
        }

        if (Schema::hasColumn('stock_movements', 'variant_id')) {
            Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn('variant_id'));
        }

        if (Schema::hasColumn('inventory_stocks', 'variant_id')) {
            Schema::table('inventory_stocks', function (Blueprint $table) {
                $table->dropForeign(['product_id']);
                $table->dropUnique('inv_stocks_variant_branch_unique');
                $table->unique(['product_id', 'branch_id']);
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->dropColumn('variant_id');
            });
        }
    }
};
