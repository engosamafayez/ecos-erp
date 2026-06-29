<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills_of_materials', function (Blueprint $table): void {
            // Integer version counter for copy-on-write versioning (RC-10 architecture).
            // Monotonically increasing per product. Used in manufacturing_transactions
            // unique constraint: (order_line_id, bom_id, bom_version_number) WHERE status != 'failed'.
            $table->unsignedInteger('bom_version_number')->default(1)->after('version');

            $table->index(['product_id', 'bom_version_number'], 'idx_bom_product_version');
        });

        // Backfill all existing rows — they were all created before versioning existed, so version 1.
        DB::table('bills_of_materials')->whereNull('deleted_at')->update(['bom_version_number' => 1]);
    }

    public function down(): void
    {
        Schema::table('bills_of_materials', function (Blueprint $table): void {
            $table->dropIndex('idx_bom_product_version');
            $table->dropColumn('bom_version_number');
        });
    }
};
