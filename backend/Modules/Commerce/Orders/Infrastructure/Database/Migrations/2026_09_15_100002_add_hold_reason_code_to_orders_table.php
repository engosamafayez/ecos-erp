<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009.
 *
 * Hold Reason Authority (§15). No hold_reason/status_reason field existed on
 * `orders` before this — only `order_events.reason` (a free-text audit-log entry,
 * not a live/queryable column on the order) and the unrelated
 * `reservation_failure_reason` (stock-shortage-only, added by
 * 2026_07_18_100000_add_reservation_status_to_orders_table.php).
 *
 * Mirrors that exact precedent: a small, nullable, machine-readable sub-reason
 * column scoped to one lifecycle status (there: awaiting_stock/failed; here:
 * on_hold), not a second Order state machine (§14) and not free text as the sole
 * authority. Written by MoveToReviewWorkflow when entering On Hold and cleared by
 * ProcessOrderWorkflow/ConfirmOrderWorkflow when leaving it — see those workflows.
 * Closed vocabulary for now: only 'blocked_customer' is written by this task.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'hold_reason_code')) {
            return;
        }

        Schema::table('orders', static function (Blueprint $table): void {
            $table->string('hold_reason_code', 50)
                ->nullable()
                ->default(null)
                ->after('reservation_failure_reason')
                ->comment('Machine-readable sub-reason while status = on_hold, e.g. blocked_customer');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'hold_reason_code')) {
            return;
        }

        Schema::table('orders', static function (Blueprint $table): void {
            $table->dropColumn('hold_reason_code');
        });
    }
};
