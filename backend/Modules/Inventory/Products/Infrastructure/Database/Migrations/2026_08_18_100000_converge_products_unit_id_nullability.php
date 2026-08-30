<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * D-5 — converge `products.unit_id` nullability across environments.
 *
 * WHAT WENT WRONG
 * ---------------
 * `2026_07_03_000001_make_unit_id_nullable_in_products` carries an INVERTED guard:
 *
 *     if (Schema::hasColumn('products', 'unit_id')) {
 *         return;                                   // returns when the column EXISTS
 *     }
 *     $table->foreignUuid('unit_id')->nullable()->change();   // unreachable
 *
 * `unit_id` has existed since `2026_06_23_110000_create_products_table`, so `up()` always
 * returns early and its change never runs. `down()` is inverted the same way. The result is
 * that environments disagree about a column the business contract has an opinion on:
 *
 *     ecos_dev       NULLABLE     6 / 18 products with NULL unit_id
 *     ecos_dev_test  NOT NULL     (built fresh from migrations — the original constraint stands)
 *     ecos_erp       NULLABLE     1 / 3  products with NULL unit_id
 *
 * That drift is why the test suite cannot reproduce production behaviour for this column.
 *
 * WHAT THIS MIGRATION DOES — AND DELIBERATELY DOES NOT DO
 * ------------------------------------------------------
 * The approved business contract is EVERY PRODUCT MUST HAVE A UNIT, so the target state is
 * `NOT NULL`. This migration moves an environment to that state ONLY when it can do so without
 * inventing data:
 *
 *   - No NULL rows  → apply NOT NULL. The environment now matches the contract, and a fresh
 *                     install (where the original NOT NULL already stands) is unaffected.
 *   - NULL rows     → LEAVE THE COLUMN AS IT IS and log. The rows are NOT backfilled, because
 *                     no deterministic source exists for their correct unit and every inference
 *                     route (name, type, channel, first-unit, most-common) is prohibited.
 *
 * It never writes a Unit, never deletes a product, and never fails an environment that holds
 * legacy data. Enforcement for those environments lives in the application layer
 * (StoreProductRequest / UpdateProductRequest), which already blocks new violations; the DB
 * constraint follows once the legacy rows are remediated by an owner decision.
 *
 * Re-running is safe: the state is derived from the live schema and data, not from a flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'unit_id')) {
            return;
        }

        if ($this->isNotNullAlready()) {
            return;
        }

        $nullCount = (int) DB::table('products')->whereNull('unit_id')->count();

        if ($nullCount > 0) {
            // PENDING LEGACY DATA REMEDIATION. Forcing NOT NULL here would either fail the
            // migration or require inventing a unit for real products — both unacceptable.
            Log::warning(
                '[D-5] products.unit_id left NULLABLE: legacy rows without a unit are present. '
                .'Application-level enforcement is active; the NOT NULL constraint is pending '
                .'legacy data remediation.',
                ['products_with_null_unit_id' => $nullCount],
            );

            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignUuid('unit_id')->nullable(false)->change();
        });
    }

    /**
     * Deliberately a no-op.
     *
     * Relaxing the column back to NULLABLE would re-open the exact drift this migration exists
     * to close, and nothing in the contract asks for it. Environments that were already NULLABLE
     * are untouched by `up()`, so there is no state to restore.
     */
    public function down(): void
    {
        // no-op by design
    }

    private function isNotNullAlready(): bool
    {
        // The column is aliased and the row cast to an array before reading: MySQL returns
        // information_schema column names in different cases depending on server
        // configuration, so `$row->is_nullable` is not safe to rely on.
        $row = DB::selectOne(
            'SELECT is_nullable AS nullable_flag FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['products', 'unit_id'],
        );

        if ($row === null) {
            return false;
        }

        $values = array_change_key_case((array) $row, CASE_LOWER);

        return strtoupper((string) ($values['nullable_flag'] ?? '')) === 'NO';
    }
};
