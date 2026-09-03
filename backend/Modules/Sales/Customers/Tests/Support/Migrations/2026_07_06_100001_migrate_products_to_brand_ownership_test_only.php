<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-CUSTOMER-INTELLIGENCE-008.
 *
 * TEST-HARNESS ONLY — never applied to any real database, not part of the
 * application's migration set, and never referenced from `--path` outside
 * this test suite's own migrateFreshUsing() override.
 *
 * Replicates ONLY the schema effect of the real
 * `2026_07_06_100001_migrate_products_to_brand_ownership.php` (adds the
 * nullable `brand_id` FK to `brands`) — deliberately omitting that
 * migration's two backfill UPDATE statements (one of which reads
 * `product_channel_mappings`, a table this suite's minimal schema does not
 * include) and its `company_id` drop. This suite's ProductFactory always
 * sets `brand_id` directly and never relies on `products.company_id`, so
 * skipping the backfill/drop changes nothing this suite depends on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'brand_id')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignUuid('brand_id')
                ->nullable()
                ->after('id')
                ->constrained('brands')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['brand_id']);
            $table->dropColumn('brand_id');
        });
    }
};
