<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerEngagement\Voice;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\AI\Application\Services\AIToolInvoker;
use Modules\AI\Domain\Enums\AIToolStatus;
use Modules\AI\Domain\ValueObjects\AIRequestContext;
use Modules\CustomerEngagement\Domain\Enums\ChannelProviderStatus;
use Modules\CustomerEngagement\Domain\Models\ChannelProvider;
use Modules\CustomerEngagement\Voice\Application\Services\CallerVerificationService;
use Modules\CustomerEngagement\Voice\Application\Services\VoiceAIToolInvoker;
use Modules\CustomerEngagement\Voice\Application\Services\VoiceCallService;
use Modules\CustomerEngagement\Voice\Domain\Enums\CallerVerificationLevel;
use Modules\CustomerEngagement\Voice\Domain\Models\Call;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-CRM-03-VOICE-UX-AND-FINAL-SOURCE-CLOSURE-016 §31 VERIFICATION items 10-20 —
 * proving Gap B (verification was only enforced at session-config time, never re-checked at the
 * final AIToolInvoker execution choke point) is actually closed.
 *
 * Items 16 (ai.assistant.use still mandatory for Resident AI), 17 (Voice still doesn't require
 * ai.assistant.use) and 20 (unknown tool remains fail-closed) are already proven by Task 1's own
 * {@see VoiceAIToolInvokerTest} (test_resident_ai_invoker_still_requires_ai_assistant_use,
 * test_voice_invoker_does_not_require_ai_assistant_use, test_both_invokers_deny_an_unknown_tool_name)
 * — that file is preserved exactly as Task 1 left it, not touched or duplicated here.
 */
