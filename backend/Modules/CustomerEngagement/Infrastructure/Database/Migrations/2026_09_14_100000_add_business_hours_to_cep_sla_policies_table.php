<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §3B.
 *
 * `business_hours_only` has existed since the original cep_sla_policies migration but was never
 * enforced — nothing in this module read it or defined an actual schedule. This gives it one:
 * a per-day-of-week open/close window plus a timezone. Nullable/additive only; a policy with
 * business_hours_only=true and no business_hours configured is treated as "always open" by
 * BusinessHoursService (fail toward not silently extending every SLA clock, not toward blocking).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cep_sla_policies', 'business_hours')) {
            return;
        }

        Schema::table('cep_sla_policies', function (Blueprint $table): void {
            $table->json('business_hours')->nullable()->after('business_hours_only');
            $table->string('timezone')->nullable()->after('business_hours');
        });
    }

    public function down(): void
    {
        Schema::table('cep_sla_policies', function (Blueprint $table): void {
            $table->dropColumn(['business_hours', 'timezone']);
        });
    }
};
