<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Core\Audit\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\IAM\Application\Services\UserIdentityService;
use Modules\IAM\Application\Services\UserLifecycleService;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Tests\TestCase;

/**
 * CORE-02 Task 2 §2/§3 — the central Audit read/search HTTP surface. Write-side behaviour
 * (App\Core\Audit\AuditService::record()) is exercised elsewhere by every module that already
 * calls it; these tests cover only the new read/search endpoint: authorization, company
 * boundary, filtering, pagination, and that stored payloads are rendered honestly.
 */
class AuditLogHttpTest extends TestCase
{
    use RefreshDatabase;

    private function perm(string $name): Permission
    {
        [$d, $r, $a] = explode('.', $name);

        return Permission::firstOrCreate(['name' => $name], ['module' => $d, 'resource' => $r, 'action' => $a]);
    }

    private function actorWithPermissions(string $companyId, array $permissionNames): User
    {
        $role = Role::create(['name' => 'Role '.Str::random(6), 'slug' => 'r-'.Str::random(8), 'is_system' => false]);
        foreach ($permissionNames as $name) {
            $role->permissions()->attach($this->perm($name)->id, ['effect' => 'allow']);
        }

        $user = app(UserIdentityService::class)->createDraft(['name' => 'Actor', 'email' => Str::random(10).'@ecos.test'], $companyId);
        app(UserLifecycleService::class)->activate($user);
        $user->roles()->attach($role->id);

        return $user->refresh();
    }

    private function logRow(array $overrides = []): AuditLog
    {
        return AuditLog::create(array_merge([
            'id' => (string) Str::uuid(),
            'company_id' => (string) Str::uuid(),
            'user_id' => null,
            'action' => 'user.activated',
            'entity_type' => 'user',
            'entity_id' => (string) Str::uuid(),
            'old_values' => ['status' => 'draft'],
            'new_values' => ['status' => 'active'],
            'metadata' => null,
            'occurred_at' => now(),
        ], $overrides));
    }

    public function test_unauthorized_actor_cannot_list_audit_logs(): void
    {
        $companyId = (string) Str::uuid();
        $actor = $this->actorWithPermissions($companyId, []); // no system.audit.view

        $this->actingAsUnprivileged($actor)->getJson('/api/audit')->assertForbidden();
    }

    public function test_authorized_actor_sees_only_their_own_companys_events(): void
    {
        $companyId = (string) Str::uuid();
        $foreignCompanyId = (string) Str::uuid();
        $actor = $this->actorWithPermissions($companyId, ['system.audit.view']);

        $mine = $this->logRow(['company_id' => $companyId, 'action' => 'user.invited']);
        $this->logRow(['company_id' => $foreignCompanyId, 'action' => 'user.invited']);

        $response = $this->actingAsUnprivileged($actor)->getJson('/api/audit')->assertOk();

        $rows = $response->json('data.items');
        $this->assertCount(1, $rows);
        $this->assertSame($mine->id, $rows[0]['id']);
    }

    public function test_a_client_supplied_company_id_cannot_widen_a_normal_actors_scope(): void
    {
        $companyId = (string) Str::uuid();
        $foreignCompanyId = (string) Str::uuid();
        $actor = $this->actorWithPermissions($companyId, ['system.audit.view']);

        $this->logRow(['company_id' => $foreignCompanyId]);

        $response = $this->actingAsUnprivileged($actor)
            ->getJson('/api/audit?company_id='.$foreignCompanyId)
            ->assertOk();

        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_filters_by_action_entity_type_and_date_range(): void
    {
        $companyId = (string) Str::uuid();
        $actor = $this->actorWithPermissions($companyId, ['system.audit.view']);

        $match = $this->logRow([
            'company_id' => $companyId,
            'action' => 'role.updated',
            'entity_type' => 'role',
            'occurred_at' => '2026-06-15 10:00:00',
        ]);
        $this->logRow(['company_id' => $companyId, 'action' => 'user.invited', 'entity_type' => 'user', 'occurred_at' => '2026-06-15 10:00:00']);
        $this->logRow(['company_id' => $companyId, 'action' => 'role.updated', 'entity_type' => 'role', 'occurred_at' => '2026-01-01 10:00:00']);

        $response = $this->actingAsUnprivileged($actor)->getJson(
            '/api/audit?action=role.updated&entity_type=role&date_from=2026-06-01&date_to=2026-06-30',
        )->assertOk();

        $rows = $response->json('data.items');
        $this->assertCount(1, $rows);
        $this->assertSame($match->id, $rows[0]['id']);
    }

    public function test_pagination_meta_reflects_total_and_page_size(): void
    {
        $companyId = (string) Str::uuid();
        $actor = $this->actorWithPermissions($companyId, ['system.audit.view']);

        for ($i = 0; $i < 5; $i++) {
            $this->logRow(['company_id' => $companyId]);
        }

        $response = $this->actingAsUnprivileged($actor)->getJson('/api/audit?per_page=2&page=1')->assertOk();

        $this->assertCount(2, $response->json('data.items'));
        $meta = $response->json('data.meta');
        $this->assertSame(5, $meta['total']);
        $this->assertSame(2, $meta['per_page']);
        $this->assertSame(3, $meta['last_page']);
    }

    /**
     * Renders whatever the write side already stored, honestly — this read layer must never
     * invent, strip, or reconstruct fields beyond what the recording caller chose to persist
     * (§4). A caller that already redacted a sensitive field before calling
     * AuditService::record() (out of this task's scope to change) is proven correct here by
     * the read side faithfully returning exactly that already-redacted shape.
     */
    public function test_stored_payload_is_rendered_exactly_as_written_no_more_no_less(): void
    {
        $companyId = (string) Str::uuid();
        $actor = $this->actorWithPermissions($companyId, ['system.audit.view']);

        $this->logRow([
            'company_id' => $companyId,
            'action' => 'user.password_reset',
            'old_values' => [],
            'new_values' => ['password' => '[redacted]'],
            'metadata' => ['reset_by' => 'admin'],
        ]);

        $row = $this->actingAsUnprivileged($actor)->getJson('/api/audit')->assertOk()->json('data.items.0');

        $this->assertSame(['password' => '[redacted]'], $row['new_values']);
        $this->assertSame(['reset_by' => 'admin'], $row['metadata']);
    }

    public function test_system_actor_sees_events_across_companies_without_a_filter(): void
    {
        $companyA = (string) Str::uuid();
        $companyB = (string) Str::uuid();
        $this->logRow(['company_id' => $companyA]);
        $this->logRow(['company_id' => $companyB]);

        $systemRole = Role::create(['name' => 'Super Admin Test', 'slug' => 'sa-'.Str::random(8), 'is_system' => true]);
        $system = app(UserIdentityService::class)->createDraft(['name' => 'System', 'email' => Str::random(10).'@ecos.test'], $companyA);
        app(UserLifecycleService::class)->activate($system);
        $system->roles()->attach($systemRole->id);

        $response = $this->actingAsUnprivileged($system->refresh())->getJson('/api/audit')->assertOk();

        $this->assertCount(2, $response->json('data.items'));
    }
}
