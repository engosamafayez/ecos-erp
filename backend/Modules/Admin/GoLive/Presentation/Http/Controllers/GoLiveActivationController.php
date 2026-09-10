<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Presentation\Http\Controllers;

use App\Core\Company\CurrentCompanyService;
use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Admin\GoLive\Application\Actions\ActivateGoLiveAction;
use Modules\Organization\Companies\Domain\Services\CompanyLifecycleAuthority;
use RuntimeException;

final class GoLiveActivationController extends Controller
{
    use HasApiResponse;

    public function status(CompanyLifecycleAuthority $lifecycle, CurrentCompanyService $currentCompany): JsonResponse
    {
        $companyId = $this->companyId($currentCompany);

        return $this->success([
            'company_id' => $companyId,
            'lifecycle_state' => $lifecycle->stateFor($companyId)->value,
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
