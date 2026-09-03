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
 * `2026_07_06_000001_add_company_id_to_products_table.php` (adds the
 * nullable `company_id` FK to `companies`) — deliberately omitting that
 * migration's backfill UPDATE, which reads `product_channel_mappings`, a
 * table this suite's minimal schema does not include. This suite's
 * ProductFactory always sets `company_id` directly, so skipping the
 * backfill changes nothing this suite depends on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'company_id')) {
            return;
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignUuid('company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
