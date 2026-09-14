<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Application\Services;

use Modules\CustomerEngagement\Application\Services\BusinessHoursService;
use Modules\CustomerEngagement\Application\Services\RoutingService;
use Modules\CustomerEngagement\Application\Services\SlaService;
use Modules\CustomerEngagement\Domain\Models\Conversation;
use Modules\CustomerEngagement\Domain\Models\ConversationTask;
use Modules\CustomerEngagement\Voice\Application\Contracts\TelephonyProviderContract;
use Modules\CustomerEngagement\Voice\Domain\Exceptions\TelephonyProviderUnavailableException;
use Modules\CustomerEngagement\Voice\Domain\Models\Call;
use Modules\CustomerEngagement\Voice\Domain\ValueObjects\HumanTransferOutcome;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §16, closed for Gap A by
 * TASK-ECOS-V1.1-CRM-03-VOICE-UX-AND-FINAL-SOURCE-CLOSURE-016 §2/§3 — real human-transfer
 * orchestration. Target resolution reuses the existing RoutingService/Conversation assignment
 * fields unchanged (no second routing engine); TransferDestinationResolver turns that
 * assignment into a real, validated, dialable TransferDestination (never a bare internal id —
 * see its own docblock for exactly why the prior 015 implementation was wrong). The transfer
 * itself is a real telephony bridge (TelephonyProviderContract::bridgeTransfer()), never
 * simulated via task/notification/assignment alone. Every non-bridged outcome still schedules a
 * ConversationTask callback (the same real model ScheduleCallbackTool uses) — §3's "do not
 * report success merely because a task was created" is about the OUTCOME label, not about
 * withholding the callback itself.
 */
final class HumanTransferService
{
    public function __construct(
        private readonly RoutingService $routing,
        private readonly BusinessHoursService $businessHours,
        private readonly SlaService $sla,
        private readonly TelephonyProviderContract $telephony,
        private readonly CallStateMapper $stateMapper,
        private readonly VoiceSystemIdentityResolver $voiceIdentity,
        private readonly TransferDestinationResolver $destinations,
    ) {}

    public function transfer(Call $call, string $reason): HumanTransferOutcome
    {
        $conversation = $call->conversation;

        if ($conversation->assigned_employee_id === null && $conversation->assigned_team_id === null) {
            $this->routing->autoRoute($conversation);
            $conversation = $conversation->fresh();
        }

        $policy = $conversation->sla_policy_id !== null
            ? $conversation->slaPolicy
            : $this->sla->getDefaultPolicy($conversation->company_id);

        $withinHours = $policy === null || $this->businessHours->isOpenAt($policy, now());

        // created_by is required on ConversationTask; an inbound call has no initiated_by
        // human, so any callback fallback attributes to the call's own company-scoped AI Voice
        // Assistant identity instead of leaving it unset.
        $createdBy = $call->initiated_by ?? $this->voiceIdentity->forCompany($call->company_id)->id;

        if (! $withinHours) {
            return $this->scheduleFallback($conversation, $createdBy, $reason, 'outside business hours');
        }

        // §2/§3 — the destination must be a real, company/Brand-scoped, active, dialable
        // number; never the raw assigned_employee_id/assigned_team_id passed straight through
        // (the exact defect Gap A named).
        $destination = $this->destinations->resolveForConversation($conversation);

        if ($destination === null) {
            return $this->scheduleFallback($conversation, $createdBy, $reason, 'no valid dialable destination');
        }

        $this->stateMapper->apply($call, CallStateMapper::SIGNAL_TRANSFER_STARTED);
        $call->transferred_at = now();
        $call->transfer_target_type = $destination->referenceType;
        $call->transfer_target_id = $destination->referenceId;

        try {
            $this->telephony->bridgeTransfer((string) $call->provider_call_id, $destination->phoneNumber);
        } catch (TelephonyProviderUnavailableException $e) {
            $this->stateMapper->apply($call, CallStateMapper::SIGNAL_FAILED);
            $call->save();

            return HumanTransferOutcome::failed($e->getMessage());
        }

        $this->stateMapper->apply($call, CallStateMapper::SIGNAL_HUMAN_ENGAGED);
        $call->save();

        return HumanTransferOutcome::bridged();
    }

    private function scheduleFallback(Conversation $conversation, int $createdBy, string $reason, string $why): HumanTransferOutcome
    {
        $task = ConversationTask::create([
            'conversation_id' => $conversation->id,
            'title' => 'Callback — human transfer unavailable',
            'description' => $reason,
            'due_at' => now()->addHour(),
            'created_by' => $createdBy,
        ]);

        return HumanTransferOutcome::transferUnavailable($task->id, $why);
    }
}
