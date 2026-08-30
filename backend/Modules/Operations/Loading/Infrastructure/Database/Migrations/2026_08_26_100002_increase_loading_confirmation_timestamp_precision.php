<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-LOADING-WAREHOUSE-DRIVER-CUSTODY-IMPLEMENTATION-001 — staleness needs sub-second
 * precision to be decidable.
 *
 * ┌─ THE DEFECT THIS CLOSES ─────────────────────────────────────────────────┐
 * │ The workflow state is DERIVED, and its central invariant is:               │
 * │                                                                          │
 * │     a warehouse re-confirmation makes an earlier driver confirmation      │
 * │     stale, because `driver_confirmed_at < confirmed_at`.                  │
 * │                                                                          │
 * │ Both columns were second-precision TIMESTAMPs. A warehouse revision in    │
 * │ the SAME SECOND as an earlier driver confirmation therefore compared      │
 * │ EQUAL, and the comparison returned "still current" — silently keeping a   │
 * │ driver confirmation against a quantity the driver never agreed to. That   │
 * │ is the one thing the reconfirmation rule exists to prevent.               │
 * │                                                                          │
 * │ A focused test caught it only intermittently: the first run happened to   │
 * │ straddle a second boundary and passed.                                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * WHY PRECISION AND NOT A DIFFERENT OPERATOR. Neither comparison works at second
 * granularity. `>=` fails OPEN (keeps a stale confirmation — dangerous). `>` fails
 * CLOSED but breaks the ordinary case, where a driver confirming in the same second
 * as the warehouse would be told to confirm again, forever. The ambiguity is in the
 * stored value, so it has to be fixed there.
 *
 * WHY NOT A STORED "generation" COLUMN. That would add a second source of truth for
 * an ordering the timestamps already express — and the approved architecture
 * explicitly derives this state rather than storing it.
 *
 * SAFETY. Widening TIMESTAMP → TIMESTAMP(6) is loss-free and backward compatible:
 * existing values keep their value with a zero fraction, nullability is preserved,
 * no row is rewritten in a way that changes its meaning, and no code that reads these
 * columns needs to change. `confirmed_at` predates this task, so it is widened rather
 * than redefined — its type, nullability and meaning are otherwise untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('loading_tasks')) {
            return;
        }

        // Pre-existing column (2026_07_05), now claimed for the warehouse confirmation.
        if (Schema::hasColumn('loading_tasks', 'confirmed_at')) {
            DB::statement('ALTER TABLE loading_tasks MODIFY confirmed_at TIMESTAMP(6) NULL');
        }

        if (Schema::hasColumn('loading_tasks', 'driver_confirmed_at')) {
            DB::statement('ALTER TABLE loading_tasks MODIFY driver_confirmed_at TIMESTAMP(6) NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('loading_tasks')) {
            return;
        }

        // Narrowing TRUNCATES the fraction, which is exactly the ambiguity this
        // migration removed — acceptable only as a deliberate rollback.
        if (Schema::hasColumn('loading_tasks', 'confirmed_at')) {
            DB::statement('ALTER TABLE loading_tasks MODIFY confirmed_at TIMESTAMP NULL');
        }

        if (Schema::hasColumn('loading_tasks', 'driver_confirmed_at')) {
            DB::statement('ALTER TABLE loading_tasks MODIFY driver_confirmed_at TIMESTAMP NULL');
        }
    }
};
