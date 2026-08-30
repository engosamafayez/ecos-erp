<?php

declare(strict_types=1);

namespace Modules\CostManagement\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * TASK-PRICING-REVIEW-SNOOZE-ASSIGN-HTTP-CONTRACT-REPAIR-001.
 *
 * `snooze()` used to typehint Illuminate\Http\Request, validate inline, and then
 * call `$request->validated('until')`. `validated()` exists only on FormRequest,
 * so a request that had already PASSED validation died with a
 * BadMethodCallException and surfaced as HTTP 500.
 *
 * The rules are carried over unchanged — this repairs the HTTP/validation
 * contract only. Snooze business semantics are untouched: a date strictly in the
 * future, moving an open review to `snoozed` without resolving it.
 *
 * Authorization stays on the route (`permission:inventory.price_review.update`),
 * matching ApprovePricingReviewRequest, which likewise defines no authorize().
 */
class SnoozePricingReviewRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'until' => ['required', 'date', 'after:today'],
        ];
    }
}
