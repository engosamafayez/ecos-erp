<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerEngagement\Voice;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\AI\Application\Services\AIToolInvoker;
use Modules\AI\Application\Services\AIToolRegistry;
use Modules\AI\Domain\Enums\AIToolStatus;
use Modules\AI\Domain\ValueObjects\AIRequestContext;
use Modules\CustomerEngagement\Voice\Application\Actions\ProvisionVoiceSystemIdentityAction;
use Modules\CustomerEngagement\Voice\Application\Services\VoiceAIToolInvoker;
use Modules\CustomerEngagement\Voice\Application\Services\VoiceAIToolRegistry;
use Modules\CustomerEngagement\Voice\Application\Services\VoiceSystemIdentityResolver;
use Modules\CustomerEngagement\Voice\Application\Services\VoiceToolCatalogService;
use Modules\CustomerEngagement\Voice\Domain\Enums\CallerVerificationLevel;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §30 VOICE AI items 16-23
 * and §31 CORE-03 regression. `grantsBaselineAuthorization = false` throughout — this suite
 * exercises the authorization system itself, so actingAs()'s system-role auto-grant must not
 * mask what is actually being asserted.
 */
final class VoiceAIToolInvokerTest extends TestCase
{
    use DatabaseTransactions;

    protected bool $grantsBaselineAuthorization = false;

    private function grant(User $user, string ...$permissionNames): void
    {
        $role = Role::create(['name' => 'Test Role '.$user->id, 'slug' => 'test-role-'.$user->id, 'is_system' => false]);
        $ids = Permission::query()->whereIn('name', $permissionNames)->pluck('id');
        $role->permissions()->syncWithoutDetaching($ids);
        $user->assignRole($role);
    }

    private function context(string $companyId): AIRequestContext
    {
        return new AIRequestContext(
            userId: 0,
            companyId: $companyId,
            brandId: null,
            locale: 'en',
            route: null,
            module: null,
            page: null,
            entityType: 'cep_conversation',
            entityId: null,
        );
    }

    // ── 16: realtime voice provider contract fails closed when unavailable ─────────

    public function test_the_unavailable_realtime_voice_provider_fails_closed_on_every_call(): void
    {
        $provider = app(\Modules\CustomerEngagement\Voice\Application\Contracts\RealtimeVoiceProviderContract::class);
        $this->assertInstanceOf(\Modules\CustomerEngagement\Voice\Infrastructure\Providers\UnavailableRealtimeVoiceProvider::class, $provider);

        $this->expectException(\Modules\CustomerEngagement\Voice\Domain\Exceptions\RealtimeVoiceProviderUnavailableException::class);
        $provider->createSession(new \Modules\CustomerEngagement\Voice\Domain\ValueObjects\RealtimeVoiceSessionConfig(
            callId: 'x', language: 'ar-EG', systemPrompt: 'x', toolDefinitions: [], maxDurationSeconds: 60,
        ));
    }

    // ── 17: Voice tool invocation reuses canonical domain permissions ───────────────
    // ── 21: unauthorized Voice tool denied ───────────────────────────────────────────

    public function test_voice_invoker_denies_a_tool_whose_own_domain_permission_is_missing(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        // Has the Voice entry permission but NOT crm.customers.view (get_customer_orders'
        // own domain permission) — proves the tool's own permission is checked separately,
        // not substituted by the entry gate.
        $this->grant($user, 'cep.voice.use');
        $this->actingAsUnprivileged($user);

        $invoker = app(VoiceAIToolInvoker::class);
        $result = $invoker->invoke($this->context($company->id), $user, 'get_customer_orders', ['customer_id' => 'whatever']);

        $this->assertSame(AIToolStatus::Denied, $result->status);
    }

    public function test_voice_invoker_allows_a_tool_when_both_entry_and_domain_permissions_are_present(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->grant($user, 'cep.voice.use', 'crm.customers.view');
        $this->actingAsUnprivileged($user);

        $invoker = app(VoiceAIToolInvoker::class);
        $result = $invoker->invoke($this->context($company->id), $user, 'get_customer_orders', ['customer_id' => 'does-not-exist']);

        // Not denied by authorization — NotFound (unknown customer id), proving the tool's own
        // execute() ran, i.e. authorization passed.
        $this->assertNotSame(AIToolStatus::Denied, $result->status);
    }

    // ── 18: Resident AI still requires ai.assistant.use ──────────────────────────────

