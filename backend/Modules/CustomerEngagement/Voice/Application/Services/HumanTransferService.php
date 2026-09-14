<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Application\Services;

use Modules\CustomerEngagement\Application\Services\BusinessHoursService;
use Modules\CustomerEngagement\Application\Services\RoutingService;
use Modules\CustomerEngagement\Application\Services\SlaService;
use Modules\CustomerEngagement\Domain\Models\ConversationTask;
use Modules\CustomerEngagement\Voice\Application\Contracts\TelephonyProviderContract;
use Modules\CustomerEngagement\Voice\Domain\Exceptions\TelephonyProviderUnavailableException;
use Modules\CustomerEngagement\Voice\Domain\Models\Call;
use Modules\CustomerEngagement\Voice\Domain\ValueObjects\HumanTransferOutcome;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §16 — real human-transfer
 * orchestration. Target resolution reuses the existing RoutingService/Conversation assignment
 * fields unchanged (no second routing engine); the transfer itself is a real telephony bridge
 * (TelephonyProviderContract::bridgeTransfer()), never simulated via task/notification/
 * assignment alone. Fallback when no human is available or outside business hours is
 * ConversationTask-based callback scheduling — the same real model ScheduleCallbackTool uses.
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
        $hasTarget = $conversation->assigned_employee_id !== null || $conversation->assigned_team_id !== null;

        // created_by is required on ConversationTask; an inbound call has no initiated_by
        // human, so this system-triggered fallback attributes to the call's own company-scoped
        // AI Voice Assistant identity instead of leaving it unset.
        $createdBy = $call->initiated_by ?? $this->voiceIdentity->forCompany($call->company_id)->id;

        if (! $withinHours || ! $hasTarget) {
            $task = ConversationTask::create([
                'conversation_id' => $conversation->id,
                'title' => 'Callback — human transfer unavailable',
                'description' => $reason,
                'due_at' => now()->addHour(),
                'created_by' => $createdBy,
            ]);

            return HumanTransferOutcome::fallbackCallback($task->id);
        }

        $targetNumber = $conversation->assigned_employee_id ?? $conversation->assigned_team_id;

        $this->stateMapper->apply($call, CallStateMapper::SIGNAL_TRANSFER_STARTED);
        $call->transferred_at = now();
        $call->transfer_target_type = $conversation->assigned_employee_id !== null ? 'employee' : 'team';
        $call->transfer_target_id = (string) $targetNumber;

        try {
            $this->telephony->bridgeTransfer((string) $call->provider_call_id, (string) $targetNumber);
        } catch (TelephonyProviderUnavailableException $e) {
            $this->stateMapper->apply($call, CallStateMapper::SIGNAL_FAILED);
            $call->save();

            return HumanTransferOutcome::failed($e->getMessage());
        }

        $this->stateMapper->apply($call, CallStateMapper::SIGNAL_HUMAN_ENGAGED);
        $call->save();

        return HumanTransferOutcome::bridged();
    }
}
