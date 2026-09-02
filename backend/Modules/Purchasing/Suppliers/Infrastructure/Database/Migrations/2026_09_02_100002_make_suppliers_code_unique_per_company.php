<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIERS-BATCH-01-MASTER-DATA-002.
 *
 * `suppliers.code` was globally unique (`create_suppliers_table`), added before
 * `company_id` existed. The new SupplierCodeGeneratorService counts per company
 * (mirroring WarehouseCodeGeneratorService) — under a global constraint, two
 * companies could both compute `SUP-000001` and collide. `warehouses` already
 * uses the correct composite form: `unique(['company_id', 'code'])`. This
 * migration brings `suppliers` in line with that existing, working pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        $indexes = Schema::getIndexes('suppliers');
        $hasGlobalUnique = collect($indexes)->contains(fn (array $i) => $i['name'] === 'suppliers_code_unique');
        $hasCompositeUnique = collect($indexes)->contains(fn (array $i) => $i['name'] === 'suppliers_company_id_code_unique');

        if ($hasCompositeUnique) {
            return;
        }

        Schema::table('suppliers', function (Blueprint $table) use ($hasGlobalUnique): void {
            if ($hasGlobalUnique) {
                $table->dropUnique('suppliers_code_unique');
            }

            $table->unique(['company_id', 'code']);
        });
    }

    public function down(): void
    {
        $indexes = Schema::getIndexes('suppliers');
        $hasCompositeUnique = collect($indexes)->contains(fn (array $i) => $i['name'] === 'suppliers_company_id_code_unique');

        if (! $hasCompositeUnique) {
            return;
        }

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropUnique('suppliers_company_id_code_unique');
            $table->unique('code');
        });
    }
};
