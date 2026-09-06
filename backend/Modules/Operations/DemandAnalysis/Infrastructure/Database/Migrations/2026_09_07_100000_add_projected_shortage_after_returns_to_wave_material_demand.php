<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-POST-DRIVER-RETURN-WAREHOUSE-RETURNS-FINAL-IMPLEMENTATION-002 §14/§21-26.
 *
 * The five required shortage-projection figures map onto `wave_material_demand` as:
 *
 *   Required Qty                     — required_qty          (existing, unchanged)
 *   Physical Available                — available_qty          (existing, unchanged)
 *   Expected Driver Returns           — expected_today         (existing column, REUSED —
 *                                       was hardcoded 0.0 in MaterialDemandCalculator; see
 *                                       that class for why no new column was needed here)
 *   Physical Shortage Now             — missing_qty            (existing, unchanged)
 *   Projected Shortage After Returns  — projected_shortage_after_returns (NEW — this migration)
 *
 * ONLY ONE NEW COLUMN, because four of the five figures already have an
 * existing, correctly-typed home (§7's "do NOT add fields blindly" applies
 * here exactly as it does to the Distribution schema change). No existing
 * column can honestly carry "shortage after returns" — `in_transit_qty` is a
 * different, still-unused figure left untouched for a future distinct need
 * (e.g. supplier-in-transit) — so this one is genuinely new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wave_material_demand', function (Blueprint $table): void {
            $table->decimal('projected_shortage_after_returns', 15, 4)->default(0)->after('missing_qty');
        });
    }

    public function down(): void
    {
        Schema::table('wave_material_demand', function (Blueprint $table): void {
            $table->dropColumn('projected_shortage_after_returns');
        });
    }
};
