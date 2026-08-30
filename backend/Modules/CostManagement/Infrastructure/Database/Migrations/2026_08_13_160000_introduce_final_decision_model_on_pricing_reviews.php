<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-PRICE-REVIEW-FINAL-DECISION-MODEL-001 — Current / Suggested / Manual.
 *
 * The review row now carries three distinct layers instead of one mutable price:
 *
 *   CURRENT    selling_price · current_sale_price      immutable snapshot
 *   SUGGESTED  suggested_selling_price · suggested_sale_price   engine output, may be NULL
 *   MANUAL     manual_regular_price · manual_sale_price         the operator's decision
 *
 * Final decision = manual ?? suggested. A NULL manual column means "not decided",
 * so the suggestion stands — no duplicate copy of the suggested figure is stored.
 *
 * Replaces the boolean override flags added by 2026_08_13_140000. Those recorded
 * *that* a manual decision existed; the manual columns now record *what* it is,
 * which makes the flags redundant. Every consumer of them
 * (model, service, controller, resource, frontend type, tests) is migrated in the
 * same change, and a repo-wide search found no other reader, so they are dropped
 * here rather than left as dead state.
 *
 * `suggested_selling_price` becomes nullable: when the engine cannot produce a
 * recommendation the review must show "—", never a fabricated 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pricing_reviews')) {
            return;
        }

        Schema::table('pricing_reviews', function (Blueprint $table): void {
            if (! Schema::hasColumn('pricing_reviews', 'current_sale_price')) {
                // Snapshot of the sale price as it stood when the review opened.
                // Previously the API read this live from products, so approving a
                // review retroactively changed its own "current" value.
                $table->decimal('current_sale_price', 15, 4)->nullable()->after('selling_price');
            }

            if (! Schema::hasColumn('pricing_reviews', 'manual_regular_price')) {
                $table->decimal('manual_regular_price', 15, 4)->nullable()->after('suggested_sale_price');
            }

            if (! Schema::hasColumn('pricing_reviews', 'manual_sale_price')) {
                $table->decimal('manual_sale_price', 15, 4)->nullable()->after('manual_regular_price');
            }
        });

        // A missing suggestion is a real state, not zero.
        Schema::table('pricing_reviews', function (Blueprint $table): void {
            $table->decimal('suggested_selling_price', 15, 4)->nullable()->change();
        });

        // Backfill the Current Sale snapshot for reviews opened before this model.
        // Correlated subquery form: portable across MySQL, PostgreSQL and SQLite.
        DB::statement(
            'UPDATE pricing_reviews SET current_sale_price = ('.
            'SELECT sale_price FROM products WHERE products.id = pricing_reviews.product_id'.
            ') WHERE current_sale_price IS NULL',
        );

        // Redundant now that manual_* carries the decision itself.
        Schema::table('pricing_reviews', function (Blueprint $table): void {
            foreach (['selling_price_overridden', 'sale_price_overridden'] as $column) {
                if (Schema::hasColumn('pricing_reviews', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pricing_reviews')) {
            return;
        }

        Schema::table('pricing_reviews', function (Blueprint $table): void {
            if (! Schema::hasColumn('pricing_reviews', 'selling_price_overridden')) {
                $table->boolean('selling_price_overridden')->default(false);
            }

            if (! Schema::hasColumn('pricing_reviews', 'sale_price_overridden')) {
                $table->boolean('sale_price_overridden')->default(false);
            }
        });

        // Restore the flags from the manual columns before dropping them, so a
        // rollback does not silently lose the fact that a decision was made.
        DB::statement('UPDATE pricing_reviews SET selling_price_overridden = 1 WHERE manual_regular_price IS NOT NULL');
        DB::statement('UPDATE pricing_reviews SET sale_price_overridden = 1 WHERE manual_sale_price IS NOT NULL');

        DB::statement('UPDATE pricing_reviews SET suggested_selling_price = 0 WHERE suggested_selling_price IS NULL');

        Schema::table('pricing_reviews', function (Blueprint $table): void {
            $table->decimal('suggested_selling_price', 15, 4)->nullable(false)->change();
        });

        Schema::table('pricing_reviews', function (Blueprint $table): void {
            foreach (['current_sale_price', 'manual_regular_price', 'manual_sale_price'] as $column) {
                if (Schema::hasColumn('pricing_reviews', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
