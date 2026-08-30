<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Events\TripDispatched;
use Modules\Logistics\Distribution\Domain\Events\TripStatusChanged;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\TripCustody;
use Modules\Logistics\Distribution\Domain\Models\TripOrder;
use Modules\Logistics\Drivers\Domain\Models\DriverVehicleAssignment;

/**
 * Owns the trip lifecycle, order assignment and custody.
 *
 * Resource readiness is delegated to the aggregates that own it — this service
 * never re-derives whether a driver may drive or a vehicle may roll.
 */
class TripService
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, ?string $actor = null): Trip
    {
        return DB::transaction(function () use ($attributes) {
            $attributes['trip_number'] ??= $this->nextTripNumber($attributes['company_id'] ?? null);

            return Trip::create($attributes);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Trip $trip, array $attributes): Trip
    {
        // Status only moves through changeStatus() so the transition table always applies.
        unset($attributes['status']);

        return DB::transaction(function () use ($trip, $attributes) {
            $trip->update($attributes);

            return $trip->refresh();
        });
    }

    public function changeStatus(
        Trip $trip,
        TripStatus $target,
        ?string $reason = null,
        ?string $actor = null,
    ): Trip {
        $current = $trip->status;

        if ($current === $target) {
            return $trip;
        }

        if (! $current->canTransitionTo($target)) {
            throw DistributionException::invalidTripTransition($current, $target);
        }

        // Dispatch is gated on readiness, not just on the transition table.
        if ($target === TripStatus::Dispatched) {
            $blockers = $trip->dispatchBlockers();
            if ($blockers !== []) {
                throw DistributionException::dispatchBlocked($blockers);
            }
        }

        // A trip crossing from a planning/loading shell into an OPEN operational custody is the
        // single-active-custody boundary — enforce that the driver has no other open custody.
        $isCustodyStart = ! $current->isCustodyEligible() && $target->isCustodyEligible();

        $updated = DB::transaction(function () use ($trip, $target, $isCustodyStart) {
            if ($isCustodyStart) {
                $this->assertDriverHasNoOtherOpenCustody($trip);
            }

            $payload = ['status' => $target->value];

            if ($target === TripStatus::Dispatched) {
                $payload['dispatched_at'] = now();
            }

            $trip->update($payload);

            return $trip->refresh();
        });

        TripStatusChanged::dispatch($updated, $current, $target, $reason, $actor);

        if ($target === TripStatus::Dispatched) {
            TripDispatched::dispatch($updated, $actor);
        }

        return $updated;
    }

    /**
     * Single-active-custody invariant (TASK-...-SINGLE-ACTIVE-CUSTODY-CLOSURE-001): a driver may
     * hold at most ONE open operational custody. Called only at the custody-start transition (a
     * trip crossing from a planning/loading shell into a custody-eligible status).
     *
     * Concurrency-safe: it takes a pessimistic lock on the driver's pairing rows, so two
     * simultaneous handoffs for the same driver serialize — whichever commits first makes its trip
     * an open custody, which the second then observes and is rejected. A partial-unique DB index
     * cannot represent "one open custody across several live trip statuses" in MySQL, so the guard
     * is a driver lock over the status-derived open-custody set rather than a misleading uniqueness
     * constraint. Runs inside the caller's status-change transaction.
     */
    private function assertDriverHasNoOtherOpenCustody(Trip $trip): void
    {
        $driverId = $trip->driverVehicleAssignment?->driver_id;
        if ($driverId === null) {
            return; // no driver on the pairing ⇒ no per-driver custody to guard
        }

        // Serialize concurrent custody-starts for this driver.
        DriverVehicleAssignment::query()->where('driver_id', $driverId)->lockForUpdate()->get();

        $otherOpen = Trip::query()
            ->where('id', '!=', $trip->id)
            ->whereIn('status', TripStatus::custodyEligibleValues())
            ->whereHas('driverVehicleAssignment', fn ($a) => $a->where('driver_id', $driverId))
            ->exists();

        if ($otherOpen) {
            throw DistributionException::driverAlreadyHasOpenCustody();
        }
    }

    // ── Order assignment ──────────────────────────────────────────────────────

    /**
     * Assign an order to a trip.
     *
     * Enforces: the trip must be editable, must have capacity, and the order
     * must not already sit on another trip (the unique index is the backstop).
     */
    public function assignOrder(
        Trip $trip,
        string $orderId,
        array $snapshot = [],
        ?int $actorId = null,
        string $assignmentType = 'auto',
    ): TripOrder {
        if (! $trip->isEditable()) {
            throw DistributionException::tripNotEditable($trip->status);
        }

        return DB::transaction(function () use ($trip, $orderId, $snapshot, $actorId, $assignmentType) {
            $existing = TripOrder::where('order_id', $orderId)->lockForUpdate()->first();

            if ($existing !== null) {
                $otherTrip = Trip::find($existing->trip_id);
                throw DistributionException::orderAlreadyOnAnotherTrip(
                    $otherTrip?->trip_number ?? (string) $existing->trip_id,
                );
            }

            // ── A GROUP-OWNED TRIP CARRIES ONLY ITS OWN GROUP'S ORDERS ──────────
            //
            // Additive, and deliberately conditional on the Trip HAVING a Group:
            // every Trip that existed before the Group relation — and every ad-hoc
            // or externally-sourced Trip after it — keeps behaving exactly as before.
            //
            // Without this, a Trip could silently mix two Groups' work, and because
            // `assignOrder` never reads the order's warehouse, that would mean two
            // warehouses' orders on one vehicle with nothing recording it. The Group
            // is where warehouse ownership lives, so the Group link is what makes
            // that check possible at all.
            if ($trip->virtual_slot_id !== null) {
                $belongsToThisGroup = DB::table('distribution_window_orders')
                    ->where('order_id', $orderId)
                    ->where('virtual_slot_id', $trip->virtual_slot_id)
                    ->exists();

                if (! $belongsToThisGroup) {
                    throw new DistributionException(
                        'That order does not belong to this trip\'s distribution group. '
                        .'A trip may only carry the orders of the group that produced it.',
                    );
                }
            }

            if ($trip->isAtCapacity()) {
                throw DistributionException::tripAtCapacity($trip->capacity);
            }

            $tripOrder = $trip->tripOrders()->create([
                'order_id' => $orderId,
                'zone_code_snapshot' => $snapshot['zone_code'] ?? null,
                'governorate_snapshot' => $snapshot['governorate'] ?? null,
                'assignment_type' => $assignmentType,
                'assigned_by' => $actorId,
                'assigned_at' => now(),
            ]);

            $this->syncOrdersCount($trip);

            return $tripOrder;
        });
    }

    public function removeOrder(Trip $trip, string $orderId): void
    {
        if (! $trip->isEditable()) {
            throw DistributionException::tripNotEditable($trip->status);
        }

        DB::transaction(function () use ($trip, $orderId) {
            $trip->tripOrders()->where('order_id', $orderId)->delete();
            $this->syncOrdersCount($trip);
        });
    }

    /** Move an order between trips atomically, so it is never on two or none. */
    public function moveOrder(Trip $from, Trip $to, string $orderId, ?int $actorId = null): TripOrder
    {
        if (! $from->isEditable()) {
            throw DistributionException::tripNotEditable($from->status);
        }
        if (! $to->isEditable()) {
            throw DistributionException::tripNotEditable($to->status);
        }

        return DB::transaction(function () use ($from, $to, $orderId, $actorId) {
            $existing = $from->tripOrders()->where('order_id', $orderId)->lockForUpdate()->first();

            if ($existing === null) {
                throw DistributionException::orderAlreadyOnAnotherTrip($to->trip_number);
            }

            if ($to->isAtCapacity()) {
                throw DistributionException::tripAtCapacity($to->capacity);
            }

            $snapshot = [
                'zone_code' => $existing->zone_code_snapshot,
                'governorate' => $existing->governorate_snapshot,
            ];

            $existing->delete();
            $this->syncOrdersCount($from);

            $moved = $to->tripOrders()->create([
                'order_id' => $orderId,
                'zone_code_snapshot' => $snapshot['zone_code'],
                'governorate_snapshot' => $snapshot['governorate'],
                'assignment_type' => 'manual',
                'assigned_by' => $actorId,
                'assigned_at' => now(),
            ]);

            $this->syncOrdersCount($to);

            return $moved;
        });
    }

    // ── Custody ───────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $attributes */
    public function addCustody(Trip $trip, array $attributes, ?int $actorId = null): TripCustody
    {
        if (! $trip->isEditable()) {
            throw DistributionException::tripNotEditable($trip->status);
        }

        return $trip->custodyItems()->create($attributes + ['created_by' => $actorId]);
    }

    public function confirmCustody(TripCustody $item, int $receivedQuantity, ?int $actorId = null): TripCustody
    {
        $item->update([
            'received_quantity' => $receivedQuantity,
            'is_driver_confirmed' => true,
            'driver_confirmed_at' => now(),
            'driver_confirmed_by' => $actorId,
        ]);

        return $item->refresh();
    }

    // ── Driver acceptance ─────────────────────────────────────────────────────

    /**
     * Record the driver's three confirmations. Any shortfall flags a discrepancy
     * so the dispatch gate can surface it rather than silently proceeding.
     */
    public function recordDriverAcceptance(
        Trip $trip,
        bool $products,
        bool $custody,
        bool $equipment,
        ?string $discrepancyNotes = null,
        ?int $actorId = null,
    ): Trip {
        $hasDiscrepancy = ! ($products && $custody && $equipment)
            || $trip->custodyItems->contains(fn (TripCustody $c) => $c->hasShortfall());

        $trip->update([
            'driver_accepted_products' => $products,
            'driver_accepted_custody' => $custody,
            'driver_accepted_equipment' => $equipment,
            'driver_acceptance_at' => now(),
            'driver_acceptance_by' => $actorId,
            'has_discrepancy' => $hasDiscrepancy,
            'discrepancy_notes' => $discrepancyNotes,
        ]);

        return $trip->refresh();
    }

    /** Link an approved driver/vehicle pairing to the trip. */
    public function assignDriverVehicle(Trip $trip, int $assignmentId): Trip
    {
        $assignment = DriverVehicleAssignment::findOrFail($assignmentId);

        if (! $assignment->isActive()) {
            throw DistributionException::assignmentNotActive();
        }

        $trip->update(['driver_vehicle_assignment_id' => $assignment->id]);

        return $trip->refresh();
    }

    /**
     * THE canonical driver/vehicle availability predicate.
     *
     * Given a set of pairing ids (`driver_vehicle_assignment_id`), returns the
     * subset that is ENGAGED — attached to a non-terminal Distribution trip that
     * belongs to a Group OTHER than $currentGroupId.
     *
     * Both callers share this one implementation so the selector and the write
     * guard can never disagree about what "engaged" means:
     *   - the fleet-options READ hides an engaged pairing from the drawer;
     *   - the assign WRITE refuses one, fail-closed, even if the drawer is bypassed.
     *
     * Definitions, each deliberate:
     *   - "non-terminal" is TripStatus::nonTerminalValues(), derived from
     *     TripStatus::isTerminal() — never a second status list;
     *   - "another Group" is a trip whose virtual_slot_id is set and differs from
     *     the current group. A trip on THIS group is the idempotent re-entry case
     *     (re-opening or re-saving the same group's own assignment) and MUST stay
     *     available; a group-less ad-hoc trip is outside this rule by definition.
     *
     * $lock makes it a locking read. The write path passes true so that, while it
     * holds the pairing row lock, this observes a sibling transaction's already
     * committed trip even though its own snapshot predates that commit — without
     * it two concurrent assigns to different groups could both read "not engaged".
     *
     * @param  list<int>  $assignmentIds
     * @return list<int> the engaged subset, deduplicated
     */
    public function assignmentsEngagedElsewhere(
        array $assignmentIds,
        ?string $currentGroupId,
        bool $lock = false,
    ): array {
        $assignmentIds = array_values(array_unique(array_filter(
            array_map('intval', $assignmentIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($assignmentIds === []) {
            return [];
        }

        $query = Trip::query()
            ->whereIn('driver_vehicle_assignment_id', $assignmentIds)
            ->whereNotNull('virtual_slot_id')
            ->whereIn('status', TripStatus::nonTerminalValues());

        if ($currentGroupId !== null) {
            $query->where('virtual_slot_id', '!=', $currentGroupId);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->pluck('driver_vehicle_assignment_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function syncOrdersCount(Trip $trip): void
    {
        $trip->update(['orders_count' => $trip->tripOrders()->count()]);
    }

    /** Sequential per company: TRP-001, TRP-002, … */
    public function nextTripNumber(?string $companyId): string
    {
        $max = Trip::query()
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->where('trip_number', 'like', 'TRP-%')
            ->pluck('trip_number')
            ->map(static fn (string $n) => (int) substr($n, 4))
            ->max() ?? 0;

        return sprintf('TRP-%03d', $max + 1);
    }
}
