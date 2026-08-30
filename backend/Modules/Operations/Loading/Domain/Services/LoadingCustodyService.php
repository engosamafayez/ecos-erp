<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Loading\Application\Actions\LoadProductAction;
use Modules\Operations\Loading\Domain\Models\LoadingTask;
use Modules\Operations\Loading\Domain\Models\LoadingTaskAdjustment;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use RuntimeException;

/**
 * TASK-LOADING-WAREHOUSE-DRIVER-CUSTODY-IMPLEMENTATION-001 — the warehouse ↔ driver
 * quantity conversation.
 *
 * ┌─ ONE WRITER PER FACT ────────────────────────────────────────────────────┐
 * │ WAREHOUSE writes `quantity_loaded` + `confirmed_*`.                       │
 * │ DRIVER    writes `driver_received_qty` + `driver_confirmed_*`, and may     │
 * │           REQUEST a change — never make one.                              │
 * │                                                                          │
 * │ No driver method in this class touches `quantity_loaded`. That is the      │
 * │ separation, expressed structurally rather than by convention.             │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * QUANTITY_LOADED HAS EXACTLY ONE WRITER, AND IT IS NOT THIS CLASS.
 * Every warehouse quantity change delegates to `LoadProductAction`, so the certified
 * over-load ceiling ("loaded may fall short but must never exceed planned"), the
 * absolute-set idempotency on `(vehicle_assignment_id, product_id)`, and the vehicle
 * inventory delta all keep applying unchanged. This service adds the CONVERSATION
 * around that write; it does not become a second loading engine.
 *
 * STATE IS DERIVED, NEVER STORED. See `stateOf()`. A stored status could contradict
 * the quantities it claims to describe; a derived one cannot.
 *
 * CONCURRENCY. Every mutation re-reads its row under `lockForUpdate` — the pattern
 * this module already uses — and every actor-facing write carries the quantity the
 * actor believed was current. A mismatch is refused rather than silently applied, so
 * a confirmation can never land against a number the actor never saw.
 */
class LoadingCustodyService
{
    /** Quantities are decimal(18,4); this is half of the last representable digit. */
    private const EPSILON = 0.00005;

    public const STATE_PENDING_LOADING = 'pending_loading';

    public const STATE_AWAITING_DRIVER_CONFIRMATION = 'awaiting_driver_confirmation';

    public const STATE_ADJUSTMENT_REQUESTED = 'adjustment_requested';

    public const STATE_AWAITING_DRIVER_RECONFIRMATION = 'awaiting_driver_reconfirmation';

    public const STATE_DRIVER_CONFIRMED = 'driver_confirmed';

    public function __construct(private readonly LoadProductAction $loadProduct) {}

    /**
     * The product's position in the workflow — a pure function of canonical values.
     *
     * Order matters: an OPEN adjustment outranks everything, because while one is
     * pending neither side may treat the current number as settled.
     */
    public function stateOf(LoadingTask $task, ?LoadingTaskAdjustment $openAdjustment = null): string
    {
        if ($openAdjustment !== null && $openAdjustment->isOpen()) {
            return self::STATE_ADJUSTMENT_REQUESTED;
        }

        if ($task->confirmed_at === null) {
            return self::STATE_PENDING_LOADING;
        }

        if ($task->isDriverConfirmationCurrent()) {
            return self::STATE_DRIVER_CONFIRMED;
        }

        // A driver confirmation that exists but predates the warehouse's latest
        // confirmation is STALE — the driver agreed to a number that has since moved.
        return $task->driver_confirmed_at !== null
            ? self::STATE_AWAITING_DRIVER_RECONFIRMATION
            : self::STATE_AWAITING_DRIVER_CONFIRMATION;
    }

    /**
     * The states in which an item the WAREHOUSE loaded still awaits the driver.
     *
     * ┌─ WHAT IS AND IS NOT IN SCOPE ────────────────────────────────────────────┐
     * │ The rule governs items "loaded by the warehouse" — custody handed from one │
     * │ party to another, which the receiving party must acknowledge.             │
     * │                                                                          │
     * │ `pending_loading` is therefore ABSENT. It means no warehouse confirmation  │
     * │ exists, which is the legacy path where the DRIVER records the quantity     │
     * │ themselves. There is no handover to acknowledge — the driver is already    │
     * │ the source — and blocking it would make that flow uncompletable.          │
     * │                                                                          │
     * │ `adjustment_requested` is absent too: the driver HAS acted by reporting a  │
     * │ discrepancy, and the approved adjustment workflow owns it. Blocking there  │
     * │ would trap a shipment behind a dispute the driver themselves raised.      │
     * │                                                                          │
     * │ `awaiting_driver_reconfirmation` IS blocking: the warehouse changed the    │
     * │ quantity after the driver agreed, so the number now standing is one the    │
     * │ driver never accepted.                                                    │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * @var list<string>
     */
    public const UNRESOLVED_STATES = [
        self::STATE_AWAITING_DRIVER_CONFIRMATION,
        self::STATE_AWAITING_DRIVER_RECONFIRMATION,
    ];

