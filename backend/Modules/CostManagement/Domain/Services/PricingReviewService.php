<?php

declare(strict_types=1);

namespace Modules\CostManagement\Domain\Services;

use Illuminate\Support\Collection;
use Modules\CostManagement\Domain\Enums\PricingReviewStatus;
use Modules\CostManagement\Domain\Models\PriceApproval;
use Modules\CostManagement\Domain\Models\PricingReview;
use Modules\Inventory\Products\Domain\Models\Product;

/**
 * Manages the Price Review Center queue (Parts 5 & 7).
 *
 * When Product Cost changes, one pricing_review is created per product.
 * Management resolves each review. Selling Price never changes automatically.
 *
 * Default target_margin: 30%. Products should configure their own margin
 * target on the product record (future extension).
 */
final class PricingReviewService
{
    private const DEFAULT_TARGET_MARGIN = 30.0;

    /**
     * Create a pricing review for a finished product whose cost just changed.
     */
    public function createForProduct(
        Product $product,
        float $newProductCost,
        float $previousProductCost,
        array $impacts,
        string $companyId,
        ?string $channelId,
        ?string $triggeredByCostHistoryId,
    ): PricingReview {
        $sellingPrice  = (float) ($product->regular_price ?? 0.0);
        $targetMargin  = self::DEFAULT_TARGET_MARGIN;

        // Suggested Selling Price = cost / (1 - margin%)
        $suggestedPrice = $targetMargin < 100
            ? round($newProductCost / (1 - $targetMargin / 100), 4)
            : $newProductCost;

        $currentMargin = $sellingPrice > 0
            ? round((($sellingPrice - $newProductCost) / $sellingPrice) * 100, 4)
            : 0.0;

        // Resolve dominant impact flags
        if (empty($impacts)) {
            $diff = $newProductCost - $previousProductCost;
            $impacts = $diff > 0 ? ['cost_increased'] : ($diff < 0 ? ['cost_decreased'] : []);
        }
        if ($currentMargin < $targetMargin) {
            $impacts = array_unique(array_merge($impacts, ['margin_below_target']));
        }

        return PricingReview::query()->create([
            'product_id'                   => $product->id,
            'company_id'                   => $companyId,
            'channel_id'                   => $channelId,
            'product_cost'                 => round($newProductCost, 4),
            'previous_product_cost'        => round($previousProductCost, 4),
            'cost_difference'              => round($newProductCost - $previousProductCost, 4),
            'selling_price'                => $sellingPrice,
            'suggested_selling_price'      => $suggestedPrice,
            'target_margin'                => $targetMargin,
            'current_margin'               => $currentMargin,
            'impacts'                      => array_values(array_unique($impacts)),
            'status'                       => PricingReviewStatus::Pending->value,
            'triggered_by_cost_history_id' => $triggeredByCostHistoryId,
        ]);
    }

    /**
     * Approve/resolve a pricing review and publish selling price.
     *
     * @param array<string> $channels  e.g. ['pos','website','wholesale']
     */
    public function resolve(
        PricingReview $review,
        string $action,
        ?float $customPrice,
        ?string $reason,
        ?string $managerName,
        array $channels,
    ): PriceApproval {
        $product       = $review->product;
        $oldPrice      = $review->selling_price;
        $newPrice      = match ($action) {
            'approve_suggested' => $review->suggested_selling_price,
            'keep_current'      => $review->selling_price,
            'custom_price'      => $customPrice ?? $review->selling_price,
            default             => $review->selling_price,
        };

        // Update selling price if changed and channels includes website/pos
        if ($newPrice !== $oldPrice) {
            $product->update(['regular_price' => $newPrice]);
        }

        // Create audit record
        $approval = PriceApproval::query()->create([
            'pricing_review_id' => $review->id,
            'product_id'        => $review->product_id,
            'old_product_cost'  => $review->previous_product_cost ?? $review->product_cost,
            'new_product_cost'  => $review->product_cost,
            'old_selling_price' => $oldPrice,
            'new_selling_price' => $newPrice,
            'action'            => $action,
            'custom_price'      => $action === 'custom_price' ? $customPrice : null,
            'reason'            => $reason,
            'manager_name'      => $managerName,
            'approved_channels' => $channels,
            'approved_at'       => now(),
            'created_at'        => now(),
        ]);

        // Mark review as resolved
        $status = match ($action) {
            'approve_suggested' => PricingReviewStatus::Approved,
            'keep_current'      => PricingReviewStatus::Kept,
            'custom_price'      => PricingReviewStatus::CustomPrice,
            default             => PricingReviewStatus::Approved,
        };
        $review->resolve($status);

        return $approval;
    }

    /**
     * Snooze a review until a given date.
     */
    public function snooze(PricingReview $review, string $until): void
    {
        $review->update([
            'status'      => PricingReviewStatus::Snoozed->value,
            'snooze_until'=> $until,
        ]);
    }

    /**
     * Assign a reviewer to a review.
     */
    public function assign(PricingReview $review, string $reviewerName): void
    {
        $review->update(['reviewer_name' => $reviewerName]);
    }
}
