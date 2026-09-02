<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIERS-BATCH-01-SUPPLY-CAPABILITIES-003.
 *
 * "This Supplier can supply Product Category/Department X" — references the
 * canonical shared `categories` table (Modules\MasterData\Categories).
 *
 * Deliberately named distinctly from `suppliers.supplier_category_id`
 * (Task 2): that FK classifies the SUPPLIER itself (one category, its own
 * small `supplier_categories` table); THIS table declares which existing
 * product/material Categories a Supplier can supply (many-to-many, the
 * shared `categories` table). Two different concepts, two different tables —
 * never to be confused (per the approved architecture, §21 of Task 3).
 *
 * `categories` is not company-scoped (shared reference data), so no
 * cross-tenant concern on that side — only the Supplier side needs tenant
 * validation, enforced at the application layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_product_categories')) {
            return;
        }

        Schema::create('supplier_product_categories', function (Blueprint $table): void {
            // Auto-increment — see supplier_products for why (no custom Pivot class).
            $table->id();
            $table->foreignUuid('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['supplier_id', 'category_id']);
            // Reverse lookup: "which Suppliers can provide this Category" (§12).
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_product_categories');
    }
};
