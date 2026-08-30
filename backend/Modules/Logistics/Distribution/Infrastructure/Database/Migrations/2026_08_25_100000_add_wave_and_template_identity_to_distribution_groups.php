<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-DISTRIBUTION-DAILY-GROUP-WAVE-LIFECYCLE-002 — durable Group identity.
 *
 * ┌─ WHAT TASK 001 PROVED WAS MISSING ───────────────────────────────────────┐
 * │ Nothing in the schema recorded which Preparation Wave a Group belonged to │
 * │ or which Template stamped it. The only link a Group had was              │
 * │ `distribution_window_id`, and a Window is (company, calendar day) while a │
 * │ Wave is (company, warehouse, planning date, type) — so a Window can hold  │
 * │ several Waves and cannot answer "is there already a Group for Template A  │
 * │ in Wave Y?".                                                             │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * FOUR ADDITIVE NULLABLE COLUMNS. Nothing existing is altered or dropped, every
 * current row stays valid, and every existing query keeps working — the columns are
 * new information, not a changed contract.
 *
 * `distribution_group_template_id` IS PROVENANCE, NOT A LIVE REFERENCE. It records
 * which blueprint stamped this instance and is never read to derive the Group's
 * configuration — the Group's own `distribution_slot_zones` and `capacity_orders`
 * remain its only configuration. So a later Template edit still cannot reach a Group
 * that already exists. Deliberately NOT a foreign key, matching every other
 * Distribution migration in this module, so archiving a Template cannot cascade into
 * operational history.
 *
 * THE UNIQUE INDEX ENFORCES THE ONE RULE THAT MATTERS. MySQL treats NULLs as distinct
 * in a unique index, so:
 *   (wave, template)  -> at most ONE auto-created Group per Template per Wave
 *   (wave, NULL)      -> unconstrained, because operator-created Groups have no
 *                        Template and there may legitimately be many
 *   (NULL, NULL)      -> unconstrained, which is what historical rows are
 * That is exactly the invariant PART 5 states, and it needs no partial index — which
 * MySQL does not support anyway.
 *
 * BACKFILL IS DETERMINISTIC OR ABSENT. A Group's Wave is filled in ONLY where
 * (company, warehouse, window date) matches exactly one Wave. Where a day holds
 * several Waves the column is left NULL rather than guessed: PART 20 forbids inferring
 * lineage, and a wrong Wave stamped on operational history is worse than a missing one.
 * Template identity is left NULL for every existing row — no reliable source for it
 * exists, and `applied_from_template_id` was only ever echoed from a request URL.
 *
 * Verified against live data before writing: all 3 existing Groups fall on 2026-08-21,
 * which has exactly one Wave, so all 3 classify deterministically. The one ambiguous
 * day (2026-08-20, three Waves) holds no Groups at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribution_virtual_slots', function (Blueprint $table): void {
            $table->char('preparation_wave_id', 36)->nullable()->after('distribution_window_id');
            $table->char('distribution_group_template_id', 36)->nullable()->after('code');

            // Lifecycle. The table had no status column at all, so "historical because
            // its Wave ended" could not be told from "active". One timestamp plus a
            // reason is the least that can express it; the operational states before
            // closure stay derived from the existing Trip/Loading records rather than
            // duplicated here.
            $table->timestamp('closed_at')->nullable()->after('overflow_approved_by');
            $table->string('closed_reason', 40)->nullable()->after('closed_at');
        });

        Schema::table('distribution_virtual_slots', function (Blueprint $table): void {
            $table->index('preparation_wave_id', 'dist_slot_wave_idx');
            $table->unique(
                ['preparation_wave_id', 'distribution_group_template_id'],
                'dist_slot_wave_template_unique',
            );
        });

        $this->backfillWaveIdentity();
    }

    public function down(): void
    {
        Schema::table('distribution_virtual_slots', function (Blueprint $table): void {
            $table->dropUnique('dist_slot_wave_template_unique');
            $table->dropIndex('dist_slot_wave_idx');
        });

        Schema::table('distribution_virtual_slots', function (Blueprint $table): void {
            $table->dropColumn([
                'preparation_wave_id',
                'distribution_group_template_id',
                'closed_at',
                'closed_reason',
            ]);
        });
    }

    /**
     * Stamp the Wave on Groups whose Wave is unambiguous, and only those.
     *
     * One UPDATE per Group rather than a set-based join, because the decision is
     * per-row ("does exactly one Wave match?") and a join would silently pick an
     * arbitrary Wave on the days where several exist.
     */
    private function backfillWaveIdentity(): void
    {
        $groups = DB::table('distribution_virtual_slots as s')
            ->join('distribution_windows as w', 'w.id', '=', 's.distribution_window_id')
            ->select('s.id', 's.company_id', 's.warehouse_id', 'w.window_date')
            ->get();

        foreach ($groups as $group) {
            $waveIds = DB::table('preparation_waves')
                ->where('company_id', $group->company_id)
                ->where('warehouse_id', $group->warehouse_id)
                ->whereDate('planning_date', $group->window_date)
                ->pluck('id')
                ->all();

            // Exactly one candidate, or nothing is written.
            if (count($waveIds) !== 1) {
                continue;
            }

            DB::table('distribution_virtual_slots')
                ->where('id', $group->id)
                ->update(['preparation_wave_id' => $waveIds[0]]);
        }
    }
};
