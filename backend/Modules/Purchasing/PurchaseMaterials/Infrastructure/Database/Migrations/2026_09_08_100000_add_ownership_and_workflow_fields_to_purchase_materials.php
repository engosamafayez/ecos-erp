<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-PROCUREMENT-PURCHASE-REQUESTS-AND-HUB-FINAL-REMEDIATION-011.
 *
 * Three small, additive columns — no existing column is dropped or renamed,
 * so every existing reader of `assigned_buyer` (the free-text display value,
 * kept in sync going forward rather than replaced) keeps working unchanged:
 *
 *   assigned_buyer_id  — the REAL canonical-IAM-user relation §5 requires.
 *                        `assigned_buyer` (string) stays as-is and is kept in
 *                        sync with this user's name by the write path, so it
 *                        remains a valid display value / exact-match filter.
 *   held_from_status   — §4's "on_hold is a dead end" fix needs to know which
 *                        status to resume back into; HoldPurchaseMaterialAction
 *                        never recorded this before.
 *   completed_at        — mirrors the existing submitted_at/approved_at
 *                        pattern for the new terminal-fulfillment state §10
 *                        makes reachable for the first time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('purchase_materials', 'assigned_buyer_id')) {
            return;
        }

        Schema::table('purchase_materials', function (Blueprint $table): void {
            $table->foreignId('assigned_buyer_id')->nullable()->after('assigned_buyer')
                ->constrained('users')->restrictOnDelete();
            $table->string('held_from_status', 30)->nullable()->after('status');
            $table->timestampTz('completed_at')->nullable()->after('approved_at');

            $table->index('assigned_buyer_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_materials', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_buyer_id');
            $table->dropColumn(['held_from_status', 'completed_at']);
        });
    }
};
