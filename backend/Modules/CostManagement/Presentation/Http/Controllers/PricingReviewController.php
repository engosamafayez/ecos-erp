<?php

declare(strict_types=1);

namespace Modules\CostManagement\Presentation\Http\Controllers;

use App\Core\Company\TenantOwnershipResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\ProductMappings\Domain\Enums\SyncStatus;
use Modules\Commerce\ProductMappings\Domain\Models\ProductMapping;
use Modules\CostManagement\Domain\Enums\PricingReviewStatus;
use Modules\CostManagement\Domain\Models\PricingReview;
use Modules\CostManagement\Domain\Services\PricingReviewService;
use Modules\CostManagement\Presentation\Http\Requests\ApprovePricingReviewRequest;
use Modules\CostManagement\Presentation\Http\Requests\AssignPricingReviewRequest;
use Modules\CostManagement\Presentation\Http\Requests\SnoozePricingReviewRequest;
use Modules\CostManagement\Presentation\Http\Resources\PricingReviewResource;

class PricingReviewController extends Controller
{
    public function __construct(
        private readonly PricingReviewService $service,
        private readonly TenantOwnershipResolver $tenant,
    ) {}

    /**
     * Every read and every mutation starts here.
     *
     * TASK-PRICE-REVIEW-ACTION-REPAIR-001 — this controller previously located
     * records with unscoped `findOrFail()` / `whereIn()`, so a review belonging
     * to another company was both readable and mutable. `pricing_reviews` carries
     * `company_id` directly, so ownership needs no relation hop.
     *
     * The resolver and the fail-closed predicate are the same ones the Inventory
     * domain already uses (ProductController::patch/stats, EloquentProductRepository)
     * — RC-6 / GD-1. Cross-company privilege flows only through the documented
     * is_system path; an actor with no company context is closed out, never
     * widened to the global pool.
     *
     * @return Builder<PricingReview>
     */
    private function scopedQuery(): Builder
    {
        $query = PricingReview::query();

        if ($this->tenant->appliesTo() && ! $this->tenant->isUnrestricted()) {
            $companyId = $this->tenant->companyId();

            if ($companyId === null) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('company_id', $companyId);
            }
        }

