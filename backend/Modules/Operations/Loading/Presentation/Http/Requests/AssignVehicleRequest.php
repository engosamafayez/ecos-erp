<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AssignVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'uuid'],
            'vehicle_registration' => ['required', 'string', 'max:50'],
            'vehicle_type' => ['required', 'string', 'max:50'],
            // ECOS CAPACITY CONTRACT — capacity is an ORDER COUNT and nothing
            // else. These three are accepted so the pre-existing standalone
            // Loading clients keep working, but they are NOT requirements and
            // nothing reads them to make a decision. `nullable` rather than a
            // default of 0: an absent value means "not measured", while a 0
            // would mean "measured, and it carries nothing" — and only the
            // first is true.
            'capacity_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'capacity_volume_m3' => ['nullable', 'numeric', 'min:0'],
            'refrigerated' => ['nullable', 'boolean'],
            'vehicle_plan_slot_id' => ['nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
