<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VP-1 / D2 — gives a Driver a tenant and a stable cross-module identity.
 *
 * Two columns, both additive, both nullable, mirroring the shape
 * `logistics_vehicles` already carries (2026_07_25_100000):
 *
 *   • company_id — the APPROVED tenant boundary. A driver belongs to exactly one
 *     company, may work across that company's warehouses, and may never be
 *     assigned across companies. Before this column the two candidate paths were
 *     both unusable: `logistics_shipping_companies` has no company_id at all and
 *     reaches the tenant only through a MANY-TO-MANY mapping table, so it can
 *     never name a single owner; and `user_id` is nullable on master-data rows
 *     with no login. Neither was read by any code, so a driver was tenant-scoped
 *     by nothing.
 *
 *   • uuid — the cross-module reference. Operations/Loading types its
 *     driver_id as char(36) and validates it as a uuid, while the identity here
 *     is bigint. This is the value that makes that contract satisfiable, exactly
 *     as logistics_vehicles.uuid already does for vehicles. The bigint primary
 *     key and every foreign key that points at it are untouched.
 *
 * NOT NULL is deliberately NOT used on company_id. The Vehicle precedent treats
 * a null owner as the shared/unowned fleet that console, seeder and queue rows
 * are born into, and the Driver scope added alongside this migration reads it
 * the same way. Making it NOT NULL here would break those flows and would differ
 * from the sibling table for no gain.
 *
 * Data safety: `logistics_drivers` was verified EMPTY (0 rows, and the table has
 * no deleted_at and the model has no SoftDeletes, so there is no hidden set
 * either). No backfill is performed and no historical driver is silently
 * assigned to a company — if rows ever exist, they stay null-owned and visible
 * only to the shared-fleet path until an operator assigns them deliberately.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('logistics_drivers')) {
            return;
        }

        Schema::table('logistics_drivers', function (Blueprint $table): void {
            if (! Schema::hasColumn('logistics_drivers', 'uuid')) {
                $table->uuid('uuid')->nullable()->after('id');
            }

            if (! Schema::hasColumn('logistics_drivers', 'company_id')) {
                $table->uuid('company_id')->nullable()->after('uuid');
            }
        });

        // Indexes are added in a second pass so the columns are guaranteed to
        // exist first — the same two-step the sibling fleet migrations use.
        Schema::table('logistics_drivers', function (Blueprint $table): void {
            if (! $this->hasIndex('logistics_drivers', 'logistics_drivers_uuid_unique')) {
                $table->unique('uuid', 'logistics_drivers_uuid_unique');
            }

            if (! $this->hasIndex('logistics_drivers', 'logistics_drivers_company_idx')) {
                $table->index('company_id', 'logistics_drivers_company_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('logistics_drivers')) {
            return;
        }

        Schema::table('logistics_drivers', function (Blueprint $table): void {
            if ($this->hasIndex('logistics_drivers', 'logistics_drivers_uuid_unique')) {
                $table->dropUnique('logistics_drivers_uuid_unique');
            }

            if ($this->hasIndex('logistics_drivers', 'logistics_drivers_company_idx')) {
                $table->dropIndex('logistics_drivers_company_idx');
            }
        });

        Schema::table('logistics_drivers', function (Blueprint $table): void {
            foreach (['company_id', 'uuid'] as $column) {
                if (Schema::hasColumn('logistics_drivers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * MySQL-safe index probe. Schema::hasIndex is not available on this Laravel
     * line, and re-adding an existing index is a hard error rather than a no-op.
     */
    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $i): bool => $i['name'] === $index);
    }
};
