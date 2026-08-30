<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aligns Operations\Loading to the approved ECOS capacity contract.
 *
 * THE CONTRACT
 * ------------
 * Capacity in ECOS is an ORDER COUNT and nothing else. Weight, volume,
 * refrigeration and product dimensions are not business constraints in this
 * platform: there is no rule that reads them, no engine that computes them and
 * no approved model that defines them.
 *
 * WHAT THIS MIGRATION DOES — AND DELIBERATELY DOES NOT DO
 * ------------------------------------------------------
 * It REMOVES a requirement. It does not add a concept.
 *
 * `vehicle_assignments.capacity_weight_kg_snapshot` and
 * `capacity_volume_m3_snapshot` are `decimal(18,4) NOT NULL with no default`.
 * That NOT NULL is the entire reason a Group could not reach Loading: creating
 * a vehicle assignment demanded two numbers the canonical fleet registry does
 * not carry (`logistics_vehicles.capacity_weight_kg` / `capacity_volume_m3` are
 * both NULLABLE and unset), and a third — refrigeration — that has no source
 * column anywhere in the fleet.
 *
 * Making them NULLABLE is the honest fix. The alternative — writing 0 — would
 * be worse than leaving the block in place: 0 is a real decimal that reads as
 * "this vehicle carries nothing", and the moment anything consumed it the
 * platform would silently refuse every load. An absent value says "not
 * measured"; a zero says "measured, and it is nothing". Only one of those is
 * true.
 *
 * The columns are kept rather than dropped. They hold no rows today, they are
 * referenced by a service and a resource that still compile, and dropping them
 * would be a destructive change to a certified table for no operational gain.
 * They simply stop being REQUIRED — existing technically is not the same as
 * being a business rule.
 *
 * TRIP LINKAGE
 * ------------
 * `trip_id` is added as the minimum link that lets Loading reach its Group
 * through the canonical execution chain:
 *
 *     Group → distribution_trips.virtual_slot_id → trip_id → vehicle_assignments
 *
 * Nullable, because a vehicle assignment created outside the Group flow (the
 * pre-existing standalone Loading path) has no Trip and must keep working. No
 * foreign key: this crosses a module boundary, where FOREIGN-KEY-STANDARDS.md
 * §1 requires a plain reference without a DB-level constraint.
 *
 * IDEMPOTENCY
 * -----------
 * A unique index on (vehicle_assignment_id, product_id) makes a Loading Task
 * one-per-product-per-vehicle by CONSTRUCTION. `LoadProductAction` currently
 * calls `LoadingTask::create()` unconditionally, so a retry — or two operators
 * — produce two tasks for the same product and the loaded quantity doubles.
 * The index is what makes the accompanying absolute-set write safe rather than
 * merely well-behaved.
 *
 * DATA SAFETY
 * -----------
 * `vehicle_assignments` and `loading_tasks` were both verified EMPTY before
 * this migration was written. Nothing is converted, nothing is back-filled and
 * no operational or financial history is touched. Loosening NOT NULL cannot
 * invalidate an existing row in any case.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vehicle_assignments')) {
            // Raw DDL rather than Blueprint->change(): doctrine/dbal is not a
            // dependency here, and MODIFY states the resulting column exactly
            // rather than asking a differ to infer it.
            DB::statement(
                'ALTER TABLE `vehicle_assignments`
                 MODIFY `capacity_weight_kg_snapshot` DECIMAL(18,4) NULL,
                 MODIFY `capacity_volume_m3_snapshot` DECIMAL(18,4) NULL',
            );

            Schema::table('vehicle_assignments', function (Blueprint $table): void {
                if (! Schema::hasColumn('vehicle_assignments', 'trip_id')) {
                    $table->unsignedBigInteger('trip_id')->nullable()->after('loading_session_id');
                }
            });

            Schema::table('vehicle_assignments', function (Blueprint $table): void {
                if (! $this->hasIndex('vehicle_assignments', 'vehicle_assignments_trip_idx')) {
                    $table->index('trip_id', 'vehicle_assignments_trip_idx');
                }
            });
        }

        if (Schema::hasTable('loading_tasks')) {
            Schema::table('loading_tasks', function (Blueprint $table): void {
                if (! $this->hasIndex('loading_tasks', 'loading_tasks_assignment_product_unique')) {
                    $table->unique(
                        ['vehicle_assignment_id', 'product_id'],
                        'loading_tasks_assignment_product_unique',
                    );
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('loading_tasks')
            && $this->hasIndex('loading_tasks', 'loading_tasks_assignment_product_unique')) {
            Schema::table('loading_tasks', function (Blueprint $table): void {
                $table->dropUnique('loading_tasks_assignment_product_unique');
            });
        }

        if (! Schema::hasTable('vehicle_assignments')) {
            return;
        }

        if ($this->hasIndex('vehicle_assignments', 'vehicle_assignments_trip_idx')) {
            Schema::table('vehicle_assignments', function (Blueprint $table): void {
                $table->dropIndex('vehicle_assignments_trip_idx');
            });
        }

        Schema::table('vehicle_assignments', function (Blueprint $table): void {
            if (Schema::hasColumn('vehicle_assignments', 'trip_id')) {
                $table->dropColumn('trip_id');
            }
        });

        // Restoring NOT NULL would fail against any row holding a null, which is
        // exactly the state this migration makes legal. Rows are defaulted to 0
        // ONLY on the down path, where the alternative is a migration that
        // cannot roll back at all.
        DB::statement('UPDATE `vehicle_assignments` SET `capacity_weight_kg_snapshot` = 0 WHERE `capacity_weight_kg_snapshot` IS NULL');
        DB::statement('UPDATE `vehicle_assignments` SET `capacity_volume_m3_snapshot` = 0 WHERE `capacity_volume_m3_snapshot` IS NULL');

        DB::statement(
            'ALTER TABLE `vehicle_assignments`
             MODIFY `capacity_weight_kg_snapshot` DECIMAL(18,4) NOT NULL,
             MODIFY `capacity_volume_m3_snapshot` DECIMAL(18,4) NOT NULL',
        );
    }

    /** Schema::hasIndex is unavailable on this Laravel line; re-adding an index is a hard error. */
    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $i): bool => $i['name'] === $index);
    }
};
