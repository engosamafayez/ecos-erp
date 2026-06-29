<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RC-10: Add partial unique index on manufacturing_transactions.
 *
 * Guarantees that each order line is manufactured at most once per BOM version,
 * enforced at the database level (PostgreSQL partial index).
 *
 * UNIQUE(order_line_id, bom_id, bom_version_number) WHERE status != 'failed'
 *   AND order_line_id IS NOT NULL
 *
 * Rationale:
 *   - Failed transactions are excluded so a retry can create a new record
 *   - order_line_id IS NOT NULL guard prevents constraint fires for non-order-triggered runs
 *   - Complements the application-level idempotency check in PrepareOrderManufacturingAction
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE UNIQUE INDEX manufacturing_transactions_rc10_order_line_bom_unique
            ON manufacturing_transactions (order_line_id, bom_id, bom_version_number)
            WHERE status != 'failed' AND order_line_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS manufacturing_transactions_rc10_order_line_bom_unique');
    }
};
