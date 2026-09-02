<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIERS-BATCH-01-SUPPLY-CAPABILITIES-003.
 *
 * "This Supplier can supply this Raw Material" — a capability DECLARATION,
 * not purchase history (that already exists via GetSupplierProductDemandQuery
 * / PurchaseOrderLine / PurchaseMaterialLine and is untouched by this table).
 *
 * References the canonical `products` table directly (Raw Material has no
 * separate table — it's a Product row with product_type='raw_material';
 * enforced at the application-validation layer, not by a schema constraint,
 * since the column simply points at Product like any other relation). No
 * duplicate catalog data, no company_id on the pivot itself — tenancy is
 * inherited from the parent Supplier/Product rows' own global scopes,
 * matching this repository's existing pivot precedent (role_permissions /
 * crm_customer_tag_assignments neither store their own company_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_products')) {
            return;
        }

        Schema::create('supplier_products', function (Blueprint $table): void {
            // Auto-increment, not uuid — a plain belongsToMany attach()/detach()
            // never routes through an Eloquent model's `creating` event, so
            // there is nothing to generate a uuid PK; matches the simpler
            // existing precedent (crm_customer_tag_assignments), no custom
            // Pivot class needed.
            $table->id();
            $table->foreignUuid('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            // Who declared this capability — the audit trail this table needs (§18);
            // no second audit subsystem.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['supplier_id', 'product_id']);
            // Reverse lookup: "which Suppliers can provide this Raw Material" (§12).
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_products');
    }
};
