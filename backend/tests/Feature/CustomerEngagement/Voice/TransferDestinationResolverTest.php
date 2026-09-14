<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerEngagement\Voice;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\CustomerEngagement\Domain\Enums\ChannelProviderStatus;
use Modules\CustomerEngagement\Domain\Models\ChannelProvider;
use Modules\CustomerEngagement\Domain\Models\Conversation;
use Modules\CustomerEngagement\Voice\Application\Services\HumanTransferService;
use Modules\CustomerEngagement\Voice\Application\Services\TransferDestinationResolver;
use Modules\CustomerEngagement\Voice\Application\Services\VoiceCallService;
use Modules\CustomerEngagement\Voice\Domain\Models\VoiceTeamDestination;
use Modules\Hr\Workforce\Domain\Models\Employee;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Organization\Teams\Domain\Models\Team;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-CRM-03-VOICE-UX-AND-FINAL-SOURCE-CLOSURE-016 §31 TRANSFER items 1-9 — proving
 * Gap A (previously: HumanTransferService passed an internal identifier straight through as if
 * it were a phone number) is actually closed.
 */
final class TransferDestinationResolverTest extends TestCase
{
    use DatabaseTransactions;

    private function makeConversation(string $companyId, ?string $brandId = null): Conversation
    {
        return Conversation::create([
            'company_id' => $companyId,
            'brand_id' => $brandId,
            'provider' => 'voice',
            'external_conversation_id' => (string) Str::uuid(),
            'conversation_uuid' => (string) Str::uuid(),
            'status' => 'open',
            'started_at' => now(),
        ]);
    }

    private function makeAgent(string $companyId, string $phone = '01055500001', string $status = 'active'): User
    {
        $user = User::factory()->create(['company_id' => $companyId, 'phone' => $phone]);
        Employee::create([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'employee_number' => 'EMP-'.$user->id,
            'first_name' => 'Agent',
            'last_name' => (string) $user->id,
            'phone' => $phone,
            'status' => $status,
        ]);

        return $user;
    }

    // ── 1: resolved human transfer uses an actual dialable destination ───────────────
    // ── 2: internal UUID/id is never passed as telephone destination ────────────────

    public function test_resolved_destination_is_the_employees_real_normalized_phone_not_the_internal_id(): void
    {
        $company = Company::factory()->create();
        $agent = $this->makeAgent($company->id, '01055512345');
        $conversation = $this->makeConversation($company->id);
        $conversation->update(['assigned_employee_id' => (string) $agent->id]);

        $destination = app(TransferDestinationResolver::class)->resolveForConversation($conversation->fresh());

        $this->assertNotNull($destination);
        $this->assertNotSame((string) $agent->id, $destination->phoneNumber, 'the internal user id must never be passed as the phone number');
        $this->assertSame('201055512345', $destination->phoneNumber);
        $this->assertSame('employee', $destination->referenceType);
    }

    // ── 3: inactive destination rejected ─────────────────────────────────────────────

    public function test_a_terminated_employee_never_resolves_as_a_transfer_destination(): void
    {
        $company = Company::factory()->create();
        $agent = $this->makeAgent($company->id, '01055512346', status: 'terminated');
        $conversation = $this->makeConversation($company->id);
        $conversation->update(['assigned_employee_id' => (string) $agent->id]);

        $destination = app(TransferDestinationResolver::class)->resolveForConversation($conversation->fresh());

        $this->assertNull($destination);
    }

    public function test_an_inactive_team_voice_destination_is_rejected(): void
    {
        $company = Company::factory()->create();
        $team = Team::factory()->create(['company_id' => $company->id]);
        VoiceTeamDestination::create([
            'company_id' => $company->id,
            'team_id' => $team->id,
            'phone_number' => '01055512347',
            'is_active' => false,
        ]);
        $conversation = $this->makeConversation($company->id);
        $conversation->update(['assigned_team_id' => $team->id]);

        $destination = app(TransferDestinationResolver::class)->resolveForConversation($conversation->fresh());

        $this->assertNull($destination);
    }

    // ── 4: cross-company destination rejected ───────────────────────────────────────

    public function test_an_employee_belonging_to_a_different_company_never_resolves(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $agentInB = $this->makeAgent($companyB->id, '01055512348');

        $conversationInA = $this->makeConversation($companyA->id);
        $conversationInA->update(['assigned_employee_id' => (string) $agentInB->id]);

        $destination = app(TransferDestinationResolver::class)->resolveForConversation($conversationInA->fresh());

        $this->assertNull($destination, 'a company-A conversation must never resolve a company-B employee as its transfer target');
    }

    // ── 5: cross-Brand destination rejected ──────────────────────────────────────────

    public function test_a_brand_scoped_team_destination_never_serves_a_different_brand(): void
    {
        $company = Company::factory()->create();
        $brandX = Brand::factory()->create(['company_id' => $company->id, 'code' => 'BRD-X', 'name' => 'Brand X']);
        $brandY = Brand::factory()->create(['company_id' => $company->id, 'code' => 'BRD-Y', 'name' => 'Brand Y']);
        $team = Team::factory()->create(['company_id' => $company->id]);
        VoiceTeamDestination::create([
            'company_id' => $company->id,
            'brand_id' => $brandX->id,
            'team_id' => $team->id,
            'phone_number' => '01055512349',
            'is_active' => true,
        ]);

        $conversationForY = $this->makeConversation($company->id, $brandY->id);
        $conversationForY->update(['assigned_team_id' => $team->id]);

        $destination = app(TransferDestinationResolver::class)->resolveForConversation($conversationForY->fresh());

        $this->assertNull($destination, 'a Brand-X-scoped destination must never serve a Brand-Y conversation');
    }

