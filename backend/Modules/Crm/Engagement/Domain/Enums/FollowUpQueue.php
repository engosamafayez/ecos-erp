<?php

declare(strict_types=1);

namespace Modules\Crm\Engagement\Domain\Enums;

/**
 * The derived (never stored) work-queue bucket for an OPEN Follow-Up
 * (TASK-ECOS-CRM-CUSTOMER-PORTFOLIO-AND-FOLLOWUP-003 §9/§10). A closed
 * (completed/cancelled) task has no queue — see FollowUpQueueClassifier.
 */
enum FollowUpQueue: string
{
    case Overdue = 'overdue';
    case DueToday = 'due_today';
    case Upcoming = 'upcoming';
    case Unscheduled = 'unscheduled';
}
