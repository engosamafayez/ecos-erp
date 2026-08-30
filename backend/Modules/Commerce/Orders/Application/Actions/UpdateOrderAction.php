<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Commerce\Orders\Application\DTO\OrderDTO;
use Modules\Commerce\Orders\Application\Services\GoogleMapsUrlResolver;
use Modules\Commerce\Orders\Domain\Contracts\OrderRepositoryInterface;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Events\OrderGeographyChanged;
use Modules\Commerce\Orders\Domain\Exceptions\OrderAlreadyReleasedException;
use Modules\Commerce\Orders\Domain\Exceptions\OrderNotFoundException;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Sales\Customers\Domain\Models\Customer;
use Modules\Sales\Customers\Domain\Models\CustomerAddress;
use Throwable;

final class UpdateOrderAction extends BaseAction
{
    /**
     * Non-structural fields — editable for any non-terminal order regardless of workflow stage.
     * These never affect financial totals or inventory commitments.
     */
    private const SOFT_FIELDS = [
        'customer_name', 'customer_secondary_phone', 'customer_notes',
        'billing_phone',
        'governorate', 'city', 'area', 'shipping_address',
        'building', 'floor', 'apartment', 'landmark', 'address_notes',
        'delivery_zone_id', 'delivery_zone',
        'google_maps_lat', 'google_maps_lng', 'google_maps_url', 'location_source',
        'payment_method_manual',
        'requested_delivery_date', 'delivery_window_id', 'delivery_window',
        // `deposit_amount` REMOVED — TASK-ORDERS-PREPARATION-PAYMENT-FINAL-FIX-001 (D6).
        //
        // Mass-assigning it here wrote money straight to the column, bypassing
        // RecordOrderPaymentAction's overpayment guard, its idempotency check and the
        // `payment_recorded` OrderEvent — and, because ConfirmOrderWorkflow treats
        // `deposit_amount >= total` as paid, it was the only reachable way to clear the
        // payment gate, silently and with no audit trail.
        //
        // Recording a payment is a domain action, not a field edit:
        // POST /api/orders/{order}/record-payment.
        'notes',
    ];

    /**
     * Structural fields — only editable when order is Pending or Awaiting Payment.
     * Changing these alters financial commitments and may affect inventory.
     */
    private const STRUCTURAL_FIELDS = [
        'shipping_cost', 'shipping_cost_source',
        'discount_amount', 'discount_type',
    ];

    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly ReleaseOrderInventoryAction $releaseInventory,
        private readonly ReserveOrderInventoryAction $reserveInventory,
        private readonly UpdateReservationStatusAction $updateReservationStatus,
        private readonly GoogleMapsUrlResolver $mapsResolver,
        // Payment-method changes re-open the payment gate — see the trigger below.
        private readonly ReevaluateOrderFulfillmentAction $reevaluateFulfillment,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $id = (string) ($arguments[0] ?? '');

        /** @var OrderDTO $dto */
        $dto = $arguments[1];

        /** @var array<string, mixed> $extraData  Enterprise fields from UpdateOrderRequest */
        $extraData = (array) ($arguments[2] ?? []);

        // Resolve a short Google Maps link to coordinates server-side (Finding 05)
        // before the location fields propagate to the order and the customer's
        // default address. No-op when coordinates are already present.
        $extraData = $this->mapsResolver->backfillCoordinates($extraData);

        $order = $this->orders->findById($id);

        if ($order === null) {
            throw new OrderNotFoundException($id);
        }

        // Terminal orders (Completed, Cancelled) — fully read-only.
        if ($order->status->isTerminal()) {
            abort(422, "Order [{$id}] has status [{$order->status->value}] and cannot be modified. Terminal orders are read-only.");
        }

        $isLocked = $order->status->isLocked();

        // Captured BEFORE any write — `payment_method_manual` is a SOFT field, so it is
        // editable even on a structurally locked order, and the payment gate must be
        // re-asked when it moves. See the re-evaluation trigger at the end of this method.
        $previousPaymentMethod = (string) ($order->payment_method_manual ?? '');

