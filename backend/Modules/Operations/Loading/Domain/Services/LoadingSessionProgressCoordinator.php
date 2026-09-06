<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Domain\Services;

use Modules\Operations\Loading\Application\Actions\CompleteLoadingAction;
use Modules\Operations\Loading\Application\Actions\OpenLoadingSessionAction;
use Modules\Operations\Loading\Application\Actions\StartLoadingAction;
use Modules\Operations\Loading\Domain\Enums\LoadingSessionStatus;
use Modules\Operations\Loading\Domain\Enums\VehicleAssignmentStatus;
use Modules\Operations\Loading\Domain\Models\LoadingSession;
use RuntimeException;

/**
 * TASK-ECOS-OPERATIONS-LOADING-LIFECYCLE-CUSTODY-IMPLEMENTATION-002 — the parent-session
 * coordinator the architecture review (Task 001) found missing.
 *
 * ┌─ THE GAP THIS CLOSES ────────────────────────────────────────────────────┐
 * │ A LoadingSession is shared per warehouse+day across several Trips, each   │
 * │ as its own VehicleAssignment (GroupLoadingContextService's own contract). │
 * │ DriverLoadingController::complete() therefore flips ONLY the assignment   │
 * │ to LoadingComplete — calling the session-wide CompleteLoadingAction       │
 * │ there would wrongly complete other drivers' still-pending work. Nothing   │
 * │ was ever built to advance the SESSION once every assignment under it      │
 * │ finishes on its own, so the session stayed Draft forever (Task 001, §18). │
 * └────────────────────────────────────────────────────────────────────────────┘
 *
 * NOT A SECOND ENGINE. Both methods below only ever call the EXISTING, already-
 * guarded session actions (OpenLoadingSessionAction/StartLoadingAction/
 * CompleteLoadingAction) — this class decides WHEN it is safe to call them from
 * the assignment-scoped Group/Trip flow, never re-implements what they do.
 *
 * IDEMPOTENT BY RE-EVALUATION, not by a stored flag. Every call re-reads the
 * session's current status and its assignments' current statuses fresh, so a
 * repeated or out-of-order call is always safe: it either advances exactly the
 * legal next hop or finds nothing to do.
 */
final class LoadingSessionProgressCoordinator
{
    public function __construct(
        private readonly OpenLoadingSessionAction $openSession,
        private readonly StartLoadingAction $startLoading,
        private readonly CompleteLoadingAction $completeLoading,
    ) {}

    /**
     * Walk Draft -> Ready -> Loading the first time real loading activity begins.
     *
     * Called from the same moment an assignment itself first moves Pending -> Loading
     * (DriverLoadingController::markLoading()) — the truthful "loading has started"
     * signal already used at the assignment grain, reused here at the session grain,
     * so Draft no longer outlives real canonical progress (Task 001, §6/§13).
     * No-ops once the session is already at Loading or beyond.
     *
     * A shared session can have two different assignments each make their OWN first
     * load call at nearly the same moment, each racing to open/start the same session.
     * Both hops are refused by the underlying actions (RuntimeException) if the status
     * has already moved past what this method last observed — swallowed here exactly
     * like advanceIfComplete() below, since "a concurrent caller already advanced it"
     * is success, not failure, for the caller's own assignment-level transaction.
     */
    public function ensureStarted(LoadingSession $session, string $actorId): void
    {
        try {
            $status = $this->statusOf($session);

            if ($status === LoadingSessionStatus::Draft) {
                $session = $this->openSession->execute($session, $actorId);
                $status = $this->statusOf($session);
            }

            if ($status === LoadingSessionStatus::Ready) {
                $this->startLoading->execute($session, $actorId);
            }
        } catch (RuntimeException) {
            // Defensive only — see docblock above.
        }
    }

    /**
     * Advance Loading -> LoadingComplete once EVERY vehicle assignment under this
     * session has independently reached a loading-terminal state (LoadingComplete or
     * Cancelled).
     *
     * ┌─ WHY THIS ASKS A DIFFERENT QUESTION THAN CompleteLoadingAction'S OWN GUARD ──┐
     * │ CompleteLoadingAction::execute() refuses if ANY loading_tasks row session-   │
     * │ wide is still pending/in_progress — correct for the session-centric admin    │
     * │ screen's own explicit "complete loading" button, wrong here: one driver's    │
     * │ own unfinished task would then block another driver's already-finished       │
     * │ assignment from ever letting the shared session close. This asks about       │
     * │ ASSIGNMENTS instead — each already gated by its own completion contract       │
     * │ (LoadingCustodyService::unresolvedLoadedTasks()) before it could reach        │
     * │ LoadingComplete at all, so re-checking raw task status here would be          │
     * │ redundant, not safer.                                                         │
     * └────────────────────────────────────────────────────────────────────────────┘
     *
     * A session with ZERO assignments is not eligible — that is "not started", not
     * "vacuously complete" (Task 001 §7's "document and explicitly handle" instruction
     * applied at the session grain).
     *
     * Delegates the actual write to the existing CompleteLoadingAction so the session
     * status transition, the loading_completed_at stamp, and the VehicleLoaded events
     * stay exactly as that action already defines them — this method decides only
     * WHETHER it is safe to call it, never duplicates what it does. If that action's
     * own guard still refuses — its session-wide pending-task check (a stray task from
     * the separate pool-based grain; never observed alongside the Group grain this flow
     * uses), or a same-moment race where a sibling assignment's own completion already
     * advanced the session between this method's own status check and this call — the
     * refusal is swallowed rather than surfaced as an unrelated failure inside the
     * caller's own assignment-completion transaction. Either way the session is left
     * exactly where it truthfully is; a later re-evaluation (or the admin screen) can
     * still close it.
     */
    public function advanceIfComplete(LoadingSession $session, string $actorId): void
    {
        if ($this->statusOf($session) !== LoadingSessionStatus::Loading) {
            return;
        }

        $assignments = $session->vehicleAssignments()->get();

        if ($assignments->isEmpty()) {
            return;
        }

        foreach ($assignments as $assignment) {
            $assignmentStatus = $assignment->status instanceof VehicleAssignmentStatus
                ? $assignment->status
                : VehicleAssignmentStatus::from((string) $assignment->status);

            if (! in_array($assignmentStatus, [VehicleAssignmentStatus::LoadingComplete, VehicleAssignmentStatus::Cancelled], true)) {
                return;
            }
        }

        try {
            $this->completeLoading->execute($session, $actorId);
        } catch (RuntimeException) {
            // Defensive only — see docblock above.
        }
    }

    private function statusOf(LoadingSession $session): LoadingSessionStatus
    {
        return $session->status instanceof LoadingSessionStatus
            ? $session->status
            : LoadingSessionStatus::from((string) $session->status);
    }
}
