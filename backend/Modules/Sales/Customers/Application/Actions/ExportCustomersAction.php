<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Modules\Sales\Customers\Domain\Contracts\CustomerRepositoryInterface;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-FINAL-UI-CLOSURE-014 (§10/§11).
 *
 * Mirrors ListCustomersAction, but returns the full filtered+sorted population for
 * Print/Export instead of one page — see EloquentCustomerRepository::allMatching().
 */
final class ExportCustomersAction extends BaseAction
{
    public function __construct(private readonly CustomerRepositoryInterface $customers) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $filters = is_array($arguments[0] ?? null) ? $arguments[0] : [];

        return OperationResult::success($this->customers->allMatching($filters));
    }
}
