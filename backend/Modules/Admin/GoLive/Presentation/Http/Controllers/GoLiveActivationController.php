<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Presentation\Http\Controllers;

use App\Core\Company\CurrentCompanyService;
use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Admin\GoLive\Application\Actions\ActivateGoLiveAction;
use Modules\Admin\GoLive\Domain\Services\CashBankOpeningReadiness;
use Modules\Organization\Companies\Domain\Services\CompanyLifecycleAuthority;
use RuntimeException;

final class GoLiveActivationController extends Controller
{
    use HasApiResponse;

    public function status(
        CompanyLifecycleAuthority $lifecycle,
        CashBankOpeningReadiness $cashBankReadiness,
        CurrentCompanyService $currentCompany,
    ): JsonResponse {
        $companyId = $this->companyId($currentCompany);

        return $this->success([
            'company_id' => $companyId,
            'lifecycle_state' => $lifecycle->stateFor($companyId)->value,
            // TASK-...-026-R1 Gate 4 — advisory only; ActivateGoLiveAction is the actual gate.
            // Surfaced here so the operator sees a real blocker before clicking Activate, not only
            // as a rejected request afterward.
            'cash_bank_opening_blocked' => $cashBankReadiness->isBlocked($companyId),
            'cash_bank_opening_message' => $cashBankReadiness->missingPrerequisiteMessage($companyId),
        ]);
    }

    public function activate(ActivateGoLiveAction $action, CurrentCompanyService $currentCompany): JsonResponse
    {
        $companyId = $this->companyId($currentCompany);

        try {
            $company = $action->execute($companyId);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        return $this->success([
            'company_id' => $company->id,
            'lifecycle_state' => $company->lifecycle_state,
            'live_activated_at' => $company->live_activated_at?->toIso8601String(),
        ]);
    }

    private function companyId(CurrentCompanyService $currentCompany): string
    {
        $companyId = $currentCompany->id();

        if ($companyId === null) {
            abort(422, 'No active company context.');
        }

        return $companyId;
    }
}
