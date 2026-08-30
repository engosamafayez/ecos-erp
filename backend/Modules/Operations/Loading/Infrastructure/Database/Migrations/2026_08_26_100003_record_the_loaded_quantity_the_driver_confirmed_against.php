<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-LOADING-WAREHOUSE-DRIVER-CUSTODY-IMPLEMENTATION-001 — make the reconfirmation
 * invariant exact.
 *
 * ┌─ WHY TIMESTAMPS COULD NOT CARRY THIS ────────────────────────────────────┐
 * │ The approved architecture derived staleness from                          │
 * │     driver_confirmed_at < confirmed_at                                    │
 * │ so that a warehouse revision would invalidate an earlier driver           │
 * │ confirmation with no reset routine. Two focused runs proved it does not   │
 * │ hold:                                                                     │
 * │                                                                          │
 * │   1. Both columns were second-precision, so a revision in the same second │
 * │      compared EQUAL and `>=` kept the stale confirmation.                 │
 * │   2. Widening both to TIMESTAMP(6) did NOT fix it: Eloquent's default     │
 * │      `$dateFormat` is 'Y-m-d H:i:s', so Laravel writes second-truncated   │
 * │      values whatever the column precision allows.                         │
 * │                                                                          │
 * │ Forcing `$dateFormat = 'Y-m-d H:i:s.u'` on the model would have made      │
 * │ MySQL ROUND every other datetime on that row (created_at, updated_at,     │
 * │ loaded_at are TIMESTAMP(0)) — trading a workflow bug for a subtler        │
 * │ audit-timestamp bug.                                                      │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * THE FIX IS TO COMPARE WHAT, NOT WHEN. A driver confirms receipt *against a
 * specific warehouse quantity*. Recording that quantity makes the rule exact and
 * precision-independent:
 *
 *     stale  ⟺  driver_confirmed_loaded_qty ≠ quantity_loaded
 *
 * It is also truer to the domain. "The number you agreed to has changed, so please
 * agree again" is the actual business rule; clock ordering was only ever a proxy for
 * it, and a lossy one.
 *
 * It keeps every property the architecture wanted: still DERIVED (no stored workflow
 * status), still no reset routine, and a warehouse revision still invalidates an
 * earlier confirmation automatically — now without depending on the clock.
 *
 * NOTE ON THE PARTIAL-ACCEPTANCE CASE. A driver may legitimately confirm receiving 2
 * against a warehouse-loaded 3 without disputing it. That stays CONFIRMED, because
 * what is compared is the WAREHOUSE quantity they confirmed against (3 = 3) — never
 * their own received quantity.
 *
 * Additive, nullable, no backfill: NULL means "confirmed before this column existed,
 * or not confirmed at all", and `isDriverConfirmationCurrent()` treats NULL as not
 * current — failing CLOSED, which asks for a re-confirmation rather than silently
 * accepting one that cannot be verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('loading_tasks')) {
            return;
        }

        if (Schema::hasColumn('loading_tasks', 'driver_confirmed_loaded_qty')) {
            return;
        }

        Schema::table('loading_tasks', function (Blueprint $table): void {
            $table->decimal('driver_confirmed_loaded_qty', 18, 4)
                ->nullable()
                ->after('driver_confirmed_by');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('loading_tasks') || ! Schema::hasColumn('loading_tasks', 'driver_confirmed_loaded_qty')) {
            return;
        }

        Schema::table('loading_tasks', function (Blueprint $table): void {
            $table->dropColumn('driver_confirmed_loaded_qty');
        });
    }
};
