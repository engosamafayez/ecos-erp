<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-OPERATIONS-DRIVER-SINGLE-ACTIVE-TRIP-CLOSURE-CONTRACT-001 — Expected Collection snapshot.
 *
 * The CTO-approved meaning of "Expected Collection" is the amount still collectible from the
 * customer AT THE MOMENT the order enters the driver's operational custody / trip — an immutable
 * operational snapshot, NOT a read-time recompute from the (mutable) order state. This adds the
 * smallest such snapshot at the existing custody-handoff boundary (stop creation).
 *
 * Nullable on purpose: rows that predate this column (historical stops) stay NULL and surface as
 * "Not available" — there is NO backfill from current order state (rule 8). Later events
 * (payment-method change, cash collection, transfer proof, delivery result) must NEVER rewrite it;
 * they affect Actual Collections / Collection Difference, not this handoff expectation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distribution_delivery_stops', function (Blueprint $table): void {
            $table->decimal('expected_collection_at_handoff', 12, 2)
                ->nullable()
                ->after('collected_amount');
        });
    }

    public function down(): void
    {
        Schema::table('distribution_delivery_stops', function (Blueprint $table): void {
            $table->dropColumn('expected_collection_at_handoff');
        });
    }
};
