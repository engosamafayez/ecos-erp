<?php

declare(strict_types=1);

namespace Modules\Operations\ShippingOrders\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Commerce\Orders\Domain\Enums\PaymentState;
use Modules\Logistics\Distribution\Domain\Enums\TripType;
use Modules\Operations\ShippingOrders\Domain\Services\ShippingOrderReadModel;

/**
 * @mixin \Modules\Commerce\Orders\Domain\Models\Order
 */
final class ShippingOrderResource extends JsonResource
{
    public function __construct(
        $resource,
        private readonly ShippingOrderReadModel $readModel,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $classification = $this->readModel->classificationFor($this->resource);

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'brand' => $this->whenLoaded('channel', fn () => $this->channel?->relationLoaded('brand') && $this->channel->brand ? [
                'id' => $this->channel->brand->id,
                'name' => $this->channel->brand->name,
            ] : null),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer !== null ? [
                'name' => $this->customer_name ?? $this->customer->name,
                'code' => $this->customer->code,
            ] : null),
            'order_value' => $this->resolveOrderValue(),
            // `orders.payment_state` does not exist and `orders.payment_status`
            // exists but is never written (both confirmed dead in TASK-ECOS-
            // SHIPPING-AND-DRIVER-APP-USER-REVIEW-REMEDIATION-001) — payment status
            // is always DERIVED from deposit_amount vs total, the same authority
            // OrderResource/DistributionAggregationService already use for display.
            'payment_status' => PaymentState::fromAmounts(
                (float) ($this->deposit_amount ?? 0),
                (float) $this->total,
            )->value,
            'shipping_classification' => $classification?->value,
            'shipping_company' => $this->resolveShippingCompany(),
            'driver' => $this->resolveDriver(),
            'trip' => $this->resolveTrip(),
            'address' => [
                'shipping_address' => $this->shipping_address,
                'building' => $this->building,
                'floor' => $this->floor,
                'apartment' => $this->apartment,
                'landmark' => $this->landmark,
                'address_notes' => $this->address_notes,
                'area' => $this->area,
                'city' => $this->city,
                'governorate' => $this->governorate,
            ],
            'location' => $this->google_maps_lat !== null && $this->google_maps_lng !== null ? [
                'lat' => (float) $this->google_maps_lat,
                'lng' => (float) $this->google_maps_lng,
            ] : null,
        ];
    }

    /**
     * Canonical Order commercial total — TASK-...-IMPLEMENTATION-002 §19 ("ORDER VALUE"),
     * Architecture-001 §16 ("Order Value Authority"). Reused directly from OrderResource's own formula, not
     * recomputed independently: subtotal + shipping - discount + tax, the same
     * "single source of truth for all canonical financial fields" OrderResource's own
     * docblock claims. This page does not import OrderResource itself (a different
     * bounded context/read model), so the same, small, already-established formula is
     * restated here rather than either duplicating a much larger resource or reaching
     * across module boundaries for one field.
     */
    private function resolveOrderValue(): float
    {
        $rawDiscount = (float) ($this->discount_amount ?? 0);
        $subtotal = (float) ($this->subtotal ?? 0);
        $wcDiscount = (float) ($this->discount_total ?? 0);
        $discountAmt = match ($this->discount_type) {
            'percentage' => round($subtotal * $rawDiscount / 100, 2),
            'fixed' => $rawDiscount,
            default => max($rawDiscount, $wcDiscount),
        };
        $shippingAmt = $this->shipping_cost !== null
            ? (float) $this->shipping_cost
            : (float) ($this->shipping_total ?? 0);
        $taxAmt = (float) ($this->tax_total ?? 0);

        return round($subtotal + $shippingAmt - $discountAmt + $taxAmt, 2);
    }

    /**
     * §21 — internal fleet vs external carrier, from the Trip's own TripType, not the
     * legacy/unpopulated `orders.shipping_company_name` column (confirmed dead in
     * Architecture-001 §18) and not the WooCommerce-import-only `shipping_method`
     * column (a different, pre-Distribution concept). `shipping_company_name_resolved`
     * is attached by ShippingOrderController::index() via a batched lookup keyed on
     * the page's distinct `shipping_company_id`s — never a per-row query.
     *
     * @return array{type: string, name: string|null}
     */
    private function resolveShippingCompany(): array
    {
        $tripType = $this->getAttribute('trip_type');

        if ($tripType === TripType::ExternalCarrier->value) {
            return [
                'type' => 'external',
                'name' => $this->getAttribute('shipping_company_name_resolved'),
            ];
        }

        return ['type' => 'internal', 'name' => null];
    }

    /**
     * §22 — the driver associated with the actual delivery execution (this order's
     * Trip), never a stale historical Distribution Group planning-time selection,
     * which a later Trip assignment can supersede (Architecture-001 §19).
     *
     * @return array{name: string, code: string}|null
     */
    private function resolveDriver(): ?array
    {
        $name = $this->getAttribute('driver_full_name');
        $code = $this->getAttribute('driver_code');

        return $name !== null ? ['name' => $name, 'code' => $code] : null;
    }

    /**
     * TASK-ECOS-SHIPPING-OS-REDESIGN-003 §12 — concise execution context ("Trip,
     * stop position/progress"). `trip_uuid`/`trip_number`/`ds_sequence`/
     * `trip_stops_total` are all read from the SAME joined query this resource's
     * other trip-derived fields already use (`ShippingOrderReadModel::baseQuery()`)
     * — no new join. Null, not a fabricated placeholder, before this order has
     * reached a real Trip (Architecture-001 §4/§5's own population boundary —
     * this can only be non-null for a row this page shows at all, since the page's
     * eligibility already requires a DeliveryStop, but the Trip itself can still be
     * null this early — see the LEFT JOIN in `joinedQuery()`).
     *
     * @return array{id: string, number: string, stop_sequence: int, stop_total: int}|null
     */
    private function resolveTrip(): ?array
    {
        $id = $this->getAttribute('trip_uuid');
        $number = $this->getAttribute('trip_number');
        $stopsTotal = $this->getAttribute('trip_stops_total');

        if ($id === null || $number === null || $stopsTotal === null) {
            return null;
        }

        return [
            'id' => $id,
            'number' => $number,
            'stop_sequence' => (int) $this->getAttribute('ds_sequence'),
            'stop_total' => (int) $stopsTotal,
        ];
    }
}
