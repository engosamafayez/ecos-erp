<?php

declare(strict_types=1);

namespace Tests\Feature\Collaboration\Concerns;

use App\Models\User;
use Illuminate\Support\Str;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Models\ConversationParticipant;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Organization\Companies\Domain\Models\Company;

/**
 * Mirrors Tests\Feature\Security\DriverRbacTenancySecurityTest's fixture
 * pattern deliberately: a plain `actingAs()` auto-grants the `is_system`
 * role (see Tests\TestCase::actingAs()), which bypasses every permission
 * check — a test using it proves only that a route resolves, never that a
 * real, unprivileged role can reach it. Every Collaboration authorization
 * test therefore uses `actingAsUnprivileged()` on a user wearing a role
 * built here with an explicit, real grant list.
 */
trait CollaborationTestHelpers
{
    /** @param  list<string>  $permissionNames */
    private function userWithGrants(Company $company, string $roleSlug, array $permissionNames, string $dataScope = 'all'): User
    {
        $role = Role::firstOrCreate(['slug' => $roleSlug], ['name' => $roleSlug, 'is_system' => false]);

        $pivot = [];
        foreach ($permissionNames as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['module' => Str::before($name, '.'), 'action' => Str::afterLast($name, '.')],
            );
            $pivot[$permission->id] = ['effect' => 'allow', 'data_scope' => $dataScope];
        }
        $role->permissions()->sync($pivot);

        $user = User::factory()->create(['company_id' => $company->id]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function makeDriver(Company $company, ?User $user = null): Driver
    {
        return Driver::create([
            'company_id' => $company->id,
            'driver_code' => 'DRV-'.strtoupper(substr(uniqid('', true), -8)),
            'user_id' => $user?->id,
            'full_name' => 'Driver '.uniqid(),
            'mobile' => '0100'.random_int(1000000, 9999999),
            'national_id' => 'NID-'.strtoupper(substr(uniqid('', true), -10)),
        ]);
    }

    private function directConversation(Company $company, User $a, User $b): Conversation
    {
        $conversation = Conversation::factory()->direct()->create([
            'company_id' => $company->id,
            'created_by_user_id' => $a->id,
            'direct_pair_key' => Conversation::directPairKey($a->id, $b->id),
        ]);
        ConversationParticipant::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $a->id]);
        ConversationParticipant::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $b->id]);

        return $conversation;
    }

    /** @param  list<User>  $members */
    private function groupConversation(Company $company, User $owner, array $members): Conversation
    {
        $conversation = Conversation::factory()->create(['company_id' => $company->id, 'created_by_user_id' => $owner->id]);
        ConversationParticipant::factory()->owner()->create(['conversation_id' => $conversation->id, 'user_id' => $owner->id]);

        foreach ($members as $member) {
            ConversationParticipant::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $member->id]);
        }

        return $conversation;
    }
}
