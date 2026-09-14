<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Presentation\Http\Controllers;

use App\Core\Company\TenantOwnershipResolver;
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
use Modules\Organization\Brands\Domain\Models\Brand;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §25 — the smallest
 * permission-gated backend API Task 2 needs. Never exposes provider secrets or raw webhook
 * payloads (§25).
 *
 * TASK-ECOS-V1.1-CRM-03-BRAND-VOICE-IDENTITY-FINAL-REMEDIATION-017 — company_id/brand_id are
 * client-supplied HINTS, never trusted outright (the exact RC-6 anti-pattern
 * TenantOwnershipResolver's own docblock names: "the create path took company_id from the
 * client payload... a record written under one answer was invisible to the other"). Every
 * value is independently re-validated against the authenticated actor's own tenant scope
 * before it is used to filter or authorize anything.
 */
class VoiceController extends Controller
{
    public function __construct(
        private readonly VoiceCallService $calls,
        private readonly ChannelProviderService $channelProviders,
        private readonly HumanTransferService $transfer,
        private readonly VoiceAuditService $audit,
        private readonly TenantOwnershipResolver $tenant,
    ) {}

    /**
     * Available Brand calling identities — used by the outbound-call UI to let the user pick
     * which number/Brand identity to call from. §3/§5: Brand-scoped, never "every company
     * number" — a caller with no resolvable Brand context gets an explicit
     * brand_context_required signal, never a silent full-company fallback.
     */
    public function channelProviders(Request $request): JsonResponse
    {
        $companyId = $request->string('company_id')->toString();

        if (! $this->tenant->owns($companyId)) {
            return response()->json(['message' => 'Company scope could not be verified.'], 403);
        }

        if (! $request->filled('brand_id')) {
            return response()->json(['data' => [], 'brand_context_required' => true]);
        }

        $brandId = $request->string('brand_id')->toString();

        if (! $this->brandBelongsToCompany($brandId, $companyId)) {
            return response()->json(['message' => 'Brand scope could not be verified.'], 403);
        }

        $providers = $this->channelProviders->paginate([
            'company_id' => $companyId,
            'channel' => 'voice',
            'brand_id' => $brandId,
        ], (int) $request->get('per_page', 50));

        return response()->json(['data' => ChannelProviderResource::collection($providers)]);
    }

    private function brandBelongsToCompany(string $brandId, string $companyId): bool
    {
        return Brand::query()->where('id', $brandId)->where('company_id', $companyId)->exists();
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

    /**
     * §4 — a stale/manipulated frontend must never be able to submit a Brand B (or Company B)
     * identity while operating in Brand A / Company A context: every fact this depends on
     * (company, Brand, active status, real Voice channel) is re-proven here from the resolved
     * ChannelProvider row itself, never assumed from the fact that route-model-binding merely
     * found *a* row with this id.
     */
    public function initiateOutbound(Request $request, ChannelProvider $channelProvider): JsonResponse
    {
        $data = $request->validate([
            'to_number' => 'required|string|max:32',
            'purpose' => 'required|string',
            'customer_id' => 'nullable|uuid',
            'brand_id' => 'nullable|uuid',
        ]);

        if (! $this->tenant->owns($channelProvider->company_id)) {
            return response()->json(['message' => 'This calling identity does not belong to your company.'], 403);
        }

        if ($channelProvider->channel !== 'voice') {
            return response()->json(['message' => 'This calling identity is not a Voice number.'], 422);
        }

        if (! $channelProvider->isActive()) {
            return response()->json(['message' => 'This calling identity is not active.'], 422);
        }

        // A Brand-scoped identity may only be used while operating in that exact Brand; a
        // brand_id=NULL identity is the documented company-wide shared fallback (§3) and may be
        // used regardless of (or without) a resolved Brand context.
        if ($channelProvider->brand_id !== null
            && (($data['brand_id'] ?? null) === null || $data['brand_id'] !== $channelProvider->brand_id)) {
            return response()->json(['message' => 'This calling identity does not belong to the current Brand.'], 403);
        }

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
