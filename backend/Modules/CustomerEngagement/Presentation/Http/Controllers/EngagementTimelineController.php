<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\CustomerEngagement\Application\Services\EngagementTimelineService;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §3D — read-only
 * cross-channel customer timeline. Gated by the same cep.inbox.manage permission as the rest of
 * this module's read surface (this is a view over existing Conversation/Message/Call data the
 * operator can already see individually, not a new capability).
 */
class EngagementTimelineController extends Controller
{
    public function __construct(private readonly EngagementTimelineService $timeline) {}

    public function forCustomer(Request $request, string $customer): JsonResponse
    {
        $items = $this->timeline->forCustomer(
            $request->string('company_id')->toString(),
            $customer,
            (int) $request->get('limit', 50),
        );

        return response()->json([
            'data' => array_map(fn ($item) => $item->toArray(), $items),
        ]);
    }
}
