<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-PREPARATION-WAVE-CROSS-DAY-TRANSITION-002 — unblock cross-midnight cycles.
 *
 * `2026_07_16_100000_create_wave_engine_configurations_table.php:45` installed:
 *
 *   CHECK (preparation_start_time > collection_start_time
 *      AND wave_end_time        > preparation_start_time)
 *
 * That constraint hard-codes "an operational cycle fits inside one calendar day". The
 * approved contract requires 18:00 → 08:00 → 15:00, whose first comparison
 * (08:00 > 18:00) is false — so the cycle the business actually runs cannot be stored.
 * The live configuration had been pushed to 00:00 / 23:59 / 23:59:59, the extreme values
 * that satisfy the constraint while keeping intake open, which is the constraint being
 * worked around in production data.
 *
 * The ordering invariant is NOT abandoned — it moves to where it can express a cycle:
 * WaveScheduleResolver resolves each boundary as the NEXT OCCURRENCE strictly after the
 * previous one, in company-local time. Ordering is therefore guaranteed by construction
 * (starts_at < intake_closes_at < ends_at, always), for same-day and cross-day windows
 * alike, and the resolved instants are persisted on the wave.
 *
 * Dropping a CHECK is reversible: down() restores it verbatim. Note that restoring it
 * will fail if any row holds a cross-day window by then — which is correct, because that
 * is exactly the data the old constraint forbade.
 */
return new class extends Migration
{
    private const CONSTRAINT = 'chk_wave_engine_config_times';

    private const TABLE = 'wave_engine_configurations';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! $this->constraintExists()) {
            return;
        }

        DB::statement(match (DB::getDriverName()) {
            // MySQL 8.0.16+ names the operation DROP CHECK; DROP CONSTRAINT is accepted
            // too, but DROP CHECK is unambiguous and is what the engine reports.
            'mysql', 'mariadb' => 'ALTER TABLE '.self::TABLE.' DROP CHECK '.self::CONSTRAINT,
            default => 'ALTER TABLE '.self::TABLE.' DROP CONSTRAINT '.self::CONSTRAINT,
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->constraintExists()) {
            return;
        }

        DB::statement(
            'ALTER TABLE '.self::TABLE.' ADD CONSTRAINT '.self::CONSTRAINT
            .' CHECK (preparation_start_time > collection_start_time'
            .' AND wave_end_time > preparation_start_time)',
        );
    }

    private function constraintExists(): bool
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return DB::table('information_schema.table_constraints')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', self::TABLE)
                ->where('constraint_name', self::CONSTRAINT)
                ->exists();
        }

        if (DB::getDriverName() === 'pgsql') {
            return DB::selectOne(
                'SELECT 1 AS present FROM pg_constraint WHERE conname = ?',
                [self::CONSTRAINT],
            ) !== null;
        }

        // sqlite and friends never received the constraint (it was added by raw
        // DB::statement in the creating migration, which those drivers skip).
        return false;
    }
};
