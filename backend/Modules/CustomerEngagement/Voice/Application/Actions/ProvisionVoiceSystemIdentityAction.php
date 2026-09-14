<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Application\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\IAM\Domain\Enums\UserStatus;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §11 — the explicit
 * provisioning pattern for the minimal-privilege "AI Voice Assistant" system identity.
 *
 * One dedicated Role (`ai-voice-assistant`), shared across companies, holding EXACTLY the
 * permissions the Voice tool catalogue needs — no more:
 *   cep.voice.use (its own entry gate), crm.customers.view, sales.orders.view,
 *   sales.orders.proof_view, inventory.products.view (the 5 reused CORE-03 read tools),
 *   cep.inbox.manage (CreateFollowUpTool/ScheduleCallbackTool), crm.service.manage
 *   (CreateSupportTicketTool). `is_system` is never true (§11: "no Super Admin bypass, no
 * system-wide wildcard permission").
 *
 * One dedicated User PER COMPANY (never one global row) — TenantOwnershipResolver/
 * CurrentCompanyService resolve company scope from `$user->company_id` directly (no session
 * "active company" override exists in this codebase), so a single cross-company identity would
 * either be scoped to one arbitrary company (silently wrong for every other) or would need
 * `is_system=true` (an explicit, forbidden bypass). A per-company row lets every existing
 * tenant-scoping check keep working unmodified, honestly, for whichever company a given call
 * belongs to.
 *
 * Idempotent: safe to call again for a company that's already provisioned (no duplicate role or
 * user rows, permissions re-synced not appended).
 */
final class ProvisionVoiceSystemIdentityAction
{
    private const ROLE_SLUG = 'ai-voice-assistant';

    private const PERMISSIONS = [
        'cep.voice.use',
        'crm.customers.view',
        'sales.orders.view',
        'sales.orders.proof_view',
        'inventory.products.view',
        'cep.inbox.manage',
        'crm.service.manage',
    ];

    public function execute(string $companyId): User
    {
        $role = Role::query()->firstOrCreate(
            ['slug' => self::ROLE_SLUG],
            [
                'name' => 'AI Voice Assistant',
                'description' => 'Minimal-privilege execution principal for Voice AI tool calls. Never assigned to a human.',
                'is_system' => false,
            ],
        );

        $permissionIds = Permission::query()->whereIn('name', self::PERMISSIONS)->pluck('id');
        $role->permissions()->syncWithoutDetaching($permissionIds);

        $email = "ai-voice-assistant+{$companyId}@system.ecos.internal";

        $user = User::query()->where('company_id', $companyId)->where('email', $email)->first();

        if ($user === null) {
            $user = new User([
                'name' => 'AI Voice Assistant',
                'email' => $email,
                'password' => Hash::make(Str::random(64)),
                'company_id' => $companyId,
            ]);
            // status is deliberately not mass-assignable (ADR-040) — set directly, bypassing
            // the human draft->invited->pending_activation lifecycle this account never goes
            // through (nobody logs into it interactively).
            $user->status = UserStatus::ACTIVE->value;
            $user->save();
        }

        $user->assignRole($role);

        return $user->fresh();
    }
}
