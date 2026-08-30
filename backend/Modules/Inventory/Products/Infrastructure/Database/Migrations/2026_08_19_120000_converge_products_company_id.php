<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ORDERS-INVENTORY-MANUAL-REMEDIATION-001 — Decision 2 (full tenant isolation).
 *
 * Converges the RUNTIME `products` schema with the certified contract. A fresh
 * migrate produces `products.company_id` (see 2026_07_06_000003_make_product_
 * company_id_not_null), and ProductFactory already writes it — but the runtime
 * database drifted and lost the column, which is why `Product` has no tenant
 * column to scope on and every by-id read/update/delete is a cross-company IDOR.
 *
 * This migration is IDEMPOTENT: on the canonical/test database (column already
 * present, NOT NULL) the add is skipped and only the guarded back-fill of any
 * remaining NULLs runs; on the drifted runtime it adds a NULLABLE column and
 * back-fills every owner that can be derived DETERMINISTICALLY — never guessed:
 *
 *   1. brand ownership     products.brand_id  → brands.company_id      (finished goods)
 *   2. inventory ownership products.id        → inventory_items.company_id (stocked materials)
 *
 * Rows still NULL afterwards are legacy materials with no brand and no stock, so
 * no deterministic owner exists. They are LEFT NULL on purpose: the Product tenant
 * global scope fail-closes a NULL-company row (invisible) rather than leaking it
 * cross-company. Making the column NOT NULL on runtime is therefore blocked until
 * those rows are attributed (or re-seeded) — reported, not invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'company_id')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->char('company_id', 36)->nullable()->after('id');
            });

            // Separate statement so it is skipped when the column (and its index)
            // already exist on the canonical schema.
            DB::statement('CREATE INDEX products_company_id_index ON products (company_id)');
        }

        // Deterministic back-fill only. Idempotent — touches NULLs exclusively.
        DB::statement(
            'UPDATE products p JOIN brands b ON b.id = p.brand_id '
            .'SET p.company_id = b.company_id '
            .'WHERE p.company_id IS NULL AND b.company_id IS NOT NULL',
        );

        DB::statement(
            'UPDATE products p JOIN inventory_items ii ON ii.product_id = p.id '
            .'SET p.company_id = ii.company_id '
            .'WHERE p.company_id IS NULL AND ii.company_id IS NOT NULL',
        );
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'company_id')) {
            return;
        }

        $hasIndex = DB::selectOne(
            'SELECT 1 AS x FROM information_schema.STATISTICS '
            ."WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' "
            ."AND INDEX_NAME = 'products_company_id_index' LIMIT 1",
        );

        Schema::table('products', function (Blueprint $table) use ($hasIndex): void {
            if ($hasIndex !== null) {
                $table->dropIndex('products_company_id_index');
            }
            $table->dropColumn('company_id');
        });
    }
};
