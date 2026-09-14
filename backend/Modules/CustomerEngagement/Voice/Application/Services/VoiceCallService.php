<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Application\Services;

use Illuminate\Support\Str;
use Modules\CustomerEngagement\Application\Services\ConversationService;
use Modules\CustomerEngagement\Application\Services\LeadService;
use Modules\CustomerEngagement\Application\Services\RoutingService;
use Modules\CustomerEngagement\Application\Services\SlaService;
use Modules\CustomerEngagement\Domain\Enums\ConversationStatus;
use Modules\CustomerEngagement\Domain\Models\ChannelProvider;
use Modules\CustomerEngagement\Voice\Application\Contracts\TelephonyProviderContract;
use Modules\CustomerEngagement\Voice\Domain\Enums\CallCanonicalState;
use Modules\CustomerEngagement\Voice\Domain\Enums\CallDirection;
use Modules\CustomerEngagement\Voice\Domain\Enums\OutboundCallPurpose;
use Modules\CustomerEngagement\Voice\Domain\Exceptions\OutboundCallNotEligibleException;
use Modules\CustomerEngagement\Voice\Domain\Models\Call;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §13/§25 — the orchestration
 * seam that ties the additive Call domain to the existing CustomerEngagement engine for both
 * directions (architecture report, TARGET VOICE ARCHITECTURE). Brand/company always come from
 * the resolved ChannelProvider row (§3A / §13), never from event/client content. A Conversation
 * (provider=voice) is created exactly once per (channel_provider, provider_call_id) — idempotent
 * find-or-create, mirroring WebhookIngestService's own (provider, external_conversation_id,
 * company_id) key.
 */
final class VoiceCallService
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly RoutingService $routing,
        private readonly SlaService $sla,
        private readonly LeadService $leads,
        private readonly CallerIdentityResolver $identity,
        private readonly VoiceOutboundEligibilityService $eligibility,
        private readonly VoiceAuditService $audit,
        private readonly TelephonyProviderContract $telephony,
    ) {}

    /**
     * @param  array{provider_call_id: string, direction: string, from_number: string, to_number: string, signal: string, raw_status: string, event_id: string|null}  $event
     */
    public function handleInboundEvent(ChannelProvider $config, array $event): Call
    {
        $call = Call::query()
            ->where('channel_provider_id', $config->id)
            ->where('provider_call_id', $event['provider_call_id'])
            ->first();

        if ($call !== null) {
            return $call;
        }

        $callerNumber = $event['from_number'];
        $resolved = $this->identity->resolve($config->company_id, $callerNumber);

        $conversation = $this->conversations->create([
            'company_id' => $config->company_id,
            'brand_id' => $config->brand_id,
            'channel_id' => $config->id,
            'provider' => $config->channel,
            'external_conversation_id' => $event['provider_call_id'],
            'conversation_uuid' => Str::uuid()->toString(),
            'customer_id' => $resolved->customerId,
            'customer_phone' => $callerNumber,
            'status' => ConversationStatus::Open->value,
            'started_at' => now(),
        ]);

        $this->routing->autoRoute($conversation);
        $this->sla->startTracking($conversation);

        $leadId = $resolved->leadId;

        // Architecture report BUSINESS DECISIONS #3 (approved): immediate Lead creation on
        // first unknown-caller contact — the same policy WhatsApp/Instagram/Messenger already
        // use (LeadService::createFromConversation, unmodified).
        if (! $resolved->isKnown()) {
            $lead = $this->leads->createFromConversation($conversation, [
                'customer_phone' => $callerNumber,
                'source' => 'voice',
            ]);
            $leadId = $lead->id;
            $this->audit->leadCreated($config->company_id, $event['provider_call_id'], $lead->id);
        }

        $call = Call::create([
            'conversation_id' => $conversation->id,
            'company_id' => $config->company_id,
            'brand_id' => $config->brand_id,
            'channel_provider_id' => $config->id,
            'customer_id' => $resolved->customerId,
            'lead_id' => $leadId,
            'direction' => CallDirection::Inbound->value,
            'from_number' => $callerNumber,
            'to_number' => $event['to_number'],
            'provider_call_id' => $event['provider_call_id'],
            'provider' => $config->channel,
            'canonical_state' => CallCanonicalState::Initiated->value,
            'provider_raw_status' => $event['raw_status'],
            'started_at' => now(),
        ]);

        $this->audit->callReceived($config->company_id, $call->id, ['from_number' => $callerNumber]);
        $this->audit->identityResolved($config->company_id, $call->id, [
            'resolved' => $resolved->isKnown(),
            'customer_id' => $resolved->customerId,
            'lead_id' => $leadId,
        ]);

        return $call;
    }

    public function initiateOutbound(
        ChannelProvider $config,
        string $fromNumber,
        string $toNumber,
        OutboundCallPurpose $purpose,
        ?string $customerId,
        int $initiatedByUserId,
    ): Call {
        if ($customerId !== null && ! $this->eligibility->isEligible($customerId, $purpose)) {
            throw OutboundCallNotEligibleException::optedOut($customerId);
        }

        $conversation = $this->conversations->create([
            'company_id' => $config->company_id,
            'brand_id' => $config->brand_id,
            'channel_id' => $config->id,
            'provider' => $config->channel,
            'external_conversation_id' => Str::uuid()->toString(),
            'conversation_uuid' => Str::uuid()->toString(),
            'customer_id' => $customerId,
            'customer_phone' => $toNumber,
            'status' => ConversationStatus::Open->value,
            'started_at' => now(),
        ]);

        $this->sla->startTracking($conversation);

        // Fail-closed by construction: UnavailableTelephonyProvider throws
        // TelephonyProviderUnavailableException here until a concrete vendor is bound —
        // no fake/invented provider_call_id is ever fabricated (architecture report,
        // FAILURE/FALLBACK: "fail the initiation attempt with an honest error").
        $result = $this->telephony->initiateOutboundCall($fromNumber, $toNumber, [
            'purpose' => $purpose->value,
        ]);

        $call = Call::create([
            'conversation_id' => $conversation->id,
            'company_id' => $config->company_id,
            'brand_id' => $config->brand_id,
            'channel_provider_id' => $config->id,
            'customer_id' => $customerId,
            'direction' => CallDirection::Outbound->value,
            'from_number' => $fromNumber,
            'to_number' => $toNumber,
            'provider_call_id' => $result['provider_call_id'],
            'provider' => $config->channel,
            'canonical_state' => CallCanonicalState::Initiated->value,
            'initiated_by' => $initiatedByUserId,
        ]);

        $this->audit->callInitiated($config->company_id, $initiatedByUserId, $call->id, [
            'purpose' => $purpose->value,
            'to_number' => $toNumber,
        ]);

        return $call;
    }
}
