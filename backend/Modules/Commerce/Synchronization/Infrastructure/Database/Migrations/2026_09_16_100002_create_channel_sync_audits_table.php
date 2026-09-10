<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-WOOCOMMERCE-SYNC-CONTROLS-AND-HISTORICAL-IMPORT-025 (audit).
 *
 * Scoped narrowly to Woo channel sync-setting events (pause/resume/policy/cutoff/historical
 * import/credential rotation) — not a platform-wide audit framework. `company_id` is denormalised
 * here (rather than resolved via channel->brand->company on every read) so company-scoped audit
 * queries never depend on a channel that may since have been deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_sync_audits', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('channel_id')->index();
            $table->uuid('company_id')->index();
            $table->uuid('actor_id')->nullable();
            $table->string('actor_type', 20)->default('system');
            $table->string('action', 60)->index();
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_sync_audits');
    }
};
