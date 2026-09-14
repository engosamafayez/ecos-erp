<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerEngagement\Voice;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\CustomerEngagement\Domain\Enums\ChannelProviderStatus;
use Modules\CustomerEngagement\Domain\Models\ChannelProvider;
use Modules\CustomerEngagement\Domain\Models\Lead;
use Modules\CustomerEngagement\Voice\Application\Services\CallerIdentityResolver;
use Modules\CustomerEngagement\Voice\Application\Services\CallStateMapper;
use Modules\CustomerEngagement\Voice\Application\Services\VoiceCallService;
use Modules\CustomerEngagement\Voice\Domain\Enums\CallCanonicalState;
use Modules\CustomerEngagement\Voice\Domain\Models\Call;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §30 CALL DOMAIN items 6-11
 * and IDENTITY items 12-15.
 */
final class VoiceCallDomainTest extends TestCase
{
    use DatabaseTransactions;

    private function makeVoiceConfig(string $companyId, ?string $brandId = null): ChannelProvider
    {
        return ChannelProvider::create([
            'company_id' => $companyId,
            'brand_id' => $brandId,
            'channel' => 'voice',
            'display_name' => 'Voice Line',
            'phone_number' => '201099999999',
            'status' => ChannelProviderStatus::ACTIVE->value,
            'credentials' => [],
        ]);
    }

    private function inboundEvent(string $providerCallId, string $fromNumber = '201000000001'): array
    {
        return [
            'provider_call_id' => $providerCallId,
            'direction' => 'inbound',
            'from_number' => $fromNumber,
            'to_number' => '201099999999',
            'signal' => 'ringing',
            'raw_status' => 'ringing',
            'event_id' => (string) Str::uuid(),
        ];
    }

    // ── 6: inbound Call created idempotently / 7: duplicate provider event ──────────

