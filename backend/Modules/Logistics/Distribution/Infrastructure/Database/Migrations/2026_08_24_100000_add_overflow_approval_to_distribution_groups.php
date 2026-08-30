<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1-B-A2 — explicit Overflow Approval for a Distribution Group.
 *
 * ┌─ THE GAP THIS CLOSES ────────────────────────────────────────────────────┐
 * │ `capacity_orders` is a PLANNING capacity. Automatic ingestion is          │
 * │ deliberately not policed against it (GroupCapacityGuard states why: the   │
 * │ global unique on `order_id` makes a refused assignment unretryable, so a  │
 * │ capacity refusal there would silently drop work out of Distribution), and │
 * │ Finalize is the backstop that refuses an over-capacity Group.             │
 * │                                                                          │
 * │ There was no way to say "the operator looked at this and accepted it".    │
 * │ The only levers were raising `capacity_orders` or nulling it — both of    │
 * │ which change the LIMIT rather than approving an exception against it, and │
 * │ both of which the owner explicitly forbade as approval mechanisms.        │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * THREE COLUMNS, AND NONE IS REDUNDANT.
 *
 *   overflow_approved_orders  The order count the operator approved. THIS IS WHAT
 *                             MAKES THE APPROVAL BOUNDED. Without it an approval is
 *                             a blanket waiver: approve at 25, drift to 40, and
 *                             Finalize would still pass — which is precisely the
 *                             "capacity is unlimited" meaning the contract forbids.
 *                             With it, growth past the approved figure re-blocks and
 *                             asks the operator again.
 *   overflow_approved_at      When. Required for the approval to be auditable.
 *   overflow_approved_by      Who. Same requirement, and the same
 *                             `*_by bigint unsigned nullable` shape this module
 *                             already uses (`distribution_trips.finalized_by`,
 *                             `dispatched_by`, `distribution_group_product_preparation
 *                             .last_recorded_by`).
 *
 * WHAT IT DOES NOT DO. It does not touch `capacity_orders`, which keeps its exact
 * meaning: the Group's planning limit. An approved Group with capacity 20 and 25
 * orders still reports a maximum of 20 — the approval records an accepted exception,
 * never a new limit. No second capacity axis is introduced and nothing reads the
 * three non-contract capacity columns.
 *
 * NO BACKFILL. Every existing Group gets NULL, which means "not approved", so their
 * behaviour is byte-for-byte what it was: within capacity they finalize, over capacity
 * Finalize still refuses. Nullable and reversible; the down() drops only what up()
 * added and rewrites no data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribution_virtual_slots', function (Blueprint $table): void {
            // Placed after the capacity columns it qualifies, so a reader of the table
            // sees the limit and the accepted exception to it together.
            $table->unsignedInteger('overflow_approved_orders')->nullable()->after('capacity_volume_m3');
            $table->timestamp('overflow_approved_at')->nullable()->after('overflow_approved_orders');
            $table->unsignedBigInteger('overflow_approved_by')->nullable()->after('overflow_approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('distribution_virtual_slots', function (Blueprint $table): void {
            $table->dropColumn([
                'overflow_approved_orders',
                'overflow_approved_at',
                'overflow_approved_by',
            ]);
        });
    }
};
