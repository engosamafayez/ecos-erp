<?php

declare(strict_types=1);

namespace Modules\CostManagement\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Commerce\ProductMappings\Domain\Enums\SyncStatus;
use Modules\Commerce\ProductMappings\Domain\Models\ProductMapping;
use Modules\CostManagement\Domain\Enums\PricingReviewStatus;
use Modules\CostManagement\Domain\Events\PriceReviewApproved;
use Modules\CostManagement\Domain\Events\PriceReviewCreated;
use Modules\CostManagement\Domain\Events\PriceReviewRejected;
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
    public const DEFAULT_TARGET_MARGIN = 30.0;

    /**
     * Create or update a pending pricing review for a finished product.
     *
     * Rules enforced here:
     *  - If product_cost did not actually change: returns null (no-op).
     *  - If an open (pending/snoozed) review already exists: update it in-place.
     *  - Otherwise: create a new Pending review.
     *
     * Callers should call this inside the same DB transaction as the cost update
     * so that a concurrent cascade for the same product cannot race to create two rows.
     */
    public function upsertForProduct(
        Product $product,
        float $newProductCost,
        float $previousProductCost,
        string $companyId,
        ?string $historyId,
        ?string $triggerReason = null,
        ?string $triggerSource = null,
        ?array $costSnapshot = null,
        ?string $explanation = null,
    ): ?PricingReview {
        // No-op: cost did not move
        if (abs($newProductCost - $previousProductCost) < 0.0001) {
            return null;
        }

        $product->loadMissing('brand');
        $sellingPrice = (float) ($product->regular_price ?? 0.0);
        // CURRENT layer snapshot — frozen for the life of this review.
        $currentSalePrice = $product->sale_price !== null ? (float) $product->sale_price : null;
        $targetMargin = $product->effectiveTargetMargin();
        $suggestedPrice = $targetMargin < 100
            ? round($newProductCost / (1 - $targetMargin / 100), 4)
            : $newProductCost;
        $discountPct = $product->effectiveDiscountPct();
        $suggestedSalePrice = round($suggestedPrice * (1 - $discountPct / 100), 4);
        $currentMargin = $sellingPrice > 0
            ? round((($sellingPrice - $newProductCost) / $sellingPrice) * 100, 4)
            : 0.0;

        $diff = $newProductCost - $previousProductCost;
        $impacts = $diff > 0 ? ['cost_increased'] : ['cost_decreased'];
        if ($currentMargin < $targetMargin) {
            $impacts[] = 'margin_below_target';
        }
        $impacts = array_values(array_unique($impacts));

        // Look for an open review (pending or snoozed) for this product+company
        $existing = PricingReview::query()
            ->where('product_id', $product->id)
            ->where('company_id', $companyId)
            ->whereNull('channel_id')
            ->whereIn('status', [
                PricingReviewStatus::Pending->value,
                PricingReviewStatus::Snoozed->value,
            ])
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            // Keep the original previous_cost so the delta shows total drift.
            // cost_snapshot is refreshed each update (latest state wins); explanation is appended.
            $updateData = [
                'product_cost' => round($newProductCost, 4),
                'cost_difference' => round($newProductCost - (float) ($existing->previous_product_cost ?? $previousProductCost), 4),
                'selling_price' => $sellingPrice,
                'suggested_selling_price' => $suggestedPrice,
                'suggested_sale_price' => $suggestedSalePrice,
                'current_margin' => $currentMargin,
                'impacts' => $impacts,
                'status' => PricingReviewStatus::Pending->value,
                'snooze_until' => null,
                // A fresh cost movement re-opens the decision: the earlier manual
                // figure was chosen against a cost that no longer applies, so the
                // newly derived suggestion becomes the default again.
                'manual_regular_price' => null,
                'manual_sale_price' => null,
                'current_sale_price' => $currentSalePrice,
                'triggered_by_cost_history_id' => $historyId,
                'trigger_reason' => $triggerReason,
                'trigger_source' => $triggerSource,
            ];
            if ($costSnapshot !== null) {
                $updateData['cost_snapshot'] = $costSnapshot;
            }
            if ($explanation !== null) {
                $updateData['explanation'] = $explanation;
            }
            $existing->update($updateData);

            return $existing->fresh();
        }

        $review = PricingReview::query()->create([
            'product_id' => $product->id,
            'company_id' => $companyId,
            'channel_id' => null,
            'product_cost' => round($newProductCost, 4),
            'previous_product_cost' => round($previousProductCost, 4),
            'cost_difference' => round($diff, 4),
            'selling_price' => $sellingPrice,
            'current_sale_price' => $currentSalePrice,
            'suggested_selling_price' => $suggestedPrice,
            'suggested_sale_price' => $suggestedSalePrice,
            'target_margin' => $targetMargin,
            'current_margin' => $currentMargin,
            'impacts' => $impacts,
            'status' => PricingReviewStatus::Pending->value,
            'triggered_by_cost_history_id' => $historyId,
            'trigger_reason' => $triggerReason,
            'trigger_source' => $triggerSource,
            'cost_snapshot' => $costSnapshot,
            'explanation' => $explanation,
        ]);

        PriceReviewCreated::dispatch(
            $review->id,
            $product->id,
            $companyId,
            $previousProductCost,
            $newProductCost,
            $triggerReason ?? 'cost_changed',
            $triggerSource,
        );

        return $review;
    }

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
        $product->loadMissing('brand');
        $sellingPrice = (float) ($product->regular_price ?? 0.0);
        // CURRENT layer snapshot — frozen for the life of this review.
        $currentSalePrice = $product->sale_price !== null ? (float) $product->sale_price : null;
        $targetMargin = $product->effectiveTargetMargin();

        // Suggested Selling Price = cost / (1 - margin%)
        $suggestedPrice = $targetMargin < 100
            ? round($newProductCost / (1 - $targetMargin / 100), 4)
            : $newProductCost;

        $discountPct = $product->effectiveDiscountPct();
        $suggestedSalePrice = round($suggestedPrice * (1 - $discountPct / 100), 4);

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
            'product_id' => $product->id,
            'company_id' => $companyId,
            'channel_id' => $channelId,
            'product_cost' => round($newProductCost, 4),
            'previous_product_cost' => round($previousProductCost, 4),
            'cost_difference' => round($newProductCost - $previousProductCost, 4),
            'selling_price' => $sellingPrice,
            'current_sale_price' => $currentSalePrice,
            'suggested_selling_price' => $suggestedPrice,
            'suggested_sale_price' => $suggestedSalePrice,
            'target_margin' => $targetMargin,
            'current_margin' => $currentMargin,
            'impacts' => array_values(array_unique($impacts)),
            'status' => PricingReviewStatus::Pending->value,
            'triggered_by_cost_history_id' => $triggeredByCostHistoryId,
        ]);
    }

    /**
     * The regular price a given action will publish to the catalogue.
     *
     * TASK-PRICE-REVIEW-ZERO-PRICE-GUARD-REPAIR-001 — extracted verbatim from resolve()
     * so the pre-approval guard and the mutation itself read the SAME selection. It is
     * not a second resolver: `approve_suggested` still defers to
     * PricingReview::finalSellingPrice(), which is the canonical
     * manual-?? -suggested decision.
     *
     * Returns null only when nothing at all has been decided.
     */
    public static function effectiveRegularPriceFor(
        PricingReview $review,
        string $action,
        ?float $customPrice,
    ): ?float {
        return match ($action) {
            'approve_suggested' => $review->finalSellingPrice(),
            'custom_price' => $customPrice ?? $review->selling_price,
            default => $review->selling_price,
        };
    }

    /**
     * Whether $action may be published for this review.
     *
     * THE invariant this guard exists for: a catalogue price must be strictly
     * positive. `reject` is exempt because it writes no price at all.
     */
    public static function isApprovableAt(
        PricingReview $review,
        string $action,
        ?float $customPrice,
    ): bool {
        if ($action === 'reject') {
            return true;
        }

        $price = self::effectiveRegularPriceFor($review, $action, $customPrice);

        return $price !== null && $price > 0.0;
    }

    /**
     * Approve/resolve a pricing review, apply the decided prices, and close it.
     *
     * TASK-PRICE-REVIEW-ACTION-REPAIR-001 — two contract points:
     *
     * 1. $approverId is `?int` because it carries `users.id`, which is bigint
     *    (ADR-040). It was previously typed `?string`; under strict_types the
     *    controller's `$request->user()?->id` raised a TypeError at this call
     *    boundary, so no approval was ever written. `price_approvals.approved_by`
     *    is aligned to the same type by the 2026_08_13_120000 migration.
     *
     * 2. Approve APPLIES. The brand `publishing_strategy` used to fork here, and
     *    `approval_only` staged the decided prices into the review while leaving
     *    the product untouched, pending a second Publish call. The approved
     *    workflow is that resolving the review IS the approval gate, so the
     *    decided price becomes the product's effective price in every case. No
     *    price is computed differently — only the destination changed, and the
     *    staged columns are still recorded for audit.
     *
     * @param  array<string>  $channels  e.g. ['pos','website','wholesale']
     */
    public function resolve(
        PricingReview $review,
        string $action,
        ?float $customPrice,
        ?string $reason,
        ?string $managerName,
        array $channels,
        ?int $approverId = null,
    ): PriceApproval {
        return DB::transaction(function () use (
            $review,
            $action,
            $customPrice,
            $reason,
            $managerName,
            $channels,
            $approverId,
        ): PriceApproval {
            $product = $review->product;
            $product->loadMissing('brand');

            $oldPrice = $review->selling_price;
            $oldSalePrice = (float) ($product->sale_price ?? 0.0);

            $newPrice = self::effectiveRegularPriceFor($review, $action, $customPrice);

            $newSalePrice = null;
            $discountPct = $product->effectiveDiscountPct();

            // Reject is the one action that never touches price — unchanged contract.
            if ($action !== 'reject') {
                // approve_suggested defers to the same decision order for the sale
                // price (manual sale → derived from a manual regular → suggestion).
                // keep_current / custom_price derive sale from the chosen price.
                $newSalePrice = $action === 'approve_suggested'
                    ? $review->finalSalePrice($discountPct)
                    : round($newPrice * (1 - $discountPct / 100), 4);

                $effectiveSalePrice = $newSalePrice > 0.0 ? $newSalePrice : null;

                // Apply: the decided price becomes the product's effective price.
                $product->update([
                    'regular_price' => $newPrice,
                    'sale_price' => $effectiveSalePrice,
                ]);

                ProductMapping::query()
                    ->where('product_id', $product->id)
                    ->update(['sync_status' => SyncStatus::Pending->value]);

                // Record what was approved and that it is live. `approved_price` /
                // `approved_sale_price` remain the audit of the decided figures.
                $review->update([
                    'approved_price' => $newPrice,
                    'approved_sale_price' => $effectiveSalePrice,
                    'publish_status' => 'published',
                    'published_at' => now(),
                ]);
            }

            $marginPct = $newPrice > 0
                ? round((($newPrice - $review->product_cost) / $newPrice) * 100, 4)
                : 0.0;

            // Create audit record
            $approval = PriceApproval::query()->create([
                'pricing_review_id' => $review->id,
                'product_id' => $review->product_id,
                'old_product_cost' => $review->previous_product_cost ?? $review->product_cost,
                'new_product_cost' => $review->product_cost,
                'old_selling_price' => $oldPrice,
                'new_selling_price' => $newPrice,
                'old_sale_price' => $oldSalePrice > 0.0 ? $oldSalePrice : null,
                'new_sale_price' => $newSalePrice,
                'margin_pct' => $marginPct,
                'discount_pct' => $discountPct,
                'action' => $action,
                'custom_price' => $action === 'custom_price' ? $customPrice : null,
                'reason' => $reason,
                'manager_name' => $managerName,
                'approved_by' => $approverId,
                'approved_channels' => $channels,
                'approved_at' => now(),
                'created_at' => now(),
            ]);

            // Mark review as resolved
            $status = match ($action) {
                'approve_suggested' => PricingReviewStatus::Approved,
                'keep_current' => PricingReviewStatus::Kept,
                'custom_price' => PricingReviewStatus::CustomPrice,
                'reject' => PricingReviewStatus::Rejected,
                default => PricingReviewStatus::Approved,
            };
            $review->resolve($status);

            $actor = $approverId !== null ? (string) $approverId : ($managerName ?? 'unknown');

            // Fire domain events
            if ($action === 'reject') {
                PriceReviewRejected::dispatch(
                    $review->id,
                    $review->product_id,
                    $review->company_id,
                    $actor,
                    $reason,
                );
            } else {
                PriceReviewApproved::dispatch(
                    $review->id,
                    $review->product_id,
                    $review->company_id,
                    $actor,
                    $newPrice,
                    $newSalePrice,
                    $marginPct,
                    $discountPct,
                );
            }

            return $approval;
        });
    }

    /**
     * Snooze a review until a given date.
     */
    public function snooze(PricingReview $review, string $until): void
    {
        $review->update([
            'status' => PricingReviewStatus::Snoozed->value,
            'snooze_until' => $until,
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
