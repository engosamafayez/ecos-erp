<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §20 — the four permissions
 * approved by the CRM-03 architecture report's IAM section, exact names, same
 * module.resource.action convention and same "seed but never auto-grant" precedent as
 * `ai.assistant.use` (CORE-03) — see 2026_12_30_130000_seed_ai_assistant_permission.php.
 *
 * `cep.voice.use` is the entry permission VoiceAIToolInvoker checks (never `ai.assistant.use` —
 * architecture report §35 / CORE-03 REUSE). None of these four grant business-data access on
 * their own; every Voice tool call additionally requires that tool's own existing domain
 * permission, identically to Resident AI.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        ['cep.voice.use', 'cep', 'voice', 'use', 'See, answer, monitor, and initiate Voice calls'],
        ['cep.voice.transfer', 'cep', 'voice', 'transfer', 'Take over / bridge a live Voice call'],
        ['cep.voice.recordings.view', 'cep', 'voice-recordings', 'view', 'Listen to Voice call recordings and read transcripts'],
        ['cep.voice.provider.manage', 'cep', 'voice-provider', 'manage', 'Configure telephony provider, numbers, and Brand voice settings'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach (self::PERMISSIONS as [$name, $module, $resource, $action, $description]) {
            if (DB::table('permissions')->where('name', $name)->exists()) {
                continue;
            }

            DB::table('permissions')->insert([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'module' => $module,
                'resource' => $resource,
                'action' => $action,
                'description' => $description,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->whereIn('name', array_column(self::PERMISSIONS, 0))->delete();
    }
};
