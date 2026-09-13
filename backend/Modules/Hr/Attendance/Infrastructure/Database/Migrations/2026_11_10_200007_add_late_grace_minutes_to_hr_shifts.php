<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FIN-01 — Late semantics needs one configuration the existing shift/calendar
 * authority does not yet carry: how many minutes past the scheduled start a
 * check-in still counts as on time. Additive, defaults to zero (no grace)
 * rather than any guessed company-wide value.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_shifts') || Schema::hasColumn('hr_shifts', 'late_grace_minutes')) {
            return;
        }

        Schema::table('hr_shifts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('late_grace_minutes')->default(0)->after('end_time');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_shifts') || ! Schema::hasColumn('hr_shifts', 'late_grace_minutes')) {
            return;
        }

        Schema::table('hr_shifts', function (Blueprint $table): void {
            $table->dropColumn('late_grace_minutes');
        });
    }
};
