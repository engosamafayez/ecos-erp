<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-WAREHOUSE-COVERAGE-BRAND-ASSIGNMENT-001 — Warehouse → Brand coverage.
 *
 * The approved owner of brand coverage is the WAREHOUSE, not the Branch: the
 * warehouse is the final fulfilment authority — it is what physically holds the
 * stock a brand's order will be picked from.
 *
 * Geographic coverage is deliberately NOT duplicated here. Governorate and zone
 * coverage already exist on `branch_coverage_areas` (master_governorate_id +
 * nullable master_zone_id, most-specific-wins) and are reused unchanged. This
 * table adds only the one fact the model was missing.
 *
 * SEMANTICS — mandatory, and the reason this table has no seed:
 *
 *   NO ROWS FOR A WAREHOUSE  =  THAT WAREHOUSE SERVES NO BRANDS.
 *
 * An empty configuration is never read as "serves all brands". Serving every
 * brand must be configured explicitly, row by row. This is fail-closed, matching
 * ADR-027 §16.4's treatment of an underivable company: absence of permission is
 * never permission.
 *
 * company_id is carried denormalised for tenant integrity, matching the
 * convention used across the platform (warehouses.company_id, brands.company_id).
 * A row is only ever valid when all three agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('warehouse_brand_coverage')) {
            return;
        }

        Schema::create('warehouse_brand_coverage', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUuid('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignUuid('brand_id')->constrained('brands')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One relationship per (warehouse, brand). A duplicate is rejected by
            // the database, not merely by application code.
            $table->unique(['warehouse_id', 'brand_id'], 'uq_wbc_warehouse_brand');

            // Eligibility reads: "which brands does this warehouse serve?"
            $table->index(['warehouse_id', 'is_active'], 'idx_wbc_warehouse_active');
            // Reverse reads and tenant-scoped listings.
            $table->index(['brand_id', 'is_active'], 'idx_wbc_brand_active');
            $table->index(['company_id', 'is_active'], 'idx_wbc_company_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_brand_coverage');
    }
};
