<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Loading\Domain\Enums\AllocationRecordStatus;
use Modules\Operations\Loading\Domain\Events\ProductDeliveryRecorded;
use Modules\Operations\Loading\Domain\Models\AllocationRecord;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Operations\Loading\Domain\Services\VehicleInventoryService;
use RuntimeException;

/**
 * Records the ACTUAL quantity delivered for one product on one order line.
 *
 * ┌─ WHY THIS EXISTS ────────────────────────────────────────────────────────┐
 * │ ADR-015 §6.4 requires vehicle shift reconciliation to compute            │
 * │   quantity_variance = loaded - delivered - returned                      │
 * │ and `loaded` and `returned` already had authorities while `delivered`    │
 * │ had none: VehicleInventoryService::recordDelivery() had no callers, and  │
 * │ allocation_records.quantity_delivered was written once as 0.0 at         │
 * │ creation and never advanced. Delivery was confirmed only at order-stop   │
 * │ granularity, which carries no product quantity at all.                   │
 * │                                                                           │
 * │ PRODUCT-ALLOCATION-ENGINE.md §6 (APPROVED, ADR-015) already names the    │
 * │ authority — OrderAllocation is "the definitive record of what a driver   │
 * │ should deliver for each order", carrying                                 │
 * │   quantity_delivered  "confirmed delivered (updated by Logistics OS)"    │
 * │   quantity_remaining  "computed: allocated - delivered"                  │
 * │ so no new entity, table or authority is introduced here. This action is  │
 * │ the missing writer for a field the approved architecture already owns.   │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * Completing an order stop does NOT imply the full allocated quantity was
 * delivered (owner decision D-A, Option 2 — actual product delivery). The
 * caller supplies the counted figure.
 *
 * The quantity is ABSOLUTE, not a delta: it is "how much of this line has
 * actually been delivered", so replaying the same confirmation is a no-op
 * rather than a double count. The difference against the previous value is
 * what propagates to the vehicle inventory aggregate.
 */
final class RecordProductDeliveryAction
{
    /** Quantities are decimal(18,4); compare below that resolution. */
    private const EPSILON = 0.00005;

    public function __construct(
        private readonly VehicleInventoryService $inventoryService,
    ) {}

