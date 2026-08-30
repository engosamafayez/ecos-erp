<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Models\GroupProductPreparation;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;

/**
 * Records how much of a Product a warehouse has separated for one Distribution Group.
 *
 * ┌─ THE WHOLE CONTRACT, IN FOUR LINES ──────────────────────────────────────┐
 * │ Required  — LIVE, canonical, never stored (productAggregation)            │
 * │ Prepared  — declared by the operator, stored, ABSOLUTE SET                │
 * │ Remaining — max(0, Required − Prepared), derived at read time             │
 * │ Ceiling   — Prepared <= Required, evaluated INSIDE the lock               │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * WHY THE CEILING MUST BE COMPUTED INSIDE THE LOCK. Required is not a stored
 * column — it is a live aggregate over the Group's current membership, and that
 * membership changes while operators work (a Zone is attached, an order is
 * cancelled, a wave postpones one). Reading Required before the transaction and
 * validating against it afterwards would let a Group shrink in between and leave
 * Prepared above a Required that no longer exists. So the sequence is: lock, THEN
 * recompute, THEN validate, THEN write.
 *
 * WHY THE GROUP IS THE LOCK TARGET, not the preparation row. The row may not exist
 * yet, so locking it cannot serialise the FIRST write for a product — two racing
 * creates would both find nothing and one would die on the unique index. Locking
 * the Group serialises every Prepared write for that Group, which removes the
 * create race and the ceiling race with one lock. It is also the pattern this
 * codebase already uses for exactly this shape of problem —
 * CapacityLedgerService::reserve(): "Lock the slot: two concurrent reservations
 * against the last order must not both succeed."
 *
 * The cost is stated rather than hidden: two operators preparing DIFFERENT products
 * in the SAME Group serialise behind each other. The transaction is one aggregate
 * read plus one row write, so the window is short, and a Group is worked by one
 * warehouse team. Per-product locking would trade that for a create-race retry loop
 * — more moving parts for a contention profile this workflow does not have.
 *
 * WHY ABSOLUTE SET, NOT INCREMENT. `prepared_qty = N`, never `+= N`. A retried
 * request writes the same value and changes nothing, which is what makes the
 * operation idempotent WITHOUT an idempotency key — the same reasoning
 * RecordProductDeliveryAction states for itself: "replaying the same confirmation
 * is a no-op rather than a double count." No idempotency-key infrastructure exists
 * in this platform and none is introduced here.
 *
 * WHAT THIS NEVER TOUCHES: inventory (no deduction, no reservation, no ledger, no
 * FIFO), `orders` (no status write — that belongs to FulfillmentEngine), and every
 * Preparation table — `wave_product_demand`, `preparation_wave_items` and
 * `prepared_products_pool` are neither read as a Group figure nor written at all.
 * Preparation Prepared and Group Prepared are different facts at different grains.
 */
final class GroupPreparationService
{
    /**
     * Quantities are decimal(12,4); compare below that resolution.
     *
     * Same constant and the same role as RecordProductDeliveryAction::EPSILON and
     * LoadProductAction::EPSILON — a float ceiling check must not refuse a value
     * that is equal to the ceiling but arrived through binary floating point.
     */
    private const EPSILON = 0.00005;

    /** Storage scale — matches order_lines.quantity, the column Required is summed from. */
    private const SCALE = 4;

    public function __construct(
        private readonly DistributionAggregationService $aggregation,
    ) {}