final class VoiceVerificationEnforcementTest extends TestCase
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

    private function context(string $companyId, ?string $callId): AIRequestContext
    {
        return new AIRequestContext(
            userId: 0,
            companyId: $companyId,
            brandId: null,
            locale: 'en',
            route: null,
            module: null,
            page: null,
            entityType: 'cep_call',
            entityId: $callId,
        );
    }

    private function makeCall(string $companyId): Call
    {
        $config = ChannelProvider::create([
            'company_id' => $companyId,
            'channel' => 'voice',
            'display_name' => 'Voice Line',
            'phone_number' => '201099999999',
            'status' => ChannelProviderStatus::ACTIVE->value,
            'credentials' => [],
        ]);

        return app(VoiceCallService::class)->handleInboundEvent($config, [
            'provider_call_id' => 'prov-verify-'.Str::random(8),
            'direction' => 'inbound',
            'from_number' => '201000000099',
            'to_number' => '201099999999',
            'signal' => 'ringing',
            'raw_status' => 'ringing',
            'event_id' => (string) Str::uuid(),
        ]);
    }

    // ── 10: verified-required tool denied when unverified ───────────────────────────

    public function test_verification_required_tool_is_denied_while_the_call_is_unverified(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->grant($user, 'cep.voice.use', 'finance.reports.view');
        $this->actingAsUnprivileged($user);

        $call = $this->makeCall($company->id);
        $this->assertSame(CallerVerificationLevel::Unverified, app(CallerVerificationService::class)->levelOf($call->fresh()));

        $result = app(VoiceAIToolInvoker::class)->invoke(
            $this->context($company->id, $call->id), $user, 'get_customer_balance', ['customer_id' => 'x'],
        );

        $this->assertSame(AIToolStatus::Denied, $result->status);
    }

    // ── 11: same tool allowed only after canonical verification ─────────────────────

    public function test_verification_required_tool_is_allowed_once_the_call_is_order_corroborated(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->grant($user, 'cep.voice.use', 'finance.reports.view');
        $this->actingAsUnprivileged($user);

        $call = $this->makeCall($company->id);
        $call->update(['metadata' => ['verification_level' => CallerVerificationLevel::OrderCorroborated->value]]);

        $result = app(VoiceAIToolInvoker::class)->invoke(
            $this->context($company->id, $call->id), $user, 'get_customer_balance', ['customer_id' => 'does-not-exist'],
        );

        // Not Denied — proves the invoker's verification gate let it through to the tool's own
        // execute() (which then reports NotFound for the made-up customer id).
        $this->assertNotSame(AIToolStatus::Denied, $result->status);
    }

    // ── 12: stale tool catalogue cannot bypass ───────────────────────────────────────

    public function test_a_call_that_was_once_verified_but_is_no_longer_is_denied_despite_ever_having_been_offered_the_tool(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->grant($user, 'cep.voice.use', 'finance.reports.view');
        $this->actingAsUnprivileged($user);

        $call = $this->makeCall($company->id);
        // A session built its tool catalogue while the call WAS verified (so the tool would
        // legitimately have been offered to the model)...
        $call->update(['metadata' => ['verification_level' => CallerVerificationLevel::OrderCorroborated->value]]);
        // ...but the call's CURRENT authoritative state has since reverted (e.g. a fresh leg,
        // an expired/invalidated verification) by the time this specific tool call actually
        // arrives at the invoker.
        $call->update(['metadata' => ['verification_level' => CallerVerificationLevel::Unverified->value]]);

        $result = app(VoiceAIToolInvoker::class)->invoke(
            $this->context($company->id, $call->id), $user, 'get_customer_balance', ['customer_id' => 'x'],
        );

        $this->assertSame(AIToolStatus::Denied, $result->status, 'being offered a tool once must never imply it stays executable later');
    }

    // ── 13: model-supplied verified=true cannot bypass ───────────────────────────────

    public function test_a_model_supplied_verified_flag_inside_raw_input_is_never_consulted(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->grant($user, 'cep.voice.use', 'finance.reports.view');
        $this->actingAsUnprivileged($user);

        $call = $this->makeCall($company->id);

        $result = app(VoiceAIToolInvoker::class)->invoke(
            $this->context($company->id, $call->id), $user, 'get_customer_balance',
            ['customer_id' => 'x', 'verified' => true, 'verification_level' => 'order_corroborated', 'is_verified' => true],
        );

        $this->assertSame(AIToolStatus::Denied, $result->status, 'no key inside model-supplied rawInput may ever substitute for a real, server-read verification level');
    }

    // ── 14: frontend-supplied verified=true cannot bypass ────────────────────────────

    public function test_an_unresolvable_call_reference_fails_closed_to_unverified_rather_than_defaulting_to_allowed(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->grant($user, 'cep.voice.use', 'finance.reports.view');
        $this->actingAsUnprivileged($user);

        // AIRequestContext has no "verified" field for a frontend/session to populate at all
        // (see its own constructor) — the only lever a caller has is entityId, and a bogus one
        // must resolve to nothing, never to an implicit allow.
        $result = app(VoiceAIToolInvoker::class)->invoke(
            $this->context($company->id, (string) Str::uuid()), $user, 'get_customer_balance', ['customer_id' => 'x'],
        );

        $this->assertSame(AIToolStatus::Denied, $result->status);
    }

    // ── 15: Resident AI has no verification concept at all — unaffected by Gap B ────

    public function test_resident_ai_invoker_has_no_caller_verification_concept_and_is_unaffected_by_the_voice_only_gate(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->grant($user, 'ai.assistant.use', 'finance.reports.view');
        $this->actingAsUnprivileged($user);

        // No Call exists at all in this test — proving the Resident invoker's path to
        // get_customer_balance has nothing analogous to VoiceAIToolInvoker::resolveCall() to
        // even consult.
        $result = app(AIToolInvoker::class)->invoke(
            $this->context($company->id, null), $user, 'get_customer_balance', ['customer_id' => 'does-not-exist'],
        );

        $this->assertNotSame(AIToolStatus::Denied, $result->status);
    }

    // ── 18: domain permission still independently required, even once verified ─────

    public function test_a_verified_call_still_cannot_reach_a_tool_whose_own_domain_permission_is_missing(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        // Entry permission only — deliberately withholds finance.reports.view.
        $this->grant($user, 'cep.voice.use');
        $this->actingAsUnprivileged($user);

        $call = $this->makeCall($company->id);
        $call->update(['metadata' => ['verification_level' => CallerVerificationLevel::OrderCorroborated->value]]);

        $result = app(VoiceAIToolInvoker::class)->invoke(
            $this->context($company->id, $call->id), $user, 'get_customer_balance', ['customer_id' => 'x'],
        );

        $this->assertSame(AIToolStatus::Denied, $result->status, 'verification satisfies the verification gate only — it must never substitute for the tool\'s own domain permission');
    }

    // ── 19: company scope still enforced — a verified call in another company can never verify this request ──

    public function test_a_verified_call_belonging_to_a_different_company_can_never_verify_this_request(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $userB = User::factory()->create(['company_id' => $companyB->id]);
        $this->grant($userB, 'cep.voice.use', 'finance.reports.view');
        $this->actingAsUnprivileged($userB);

        $callInA = $this->makeCall($companyA->id);
        $callInA->update(['metadata' => ['verification_level' => CallerVerificationLevel::OrderCorroborated->value]]);

        // The context claims company B (userB's real, authorized company) but points entityId
        // at a call that actually belongs to company A.
        $result = app(VoiceAIToolInvoker::class)->invoke(
            $this->context($companyB->id, $callInA->id), $userB, 'get_customer_balance', ['customer_id' => 'x'],
        );

        $this->assertSame(AIToolStatus::Denied, $result->status, 'resolveCall() must scope by the context\'s own company_id — a verified call from a different company must never leak verification across tenants');
    }
}
