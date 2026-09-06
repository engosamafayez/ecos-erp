<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Domain\Enums;

/**
 * TASK-ECOS-OPERATIONS-LOADING-LIFECYCLE-CUSTODY-WORKSPACE-READ-MODEL-004 —
 * presentation-only classification for the Loading Workspace.
 *
 * ┌─ READ-MODEL, NOT A LIFECYCLE ─────────────────────────────────────────────┐
 * │ This is never persisted, cast on an Eloquent model, or checked by any      │
 * │ write path. It is computed fresh on every read by                         │
 * │ `LoadingWorkspaceClassificationService` from the existing canonical        │
 * │ authorities (`LoadingSessionStatus`, `VehicleAssignmentStatus`,            │
 * │ `LoadingCustodyService::stateOf()`, `VehicleInventoryItem` existence) —    │
 * │ it does not compete with, replace, or gate any of them.                   │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
enum LoadingWorkspaceBucket: string
{
    /** Real, unfinished operational work — a driver/warehouse action is expected next. */
    case CurrentActionable = 'current_actionable';

    /** Warehouse-side loading evidence exists; the open item is the driver's own (re)confirmation. */
    case WaitingDriverConfirmation = 'waiting_driver_confirmation';

    /** Either genuinely, truthfully complete, or historical with no activity to act on. */
    case CompletedHistory = 'completed_history';

    /** Evidence is missing or contradicts a recorded status — needs a human decision, not a routine step. */
    case NeedsReview = 'needs_review';
}
