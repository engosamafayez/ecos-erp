<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Core\AI\Contracts\AIProviderInterface;
use App\Core\AI\Providers\FakeAIProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\IAM\Application\Services\UserIdentityService;
use Modules\IAM\Application\Services\UserLifecycleService;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Tests\TestCase;

/**
 * CORE-03 Task 2 §3/§28 — the only intended backend closure item this task
 * adds. Not executed this session (same missing fresh-MySQL/migration-runtime
 * gap as Task 1's own suite) — written and ready for the next runtime-testing
 * phase.
 */
class AIAssistantRateLimitTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('ai-assistant');
        config(['ai.rate_limit_per_minute' => 3]);
        $this->app->instance(AIProviderInterface::class, FakeAIProvider::withText('OK'));
    }

    public function test_assistant_endpoint_is_rate_limited(): void
    {
        $companyId = (string) Str::uuid();
        $user = $this->actorWithPermissions($companyId, ['ai.assistant.use']);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAsUnprivileged($user)->postJson('/api/ai/assistant/message', ['message' => 'hi'])->assertOk();
        }

        $this->actingAsUnprivileged($user)->postJson('/api/ai/assistant/message', ['message' => 'hi'])->assertStatus(429);
    }

    public function test_limit_is_per_authenticated_user_not_global(): void
    {
        $companyId = (string) Str::uuid();
        $userA = $this->actorWithPermissions($companyId, ['ai.assistant.use']);
        $userB = $this->actorWithPermissions($companyId, ['ai.assistant.use']);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAsUnprivileged($userA)->postJson('/api/ai/assistant/message', ['message' => 'hi'])->assertOk();
        }
        $this->actingAsUnprivileged($userA)->postJson('/api/ai/assistant/message', ['message' => 'hi'])->assertStatus(429);

        // A different user's own bucket is untouched.
        $this->actingAsUnprivileged($userB)->postJson('/api/ai/assistant/message', ['message' => 'hi'])->assertOk();
    }

    public function test_requests_within_the_threshold_succeed(): void
    {
        $companyId = (string) Str::uuid();
        $user = $this->actorWithPermissions($companyId, ['ai.assistant.use']);

        $this->actingAsUnprivileged($user)->postJson('/api/ai/assistant/message', ['message' => 'hi'])
            ->assertOk()
            ->assertJsonPath('data.status', 'ok');
    }

    /**
     * Being rate-limited is orthogonal to ai.assistant.use — a user who lacks
     * the permission is still denied (never let through just because they
     * haven't hit the throttle yet).
     */
    public function test_rate_limiting_does_not_bypass_the_entry_permission(): void
    {
        $companyId = (string) Str::uuid();
        $user = $this->actorWithPermissions($companyId, []); // no ai.assistant.use

        $response = $this->actingAsUnprivileged($user)->postJson('/api/ai/assistant/message', ['message' => 'hi'])->assertOk();

        $this->assertSame('denied', $response->json('data.status'));
    }

    /**
     * Being well under the throttle is orthogonal to tool/domain authorization
     * — a user with ai.assistant.use but no sales.orders.view is still denied
     * at the tool level regardless of how many requests they have left.
     */
    public function test_rate_limiting_does_not_weaken_tool_authorization(): void
    {
        $companyId = (string) Str::uuid();
        $user = $this->actorWithPermissions($companyId, ['ai.assistant.use']); // no sales.orders.view
        $this->app->instance(AIProviderInterface::class, FakeAIProvider::withToolCall('get_order_summary', ['order_id' => (string) Str::uuid()]));

        $this->actingAsUnprivileged($user)->postJson('/api/ai/assistant/message', ['message' => 'why is this order stuck?'])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['action' => 'ai.tool_denied', 'company_id' => $companyId]);
    }
}
