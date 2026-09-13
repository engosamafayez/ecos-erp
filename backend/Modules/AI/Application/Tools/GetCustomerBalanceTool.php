<?php

declare(strict_types=1);

namespace Modules\AI\Application\Tools;

use App\Models\User;
use Modules\AI\Domain\Contracts\AIToolInterface;
use Modules\AI\Domain\Enums\AIToolClassification;
use Modules\AI\Domain\Enums\AIToolScope;
use Modules\AI\Domain\ValueObjects\AIRequestContext;
use Modules\AI\Domain\ValueObjects\AIToolResult;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Finance\Receivables\Domain\Services\CustomerLedgerService;

/**
 * §20 — the FIN-permission-gated counterpart to get_customer_summary's own
 * (CRM-permission-gated) balance field. A Finance user with finance.reports.view
 * but no crm.customers.view can still ask "what does this customer owe" through
 * this tool; both read the exact same CustomerLedgerService::balance(), never a
 * second Finance computation.
 */
final class GetCustomerBalanceTool implements AIToolInterface
{
    public function __construct(private readonly CustomerLedgerService $ledger) {}

    public function name(): string
    {
        return 'get_customer_balance';
    }

    public function description(): string
    {
        return 'Returns the current outstanding accounts-receivable balance for one customer.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_id' => ['type' => 'string'],
            ],
            'required' => ['customer_id'],
        ];
    }

    public function permission(): string
    {
        return 'finance.reports.view';
    }

    public function scope(): AIToolScope
    {
        return AIToolScope::Company;
    }

    public function classification(): AIToolClassification
    {
        return AIToolClassification::Read;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function execute(AIRequestContext $context, User $user, array $input): AIToolResult
    {
        $customerId = is_string($input['customer_id'] ?? null) ? $input['customer_id'] : null;

        if ($customerId === null || $customerId === '') {
            return AIToolResult::invalidInput('customer_id is required.');
        }

        $customer = Customer::query()->where('company_id', $context->companyId)->find($customerId);

        if ($customer === null) {
            return AIToolResult::notFound('No such customer in your company.');
        }

        return AIToolResult::success([
            'customer_name' => $customer->displayName(),
            'outstanding_balance' => $this->ledger->balance($context->companyId, $customerId),
        ]);
    }
}
