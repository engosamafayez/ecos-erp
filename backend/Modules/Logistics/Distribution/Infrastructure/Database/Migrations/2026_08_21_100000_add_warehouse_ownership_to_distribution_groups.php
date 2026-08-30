<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-OPERATIONS-DISTRIBUTOR-ORDERS-PART-5B — Distribution Group warehouse ownership.
 *
 * ┌─ THE DEFECT THIS CLOSES ─────────────────────────────────────────────────┐
 * │ A Distribution Group (a Virtual Capacity Slot) hangs off a COMPANY-level  │
 * │ Window and carried no warehouse. Two warehouses therefore shared one set  │
 * │ of Groups, and because `dist_slot_zones_window_zone_unique` is keyed on   │
 * │ (window, zone), the second warehouse's planner assigning a Zone SILENTLY  │
 * │ MOVED it out of the first warehouse's Group — no error, no trace, and the │
 * │ first Group's totals simply dropped.                                      │
 * │                                                                           │
 * │ Filtering the reads (Part 5A) hid the symptom. It could not fix the       │
 * │ ownership, because the ownership did not exist.                           │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * TWO COLUMNS, AND THE SECOND IS NOT DUPLICATION.
 *
 *   • `distribution_virtual_slots.warehouse_id` is the OWNERSHIP.
 *   • `distribution_slot_zones.warehouse_id` is DENORMALISED so the uniqueness
 *     rule can be stated in the database at all. MySQL cannot express a unique
 *     index that reaches through `virtual_slot_id` into the slot's warehouse.
 *     This is the same reason `distribution_window_id` was already denormalised
 *     onto this table by the original migration, which said so explicitly:
 *     "purely so that uniqueness can be expressed at the database level.
 *     Without it the constraint would have to be enforced in application code,
 *     which is exactly where this class of rule goes wrong under concurrency."
 *
 * THE UNIQUENESS RULE CHANGES SHAPE, NOT INTENT:
 *
 *   before   (window, zone)               one Zone -> one Group per WINDOW
 *   after    (window, warehouse, zone)    one Zone -> one Group per WINDOW AND WAREHOUSE
 *
 * The old rule made a legitimate operation impossible: two warehouses both
 * delivering into Maadi could not each plan Maadi. The new one still forbids the
 * thing that must stay forbidden — one Zone in two Groups for the same warehouse.
 *
 * NO FOREIGN KEYS, MATCHING THIS MODULE. Neither table carries an FK today
 * (`company_id` and `distribution_window_id` are plain uuids with indexes), so
 * adding one here would be a new convention rather than a followed one.
 *
 * BACKFILL IS DERIVED, NEVER GUESSED — see up().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('distribution_virtual_slots', 'warehouse_id')) {
            Schema::table('distribution_virtual_slots', function (Blueprint $table): void {
                // Nullable first: the column has to exist before it can be filled.
                $table->uuid('warehouse_id')->nullable()->after('distribution_window_id');
                $table->index(
                    ['company_id', 'distribution_window_id', 'warehouse_id'],
                    'dist_slots_company_window_wh_idx',
                );
            });
        }

        if (! Schema::hasColumn('distribution_slot_zones', 'warehouse_id')) {
            Schema::table('distribution_slot_zones', function (Blueprint $table): void {
                $table->uuid('warehouse_id')->nullable()->after('distribution_window_id');
            });
        }

        $this->backfillSlotWarehouses();
        $this->backfillSlotZoneWarehouses();
        $this->assertEveryGroupIsOwned();
        $this->enforceNotNull();
        $this->replaceZoneUniqueness();
    }

    /**
     * Derive each Group's warehouse from the Orders it actually holds.
     *
     * THE RULE, stated before it is applied: a Group's warehouse is the SINGLE
     * distinct `orders.assigned_warehouse_id` among the assignments that point at
     * it. One distinct value is an answer; zero or several is not, and neither is
     * filled in. `assigned_warehouse_id` is the same column Preparation's own
     * collector uses, so this derives ownership from the operational truth rather
     * than from anything about the Group itself.
     */
    private function backfillSlotWarehouses(): void
    {
        $slots = DB::table('distribution_virtual_slots')
            ->whereNull('warehouse_id')
            ->select('id')
            ->get();

        foreach ($slots as $slot) {
            $warehouses = DB::table('distribution_window_orders as dwo')
                ->join('orders as o', 'o.id', '=', 'dwo.order_id')
                ->where('dwo.virtual_slot_id', $slot->id)
                ->whereNotNull('o.assigned_warehouse_id')
                ->distinct()
                ->pluck('o.assigned_warehouse_id');

            // Exactly one, or nothing is written. A Group spanning two warehouses is
            // precisely the corruption this migration exists to make impossible, and
            // picking one of them would bless it.
            if ($warehouses->count() !== 1) {
                continue;
            }

            DB::table('distribution_virtual_slots')
                ->where('id', $slot->id)
                ->update(['warehouse_id' => $warehouses->first()]);
        }
    }

    /** A Zone link inherits its Group's warehouse — the Group is the owner. */
    private function backfillSlotZoneWarehouses(): void
    {
        DB::statement('
            UPDATE distribution_slot_zones sz
            JOIN distribution_virtual_slots s ON s.id = sz.virtual_slot_id
            SET sz.warehouse_id = s.warehouse_id
            WHERE sz.warehouse_id IS NULL
              AND s.warehouse_id IS NOT NULL
        ');
    }

    /**
     * Refuse to continue on data this migration cannot own honestly.
     *
     * A Group with no orders, or with orders from several warehouses, has no
     * derivable owner. Forcing NOT NULL would then either fail with an opaque
     * database error or — worse — invite a guess. Failing here names the rows and
     * stops, which is the only outcome that does not manufacture ownership.
     */
    private function assertEveryGroupIsOwned(): void
    {
        $orphans = DB::table('distribution_virtual_slots')
            ->whereNull('warehouse_id')
            ->pluck('code');

        if ($orphans->isNotEmpty()) {
            throw new RuntimeException(
                'Distribution Group warehouse ownership cannot be derived for '
                .$orphans->count().' group(s): '.$orphans->implode(', ')
                .'. Each must resolve to exactly one orders.assigned_warehouse_id. '
                .'Resolve these groups before migrating; ownership must not be guessed.',
            );
        }
    }

    private function enforceNotNull(): void
    {
        // Schema Builder cannot alter a column to NOT NULL without doctrine/dbal on
        // this stack, so the change is stated directly. MySQL-compatible, and it
        // preserves the column's type and position.
        DB::statement('ALTER TABLE distribution_virtual_slots MODIFY warehouse_id CHAR(36) NOT NULL');
        DB::statement('ALTER TABLE distribution_slot_zones MODIFY warehouse_id CHAR(36) NOT NULL');
    }

    private function replaceZoneUniqueness(): void
    {
        $exists = static fn (string $name): bool => collect(DB::select(
            'SHOW INDEX FROM distribution_slot_zones WHERE Key_name = ?',
            [$name],
        ))->isNotEmpty();

        if ($exists('dist_slot_zones_window_zone_unique')) {
            Schema::table('distribution_slot_zones', function (Blueprint $table): void {
                $table->dropUnique('dist_slot_zones_window_zone_unique');
            });
        }

        if (! $exists('dist_slot_zones_window_wh_zone_unique')) {
            Schema::table('distribution_slot_zones', function (Blueprint $table): void {
                $table->unique(
                    ['distribution_window_id', 'warehouse_id', 'distribution_zone_id'],
                    'dist_slot_zones_window_wh_zone_unique',
                );
            });
        }
    }

    /**
     * Reversible: the columns and the new index go, the original constraint returns.
     *
     * Dropping the columns loses only ownership metadata this migration itself
     * derived — no Group, Zone link, Order or quantity is removed.
     */
    public function down(): void
    {
        $exists = static fn (string $name): bool => collect(DB::select(
            'SHOW INDEX FROM distribution_slot_zones WHERE Key_name = ?',
            [$name],
        ))->isNotEmpty();

        if ($exists('dist_slot_zones_window_wh_zone_unique')) {
            Schema::table('distribution_slot_zones', function (Blueprint $table): void {
                $table->dropUnique('dist_slot_zones_window_wh_zone_unique');
            });
        }

        if (Schema::hasColumn('distribution_slot_zones', 'warehouse_id')) {
            Schema::table('distribution_slot_zones', function (Blueprint $table): void {
                $table->dropColumn('warehouse_id');
            });
        }

        if (! $exists('dist_slot_zones_window_zone_unique')) {
            Schema::table('distribution_slot_zones', function (Blueprint $table): void {
                $table->unique(
                    ['distribution_window_id', 'distribution_zone_id'],
                    'dist_slot_zones_window_zone_unique',
                );
            });
        }

        if (Schema::hasColumn('distribution_virtual_slots', 'warehouse_id')) {
            Schema::table('distribution_virtual_slots', function (Blueprint $table): void {
                $table->dropIndex('dist_slots_company_window_wh_idx');
                $table->dropColumn('warehouse_id');
            });
        }
    }
};
