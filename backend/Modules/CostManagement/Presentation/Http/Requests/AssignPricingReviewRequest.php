<?php

declare(strict_types=1);

namespace Modules\CostManagement\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-PRICING-REVIEW-SNOOZE-ASSIGN-HTTP-CONTRACT-REPAIR-001.
 *
 * `assign()` carried the identical defect to snooze(): a base Illuminate\Http\Request
 * typehint followed by `$request->validated('reviewer_name')`, which only
 * FormRequest provides. It threw after validation had passed, returning HTTP 500
 * on an otherwise valid request.
 *
 * The rules are carried over unchanged. Assign business semantics are untouched:
 * it records a reviewer name and is NOT a resolution — the review stays in its
 * current state and no price moves.
 *
 * Authorization stays on the route (`permission:inventory.price_review.update`).
 */
class AssignPricingReviewRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string|int>>
     */
    public function rules(): array
    {
        return [
            'reviewer_name' => ['required', 'string', 'max:255'],
        ];
    }
}
