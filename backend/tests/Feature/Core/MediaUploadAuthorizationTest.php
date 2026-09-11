<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * 035B-R1 — MediaController::upload() previously required only auth:sanctum:
 * any authenticated user could upload media for ANY context, for ANY
 * company. Authorization is now context-aware, keyed to the same permission
 * that already governs each context's owning entity's create/update routes.
 */
final class MediaUploadAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    private function userWithPermission(?Company $company, string ...$permissionNames): User
    {
        $user = User::factory()->create(['company_id' => $company?->id]);

        if ($permissionNames === []) {
            return $user;
        }

        $role = Role::firstOrCreate(
            ['slug' => 'test-media-'.md5(implode(',', $permissionNames))],
            ['name' => 'Test Media Role', 'is_system' => false],
        );

        foreach ($permissionNames as $name) {
            [$module, $resource, $action] = explode('.', $name);
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['module' => $module, 'resource' => $resource, 'action' => $action],
            );

            if (! $role->permissions()->where('permissions.id', $permission->id)->exists()) {
                $role->permissions()->attach($permission->id);
            }
        }

        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    private function fakeImage(): UploadedFile
    {
        return UploadedFile::fake()->image('photo.jpg', 100, 100)->size(50);
    }

    public function test_unauthenticated_upload_is_denied(): void
    {
        $this->postJson('/api/media/upload', ['context' => 'brands', 'file' => $this->fakeImage()])
            ->assertUnauthorized();
    }

    public function test_authenticated_but_unauthorized_context_is_denied(): void
    {
        // Holds no permission at all.
        $user = $this->userWithPermission(Company::factory()->create());

        $this->actingAsUnprivileged($user)
            ->postJson('/api/media/upload', ['context' => 'brands', 'file' => $this->fakeImage()])
            ->assertForbidden();
    }

    public function test_unknown_context_is_denied_even_for_a_privileged_user(): void
    {
        $user = $this->userWithPermission(
            Company::factory()->create(),
            'organization.brands.create',
            'organization.companies.create',
            'organization.business_accounts.create',
            'inventory.products.create',
        );

        // Fails validation's `in:` whitelist first (422), never reaching the
        // authorization map — still correctly denied either way.
        $this->actingAsUnprivileged($user)
            ->postJson('/api/media/upload', ['context' => 'not-a-real-context', 'file' => $this->fakeImage()])
            ->assertStatus(422);
    }

    public function test_authorized_inventory_context_succeeds(): void
    {
        Storage::fake('public');
        $user = $this->userWithPermission(Company::factory()->create(), 'inventory.products.create');

        $this->actingAsUnprivileged($user)
            ->postJson('/api/media/upload', ['context' => 'raw-materials', 'file' => $this->fakeImage()])
            ->assertOk()
            ->assertJsonStructure(['data' => ['path', 'url']]);
    }

    public function test_authorized_organization_context_succeeds(): void
    {
        Storage::fake('public');
        $user = $this->userWithPermission(Company::factory()->create(), 'organization.brands.update');

        $this->actingAsUnprivileged($user)
            ->postJson('/api/media/upload', ['context' => 'brands', 'file' => $this->fakeImage()])
            ->assertOk()
            ->assertJsonStructure(['data' => ['path', 'url']]);
    }

    public function test_permission_for_one_context_does_not_authorize_another(): void
    {
        // Only inventory.products — no organization.brands.* at all.
        $user = $this->userWithPermission(Company::factory()->create(), 'inventory.products.create');

        $this->actingAsUnprivileged($user)
            ->postJson('/api/media/upload', ['context' => 'brands', 'file' => $this->fakeImage()])
            ->assertForbidden();
    }

    public function test_a_client_supplied_company_id_is_never_trusted_as_authorization(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();

        // No permission grant at all — only a spoofed company_id claiming to
        // be a different (unrelated) company is supplied.
        $user = $this->userWithPermission($mine);

        $this->actingAsUnprivileged($user)
            ->postJson('/api/media/upload', [
                'context' => 'brands',
                'company_id' => $theirs->id,
                'file' => $this->fakeImage(),
            ])
            ->assertForbidden();
    }

    public function test_existing_legitimate_callers_remain_compatible(): void
    {
        Storage::fake('public');

        // Mirrors the real callers' shape exactly: raw-materials/packaging-
        // materials/products share inventory.products.*; companies and
        // business-accounts use their own sibling permissions.
        $cases = [
            ['context' => 'raw-materials', 'permission' => 'inventory.products.create'],
            ['context' => 'packaging-materials', 'permission' => 'inventory.products.update'],
            ['context' => 'products', 'permission' => 'inventory.products.create'],
            ['context' => 'companies', 'permission' => 'organization.companies.update'],
            ['context' => 'business-accounts', 'permission' => 'organization.business_accounts.create'],
        ];

        foreach ($cases as $case) {
            $user = $this->userWithPermission(Company::factory()->create(), $case['permission']);

            $this->actingAsUnprivileged($user)
                ->postJson('/api/media/upload', ['context' => $case['context'], 'file' => $this->fakeImage()])
                ->assertOk();
        }
    }
}
