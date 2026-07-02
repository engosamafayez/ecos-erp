<?php

declare(strict_types=1);

namespace Modules\CostManagement\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\CostManagement\Domain\Enums\PricingReviewStatus;
use Modules\CostManagement\Domain\Models\PricingReview;
use Modules\CostManagement\Domain\Services\PricingReviewService;
use Modules\CostManagement\Presentation\Http\Requests\ApprovePricingReviewRequest;
use Modules\CostManagement\Presentation\Http\Resources\PricingReviewResource;

class PricingReviewController extends Controller
{
    public function __construct(
        private readonly PricingReviewService $service,
    ) {}

    /**
     * GET /cost-management/pricing-reviews
     * List pending/filtered pricing reviews with summary counts.
     */
    public function index(Request $request): JsonResponse
    {
        $status  = $request->query('status', 'pending');
        $search  = $request->query('search');
        $perPage = (int) $request->query('per_page', 20);

        $query = PricingReview::query()
            ->with(['product.unit'])
            ->latest('created_at');

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($search) {
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('sku', 'ilike', "%{$search}%");
            });
        }

        $reviews = $query->paginate($perPage);

        // Summary counts for the UI tabs
        $summary = [
            'pending'      => PricingReview::query()->where('status', PricingReviewStatus::Pending->value)->count(),
            'approved'     => PricingReview::query()->where('status', PricingReviewStatus::Approved->value)->count(),
            'kept'         => PricingReview::query()->where('status', PricingReviewStatus::Kept->value)->count(),
            'custom_price' => PricingReview::query()->where('status', PricingReviewStatus::CustomPrice->value)->count(),
            'snoozed'      => PricingReview::query()->where('status', PricingReviewStatus::Snoozed->value)->count(),
        ];

        return response()->json([
            'data'       => PricingReviewResource::collection($reviews->items()),
            'pagination' => [
                'total'        => $reviews->total(),
                'per_page'     => $reviews->perPage(),
                'current_page' => $reviews->currentPage(),
                'last_page'    => $reviews->lastPage(),
            ],
            'summary' => $summary,
        ]);
    }

    /**
     * GET /cost-management/pricing-reviews/{id}/detail
     * Full detail: cost breakdown, recipe snapshot, price history.
     */
    public function detail(string $id): JsonResponse
    {
        $review = PricingReview::query()
            ->with([
                'product.unit',
                'product.activeRecipe.components',
                'approvals',
            ])
            ->findOrFail($id);

        $resource = new PricingReviewResource($review);
        $data     = $resource->toArray(request());

        // Augment with approval history
        $data['approvals'] = $review->approvals->map(fn ($a) => [
            'id'               => $a->id,
            'action'           => $a->action,
            'old_selling_price'=> $a->old_selling_price,
            'new_selling_price'=> $a->new_selling_price,
            'custom_price'     => $a->custom_price,
            'reason'           => $a->reason,
            'manager_name'     => $a->manager_name,
            'approved_channels'=> $a->approved_channels ?? [],
            'approved_at'      => $a->approved_at?->toIso8601String(),
        ])->values()->toArray();

        return response()->json(['data' => $data]);
    }

    /**
     * POST /cost-management/pricing-reviews/{id}/approve
     * Approve / keep / set custom price for a review.
     */
    public function approve(ApprovePricingReviewRequest $request, string $id): JsonResponse
    {
        $review = PricingReview::query()->findOrFail($id);

        if ($review->status->isResolved()) {
            return response()->json(['message' => 'This review has already been resolved.'], 422);
        }

        $approval = $this->service->resolve(
            review:      $review,
            action:      $request->validated('action'),
            customPrice: $request->validated('custom_price') !== null
                ? (float) $request->validated('custom_price')
                : null,
            reason:      $request->validated('reason'),
            managerName: $request->validated('manager_name'),
            channels:    (array) ($request->validated('channels') ?? []),
        );

        return response()->json([
            'message'  => 'Pricing review resolved.',
            'approval' => [
                'id'               => $approval->id,
                'action'           => $approval->action,
                'new_selling_price'=> $approval->new_selling_price,
                'approved_channels'=> $approval->approved_channels ?? [],
                'approved_at'      => $approval->approved_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /cost-management/pricing-reviews/{id}/snooze
     * Snooze a review until a date.
     */
    public function snooze(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'until' => ['required', 'date', 'after:today'],
        ]);

        $review = PricingReview::query()->findOrFail($id);

        if ($review->status->isResolved()) {
            return response()->json(['message' => 'This review has already been resolved.'], 422);
        }

        $this->service->snooze($review, $request->validated('until'));

        return response()->json(['message' => 'Review snoozed.']);
    }

    /**
     * POST /cost-management/pricing-reviews/{id}/assign
     * Assign a reviewer name to a review.
     */
    public function assign(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reviewer_name' => ['required', 'string', 'max:255'],
        ]);

        $review = PricingReview::query()->findOrFail($id);
        $this->service->assign($review, $request->validated('reviewer_name'));

        return response()->json(['message' => 'Reviewer assigned.']);
    }

    /**
     * POST /cost-management/pricing-reviews/bulk-approve
     * Bulk-approve multiple reviews with the same action.
     */
    public function bulkApprove(ApprovePricingReviewRequest $request): JsonResponse
    {
        $request->validate([
            'ids'    => ['required', 'array', 'min:1'],
            'ids.*'  => ['required', 'string'],
        ]);

        $ids     = (array) $request->input('ids', []);
        $reviews = PricingReview::query()->whereIn('id', $ids)->get();

        $resolved = 0;
        $skipped  = 0;

        foreach ($reviews as $review) {
            if ($review->status->isResolved()) {
                $skipped++;
                continue;
            }

            $this->service->resolve(
                review:      $review,
                action:      $request->validated('action'),
                customPrice: $request->validated('custom_price') !== null
                    ? (float) $request->validated('custom_price')
                    : null,
                reason:      $request->validated('reason'),
                managerName: $request->validated('manager_name'),
                channels:    (array) ($request->validated('channels') ?? []),
            );

            $resolved++;
        }

        return response()->json([
            'message'  => "Bulk approval complete: {$resolved} resolved, {$skipped} skipped.",
            'resolved' => $resolved,
            'skipped'  => $skipped,
        ]);
    }
}
