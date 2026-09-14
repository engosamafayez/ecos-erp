<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §4 — see
 * Modules\CustomerEngagement\Voice\Domain\Models\Call's own docblock for the reasoning. One
 * additive table; no existing cep_* table is altered by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cep_calls')) {
            return;
        }

        Schema::create('cep_calls', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('cep_conversations')->cascadeOnDelete();

            // Business context — resolved server-side from the canonical ChannelProvider row,
            // never from AI/client input (architecture report, "Brand / Phone Ownership").
            $table->uuid('company_id')->index();
            $table->uuid('brand_id')->nullable();
            $table->foreignUuid('channel_provider_id')->constrained('cep_channel_providers');

            // Identity — resolved via CallerIdentityResolver; both nullable, an unresolved
            // caller has neither.
            $table->uuid('customer_id')->nullable()->index();
            $table->uuid('lead_id')->nullable();

            $table->string('direction');
            $table->string('from_number');
            $table->string('to_number');

            // Idempotency key alongside channel_provider_id — see architecture report SECURITY.
            $table->string('provider_call_id')->nullable();
            $table->string('provider');

            $table->string('canonical_state');
            $table->string('provider_raw_status')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->string('outcome')->nullable();
            $table->string('handled_by')->nullable();

            $table->timestamp('transferred_at')->nullable();
            $table->string('transfer_target_type')->nullable();
            $table->string('transfer_target_id')->nullable();

            // Pointers only — never the transcript/audio blob itself. Null unless the approved
            // recording/transcript policy actually retained one (architecture report PRIVACY).
            $table->string('transcript_ref')->nullable();
            $table->string('recording_ref')->nullable();

            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();

            // Minimized only — never a raw provider payload dump (§16/§25).
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->unique(['channel_provider_id', 'provider_call_id'], 'cep_calls_provider_call_unique');
            $table->index(['company_id', 'canonical_state'], 'cep_calls_co_state_idx');
            $table->index(['brand_id'], 'cep_calls_brand_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cep_calls');
    }
};
