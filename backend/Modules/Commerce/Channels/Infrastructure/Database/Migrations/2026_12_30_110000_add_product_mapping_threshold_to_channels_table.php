<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-V1.1-WOO-04-GO-LIVE-LIFECYCLE — CTO source-review closure item C.
 *
 * The "product/price/stock mapping coverage" go-live gate originally used a hard-coded 80%
 * constant; the approved architecture calls for an operator-set threshold. Additive only: every
 * existing channel gets the same 80 this codebase already used, so no channel's readiness
 * outcome changes as a result of this migration alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', static function (Blueprint $table): void {
            $table->unsignedTinyInteger('product_mapping_coverage_threshold')->default(80)->after('shipping_mapping_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('channels', static function (Blueprint $table): void {
            $table->dropColumn('product_mapping_coverage_threshold');
        });
    }
};
