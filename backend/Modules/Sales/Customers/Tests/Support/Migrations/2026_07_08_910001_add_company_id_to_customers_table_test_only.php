<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-CUSTOMER-CODE-VERIFICATION-007-R2.
 *
 * TEST-HARNESS ONLY — never applied to any real database, not part of the
 * application's migration set, and never referenced from `--path` outside this
 * test suite's own migrateFreshUsing() override (see CustomerCodeSequenceTest).
 *
 * Replicates ONLY the schema effect of the real
 * `2026_07_08_910001_add_company_id_to_customers_table.php` (adds the
 * `company_id` column/index/FK) — deliberately omitting that migration's
 * historical-data backfill UPDATE, which reads `orders.company_id` and would
 * otherwise pull the entire Orders module's migration dependency chain into
 * this test suite's isolated schema for no reason: this test suite runs
 * against a table with zero pre-existing rows, so the real migration's
 * backfill would be a no-op here regardless. Skipping it changes nothing this
 * test suite depends on or asserts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('customers', 'company_id')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignUuid('company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->restrictOnDelete();

            $table->index('company_id', 'idx_customers_company');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
            $table->dropIndex('idx_customers_company');
            $table->dropColumn('company_id');
        });
    }
};