    /**
     * Loaded items the driver has not yet resolved — the Loading Complete gate.
     *
     * ┌─ WHY THIS LIVES HERE ────────────────────────────────────────────────────┐
     * │ It answers the question with `stateOf()`, the one custody state machine.  │
     * │ Deciding "resolved" anywhere else would be a SECOND state machine able to │
     * │ disagree with the manifest the driver is looking at — the completion gate  │
     * │ and the screen must always agree.                                         │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * An item with NOTHING loaded is skipped: there is no custody to acknowledge, so it
     * cannot hold completion up. A product with no task row at all never reaches here.
     *
     * @return list<LoadingTask>
     */
    public function unresolvedLoadedTasks(string $vehicleAssignmentId): array
    {
        $unresolved = [];

        foreach (
            LoadingTask::query()->where('vehicle_assignment_id', $vehicleAssignmentId)->get() as $task
        ) {
            if ((float) $task->quantity_loaded <= self::EPSILON) {
                continue;
            }

            $state = $this->stateOf($task, $this->openAdjustmentFor($task));

            if (in_array($state, self::UNRESOLVED_STATES, true)) {
                $unresolved[] = $task;
            }
        }

        return $unresolved;
    }

    // ── WAREHOUSE ────────────────────────────────────────────────────────────

    /**
     * Record and confirm what the warehouse physically loaded.
     *
     * Delegates the quantity to `LoadProductAction` (over-load ceiling, absolute-set,
     * inventory delta) and then stamps the warehouse confirmation. Re-posting the same
     * quantity is a no-op on the quantity and simply re-stamps the confirmation, so a
     * double click cannot double-count.
     *
     * Confirming AFTER the driver already confirmed is legitimate — a warehouse
     * recount — and deliberately makes the driver's confirmation stale (§16). Nothing
     * is reset; the timestamp comparison does it.
     */
    public function confirmLoaded(
        VehicleAssignment $assignment,
        string $productId,
        string $skuSnapshot,
        string $nameSnapshot,
        float $quantityPlanned,
        float $quantityLoaded,
        string $actorId,
    ): LoadingTask {
        if ($quantityLoaded < 0) {
            throw new RuntimeException('Loaded quantity cannot be negative.');
        }

        return DB::transaction(function () use (
            $assignment,
            $productId,
            $skuSnapshot,
            $nameSnapshot,
            $quantityPlanned,
            $quantityLoaded,
            $actorId,
        ): LoadingTask {
            // The over-load refusal lives here, not in this service — unchanged.
            $task = $this->loadProduct->execute(
                assignment: $assignment,
                poolEntryId: null,
                productId: $productId,
                skuSnapshot: $skuSnapshot,
                nameSnapshot: $nameSnapshot,
                preparationWaveId: null,
                quantityPlanned: $quantityPlanned,
                quantityLoaded: $quantityLoaded,
                loadedBy: $actorId,
            );

            $task->forceFill([
                'confirmed_by' => $actorId,
                'confirmed_at' => now(),
            ])->save();

            return $task->refresh();
        });
    }

