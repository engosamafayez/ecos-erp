<?php

declare(strict_types=1);

namespace Modules\Logistics\Operations\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Logistics\Operations\Domain\Services\CustodyReturnsMonitoringService;

/**
 * TASK-ECOS-V1.1-OPS-04-TASK1 §5/§12 — the one dedicated endpoint this task's
 * own instructions explicitly allow alongside the additive summary response:
 * a paginated queue cannot fit cleanly into a single summary payload.
 */
final class ExpectedReturnsController extends Controller
{
    public function __construct(private readonly CustodyReturnsMonitoringService $custodyReturns) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $request->user()?->company_id;

        $result = $this->custodyReturns->expectedReturns(
            $companyId !== null ? (string) $companyId : null,
            max(1, min((int) $request->integer('per_page', 25), 100)),
            max(1, (int) $request->integer('page', 1)),
        );

        return response()->json($result);
    }
}
