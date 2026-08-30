<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;

final class PatchOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $statuses = array_column(OrderStatus::cases(), 'value');

        return [
            'status' => ['sometimes', 'string', Rule::in($statuses)],
            'area' => ['sometimes', 'nullable', 'string', 'max:255'],
            // CITY was missing from this request, and that absence WAS the stale-zone
            // defect. `area` and `delivery_zone` are free-text labels with no catalog
            // and nothing derives a zone from either, so an operator changing an
            // Order's location from the grid could only ever edit a display string
            // while `city` — the one field that resolves to `logistics_city_id` and
            // therefore to a Distribution zone — stayed at its original value.
            //
            // Same 100-char bound as UpdateOrderRequest, which already accepted it:
            // the column is varchar(100) and the two edit surfaces must not disagree
            // about what fits.
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'governorate' => ['sometimes', 'nullable', 'string', 'max:255'],
            'google_maps_lat' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'google_maps_lng' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'google_maps_url' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // A3 — inline payment-method edit from the Orders grid. Constrained to the same
            // five-value catalogue the create/update gates enforce, so an unknown method can
            // never slip past ConfirmOrderWorkflow's proof requirement.
            'payment_method_manual' => ['sometimes', 'string', 'in:cod,instapay,mobile_wallet,credit_card,bank_transfer'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
