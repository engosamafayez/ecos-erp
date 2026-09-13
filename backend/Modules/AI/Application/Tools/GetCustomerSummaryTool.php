<?php

declare(strict_types=1);

namespace Modules\AI\Application\Tools;

use App\Models\User;
use Modules\AI\Domain\Contracts\AIToolInterface;
use Modules\AI\Domain\Enums\AIToolClassification;
use Modules\AI\Domain\Enums\AIToolScope;
use Modules\AI\Domain\ValueObjects\AIEntityReference;
use Modules\AI\Domain\ValueObjects\AIRequestContext;
use Modules\AI\Domain\ValueObjects\AIToolResult;
use Modules\Commerce\Orders\Domain\Services\CustomerOrderMetricsService;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Crm\Customers\Domain\Services\Customer360Service;
use Modules\Finance\Receivables\Domain\Services\CustomerLedgerService;

/**
 * §17 — composes the same canonical CRM-01 authorities CustomerController's own
 * `profile()` action does (Customer360Service::identity(), the order-metrics
 * service, the Finance ledger service), curated to a right-sized AI answer
 * rather than the full page payload (notes/tags/documents are page content, not
 * needed for "what's this customer's history").
 */
final class GetCustomerSummaryTool implements AIToolInterface
{
    public function __construct(
        private readonly Customer360Service $profiles,
        private readonly CustomerOrderMetricsService $orderMetrics,
        private readonly CustomerLedgerService $ledger,
    ) {}

    public function name(): string
    {
        return 'get_customer_summary';
    }

    public function description(): string
    {
        return 'Looks up one customer by id and returns identity, order metrics, and outstanding balance.';
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
        return 'crm.customers.view';
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

        $identity = $this->profiles->identity($customer);
        $metrics = $this->orderMetrics->forCustomer($customerId, $context->companyId);
        $balance = $this->ledger->balance($context->companyId, $customerId);

        return AIToolResult::success(
            data: [
                'display_name' => $identity['display_name'],
                'code' => $identity['code'],
                'status' => $identity['status'],
                'primary_phone' => $identity['primary_phone'],
                'primary_email' => $identity['primary_email'],
                'order_metrics' => $metrics,
                'outstanding_balance' => $balance,
            ],
            references: [new AIEntityReference('customer', (string) $customer->id, $identity['display_name'], "/customers?open={$customer->id}")],
        );
    }
}
