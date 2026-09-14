<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\CustomerEngagement\Application\Services\ChannelProviderService;
use Modules\CustomerEngagement\Domain\Models\ChannelProvider;
use Modules\CustomerEngagement\Presentation\Http\Resources\ChannelProviderResource;
use Modules\CustomerEngagement\Voice\Application\Services\HumanTransferService;
use Modules\CustomerEngagement\Voice\Application\Services\VoiceAuditService;
use Modules\CustomerEngagement\Voice\Application\Services\VoiceCallService;
use Modules\CustomerEngagement\Voice\Domain\Enums\OutboundCallPurpose;
use Modules\CustomerEngagement\Voice\Domain\Models\Call;
use Modules\CustomerEngagement\Voice\Presentation\Http\Resources\CallResource;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §25 — the smallest
 * permission-gated backend API Task 2 needs. Never exposes provider secrets or raw webhook
 * payloads (§25). No frontend is built in this task.
 */
class VoiceController extends Controller
{
    public function __construct(
        private readonly VoiceCallService $calls,
        private readonly ChannelProviderService $channelProviders,
        private readonly HumanTransferService $transfer,
        private readonly VoiceAuditService $audit,
    ) {}

    /**
     * Available Brand calling identities — used by the (Task 2) outbound-call UI to let the
     * user pick which number/Brand identity to call from.
     */
    public function channelProviders(Request $request): JsonResponse
    {
        $providers = $this->channelProviders->paginate([
            'company_id' => $request->string('company_id')->toString(),
            'channel' => 'voice',
        ], (int) $request->get('per_page', 50));

        return response()->json(['data' => ChannelProviderResource::collection($providers)]);
    }

    public function index(Request $request): JsonResponse
    {
        $calls = Call::query()
            ->where('company_id', $request->string('company_id')->toString())
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->string('brand_id')->toString()))
            ->when($request->filled('canonical_state'), fn ($q) => $q->where('canonical_state', $request->string('canonical_state')->toString()))
            ->latest('started_at')
            ->paginate((int) $request->get('per_page', 25));

        return response()->json([
            'data' => CallResource::collection($calls),
            'meta' => [
                'current_page' => $calls->currentPage(),
                'last_page' => $calls->lastPage(),
                'per_page' => $calls->perPage(),
                'total' => $calls->total(),
            ],
        ]);
    }

    public function show(Call $call): JsonResponse
    {
        return response()->json(['data' => new CallResource($call)]);
    }

    public function initiateOutbound(Request $request, ChannelProvider $channelProvider): JsonResponse
    {
        $data = $request->validate([
            'to_number' => 'required|string|max:32',
            'purpose' => 'required|string',
            'customer_id' => 'nullable|uuid',
        ]);

        $purpose = OutboundCallPurpose::from($data['purpose']);

        $call = $this->calls->initiateOutbound(
            $channelProvider,
            (string) $channelProvider->phone_number,
            $data['to_number'],
            $purpose,
            $data['customer_id'] ?? null,
            (int) $request->user()->id,
        );

        return response()->json(['data' => new CallResource($call)], 201);
    }

    public function transfer(Request $request, Call $call): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);

        $this->audit->transferRequested($call->company_id, $call->id, $data['reason']);
        $outcome = $this->transfer->transfer($call, $data['reason']);
        $this->audit->transferOutcome($call->company_id, $call->id, $outcome->result);

        return response()->json([
            'data' => [
                'result' => $outcome->result,
                'task_id' => $outcome->taskId,
                'failure_reason' => $outcome->failureReason,
                'call' => new CallResource($call->fresh()),
            ],
        ]);
    }

    /**
     * §18/§32 — a read of a stored transcript is itself an audited event, gated separately
     * from ordinary call viewing (cep.voice.recordings.view, not cep.voice.use).
     */
    public function transcript(Request $request, Call $call): JsonResponse
    {
        if ($call->transcript_ref === null) {
            return response()->json(['data' => null]);
        }

        $this->audit->transcriptAccessed($call->company_id, (int) $request->user()->id, $call->id);

        return response()->json(['data' => ['transcript_ref' => $call->transcript_ref]]);
    }

    public function recording(Request $request, Call $call): JsonResponse
    {
        if ($call->recording_ref === null) {
            return response()->json(['data' => null]);
        }

        $this->audit->recordingAccessed($call->company_id, (int) $request->user()->id, $call->id);

        return response()->json(['data' => ['recording_ref' => $call->recording_ref]]);
    }
}