    /**
     * Warehouse decision on an open request: ACCEPT, EDIT (revise) or REJECT.
     *
     * ACCEPT takes the driver's number. EDIT takes a third number the warehouse
     * supplies. REJECT changes nothing — it exists so a warehouse that recounts and
     * finds its original figure correct can say so, instead of being forced to alter a
     * correct quantity or leave the driver blocked.
     *
     * Resolving is append-plus-close: the open row is closed, and a second row records
     * the decision. Round 1 is never edited away.
     */
    public function resolveAdjustment(
        LoadingTaskAdjustment $adjustment,
        string $action,
        ?float $revisedQuantity,
        string $actorId,
    ): LoadingTask {
        return DB::transaction(function () use ($adjustment, $action, $revisedQuantity, $actorId): LoadingTask {
            /** @var LoadingTaskAdjustment $locked */
            $locked = LoadingTaskAdjustment::query()
                ->whereKey($adjustment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotency: a second Accept/Reject on an already-resolved request is
            // refused rather than appending a contradictory second decision.
            if (! $locked->isOpen()) {
                throw new RuntimeException('This adjustment request has already been resolved.');
            }

            /** @var LoadingTask $task */
            $task = LoadingTask::query()
                ->whereKey($locked->loading_task_id)
                ->lockForUpdate()
                ->firstOrFail();

            $before = (float) $task->quantity_loaded;

            $target = match ($action) {
                'accept' => (float) $locked->driver_reported_qty,
                'reject' => null,
                'edit' => $revisedQuantity,
                default => throw new RuntimeException("Unknown adjustment action '{$action}'."),
            };

            if ($action === 'edit' && $target === null) {
                throw new RuntimeException('A revised quantity is required when editing an adjustment.');
            }

            if ($target !== null) {
                $assignment = VehicleAssignment::query()
                    ->whereKey($task->vehicle_assignment_id)
                    ->firstOrFail();

                // Same single writer, same ceiling: an accepted or edited quantity is
                // still refused if it exceeds what was planned/required.
                $task = $this->loadProduct->execute(
                    assignment: $assignment,
                    poolEntryId: $task->pool_entry_id,
                    productId: (string) $task->product_id,
                    skuSnapshot: (string) $task->sku_snapshot,
                    nameSnapshot: (string) $task->name_snapshot,
                    preparationWaveId: $task->preparation_wave_id,
                    quantityPlanned: (float) $task->quantity_planned,
                    quantityLoaded: $target,
                    loadedBy: $actorId,
                );

                // The revision IS a re-confirmation, which is what makes any earlier
                // driver confirmation stale and forces re-confirmation (§16).
                $task->forceFill([
                    'confirmed_by' => $actorId,
                    'confirmed_at' => now(),
                ])->save();
            }

            $locked->forceFill([
                'status' => match ($action) {
                    'accept' => LoadingTaskAdjustment::STATUS_ACCEPTED,
                    'edit' => LoadingTaskAdjustment::STATUS_REVISED,
                    default => LoadingTaskAdjustment::STATUS_REJECTED,
                },
                'quantity_after' => $target,
                'resolved_by' => $actorId,
                'resolved_at' => now(),
            ])->save();

            // The decision itself, appended. History now holds the request AND the
            // outcome as two rows, so a later round cannot erase either.
            LoadingTaskAdjustment::create([
                'company_id' => $locked->company_id,
                'loading_task_id' => $locked->loading_task_id,
                'action_type' => match ($action) {
                    'accept' => LoadingTaskAdjustment::ACTION_WAREHOUSE_ACCEPTED,
                    'edit' => LoadingTaskAdjustment::ACTION_WAREHOUSE_REVISED,
                    default => LoadingTaskAdjustment::ACTION_WAREHOUSE_REJECTED,
                },
                'actor_type' => LoadingTaskAdjustment::ACTOR_WAREHOUSE,
                'actor_id' => $actorId,
                'quantity_before' => $before,
                'quantity_after' => $target,
                'driver_reported_qty' => $locked->driver_reported_qty,
                'status' => match ($action) {
                    'accept' => LoadingTaskAdjustment::STATUS_ACCEPTED,
                    'edit' => LoadingTaskAdjustment::STATUS_REVISED,
                    default => LoadingTaskAdjustment::STATUS_REJECTED,
                },
                'resolved_by' => $actorId,
                'resolved_at' => now(),
            ]);

            return $task->refresh();
        });
    }

    // ── DRIVER ───────────────────────────────────────────────────────────────

    /**
     * The driver acknowledges what they physically received.
     *
     * Writes ONLY driver columns. `quantity_loaded` is untouched by design — a driver
     * who disagrees files a request instead (`requestAdjustment`).
     *
     * `expectedLoadedQty` is the number the driver's screen showed. If the warehouse
     * has since revised, this refuses rather than confirming a figure the driver never
     * saw. That is the stale-confirmation guard, and it needs no version column.
     */
    public function confirmReceived(
        LoadingTask $task,
        float $receivedQty,
        ?float $expectedLoadedQty,
        string $actorId,
    ): LoadingTask {
        if ($receivedQty < 0) {
            throw new RuntimeException('Received quantity cannot be negative.');
        }

        return DB::transaction(function () use ($task, $receivedQty, $expectedLoadedQty, $actorId): LoadingTask {
            /** @var LoadingTask $locked */
            $locked = LoadingTask::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->confirmed_at === null) {
                throw new RuntimeException('The warehouse has not confirmed this quantity yet.');
            }

            $this->assertNotStale($locked, $expectedLoadedQty);

            // While a request is open, neither side may treat the number as settled.
            if ($this->openAdjustmentFor($locked) !== null) {
                throw new RuntimeException(
                    'An adjustment request is open for this product. It must be resolved before confirming.',
                );
            }

            $locked->forceFill([
                'driver_received_qty' => $receivedQty,
                'driver_confirmed_by' => $actorId,
                'driver_confirmed_at' => now(),
                // The WAREHOUSE quantity being agreed to, captured under the same lock.
                // A later warehouse revision changes `quantity_loaded` and this stops
                // matching — which is precisely what makes the confirmation stale, with
                // no clock comparison and no reset routine.
                'driver_confirmed_loaded_qty' => (float) $locked->quantity_loaded,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * The driver reports a different quantity and asks the warehouse to review.
     *
     * THIS CHANGES NO QUANTITY. It appends a request and records what the driver
     * counted; `quantity_loaded` keeps its value until a warehouse decision.
     *
     * At most one open request per task, enforced under the lock — a second click
     * returns the EXISTING request rather than opening a rival one.
     */
    public function requestAdjustment(
        LoadingTask $task,
        float $reportedQty,
        ?float $expectedLoadedQty,
        ?string $reason,
        string $actorId,
    ): LoadingTaskAdjustment {
        if ($reportedQty < 0) {
            throw new RuntimeException('Reported quantity cannot be negative.');
        }

        return DB::transaction(function () use ($task, $reportedQty, $expectedLoadedQty, $reason, $actorId): LoadingTaskAdjustment {
            /** @var LoadingTask $locked */
            $locked = LoadingTask::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->confirmed_at === null) {
                throw new RuntimeException('The warehouse has not confirmed this quantity yet.');
            }

            $this->assertNotStale($locked, $expectedLoadedQty);

            $existing = $this->openAdjustmentFor($locked, lock: true);

            if ($existing !== null) {
                // Idempotent: the same unresolved discrepancy yields the same record.
                return $existing;
            }

            if (abs($reportedQty - (float) $locked->quantity_loaded) <= self::EPSILON) {
                throw new RuntimeException(
                    'The reported quantity matches the loaded quantity; there is nothing to adjust.',
                );
            }

            // The driver's own count is recorded even before the warehouse rules on it,
            // so the discrepancy is never lost if the request is later rejected.
            $locked->forceFill(['driver_received_qty' => $reportedQty])->save();

            return LoadingTaskAdjustment::create([
                'company_id' => $locked->company_id,
                'loading_task_id' => $locked->id,
                'action_type' => LoadingTaskAdjustment::ACTION_DRIVER_REQUESTED,
                'actor_type' => LoadingTaskAdjustment::ACTOR_DRIVER,
                'actor_id' => $actorId,
                'quantity_before' => (float) $locked->quantity_loaded,
                // NULL: a request changes nothing. Only a warehouse decision sets this.
                'quantity_after' => null,
                'driver_reported_qty' => $reportedQty,
                'reason' => $reason,
                'status' => LoadingTaskAdjustment::STATUS_OPEN,
            ]);
        });
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /** The single open request for a task, if any. */
    public function openAdjustmentFor(LoadingTask $task, bool $lock = false): ?LoadingTaskAdjustment
    {
        $query = LoadingTaskAdjustment::query()
            ->where('loading_task_id', $task->id)
            ->where('status', LoadingTaskAdjustment::STATUS_OPEN)
            ->orderByDesc('recorded_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Refuse an act aimed at a quantity that has since moved.
     *
     * A null expectation is allowed for clients that cannot supply one; the guard then
     * degrades to the lock alone rather than blocking the flow.
     */
    private function assertNotStale(LoadingTask $task, ?float $expectedLoadedQty): void
    {
        if ($expectedLoadedQty === null) {
            return;
        }

        if (abs($expectedLoadedQty - (float) $task->quantity_loaded) > self::EPSILON) {
            throw new StaleQuantityException(
                'The loaded quantity changed while this screen was open. Refresh and try again.',
            );
        }
    }
}
