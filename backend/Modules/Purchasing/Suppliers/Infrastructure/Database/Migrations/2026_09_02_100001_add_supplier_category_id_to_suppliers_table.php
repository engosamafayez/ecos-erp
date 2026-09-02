<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIERS-BATCH-01-MASTER-DATA-002.
 * Additive, nullable — assigning a category is optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('suppliers', 'supplier_category_id')) {
            return;
        }

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->foreignUuid('supplier_category_id')
                ->nullable()
                ->after('code')
                ->constrained('supplier_categories')
                ->restrictOnDelete();

            $table->index('supplier_category_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('suppliers', 'supplier_category_id')) {
            return;
        }

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropForeign(['supplier_category_id']);
            $table->dropIndex(['supplier_category_id']);
            $table->dropColumn('supplier_category_id');
        });
    }
};
