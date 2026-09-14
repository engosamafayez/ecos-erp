<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Presentation\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never serializes transcript_ref/recording_ref content — those are pointers, resolved only via
 * the dedicated, cep.voice.recordings.view-gated endpoints (§18/§32).
 */
class CallResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'company_id' => $this->company_id,
            'brand_id' => $this->brand_id,
            'customer_id' => $this->customer_id,
            'lead_id' => $this->lead_id,
            'direction' => $this->direction->value,
            'from_number' => $this->from_number,
            'to_number' => $this->to_number,
            'provider' => $this->provider->value,
            'canonical_state' => $this->canonical_state->value,
            'canonical_state_label' => $this->canonical_state->label(),
            'started_at' => $this->started_at?->toIso8601String(),
            'answered_at' => $this->answered_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,
            'outcome' => $this->outcome,
            'handled_by' => $this->handled_by?->value,
            'transferred_at' => $this->transferred_at?->toIso8601String(),
            'transfer_target_type' => $this->transfer_target_type,
            'has_transcript' => $this->transcript_ref !== null,
            'has_recording' => $this->recording_ref !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
