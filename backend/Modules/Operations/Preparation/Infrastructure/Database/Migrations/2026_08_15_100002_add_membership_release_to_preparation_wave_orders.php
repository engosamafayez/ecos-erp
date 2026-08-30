<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-PREPARATION-WAVE-CROSS-DAY-TRANSITION-002 — historical vs ACTIVE membership.
 *
 * `uq_prep_wave_orders_company_order UNIQUE (company_id, order_id)`
 * (2026_07_06_120200) enforces "one order belongs to one wave FOREVER". Carry-over needs
 * a second membership row while the first is retained as history (PART 14), so that
 * constraint has to go — but the invariant behind it must NOT be weakened (PART 16):
 * an order still may not be ACTIVE in two waves at once, or two waves would each count
 * its demand against the same warehouse stock.
 *
 * The invariant is therefore re-expressed, not removed:
 *
 *   released_at IS NULL   →  active membership   (at most ONE per company+order)
 *   released_at IS NOT NULL → historical         (unlimited)
 *
 * enforced by a STORED generated column that is 1 while active and NULL once released,
 * carried as the third term of the unique key. Both MySQL and PostgreSQL treat NULLs in
 * a unique index as distinct, so released rows never collide with each other while two
 * active rows always do. One shape, both engines — deliberately NOT the
 * partial-index-on-pgsql / drop-it-on-mysql split used by
 * `2026_07_20_100000_fix_inventory_items_soft_delete_unique.php`, whose live consequence
 * is that `inventory_items` currently carries no unique at all on MySQL.
 *
 * `uq_preparation_wave_orders_wave_order (preparation_wave_id, order_id)` is UNTOUCHED.
 * It is what makes same-wave attachment idempotent — `WaveMembershipService::attachOrder`
 * catches its violation and returns null — and it is what keeps every wave-scoped demand
 * join free of fan-out. Both properties must survive this change, so it is left exactly
 * as it is.
 *
 * `postponed_at` is deliberately NOT part of the active predicate. Postponement means
 * "this member has left the CURRENT CYCLE" (a demand/participation fact); release means
 * "this membership is over". Keeping them separate preserves REFINEMENT-002's guarantee
 * that a postponed order is not re-attached to the same wave 60 seconds later, and
 * preserves `scopeActive()`'s existing meaning for demand and loading consumers.
 *
 * ADDITIVE. No row is deleted; every existing row reads `released_at IS NULL`, i.e.
 * "active", exactly as it is treated today.
 */
return new class extends Migration
{
    private const TABLE = 'preparation_wave_orders';

    private const OLD_UNIQUE = 'uq_prep_wave_orders_company_order';

    private const NEW_UNIQUE = 'uq_prep_wave_orders_company_order_active';

    private const GENERATED = 'active_membership';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'released_at')) {
            // Positioned after `postponed_at` where that column exists, but NOT dependent
            // on it: the two are independent facts (see the class docblock), and a
            // database that has not yet taken the postponement migration must still be
            // able to take this one. Column order is cosmetic; the dependency would not be.
            $after = Schema::hasColumn(self::TABLE, 'postponed_at') ? 'postponed_at' : 'added_by';

            Schema::table(self::TABLE, function (Blueprint $table) use ($after): void {
                $table->timestamp('released_at')->nullable()->after($after);
                $table->index(['preparation_wave_id', 'released_at'], 'idx_pwo_wave_released');
            });
        }

        if (! Schema::hasColumn(self::TABLE, self::GENERATED)) {
            DB::statement(sprintf(
                'ALTER TABLE %s ADD COLUMN %s %s GENERATED ALWAYS AS '
                .'(CASE WHEN released_at IS NULL THEN 1 ELSE NULL END) STORED',
                self::TABLE,
                self::GENERATED,
                in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? 'TINYINT' : 'SMALLINT',
            ));
        }

        // Order matters: the replacement must exist before the old one is dropped, so the
        // invariant is never unenforced — not even inside this migration.
        if (! $this->indexExists(self::NEW_UNIQUE)) {
            DB::statement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s UNIQUE (company_id, order_id, %s)',
                self::TABLE,
                self::NEW_UNIQUE,
                self::GENERATED,
            ));
        }

        if ($this->indexExists(self::OLD_UNIQUE)) {
            $this->dropUnique(self::OLD_UNIQUE);
        }
    }

    /**
     * Reverses cleanly ONLY while no carry-over has happened yet.
     *
     * Restoring `UNIQUE (company_id, order_id)` will fail if any order has accumulated
     * more than one membership row by then. That is correct and deliberate: those rows
     * are the history the old constraint made impossible, and silently deleting them to
     * make a rollback succeed would destroy audit data (PART 14).
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! $this->indexExists(self::OLD_UNIQUE)) {
            DB::statement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s UNIQUE (company_id, order_id)',
                self::TABLE,
                self::OLD_UNIQUE,
            ));
        }

        if ($this->indexExists(self::NEW_UNIQUE)) {
            $this->dropUnique(self::NEW_UNIQUE);
        }

        if (Schema::hasColumn(self::TABLE, self::GENERATED)) {
            DB::statement('ALTER TABLE '.self::TABLE.' DROP COLUMN '.self::GENERATED);
        }

        if (Schema::hasColumn(self::TABLE, 'released_at')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex('idx_pwo_wave_released');
                $table->dropColumn('released_at');
            });
        }
    }

    /**
     * `2026_07_06_120200_add_order_exclusivity_constraint.php:23-27` used
     * `ALTER TABLE … DROP INDEX` unconditionally — MySQL-only syntax that fails on
     * PostgreSQL, which is the production engine. Branched here so it does not.
     */
    private function dropUnique(string $name): void
    {
        DB::statement(match (DB::getDriverName()) {
            'mysql', 'mariadb' => 'ALTER TABLE '.self::TABLE.' DROP INDEX '.$name,
            default => 'ALTER TABLE '.self::TABLE.' DROP CONSTRAINT '.$name,
        });
    }

    private function indexExists(string $name): bool
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', self::TABLE)
                ->where('index_name', $name)
                ->exists();
        }

        if (DB::getDriverName() === 'pgsql') {
            return DB::selectOne(
                'SELECT 1 AS present FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [self::TABLE, $name],
            ) !== null;
        }

        return false;
    }
};
