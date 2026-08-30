<?php

declare(strict_types=1);

namespace Modules\CostManagement\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\CostManagement\Domain\Enums\PricingReviewStatus;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Companies\Domain\Models\Company;

/**
 * A pricing decision request created whenever Product Cost changes.
 *
 * Selling Price is NEVER updated automatically.
 * Management resolves each review through the Price Review Center.
 *
 * @property string $id
 * @property string $product_id
 * @property string $company_id
 * @property string|null $channel_id
 * @property float $product_cost
 * @property float|null $previous_product_cost
 * @property float $cost_difference
 * @property float $selling_price
 * @property float $suggested_selling_price
 * @property float|null $suggested_sale_price
 * @property float $target_margin
 * @property float $current_margin
 * @property array<string> $impacts
 * @property PricingReviewStatus $status
 * @property string|null $triggered_by_cost_history_id
 * @property string|null $reviewer_name
 * @property string|null $snooze_until
 * @property string|null $notes
 * @property \Carbon\Carbon|null $resolved_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class PricingReview extends Model
{
    use HasUuids;

    protected $table = 'pricing_reviews';

    protected $fillable = [
        'product_id',
        'company_id',
        'channel_id',
        'product_cost',
        'previous_product_cost',
        'cost_difference',
        'selling_price',
        'current_sale_price',
        'suggested_selling_price',
        'suggested_sale_price',
        'manual_regular_price',
        'manual_sale_price',
        'target_margin',
        'current_margin',
        'impacts',
        'status',
        'triggered_by_cost_history_id',
        'trigger_reason',
        'trigger_source',
        'cost_snapshot',
        'explanation',
        'reviewer_name',
        'snooze_until',
        'notes',
        'resolved_at',
        'publish_status',
        'approved_price',
        'approved_sale_price',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'product_cost' => 'float',
            'previous_product_cost' => 'float',
            'cost_difference' => 'float',
            'selling_price' => 'float',
            'suggested_selling_price' => 'float',
            'suggested_sale_price' => 'float',
            'current_sale_price' => 'float',
            'manual_regular_price' => 'float',
            'manual_sale_price' => 'float',
            'target_margin' => 'float',
            'current_margin' => 'float',
            'impacts' => 'array',
            'cost_snapshot' => 'array',
            'status' => PricingReviewStatus::class,
            'snooze_until' => 'date:Y-m-d',
            'resolved_at' => 'datetime',
            'approved_price' => 'float',
            'approved_sale_price' => 'float',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<PriceApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(PriceApproval::class);
    }

    public function resolve(PricingReviewStatus $status): void
    {
        $this->status = $status;
        $this->resolved_at = now();
        $this->save();
    }

    /**
     * THE single authoritative final-price resolution (Current / Suggested / Manual).
     *
     *   CURRENT    selling_price · current_sale_price    immutable snapshot
     *   SUGGESTED  suggested_*                           engine output, may be NULL
     *   MANUAL     manual_*                              the operator's decision
     *
     * Final = manual ?? suggested. A NULL manual column means "not decided", so the
     * suggestion stands — the suggested figure is never copied into manual_*, which
     * would make the same number exist twice and let the two drift.
     *
     * Returns null only when the engine produced no suggestion AND the operator
     * entered nothing. That is an un-approvable review, not a zero price.
     */
    public function finalSellingPrice(): ?float
    {
        $final = $this->manual_regular_price ?? $this->suggested_selling_price;

        return $final !== null ? (float) $final : null;
    }

    /**
     * The sale price an Approve applies.
     *
     * A manual sale price wins outright. Otherwise the sale price is derived from
     * the FINAL regular price through the canonical discount relationship — never
     * from `suggested_sale_price` directly, because that figure was derived from
     * the suggested regular price, which the operator may have replaced. Deriving
     * keeps the two consistent: when no manual regular exists the derivation
     * reproduces the suggestion exactly.
     */
    public function finalSalePrice(float $discountPct): ?float
    {
        if ($this->manual_sale_price !== null) {
            return (float) $this->manual_sale_price > 0.0 ? (float) $this->manual_sale_price : null;
        }

        $finalRegular = $this->finalSellingPrice();

        if ($finalRegular === null) {
            return null;
        }

        $derived = round($finalRegular * (1 - $discountPct / 100), 4);

        return $derived > 0.0 ? $derived : null;
    }
}
