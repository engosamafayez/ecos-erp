<?php

declare(strict_types=1);

namespace Modules\Crm\SelfService\Domain\Services;

use BackedEnum;
use Illuminate\Support\Carbon;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\PaymentProof;
use Modules\Crm\SelfService\Domain\Models\CustomerTrackingToken;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;

/**
 * TASK-ECOS-V1.1-CRM-04-SECURE-SELF-SERVICE-BACKEND-IMPLEMENTATION-019 §7/§8/§9/§10 — the ONE
 * bounded, read-only aggregation over canonical Commerce/Logistics authorities. Never writes
 * Order.status, never duplicates FulfillmentEngine, never invents a fulfilment transition —
 * `Order::booted()`'s own `updating()` guard already makes any direct status write from here
 * throw, so this class structurally cannot become a second lifecycle authority even by mistake.
 *
 * Every field returned here is deliberately a SUBSET of what the internal (staff-facing)
 * OrderResource exposes — see the excluded-fields list in the class docblock of
 * CustomerOrderController. This class does not read OrderResource at all; it is built directly
 * from Order and its canonical relations so there is no chance of a staff-only field leaking
 * through by inheritance.
 */
final class CustomerOrderReadModel
{
    /** Approved decision #5 — order-linked self-service support window. */
    private const SUPPORT_WINDOW_DAYS = 30;

    /**
     * TASK-ECOS-V1.1-CRM-04-BACKEND-SOURCE-CLOSURE-REMEDIATION-019R1 §2 — ONLY these categories
     * are gated by the 30-day post-delivery window at all. General support and
     * payment/invoice-related categories are never subject to it, in either direction: not
     * blocked by an unproven delivery timestamp, and not opened early by one either.
     */
    public const POST_DELIVERY_CATEGORIES = ['wrong_item', 'damaged_item', 'missing_item', 'return_request'];

    /**
     * @return array<string, mixed>|null null when the token's own order no longer resolves, or
     *                                   (defence in depth) its scope no longer matches the token.
     */
    public function build(CustomerTrackingToken $token): ?array
    {
        if ($token->order_id === null) {
            return null;
        }

        $order = Order::query()
            ->with([
                'lines.product.unit',
                'channel.brand',
                'currentTripOrder.trip.shippingCompany',
                'currentTripOrder.trip.driverVehicleAssignment.driver',
            ])
            ->find($token->order_id);

        if ($order === null) {
            return null;
        }

        // Defence in depth (§6/§13): re-prove every scope fact the token claims, even though
        // the token row is itself the trusted source — an Order that has moved company/Brand/
        // customer since issuance (should never happen, but never assumed) must fail closed.
        if ($order->customer_id !== $token->customer_id || $order->company_id !== $token->company_id) {
            return null;
        }

        $brandId = $order->channel?->brand_id;
        if ($token->brand_id !== null && $brandId !== $token->brand_id) {
            return null;
        }

        $depositPaid = (float) $order->deposit_amount;
        $grandTotal = (float) $order->total;

        return [
            'order_number' => $order->order_number,
            'order_date' => $order->order_date?->toDateString(),
            'brand' => $order->channel?->brand !== null ? [
                'id' => $order->channel->brand->id,
                'name' => $order->channel->brand->name,
                'code' => $order->channel->brand->code,
            ] : null,
            'requested_delivery_date' => $order->requested_delivery_date?->toDateString(),
            'items' => $order->lines->map(fn ($line) => [
                'product_name' => $line->product?->name,
                'sku' => $line->product?->sku,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'line_total' => (float) $line->line_total,
            ])->values()->all(),
            'subtotal' => (float) $order->subtotal,
            'shipping_amount' => $order->shipping_cost !== null ? (float) $order->shipping_cost : (float) $order->shipping_total,
            'discount_amount' => (float) $order->discount_amount,
            'tax_amount' => (float) $order->tax_total,
            'grand_total' => $grandTotal,
            'paid_amount' => $depositPaid,
            'outstanding_amount' => round($grandTotal - $depositPaid, 2),
            'payment_state' => \Modules\Commerce\Orders\Domain\Enums\PaymentState::fromAmounts($depositPaid, $grandTotal)->value,
            'payment_proof_state' => $this->resolveProofState($order),
            'canonical_status' => $order->status->value,
            'canonical_status_label' => $order->status->label(),
            'delivery' => $this->resolveDelivery($order),
            'timeline' => $this->resolveTimeline($order),
            'invoice_available' => true,
            'support' => [
                // General/payment/invoice support is ALWAYS reachable — never gated by
                // delivery timing at all (§2.A/§2.B).
                'general_available' => true,
                // Only the 4 POST_DELIVERY_CATEGORIES consult this; the customer-facing
                // meaning is "may I submit a wrong/damaged/missing-item or return report
                // right now" — see resolvePostDeliveryWindow()'s own docblock.
                'post_delivery_window' => $this->resolvePostDeliveryWindow($order),
            ],
            'payment_method_change_eligible' => $this->isPaymentMethodChangeEligible($order),
        ];
    }

    private function resolveProofState(Order $order): ?string
    {
        $proof = PaymentProof::query()
            ->where('order_id', $order->id)
            ->where('company_id', $order->company_id)
            ->whereNull('superseded_at')
            ->latest('created_at')
            ->first();

        return $proof?->state instanceof BackedEnum ? $proof->state->value : $proof?->state;
    }