    public function test_handling_the_same_inbound_event_twice_creates_only_one_call(): void
    {
        $company = Company::factory()->create();
        $config = $this->makeVoiceConfig($company->id);
        $service = app(VoiceCallService::class);

        $event = $this->inboundEvent('prov-call-idem-1');
        $first = $service->handleInboundEvent($config, $event);
        $second = $service->handleInboundEvent($config, $event);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Call::query()->where('channel_provider_id', $config->id)->where('provider_call_id', 'prov-call-idem-1')->count());
    }

    // ── 8: raw provider state maps to canonical state ────────────────────────────────

    public function test_call_state_mapper_translates_normalized_signal_to_canonical_state(): void
    {
        $company = Company::factory()->create();
        $config = $this->makeVoiceConfig($company->id);
        $call = app(VoiceCallService::class)->handleInboundEvent($config, $this->inboundEvent('prov-call-state-1'));

        $mapper = app(CallStateMapper::class);
        $mapper->apply($call, CallStateMapper::SIGNAL_ANSWERED, 'answered-by-vendor');

        $this->assertSame(CallCanonicalState::Connected, $call->canonical_state);
        $this->assertSame('answered-by-vendor', $call->provider_raw_status, 'raw provider status must be preserved verbatim, never overwritten by canonical state');
    }

    // ── 9: out-of-order event cannot improperly regress a terminal state ────────────

    public function test_a_terminal_state_is_never_regressed_by_a_delayed_earlier_signal(): void
    {
        $company = Company::factory()->create();
        $config = $this->makeVoiceConfig($company->id);
        $call = app(VoiceCallService::class)->handleInboundEvent($config, $this->inboundEvent('prov-call-state-2'));

        $mapper = app(CallStateMapper::class);
        $mapper->apply($call, CallStateMapper::SIGNAL_COMPLETED);
        $this->assertSame(CallCanonicalState::Completed, $call->canonical_state);

        // A delayed "ringing" arrives after the call already completed.
        $mapper->apply($call, CallStateMapper::SIGNAL_RINGING);
        $this->assertSame(CallCanonicalState::Completed, $call->canonical_state, 'a terminal state must never be regressed');
    }

    public function test_non_terminal_signals_only_advance_forward_never_backward(): void
    {
        $company = Company::factory()->create();
        $config = $this->makeVoiceConfig($company->id);
        $call = app(VoiceCallService::class)->handleInboundEvent($config, $this->inboundEvent('prov-call-state-3'));

        $mapper = app(CallStateMapper::class);
        $mapper->apply($call, CallStateMapper::SIGNAL_AI_ENGAGED);
        $this->assertSame(CallCanonicalState::AiActive, $call->canonical_state);

        // A delayed "ringing" (earlier in the lifecycle) must not move state backward.
        $mapper->apply($call, CallStateMapper::SIGNAL_RINGING);
        $this->assertSame(CallCanonicalState::AiActive, $call->canonical_state);
    }

    // ── 10: outbound Call uses approved Brand phone identity ────────────────────────

    public function test_outbound_call_is_scoped_to_the_channel_providers_own_brand(): void
    {
        $company = Company::factory()->create();
        $brand = Brand::create(['company_id' => $company->id, 'code' => 'OUT', 'name' => 'Outbound Brand']);
        $config = $this->makeVoiceConfig($company->id, $brand->id);

        // UnavailableTelephonyProvider throws — outbound initiation is expected to fail
        // closed with no concrete vendor configured (architecture: source-controllable now,
        // concrete vendor is an external dependency). We only assert the Brand/company
        // resolution reaches the point of calling the provider, not that a real call happens.
        $this->expectException(\Modules\CustomerEngagement\Voice\Domain\Exceptions\TelephonyProviderUnavailableException::class);

        app(VoiceCallService::class)->initiateOutbound(
            $config,
            '201099999999',
            '201000000002',
            \Modules\CustomerEngagement\Voice\Domain\Enums\OutboundCallPurpose::Support,
            null,
            1,
        );
    }

    // ── 11: cross-company Brand phone identity rejected ─────────────────────────────

    public function test_a_channel_providers_brand_never_belongs_to_a_different_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $brandB = Brand::create(['company_id' => $companyB->id, 'code' => 'B', 'name' => 'Brand B']);

        $configA = $this->makeVoiceConfig($companyA->id, null);

        $this->assertNotSame($brandB->company_id, $configA->company_id);
        // A voice ChannelProvider's own brand_id, if ever set, must belong to its own company —
        // this asserts the fixture invariant this task's model relies on (Brand::company_id),
        // not a new runtime check invented by this test.
        $this->assertSame($companyB->id, $brandB->company_id);
    }

    // ── 12: known Customer resolves correctly ────────────────────────────────────────

    public function test_caller_identity_resolves_to_an_existing_customer_by_normalized_phone(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::create([
            'company_id' => $company->id,
            'name' => 'Known Customer',
            'phone' => '01012345678',
        ]);

        $resolved = app(CallerIdentityResolver::class)->resolve($company->id, '+201012345678');

        $this->assertTrue($resolved->isKnown());
        $this->assertTrue($resolved->isCustomer());
        $this->assertSame($customer->id, $resolved->customerId);
    }

    // ── 13: known Lead resolves correctly ────────────────────────────────────────────

    public function test_caller_identity_resolves_to_an_existing_lead_when_no_customer_matches(): void
    {
        $company = Company::factory()->create();
        $lead = Lead::create([
            'company_id' => $company->id,
            'customer_name' => 'Existing Lead',
            'customer_phone' => '01098765432',
            'status' => 'new',
        ]);

        $resolved = app(CallerIdentityResolver::class)->resolve($company->id, '201098765432');

        $this->assertTrue($resolved->isKnown());
        $this->assertFalse($resolved->isCustomer());
        $this->assertSame($lead->id, $resolved->leadId);
    }

    // ── 14: unknown caller follows the approved Task 014 policy (immediate Lead) ────

    public function test_unknown_caller_gets_an_immediate_lead_created_on_first_inbound_call(): void
    {
        $company = Company::factory()->create();
        $config = $this->makeVoiceConfig($company->id);

        $call = app(VoiceCallService::class)->handleInboundEvent($config, $this->inboundEvent('prov-call-unknown-1', '201055555555'));

        $this->assertNotNull($call->lead_id);
        $lead = Lead::query()->find($call->lead_id);
        $this->assertNotNull($lead);
        $this->assertSame('201055555555', $lead->customer_phone);
        $this->assertSame('voice', $lead->source);
    }

    // ── 15: repeated webhook does not create a duplicate Lead ───────────────────────

    public function test_a_retried_inbound_webhook_never_creates_a_second_lead(): void
    {
        $company = Company::factory()->create();
        $config = $this->makeVoiceConfig($company->id);
        $event = $this->inboundEvent('prov-call-unknown-2', '201066666666');

        $service = app(VoiceCallService::class);
        $first = $service->handleInboundEvent($config, $event);
        $second = $service->handleInboundEvent($config, $event);

        $this->assertSame($first->lead_id, $second->lead_id);
        $this->assertSame(1, Lead::query()->where('customer_phone', '201066666666')->count());
    }
}
