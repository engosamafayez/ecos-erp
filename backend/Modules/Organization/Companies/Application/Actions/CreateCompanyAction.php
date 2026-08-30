<?php

declare(strict_types=1);

namespace Modules\Organization\Companies\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Finance\Shared\Domain\Services\CompanyFinanceProvisioner;
use Modules\Organization\Companies\Application\DTO\CompanyDTO;
use Modules\Organization\Companies\Domain\Contracts\CompanyRepositoryInterface;

/**
 * Creates a new company.
 *
 * V-3: a company is not usable until it owns Finance data. Every subledger posting resolves its
 * accounts by role, so a company without a chart of accounts cannot record a supplier payable at
 * all — `AccountRoleResolver` throws before anything can be posted. Provisioning therefore happens
 * here, at the canonical creation boundary, rather than being left for someone to remember later.
 *
 * Both steps share one transaction: a company that exists without its accounts is the exact broken
 * state this closes, so a provisioning failure must take the company row with it.
 */
final class CreateCompanyAction extends BaseAction
{
    public function __construct(
        private readonly CompanyRepositoryInterface $companies,
        private readonly CompanyFinanceProvisioner $finance,
    ) {}

    /**
     * @param  mixed  ...$arguments  Expects a single {@see CompanyDTO}.
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        $dto = $arguments[0] ?? null;

        if (! $dto instanceof CompanyDTO) {
            throw new InvalidArgumentException('CreateCompanyAction::execute expects a CompanyDTO.');
        }

        $company = DB::transaction(function () use ($dto) {
            $company = $this->companies->create($dto->toArray());

            $this->finance->provision((string) $company->id);

            return $company;
        });

        return OperationResult::success($company, 'Company created successfully.');
    }
}