    public function execute(
        AllocationRecord $record,
        float $quantityDelivered,
        string $actorId,
        string $actorType = 'driver',
    ): AllocationRecord {
        if ($quantityDelivered < 0) {
            throw new RuntimeException(
                "Cannot record a negative delivered quantity ({$quantityDelivered}) for allocation '{$record->id}'.",
            );
        }

        $updated = DB::transaction(function () use ($record, $quantityDelivered, $actorId, $actorType): AllocationRecord {
            // Two operators confirming the same line must not interleave a read of
            // quantity_delivered with each other's write. Same lockForUpdate pattern
            // the module already uses for contended rows (CapacityLedgerService).
            /** @var AllocationRecord $locked */
            $locked = AllocationRecord::query()->lockForUpdate()->findOrFail($record->id);

            $status = $locked->status instanceof AllocationRecordStatus
                ? $locked->status
                : AllocationRecordStatus::from((string) $locked->status);

            if ($status->isTerminal()) {
                throw new RuntimeException(
                    "Allocation '{$locked->id}' is '{$status->value}' and can no longer record a delivery.",
                );
            }

            $allocated = (float) $locked->quantity_allocated;

            // OVER-DELIVERY IS NOT DEFINED BY ADR-015. The contract states only that
            // quantity_variance "must be 0" and quantity_remaining is "allocated -
            // delivered"; it defines no behaviour for delivered > allocated, and the
            // schema bounds only quantity_delivered >= 0. Rather than invent a rule
            // (a write-off, an auto-raised allocation, a tolerated negative
            // remainder), this fails closed and names the gap. Replace this guard
            // once the over-delivery contract is approved.
            if ($quantityDelivered - $allocated > self::EPSILON) {
                throw new RuntimeException(
                    "Delivered quantity ({$quantityDelivered}) exceeds the allocated quantity ({$allocated}) "
                    ."for allocation '{$locked->id}'. Over-delivery has no approved contract "
                    .'(ADR-015 defines quantity_variance = 0 but no over-delivery rule), so it is refused.',
                );
            }

            $previouslyDelivered = (float) $locked->quantity_delivered;
            $delta = $quantityDelivered - $previouslyDelivered;

            $locked->quantity_delivered = $quantityDelivered;
            // PRODUCT-ALLOCATION-ENGINE.md §6: "computed: allocated - delivered".
            $locked->quantity_remaining = $allocated - $quantityDelivered;
            $locked->updated_by = $actorId;

            // Outcome states come from the existing AllocationRecordStatus table; the
            // walk below only ever uses transitions that enum already declares legal.
            // Nothing is delivered at 0, and "refusal" has no approved semantics, so
            // a zero confirmation records the quantity and leaves the status alone.
            if ($quantityDelivered > self::EPSILON) {
                $this->advanceTo(
                    $locked,
                    abs($allocated - $quantityDelivered) <= self::EPSILON
                        ? AllocationRecordStatus::Delivered
                        : AllocationRecordStatus::PartialDelivery,
                );
            }

            $locked->save();

            // Propagate only the change, so a replay (delta 0) moves no quantity.
            if (abs($delta) > self::EPSILON && $locked->vehicle_inventory_item_id !== null) {
                /** @var VehicleInventoryItem|null $item */
                $item = VehicleInventoryItem::query()
                    ->lockForUpdate()
                    ->find($locked->vehicle_inventory_item_id);

                if ($item !== null) {
                    $this->inventoryService->recordDelivery(
                        item: $item,
                        orderId: (string) $locked->order_id,
                        quantity: $delta,
                        actorId: $actorId,
                        actorType: $actorType,
                    );
                }
            }

            return $locked->refresh();
        });

        // TASK-DRIVER-04 (A): the delivered quantity now lives canonically on the
        // allocation row. Announce it (ids only) so the Commerce order line can
        // project delivered_qty := Σ allocation_records.quantity_delivered. The
        // projection re-derives from this source, so it stays idempotent and this
        // action remains the single delivered-quantity writer. Dispatched after the
        // commit (mirrors DeliveryService::completeStop) — the allocation write is
        // authoritative and independent of the projection.
        ProductDeliveryRecorded::dispatch(
            (string) $updated->order_id,
            (string) $updated->order_line_id,
            (string) $updated->company_id,
        );

        return $updated;
    }

    /**
     * Walk the AllocationRecordStatus table to $target using only declared transitions.
     *
     * Records are created as `allocated` and nothing else in the platform advances
     * them, so demanding the caller pre-walk confirmed → in_delivery would leave this
     * action uncallable in production — the same dead end that left quantity_delivered
     * unwritten. Each hop is still asserted against canTransitionTo(), so no new
     * transition is introduced.
     */
    private function advanceTo(AllocationRecord $record, AllocationRecordStatus $target): void
    {
        $current = $record->status instanceof AllocationRecordStatus
            ? $record->status
            : AllocationRecordStatus::from((string) $record->status);

        $guard = 0;

        while ($current !== $target) {
            if (++$guard > 5) {
                throw new RuntimeException(
                    "No legal AllocationRecordStatus path from '{$current->value}' to '{$target->value}'.",
                );
            }

            $next = match ($current) {
                AllocationRecordStatus::Allocated => AllocationRecordStatus::Confirmed,
                AllocationRecordStatus::Confirmed => AllocationRecordStatus::InDelivery,
                AllocationRecordStatus::InDelivery, AllocationRecordStatus::PartialDelivery => $target,
                default => null,
            };

            if ($next === null || ! $current->canTransitionTo($next)) {
                throw new RuntimeException(
                    "No legal AllocationRecordStatus path from '{$current->value}' to '{$target->value}'.",
                );
            }

            $current = $next;
        }

        $record->status = $target->value;
    }
}
