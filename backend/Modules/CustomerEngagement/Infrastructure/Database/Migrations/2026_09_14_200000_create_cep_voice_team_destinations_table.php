<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-V1.1-CRM-03-VOICE-UX-AND-FINAL-SOURCE-CLOSURE-016 §2/§35 — Gap A.
 *
 * Individual-employee transfer targets already have a canonical dialable phone: hr_employees
 * (phone/mobile), reached via Employee.user_id = cep_conversations.assigned_employee_id. No
 * migration is needed or added for that case — see TransferDestinationResolver.
 *
 * Teams (Organization\Teams\Team, referenced by cep_conversations.assigned_team_id) have NO
 * phone concept anywhere in the codebase — confirmed by reading Team.php (company_id, code,
 * name, leader_name, description, is_active only). This is the one genuinely missing piece the
 * architecture (014) anticipated ("Brand/company scoped Voice transfer destination
 * configuration linked to the existing routing/team authority") — additive only, no existing
 * table altered, no historical data touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cep_voice_team_destinations')) {
            return;
        }

        Schema::create('cep_voice_team_destinations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('brand_id')->nullable();
            $table->foreignUuid('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('phone_number');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['team_id', 'brand_id'], 'cep_vtd_team_brand_unique');
            $table->index(['company_id', 'is_active'], 'cep_vtd_co_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cep_voice_team_destinations');
    }
};
