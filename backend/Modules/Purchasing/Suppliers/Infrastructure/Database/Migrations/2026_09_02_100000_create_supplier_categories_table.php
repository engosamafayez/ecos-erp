<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIERS-BATCH-01-MASTER-DATA-002.
 *
 * A small, company-scoped, flat classification for Suppliers — mirrors
 * `finance_tax_categories` (company-scoped, code+name+name_ar+is_active, no
 * hierarchy) rather than widening the shared `categories` table, which is not
 * company-scoped and is validated to product/material scope only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_categories')) {
            return;
        }

        Schema::create('supplier_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_categories');
    }
};
