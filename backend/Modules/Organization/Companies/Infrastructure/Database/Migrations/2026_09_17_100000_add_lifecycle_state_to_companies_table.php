<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-PRELIVE-DATA-RESET-AND-GO-LIVE-PREPARATION-026 — the canonical Pre-Live/Live
 * authority. No such state existed anywhere in this codebase before this migration (confirmed by
 * exhaustive search — only `companies.is_active`, an unrelated enabled/disabled flag, existed).
 *
 * Mirrors the `goods_inward_mode` precedent exactly (same migration, same "own column on
 * `companies`, read through a dedicated Authority class" shape) rather than the generic
 * `config_company_settings` KV store — that precedent's own docblock explains why a certified
 * authoritative value needs its own column, not a second home in a generic store.
 *
 * DEFAULT IS `pre_live`: every existing company keeps behaving exactly as it does today
 * (destructive reset capability available, no Live-lock anywhere) until an operator explicitly
 * runs Go-Live activation. Defaulting to `live` would silently lock every existing company out of
 * this feature and any future pre-live tooling, which is the wrong direction to fail in.
 *
 * `live_activated_at`/`live_activated_by` are the audit trail for the one-way transition — see
 * ActivateGoLiveAction. There is no approved reversal contract (Task 026 §1 explicitly forbids
 * inventing one), so there is no `pre_live_reverted_at` counterpart.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'lifecycle_state')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->string('lifecycle_state', 20)->default('pre_live')->after('is_active');
                $table->timestamp('live_activated_at')->nullable()->after('lifecycle_state');
                $table->uuid('live_activated_by')->nullable()->after('live_activated_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'lifecycle_state')) {
            Schema::table('companies', function (Blueprint $table): void {
                $table->dropColumn(['lifecycle_state', 'live_activated_at', 'live_activated_by']);
            });
        }
    }
};
