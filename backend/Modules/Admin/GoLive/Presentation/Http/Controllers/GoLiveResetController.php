<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\GoLive\Application\Actions\ExecuteGoLiveResetAction;
use Modules\Admin\GoLive\Application\Actions\PreviewGoLiveResetAction;
use Modules\Admin\GoLive\Domain\Enums\ResetDomain;
use RuntimeException;

/**
 * TASK-...-026 — thin HTTP wrapper. Every safety decision lives in the two Actions; company scope
 * comes from the authenticated actor's own tenant context (never a client-supplied company id),
 * matching this codebase's TenantOwnershipResolver convention used throughout Tasks 024/025.
 */
final class GoLiveResetController extends Controller
{
    use HasApiResponse;

    public function preview(Request $request, PreviewGoLiveResetAction $action): JsonResponse
    {
        $validated = $request->validate([
            'domains' => ['required', 'array', 'min:1'],
            'domains.*' => ['string', 'in:'.implode(',', array_map(fn (ResetDomain $d) => $d->value, ResetDomain::cases()))],
        ]);

        $companyId = $this->resolveCompanyId($request);
        $result = $action->execute($companyId, $validated['domains']);

        return $this->success($result->toArray());
    }

    public function execute(Request $request, ExecuteGoLiveResetAction $action): JsonResponse
    {
        $validated = $request->validate([
            'domains' => ['required', 'array', 'min:1'],
            'domains.*' => ['string', 'in:'.implode(',', array_map(fn (ResetDomain $d) => $d->value, ResetDomain::cases()))],
            'confirmation_phrase' => ['required', 'string'],
            'idempotency_key' => ['required', 'string', 'max:100'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $companyId = $this->resolveCompanyId($request);

        try {
            $operation = $action->execute(
                $companyId,
                $validated['domains'],
                $validated['confirmation_phrase'],
                $validated['idempotency_key'],
                $validated['reason'] ?? null,
            );
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return $this->success([
            'operation_id' => $operation->id,
            'status' => $operation->status->value,
            'execution_counts' => $operation->execution_counts,
            'failure_stage' => $operation->failure_stage,
            'failure_message' => $operation->failure_message,
        ]);
    }

    private function resolveCompanyId(Request $request): string
    {
        $companyId = app(\App\Core\Company\CurrentCompanyService::class)->id();

        if ($companyId === null) {
            abort(422, 'No active company context.');
        }

        return $companyId;
    }
}
