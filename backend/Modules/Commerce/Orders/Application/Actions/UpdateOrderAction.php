<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\Auth;
use Modules\Commerce\Orders\Application\DTO\OrderDTO;
use Modules\Commerce\Orders\Domain\Contracts\OrderRepositoryInterface;
use Modules\Commerce\Orders\Domain\Exceptions\OrderNotFoundException;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;

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
        'deposit_amount',
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

    public function __construct(private readonly OrderRepositoryInterface $orders) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $id = (string) ($arguments[0] ?? '');

        /** @var OrderDTO $dto */
        $dto = $arguments[1];

        /** @var array<string, mixed> $extraData  Enterprise fields from UpdateOrderRequest */
        $extraData = (array) ($arguments[2] ?? []);

        $order = $this->orders->findById($id);

        if ($order === null) {
            throw new OrderNotFoundException($id);
        }

        // Terminal orders (Completed, Cancelled) — fully read-only.
        if ($order->status->isTerminal()) {
            abort(422, "Order [{$id}] has status [{$order->status->value}] and cannot be modified. Terminal orders are read-only.");
        }

        $isLocked = $order->status->isLocked();

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
                $subtotal     = (float) $order->subtotal;
                $rawDiscount  = (float) ($order->discount_amount ?? 0);
                $discountType = (string) ($order->discount_type ?? '');
                $monetary     = $discountType === 'percentage'
                    ? round($subtotal * $rawDiscount / 100, 2)
                    : $rawDiscount;
                $shipping     = $order->shipping_cost !== null
                    ? (float) $order->shipping_cost
                    : (float) ($order->shipping_total ?? 0);
                $tax          = (float) ($order->tax_total ?? 0);
                $grandTotal   = max(0.0, round($subtotal + $shipping - $monetary + $tax, 2));

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

            $subtotal         = array_sum(array_column($dto->lineAttributes(), 'line_total'));
            $rawDiscount      = (float) ($attributes['discount_amount'] ?? 0);
            $discountType     = (string) ($attributes['discount_type'] ?? '');
            $monetaryDiscount = $discountType === 'percentage'
                ? round($subtotal * $rawDiscount / 100, 2)
                : $rawDiscount;
            $shippingCost     = (float) ($attributes['shipping_cost'] ?? 0);
            $depositAmount    = (float) ($attributes['deposit_amount'] ?? 0);
            $grandTotal       = max(0.0, round($subtotal - $monetaryDiscount + $shippingCost, 2));

            $attributes['subtotal']          = $subtotal;
            $attributes['total']             = $grandTotal;
            $attributes['remaining_balance'] = max(0.0, round($grandTotal - $depositAmount, 2));

            $updated = $this->orders->update($order, $attributes, $dto->lineAttributes());
        }

        $actorId   = Auth::id() !== null ? (string) Auth::id() : null;
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

        OrderEvent::log(
            $updated->id,
            'order_updated',
            "Order #{$updated->order_number} updated" . ($isLocked ? ' (soft fields only — order is locked).' : '.'),
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

        return OperationResult::success($updated, 'Order updated successfully.');
    }
}
