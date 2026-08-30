<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * HTTP input for recording the ACTUAL delivered quantity of one allocation line
 * (T-09, ADR-015 §6.4). Mirrors OverrideAllocationRequest: the allocation is
 * addressed by id in the body, the session/assignment come from the route.
 *
 * The bounds here are only the schema's own floor (quantity_delivered >= 0). The
 * over-delivery ceiling is NOT validated at the HTTP layer on purpose — ADR-015
 * defines no over-delivery contract, so refusing `delivered > allocated` is the
 * domain writer's fail-closed responsibility (RecordProductDeliveryAction), and
 * this request must not duplicate or pre-empt that decision.
 */
final class RecordProductDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'allocation_record_id' => ['required', 'uuid'],
            'quantity_delivered' => ['required', 'numeric', 'min:0'],
            'actor_type' => ['nullable', 'in:driver,dispatcher'],
        ];
    }
}