    /** @return array<string, mixed> */
    private function resolveDelivery(Order $order): array
    {
        $trip = $order->currentTripOrder?->trip;
        $shippingCompanyName = $trip?->shippingCompany?->name;

        $stop = $trip !== null
            ? DeliveryStop::query()->where('trip_id', $trip->id)->where('order_id', $order->id)->first()
            : null;

        return [
            'shipping_company' => $shippingCompanyName,
            'stop_status' => $stop?->status?->value,
            'stop_status_label' => $stop?->status?->label(),
            // §9 driver privacy — approved policy, applied here and ONLY here.
            'driver' => $this->resolveDriver($order, $trip),
        ];
    }

    /**
     * §0 item 1/2, §9 — first name only, from Out for Delivery onward; never a phone number,
     * ever, in V1 (no masking/relay authority exists to safely substitute the real mobile).
     *
     * @return array{first_name: string}|null
     */
    private function resolveDriver(Order $order, ?\Modules\Logistics\Distribution\Domain\Models\Trip $trip): ?array
    {
        $eligibleStatuses = [
            OrderStatus::OutForDelivery,
            OrderStatus::Delivered,
            OrderStatus::FinalCash,
            OrderStatus::Returned,
        ];

        if (! in_array($order->status, $eligibleStatuses, true) || $trip === null) {
            return null;
        }

        $driver = $trip->driverVehicleAssignment?->driver;

        if ($driver === null || $driver->full_name === null || trim($driver->full_name) === '') {
            return null;
        }

        $firstName = trim(explode(' ', trim($driver->full_name))[0]);

        return $firstName === '' ? null : ['first_name' => $firstName];
    }

    /**
     * §10 — a deliberately small, honest timeline. Every entry's timestamp comes from a real,
     * named Order/Trip/DeliveryStop/PaymentProof column; an event whose timestamp is not
     * present is simply omitted, never fabricated from updated_at or a guess.
     *
     * @return list<array{event: string, occurred_at: string}>
     */
    private function resolveTimeline(Order $order): array
    {
        $events = [];

        $push = function (string $event, ?Carbon $at) use (&$events): void {
            if ($at !== null) {
                $events[] = ['event' => $event, 'occurred_at' => $at->toIso8601String()];
            }
        };

        $push('order_created', $order->created_at);
        $push('confirmed', $order->confirmed_at);
        $push('payment_confirmed', $order->date_paid);

        $latestProof = PaymentProof::query()->where('order_id', $order->id)->latest('created_at')->first();
        $push('payment_proof_submitted', $latestProof?->created_at);

        $push('preparation_completed', $order->preparation_completed_at);

        $trip = $order->currentTripOrder?->trip;
        $push('out_for_delivery', $trip?->dispatched_at);

        $stop = $trip !== null
            ? DeliveryStop::query()->where('trip_id', $trip->id)->where('order_id', $order->id)->first()
            : null;
        if ($stop?->status !== null && $stop->status->acceptsPayment()) {
            $push('delivered', $stop->completed_at);
        }

        // The current status's own entry time is always accurate for THAT status (the column
        // is stamped on every transition) — safe to surface regardless of which status it is,
        // including terminal ones (cancelled/returned), without claiming a full history.
        if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Returned, OrderStatus::OnHold], true)) {
            $push($order->status->value, $order->status_entered_at);
        }

        usort($events, fn ($a, $b) => $a['occurred_at'] <=> $b['occurred_at']);

        return $events;
    }

    /**
     * TASK-...-019R1 §2 — the 30-day post-delivery self-service window, for the 4
     * POST_DELIVERY_CATEGORIES ONLY. Public so CustomerSupportController can independently
     * re-derive the SAME fact before accepting an order-linked issue submission, rather than
     * trusting whatever the last GET /track/order response happened to say.
     *
     * CORRECTED (019R1): the original Task-1 version returned available=true whenever the
     * canonical delivery timestamp could not be proven — too permissive for a rule whose whole
     * point is "prove delivery happened within the last 30 days". An unprovable timestamp (or
     * an order that has not reached a delivered status at all) now yields available=false with
     * a bounded reason — never a guess, and never Order.updated_at treated as delivery time.
     * This method is consulted ONLY for the 4 post-delivery-specific categories; general/
     * payment/invoice support never call it and are therefore never affected by this rule in
     * either direction (§2.A/§2.B, closure gate items J/K).
     *
     * @return array{available: bool, reason: string}
     */
    public function resolvePostDeliveryWindow(Order $order): array
    {
        if (! in_array($order->status, [OrderStatus::Delivered, OrderStatus::FinalCash], true)) {
            return ['available' => false, 'reason' => 'not_yet_delivered'];
        }

        $stop = null;
        $trip = $order->currentTripOrder?->trip;
        if ($trip !== null) {
            $stop = DeliveryStop::query()->where('trip_id', $trip->id)->where('order_id', $order->id)->first();
        }

        $deliveredAt = $stop?->completed_at;

        // §2 — an order marked Delivered/FinalCash but with no provable canonical delivery
        // timestamp (e.g. the DeliveryStop row itself is missing or was never completed
        // through the normal flow) must fail CLOSED for this specific, timing-dependent rule —
        // never fabricated from updated_at, never assumed within-window.
        if ($deliveredAt === null) {
            return ['available' => false, 'reason' => 'delivery_timestamp_unavailable'];
        }

        $withinWindow = $deliveredAt->diffInDays(now()) <= self::SUPPORT_WINDOW_DAYS;

        return [
            'available' => $withinWindow,
            'reason' => $withinWindow ? 'within_post_delivery_window' : 'post_delivery_window_expired',
        ];
    }

    /** §19 — mirrors ChangeOrderPaymentMethodAction's own reusable eligibility facts exactly. */
    private function isPaymentMethodChangeEligible(Order $order): bool
    {
        $eligibleStatus = in_array($order->status, [OrderStatus::AwaitingPayment, OrderStatus::InProgress], true);

        return $eligibleStatus && ! $order->status->isLocked();
    }
}
