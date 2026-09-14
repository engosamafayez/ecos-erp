<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\CustomerEngagement\Domain\Enums\CommunicationProvider;
use Modules\CustomerEngagement\Domain\Models\Conversation;
use Modules\CustomerEngagement\Voice\Domain\Enums\CallCanonicalState;
use Modules\CustomerEngagement\Voice\Domain\Enums\CallDirection;
use Modules\CustomerEngagement\Voice\Domain\Enums\CallHandledBy;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §4 — the ONE additive
 * call-specific model the architecture report identified as genuinely needed (`Conversation`/
 * `Message` cannot honestly hold duration/transfer/provider-call-id facts). Every voice call
 * still belongs to a `provider=voice` `Conversation` — routing/SLA/assignment/inbox apply to it
 * automatically because it is still a Conversation; this row only carries what a Conversation
 * genuinely cannot.
 *
 * Per-turn transcript is NOT stored here — it reuses the existing `cep_messages` table (one row
 * per utterance), so a call's turns render in the same per-conversation timeline WhatsApp/
 * Instagram messages already use. `transcript_ref`/`recording_ref` here are pointers only
 * (§16/§18 of the ticket — never the blob itself, never a raw public URL).
 */
class Call extends Model
{
    use HasUuids;

    protected $table = 'cep_calls';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'provider' => CommunicationProvider::class,
            'direction' => CallDirection::class,
            'canonical_state' => CallCanonicalState::class,
            'handled_by' => CallHandledBy::class,
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'transferred_at' => 'datetime',
            'duration_seconds' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isTerminal(): bool
    {
        return $this->canonical_state->isTerminal();
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }
}
