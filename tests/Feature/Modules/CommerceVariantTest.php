<?php

namespace Tests\Feature\Modules;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\RoleKey;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Exceptions\InventoryException;
use Modules\Commerce\Exceptions\OrderException;
use Modules\Commerce\Models\Order;
use Modules\Commerce\Models\Product;
use Modules\Commerce\Models\ProductVariant;
use Modules\Commerce\Models\StockMovement;
use Modules\Commerce\Services\InventoryService;
use Modules\Commerce\Services\OrderService;
use Modules\Commerce\Services\ProductCatalog;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * WS3 / Phase 11 — product variants and their fulfillment.
 *
 * Proves the variant invariants: a variant carries its own SKU, price and
 * per-branch stock WITHOUT disturbing the legacy product-level behaviour; an
 * order line snapshots the variant; cancelling an order returns the variant
 * stock. Only synthetic data is used.
 */
class CommerceVariantTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    private function actor(Tenant $tenant): User
    {
        $user = User::factory()->role(RoleKey::TENANT_OWNER)->create();
        $this->giveMembership($user, $tenant, [RoleKey::TENANT_OWNER]);

        return $user;
    }

    /**
     * @return array{0: User, 1: string, 2: Product, 3: ProductVariant}
     */
    private function scaffold(int $variantStock = 10, float $variantPrice = 30000): array
    {
        $tenant = Tenant::factory()->create();
        $actor = $this->actor($tenant);
        $this->provisionTenantDatabase($tenant);

        $branch = $this->createBranch($tenant);
        $catalog = app(ProductCatalog::class);

        $product = $catalog->create([
            'name' => 'Synthetic Variant Product',
            'base_currency' => 'IDR',
            'base_price' => 25000,
        ]);

        $variant = $catalog->createVariant($product, [
            'name' => 'Synthetic Variant 60-cap',
            'price' => $variantPrice,
        ]);

        app(InventoryService::class)->receive($variant->product, (string) $branch->getKey(), $variantStock, $actor, 'opening', null, null, StockMovement::TYPE_RECEIPT, $variant);

        return [$actor, (string) $branch->getKey(), $product, $variant];
    }

    public function test_a_variant_keeps_its_own_sku_price_and_stock(): void
    {
        [, $branchId, $product, $variant] = $this->scaffold(variantStock: 8);

        $this->assertNotSame($product->sku, $variant->sku);
        $this->assertSame(30000.0, $variant->priceFor('IDR'));

        $inventory = app(InventoryService::class);

        // The variant stock is separate from the product-level stock.
        $this->assertSame(8, $inventory->level($product, $branchId, $variant));
        $this->assertSame(0, $inventory->level($product, $branchId));
    }

    public function test_variant_skus_are_unique_and_may_not_collide_with_a_product_sku(): void
    {
        [, , $product] = $this->scaffold();
        $catalog = app(ProductCatalog::class);

        // Force a collision: request the parent product's own SKU.
        $variant = $catalog->createVariant($product, [
            'name' => 'Colliding Variant',
            'sku' => $product->sku,
        ]);

        $this->assertNotSame($product->sku, $variant->sku);

        // Two variants asked for the same SKU get distinct values.
        $first = $catalog->createVariant($product, ['name' => 'A', 'sku' => 'VARIANT-DUP']);
        $second = $catalog->createVariant($product, ['name' => 'B', 'sku' => 'VARIANT-DUP']);

        $this->assertSame('VARIANT-DUP', $first->sku);
        $this->assertNotSame($first->sku, $second->sku);
    }

    public function test_an_order_line_snapshots_the_variant_and_issues_variant_stock(): void
    {
        [$actor, $branchId, $product, $variant] = $this->scaffold(variantStock: 10);

        $order = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'variant_id' => (string) $variant->getKey(), 'quantity' => 2],
        ], ['branch_id' => $branchId, 'currency' => 'IDR', 'source' => Order::SOURCE_POS], $actor);

        $line = $order->items()->first();

        $this->assertSame($variant->getKey(), $line->variant_id);
        $this->assertSame($variant->sku, $line->sku);
        $this->assertSame('60000.00', (string) $line->line_total);

        $inventory = app(InventoryService::class);

        // Only the variant stock moved; the product-level level is untouched.
        $this->assertSame(8, $inventory->level($product, $branchId, $variant));
        $this->assertSame(0, $inventory->level($product, $branchId));
    }

    public function test_cancelling_an_order_returns_the_variant_stock(): void
    {
        [$actor, $branchId, $product, $variant] = $this->scaffold(variantStock: 10);

        $order = app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'variant_id' => (string) $variant->getKey(), 'quantity' => 4],
        ], ['branch_id' => $branchId, 'currency' => 'IDR'], $actor);

        $this->assertSame(6, app(InventoryService::class)->level($product, $branchId, $variant));

        app(OrderService::class)->cancel($order->refresh(), $actor);

        $this->assertSame(10, app(InventoryService::class)->level($product, $branchId, $variant));
    }

    public function test_a_variant_of_another_product_is_refused(): void
    {
        [$actor, $branchId, $product] = $this->scaffold();

        $catalog = app(ProductCatalog::class);
        $otherProduct = $catalog->create([
            'name' => 'Another Synthetic Product',
            'base_currency' => 'IDR',
            'base_price' => 25000,
        ]);
        $foreignVariant = $catalog->createVariant($otherProduct, ['name' => 'Foreign Variant', 'price' => 20000]);

        $this->expectException(OrderException::class);

        app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'variant_id' => (string) $foreignVariant->getKey(), 'quantity' => 1],
        ], ['branch_id' => $branchId, 'currency' => 'IDR'], $actor);
    }

    public function test_an_archived_variant_can_no_longer_be_ordered(): void
    {
        [$actor, $branchId, $product, $variant] = $this->scaffold();

        app(ProductCatalog::class)->archiveVariant($variant);

        $this->expectException(OrderException::class);

        app(OrderService::class)->place([
            ['product_id' => (string) $product->getKey(), 'variant_id' => (string) $variant->getKey(), 'quantity' => 1],
        ], ['branch_id' => $branchId, 'currency' => 'IDR'], $actor);
    }

    public function test_variant_stock_is_isolated_between_branches(): void
    {
        [$actor, $branchId, $product, $variant] = $this->scaffold(variantStock: 5);

        $tenant = Tenant::query()->firstOrFail();
        $other = $this->createBranch($tenant);

        $inventory = app(InventoryService::class);

        // An issue against the empty branch is refused; the first branch is intact.
        try {
            $inventory->issue($product, (string) $other->getKey(), 1, $actor, null, null, null, StockMovement::TYPE_ISSUE, $variant);
            $this->fail('Expected an InventoryException for the empty branch.');
        } catch (InventoryException) {
            // Expected.
        }

        $this->assertSame(5, $inventory->level($product, $branchId, $variant));
    }

    public function test_removing_a_product_removes_its_variants(): void
    {
        [, , $product, $variant] = $this->scaffold();

        $product->forceDelete();

        $this->assertDatabaseMissing('product_variants', [
            'id' => $variant->getKey(),
        ], 'tenant');
    }
}
