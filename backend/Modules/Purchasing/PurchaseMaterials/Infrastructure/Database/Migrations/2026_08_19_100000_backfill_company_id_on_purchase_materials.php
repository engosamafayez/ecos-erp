<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-PROCUREMENT-MANUAL-REMEDIATION-001.
 *
 * PurchaseMaterial gained a fail-closed tenant global scope. Rows written before
 * this task took `company_id` straight from the (nullable) client payload, so a
 * request could persist with a NULL company and would now be invisible to the
 * company-restricted user who raised it. Backfill the owning company from the
 * initiating warehouse — the same authority the create path now uses — so the
 * new scope hides nothing that was legitimately created. Purely additive; a row
 * whose warehouse has no company is left NULL (correctly closed, not leaked).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('purchase_materials', 'company_id')) {
            return;
        }

        DB::statement(<<<'SQL'
            UPDATE purchase_materials pm
            INNER JOIN warehouses w ON pm.warehouse_id = w.id
            SET pm.company_id = w.company_id
            WHERE pm.company_id IS NULL
              AND w.company_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        // Data backfill only — nothing to reverse.
    }
};