    public function test_a_company_wide_null_brand_team_destination_serves_any_brand(): void
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id, 'code' => 'BRD-Z', 'name' => 'Brand Z']);
        $team = Team::factory()->create(['company_id' => $company->id]);
        VoiceTeamDestination::create([
            'company_id' => $company->id,
            'brand_id' => null,
            'team_id' => $team->id,
            'phone_number' => '01055512350',
            'is_active' => true,
        ]);

        $conversation = $this->makeConversation($company->id, $brand->id);
        $conversation->update(['assigned_team_id' => $team->id]);

        $destination = app(TransferDestinationResolver::class)->resolveForConversation($conversation->fresh());

        $this->assertNotNull($destination);
        $this->assertSame('201055512350', $destination->phoneNumber);
    }

    // ── 6: invalid/non-dialable destination rejected ─────────────────────────────────

    public function test_a_non_dialable_phone_value_is_rejected(): void
    {
        $company = Company::factory()->create();
        $agent = $this->makeAgent($company->id, phone: '123'); // too short to be dialable
        $conversation = $this->makeConversation($company->id);
        $conversation->update(['assigned_employee_id' => (string) $agent->id]);

        $destination = app(TransferDestinationResolver::class)->resolveForConversation($conversation->fresh());

        $this->assertNull($destination);
    }

    public function test_a_raw_internal_uuid_assignment_never_resolves_as_an_employee(): void
    {
        $company = Company::factory()->create();
        $conversation = $this->makeConversation($company->id);
        $conversation->update(['assigned_employee_id' => (string) Str::uuid()]);

        $destination = app(TransferDestinationResolver::class)->resolveForConversation($conversation->fresh());

        $this->assertNull($destination, 'a non-numeric assigned_employee_id can never be a real users.id');
    }

    // ── 7: provider receives normalized dialable destination ─────────────────────────
    // ── 8: provider transfer success maps to canonical transfer success ─────────────

    public function test_a_successful_bridge_transfer_reaches_human_active_with_a_normalized_number(): void
    {
        $company = Company::factory()->create();
        $config = ChannelProvider::create([
            'company_id' => $company->id,
            'channel' => 'voice',
            'display_name' => 'Voice',
            'phone_number' => '201099999999',
            'status' => ChannelProviderStatus::ACTIVE->value,
            'credentials' => [],
        ]);
        $call = app(VoiceCallService::class)->handleInboundEvent($config, [
            'provider_call_id' => 'prov-td-1',
            'direction' => 'inbound',
            'from_number' => '201000000020',
            'to_number' => '201099999999',
            'signal' => 'ringing',
            'raw_status' => 'ringing',
            'event_id' => (string) Str::uuid(),
        ]);
        $agent = $this->makeAgent($company->id, '01055512351');
        $call->conversation->update(['assigned_employee_id' => (string) $agent->id]);

        $fake = new class implements \Modules\CustomerEngagement\Voice\Application\Contracts\TelephonyProviderContract
        {
            public ?string $receivedTargetNumber = null;

            public function initiateOutboundCall(string $fromNumber, string $toNumber, array $options = []): array
            {
                return ['provider_call_id' => 'x'];
            }

            public function validateWebhook(\Illuminate\Http\Request $request, string $webhookSecret): bool
            {
                return true;
            }

            public function parseInboundEvent(array $payload): array
            {
                return [];
            }

            public function answer(string $providerCallId): bool
            {
                return true;
            }

            public function hangup(string $providerCallId): bool
            {
                return true;
            }

            public function bridgeTransfer(string $providerCallId, string $targetNumber): array
            {
                $this->receivedTargetNumber = $targetNumber;

                return ['provider_call_id' => $providerCallId, 'status' => 'bridged'];
            }

            public function getCallStatus(string $providerCallId): array
            {
                return [];
            }
        };
        app()->instance(\Modules\CustomerEngagement\Voice\Application\Contracts\TelephonyProviderContract::class, $fake);

        $outcome = app(HumanTransferService::class)->transfer($call->fresh(), 'customer requested');

        $this->assertSame('201055512351', $fake->receivedTargetNumber, 'the provider must receive a normalized dialable number, never the internal user id');
        $this->assertSame('bridged', $outcome->result);
        $this->assertSame(\Modules\CustomerEngagement\Voice\Domain\Enums\CallCanonicalState::HumanActive, $call->fresh()->canonical_state);
    }

    // ── 9: provider transfer failure remains failed/unavailable honestly ────────────
    // (covered by VoiceConsentAndTransferTest::test_transfer_fails_honestly_rather_than_pretending_to_bridge_without_a_provider,
    // which asserts a resolvable destination still reports 'failed' — never 'bridged' — when
    // the provider itself throws.)
}