        // Also captured before any write. The proof requirement is resolved per order from
        // channels.brand_id -> config_brand_policies (PaymentFulfillmentGate::orderPolicyFor),
        // so moving an order to a different channel can change the requirement for an unchanged
        // payment method — in either direction. Watching only the method would leave that half
        // of the same control unevaluated.
        $previousChannelId = (string) ($order->channel_id ?? '');

        // Start with core attributes (channel, customer, order_date, notes).
        // Status is intentionally excluded — status changes only via workflow actions.
        $attributes = array_diff_key($dto->orderAttributes(), ['status' => true]);

        // Always apply soft fields when present.
        foreach (self::SOFT_FIELDS as $field) {
            if (array_key_exists($field, $extraData)) {
                $attributes[$field] = $extraData[$field];
            }
        }

        // customer_phone maps to billing_phone on the order.
        if (array_key_exists('customer_phone', $extraData)) {
            $attributes['billing_phone'] = $extraData['customer_phone'];
        }

        if ($isLocked) {
            // Structurally locked — only soft fields may change.
            // Recompute remaining_balance if deposit changed (grand_total is unchanged).
            if (array_key_exists('deposit_amount', $attributes)) {
                $newDeposit = (float) ($attributes['deposit_amount'] ?? 0);

                // Recompute grand_total from stored order values to get the correct base.
                $subtotal = (float) $order->subtotal;
                $rawDiscount = (float) ($order->discount_amount ?? 0);
                $discountType = (string) ($order->discount_type ?? '');
                $monetary = $discountType === 'percentage'
                    ? round($subtotal * $rawDiscount / 100, 2)
                    : $rawDiscount;
                $shipping = $order->shipping_cost !== null
                    ? (float) $order->shipping_cost
                    : (float) ($order->shipping_total ?? 0);
                $tax = (float) ($order->tax_total ?? 0);
                $grandTotal = max(0.0, round($subtotal + $shipping - $monetary + $tax, 2));

                $attributes['remaining_balance'] = max(0.0, round($grandTotal - $newDeposit, 2));
            }

            // Soft update only — lines are not modified.
            $order->update($attributes);
            $updated = $this->orders->findById((string) $order->id) ?? $order->fresh();
        } else {
            // Structural update — apply structural fields and recompute all totals.
            foreach (self::STRUCTURAL_FIELDS as $field) {
                if (array_key_exists($field, $extraData)) {
                    $attributes[$field] = $extraData[$field];
                }
            }

            $subtotal = array_sum(array_column($dto->lineAttributes(), 'line_total'));
            $rawDiscount = (float) ($attributes['discount_amount'] ?? 0);
            $discountType = (string) ($attributes['discount_type'] ?? '');
            $monetaryDiscount = $discountType === 'percentage'
                ? round($subtotal * $rawDiscount / 100, 2)
                : $rawDiscount;
            $shippingCost = (float) ($attributes['shipping_cost'] ?? 0);
            $depositAmount = (float) ($attributes['deposit_amount'] ?? 0);
            $grandTotal = max(0.0, round($subtotal - $monetaryDiscount + $shippingCost, 2));

            $attributes['subtotal'] = $subtotal;
            $attributes['total'] = $grandTotal;
            $attributes['remaining_balance'] = max(0.0, round($grandTotal - $depositAmount, 2));

            // BUG-001 fix: detect whether the order has an active reservation before
            // deleting lines. If so, release it first so per-line reserved_qty is returned
            // to the inventory item's available pool. Then update lines. Then re-reserve
            // against the new lines. Without this, deleting lines orphans the reservation:
            // stock remains locked in inventory_items.reserved_qty but no order line tracks it.
            $activeReservationStates = [ReservationStatus::Reserved, ReservationStatus::PartialReserved];
            $hasActiveReservation = in_array($order->reservation_status, $activeReservationStates, true)
                && $order->inventory_reserved_at !== null
                && $order->assigned_warehouse_id !== null;

            $updated = DB::transaction(function () use ($order, $attributes, $dto, $hasActiveReservation): \Modules\Commerce\Orders\Domain\Models\Order {
                if ($hasActiveReservation) {
                    try {
                        // Release existing reservation — decrements inventory_items.reserved_qty
                        // per line using the OLD line quantities.
                        $this->releaseInventory->execute($order);
                    } catch (OrderAlreadyReleasedException) {
                        // Already released — nothing to unlock; proceed normally.
                    }
                    $order->refresh();
                }

                // Delete old lines + create new lines (structural update).
                $updated = $this->orders->update($order, $attributes, $dto->lineAttributes());

                if ($hasActiveReservation) {
                    // Clear the lifecycle timestamps that the release action stamped so that
                    // ReserveOrderInventoryAction will execute (its idempotency guard skips
                    // 'released' and 'reserved' states).
                    // H-4 fix: also clear partial_reservation_approved_at — after a structural
                    // edit the order lines change, so the previous shortage approval is stale.
                    $updated->update([
                        'inventory_released_at' => null,
                        'inventory_reserved_at' => null,
                        'reservation_status' => null,
                        'reservation_failure_reason' => null,
                        'partial_reservation_approved_at' => null,
                    ]);
                    $updated->refresh();

                    // Re-reserve for the NEW line quantities.
                    try {
                        $this->reserveInventory->execute($updated);
                        $updated->refresh();
                    } catch (Throwable $e) {
                        Log::channel('daily')->warning('[UpdateOrder] Re-reserve after structural edit failed', [
                            'order_id' => $updated->id,
                            'error' => $e->getMessage(),
                        ]);
                        // Record a POSTPONED execution, not a stock shortage. Reaching
                        // here means reservation threw — today only for a missing
                        // warehouse — and `awaiting_stock` asserts something specific
                        // and false about that: that inventory is short. It is also the
                        // wrong recovery path, because the retry that resumes a
                        // postponed reservation keys on `pending`, so an order parked
                        // here as awaiting_stock was picked up by nothing.
                        $this->updateReservationStatus->execute(
                            $updated,
                            ReservationStatus::Pending,
                            'Re-reservation postponed after structural order edit: '.$e->getMessage(),
                        );
                        $updated->refresh();
                    }
                }

                return $updated;
            });
        }

