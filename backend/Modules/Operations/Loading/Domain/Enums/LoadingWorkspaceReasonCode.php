<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Domain\Enums;

/**
 * Stable, machine-readable reasons behind a `LoadingWorkspaceBucket` classification.
 * One assignment/session may carry more than one — e.g. `TruthfullyComplete` alongside
 * `AdjustmentRequested` when a resolved shipment still has an open driver dispute.
 *
 * Presentation-only, same as `LoadingWorkspaceBucket` — never persisted.
 */
enum LoadingWorkspaceReasonCode: string
{
    // ── CurrentActionable ───────────────────────────────────────────────────
    case PendingLoading = 'pending_loading';

    case LoadingInProgress = 'loading_in_progress';

    case AdjustmentRequested = 'adjustment_requested';

    // ── WaitingDriverConfirmation ───────────────────────────────────────────
    case AwaitingDriverConfirmation = 'awaiting_driver_confirmation';

    case AwaitingDriverReconfirmation = 'awaiting_driver_reconfirmation';

    // ── NeedsReview ─────────────────────────────────────────────────────────
    case MissingLoadingTasks = 'missing_loading_tasks';

    case MissingVehicleCustody = 'missing_vehicle_custody';

    case QuantityMismatch = 'quantity_mismatch';

    case InconsistentChildState = 'inconsistent_child_state';

    // ── CompletedHistory ────────────────────────────────────────────────────
    case TruthfullyComplete = 'truthfully_complete';

    case NoActivity = 'no_activity';

    case Cancelled = 'cancelled';
}
