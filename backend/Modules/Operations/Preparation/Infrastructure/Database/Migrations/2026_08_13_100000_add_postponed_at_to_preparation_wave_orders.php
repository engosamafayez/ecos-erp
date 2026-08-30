<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-PREPARATION-WAVE-ORDERS-WORKSPACE-REFINEMENT-002 — persistent postponement.
 *
 * Postponing an order out of the current preparation cycle cannot be expressed by
 * deleting its `preparation_wave_orders` row: `WaveMembershipService::attachEligibleOrders()`
 * selects orders with `whereNotExists(... preparation_wave_orders WHERE order_id = orders.id)`,
 * scoped by neither wave nor status nor date, and `wave:run-scheduler` runs every minute —
 * so a deleted membership is re-created within 60 seconds
 * (TASK-PREPARATION-WAVE-ORDERS-WORKSPACE-REFINEMENT-001 §3, STOP 1).
 *
 * Retaining the row and stamping `postponed_at` keeps the collector's existing
 * `whereNotExists` satisfied — the order is not re-attached — without changing a single
 * eligibility rule, and preserves the membership record as history rather than deleting it.
 *
 * Nullable and additive: an existing row reads NULL, i.e. "active", so every current query
 * keeps its present meaning until it explicitly opts into the filter.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('preparation_wave_orders', 'postponed_at')) {
            return;
        }

        Schema::table('preparation_wave_orders', function (Blueprint $table): void {
            $table->timestamp('postponed_at')->nullable()->after('added_by');
            $table->index(['preparation_wave_id', 'postponed_at'], 'idx_pwo_wave_postponed');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('preparation_wave_orders', 'postponed_at')) {
            return;
        }

        Schema::table('preparation_wave_orders', function (Blueprint $table): void {
            $table->dropIndex('idx_pwo_wave_postponed');
            $table->dropColumn('postponed_at');
        });
    }
};
