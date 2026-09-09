<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Domain\Services;

use Illuminate\Support\Collection;
use Modules\Operations\Loading\Domain\Enums\LoadingSessionStatus;
use Modules\Operations\Loading\Domain\Enums\LoadingWorkspaceBucket;
use Modules\Operations\Loading\Domain\Enums\LoadingWorkspaceReasonCode;
use Modules\Operations\Loading\Domain\Enums\VehicleAssignmentStatus;
use Modules\Operations\Loading\Domain\Models\LoadingSession;
use Modules\Operations\Loading\Domain\Models\LoadingTask;
use Modules\Operations\Loading\Domain\Models\LoadingTaskAdjustment;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;

/**
 * TASK-ECOS-OPERATIONS-LOADING-LIFECYCLE-CUSTODY-WORKSPACE-READ-MODEL-004 — the one
 * server-authoritative classifier behind the Loading Workspace's presentation buckets.
 *
 * ┌─ WHAT THIS IS NOT ─────────────────────────────────────────────────────────┐
 * │ Not a second status engine, not a cache, not a stored fact. Every call      │
 * │ re-derives its answer from `LoadingSessionStatus`/`VehicleAssignmentStatus`  │
 * │ (already on the row), `LoadingCustodyService::stateOf()` (already the one    │
 * │ custody state machine), and `VehicleInventoryItem` existence (already the   │
 * │ established true custody authority, per the R1 fix at `b712850f`) — the     │
 * │ same predicates `DriverLoadingController::complete()` itself gates on, just  │
 * │ read-only and re-evaluated fresh instead of trusted from a status column     │
 * │ written once and never revisited (the exact defect Task 001/003 proved).     │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ WHY ONE ASSIGNMENT-LEVEL PREDICATE FEEDS TWO CALLERS ────────────────────┐
 * │ `classifyAssignment()` is the single source of truth, reused by both the    │
 * │ existing Group-grain read (`GroupLoadingWorkspaceController::groups()`/      │
 * │ `group()`, one assignment already in hand) and the new session-grain read    │
 * │ (`classifySession()`, aggregating every child under a shared `LoadingSession`│
 * │ per `LoadingSessionProgressCoordinator`'s own contract — one child's state    │
 * │ is never treated as the whole session's). A caller with only one assignment  │
 * │ already loaded should call `classifyAssignment()` directly rather than        │
 * │ round-tripping through a session.                                           │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class LoadingWorkspaceClassificationService
{
    /** Matches `LoadingCustodyService::EPSILON` — decimal(18,4) half-digit tolerance. */
    private const EPSILON = 0.00005;

    public function __construct(private readonly LoadingCustodyService $custody) {}

    /**
     * Classify one already-loaded `VehicleAssignment`.
     *
     * @param  Collection<int, LoadingTask>  $tasks  every LoadingTask row for this assignment
     * @param  bool  $custodyExists  whether >=1 VehicleInventoryItem row exists for this assignment
     * @param  array<string, LoadingTaskAdjustment>  $openAdjustmentsByTaskId  keyed by loading_task_id
     * @return array{bucket:string, reasons:list<string>, task_count:int, unresolved_task_count:int}
     */
    public function classifyAssignment(
        VehicleAssignment $assignment,
        Collection $tasks,
        bool $custodyExists,
        array $openAdjustmentsByTaskId = [],
    ): array {
        $status = $assignment->status instanceof VehicleAssignmentStatus
            ? $assignment->status
            : VehicleAssignmentStatus::from((string) $assignment->status);

        if ($status === VehicleAssignmentStatus::Cancelled) {
            return $this->result(LoadingWorkspaceBucket::CompletedHistory, [LoadingWorkspaceReasonCode::Cancelled], $tasks->count(), 0);
        }

        if ($status === VehicleAssignmentStatus::Pending) {
            // Real, not-yet-started work under an active workflow — genuinely actionable,
            // never "stale Draft" (that distinction belongs to the SESSION, not this).
            return $this->result(LoadingWorkspaceBucket::CurrentActionable, [LoadingWorkspaceReasonCode::PendingLoading], $tasks->count(), 0);
        }

        if ($status === VehicleAssignmentStatus::Loading) {
            $reasons = [LoadingWorkspaceReasonCode::LoadingInProgress];

            foreach ($tasks as $task) {
                $open = $openAdjustmentsByTaskId[(string) $task->id] ?? null;

                if ($open !== null && $open->isOpen()) {
                    $reasons[] = LoadingWorkspaceReasonCode::AdjustmentRequested;

                    break;
                }
            }

            return $this->result(LoadingWorkspaceBucket::CurrentActionable, $reasons, $tasks->count(), 0);
        }

        // Past the loading phase: LoadingComplete, Dispatched, Returning, Reconciling,
        // Reconciled. A recorded status this far along makes a truthful-completion claim —
        // every branch below re-verifies that claim against real evidence rather than
        // trusting the status column, exactly the gap Task 001 proved was never closed.
        if ($tasks->isEmpty()) {
            // The Trip-276 signature: claims past-loading with literally zero execution rows.
            return $this->result(LoadingWorkspaceBucket::NeedsReview, [LoadingWorkspaceReasonCode::MissingLoadingTasks], 0, 0);
        }

        $loadedTasks = $tasks->filter(
            static fn (LoadingTask $task): bool => (float) $task->quantity_loaded > self::EPSILON
        );

        if ($loadedTasks->isNotEmpty() && ! $custodyExists) {
            // Tasks exist and claim a real quantity, but the true custody authority
            // (VehicleInventoryItem — see R1, `b712850f`) never materialized.
            return $this->result(LoadingWorkspaceBucket::NeedsReview, [LoadingWorkspaceReasonCode::MissingVehicleCustody], $tasks->count(), 0);
        }

        $unresolvedCount = 0;
        $reasons = [];
        $hasAdjustmentRequested = false;
        $hasQuantityMismatch = false;

        foreach ($loadedTasks as $task) {
            $open = $openAdjustmentsByTaskId[(string) $task->id] ?? null;
            $state = $this->custody->stateOf($task, $open);

            if (in_array($state, LoadingCustodyService::UNRESOLVED_STATES, true)) {
                $unresolvedCount++;
                $reasons[] = $state === LoadingCustodyService::STATE_AWAITING_DRIVER_RECONFIRMATION
                    ? LoadingWorkspaceReasonCode::AwaitingDriverReconfirmation
                    : LoadingWorkspaceReasonCode::AwaitingDriverConfirmation;

                continue;
            }

            if ($state === LoadingCustodyService::STATE_ADJUSTMENT_REQUESTED) {
                $hasAdjustmentRequested = true;

                continue;
            }

            // DRIVER_CONFIRMED itself can still misreport: the driver's OWN received
            // quantity disagreeing with what they supposedly confirmed against is a real,
            // generic inconsistency `isDriverConfirmationCurrent()` does not catch (it only
            // compares `driver_confirmed_loaded_qty`, not `driver_received_qty`).
            if (
                $state === LoadingCustodyService::STATE_DRIVER_CONFIRMED
                && $task->driver_received_qty !== null
                && abs((float) $task->driver_received_qty - (float) $task->quantity_loaded) > self::EPSILON
            ) {
                $hasQuantityMismatch = true;
            }
        }

        if ($unresolvedCount > 0) {
            return $this->result(LoadingWorkspaceBucket::WaitingDriverConfirmation, array_values(array_unique($reasons, SORT_REGULAR)), $tasks->count(), $unresolvedCount);
        }

        if ($hasQuantityMismatch) {
            return $this->result(LoadingWorkspaceBucket::NeedsReview, [LoadingWorkspaceReasonCode::QuantityMismatch], $tasks->count(), 0);
        }

        $finalReasons = [LoadingWorkspaceReasonCode::TruthfullyComplete];

        if ($hasAdjustmentRequested) {
            $finalReasons[] = LoadingWorkspaceReasonCode::AdjustmentRequested;
        }

        return $this->result(LoadingWorkspaceBucket::CompletedHistory, $finalReasons, $tasks->count(), 0);
    }

    /**
     * Convenience for a caller holding a single `VehicleAssignment` it has not already
     * batched adjustments/custody for (one extra query each — fine at this grain; a list
     * of many assignments should batch instead, as `groups()`/`classifySession()` do).
     *
     * @return array{bucket:string, reasons:list<string>, task_count:int, unresolved_task_count:int}
     */
    public function classifyAssignmentFresh(VehicleAssignment $assignment): array
    {
        $tasks = LoadingTask::query()->where('vehicle_assignment_id', $assignment->id)->get();

        $openByTask = [];

        if ($tasks->isNotEmpty()) {
            foreach (
                LoadingTaskAdjustment::query()
                    ->whereIn('loading_task_id', $tasks->pluck('id')->all())
                    ->where('status', LoadingTaskAdjustment::STATUS_OPEN)
                    ->get() as $open
            ) {
                $openByTask[(string) $open->loading_task_id] = $open;
            }
        }

        $custodyExists = VehicleInventoryItem::query()
            ->where('vehicle_assignment_id', $assignment->id)
            ->exists();

        return $this->classifyAssignment($assignment, $tasks, $custodyExists, $openByTask);
    }

    /**
     * Classify a `LoadingSession` by aggregating every child `VehicleAssignment` —
     * never one child alone (per `LoadingSessionProgressCoordinator`'s own contract).
     *
     * Bucket priority, most severe first: any child NeedsReview makes the whole session
     * NeedsReview (a real problem is never hidden under a sibling's clean status); else
     * any child WaitingDriverConfirmation; else any child CurrentActionable; else
     * CompletedHistory (every child is truthfully complete/cancelled).
     *
     * ZERO ASSIGNMENTS RETURNS NULL UNLESS THE SESSION ITSELF REACHED A TERMINAL
     * STATE (TASK-ECOS-OPERATIONS-DISTRIBUTION-AND-LOADING-FINAL-022 §G). A
     * session with no children and a non-terminal status (Draft, almost always —
     * `CreateLoadingSessionAction`'s own starting state, before anything has
     * happened) is "not started", not "vacuously complete": it is not
     * actionable, not waiting, not a problem, and — the specific defect this
     * task closes — NOT history. §G requires "Completed/History contains only
     * terminal/finalized sessions. NO Draft", so it is excluded from every
     * bucket rather than forced into CompletedHistory the way it was before.
     * The one legitimate exception is a session explicitly cancelled/closed
     * before any assignment was ever created — genuinely terminal despite
     * having nothing under it, so it still reports `NoActivity` and still
     * belongs in history.
     *
     * @return array{
     *     session_id:string, bucket:string, reasons:list<string>,
     *     assignments:list<array<string, mixed>>
     * }|null null means "exclude from every bucket" — see above.
     */
    public function classifySession(LoadingSession $session): ?array
    {
        $assignments = $session->relationLoaded('vehicleAssignments')
            ? $session->vehicleAssignments
            : $session->vehicleAssignments()->with('loadingTasks')->get();

        if ($assignments->isEmpty()) {
            $status = $session->status instanceof LoadingSessionStatus
                ? $session->status
                : LoadingSessionStatus::from((string) $session->status);

            if (! $status->isTerminal()) {
                return null;
            }

            return [
                'session_id' => (string) $session->id,
                'bucket' => LoadingWorkspaceBucket::CompletedHistory->value,
                'reasons' => [LoadingWorkspaceReasonCode::NoActivity->value],
                'assignments' => [],
            ];
        }

        // Batched, not per-assignment: one query for every open adjustment across every
        // task under every assignment, one for custody existence — never N+1 per child.
        $allTaskIds = $assignments
            ->flatMap(static fn (VehicleAssignment $a): Collection => $a->relationLoaded('loadingTasks')
                ? $a->loadingTasks
                : $a->loadingTasks()->get())
            ->pluck('id')
            ->all();

        $openAdjustmentsByTaskId = [];

        if ($allTaskIds !== []) {
            foreach (
                LoadingTaskAdjustment::query()
                    ->whereIn('loading_task_id', $allTaskIds)
                    ->where('status', LoadingTaskAdjustment::STATUS_OPEN)
                    ->get() as $open
            ) {
                $openAdjustmentsByTaskId[(string) $open->loading_task_id] = $open;
            }
        }

        $custodyAssignmentIds = array_flip(
            VehicleInventoryItem::query()
                ->whereIn('vehicle_assignment_id', $assignments->pluck('id')->all())
                ->distinct()
                ->pluck('vehicle_assignment_id')
                ->map(static fn ($id): string => (string) $id)
                ->all()
        );

        $childResults = [];
        $bucketsSeen = [];

        foreach ($assignments as $assignment) {
            $tasks = $assignment->relationLoaded('loadingTasks')
                ? $assignment->loadingTasks
                : $assignment->loadingTasks()->get();

            $classification = $this->classifyAssignment(
                $assignment,
                $tasks,
                isset($custodyAssignmentIds[(string) $assignment->id]),
                $openAdjustmentsByTaskId,
            );

            $bucketsSeen[$classification['bucket']] = true;

            // TASK-ECOS-OPERATIONS-DISTRIBUTION-AND-LOADING-FINAL-022 §G — raw
            // audit facts alongside the classification, not folded into
            // classifyAssignment()'s own bucket/reasons contract (that stays
            // exactly as `groups()`/`group()` already depend on it). Quantities
            // are summed from `loadingTasks`, already loaded for classification —
            // no second query.
            $loadedQuantity = (float) $tasks->sum(static fn (LoadingTask $t): float => (float) $t->quantity_loaded);
            $acceptedQuantity = (float) $tasks->sum(
                static fn (LoadingTask $t): float => $t->driver_confirmed_loaded_qty !== null
                    ? (float) $t->driver_confirmed_loaded_qty
                    : 0.0,
            );

            $childResults[] = [
                'vehicle_assignment_id' => (string) $assignment->id,
                'trip_id' => $assignment->trip_id,
                'status' => $assignment->status instanceof VehicleAssignmentStatus
                    ? $assignment->status->value
                    : (string) $assignment->status,
                'loaded_quantity' => $loadedQuantity,
                'accepted_quantity' => $acceptedQuantity,
                // Never negative: a driver cannot "accept" more than was loaded, so a
                // negative value here would signal a data defect, not a real return.
                'unaccepted_returned_quantity' => max(0.0, $loadedQuantity - $acceptedQuantity),
                // The one moment this execution actually ended, whichever of the two
                // terminal paths it took — null while still open.
                'closed_at' => ($assignment->cancelled_at ?? $assignment->reconciled_at)?->toIso8601String(),
                // Only ever meaningful for a Cancelled assignment (successful
                // completion needs no reason) — null otherwise.
                'cancellation_reason' => $assignment->cancellation_reason,
            ] + $classification;
        }

        $sessionBucket = match (true) {
            isset($bucketsSeen[LoadingWorkspaceBucket::NeedsReview->value]) => LoadingWorkspaceBucket::NeedsReview,
            isset($bucketsSeen[LoadingWorkspaceBucket::WaitingDriverConfirmation->value]) => LoadingWorkspaceBucket::WaitingDriverConfirmation,
            isset($bucketsSeen[LoadingWorkspaceBucket::CurrentActionable->value]) => LoadingWorkspaceBucket::CurrentActionable,
            default => LoadingWorkspaceBucket::CompletedHistory,
        };

        // A session with children in more than one distinct bucket is itself a fact worth
        // surfacing — an operator reading only the session-level badge should not be able
        // to assume every child agrees with it.
        $childBucketsDiffer = count(array_unique(array_column($childResults, 'bucket'))) > 1;

        $sessionReasons = collect($childResults)
            ->flatMap(static fn (array $child): array => $child['reasons'])
            ->when(
                $childBucketsDiffer,
                static fn (Collection $reasons): Collection => $reasons->push(LoadingWorkspaceReasonCode::InconsistentChildState->value),
            )
            ->unique()
            ->values()
            ->all();

        return [
            'session_id' => (string) $session->id,
            'bucket' => $sessionBucket->value,
            'reasons' => $sessionReasons,
            'assignments' => $childResults,
        ];
    }

    /** @param  list<LoadingWorkspaceReasonCode>  $reasons */
    private function result(LoadingWorkspaceBucket $bucket, array $reasons, int $taskCount, int $unresolvedCount): array
    {
        return [
            'bucket' => $bucket->value,
            'reasons' => array_values(array_unique(array_map(
                static fn (LoadingWorkspaceReasonCode $r): string => $r->value,
                $reasons,
            ))),
            'task_count' => $taskCount,
            'unresolved_task_count' => $unresolvedCount,
        ];
    }
}
