<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerEngagement\Voice;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Crm\Customers\Domain\Models\CustomerPreference;
use Modules\CustomerEngagement\Domain\Enums\ChannelProviderStatus;
use Modules\CustomerEngagement\Domain\Models\ChannelProvider;
use Modules\CustomerEngagement\Domain\Models\ConversationTask;
use Modules\CustomerEngagement\Voice\Application\Services\VoiceCallService;
use Modules\CustomerEngagement\Voice\Application\Services\VoiceOutboundEligibilityService;
use Modules\CustomerEngagement\Voice\Domain\Enums\OutboundCallPurpose;
use Modules\CustomerEngagement\Voice\Domain\Exceptions\OutboundCallNotEligibleException;
use Modules\CustomerEngagement\Voice\Domain\Exceptions\TelephonyProviderUnavailableException;
use Modules\Hr\Workforce\Domain\Models\Employee;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §30 CONSENT items 24-25 and
 * TRANSFER items 26-29.
 */
final class VoiceConsentAndTransferTest extends TestCase
{
    use DatabaseTransactions;

    private function makeVoiceConfig(string $companyId): ChannelProvider
    {
        return ChannelProvider::create([
            'company_id' => $companyId,
            'channel' => 'voice',
            'display_name' => 'Voice Line',
            'phone_number' => '201099999999',
            'status' => ChannelProviderStatus::ACTIVE->value,
            'credentials' => [],
        ]);
    }

    // ── 24: disallowed outbound purpose is rejected ──────────────────────────────────

    public function test_outbound_call_is_rejected_when_the_customer_has_opted_out(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Opted Out']);
        CustomerPreference::create(['customer_id' => $customer->id, 'key' => 'voice_call_opt_out', 'value' => 'true']);

        $config = $this->makeVoiceConfig($company->id);

        $this->expectException(OutboundCallNotEligibleException::class);
        app(VoiceCallService::class)->initiateOutbound(
            $config, '201099999999', '201000000003', OutboundCallPurpose::Support, $customer->id, 1,
        );
    }

    // ── 25: approved V1 outbound purpose allowed when consent/policy permits ────────

    public function test_outbound_call_proceeds_for_a_customer_with_no_opt_out_recorded(): void
    {
        $company = Company::factory()->create();
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'No Preference Set']);
        $config = $this->makeVoiceConfig($company->id);

        // Reaches the (fail-closed, no vendor) telephony call — proving eligibility itself did
        // NOT block this attempt.
        $this->expectException(TelephonyProviderUnavailableException::class);
        app(VoiceCallService::class)->initiateOutbound(
            $config, '201099999999', '201000000004', OutboundCallPurpose::Support, $customer->id, 1,
        );
    }

    public function test_eligibility_service_directly_reports_opt_out_state(): void
    {
        $company = Company::factory()->create();
        $optedOut = Customer::create(['company_id' => $company->id, 'name' => 'A']);
        $notOptedOut = Customer::create(['company_id' => $company->id, 'name' => 'B']);
        CustomerPreference::create(['customer_id' => $optedOut->id, 'key' => 'voice_call_opt_out', 'value' => 'true']);

        $service = app(VoiceOutboundEligibilityService::class);

        $this->assertFalse($service->isEligible($optedOut->id, OutboundCallPurpose::Support));
        $this->assertTrue($service->isEligible($notOptedOut->id, OutboundCallPurpose::Support));
    }

    // ── 26: human transfer resolves through canonical routing ───────────────────────
    // ── 29: unavailable transfer target fails honestly (falls back, not a dropped call) ──

    public function test_transfer_falls_back_to_a_callback_task_when_no_agent_is_assigned(): void
    {
        $company = Company::factory()->create();
        $config = $this->makeVoiceConfig($company->id);
        $call = app(VoiceCallService::class)->handleInboundEvent($config, [
            'provider_call_id' => 'prov-call-transfer-1',
            'direction' => 'inbound',
            'from_number' => '201000000005',
            'to_number' => '201099999999',
            'signal' => 'ringing',
            'raw_status' => 'ringing',
            'event_id' => (string) Str::uuid(),
        ]);

        // No RoutingRule exists in this company, so autoRoute() leaves both assignment
        // fields null — no human target is available.
        $outcome = app(\Modules\CustomerEngagement\Voice\Application\Services\HumanTransferService::class)
            ->transfer($call, 'customer asked for a human');

        // TASK-...-016 §3 (Gap A) renamed this outcome from the ambiguous 'fallback_callback'
        // to the explicitly honest 'transfer_unavailable' — see HumanTransferOutcome/
        // TransferDestinationResolver's own docblocks.
        $this->assertSame('transfer_unavailable', $outcome->result);
        $this->assertNotNull($outcome->taskId);
        $this->assertNotNull(ConversationTask::query()->find($outcome->taskId));
    }

    // ── 27: transfer calls the telephony bridge contract / 28: after-hours fallback ──

    public function test_transfer_fails_honestly_rather_than_pretending_to_bridge_without_a_provider(): void
    {
        $company = Company::factory()->create();
        $config = $this->makeVoiceConfig($company->id);
        $call = app(VoiceCallService::class)->handleInboundEvent($config, [
            'provider_call_id' => 'prov-call-transfer-2',
            'direction' => 'inbound',
            'from_number' => '201000000006',
            'to_number' => '201099999999',
            'signal' => 'ringing',
            'raw_status' => 'ringing',
            'event_id' => (string) Str::uuid(),
        ]);

        // TASK-...-016 §2 (Gap A): assigned_employee_id must be a real, resolvable users.id
        // with a real dialable destination for the transfer to reach the provider bridge at
        // all — a bare random UUID (this test's own pre-016 fixture) now correctly resolves to
        // NO destination instead of being passed straight through.
        $agent = User::factory()->create(['company_id' => $company->id, 'phone' => '01055512345']);
        Employee::create([
            'company_id' => $company->id,
            'user_id' => $agent->id,
            'employee_number' => 'EMP-TEST-1',
            'first_name' => 'Agent',
            'last_name' => 'One',
            'phone' => '01055512345',
            'status' => 'active',
        ]);
        $call->conversation->update(['assigned_employee_id' => (string) $agent->id]);

        $outcome = app(\Modules\CustomerEngagement\Voice\Application\Services\HumanTransferService::class)
            ->transfer($call->fresh(), 'escalation');

        // A REAL, resolvable target IS assigned, but UnavailableTelephonyProvider fails the
        // bridge attempt — the outcome must report failure honestly, never a fabricated
        // "bridged" success.
        $this->assertSame('failed', $outcome->result);
        $this->assertNotNull($outcome->failureReason);
        $this->assertSame(\Modules\CustomerEngagement\Voice\Domain\Enums\CallCanonicalState::Failed, $call->fresh()->canonical_state);
    }
}
