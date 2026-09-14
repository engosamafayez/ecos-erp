<?php

declare(strict_types=1);

namespace Modules\AI\Application\Tools;

use App\Models\User;
use Modules\AI\Domain\Contracts\AIToolInterface;
use Modules\AI\Domain\Enums\AIToolClassification;
use Modules\AI\Domain\Enums\AIToolScope;
use Modules\AI\Domain\ValueObjects\AIRequestContext;
use Modules\AI\Domain\ValueObjects\AIToolResult;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Crm\Customers\Domain\Models\Customer;

/**
 * Mirrors CustomerController::orders()'s exact scoped query (same canonical
 * `orders` read, same company+customer filter, same field projection) — no
 * second query path invented for the assistant.
 */
final class GetCustomerOrdersTool implements AIToolInterface
{
    private const LIMIT = 20;

    public function name(): string
    {
        return 'get_customer_orders';
    }

    public function description(): string
    {
        return "Lists a customer's most recent orders (status, total, delivery date).";
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

        $rows = Order::query()
            ->where('company_id', $context->companyId)
            ->where('customer_id', $customer->id)
            ->with('channel.brand')
            ->latest('order_date')
            ->limit(self::LIMIT)
            ->get()
            ->map(static fn (Order $o): array => [
                'order_number' => $o->order_number,
                'order_date' => $o->order_date,
                'status' => $o->status?->value,
                'brand' => $o->channel?->brand?->name,
                'total' => (float) $o->total,
                'remaining_balance' => (float) $o->remaining_balance,
                'requested_delivery_date' => $o->requested_delivery_date,
            ])
            ->all();

        return AIToolResult::success(['orders' => $rows]);
    }
}
