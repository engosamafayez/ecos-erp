<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Enums;

/**
 * V1 lifecycle only (brief §4) — deliberately no BLOCKED/REVIEW/APPROVED/
 * REJECTED/QA/ARCHIVED. Allowed transitions live in
 * TransitionTaskStatusAction, not here — this enum is just the state set.
 */
enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