        return $query;
    }

    /**
     * GET /cost-management/pricing-reviews
     */
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');
        $search = $request->query('search');
        $productId = $request->query('product_id');
        $brandId = $request->query('brand_id');
        $perPage = (int) $request->query('per_page', 20);

        $query = $this->scopedQuery()
            ->with(['product.unit', 'product.brand'])
            ->latest('created_at');

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($productId) {
            $query->where('product_id', $productId);
        }

        if ($search) {
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if ($brandId) {
            $query->whereHas('product', function ($q) use ($brandId) {
                $q->where('brand_id', $brandId);
            });
        }

        $reviews = $query->paginate($perPage);

        // Summary counts — scoped identically to the table, so the KPI row and the
        // rows below it can never describe different sets.
        $summary = [
            'pending' => $this->scopedQuery()->where('status', PricingReviewStatus::Pending->value)->count(),
            'approved' => $this->scopedQuery()->where('status', PricingReviewStatus::Approved->value)->count(),
            'kept' => $this->scopedQuery()->where('status', PricingReviewStatus::Kept->value)->count(),
            'custom_price' => $this->scopedQuery()->where('status', PricingReviewStatus::CustomPrice->value)->count(),
            'snoozed' => $this->scopedQuery()->where('status', PricingReviewStatus::Snoozed->value)->count(),
            'rejected' => $this->scopedQuery()->where('status', PricingReviewStatus::Rejected->value)->count(),
            'below_brand_margin' => 0, // computed client-side from brand.default_target_margin vs current_margin
            'pending_publish' => $this->scopedQuery()->where('publish_status', 'pending_publish')->count(),
        ];

        return response()->json([
            'data' => PricingReviewResource::collection($reviews->items()),
            'pagination' => [
                'total' => $reviews->total(),
                'per_page' => $reviews->perPage(),
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
            ],
            'summary' => $summary,
        ]);
    }

    /**
     * GET /cost-management/pricing-reviews/{id}/detail
     */
    public function detail(string $id): JsonResponse
    {
        $review = $this->scopedQuery()
            ->with([
                'product.unit',
                'product.brand',
                'product.activeRecipe.components',
                'approvals',
                'company',
            ])
            ->findOrFail($id);

        $resource = new PricingReviewResource($review);
        $data = $resource->toArray(request());

        $data['approvals'] = $review->approvals->map(fn ($a) => [
            'id' => $a->id,
            'action' => $a->action,
            'old_selling_price' => $a->old_selling_price,
            'new_selling_price' => $a->new_selling_price,
            'custom_price' => $a->custom_price,
            'reason' => $a->reason,
            'manager_name' => $a->manager_name,
            'approved_by' => $a->approved_by,
            'approved_channels' => $a->approved_channels ?? [],
            'approved_at' => $a->approved_at?->toIso8601String(),
        ])->values()->toArray();

        return response()->json(['data' => $data]);
    }

    /**
     * POST /cost-management/pricing-reviews/{id}/approve
     *
     * Resolves the review AND applies the decided prices — there is no second
     * publish step (TASK-PRICE-REVIEW-ACTION-REPAIR-001 Part 7).
     */
    public function approve(ApprovePricingReviewRequest $request, string $id): JsonResponse
    {
        $review = $this->scopedQuery()->with('product')->findOrFail($id);

        if ($review->status->isResolved()) {
            return response()->json(['message' => 'This review has already been resolved.'], 422);
        }

        // A soft-deleted or missing product cannot carry a price. Without this the
        // service dereferences null and the caller gets a 500 for what is really a
        // business condition.
        if ($review->product === null) {
            return response()->json([
                'message' => 'The product for this review no longer exists, so its price cannot be applied.',
            ], 422);
        }

        // THE catalogue invariant: a published regular price must be strictly positive.
        //
        // This previously tested `=== null`, which let a 0 straight through — `min:0`
        // validation accepts 0, Laravel's filled(0) is true, and 0 is not null, so the
        // guard passed and `products.regular_price = 0` was written. The check now runs
        // on the effective price for the requested action, so it covers custom_price
        // and keep_current as well as approve_suggested.
        $customPriceInput = $request->validated('custom_price') !== null
            ? (float) $request->validated('custom_price')
            : null;

        if (! PricingReviewService::isApprovableAt($review, $request->validated('action'), $customPriceInput)) {
            return response()->json([
                'message' => 'This review has no valid final price. Enter a final regular price greater than zero before approving.',
                'errors' => ['final_regular_price' => ['The final regular price must be greater than zero.']],
            ], 422);
        }

        $approval = $this->service->resolve(
            review: $review,
            action: $request->validated('action'),
            customPrice: $request->validated('custom_price') !== null
                ? (float) $request->validated('custom_price')
                : null,
            reason: $request->validated('reason'),
            managerName: $request->validated('manager_name'),
            channels: (array) ($request->validated('channels') ?? []),
            approverId: $request->user()?->id,
        );

        return response()->json([
            'message' => 'Pricing review resolved.',
            'approval' => [
                'id' => $approval->id,
                'action' => $approval->action,
                'new_selling_price' => $approval->new_selling_price,
                'new_sale_price' => $approval->new_sale_price,
                'approved_by' => $approval->approved_by,
                'approved_channels' => $approval->approved_channels ?? [],
                'approved_at' => $approval->approved_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * PATCH /cost-management/pricing-reviews/{id}/inline
     * Inline editing: target_margin, markup, regular_price, sale_price.
     */
    public function inline(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'target_margin' => ['nullable', 'numeric', 'min:0', 'max:99.9999'],
            'markup' => ['nullable', 'numeric', 'min:0'],
            // A manual regular price is a price that will be published: it must be
            // strictly positive. sale_price keeps min:0 because 0 legitimately means
            // "clear the sale price" under the existing contract.
            'regular_price' => ['nullable', 'numeric', 'min:0.01'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'pricing_mode' => ['nullable', 'string', 'in:brand_policy,custom'],
        ]);

        $review = $this->scopedQuery()->with(['product.brand', 'company'])->findOrFail($id);
        $product = $review->product;

        if ($product === null) {
            return response()->json([
                'message' => 'The product for this review no longer exists.',
            ], 422);
        }

        $updated = [];

        // Resolve target_margin from either input
        $newMargin = null;
        if ($request->filled('markup')) {
            $mk = (float) $request->input('markup');
            $newMargin = round($mk / (100 + $mk) * 100, 4);
        } elseif ($request->filled('target_margin')) {
            $newMargin = (float) $request->input('target_margin');
        }

        if ($newMargin !== null) {
            $suggestedPrice = $newMargin < 100
                ? round($review->product_cost / (1 - $newMargin / 100), 4)
                : $review->product_cost;
            $discountPct = $product->effectiveDiscountPct();
            $suggestedSalePrice = round($suggestedPrice * (1 - $discountPct / 100), 4);

            // Asking the engine to re-derive supersedes any earlier manual figure,
            // so the recomputed suggestion becomes the decision again.
            $review->update([
                'target_margin' => $newMargin,
                'suggested_selling_price' => $suggestedPrice,
                'suggested_sale_price' => $suggestedSalePrice,
                'manual_regular_price' => null,
                'manual_sale_price' => null,
            ]);

            // Update product custom policy
            $product->update([
                'pricing_mode' => 'custom',
                'custom_target_margin' => $newMargin,
                'custom_markup' => $newMargin < 100
                    ? round($newMargin / (100 - $newMargin) * 100, 4)
                    : 0.0,
            ]);

            $updated[] = 'target_margin';
        }

        if ($request->filled('pricing_mode')) {
            $mode = $request->input('pricing_mode');
            $product->update(['pricing_mode' => $mode]);
            if ($mode === 'brand_policy') {
                $product->loadMissing('brand');
                $brandMargin = $product->brand?->default_target_margin ?? PricingReviewService::DEFAULT_TARGET_MARGIN;
                $suggestedPrice = $brandMargin < 100
                    ? round($review->product_cost / (1 - $brandMargin / 100), 4)
                    : $review->product_cost;
                $discountPct = $product->effectiveDiscountPct();
                $suggestedSalePrice = round($suggestedPrice * (1 - $discountPct / 100), 4);
                $review->update([
                    'target_margin' => $brandMargin,
                    'suggested_selling_price' => $suggestedPrice,
                    'suggested_sale_price' => $suggestedSalePrice,
                    'manual_regular_price' => null,
                    'manual_sale_price' => null,
                ]);
            }
            $updated[] = 'pricing_mode';
        }

        // MANUAL layer. Writes ONLY the operator's decision: the CURRENT snapshot
        // (`selling_price` / `current_sale_price`), the SUGGESTED figures and the
        // live product price are all left untouched. The catalogue is written by
        // Approve, not by editing — that is what makes the review a gate.
        if ($request->filled('regular_price')) {
            $review->update(['manual_regular_price' => (float) $request->input('regular_price')]);
            $updated[] = 'regular_price';
        }

        if ($request->has('sale_price')) {
            $saleInput = $request->input('sale_price');
            $review->update([
                'manual_sale_price' => $saleInput === null ? null : (float) $saleInput,
            ]);
            $updated[] = 'sale_price';
        }

        $review->load(['product.brand', 'product.unit', 'company']);

        return response()->json([
            'message' => 'Review updated.',
            'updated' => $updated,
            'review' => (new PricingReviewResource($review))->toArray($request),
        ]);
    }

    /**
     * POST /cost-management/pricing-reviews/{id}/snooze
     */
    public function snooze(SnoozePricingReviewRequest $request, string $id): JsonResponse
    {
        // Validation lives in the FormRequest, matching approve()/bulkApprove().
        // The previous signature took a base Illuminate\Http\Request and then
        // called validated() on it — a FormRequest-only method — so a request
        // that had already PASSED validation died with BadMethodCallException
        // and surfaced as a 500. Rules are unchanged; only the contract moved.
        $review = $this->scopedQuery()->findOrFail($id);

        if ($review->status->isResolved()) {
            return response()->json(['message' => 'This review has already been resolved.'], 422);
        }

        $this->service->snooze($review, $request->validated('until'));

        return response()->json(['message' => 'Review snoozed.']);
    }

    /**
     * POST /cost-management/pricing-reviews/{id}/assign
     */
    public function assign(AssignPricingReviewRequest $request, string $id): JsonResponse
    {
        // Same contract repair as snooze() — see SnoozePricingReviewRequest.
        $review = $this->scopedQuery()->findOrFail($id);

        $this->service->assign($review, $request->validated('reviewer_name'));

        return response()->json(['message' => 'Reviewer assigned.']);
    }

    /**
     * POST /cost-management/pricing-reviews/bulk-approve
     *
     * Fails closed: if any requested id is not resolvable within the caller's
     * tenant scope, nothing is mutated and the response does not disclose whether
     * the record exists elsewhere.
     */
    public function bulkApprove(ApprovePricingReviewRequest $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'string'],
        ]);

        $ids = array_values(array_unique(array_map('strval', (array) $request->input('ids', []))));
        $reviews = $this->scopedQuery()->with('product')->whereIn('id', $ids)->get();

        if ($reviews->count() !== count($ids)) {
            return response()->json([
                'message' => 'One or more pricing reviews were not found.',
            ], 404);
        }

        $withoutProduct = $reviews->filter(fn (PricingReview $review) => $review->product === null);

        if ($withoutProduct->isNotEmpty()) {
            return response()->json([
                'message' => 'One or more reviews reference a product that no longer exists.',
            ], 422);
        }

        $action = $request->validated('action');
        $customPrice = $request->validated('custom_price') !== null
            ? (float) $request->validated('custom_price')
            : null;

        // Same catalogue invariant as approve(), enforced with the same fail-closed
        // atomicity this endpoint already uses for unknown ids and missing products:
        // every row is checked BEFORE the transaction opens, and one invalid row
        // rejects the whole operation. Bulk must never be weaker than single.
        $unapprovable = $reviews->filter(
            fn (PricingReview $review) => ! $review->status->isResolved()
                && ! PricingReviewService::isApprovableAt($review, $action, $customPrice),
        );

        if ($unapprovable->isNotEmpty()) {
            return response()->json([
                'message' => 'One or more reviews have no valid final price. Enter a final regular price greater than zero before approving.',
                'errors' => ['final_regular_price' => ['The final regular price must be greater than zero.']],
            ], 422);
        }

        $reason = $request->validated('reason');
        $managerName = $request->validated('manager_name');
        $channels = (array) ($request->validated('channels') ?? []);
        $approverId = $request->user()?->id;

        [$resolved, $skipped] = DB::transaction(function () use (
            $reviews,
            $action,
            $customPrice,
            $reason,
            $managerName,
            $channels,
            $approverId,
        ): array {
            $resolved = 0;
            $skipped = 0;

            foreach ($reviews as $review) {
                if ($review->status->isResolved()) {
                    $skipped++;

                    continue;
                }

                $this->service->resolve(
                    review: $review,
                    action: $action,
                    customPrice: $customPrice,
                    reason: $reason,
                    managerName: $managerName,
                    channels: $channels,
                    approverId: $approverId,
                );

                $resolved++;
            }

            return [$resolved, $skipped];
        });

        return response()->json([
            'message' => "Bulk approval complete: {$resolved} resolved, {$skipped} skipped.",
            'resolved' => $resolved,
            'skipped' => $skipped,
        ]);
    }

    /**
     * POST /cost-management/pricing-reviews/bulk-policy
     * Apply brand policy, set target margin, or set markup to multiple reviews at once.
     *
     * Fails closed on any id outside the caller's tenant scope.
     */
    public function bulkPolicy(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'string'],
            'action' => ['required', 'string', 'in:apply_brand_policy,set_target_margin,set_markup,snooze'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'snooze_until' => ['nullable', 'date', 'after:today'],
        ]);

        $ids = array_values(array_unique(array_map('strval', (array) $request->input('ids'))));
        $action = (string) $request->input('action');
        $reviews = $this->scopedQuery()->with(['product.brand'])->whereIn('id', $ids)->get();

        if ($reviews->count() !== count($ids)) {
            return response()->json([
                'message' => 'One or more pricing reviews were not found.',
            ], 404);
        }

        $processed = DB::transaction(function () use ($reviews, $action, $request): int {
            $processed = 0;

            foreach ($reviews as $review) {
                $product = $review->product;

                switch ($action) {
                    case 'apply_brand_policy':
                        $brandMargin = $product?->brand?->default_target_margin ?? PricingReviewService::DEFAULT_TARGET_MARGIN;
                        $brandDiscountPct = $product?->brand?->default_discount_pct ?? 0.0;
                        $product?->update(['pricing_mode' => 'brand_policy']);
                        $suggestedPrice = $brandMargin < 100
                            ? round($review->product_cost / (1 - $brandMargin / 100), 4)
                            : $review->product_cost;
                        $review->update([
                            'target_margin' => $brandMargin,
                            'suggested_selling_price' => $suggestedPrice,
                            'suggested_sale_price' => round($suggestedPrice * (1 - $brandDiscountPct / 100), 4),
                            'manual_regular_price' => null,
                            'manual_sale_price' => null,
                        ]);
                        break;

                    case 'set_target_margin':
                        $margin = (float) ($request->input('value') ?? PricingReviewService::DEFAULT_TARGET_MARGIN);
                        $suggestedPrice = $margin < 100
                            ? round($review->product_cost / (1 - $margin / 100), 4)
                            : $review->product_cost;
                        $discountPct = $product?->effectiveDiscountPct() ?? 0.0;
                        $review->update([
                            'target_margin' => $margin,
                            'suggested_selling_price' => $suggestedPrice,
                            'suggested_sale_price' => round($suggestedPrice * (1 - $discountPct / 100), 4),
                            'manual_regular_price' => null,
                            'manual_sale_price' => null,
                        ]);
                        $product?->update([
                            'pricing_mode' => 'custom',
                            'custom_target_margin' => $margin,
                            'custom_markup' => $margin < 100 ? round($margin / (100 - $margin) * 100, 4) : 0.0,
                        ]);
                        break;

                    case 'set_markup':
                        $mk = (float) ($request->input('value') ?? 0);
                        $margin = round($mk / (100 + $mk) * 100, 4);
                        $suggestedPrice = $margin < 100
                            ? round($review->product_cost / (1 - $margin / 100), 4)
                            : $review->product_cost;
                        $discountPct = $product?->effectiveDiscountPct() ?? 0.0;
                        $review->update([
                            'target_margin' => $margin,
                            'suggested_selling_price' => $suggestedPrice,
                            'suggested_sale_price' => round($suggestedPrice * (1 - $discountPct / 100), 4),
                            'manual_regular_price' => null,
                            'manual_sale_price' => null,
                        ]);
                        $product?->update([
                            'pricing_mode' => 'custom',
                            'custom_target_margin' => $margin,
                            'custom_markup' => $mk,
                        ]);
                        break;

                    case 'snooze':
                        if (! $review->status->isResolved()) {
                            $until = $request->input('snooze_until') ?? now()->addDays(3)->toDateString();
                            $this->service->snooze($review, $until);
                        }
                        break;
                }

                $processed++;
            }

            return $processed;
        });

        return response()->json([
            'message' => "Bulk policy applied: {$processed} reviews updated.",
            'processed' => $processed,
        ]);
    }

    /**
     * POST /cost-management/pricing-reviews/{id}/publish
     *
     * Legacy path. Approve now applies prices directly, so no new review reaches
     * `pending_publish`; this remains only to drain rows staged by the previous
     * behaviour.
     */
    public function publish(string $id): JsonResponse
    {
        $review = $this->scopedQuery()
            ->with(['product'])
            ->findOrFail($id);

        if ($review->publish_status !== 'pending_publish') {
            return response()->json(['message' => 'This review is not pending publish.'], 422);
        }

        if ($review->approved_price === null) {
            return response()->json(['message' => 'No approved price stored for this review.'], 422);
        }

        $product = $review->product;

        if ($product === null) {
            return response()->json([
                'message' => 'The product for this review no longer exists.',
            ], 422);
        }

        DB::transaction(function () use ($review, $product): void {
            $product->update([
                'regular_price' => $review->approved_price,
                'sale_price' => ($review->approved_sale_price !== null && $review->approved_sale_price > 0.0)
                    ? $review->approved_sale_price
                    : null,
            ]);

            ProductMapping::query()
                ->where('product_id', $product->id)
                ->update(['sync_status' => SyncStatus::Pending->value]);

            $review->update([
                'publish_status' => 'published',
                'published_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Prices published successfully.']);
    }

    /**
     * GET /cost-management/pricing-reviews/badge
     *
     * Lightweight endpoint for the nav badge and dashboard widget.
     */
    public function badge(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');

        $base = fn () => $this->scopedQuery()
            ->where('status', PricingReviewStatus::Pending->value)
            ->when($companyId, fn (Builder $q) => $q->where('company_id', $companyId));

        $pending = $base()->count();

        $increases = $base()
            ->selectRaw('COALESCE(SUM(CASE WHEN cost_difference > 0 THEN cost_difference ELSE 0 END), 0) AS total')
            ->value('total') ?? 0;

        $decreases = $base()
            ->selectRaw('COALESCE(SUM(CASE WHEN cost_difference < 0 THEN ABS(cost_difference) ELSE 0 END), 0) AS total')
            ->value('total') ?? 0;

        $productsIncreased = $base()->where('cost_difference', '>', 0)->count();
        $productsDecreased = $base()->where('cost_difference', '<', 0)->count();

        $largestIncrease = $base()
            ->where('cost_difference', '>', 0)
            ->max('cost_difference') ?? 0;

        $largestDecrease = $base()
            ->where('cost_difference', '<', 0)
            ->selectRaw('MAX(ABS(cost_difference)) AS val')
            ->value('val') ?? 0;

        return response()->json([
            'data' => [
                'pending' => $pending,
                'total_cost_increase' => round((float) $increases, 4),
                'total_cost_decrease' => round((float) $decreases, 4),
                'products_increased' => $productsIncreased,
                'products_decreased' => $productsDecreased,
                'largest_increase' => round((float) $largestIncrease, 4),
                'largest_decrease' => round((float) $largestDecrease, 4),
            ],
        ]);
    }
}