    public function test_resident_ai_invoker_still_requires_ai_assistant_use(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->grant($user, 'crm.customers.view'); // domain permission present, entry permission absent
        $this->actingAsUnprivileged($user);

        $invoker = app(AIToolInvoker::class);
        $result = $invoker->invoke($this->context($company->id), $user, 'get_customer_orders', ['customer_id' => 'x']);

        $this->assertSame(AIToolStatus::Denied, $result->status);
    }

    // ── 19: Voice execution does NOT require ai.assistant.use ───────────────────────

    public function test_voice_invoker_does_not_require_ai_assistant_use(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        // Deliberately grants ai.assistant.use but NOT cep.voice.use.
        $this->grant($user, 'ai.assistant.use', 'crm.customers.view');
        $this->actingAsUnprivileged($user);

        $invoker = app(VoiceAIToolInvoker::class);
        $result = $invoker->invoke($this->context($company->id), $user, 'get_customer_orders', ['customer_id' => 'x']);

        $this->assertSame(AIToolStatus::Denied, $result->status, 'ai.assistant.use must never work as a shortcut for cep.voice.use');
    }

    // ── 20: Voice system identity has no wildcard/admin bypass ──────────────────────

    public function test_voice_system_identity_is_never_provisioned_as_a_system_role(): void
    {
        $company = Company::factory()->create();
        $identity = app(ProvisionVoiceSystemIdentityAction::class)->execute($company->id);

        $this->assertFalse((bool) $identity->roles()->where('is_system', true)->exists());
        $this->assertSame($company->id, $identity->company_id);

        // Fail-closed resolver round-trip.
        $resolved = app(VoiceSystemIdentityResolver::class)->forCompany($company->id);
        $this->assertSame($identity->id, $resolved->id);
    }

    public function test_voice_system_identity_resolver_fails_closed_for_an_unprovisioned_company(): void
    {
        $company = Company::factory()->create();

        $this->expectException(\Modules\CustomerEngagement\Voice\Domain\Exceptions\VoiceIdentityNotProvisionedException::class);
        app(VoiceSystemIdentityResolver::class)->forCompany($company->id);
    }

    // ── 22: sensitive tool unavailable before caller verification ───────────────────
    // ── 23: approved scope becomes available only after verification ────────────────

    public function test_customer_balance_tool_is_only_offered_after_order_corroborated_verification(): void
    {
        $catalog = app(VoiceToolCatalogService::class);

        $unverified = $catalog->toolNamesFor(CallerVerificationLevel::Unverified);
        $verified = $catalog->toolNamesFor(CallerVerificationLevel::OrderCorroborated);

        $this->assertNotContains('get_customer_balance', $unverified);
        $this->assertContains('get_customer_balance', $verified);
    }

    // ── §31 CORE-03 regression: unknown tools still fail closed (both invokers) ────

    public function test_both_invokers_deny_an_unknown_tool_name(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->grant($user, 'ai.assistant.use', 'cep.voice.use');
        $this->actingAsUnprivileged($user);

        $resident = app(AIToolInvoker::class)->invoke($this->context($company->id), $user, 'not_a_real_tool', []);
        $voice = app(VoiceAIToolInvoker::class)->invoke($this->context($company->id), $user, 'not_a_real_tool', []);

        $this->assertSame(AIToolStatus::Denied, $resident->status);
        $this->assertSame(AIToolStatus::Denied, $voice->status);
    }

    // ── §31: the 9 CORE-03 tools remain registered exactly as before ────────────────

    public function test_resident_ai_registry_still_has_its_original_nine_tools(): void
    {
        $registry = app(AIToolRegistry::class);
        $names = array_map(fn ($t) => $t->name(), $registry->all());

        foreach ([
            'get_reports_catalogue', 'run_report', 'search_audit_log', 'get_order_summary',
            'get_order_payment_proof_state', 'get_customer_summary', 'get_customer_orders',
            'get_stock_availability', 'get_customer_balance',
        ] as $expected) {
            $this->assertContains($expected, $names, "CORE-03 regression: {$expected} missing from AIToolRegistry");
        }
        $this->assertCount(9, $names);
    }

    public function test_voice_registry_is_a_distinct_smaller_set_never_including_reporting_or_audit_tools(): void
    {
        $registry = app(VoiceAIToolRegistry::class);
        $names = array_map(fn ($t) => $t->name(), $registry->all());

        foreach (['run_report', 'get_reports_catalogue', 'search_audit_log'] as $excluded) {
            $this->assertNotContains($excluded, $names, "{$excluded} must never be reachable by an anonymous voice caller");
        }
        $this->assertContains('create_follow_up', $names);
        $this->assertContains('schedule_callback', $names);
        $this->assertContains('create_support_ticket', $names);
    }
}
