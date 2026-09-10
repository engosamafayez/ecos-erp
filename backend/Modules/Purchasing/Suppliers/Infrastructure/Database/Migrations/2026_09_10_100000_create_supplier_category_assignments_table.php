<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIER-MASTER-AND-RETURNS-FINAL-018 §A.1.
 *
 * A Supplier may belong to more than one Supplier Category. This is the canonical
 * many-to-many association between `suppliers` and `supplier_categories` — deliberately
 * a NEW pivot table, not a change to the existing `supplier_category_id` column/FK.
 *
 * `suppliers.supplier_category_id` (Task 2, TASK-...-MASTER-DATA-002) is left completely
 * untouched: no data loss, no column drop, no down-migration destructive step. It becomes
 * a derived "primary category" mirror — CreateSupplierAction/UpdateSupplierAction keep it
 * in sync as `supplier_category_ids[0] ?? null` on every write from here on — so every
 * piece of existing code that still reads the singular FK or `supplierCategory()` relation
 * (list filters, `supplier_category_name` display, the FK's own `restrictOnDelete()`)
 * keeps working unchanged.
 *
 * Named distinctly from `supplier_categories` (the lookup table itself) and from
 * `supplier_product_categories` (a different pivot entirely — Supplier-to-shared-Category
 * "what this Supplier can supply", see that migration's own docblock). No duplicate
 * category model is introduced; this table only associates existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_category_assignments')) {
            return;
        }

        Schema::create('supplier_category_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignUuid('supplier_category_id')->constrained('supplier_categories')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['supplier_id', 'supplier_category_id']);
            $table->index('supplier_category_id');
        });

        // Backfill — preserve every existing single-category association exactly as it
        // stands today, so no historical data is lost by introducing the pivot.
        $now = now();
        DB::table('suppliers')
            ->whereNotNull('supplier_category_id')
            ->orderBy('id')
            ->select('id', 'supplier_category_id')
            ->chunkById(500, function ($rows) use ($now): void {
                $inserts = $rows->map(fn ($row) => [
                    'supplier_id' => $row->id,
                    'supplier_category_id' => $row->supplier_category_id,
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($inserts !== []) {
                    DB::table('supplier_category_assignments')->insertOrIgnore($inserts);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_category_assignments');
    }
};
