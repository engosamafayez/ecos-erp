<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-PREPARATION-WAVE-CROSS-DAY-TRANSITION-002 — the Wave becomes an OPERATIONAL CYCLE.
 *
 * A wave used to be a calendar day: `planning_date` plus three wall-clock strings on
 * `wave_engine_configurations`, re-evaluated on every scheduler tick. That model cannot
 * express a cycle that crosses midnight (18:00 → 08:00 → 15:00), and it made closure
 * unreachable for any wave whose `planning_date` was not today — the scheduler fetches
 * the active wave date-scoped, so a wave left behind by a missed tick was stranded
 * forever (G-3, and 3 of 5 live waves were in exactly that state).
 *
 * These three columns make the cycle a STORED FACT rather than a recomputed comparison:
 *
 *   starts_at         the instant intake opened      (cycle identity)
 *   intake_closes_at  the instant intake closes      — Collecting → Preparing
 *   ends_at           the instant the cycle ends     — → Closed
 *
 * They are absolute instants, resolved through `companies.timezone` at creation (G-2),
 * so a later configuration edit cannot retroactively move a wave that is already running,
 * and closure becomes `now() >= ends_at` — evaluable for ANY wave, of any date, which is
 * precisely what G-3 requires.
 *
 * `planning_date` is DELIBERATELY RETAINED. It stays the cycle's calendar identity: it
 * keys wave numbering (PREP-YYYYMM-NNNNNN), the createCollectingWave idempotency lock,
 * the workspace picker sort and every existing report. It simply stops being the
 * authority on whether a wave has ended.
 *
 * ADDITIVE AND REVERSIBLE. Three nullable columns and one index; no column is altered
 * and no row is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('preparation_waves')) {
            return;
        }

        if (! Schema::hasColumn('preparation_waves', 'starts_at')) {
            Schema::table('preparation_waves', function (Blueprint $table): void {
                $table->timestamp('starts_at')->nullable()->after('planning_date');
                $table->timestamp('intake_closes_at')->nullable()->after('starts_at');
                $table->timestamp('ends_at')->nullable()->after('intake_closes_at');

                // The stale-wave sweep runs every minute across all open waves:
                // WHERE status IN (...) AND ends_at <= now.
                $table->index(['status', 'ends_at'], 'idx_prep_waves_status_ends_at');
            });
        }

        $this->backfill();
    }

    public function down(): void
    {
        if (! Schema::hasTable('preparation_waves')) {
            return;
        }

        if (! Schema::hasColumn('preparation_waves', 'starts_at')) {
            return;
        }

        Schema::table('preparation_waves', function (Blueprint $table): void {
            $table->dropIndex('idx_prep_waves_status_ends_at');
            $table->dropColumn(['starts_at', 'intake_closes_at', 'ends_at']);
        });
    }

    /**
     * Give every pre-existing wave a resolved cycle.
     *
     * Legacy waves were single-day by construction, so they are backfilled as such:
     * the whole of `planning_date` in the company's timezone. The consequence is
     * deliberate and is the point of G-3 — every wave dated before today acquires an
     * `ends_at` in the past and is therefore closed by the scheduler's first
     * reconciliation pass, instead of remaining stranded in Collecting/Preparing.
     *
     * Timezone comes from `companies.timezone` (G-2). A company with no usable timezone
     * falls back to the application timezone: a backfill must not skip rows, and the
     * scheduler itself fails closed on that same condition (see CompanyTimezoneResolver),
     * so such a wave is simply left alone rather than silently mis-scheduled.
     */
    private function backfill(): void
    {
        $timezones = DB::table('companies')->pluck('timezone', 'id');
        $appTimezone = (string) config('app.timezone', 'UTC');

        DB::table('preparation_waves')
            ->whereNull('ends_at')
            ->orderBy('id')
            ->chunkById(500, function ($waves) use ($timezones, $appTimezone): void {
                foreach ($waves as $wave) {
                    $timezone = $this->usableTimezone(
                        $timezones[$wave->company_id] ?? null,
                        $appTimezone,
                    );

                    $day = CarbonImmutable::parse((string) $wave->planning_date, $timezone)
                        ->startOfDay();

                    DB::table('preparation_waves')
                        ->where('id', $wave->id)
                        ->update([
                            'starts_at' => $day->utc(),
                            'intake_closes_at' => $day->endOfDay()->utc(),
                            'ends_at' => $day->endOfDay()->utc(),
                        ]);
                }
            });
    }

    private function usableTimezone(?string $candidate, string $fallback): string
    {
        if ($candidate === null || $candidate === '') {
            return $fallback;
        }

        try {
            new DateTimeZone($candidate);
        } catch (Throwable) {
            return $fallback;
        }

        return $candidate;
    }
};
