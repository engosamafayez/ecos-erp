<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-PRICE-REVIEW-ACTION-REPAIR-001 — Final Decision Price vs Suggested Price.
 *
 * Approve was applying `suggested_selling_price` unconditionally, overwriting a
 * price the operator had already set through the inline editor. The model could
 * not express the difference: the inline edit writes the operator's value into
 * `pricing_reviews.selling_price` (and `products.sale_price`) IN PLACE, so an
 * edited row and an untouched row are indistinguishable — both simply have a
 * current price that differs from the suggestion, and the pre-edit value is gone.
 *
 * These two booleans record *that* a manual decision was made. Deliberately NOT
 * new price columns: the operator's chosen figures already live in
 * `pricing_reviews.selling_price` and `products.sale_price`, and duplicating them
 * would create a second source of truth for the same number.
 *
 * Set by PricingReviewController::inline() when the operator edits a price, and
 * cleared when the operator asks the engine to re-derive the price (target
 * margin, markup, or reverting to brand policy) — that request supersedes an
 * earlier manual figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pricing_reviews')) {
            return;
        }

        Schema::table('pricing_reviews', function (Blueprint $table): void {
            if (! Schema::hasColumn('pricing_reviews', 'selling_price_overridden')) {
                $table->boolean('selling_price_overridden')
                    ->default(false)
                    ->after('suggested_sale_price');
            }

            if (! Schema::hasColumn('pricing_reviews', 'sale_price_overridden')) {
                $table->boolean('sale_price_overridden')
                    ->default(false)
                    ->after('selling_price_overridden');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pricing_reviews')) {
            return;
        }

        Schema::table('pricing_reviews', function (Blueprint $table): void {
            foreach (['selling_price_overridden', 'sale_price_overridden'] as $column) {
                if (Schema::hasColumn('pricing_reviews', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