        $actorId = Auth::id() !== null ? (string) Auth::id() : null;
        $actorName = Auth::user()?->name;
        $actorRole = Auth::user()?->roles()->value('name');

        $auditableFields = [
            'discount_amount', 'discount_type', 'deposit_amount', 'shipping_cost',
            'billing_phone', 'governorate', 'city', 'shipping_address',
            'building', 'floor', 'apartment', 'landmark', 'area',
        ];

        $changedPrev = [];
        $changedNext = [];
        foreach ($auditableFields as $f) {
            if (array_key_exists($f, $attributes)) {
                $oldVal = (string) ($order->getAttribute($f) ?? '');
                $newVal = (string) ($attributes[$f] ?? '');
                if ($oldVal !== $newVal) {
                    $changedPrev[$f] = $order->getAttribute($f);
                    $changedNext[$f] = $attributes[$f];
                }
            }
        }

        // Keep the customer's delivery profile in sync with the order's latest address data.
        if ($order->customer_id !== null) {
            $this->syncCustomerDefaultAddress((string) $order->customer_id, $extraData);
        }

        OrderEvent::log(
            $updated->id,
            'order_updated',
            "Order #{$updated->order_number} updated".($isLocked ? ' (soft fields only — order is locked).' : '.'),
            [],
            $actorId,
            $actorName,
            $changedPrev ?: null,
            $changedNext ?: null,
            'orders',
            'user',
            'dashboard',
            'updated',
            array_keys($changedPrev) ?: array_keys($attributes),
            null,
            null,
            null,
            null,
            $actorRole,
        );

