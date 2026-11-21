<?php

namespace Modules\Commerce\Models;

use App\Platform\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 11 — an optional sellable variant of a {@see Product}.
 *
 * A variant carries its own SKU and price and its own per-branch stock, so the
 * same product can be sold as, e.g., a 30-cap or a 60-cap pack. A product
 * WITHOUT variants keeps using the product-level SKU/price/stock unchanged.
 * Extends {@see TenantModel}: it resolves the active tenant database and fails
 * closed without a verified tenant context.
 */
#[Fillable([
    'product_id',
    'sku',
    'name',
    'price',
    'status',
    'position',
    'metadata',
])]
class ProductVariant extends TenantModel
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_ARCHIVED = 'archived';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_ARCHIVED,
    ];

    protected $table = 'product_variants';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'position' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * The effective price for an explicit currency: the variant price when set,
     * otherwise the parent product's price (so an unpriced variant inherits).
     */
    public function priceFor(string $currency): ?float
    {
        if ($this->price !== null) {
            return (float) $this->price;
        }

        return $this->product?->priceFor($currency);
    }

    /**
     * @param  Builder<ProductVariant>  $query
     * @return Builder<ProductVariant>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