    /**
     * Set — not increment — the prepared quantity for one Product in one Group.
     *
     * @param  VirtualCapacitySlot  $group  ALREADY tenant-resolved by the caller. This
     *                                      service never resolves tenancy itself.
     * @param  int|null  $actorId  the acting user (bigint PK), for the durable stamp.
     *
     * @throws DistributionException on a negative value, an unknown/not-required
     *                               product, or a value above live Required. The
     *                               controller renders all three as HTTP 422.
     */
    public function record(
        VirtualCapacitySlot $group,
        string $productId,
        float $preparedQty,
        ?int $actorId = null,
    ): GroupProductPreparation {
        // Cheap guard first, outside the transaction: a negative quantity is not a
        // race, it is a malformed request, and it must not hold a row lock to be
        // refused. Request validation already rejects it; this is the authoritative
        // repeat, because the frontend is UX and the backend is the contract.
        if ($preparedQty < 0) {
            throw new DistributionException('Prepared quantity cannot be negative.');
        }

        $prepared = round($preparedQty, self::SCALE);

        return DB::transaction(function () use ($group, $productId, $prepared, $actorId): GroupProductPreparation {
            // 1. LOCK THE GROUP. Everything below is serialised per Group.
            /** @var VirtualCapacitySlot $locked */
            $locked = VirtualCapacitySlot::query()->lockForUpdate()->findOrFail($group->id);

            // 2. LIVE REQUIRED, recomputed inside the lock from the CANONICAL
            //    aggregation. Deliberately the same method the read model and LP-1
            //    already use, rather than a targeted single-product query: a second
            //    query would be a second definition of Required, and the two could
            //    drift. A Group holds a handful of products, so one aggregate read
            //    per write is the right trade for that guarantee.
            $required = $this->requiredFor($locked, $productId);

            $existing = GroupProductPreparation::query()
                ->where('virtual_slot_id', $locked->id)
                ->where('product_id', $productId)
                ->first();

            // 3a. A product this Group does not require, and has no record for, has
            //     nothing to record against. Refused rather than silently creating a
            //     zero row for an arbitrary uuid.
            if ($required <= 0.0 && $existing === null) {
                throw new DistributionException(
                    'This product is not required by this distribution group.',
                );
            }

            // 3b. THE CEILING. Fails closed. An existing row whose Required has since
            //     fallen to zero may still be set DOWN (to 0) — that is how an
            //     operator records that separated stock was returned — but never up.
            if ($prepared - $required > self::EPSILON) {
                throw new DistributionException(sprintf(
                    'Prepared quantity (%s) cannot exceed the quantity this group requires (%s).',
                    rtrim(rtrim(number_format($prepared, self::SCALE, '.', ''), '0'), '.') ?: '0',
                    rtrim(rtrim(number_format($required, self::SCALE, '.', ''), '0'), '.') ?: '0',
                ));
            }

            // 4. ABSOLUTE SET. Replaying this request writes the same number.
            $row = $existing ?? new GroupProductPreparation([
                'company_id' => $locked->company_id,
                'distribution_window_id' => $locked->distribution_window_id,
                'virtual_slot_id' => $locked->id,
                'product_id' => $productId,
            ]);

            $row->prepared_qty = $prepared;
            $row->last_recorded_by = $actorId;
            $row->last_recorded_at = now();
            $row->save();

            return $row;
        });
    }

    /**
     * Prepared quantities for one Group, keyed by product id.
     *
     * The read side of the same fact. Returns only what has been recorded; a product
     * with no row is not "0 prepared" in storage, it is "never touched" — the caller
     * renders the absence as 0 without a row having to exist.
     *
     * @return array<string, float>
     */
    public function preparedByProduct(string $groupId): array
    {
        return GroupProductPreparation::query()
            ->where('virtual_slot_id', $groupId)
            ->pluck('prepared_qty', 'product_id')
            ->map(static fn ($q): float => (float) $q)
            ->all();
    }

    /**
     * LIVE Required for one (Group, Product), from the canonical aggregation.
     *
     * Warehouse-scoped by the GROUP's own `warehouse_id`, never by a request
     * parameter: the Group owns exactly one warehouse (NOT NULL since Part 5B), so
     * taking it from the locked row makes the Part 5B boundary structural here
     * rather than dependent on what the caller sent.
     */
    private function requiredFor(VirtualCapacitySlot $group, string $productId): float
    {
        foreach ($this->aggregation->productAggregation(
            $group->distribution_window_id,
            null,
            $group->id,
            $group->warehouse_id,
        ) as $row) {
            if ((string) $row['product_id'] === $productId) {
                return round((float) $row['total_quantity'], self::SCALE);
            }
        }

        return 0.0;
    }
}