        // ── A change to either INPUT of the payment gate re-opens it (D1-A) ───────────
        // The gate's answer is a function of two order fields: the payment method, and the
        // channel that selects which brand policy resolves that method. An edit that moves
        // either one has changed the question, so the answer is re-asked — in both directions,
        // since the same edit can make a blocked order eligible or an eligible order blocked.
        //
        // Same canonical entry point as record-payment, proof-verification, proof supersession
        // and the inline quick-update path. Runs AFTER the update has persisted and after the
        // audit event, so a transition it causes is attributed to its own workflow rather than
        // to this edit. A blocked or inapplicable gate is a no-op — the field edit stays
        // committed either way.
        $paymentMethodChanged = (string) ($updated->payment_method_manual ?? '') !== $previousPaymentMethod;
        $channelChanged = (string) ($updated->channel_id ?? '') !== $previousChannelId;

        if ($paymentMethodChanged || $channelChanged) {
            $this->reevaluateFulfillment->execute($updated);
            $updated->refresh();
        }

        // ── A CITY / GOVERNORATE change re-resolves everything derived from it ────────
        // `logistics_city_id` is derived from these two fields and nothing else, and the
        // Distribution zone is derived from that id and nothing else. So an edit here has
        // invalidated both, and both are recomputed — by the modules that own them.
        //
        // Reuses the audit diff already computed above, so the trigger is exactly the set
        // of fields this edit genuinely changed; an update that merely re-sent the same
        // city raises nothing. Announced only — this action references no Geography and no
        // Distribution service. Raised after the update has persisted and after the audit
        // event, for the same reason the payment reaction is.
        if (array_key_exists('city', $changedPrev) || array_key_exists('governorate', $changedPrev)) {
            event(new OrderGeographyChanged(
                orderId: (string) $updated->id,
                companyId: (string) $updated->company_id,
                city: $updated->city,
                governorate: $updated->governorate,
                previousCity: isset($changedPrev['city']) ? (string) $changedPrev['city'] : null,
                previousGovernorate: isset($changedPrev['governorate'])
                    ? (string) $changedPrev['governorate']
                    : null,
                actorId: $actorId === null ? null : (int) $actorId,
            ));
        }

        return OperationResult::success($updated, 'Order updated successfully.');
    }

    /**
     * Upserts the customer's default delivery address using non-null address fields
     * from the order update payload. Null values are skipped so that fields not
     * present in this particular update don't overwrite existing stored data.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncCustomerDefaultAddress(string $customerId, array $data): void
    {
        $governorate = $data['governorate'] ?? null;
        $city = $data['city'] ?? null;

        if ($governorate === null && $city === null) {
            return;
        }

        $fields = [
            'governorate' => $governorate,
            'city' => $city,
            'area' => $data['area'] ?? null,
            'address_line' => $data['shipping_address'] ?? null,
            'building' => $data['building'] ?? null,
            'floor' => $data['floor'] ?? null,
            'apartment' => $data['apartment'] ?? null,
            'landmark' => $data['landmark'] ?? null,
            'address_notes' => $data['address_notes'] ?? null,
            'google_maps_lat' => $data['google_maps_lat'] ?? null,
            'google_maps_lng' => $data['google_maps_lng'] ?? null,
            'google_maps_url' => $data['google_maps_url'] ?? null,
            'location_source' => $data['location_source'] ?? null,
        ];

        $updates = array_filter($fields, static fn ($v) => $v !== null);

        if (empty($updates)) {
            return;
        }

        $existing = CustomerAddress::where('customer_id', $customerId)
            ->where('is_default', true)
            ->first();

        if ($existing !== null) {
            $existing->update($updates);
        } else {
            CustomerAddress::create(array_merge($updates, [
                'customer_id' => $customerId,
                'label' => 'Default',
                'is_default' => true,
            ]));
        }

        if (! empty($data['customer_notes'])) {
            Customer::where('id', $customerId)->update(['notes' => $data['customer_notes']]);
        }
    }
}
