<?php

declare(strict_types=1);

namespace Modules\Crm\Engagement\Domain\Services;

use Illuminate\Support\Carbon;
use Modules\Crm\Engagement\Domain\Enums\FollowUpQueue;
use Modules\Crm\Engagement\Domain\Enums\TaskStatus;

/**
 * The single overdue/due-today/upcoming/unscheduled authority for a Follow-Up
 * (TASK-ECOS-CRM-CUSTOMER-PORTFOLIO-AND-FOLLOWUP-003 §9/§10). Stateless and
 * called with plain values (not the Eloquent model) so Portfolio's batched,
 * in-memory pass over many rows and Customer 360's single-row read run the
 * exact same computation — the ratified invariant that a stored Follow-Up
 * can never disagree about its own overdue state depending on which screen
 * asks. No `is_overdue` column exists or is written anywhere.
 *
 * OVERDUE takes priority over DUE TODAY for a due time already in the past
 * today (e.g. 9am, now 2pm) — the ratification's §9/§10 wording taken
 * literally would double-count that case; resolving it this way keeps the
 * four buckets an honest partition, matching the ratified filter/precedence
 * order in §14 (Overdue listed before Due Today).
 *
 * Timezone authority: `config('app.timezone')` — the application's own
 * already-established scheduling clock (`.env.example` ships
 * `Africa/Cairo` for local/DEV, `.env.production.example`/`.env.staging.example`
 * both pin `UTC`; no company-level timezone column exists anywhere in
 * canonical source). See the Task 003 report §11 for the full evidence this
 * is based on, rather than a new CRM-specific setting.
 */
final class FollowUpQueueClassifier
{
    /** Null for a closed (completed/cancelled) task — it belongs to no open queue. */
    public static function classify(TaskStatus $status, ?Carbon $dueAt, ?Carbon $now = null): ?FollowUpQueue
    {
        if ($status !== TaskStatus::Open) {
            return null;
        }

        if ($dueAt === null) {
            return FollowUpQueue::Unscheduled;
        }

        $now ??= Carbon::now(config('app.timezone'));

        if ($dueAt->lessThan($now)) {
            return FollowUpQueue::Overdue;
        }

        if ($dueAt->lessThanOrEqualTo($now->copy()->endOfDay())) {
            return FollowUpQueue::DueToday;
        }

        return FollowUpQueue::Upcoming;
    }

    public static function isOverdue(TaskStatus $status, ?Carbon $dueAt, ?Carbon $now = null): bool
    {
        return self::classify($status, $dueAt, $now) === FollowUpQueue::Overdue;
    }
}
