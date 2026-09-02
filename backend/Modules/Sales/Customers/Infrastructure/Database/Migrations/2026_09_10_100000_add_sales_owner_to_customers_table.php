<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-OPERATIONAL-READ-MODEL-007.
 *
 * Adds the CRM Sales Owner reference. Denormalized id+name pair, mirroring the
 * established `created_by_id`/`created_by_name` convention already used on
 * `orders` (2026_07_14_100002_add_internal_notes_and_creator_to_orders.php) —
 * no live join needed to render it, and no hard FK, mirroring the identical
 * `owner_id` precedent on `crm_leads`/`crm_opportunities` (bare
 * unsignedBigInteger referencing IAM `users.id` by convention only).
 *
 * Assignment (the write path) is explicitly out of this task's scope — every
 * customer is simply unassigned until a future task adds the assignment
 * action. This migration only makes the field exist and be readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('customers', 'sales_owner_id')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->unsignedBigInteger('sales_owner_id')->nullable()->after('company_id');
            $table->string('sales_owner_name')->nullable()->after('sales_owner_id');

            $table->index('sales_owner_id', 'idx_customers_sales_owner');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('customers', 'sales_owner_id')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex('idx_customers_sales_owner');
            $table->dropColumn(['sales_owner_id', 'sales_owner_name']);
        });
    }
};
