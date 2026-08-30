<?php

declare(strict_types=1);

namespace Modules\CostManagement\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CostManagement\Domain\Models\PricingReview;

/**
 * @mixin PricingReview
 */
class PricingReviewResource extends JsonResource
{
    /** Markup % derived from target margin: markup = margin / (100 - margin) * 100 */
    private function derivedMarkup(float $targetMargin): float
    {
        return $targetMargin < 100
            ? round($targetMargin / (100 - $targetMargin) * 100, 4)
            : 0.0;
    }

    /** Suggested sale price = suggested_regular × (1 − discount_pct / 100) */
    private function suggestedSalePrice(float $suggestedRegular, float $discountPct): float
    {
        return round($suggestedRegular * (1 - $discountPct / 100), 4);
    }

    /** Gross profit % on a price. Null price (no suggestion) stays null. */
    private function grossProfitPct(float $cost, ?float $price): ?float
    {
        if ($price === null || $price <= 0.0) {
            return null;
        }

        return round(($price - $cost) / $price * 100, 2);
    }

    /**
     * Net Margin % — profit measured against the SALE price.
     *
     * This is the canonical definition this screen has always used: the effective
     * price a customer pays is the sale price when one is set, otherwise the
     * regular price. Gross Profit % (above) measures against the REGULAR price.
     * The two are deliberately different metrics and are never interchangeable.
     */
    private function netMarginPct(float $cost, ?float $sale, ?float $regular): ?float
    {
        $effective = ($sale !== null && $sale > 0.0) ? $sale : $regular;

        if ($effective === null || $effective <= 0.0) {
            return null;
        }

        return round(($effective - $cost) / $effective * 100, 2);
    }

    // Profitability is presentation: the same two formulas are applied to each of
    // the three price tiers. The FINAL PRICE itself still comes from the single
    // resolver on PricingReview, so the API and Approve cannot disagree.

    public function toArray(Request $request): array
    {
        /** @var PricingReview $this */
        $product = $this->product;
        $brand = $product?->relationLoaded('brand') ? $product->brand : null;
        $unit = $product?->unit;
        $cost = $this->product_cost;
        $targetMargin = $this->target_margin;
        $markup = $this->derivedMarkup($targetMargin);
        $discountPct = $product?->effectiveDiscountPct() ?? 0.0;

        // CURRENT sale is the review's own snapshot. It used to be read live from
        // the product, so approving a review retroactively rewrote its own history.
        // Older rows predating the snapshot column fall back to the product.
        $currentSalePrice = $this->current_sale_price ?? $product?->sale_price;

        // A missing suggestion stays NULL — never fabricated as 0.
        $suggestedRegular = $this->suggested_selling_price !== null
            ? (float) $this->suggested_selling_price
            : null;
        $suggestedSale = $this->suggested_sale_price !== null
            ? (float) $this->suggested_sale_price
            : ($suggestedRegular !== null ? $this->suggestedSalePrice($suggestedRegular, $discountPct) : null);

        $finalRegular = $this->finalSellingPrice();
        $finalSale = $this->finalSalePrice($discountPct);

        return [
            'id' => $this->id,
            'product' => [
                'id' => $product?->id,
                'name' => $product?->name,
                'sku' => $product?->sku,
                'image_url' => $product?->image_url,
                'unit' => $unit?->symbol ?? $unit?->name,
                'pricing_mode' => $product?->pricing_mode ?? 'brand_policy',
                'custom_target_margin' => $product?->custom_target_margin,
                'custom_markup' => $product?->custom_markup,
                'custom_discount_pct' => $product?->custom_discount_pct,
            ],
            'brand' => $brand !== null ? [
                'id' => $brand->id,
                'name' => $brand->name,
                'default_target_margin' => $brand->default_target_margin,
                'default_markup' => $brand->default_markup,
                'default_discount_pct' => $brand->default_discount_pct,
            ] : null,
            'company' => [
                'id' => $this->company_id,
                'name' => $this->whenLoaded('company', fn () => $this->company?->name),
            ],
            'channel' => [
                'id' => $this->channel_id ?? 'all',
                'name' => $this->channel_id ? 'Channel' : 'All Channels',
            ],

            // Official pricing dictionary
            'product_cost' => $cost,
            'previous_product_cost' => $this->previous_product_cost,
            'cost_difference' => $this->cost_difference,
            'cost_change_pct' => $this->previous_product_cost > 0
                ? round(($this->cost_difference / $this->previous_product_cost) * 100, 2)
                : null,
            // ── LAYER 1 — CURRENT (immutable snapshot taken when the review opened)
            'selling_price' => $this->selling_price,
            'sale_price' => $currentSalePrice,
            // Gross Profit % is measured on REGULAR; Net Margin % on SALE.
            'old_gross_profit_pct' => $this->grossProfitPct($cost, $this->selling_price),
            'old_margin_pct' => $this->netMarginPct($cost, $currentSalePrice, $this->selling_price),

            // ── LAYER 2 — SUGGESTED (pricing engine; NULL when it produced none)
            'suggested_selling_price' => $suggestedRegular,
            'suggested_sale_price' => $suggestedSale,
            'suggested_gross_profit_pct' => $this->grossProfitPct($cost, $suggestedRegular),
            'suggested_margin_pct' => $this->netMarginPct($cost, $suggestedSale, $suggestedRegular),

            // ── LAYER 3 — MANUAL / FINAL DECISION (what Approve applies)
            'manual_regular_price' => $this->manual_regular_price,
            'manual_sale_price' => $this->manual_sale_price,
            'final_regular_price' => $finalRegular,
            'final_sale_price' => $finalSale,
            'final_gross_profit_pct' => $this->grossProfitPct($cost, $finalRegular),
            'final_margin_pct' => $this->netMarginPct($cost, $finalSale, $finalRegular),

            'discount_pct' => $discountPct,
            'target_margin' => $targetMargin,
            'markup' => $markup,
            'current_margin' => $this->current_margin,
            // Legacy alias retained for existing consumers: gross profit % on CURRENT regular.
            'gross_profit_pct' => $this->grossProfitPct($cost, $this->selling_price),

            'impacts' => $this->impacts ?? [],
            'status' => $this->status->value,
            'reviewer' => $this->reviewer_name
                ? ['id' => null, 'name' => $this->reviewer_name]
                : null,
            'snooze_until' => $this->snooze_until?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
